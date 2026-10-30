<?php
/**
 * Usuwanie wtyczki (Wtyczki → Usuń): opcje, tabela świąt, zdarzenia cron.
 *
 * Meta zamówień (_wc_stn_ship_date, _wc_stn_delivery_date, _wc_stn_cutoff) zostają: to dane zamówień, nie ustawienia.
 * Plik główny wtyczki nie jest tu wczytany, więc nazwy są wpisane wprost (jak stałe w WC_Ship_Today_Notice).
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

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- usunięcie własnej tabeli przy deinstalacji.
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
