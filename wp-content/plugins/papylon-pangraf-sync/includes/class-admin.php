<?php
/**
 * Settings page: WooCommerce → Pangraf sync.
 */

defined( 'ABSPATH' ) || exit;

class Papylon_Pangraf_Admin {

	const PAGE   = 'papylon-pangraf-sync';
	const GROUP  = 'papylon_pangraf_sync';
	const NOTICE = 'papylon_pangraf_sync_notice_';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_papylon_pangraf_sync_run', array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_papylon_pangraf_sync_test', array( __CLASS__, 'handle_test' ) );

		// Shop managers may save the form too, not only admins.
		add_filter(
			'option_page_capability_' . self::GROUP,
			function () {
				return 'manage_woocommerce';
			}
		);
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Pangraf sync', 'papylon-pangraf-sync' ), __( 'Pangraf sync', 'papylon-pangraf-sync' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting(
			self::GROUP,
			Papylon_Pangraf_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Papylon_Pangraf_Settings', 'sanitize' ),
				'default'           => Papylon_Pangraf_Settings::defaults(),
			)
		);
	}

	/* ---------- Actions ---------- */

	private static function check( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'papylon-pangraf-sync' ) );
		}
		check_admin_referer( $action );
	}

	private static function redirect_with_notice( $message, $type = 'success' ) {
		set_transient( self::NOTICE . get_current_user_id(), array( $message, $type ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	public static function handle_run() {
		self::check( 'papylon_pangraf_sync_run' );

		if ( Papylon_Pangraf_Sync::run_now() ) {
			self::redirect_with_notice( __( 'Sync queued. It runs in the background; refresh this page to follow progress.', 'papylon-pangraf-sync' ) );
		}
		self::redirect_with_notice( __( 'A sync is already running.', 'papylon-pangraf-sync' ), 'warning' );
	}

	public static function handle_test() {
		self::check( 'papylon_pangraf_sync_test' );

		$s     = Papylon_Pangraf_Settings::get();
		$api   = new Papylon_Pangraf_API( $s['api_url'], Papylon_Pangraf_Settings::token(), $s['protocol'] );
		$items = $api->get_items( $s['supplier_ids'], $s['price_id_m'] );

		if ( is_wp_error( $items ) ) {
			self::redirect_with_notice( $items->get_error_message(), 'error' );
		}
		/* translators: %d: number of products */
		self::redirect_with_notice( sprintf( __( 'Connection OK: Pangraf returned %d products. Nothing was imported.', 'papylon-pangraf-sync' ), count( $items ) ) );
	}

	/* ---------- Page ---------- */

	public static function render() {
		$s          = Papylon_Pangraf_Settings::get();
		$name       = Papylon_Pangraf_Settings::OPTION;
		$token_lock = defined( 'PAPYLON_PANGRAF_TOKEN' ) && PAPYLON_PANGRAF_TOKEN;
		$notice     = get_transient( self::NOTICE . get_current_user_id() );
		delete_transient( self::NOTICE . get_current_user_id() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pangraf sync', 'papylon-pangraf-sync' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[1] ); ?> is-dismissible"><p><?php echo esc_html( $notice[0] ); ?></p></div>
			<?php endif; ?>

			<?php self::render_status(); ?>

			<div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<input type="hidden" name="action" value="papylon_pangraf_sync_run">
					<?php wp_nonce_field( 'papylon_pangraf_sync_run' ); ?>
					<?php submit_button( __( 'Run sync now', 'papylon-pangraf-sync' ), 'primary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<input type="hidden" name="action" value="papylon_pangraf_sync_test">
					<?php wp_nonce_field( 'papylon_pangraf_sync_test' ); ?>
					<?php submit_button( __( 'Test connection', 'papylon-pangraf-sync' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'Schedule', 'papylon-pangraf-sync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic sync', 'papylon-pangraf-sync' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Enabled', 'papylon-pangraf-sync' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="pps-frequency"><?php esc_html_e( 'Repeat', 'papylon-pangraf-sync' ); ?></label></th>
						<td>
							<select id="pps-frequency" name="<?php echo esc_attr( $name ); ?>[frequency]">
								<?php foreach ( Papylon_Pangraf_Settings::frequencies() as $key => $freq ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['frequency'], $key ); ?>><?php echo esc_html( $freq[0] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pps-start"><?php esc_html_e( 'Starting at', 'papylon-pangraf-sync' ); ?></label></th>
						<td>
							<input id="pps-start" type="time" name="<?php echo esc_attr( $name ); ?>[start_time]" value="<?php echo esc_attr( $s['start_time'] ); ?>">
							<p class="description"><?php echo esc_html( sprintf( /* translators: %s: timezone */ __( 'Site timezone (%s). Later runs repeat from this time.', 'papylon-pangraf-sync' ), wp_timezone_string() ) ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Pangraf API', 'papylon-pangraf-sync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pps-url"><?php esc_html_e( 'getItems URL', 'papylon-pangraf-sync' ); ?></label></th>
						<td>
							<input id="pps-url" type="url" class="large-text" name="<?php echo esc_attr( $name ); ?>[api_url]" value="<?php echo esc_attr( $s['api_url'] ); ?>" placeholder="http://host:port/Pangraf/Main/getItems">
							<p class="description"><?php esc_html_e( 'Without ?token=… — token and protocol are added automatically.', 'papylon-pangraf-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pps-token"><?php esc_html_e( 'Token', 'papylon-pangraf-sync' ); ?></label></th>
						<td>
							<?php if ( $token_lock ) : ?>
								<p><?php esc_html_e( 'Set in wp-config.php (PAPYLON_PANGRAF_TOKEN).', 'papylon-pangraf-sync' ); ?></p>
							<?php else : ?>
								<input id="pps-token" type="password" autocomplete="new-password" class="regular-text" name="<?php echo esc_attr( $name ); ?>[api_token]" value="" placeholder="<?php echo $s['api_token'] ? esc_attr__( '•••••• (saved — leave empty to keep)', 'papylon-pangraf-sync' ) : ''; ?>">
								<?php if ( $s['api_token'] ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_token]" value="1"> <?php esc_html_e( 'Clear', 'papylon-pangraf-sync' ); ?></label>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pps-protocol"><?php esc_html_e( 'Protocol', 'papylon-pangraf-sync' ); ?></label></th>
						<td><input id="pps-protocol" type="text" class="small-text" name="<?php echo esc_attr( $name ); ?>[protocol]" value="<?php echo esc_attr( $s['protocol'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pps-suppliers"><?php esc_html_e( 'Supplier IDs (id_dobavljac)', 'papylon-pangraf-sync' ); ?></label></th>
						<td>
							<textarea id="pps-suppliers" class="large-text" rows="3" name="<?php echo esc_attr( $name ); ?>[supplier_ids]"><?php echo esc_textarea( implode( ', ', $s['supplier_ids'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Comma, space or newline separated.', 'papylon-pangraf-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pps-price-m"><?php esc_html_e( 'Products & prices (id_m)', 'papylon-pangraf-sync' ); ?></label></th>
						<td><input id="pps-price-m" type="number" min="0" class="small-text" name="<?php echo esc_attr( $name ); ?>[price_id_m]" value="<?php echo esc_attr( $s['price_id_m'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pps-stock-m"><?php esc_html_e( 'Stock quantities (id_m)', 'papylon-pangraf-sync' ); ?></label></th>
						<td><input id="pps-stock-m" type="number" min="0" class="small-text" name="<?php echo esc_attr( $name ); ?>[stock_id_m]" value="<?php echo esc_attr( $s['stock_id_m'] ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Import', 'papylon-pangraf-sync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pps-image"><?php esc_html_e( 'Image URL pattern', 'papylon-pangraf-sync' ); ?></label></th>
						<td>
							<input id="pps-image" type="text" class="large-text" name="<?php echo esc_attr( $name ); ?>[image_url_pattern]" value="<?php echo esc_attr( $s['image_url_pattern'] ); ?>" placeholder="https://example.com/{id_item}_{code}.png">
							<p class="description"><?php esc_html_e( 'Placeholders: {id_item}, {code}. Empty = no images. An image is only downloaded when the product has none or the URL changed.', 'papylon-pangraf-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Categories', 'papylon-pangraf-sync' ); ?></th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[categories]" value="ignore" <?php checked( $s['categories'], 'ignore' ); ?>> <?php esc_html_e( 'Don\'t change product categories', 'papylon-pangraf-sync' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[categories]" value="assign" <?php checked( $s['categories'], 'assign' ); ?>> <?php esc_html_e( 'Set category from Pangraf "group" (created if missing; replaces existing categories)', 'papylon-pangraf-sync' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pps-batch"><?php esc_html_e( 'Batch size', 'papylon-pangraf-sync' ); ?></label></th>
						<td>
							<input id="pps-batch" type="number" min="1" max="200" class="small-text" name="<?php echo esc_attr( $name ); ?>[batch_size]" value="<?php echo esc_attr( $s['batch_size'] ); ?>">
							<p class="description"><?php esc_html_e( 'Products per background step. Lower it if steps time out.', 'papylon-pangraf-sync' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private static function render_status() {
		$status = Papylon_Pangraf_Sync::status();
		$next   = Papylon_Pangraf_Sync::next_run();
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$logs   = admin_url( 'admin.php?page=wc-status&tab=logs&source=' . Papylon_Pangraf_Sync::GROUP );
		?>
		<table class="widefat striped" style="max-width:720px;margin:1em 0">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Next scheduled run', 'papylon-pangraf-sync' ); ?></th>
					<td><?php echo is_int( $next ) ? esc_html( wp_date( $format, $next ) ) : esc_html__( 'Not scheduled', 'papylon-pangraf-sync' ); ?></td>
				</tr>
				<?php if ( $status ) : ?>
					<tr>
						<th><?php esc_html_e( 'Last run', 'papylon-pangraf-sync' ); ?></th>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: state, 2: start date, 3: processed, 4: total */
									__( '%1$s — started %2$s, %3$d / %4$d processed', 'papylon-pangraf-sync' ),
									$status['state'],
									wp_date( $format, $status['started'] ?? 0 ),
									$status['processed'] ?? 0,
									$status['total'] ?? 0
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Result', 'papylon-pangraf-sync' ); ?></th>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: created, 2: updated, 3: failed, 4: images */
									__( '%1$d created, %2$d updated, %3$d failed, %4$d images', 'papylon-pangraf-sync' ),
									$status['created'] ?? 0,
									$status['updated'] ?? 0,
									$status['failed'] ?? 0,
									$status['images'] ?? 0
								)
							);
							?>
							<?php if ( ! empty( $status['message'] ) ) : ?>
								<br><strong><?php echo esc_html( $status['message'] ); ?></strong>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th><?php esc_html_e( 'Logs', 'papylon-pangraf-sync' ); ?></th>
					<td><a href="<?php echo esc_url( $logs ); ?>"><?php esc_html_e( 'WooCommerce → Status → Logs', 'papylon-pangraf-sync' ); ?></a></td>
				</tr>
			</tbody>
		</table>
		<?php
	}
}
