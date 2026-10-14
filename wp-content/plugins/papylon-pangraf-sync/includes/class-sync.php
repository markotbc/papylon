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
	const QUEUE_OPTION  = 'papylon_pangraf_sync_queue';
	const STATUS_OPTION = 'papylon_pangraf_sync_status';
	const STALE_AFTER   = 3 * HOUR_IN_SECONDS;

	private static $category_cache = array();

	public static function init() {
		add_action( self::RUN_HOOK, array( __CLASS__, 'start' ) );
		add_action( self::BATCH_HOOK, array( __CLASS__, 'process_batch' ) );

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
		return ! empty( $status['state'] ) && 'running' === $status['state'] && $status['started'] > time() - self::STALE_AFTER;
	}

	private static function save_status( array $status ) {
		update_option( self::STATUS_OPTION, $status, false );
	}

	private static function log( $message, $level = 'info' ) {
		wc_get_logger()->log( $level, $message, array( 'source' => self::GROUP ) );
	}

	/* ---------- Run ---------- */

	public static function start() {
		if ( self::is_running() ) {
			self::log( 'Skipped: previous run is still in progress.', 'warning' );
			return;
		}

		$s = Papylon_Pangraf_Settings::get();
		if ( ! $s['supplier_ids'] ) {
			self::fail( __( 'No supplier IDs (id_dobavljac) configured.', 'papylon-pangraf-sync' ) );
			return;
		}

		$api   = new Papylon_Pangraf_API( $s['api_url'], Papylon_Pangraf_Settings::token(), $s['protocol'] );
		$items = $api->get_items( $s['supplier_ids'], $s['price_id_m'] );
		if ( is_wp_error( $items ) ) {
			self::fail( 'Products: ' . $items->get_error_message() );
			return;
		}

		$stock_rows = $api->get_items( $s['supplier_ids'], $s['stock_id_m'] );
		if ( is_wp_error( $stock_rows ) ) {
			self::fail( 'Stock: ' . $stock_rows->get_error_message() );
			return;
		}

		// id_item => quantity (first match wins, like the old script).
		$stock = array();
		foreach ( $stock_rows as $row ) {
			$key = (string) ( $row['id_item'] ?? '' );
			if ( '' !== $key && ! isset( $stock[ $key ] ) ) {
				$qty           = trim( (string) ( $row['quantity'] ?? '' ) );
				$stock[ $key ] = is_numeric( $qty ) ? (int) $qty : 0;
			}
		}

		$queue = array();
		foreach ( $items as $p ) {
			$sku = (string) ( $p['id_item'] ?? '' );
			if ( '' === $sku ) {
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
				'qty'         => $stock[ $sku ] ?? 0,
			);
		}

		update_option( self::QUEUE_OPTION, $queue, false );
		$status = array(
			'state'     => 'running',
			'started'   => time(),
			'finished'  => 0,
			'total'     => count( $queue ),
			'batch'     => (int) $s['batch_size'],
			'processed' => 0,
			'created'   => 0,
			'updated'   => 0,
			'failed'    => 0,
			'images'    => 0,
			'message'   => '',
		);
		self::save_status( $status );
		self::log( sprintf( 'Run started: %d products from Pangraf.', count( $queue ) ) );

		if ( ! $queue ) {
			self::finish( $status );
			return;
		}

		as_enqueue_async_action( self::BATCH_HOOK, array( 0 ), self::GROUP );
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

		if ( 'assign' === $s['categories'] && '' !== $item['group'] ) {
			$term_id = self::category_id( $item['group'] );
			if ( $term_id ) {
				$product->set_category_ids( array( $term_id ) );
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

	/**
	 * product_cat term id for a Pangraf group name, created if missing.
	 */
	private static function category_id( $name ) {
		if ( isset( self::$category_cache[ $name ] ) ) {
			return self::$category_cache[ $name ];
		}

		$term = get_term_by( 'name', $name, 'product_cat' );
		if ( $term ) {
			$id = (int) $term->term_id;
		} else {
			$res = wp_insert_term( $name, 'product_cat' );
			if ( is_wp_error( $res ) ) {
				$id = (int) $res->get_error_data( 'term_exists' );
			} else {
				$id = (int) $res['term_id'];
			}
		}

		self::$category_cache[ $name ] = $id;
		return $id;
	}
}
