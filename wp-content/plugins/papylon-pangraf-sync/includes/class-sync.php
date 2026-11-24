<?php
/**
 * Scheduling (Action Scheduler) and the import itself.
 *
 * A run fetches both Pangraf lists once, stores a compact queue, then imports it
 * in chained batches so no single request hits shared-hosting time limits.
 */

defined( 'ABSPATH' ) || exit;

class Papylon_Pangraf_Sync {

	const GROUP         = 'papylon-pangraf-sync';
	const RUN_HOOK      = 'papylon_pangraf_sync_run';
	const BATCH_HOOK    = 'papylon_pangraf_sync_batch';
	const FETCH_HOOK    = 'papylon_pangraf_sync_fetch';
	const BUILD_HOOK    = 'papylon_pangraf_sync_build';
	const QUEUE_OPTION  = 'papylon_pangraf_sync_queue';
	const STATUS_OPTION = 'papylon_pangraf_sync_status';
	const STALE_AFTER   = 20 * MINUTE_IN_SECONDS; // a run with no progress for this long is considered dead.

	private static $group_map        = null;
	private static $group_term_cache = array();

	public static function init() {
		add_action( self::RUN_HOOK, array( __CLASS__, 'start' ) );
		add_action( self::BATCH_HOOK, array( __CLASS__, 'process_batch' ) );
		add_action( self::FETCH_HOOK, array( __CLASS__, 'fetch_unit' ) );
		add_action( self::BUILD_HOOK, array( __CLASS__, 'build_queue' ) );

		// Any settings save re-creates the recurring action.
		add_action( 'add_option_' . Papylon_Pangraf_Settings::OPTION, array( __CLASS__, 'reschedule' ) );
		add_action( 'update_option_' . Papylon_Pangraf_Settings::OPTION, array( __CLASS__, 'reschedule' ) );
	}

	/* ---------- Scheduling ---------- */

	public static function reschedule() {
		self::unschedule();

		$s = Papylon_Pangraf_Settings::get();
		if ( ! $s['enabled'] || ! $s['api_url'] || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		$interval = Papylon_Pangraf_Settings::frequencies()[ $s['frequency'] ][1];
		as_schedule_recurring_action( self::next_timestamp( $s['start_time'], $interval ), $interval, self::RUN_HOOK, array(), self::GROUP );
	}

	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::RUN_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * First run at start_time (site timezone) today, moved forward by the interval until it's in the future.
	 */
	private static function next_timestamp( $start_time, $interval ) {
		list( $h, $m ) = array_map( 'intval', explode( ':', $start_time ) );

		$ts = ( new DateTimeImmutable( 'today', wp_timezone() ) )->setTime( $h, $m )->getTimestamp();
		while ( $ts <= time() ) {
			$ts += $interval;
		}
		return $ts;
	}

	public static function next_run() {
		return function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( self::RUN_HOOK, array(), self::GROUP ) : false;
	}

	public static function run_now() {
		if ( self::is_running() ) {
			return false;
		}
		as_enqueue_async_action( self::RUN_HOOK, array(), self::GROUP );
		return true;
	}

	/* ---------- Status ---------- */

	public static function status() {
		return get_option( self::STATUS_OPTION, array() );
	}

	public static function is_running() {
		$status = self::status();
		$last = (int) ( $status['heartbeat'] ?? $status['started'] ?? 0 );
		return ! empty( $status['state'] ) && 'running' === $status['state'] && $last > time() - self::STALE_AFTER;
	}

	private static function save_status( array $status ) {
		$status['heartbeat'] = time();
		update_option( self::STATUS_OPTION, $status, false );
	}

	private static function log( $message, $level = 'info' ) {
		wc_get_logger()->log( $level, $message, array( 'source' => self::GROUP ) );
	}

	/* ---------- Run ---------- */

	/**
	 * Step 1: set up the run and schedule the first fetch. Every work unit is fetched in its own
	 * action (the lists are huge), then one action builds the import queue.
	 */
	public static function start() {
		if ( self::is_running() ) {
			self::log( 'Skipped: previous run is still in progress.', 'warning' );
			return;
		}

		$s         = Papylon_Pangraf_Settings::get();
		$stock_ids = array_map( 'intval', $s['stock_ids'] );
		$price_id  = (int) $s['price_id_m'];

		// The price unit comes first (its product data is used), then the other stock units.
		$units = array( array( 'id' => $price_id, 'stock' => in_array( $price_id, $stock_ids, true ) ) );
		foreach ( $stock_ids as $id ) {
			if ( $id !== $price_id ) {
				$units[] = array( 'id' => $id, 'stock' => true );
			}
		}

		self::tmp_clear();
		delete_option( self::QUEUE_OPTION );
		self::tmp_clear();

		$status = array(
			'state'     => 'running',
			'started'   => time(),
			'finished'  => 0,
			'units'     => $units,
			'total'     => 0,
			'batch'     => (int) $s['batch_size'],
			'processed' => 0,
			'created'   => 0,
			'updated'   => 0,
			'failed'    => 0,
			'images'    => 0,
			'message'   => '',
		);
		self::save_status( $status );
		self::log( sprintf( 'Run started: fetching %d work units.', count( $units ) ) );

		as_enqueue_async_action( self::FETCH_HOOK, array( 0 ), self::GROUP );
	}

	/**
	 * Step 2 (once per work unit): download one unit and keep only a compact copy in a temp file.
	 */
	public static function fetch_unit( $index ) {
		$status = self::status();
		if ( empty( $status['state'] ) || 'running' !== $status['state'] || ! isset( $status['units'][ $index ] ) ) {
			return;
		}

		wp_raise_memory_limit( 'admin' );

		$s       = Papylon_Pangraf_Settings::get();
		$api     = new Papylon_Pangraf_API( $s['api_url'], Papylon_Pangraf_Settings::token(), $s['protocol'] );
		$unit_id = (int) $status['units'][ $index ]['id'];

		$rows = $api->get_items( $s['supplier_ids'], $unit_id );
		if ( is_wp_error( $rows ) ) {
			self::fail( sprintf( 'Work unit %d: %s', $unit_id, $rows->get_error_message() ) );
			return;
		}

		$fields = array_flip( array( 'id_item', 'name', 'price', 'description', 'ean', 'code', 'group', 'id_group' ) );
		$data   = array();
		foreach ( $rows as $row ) {
			$key = (string) ( $row['id_item'] ?? '' );
			if ( '' === $key || isset( $data[ $key ] ) ) {
				continue; // first row per item within a unit wins, like the old script.
			}
			$qty = trim( (string) ( $row['quantity'] ?? '' ) );
			$q   = is_numeric( $qty ) ? (int) $qty : 0;

			if ( 0 === (int) $index ) {
				$data[ $key ]      = array_intersect_key( $row, $fields );
				$data[ $key ]['q'] = $q;
			} else {
				$data[ $key ] = array( $q, (float) ( $row['price'] ?? 0 ) );
			}
		}
		unset( $rows );

		if ( ! self::tmp_write( 'unit-' . (int) $index . '.json', $data ) ) {
			self::fail( 'Could not write temporary files to the uploads folder.' );
			return;
		}

		self::log( sprintf( 'Work unit %d fetched: %d items.', $unit_id, count( $data ) ) );
		self::save_status( $status ); // refresh heartbeat.

		if ( $index + 1 < count( $status['units'] ) ) {
			as_enqueue_async_action( self::FETCH_HOOK, array( $index + 1 ), self::GROUP );
		} else {
			as_enqueue_async_action( self::BUILD_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Step 3: merge the unit files into the import queue (stock summed over the stock units).
	 */
	public static function build_queue() {
		$status = self::status();
		if ( empty( $status['state'] ) || 'running' !== $status['state'] ) {
			return;
		}

		wp_raise_memory_limit( 'admin' );

		$s     = Papylon_Pangraf_Settings::get();
		$items = self::tmp_read( 'unit-0.json' );
		if ( ! is_array( $items ) ) {
			self::fail( 'Temporary data for the price unit is missing.' );
			return;
		}

		$stock = array();
		foreach ( $status['units'] as $i => $unit ) {
			$data = 0 === $i ? $items : self::tmp_read( 'unit-' . $i . '.json' );
			if ( ! is_array( $data ) ) {
				continue;
			}
			foreach ( $data as $key => $row ) {
				if ( $unit['stock'] ) {
					$stock[ $key ] = ( $stock[ $key ] ?? 0 ) + ( 0 === $i ? (int) $row['q'] : (int) $row[0] );
				}
				// No price in the price unit: take the first price found in another unit.
				if ( $i > 0 && isset( $items[ $key ] ) && (float) ( $items[ $key ]['price'] ?? 0 ) <= 0 && $row[1] > 0 ) {
					$items[ $key ]['price'] = $row[1];
				}
			}
			unset( $data );
		}

		// Optionally leave out items that have no stock anywhere and are not on the site yet.
		$existing = array();
		if ( ! empty( $s['only_in_stock'] ) ) {
			global $wpdb;
			$existing = array_flip( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value <> ''" ) );
		}

		$queue = array();
		foreach ( $items as $sku => $p ) {
			$sku = (string) $sku;
			if ( ! empty( $s['only_in_stock'] ) && ( $stock[ $sku ] ?? 0 ) <= 0 && ! isset( $existing[ $sku ] ) ) {
				continue;
			}
			$queue[] = array(
				'sku'         => $sku,
				'name'        => (string) ( $p['name'] ?? '' ),
				'price'       => (string) ( $p['price'] ?? '' ),
				'description' => (string) ( $p['description'] ?? '' ),
				'ean'         => (string) ( $p['ean'] ?? '' ),
				'code'        => (string) ( $p['code'] ?? '' ),
				'group'       => (string) ( $p['group'] ?? '' ),
				'group_id'    => (int) ( $p['id_group'] ?? 0 ),
				'qty'         => $stock[ $sku ] ?? 0,
			);
		}
		unset( $items, $stock, $existing );
		self::tmp_clear();

		update_option( self::QUEUE_OPTION, $queue, false );
		$status['total'] = count( $queue );
		self::save_status( $status );
		self::log( sprintf( 'Queue built: %d products to import.', count( $queue ) ) );

		if ( ! $queue ) {
			self::finish( $status );
			return;
		}

		as_enqueue_async_action( self::BATCH_HOOK, array( 0 ), self::GROUP );
	}

	/* ---------- Temp files (big lists don't fit comfortably in the options table) ---------- */

	private static function tmp_dir() {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'papylon-pangraf-sync/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
			file_put_contents( $dir . '.htaccess', "Deny from all\n" );
		}
		return $dir;
	}

	private static function tmp_write( $name, $data ) {
		return false !== file_put_contents( self::tmp_dir() . $name, wp_json_encode( $data ) );
	}

	private static function tmp_read( $name ) {
		$file = self::tmp_dir() . $name;
		return file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	}

	private static function tmp_clear() {
		foreach ( (array) glob( self::tmp_dir() . 'unit-*.json' ) as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	public static function process_batch( $offset ) {
		$status = self::status();
		if ( empty( $status['state'] ) || 'running' !== $status['state'] ) {
			return;
		}

		$s     = Papylon_Pangraf_Settings::get();
		$queue = get_option( self::QUEUE_OPTION, array() );
		$size  = max( 1, (int) $status['batch'] );

		foreach ( array_slice( $queue, (int) $offset, $size ) as $item ) {
			try {
				$result = self::import_item( $item, $s );
				++$status[ $result['created'] ? 'created' : 'updated' ];
				if ( $result['image'] ) {
					++$status['images'];
				}
			} catch ( Throwable $e ) {
				++$status['failed'];
				self::log( sprintf( 'SKU %s failed: %s', $item['sku'], $e->getMessage() ), 'error' );
			}
			++$status['processed'];
		}

		self::save_status( $status );

		$next = (int) $offset + $size;
		if ( $next < count( $queue ) ) {
			as_enqueue_async_action( self::BATCH_HOOK, array( $next ), self::GROUP );
		} else {
			self::finish( $status );
		}
	}

	private static function finish( array $status ) {
		$status['state']    = 'done';
		$status['finished'] = time();
		self::save_status( $status );
		delete_option( self::QUEUE_OPTION );
		self::tmp_clear();

		self::log(
			sprintf(
				'Run finished: %d created, %d updated, %d failed, %d images.',
				$status['created'],
				$status['updated'],
				$status['failed'],
				$status['images']
			)
		);
	}

	private static function fail( $message ) {
		self::save_status(
			array_merge(
				self::status(),
				array(
					'state'    => 'failed',
					'finished' => time(),
					'message'  => $message,
				)
			)
		);
		delete_option( self::QUEUE_OPTION );
		self::tmp_clear();
		self::log( 'Run failed: ' . $message, 'error' );
	}

	/* ---------- Import one product ---------- */

	/**
	 * Create or update one product by SKU (= Pangraf id_item).
	 *
	 * @return array{created: bool, image: bool}
	 */
	private static function import_item( array $item, array $s ) {
		$id      = wc_get_product_id_by_sku( $item['sku'] );
		$product = $id ? wc_get_product( $id ) : null;
		$created = ! $product;

		if ( $created ) {
			$product = new WC_Product_Simple();
			$product->set_sku( $item['sku'] );
			$product->set_status( 'publish' );
		}

		$product->set_name( $item['name'] );
		$product->set_regular_price( wc_format_decimal( $item['price'] ) );
		$product->set_short_description( wp_kses_post( $item['description'] ) );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $item['qty'] );
		$product->set_stock_status( $item['qty'] > 0 ? 'instock' : 'outofstock' );

		if ( '' !== $item['ean'] ) {
			$product->update_meta_data( '_wpm_gtin_code', $item['ean'] );
		}
		if ( '' !== $item['code'] ) {
			$product->update_meta_data( '_pangraf_code', $item['code'] );
		}

		if ( 'assign' === $s['categories'] ) {
			$term_ids = self::category_ids( $item );
			if ( $term_ids ) {
				$product->set_category_ids( $term_ids );
			}
		}

		$product->save();

		return array(
			'created' => $created,
			'image'   => self::maybe_set_image( $product, $item, $s['image_url_pattern'] ),
		);
	}

	/**
	 * Download the image only when the product has none or the URL changed,
	 * so the media library doesn't fill up with duplicates on every run.
	 */
	private static function maybe_set_image( WC_Product $product, array $item, $pattern ) {
		if ( ! $pattern || '' === $item['code'] ) {
			return false;
		}

		$url = str_replace( array( '{id_item}', '{code}' ), array( rawurlencode( $item['sku'] ), rawurlencode( $item['code'] ) ), $pattern );
		if ( $product->get_image_id() && $product->get_meta( '_pangraf_image_url' ) === $url ) {
			return false;
		}

		$head = wp_remote_head( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $head ) || 200 !== wp_remote_retrieve_response_code( $head ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $url, $product->get_id(), $item['name'], 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			self::log( sprintf( 'SKU %s image failed: %s', $item['sku'], $attachment_id->get_error_message() ), 'warning' );
			return false;
		}

		$product->set_image_id( $attachment_id );
		$product->update_meta_data( '_pangraf_image_url', $url );
		$product->save();
		return true;
	}

	/* ---------- Categories ---------- */

	/**
	 * Group tree from includes/groups-map.php: id_group => array( name, parent ).
	 */
	private static function group_map() {
		if ( null === self::$group_map ) {
			$file            = __DIR__ . '/groups-map.php';
			$map             = file_exists( $file ) ? include $file : array();
			self::$group_map = apply_filters( 'papylon_pangraf_group_map', is_array( $map ) ? $map : array() );
		}
		return self::$group_map;
	}

	/**
	 * product_cat term ids for a product: its subcategory plus (by default) every parent above it.
	 * Falls back to a top-level category named after the API "group" when the id is not in the map.
	 *
	 * @return int[]
	 */
	private static function category_ids( array $item ) {
		$map      = self::group_map();
		$group_id = (int) ( $item['group_id'] ?? 0 );

		if ( $group_id && isset( $map[ $group_id ] ) ) {
			$ids = array();
			$gid = $group_id;
			$depth = 0;
			while ( $gid && isset( $map[ $gid ] ) && $depth++ < 5 ) {
				$term_id = self::resolve_group( $gid, $map );
				if ( $term_id ) {
					$ids[] = $term_id;
				}
				$gid = (int) $map[ $gid ]['parent'];
			}

			if ( ! apply_filters( 'papylon_pangraf_assign_parent_categories', true ) ) {
				$ids = array_slice( $ids, 0, 1 );
			}
			return array_values( array_unique( $ids ) );
		}

		if ( '' !== $item['group'] ) {
			$term_id = self::find_or_create_term( 0, $item['group'], 0 );
			return $term_id ? array( $term_id ) : array();
		}

		return array();
	}

	/**
	 * Term id for one Pangraf group, creating its parents first.
	 */
	private static function resolve_group( $group_id, array $map, $depth = 0 ) {
		if ( isset( self::$group_term_cache[ $group_id ] ) ) {
			return self::$group_term_cache[ $group_id ];
		}
		if ( ! isset( $map[ $group_id ] ) || $depth > 5 ) {
			return 0;
		}

		$parent_gid  = (int) $map[ $group_id ]['parent'];
		$parent_term = $parent_gid ? self::resolve_group( $parent_gid, $map, $depth + 1 ) : 0;

		$term_id = self::find_or_create_term( $group_id, $map[ $group_id ]['name'], $parent_term );

		self::$group_term_cache[ $group_id ] = $term_id;
		return $term_id;
	}

	/**
	 * Find the product_cat term for a group (by stored group id, then name + parent, then an old
	 * flat top-level category of the same name), or create it under $parent_term.
	 */
	private static function find_or_create_term( $group_id, $name, $parent_term ) {
		$base = array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'fields'     => 'ids',
			'number'     => 1,
		);

		// 1. Already linked to this Pangraf group id.
		if ( $group_id ) {
			$found = get_terms( $base + array( 'meta_key' => '_pangraf_group_id', 'meta_value' => $group_id ) );
			if ( ! is_wp_error( $found ) && $found ) {
				$term_id = (int) $found[0];
				self::ensure_parent( $term_id, $parent_term );
				return $term_id;
			}
		}

		// 2. Same name under the right parent.
		$found = get_terms( $base + array( 'name' => $name, 'parent' => $parent_term ) );
		if ( ! is_wp_error( $found ) && $found ) {
			$term_id = (int) $found[0];
			self::link_term( $term_id, $group_id );
			return $term_id;
		}

		// 3. Category created earlier as a flat top-level one (old behaviour): move it under its parent.
		if ( $parent_term && apply_filters( 'papylon_pangraf_adopt_flat_categories', true ) ) {
			$found = get_terms(
				$base + array(
					'name'       => $name,
					'parent'     => 0,
					'meta_query' => array( array( 'key' => '_pangraf_group_id', 'compare' => 'NOT EXISTS' ) ),
				)
			);
			if ( ! is_wp_error( $found ) && $found ) {
				$term_id = (int) $found[0];
				self::ensure_parent( $term_id, $parent_term );
				self::link_term( $term_id, $group_id );
				return $term_id;
			}
		}

		// 4. Create it.
		$res = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent_term ) );
		if ( is_wp_error( $res ) ) {
			$term_id = (int) $res->get_error_data( 'term_exists' );
			if ( ! $term_id ) {
				self::log( sprintf( 'Category "%s" could not be created: %s', $name, $res->get_error_message() ), 'warning' );
				return 0;
			}
		} else {
			$term_id = (int) $res['term_id'];
		}

		self::link_term( $term_id, $group_id );
		return $term_id;
	}

	private static function link_term( $term_id, $group_id ) {
		if ( $group_id && $term_id ) {
			update_term_meta( $term_id, '_pangraf_group_id', (int) $group_id );
		}
	}

	private static function ensure_parent( $term_id, $parent_term ) {
		$term = get_term( $term_id, 'product_cat' );
		if ( $term && ! is_wp_error( $term ) && (int) $term->parent !== (int) $parent_term ) {
			wp_update_term( $term_id, 'product_cat', array( 'parent' => (int) $parent_term ) );
		}
	}
}
