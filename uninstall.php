<?php
/**
 * Plugin removal: options, holidays table, cron events.
 *
 * Order meta stays: it is order data, not settings.
 * The main plugin file is not loaded here, so the names are written out.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function stdd_uninstall_site()
{
    global $wpdb;

    foreach (['stdd_options', 'stdd_style', 'stdd_version', 'stdd_block_inserted', 'stdd_row_inserted', 'stdd_method_schedules'] as $option) {
        delete_option($option);
    }

    foreach (['stdd_cron_sync_holidays', 'stdd_cron_purge_cache'] as $hook) {
        wp_clear_scheduled_hook($hook);
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping own table on uninstall.
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'stdd_holidays'));
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $stdd_site_id) {
        switch_to_blog($stdd_site_id);
        stdd_uninstall_site();
        restore_current_blog();
    }
} else {
    stdd_uninstall_site();
}
