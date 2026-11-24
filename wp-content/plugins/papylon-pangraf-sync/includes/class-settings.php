<?php
/**
 * Plugin settings (stored in one option, never in Git).
 */

defined( 'ABSPATH' ) || exit;

class Papylon_Pangraf_Settings {

	const OPTION = 'papylon_pangraf_sync_settings';

	public static function defaults() {
		return array(
			'enabled'           => 0,
			'api_url'           => '',
			'api_token'         => '',
			'protocol'          => '2.0',
			'supplier_ids'      => array(),
			'price_id_m'        => 4,
			'stock_ids'         => array( 3, 4, 5, 6, 7, 8, 12, 9 ),
			'image_url_pattern' => '',
			'categories'        => 'ignore',
			'only_in_stock'     => 1,
			'frequency'         => 'daily',
			'start_time'        => '03:00',
			'batch_size'        => 25,
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		// Settings saved before multi-unit stock support only have a single stock_id_m.
		if ( empty( $saved['stock_ids'] ) && ! empty( $saved['stock_id_m'] ) ) {
			$saved['stock_ids'] = array( absint( $saved['stock_id_m'] ) );
		}

		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * The token can be pinned in wp-config.php with PAPYLON_PANGRAF_TOKEN instead of the database.
	 */
	public static function token() {
		if ( defined( 'PAPYLON_PANGRAF_TOKEN' ) && PAPYLON_PANGRAF_TOKEN ) {
			return PAPYLON_PANGRAF_TOKEN;
		}
		return self::get()['api_token'];
	}

	public static function frequencies() {
		return array(
			'hourly'  => array( __( 'Every hour', 'papylon-pangraf-sync' ), HOUR_IN_SECONDS ),
			'3hours'  => array( __( 'Every 3 hours', 'papylon-pangraf-sync' ), 3 * HOUR_IN_SECONDS ),
			'6hours'  => array( __( 'Every 6 hours', 'papylon-pangraf-sync' ), 6 * HOUR_IN_SECONDS ),
			'12hours' => array( __( 'Every 12 hours', 'papylon-pangraf-sync' ), 12 * HOUR_IN_SECONDS ),
			'daily'   => array( __( 'Once a day', 'papylon-pangraf-sync' ), DAY_IN_SECONDS ),
			'weekly'  => array( __( 'Once a week', 'papylon-pangraf-sync' ), WEEK_IN_SECONDS ),
		);
	}

	/**
	 * Parse "12, 34\n56" into a list of unique positive ints.
	 */
	public static function parse_ids( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ',', $value );
		}
		$ids = array_map( 'absint', preg_split( '/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY ) );
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$old   = self::get();
		$out   = self::defaults();

		$out['enabled']  = empty( $input['enabled'] ) ? 0 : 1;
		$out['api_url']  = esc_url_raw( trim( $input['api_url'] ?? '' ) );
		$out['protocol'] = sanitize_text_field( $input['protocol'] ?? '' ) ?: '2.0';

		// Empty password field = keep the stored token.
		$token              = trim( (string) ( $input['api_token'] ?? '' ) );
		$out['api_token']   = '' === $token ? $old['api_token'] : sanitize_text_field( $token );
		if ( ! empty( $input['clear_token'] ) ) {
			$out['api_token'] = '';
		}

		$out['price_id_m']   = absint( $input['price_id_m'] ?? 4 );
		$out['supplier_ids'] = self::parse_ids( $input['supplier_ids'] ?? '' );
		$out['stock_ids']    = self::parse_ids( $input['stock_ids'] ?? '' ) ?: array( 9 );

		// esc_url_raw() would strip the {placeholders}.
		$pattern                  = sanitize_text_field( $input['image_url_pattern'] ?? '' );
		$out['image_url_pattern'] = preg_match( '#^https?://#i', $pattern ) ? $pattern : '';

		$out['only_in_stock'] = empty( $input['only_in_stock'] ) ? 0 : 1;
		$out['categories'] = in_array( $input['categories'] ?? '', array( 'ignore', 'assign' ), true ) ? $input['categories'] : 'ignore';
		$out['frequency']  = array_key_exists( $input['frequency'] ?? '', self::frequencies() ) ? $input['frequency'] : 'daily';
		$out['start_time'] = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $input['start_time'] ?? '' ) ? $input['start_time'] : '03:00';
		$out['batch_size'] = min( 200, max( 1, absint( $input['batch_size'] ?? 25 ) ) );

		return $out;
	}
}
