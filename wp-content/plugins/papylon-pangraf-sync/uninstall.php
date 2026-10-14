<?php
/**
 * Remove settings, run state and scheduled actions. Imported products are kept.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'papylon_pangraf_sync_settings' );
delete_option( 'papylon_pangraf_sync_queue' );
delete_option( 'papylon_pangraf_sync_status' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'papylon_pangraf_sync_run' );
	as_unschedule_all_actions( 'papylon_pangraf_sync_batch' );
}
