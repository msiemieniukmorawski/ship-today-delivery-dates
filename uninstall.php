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

function wc_stn_uninstall_site()
{
    global $wpdb;

    foreach (['wc_ship_today_notice_options', 'wc_ship_today_notice_style', 'wc_ship_today_notice_version', 'wc_ship_today_notice_block_inserted', 'wc_ship_today_notice_row_inserted', 'wc_ship_today_notice_method_schedules'] as $option) {
        delete_option($option);
    }

    foreach (['wc_ship_today_notice_cron_sync_holidays', 'wc_ship_today_notice_cron_purge_cache'] as $hook) {
        wp_clear_scheduled_hook($hook);
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping own table on uninstall.
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wc_ship_today_notice_holidays'));
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
        switch_to_blog($site_id);
        wc_stn_uninstall_site();
        restore_current_blog();
    }
} else {
    wc_stn_uninstall_site();
}
