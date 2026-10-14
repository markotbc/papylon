<?php
/**
 * Pangraf getItems client.
 */

defined( 'ABSPATH' ) || exit;

class Papylon_Pangraf_API {

	private $url;
	private $token;
	private $protocol;

	public function __construct( $url, $token, $protocol = '2.0' ) {
		$this->url      = $url;
		$this->token    = $token;
		$this->protocol = $protocol;
	}

	/**
	 * @param int[] $supplier_ids id_dobavljac list.
	 * @param int   $id_m         Warehouse / price list id.
	 * @return array|WP_Error     The "Data" rows.
	 */
	public function get_items( array $supplier_ids, $id_m ) {
		if ( ! $this->url || ! $this->token ) {
			return new WP_Error( 'pangraf_config', __( 'API URL or token is not set.', 'papylon-pangraf-sync' ) );
		}

		$url = add_query_arg(
			array(
				'token'    => rawurlencode( $this->token ),
				'protocol' => rawurlencode( $this->protocol ),
			),
			$this->url
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 120,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'id_dobavljac' => array_values( array_map( 'intval', $supplier_ids ) ),
						'id_m'         => (int) $id_m,
					)
				),
			)
		);

		// Never include $url in messages: it contains the token.
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'pangraf_http', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'pangraf_http', sprintf( __( 'Pangraf API returned HTTP %d.', 'papylon-pangraf-sync' ), $code ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['Data'] ) || ! is_array( $data['Data'] ) ) {
			return new WP_Error( 'pangraf_response', __( 'Unexpected response from Pangraf API (no "Data").', 'papylon-pangraf-sync' ) );
		}

		return $data['Data'];
	}
}
