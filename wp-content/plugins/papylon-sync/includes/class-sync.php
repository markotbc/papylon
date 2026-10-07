<?php
defined('ABSPATH') || exit;

class Papylon_Sync
{
    const LOCK_KEY = 'papylon_sync_lock';

    /**
     * Main sync entry point. Replace the TODO block with your real logic.
     *
     * @return array|WP_Error
     */
    public static function run()
    {
        // Prevent overlapping runs (cron fires every N minutes).
        if (get_transient(self::LOCK_KEY)) {
            return new WP_Error('locked', 'Previous sync still running.');
        }
        set_transient(self::LOCK_KEY, 1, 10 * MINUTE_IN_SECONDS);

        try {
            // TODO: your sync logic (e.g. WooCommerce products / stock / prices from an external source)
            $processed = 0;

            update_option('papylon_sync_last_run', time(), false);
            return ['processed' => $processed];
        } catch (Throwable $e) {
            error_log('[papylon-sync] ' . $e->getMessage());
            return new WP_Error('sync_failed', $e->getMessage());
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }
}
