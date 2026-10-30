<?php

/**
 * Plugin Name: Powiadomienie o wysyłce dziś
 * Description: Countdown to the order cutoff time with the next ship and delivery dates (shortcode [ship_today_notice]), plus an optional ship date choice at checkout.
 * Version: 2.1.0
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: MS-M.pl
 * Author URI: https://ms-m.pl
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-ship-today-notice
 * Domain Path: /languages
 * WC requires at least: 9.4
 * WC tested up to: 11.2
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WC_STN_VERSION', '2.1.0');
define('WC_STN_FILE', __FILE__);

require_once __DIR__ . '/includes/class-wc-stn-schedule.php';
require_once __DIR__ . '/includes/class-wc-stn-checkout.php';
require_once __DIR__ . '/includes/class-wc-stn-checkout-blocks.php';
require_once __DIR__ . '/includes/functions.php';

final class WC_Ship_Today_Notice
{
    const OPTION_KEY       = 'wc_ship_today_notice_options';
    const STYLE_KEY        = 'wc_ship_today_notice_style';
    const VERSION_KEY      = 'wc_ship_today_notice_version';
    const CRON_HOOK        = 'wc_ship_today_notice_cron_sync_holidays';
    const CRON_PURGE_HOOK  = 'wc_ship_today_notice_cron_purge_cache';
    const TABLE_NAME       = 'wc_ship_today_notice_holidays';
    const WPML_DOMAIN      = 'wc-ship-today-notice';

    /** Ile dni harmonogramu trafia do strony (musi pokryć czas trzymania strony w cache). */
    const FRONTEND_DAYS = 42;

    /** @var self|null */
    private static $instance = null;

    /** @var WC_STN_Schedule|null */
    private $schedule = null;

    /** @var int Przesunięcie "teraz" w sekundach w trybie testowym (?stn_now=). */
    private $now_offset = 0;

    /** @var string|false Hook strony ustawień (do ładowania skryptów zakładki "Styl"). */
    private $page_hook = false;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        register_activation_hook(__FILE__, [$this, 'on_activate']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivate']);

        // Tłumaczenia ładuje WordPress (WP 6.8+: Domain Path z nagłówka, pierwszeństwo ma wp-content/languages/plugins/)

        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'maybe_upgrade'], 5);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        // Panel jest dla manage_woocommerce, a options.php domyślnie wymaga manage_options
        add_filter('option_page_capability_' . self::OPTION_KEY, [$this, 'settings_capability']);
        add_filter('option_page_capability_' . self::STYLE_KEY, [$this, 'settings_capability']);
        add_action('before_woocommerce_init', [$this, 'declare_wc_compatibility']);

        add_action('init', [$this, 'register_shortcode']);
        add_action('init', [$this, 'maybe_enable_test_mode'], 1);
        add_action('init', [$this, 'maybe_schedule_cache_purge']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);

        add_action(self::CRON_HOOK, [$this, 'cron_sync_holidays']);
        add_action(self::CRON_PURGE_HOOK, [$this, 'cron_purge_cache']);
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        add_action('update_option_' . self::OPTION_KEY, [$this, 'on_options_updated'], 10, 2);
        add_action('add_option_' . self::OPTION_KEY, [$this, 'on_options_added'], 10, 2);
        // Pierwszy zapis stylu to add_option, kolejne to update_option
        add_action('add_option_' . self::STYLE_KEY, [$this, 'on_style_updated']);
        add_action('update_option_' . self::STYLE_KEY, [$this, 'on_style_updated']);

        // Na init, a nie przy wczytaniu pliku: get_options() woła __(), a tłumaczenia nie mogą się ładować przed init
        add_action('init', [$this, 'init_checkout'], 0);
    }

    public function init_checkout()
    {
        new WC_STN_Checkout_Blocks($this, new WC_STN_Checkout($this));
    }

    /**
     * Moduł zamówień używa tylko CRUD (get_meta / update_meta_data), więc działa z HPOS.
     * Checkout blokowy obsługuje WC_STN_Checkout_Blocks.
     */
    public function declare_wc_compatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', WC_STN_FILE, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', WC_STN_FILE, true);
        }
    }

    public function settings_capability()
    {
        return 'manage_woocommerce';
    }

    /**
     * Przesunięcie "teraz" w sekundach w trybie testowym (0 poza nim).
     */
    public function now_offset()
    {
        return $this->now_offset;
    }

    /**
     * Silnik terminów na aktualnych ustawieniach.
     */
    public function schedule()
    {
        if (null === $this->schedule) {
            $this->schedule = new WC_STN_Schedule($this->get_options());
        }
        return $this->schedule;
    }

    /* ---------------------------------------------------------
     * Activation / Deactivation / Upgrade
     * --------------------------------------------------------- */
    public function on_activate()
    {
        $this->maybe_create_table();

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'every_four_months', self::CRON_HOOK);
        }
        // Święta od razu (przy najbliższym wywołaniu WP-Cron), bez blokowania aktywacji zapytaniem do API
        wp_schedule_single_event(time(), self::CRON_HOOK);
    }

    public function on_deactivate()
    {
        foreach ([self::CRON_HOOK, self::CRON_PURGE_HOOK] as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    /**
     * 1.x → 2.0: wykluczenia świąt zapisane po ID wiersza zamieniamy na daty
     * (ID zmieniały się przy każdym pobraniu świąt, więc wykluczenia przepadały).
     */
    public function maybe_upgrade()
    {
        $installed = get_option(self::VERSION_KEY, '1.0.0');

        if (version_compare($installed, WC_STN_VERSION, '>=')) {
            return;
        }

        $this->maybe_create_table();

        $saved = get_option(self::OPTION_KEY, []);

        if (is_array($saved) && !empty($saved['excluded_holidays']) && empty($saved['excluded_holiday_dates'])) {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_NAME;
            $ids   = array_map('intval', (array) $saved['excluded_holidays']);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- jednorazowa migracja 1.x, własna tabela.
            $dates = $wpdb->get_col($wpdb->prepare('SELECT holiday_date FROM %i WHERE id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')', array_merge([$table], $ids)));
            $saved['excluded_holiday_dates'] = array_values(array_unique((array) $dates));
            unset($saved['excluded_holidays']);
            update_option(self::OPTION_KEY, $saved);
        }

        update_option(self::VERSION_KEY, WC_STN_VERSION);
    }

    public function add_cron_schedules($schedules)
    {
        $schedules['every_four_months'] = [
            'interval' => 4 * 30 * DAY_IN_SECONDS, // ~4 months; 3x per year
            'display'  => __('Every four months', 'wc-ship-today-notice')
        ];
        return $schedules;
    }

    private function maybe_create_table()
    {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            country_code VARCHAR(4) NOT NULL,
            holiday_date DATE NOT NULL,
            holiday_name VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY country_date (country_code, holiday_date)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /* ---------------------------------------------------------
     * Tryb testowy i cache
     * --------------------------------------------------------- */

    /**
     * ?stn_now=2026-10-25T19:59 (tylko dla managera sklepu) podmienia "teraz" w PHP i w JS.
     */
    public function maybe_enable_test_mode()
    {
        if (empty($_GET['stn_now']) || !current_user_can('manage_woocommerce')) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        try {
            $fake = new DateTimeImmutable(sanitize_text_field(wp_unslash($_GET['stn_now'])), wp_timezone()); // phpcs:ignore WordPress.Security.NonceVerification
        } catch (Exception $e) {
            return;
        }

        $this->now_offset = $fake->getTimestamp() - time();
        $offset           = $this->now_offset;

        // Ze znacznika czasu, nie przez modify('+N seconds'): modify liczy po czasie zegarowym
        // i przy zmianie czasu (np. 25.10) myli się o godzinę.
        add_filter('wc_ship_today_notice_now', function () use ($offset) {
            return (new DateTimeImmutable('@' . (time() + $offset)))->setTimezone(wp_timezone());
        });
    }

    public function on_options_updated($old, $new)
    {
        $this->schedule = null;
        $this->wpml_register_strings($old, $new);

        // Inny kraj, język albo region = inne święta: pobieramy od razu, żeby lista w panelu i terminy były aktualne
        foreach (['country_code', 'language_code', 'holiday_subdivision'] as $key) {
            if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                if (!$this->fetch_and_store_holidays()) {
                    add_settings_error(self::OPTION_KEY, 'holidays_fetch', __('Failed to update holidays.', 'wc-ship-today-notice'), 'error');
                }
                break;
            }
        }

        $this->purge_cache();
        $this->reschedule_cache_purge();
    }

    /** Pierwszy zapis ustawień to add_option, a nie update_option. */
    public function on_options_added($option, $value)
    {
        $this->on_options_updated([], $value);
    }

    public function on_style_updated()
    {
        $this->purge_cache();
    }

    /**
     * Strony z cache mają tekst startowy policzony przy renderze. JS i tak przelicza termin,
     * ale czyścimy cache o godzinie granicznej, żeby tekst startowy też był aktualny.
     */
    private function purge_cache()
    {
        do_action('litespeed_purge_all');
        do_action('wc_ship_today_notice_purge_cache');
    }

    public function cron_purge_cache()
    {
        $this->purge_cache();
        $this->reschedule_cache_purge();
    }

    /** Czy jest co czyścić o godzinie granicznej: LiteSpeed Cache albo podpięta akcja (te same, które woła purge_cache()). */
    private function cache_purge_enabled()
    {
        return defined('LSCWP_V') || has_action('wc_ship_today_notice_purge_cache');
    }

    public function maybe_schedule_cache_purge()
    {
        $scheduled = wp_next_scheduled(self::CRON_PURGE_HOOK);

        if (!$this->cache_purge_enabled()) {
            // Np. po wyłączeniu LiteSpeed: zdejmujemy zaplanowane czyszczenie, żeby się dalej nie przeplanowywało
            if ($scheduled) {
                wp_clear_scheduled_hook(self::CRON_PURGE_HOOK);
            }
            return;
        }
        if (!$scheduled) {
            $this->reschedule_cache_purge();
        }
    }

    private function reschedule_cache_purge()
    {
        wp_clear_scheduled_hook(self::CRON_PURGE_HOOK);

        if (!$this->cache_purge_enabled()) {
            return;
        }

        $slots = $this->schedule()->slots(1);
        if ($slots) {
            wp_schedule_single_event($slots[0]['cutoff']->getTimestamp() + 5, self::CRON_PURGE_HOOK);
        }
    }

    /* ---------------------------------------------------------
     * Admin Menu / Settings
     * --------------------------------------------------------- */
    public function register_admin_menu()
    {
        $this->page_hook = add_submenu_page(
            'woocommerce',
            __('Ship Today Notice', 'wc-ship-today-notice'),
            __('Ship Today Notice', 'wc-ship-today-notice'),
            'manage_woocommerce',
            'wc-ship-today-notice',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings()
    {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [$this, 'sanitize_options']);
        register_setting(self::STYLE_KEY, self::STYLE_KEY, [$this, 'sanitize_style']);

        // --- Sekcja: Shortcode Info ---
        add_settings_section(
            'wc_ship_today_shortcode',
            __('Shortcode', 'wc-ship-today-notice'),
            [$this, 'section_shortcode_info'],
            'wc-ship-today-notice'
        );

        // --- Sekcja: Main settings ---
        add_settings_section(
            'wc_ship_today_main',
            __('Main settings', 'wc-ship-today-notice'),
            '__return_false',
            'wc-ship-today-notice'
        );

        add_settings_field('mode', 'Tryb komunikatu', [$this, 'field_mode'], 'wc-ship-today-notice', 'wc_ship_today_main');
        add_settings_field('delivery_days', __('Delivery weekdays & cutoff times', 'wc-ship-today-notice'), [$this, 'field_delivery_days'], 'wc-ship-today-notice', 'wc_ship_today_main');
        add_settings_field('delivery', 'Dostawa', [$this, 'field_delivery'], 'wc-ship-today-notice', 'wc_ship_today_main');
        add_settings_field('excluded_dates', __('Excluded dates', 'wc-ship-today-notice'), [$this, 'field_excluded_dates'], 'wc-ship-today-notice', 'wc_ship_today_main');
        add_settings_field('preview', 'Najbliższe terminy', [$this, 'field_preview'], 'wc-ship-today-notice', 'wc_ship_today_main');

        // --- Sekcja: wybór terminu w checkoucie ---
        add_settings_section(
            'wc_ship_today_checkout',
            'Wybór terminu w checkoucie',
            [$this, 'section_checkout_info'],
            'wc-ship-today-notice'
        );
        add_settings_field('checkout_enabled', 'Moduł', [$this, 'field_checkout_enabled'], 'wc-ship-today-notice', 'wc_ship_today_checkout');
        add_settings_field('checkout_tiles', 'Kafelki', [$this, 'field_checkout_tiles'], 'wc-ship-today-notice', 'wc_ship_today_checkout');
        if (WC_STN_Checkout::iconic_active()) {
            add_settings_field('disable_iconic', 'Iconic Delivery Slots', [$this, 'field_disable_iconic'], 'wc-ship-today-notice', 'wc_ship_today_checkout');
        }

        // --- Sekcja: Visibility ---
        add_settings_section(
            'wc_ship_today_visibility',
            __('Widget visibility', 'wc-ship-today-notice'),
            '__return_false',
            'wc-ship-today-notice'
        );

        add_settings_field('visibility_mode', __('Display widget', 'wc-ship-today-notice'), [$this, 'field_visibility_mode'], 'wc-ship-today-notice', 'wc_ship_today_visibility');

        // --- Sekcja: Countdown format ---
        add_settings_section(
            'wc_ship_today_countdown',
            __('Countdown format', 'wc-ship-today-notice'),
            '__return_false',
            'wc-ship-today-notice'
        );

        add_settings_field('countdown_format', __('Time labels', 'wc-ship-today-notice'), [$this, 'field_countdown_format'], 'wc-ship-today-notice', 'wc_ship_today_countdown');

        // --- Sekcja: Public holidays API ---
        add_settings_section(
            'wc_ship_today_holidays',
            __('Public holidays API', 'wc-ship-today-notice'),
            [$this, 'section_holidays_info'],
            'wc-ship-today-notice'
        );

        add_settings_field('country_code', __('Country ISO code', 'wc-ship-today-notice'), [$this, 'field_country_code'], 'wc-ship-today-notice', 'wc_ship_today_holidays');
        add_settings_field('language_code', __('Language ISO code', 'wc-ship-today-notice'), [$this, 'field_language_code'], 'wc-ship-today-notice', 'wc_ship_today_holidays');
        add_settings_field('holiday_subdivision', __('Region', 'wc-ship-today-notice'), [$this, 'field_holiday_subdivision'], 'wc-ship-today-notice', 'wc_ship_today_holidays');
        add_settings_field('holidays_list', __('Fetched holidays', 'wc-ship-today-notice'), [$this, 'field_holidays_list'], 'wc-ship-today-notice', 'wc_ship_today_holidays');

        // --- Sekcja: Frontend texts ---
        add_settings_section(
            'wc_ship_today_texts',
            __('Frontend texts', 'wc-ship-today-notice'),
            [$this, 'section_texts_info'],
            'wc-ship-today-notice'
        );
        add_settings_field('text_next_slot', 'Tekst w trybie "najbliższy termin"', [$this, 'field_text_next_slot'], 'wc-ship-today-notice', 'wc_ship_today_texts');
        add_settings_field('text_before_cutoff', __('Text before cutoff', 'wc-ship-today-notice'), [$this, 'field_text_before_cutoff'], 'wc-ship-today-notice', 'wc_ship_today_texts');
        add_settings_field('text_after_cutoff', __('Text after cutoff', 'wc-ship-today-notice'), [$this, 'field_text_after_cutoff'], 'wc-ship-today-notice', 'wc_ship_today_texts');
        add_settings_field('text_not_shipping_day', __('Text when not a shipping day', 'wc-ship-today-notice'), [$this, 'field_text_not_shipping_day'], 'wc-ship-today-notice', 'wc_ship_today_texts');
    }

    public function sanitize_options($input)
    {
        $options = $this->get_options();

        // Cutoff time - legacy fallback (bez pola w formularzu: zostaje poprzednia wartość)
        if (preg_match('/^\d{2}:\d{2}$/', $input['cutoff_time'] ?? '')) {
            $options['cutoff_time'] = $input['cutoff_time'];
        }

        // Mode
        $options['mode'] = in_array($input['mode'] ?? '', ['today', 'next_slot'], true) ? $input['mode'] : 'today';

        // Delivery days
        $options['delivery_days'] = array_map('intval', $input['delivery_days'] ?? []);

        // Cutoff times and offsets per day
        foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
            $time = $input['cutoff_times'][$day] ?? '';
            if (preg_match('/^\d{2}:\d{2}$/', $time)) {
                $options['cutoff_times'][$day] = $time;
            }
            $options['cutoff_offsets'][$day] = max(0, min(6, (int) ($input['cutoff_offsets'][$day] ?? 0)));
        }

        // Delivery
        $options['delivery_offset']  = max(0, min(14, (int) ($input['delivery_offset'] ?? 0)));
        $options['no_delivery_days'] = array_map('intval', $input['no_delivery_days'] ?? []);

        // Excluded dates (now from textarea, one per line or comma-separated)
        $options['excluded_dates'] = sanitize_textarea_field($input['excluded_dates'] ?? '');

        // Ignored holidays (dates Y-m-d)
        $options['excluded_holiday_dates'] = array_values(array_filter(
            array_map('sanitize_text_field', (array) ($input['excluded_holiday_dates'] ?? [])),
            function ($date) {
                return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
            }
        ));

        // Country & language codes: dwie litery, błędna wartość = poprzednia + komunikat
        $codes = [
            'country_code'  => __('Country ISO code', 'wc-ship-today-notice'),
            'language_code' => __('Language ISO code', 'wc-ship-today-notice'),
        ];
        foreach ($codes as $key => $label) {
            if (!isset($input[$key])) {
                continue;
            }
            $value = strtoupper(trim(sanitize_text_field($input[$key])));
            if (preg_match('/^[A-Z]{2}$/', $value)) {
                $options[$key] = $value;
            } else {
                add_settings_error(self::OPTION_KEY, 'invalid_' . $key, sprintf(
                    /* translators: 1: field label, 2: entered value, 3: previous value */
                    __('%1$s: "%2$s" is not a two-letter code. The previous value %3$s was kept.', 'wc-ship-today-notice'),
                    esc_html($label),
                    esc_html($value),
                    esc_html($options[$key])
                ));
            }
        }

        // Region (ISO 3166-2, np. DE-BY): puste = tylko święta ogólnokrajowe
        if (isset($input['holiday_subdivision'])) {
            $value = strtoupper(trim(sanitize_text_field($input['holiday_subdivision'])));
            if ('' === $value || self::valid_subdivision($value, $options['country_code'])) {
                $options['holiday_subdivision'] = $value;
            } else {
                $options['holiday_subdivision'] = '';
                add_settings_error(self::OPTION_KEY, 'invalid_holiday_subdivision', sprintf(
                    /* translators: 1: entered value, 2: country code */
                    __('Region "%1$s" is not a region code of %2$s (e.g. DE-BY). Only nationwide holidays are used.', 'wc-ship-today-notice'),
                    esc_html($value),
                    esc_html($options['country_code'])
                ));
            }
        }

        // Frontend texts
        $options['text_next_slot'] = wp_kses_post($input['text_next_slot'] ?? '');
        $options['text_before_cutoff'] = wp_kses_post($input['text_before_cutoff'] ?? '');
        $options['text_after_cutoff'] = wp_kses_post($input['text_after_cutoff'] ?? '');
        $options['text_not_shipping_day'] = wp_kses_post($input['text_not_shipping_day'] ?? '');

        // Activation checkboxes
        $options['show_after_cutoff'] = !empty($input['show_after_cutoff']);
        $options['show_not_shipping_day'] = !empty($input['show_not_shipping_day']);

        // Visibility settings
        $allowed_modes = ['disabled', 'always', 'before_cutoff_only', 'hours_before'];
        $options['visibility_mode'] = in_array($input['visibility_mode'] ?? '', $allowed_modes, true)
            ? $input['visibility_mode']
            : 'always';
        $options['visibility_hours_before'] = max(0, (float) ($input['visibility_hours_before'] ?? 1));

        // Countdown format settings
        $options['countdown_label_h'] = sanitize_text_field($input['countdown_label_h'] ?? 'h');
        $options['countdown_label_m'] = sanitize_text_field($input['countdown_label_m'] ?? 'min');
        $options['countdown_label_s'] = sanitize_text_field($input['countdown_label_s'] ?? 's');
        $options['countdown_show_seconds'] = !empty($input['countdown_show_seconds']);

        // Checkout module
        $options['checkout_enabled']   = !empty($input['checkout_enabled']);
        $options['checkout_count']     = max(1, min(12, (int) ($input['checkout_count'] ?? 6)));
        $options['checkout_preselect'] = !empty($input['checkout_preselect']);
        $options['checkout_label']     = sanitize_text_field($input['checkout_label'] ?? '') ?: 'Termin dostawy';
        $options['checkout_primary']   = in_array($input['checkout_primary'] ?? '', ['delivery', 'ship'], true) ? $input['checkout_primary'] : 'delivery';
        $options['checkout_position']  = in_array($input['checkout_position'] ?? '', ['shortcode', 'after_order_notes', 'before_order_review'], true) ? $input['checkout_position'] : 'shortcode';
        $options['checkout_accent']    = (string) sanitize_hex_color($input['checkout_accent'] ?? '');
        $options['disable_iconic']     = !empty($input['disable_iconic']);

        unset($options['excluded_holidays']);

        return $options;
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Manual sync action
        if (
            isset($_POST['wc_ship_today_manual_sync']) &&
            check_admin_referer('wc_ship_today_manual_sync', 'wc_ship_today_manual_sync_nonce')
        ) {
            $result = $this->fetch_and_store_holidays();
            $this->schedule = null;
            add_settings_error(
                self::OPTION_KEY,
                'manual_sync',
                $result ? __('Holidays updated.', 'wc-ship-today-notice') : __('Failed to update holidays.', 'wc-ship-today-notice'),
                $result ? 'updated' : 'error'
            );
        }

        settings_errors(self::OPTION_KEY);

        $tab      = $this->current_tab();
        $page_url = admin_url('admin.php?page=wc-ship-today-notice');
?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <nav class="nav-tab-wrapper" style="margin-bottom:16px;">
                <a href="<?php echo esc_url($page_url); ?>" class="nav-tab<?php echo 'settings' === $tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e('Settings', 'wc-ship-today-notice'); ?></a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'style', $page_url)); ?>" class="nav-tab<?php echo 'style' === $tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e('Style', 'wc-ship-today-notice'); ?></a>
            </nav>

            <?php if ('style' === $tab) : ?>
                <?php $this->render_style_tab(); ?>
            <?php else : ?>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_KEY);
                do_settings_sections('wc-ship-today-notice');
                submit_button();
                ?>
            </form>

            <hr style="margin: 30px 0;">

            <h2><?php esc_html_e('Fetch holidays', 'wc-ship-today-notice'); ?></h2>
            <p class="description">Pobiera święta na bieżący i następny rok. Święta są też pobierane automatycznie co 4 miesiące.</p>
            <form method="post" style="margin-top:12px;">
                <?php wp_nonce_field('wc_ship_today_manual_sync', 'wc_ship_today_manual_sync_nonce'); ?>
                <input type="hidden" name="wc_ship_today_manual_sync" value="1" />
                <?php submit_button(__('Fetch holidays now', 'wc-ship-today-notice'), 'secondary', 'submit', false); ?>
            </form>

            <?php endif; ?>
        </div>
    <?php
    }

    public function get_options()
    {
        $defaults = [
            'cutoff_time' => '14:00', // fallback global cutoff
            // 'today' = odliczanie do dzisiejszej godziny granicznej (jak w 1.x), 'next_slot' = zawsze najbliższy termin
            'mode' => 'today',
            // ISO-8601 numeric: 1=Mon ... 7=Sun
            'delivery_days' => [1, 2, 3, 4, 5],
            // Cutoff times per day (HH:MM format)
            'cutoff_times' => [
                1 => '14:00', // Monday
                2 => '14:00', // Tuesday
                3 => '14:00', // Wednesday
                4 => '14:00', // Thursday
                5 => '14:00', // Friday
                6 => '12:00', // Saturday
                7 => '12:00', // Sunday
            ],
            // Ile dni przed dniem wysyłki wypada godzina graniczna (0 = ten sam dzień)
            'cutoff_offsets' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0],
            // Dostawa: N dni doręczeń po wysyłce (0 = bez daty dostawy) i dni bez doręczeń
            'delivery_offset' => 0,
            'no_delivery_days' => [7],
            'excluded_dates' => '',
            'excluded_holiday_dates' => [], // święta ignorowane (wysyłka możliwa), daty Y-m-d
            'country_code' => 'PL',
            'language_code' => 'PL',
            'holiday_subdivision' => '', // region ISO 3166-2 (np. DE-BY), puste = tylko święta ogólnokrajowe
            'text_next_slot' => 'Zamów {cutoff_when}, wyślemy {ship_when}. Zostało: {countdown}',
            'text_before_cutoff' => __('Order within: {countdown} — we will ship today!', 'wc-ship-today-notice'),
            'text_after_cutoff' => __('We will ship tomorrow.', 'wc-ship-today-notice'),
            'text_not_shipping_day' => __('We will ship on the next business day.', 'wc-ship-today-notice'),
            // Activation checkboxes
            'show_after_cutoff' => true,
            'show_not_shipping_day' => true,
            // Visibility settings
            'visibility_mode' => 'always', // 'disabled', 'always', 'before_cutoff_only', 'hours_before'
            'visibility_hours_before' => 1, // hours before cutoff to show widget
            // Countdown format settings
            'countdown_label_h' => 'h',
            'countdown_label_m' => 'min',
            'countdown_label_s' => 's',
            'countdown_show_seconds' => true,
            // Wybór terminu w checkoucie (domyślnie wyłączony)
            'checkout_enabled' => false,
            'checkout_count' => 6,
            'checkout_preselect' => true,
            'checkout_label' => 'Termin dostawy',
            'checkout_primary' => 'delivery', // co jest dużym napisem na kafelku: 'delivery' albo 'ship'
            'checkout_position' => 'shortcode', // 'shortcode', 'after_order_notes', 'before_order_review'
            'checkout_accent' => '',
            'disable_iconic' => false,
        ];

        $saved = get_option(self::OPTION_KEY, []);
        return wp_parse_args($saved, $defaults);
    }

    /* ---------------------------------------------------------
     * Style tab
     * --------------------------------------------------------- */

    /**
     * Pola zakładki "Styl": klucz => [typ, zmienna CSS bez prefiksu, min, max].
     * Stan "nie dzień wysyłki" ma na froncie klasę --after, więc dziedziczy kolory "po".
     */
    private function style_fields()
    {
        return [
            'before_bg'       => ['color', 'before-bg'],
            'before_border'   => ['color', 'before-border'],
            'before_color'    => ['color', 'before-color'],
            'after_bg'        => ['color', 'after-bg'],
            'after_border'    => ['color', 'after-border'],
            'after_color'     => ['color', 'after-color'],
            'countdown_color' => ['color', 'countdown-color'],
            'border_width'    => ['px', 'border-width', 0, 20],
            'radius'          => ['px', 'radius', 0, 100],
            'padding'         => ['px', 'padding', 0, 80],
            'font_size'       => ['px', 'font-size', 8, 72],
            'font_weight'     => ['weight', 'font-weight'],
            'align'           => ['align', 'align'],
        ];
    }

    /** Pusta wartość = domyślny wygląd z notice.css. */
    public function get_style()
    {
        $saved = get_option(self::STYLE_KEY, []);
        return wp_parse_args(is_array($saved) ? $saved : [], array_fill_keys(array_keys($this->style_fields()), ''));
    }

    public function sanitize_style($input)
    {
        $input = is_array($input) ? $input : [];
        $style = [];

        foreach ($this->style_fields() as $key => $field) {
            $value = trim((string) ($input[$key] ?? ''));

            if ('' === $value) {
                $style[$key] = '';
            } elseif ('color' === $field[0]) {
                $style[$key] = (string) sanitize_hex_color($value);
            } elseif ('px' === $field[0]) {
                $style[$key] = max($field[2], min($field[3], absint($value)));
            } elseif ('weight' === $field[0]) {
                $style[$key] = in_array($value, ['400', '500', '600', '700', '800'], true) ? $value : '';
            } else {
                $style[$key] = in_array($value, ['left', 'center', 'right'], true) ? $value : '';
            }
        }

        return $style;
    }

    /**
     * Zmienne CSS dla notice.css (na :root, więc własny CSS na elemencie dalej je nadpisuje).
     * Czysty CSS w HTML strony, działa też ze stron w cache (cache czyszczony po zapisie).
     */
    private function style_css()
    {
        $fields  = $this->style_fields();
        $justify = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'];
        $css     = '';

        foreach ($this->sanitize_style($this->get_style()) as $key => $value) {
            if ('' === $value) {
                continue;
            }
            $css .= '--wc-ship-today-notice-' . $fields[$key][1] . ':' . $value . ('px' === $fields[$key][0] ? 'px' : '') . ';';
            if ('align' === $fields[$key][0]) {
                $css .= '--wc-ship-today-notice-justify:' . $justify[$value] . ';';
            }
        }

        return '' === $css ? '' : ':root{' . $css . '}';
    }

    private function current_tab()
    {
        return (isset($_GET['tab']) && 'style' === $_GET['tab']) ? 'style' : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification
    }

    public function admin_assets($hook)
    {
        if (!$this->page_hook || $hook !== $this->page_hook || 'style' !== $this->current_tab()) {
            return;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_style(
            'wc-ship-today-notice',
            plugins_url('assets/css/notice.css', __FILE__),
            [],
            WC_STN_VERSION
        );
        wp_enqueue_script(
            'wc-ship-today-notice-style',
            plugins_url('assets/js/style-preview.js', __FILE__),
            ['jquery', 'wp-color-picker'],
            WC_STN_VERSION,
            true
        );
    }

    private function style_color_input($key, array $s)
    {
        printf(
            '<input type="text" class="wc-stn-color" name="%1$s[%2$s]" value="%3$s" data-stn-var="--wc-ship-today-notice-%4$s" />',
            esc_attr(self::STYLE_KEY),
            esc_attr($key),
            esc_attr($s[$key]),
            esc_attr($this->style_fields()[$key][1])
        );
    }

    private function style_px_input($key, array $s, $placeholder)
    {
        $field = $this->style_fields()[$key];
        printf(
            '<input type="number" min="%1$d" max="%2$d" step="1" name="%3$s[%4$s]" value="%5$s" placeholder="%6$s" data-stn-var="--wc-ship-today-notice-%7$s" data-stn-unit="px" style="width:80px" /> px',
            (int) $field[2],
            (int) $field[3],
            esc_attr(self::STYLE_KEY),
            esc_attr($key),
            esc_attr($s[$key]),
            esc_attr($placeholder),
            esc_attr($field[1])
        );
    }

    private function render_style_tab()
    {
        $s    = $this->get_style();
        $o    = $this->get_options();
        $name = self::STYLE_KEY;

        // Przykładowe odliczanie w formacie z ustawień (jak formatCountdown w notice.js)
        $countdown = '01' . $o['countdown_label_h'] . ' 25' . $o['countdown_label_m'];
        if (!empty($o['countdown_show_seconds'])) {
            $countdown .= ' 08' . $o['countdown_label_s'];
        }
        $before_html = str_replace(
            '{countdown}',
            '<span class="wc-ship-today-countdown">' . esc_html($countdown) . '</span>',
            esc_html__('Order within: {countdown} — we will ship today!', 'wc-ship-today-notice')
        );

        $colors = [
            'bg'     => __('Background', 'wc-ship-today-notice'),
            'border' => __('Border color', 'wc-ship-today-notice'),
            'color'  => __('Text color', 'wc-ship-today-notice'),
        ];
        $col = 'display:inline-block;vertical-align:top;margin:0 16px 8px 0;';
        ?>
        <div style="display:flex;flex-wrap:wrap;gap:30px;align-items:flex-start;">
            <form method="post" action="options.php" id="wc-stn-style-form" style="flex:1 1 520px;min-width:0;">
                <?php settings_fields($name); ?>
                <p class="description"><?php esc_html_e('Empty field = default look. Changes are shown in the preview right away; click "Save Changes" to apply them on the site.', 'wc-ship-today-notice'); ?></p>

                <h2><?php esc_html_e('Colors', 'wc-ship-today-notice'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php foreach (['before' => __('Before cutoff', 'wc-ship-today-notice'), 'after' => __('After cutoff', 'wc-ship-today-notice')] as $state => $label) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($label); ?></th>
                            <td>
                                <?php foreach ($colors as $suffix => $color_label) : ?>
                                    <div style="<?php echo esc_attr($col); ?>">
                                        <span style="display:block;margin-bottom:4px;"><?php echo esc_html($color_label); ?></span>
                                        <?php $this->style_color_input($state . '_' . $suffix, $s); ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ('after' === $state) : ?>
                                    <p class="description"><?php esc_html_e('Also used on non-shipping days.', 'wc-ship-today-notice'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Countdown color', 'wc-ship-today-notice'); ?></th>
                        <td><?php $this->style_color_input('countdown_color', $s); ?></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Layout and text', 'wc-ship-today-notice'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Border width', 'wc-ship-today-notice'); ?></th>
                        <td><?php $this->style_px_input('border_width', $s, '2'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Border radius', 'wc-ship-today-notice'); ?></th>
                        <td><?php $this->style_px_input('radius', $s, '8'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Padding', 'wc-ship-today-notice'); ?></th>
                        <td>
                            <?php $this->style_px_input('padding', $s, '10 / 14'); ?>
                            <p class="description"><?php esc_html_e('Same on all sides. Default: 10px top and bottom, 14px left and right.', 'wc-ship-today-notice'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Font size', 'wc-ship-today-notice'); ?></th>
                        <td><?php $this->style_px_input('font_size', $s, '13'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Font weight', 'wc-ship-today-notice'); ?></th>
                        <td>
                            <select name="<?php echo esc_attr($name); ?>[font_weight]" data-stn-var="--wc-ship-today-notice-font-weight">
                                <option value=""><?php esc_html_e('Default (600)', 'wc-ship-today-notice'); ?></option>
                                <?php foreach (['400', '500', '600', '700', '800'] as $weight) : ?>
                                    <option value="<?php echo esc_attr($weight); ?>" <?php selected($s['font_weight'], $weight); ?>><?php echo esc_html($weight); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Alignment', 'wc-ship-today-notice'); ?></th>
                        <td>
                            <select name="<?php echo esc_attr($name); ?>[align]" data-stn-align="1">
                                <option value=""><?php esc_html_e('Default', 'wc-ship-today-notice'); ?></option>
                                <option value="left" <?php selected($s['align'], 'left'); ?>><?php esc_html_e('Left', 'wc-ship-today-notice'); ?></option>
                                <option value="center" <?php selected($s['align'], 'center'); ?>><?php esc_html_e('Center', 'wc-ship-today-notice'); ?></option>
                                <option value="right" <?php selected($s['align'], 'right'); ?>><?php esc_html_e('Right', 'wc-ship-today-notice'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <?php submit_button(null, 'primary', 'submit', false); ?>
                    <button type="button" class="button" id="wc-stn-style-reset" style="margin-left:8px;"><?php esc_html_e('Restore defaults', 'wc-ship-today-notice'); ?></button>
                </p>
            </form>

            <div style="flex:0 1 380px;min-width:260px;position:sticky;top:46px;">
                <h2><?php esc_html_e('Preview', 'wc-ship-today-notice'); ?></h2>
                <div id="wc-stn-style-preview" style="background:#fff;border:1px solid #c3c4c7;padding:16px;">
                    <p style="margin:0 0 6px;"><?php esc_html_e('Before cutoff', 'wc-ship-today-notice'); ?></p>
                    <div class="wc-ship-today-notice wc-ship-today-notice--before"><?php echo $before_html; // phpcs:ignore WordPress.Security.EscapeOutput -- esc_html__ + esc_html wyżej. ?></div>
                    <p style="margin:16px 0 6px;"><?php esc_html_e('After cutoff', 'wc-ship-today-notice'); ?></p>
                    <div class="wc-ship-today-notice wc-ship-today-notice--after"><?php esc_html_e('We will ship tomorrow.', 'wc-ship-today-notice'); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------
     * Settings fields
     * --------------------------------------------------------- */

    /**
     * Section callback: Shortcode info
     */
    public function section_shortcode_info()
    {
        ?>
        <div style="background:#f0f6fc;border:1px solid #c3d9ed;border-radius:4px;padding:12px 16px;margin-bottom:20px;">
            <strong><?php esc_html_e('Use this shortcode to display the widget:', 'wc-ship-today-notice'); ?></strong>
            <code style="display:inline-block;margin:8px 0;padding:6px 12px;background:#fff;border-radius:3px;font-size:14px;">[ship_today_notice]</code>
            <p class="description" style="margin:0 0 6px;">
                <?php esc_html_e('Place it on product pages, cart, sidebar, or anywhere you want to show the shipping countdown.', 'wc-ship-today-notice'); ?>
            </p>
            <p class="description" style="margin:0;">
                Atrybuty: <code>count="2"</code> (lista kolejnych terminów), <code>text="Zamów {cutoff_when}, dostarczymy {delivery_when}."</code> (własny tekst), <code>mode="next_slot"</code> lub <code>mode="today"</code>, <code>class="moja-klasa"</code> (własna klasa CSS).
            </p>
        </div>
        <?php
    }

    /**
     * Section callback: Holidays API info
     */
    public function section_holidays_info()
    {
        ?>
        <p class="description">
            <?php
            printf(
                /* translators: %s: link to OpenHolidays API */
                esc_html__('Public holidays are fetched from %s. Configure your country and language below.', 'wc-ship-today-notice'),
                '<a href="https://www.openholidaysapi.org/" target="_blank" rel="noopener noreferrer">OpenHolidays API</a>'
            );
            ?>
        </p>
        <?php
    }

    /**
     * Section callback: lista tokenów
     */
    public function section_texts_info()
    {
        ?>
        <p class="description">
            Tokeny: <code>{countdown}</code> odliczanie,
            <code>{cutoff_when}</code> "dziś do 20:00" / "jutro do 20:00" / "do niedzieli 20:00",
            <code>{cutoff_day}</code> "niedzieli", <code>{cutoff_time}</code> "20:00", <code>{cutoff_date}</code> "25.10",
            <code>{ship_when}</code> "dziś" / "jutro" / "w poniedziałek", <code>{ship_day}</code> "w poniedziałek", <code>{ship_date}</code>,
            <code>{delivery_when}</code> "jutro" / "we wtorek", <code>{delivery_day}</code> "we wtorek", <code>{delivery_date}</code> (wymaga ustawienia dostawy).
        </p>
        <?php
    }

    public function section_checkout_info()
    {
        ?>
        <p class="description">
            Kafelki z najbliższymi terminami w checkoucie. Data wysyłki i dostawy trafia do zamówienia, maili, strony podziękowania, panelu zamówienia (z możliwością zmiany) i kolumny "Wysyłka" na liście zamówień.
            Wstawienie przez shortcode <code>[ship_today_checkout]</code> (musi być wewnątrz formularza checkoutu) albo automatycznie.
        </p>
        <?php
    }

    public function field_checkout_enabled()
    {
        $o = $this->get_options();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[checkout_enabled]" value="1" <?php checked(!empty($o['checkout_enabled'])); ?> />
            Włącz wybór terminu w checkoucie (pole wymagane przy produktach z wysyłką)
        </label>
        <p style="margin-top:8px">
            <label>
                Miejsce:
                <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[checkout_position]">
                    <option value="shortcode" <?php selected($o['checkout_position'], 'shortcode'); ?>>tylko shortcode [ship_today_checkout]</option>
                    <option value="after_order_notes" <?php selected($o['checkout_position'], 'after_order_notes'); ?>>automatycznie pod uwagami do zamówienia</option>
                    <option value="before_order_review" <?php selected($o['checkout_position'], 'before_order_review'); ?>>automatycznie nad podsumowaniem zamówienia</option>
                </select>
            </label>
        </p>
        <?php if ('shortcode' === $o['checkout_position']) : ?>
            <p class="description" style="color:#b32d2e;">
                <?php esc_html_e('The [ship_today_checkout] shortcode must be placed inside the checkout form. If it is missing or outside the form, the date field is not shown and orders are placed without a ship date.', 'wc-ship-today-notice'); ?>
            </p>
        <?php endif; ?>
        <p>
            <label>
                Nazwa pola:
                <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[checkout_label]" value="<?php echo esc_attr($o['checkout_label']); ?>" class="regular-text" />
            </label>
        </p>
        <?php
    }

    public function field_checkout_tiles()
    {
        $o = $this->get_options();
        ?>
        <p>
            <label>
                Liczba terminów:
                <input type="number" min="1" max="12" name="<?php echo esc_attr(self::OPTION_KEY); ?>[checkout_count]" value="<?php echo esc_attr((int) $o['checkout_count']); ?>" style="width:60px" />
            </label>
            <label style="margin-left:16px">
                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[checkout_preselect]" value="1" <?php checked(!empty($o['checkout_preselect'])); ?> />
                zaznacz najbliższy termin
            </label>
        </p>
        <p>
            Duży napis na kafelku:
            <label><input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[checkout_primary]" value="delivery" <?php checked($o['checkout_primary'], 'delivery'); ?> /> data dostawy</label>
            <label style="margin-left:10px"><input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[checkout_primary]" value="ship" <?php checked($o['checkout_primary'], 'ship'); ?> /> data wysyłki</label>
        </p>
        <p>
            <label>
                Kolor zaznaczenia:
                <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[checkout_accent]" value="<?php echo esc_attr($o['checkout_accent']); ?>" placeholder="#8c3215" style="width:90px" />
            </label>
            <span class="description">(HEX, puste = ciemnoszary)</span>
        </p>
        <?php
    }

    public function field_disable_iconic()
    {
        $o = $this->get_options();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[disable_iconic]" value="1" <?php checked(!empty($o['disable_iconic'])); ?> />
            Wyłącz Iconic Delivery Slots w koszyku i checkoucie (działa tylko przy włączonym module)
        </label>
        <p class="description">
            Na okres przejściowy: Iconic zostaje aktywny w panelu (stare zamówienia, jego kolumna i strona Deliveries), ale nie pokazuje kalendarza, nie waliduje i nie zapisuje dat przy nowych zamówieniach.
            Nowe zamówienia nie pojawią się w Iconic. Wycofanie: odznacz i przywróć shortcode <code>[iconic-wds-fields]</code> w checkoucie.
        </p>
        <?php
    }

    public function field_mode()
    {
        $o = $this->get_options();
        ?>
        <fieldset>
            <label>
                <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[mode]" value="today" <?php checked($o['mode'], 'today'); ?> />
                Wysyłka dziś: odliczanie do dzisiejszej godziny granicznej, po niej i w dni bez wysyłki osobne teksty
            </label>
            <br>
            <label>
                <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[mode]" value="next_slot" <?php checked($o['mode'], 'next_slot'); ?> />
                Najbliższy termin: zawsze odliczanie do najbliższej godziny granicznej i jeden tekst z datami (np. wysyłka 2 razy w tygodniu)
            </label>
        </fieldset>
        <?php
    }

    public function field_delivery_days()
    {
        $o = $this->get_options();
        $days = [
            1 => __('Monday', 'wc-ship-today-notice'),
            2 => __('Tuesday', 'wc-ship-today-notice'),
            3 => __('Wednesday', 'wc-ship-today-notice'),
            4 => __('Thursday', 'wc-ship-today-notice'),
            5 => __('Friday', 'wc-ship-today-notice'),
            6 => __('Saturday', 'wc-ship-today-notice'),
            7 => __('Sunday', 'wc-ship-today-notice'),
        ];
        $offsets = [0 => 'tego samego dnia', 1 => 'dzień wcześniej', 2 => '2 dni wcześniej', 3 => '3 dni wcześniej', 4 => '4 dni wcześniej', 5 => '5 dni wcześniej', 6 => '6 dni wcześniej'];

        $cutoff_times = $o['cutoff_times'] ?? [];
        $cutoff_offsets = $o['cutoff_offsets'] ?? [];

        echo '<table class="widefat striped" style="max-width:640px">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Day', 'wc-ship-today-notice') . '</th>';
        echo '<th>' . esc_html__('Shipping', 'wc-ship-today-notice') . '</th>';
        echo '<th>' . esc_html__('Cutoff time', 'wc-ship-today-notice') . '</th>';
        echo '<th>Godzina graniczna wypada</th>';
        echo '</tr></thead><tbody>';

        foreach ($days as $num => $label) {
            $cutoff = $cutoff_times[$num] ?? '14:00';
            $offset = (int) ($cutoff_offsets[$num] ?? 0);

            $options_html = '';
            foreach ($offsets as $value => $text) {
                $options_html .= sprintf('<option value="%d" %s>%s</option>', $value, selected($offset, $value, false), esc_html($text));
            }

            printf(
                '<tr>
                    <td>%s</td>
                    <td><input type="checkbox" name="%s[delivery_days][]" value="%d" %s /></td>
                    <td><input type="time" name="%s[cutoff_times][%d]" value="%s" style="width:100px" /></td>
                    <td><select name="%s[cutoff_offsets][%d]">%s</select></td>
                </tr>',
                esc_html($label),
                esc_attr(self::OPTION_KEY),
                (int)$num,
                checked(in_array($num, (array)$o['delivery_days'], true), true, false),
                esc_attr(self::OPTION_KEY),
                (int)$num,
                esc_attr($cutoff),
                esc_attr(self::OPTION_KEY),
                (int)$num,
                wp_kses($options_html, ['option' => ['value' => true, 'selected' => true]])
            );
        }

        echo '</tbody></table>';
        echo '<p class="description">Godzina graniczna dotyczy dnia wysyłki. Przykład: wysyłka w poniedziałek, 20:00, "dzień wcześniej" = zamówienia do niedzieli 20:00 jadą w poniedziałek.</p>';
    }

    public function field_delivery()
    {
        $o = $this->get_options();
        $days = [1 => 'pn', 2 => 'wt', 3 => 'śr', 4 => 'czw', 5 => 'pt', 6 => 'sob', 7 => 'nd'];
        ?>
        <p>
            <label>
                Dostawa
                <input type="number" min="0" max="14" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[delivery_offset]" value="<?php echo esc_attr((int) $o['delivery_offset']); ?>" style="width:60px" />
                dzień/dni doręczeń po wysyłce
            </label>
            <span class="description">(0 = nie pokazuj daty dostawy)</span>
        </p>
        <p>
            Dni bez doręczeń:
            <?php foreach ($days as $num => $label) : ?>
                <label style="margin-right:10px">
                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[no_delivery_days][]" value="<?php echo (int) $num; ?>" <?php checked(in_array($num, (array) $o['no_delivery_days'], true)); ?> />
                    <?php echo esc_html($label); ?>
                </label>
            <?php endforeach; ?>
        </p>
        <p class="description">Święta państwowe są zawsze pomijane przy liczeniu dostawy.</p>
        <?php
    }

    public function field_excluded_dates()
    {
        $o = $this->get_options();
        printf(
            '<textarea name="%s[excluded_dates]" rows="4" class="large-text" style="max-width:400px" placeholder="24-12-2025&#10;31-12-2025&#10;02-01-2026">%s</textarea>',
            esc_attr(self::OPTION_KEY),
            esc_textarea($o['excluded_dates'])
        );
        echo '<p class="description">' . esc_html__('Enter dates in dd-mm-yyyy format, one per line or comma-separated. These dates are excluded even if they are weekdays.', 'wc-ship-today-notice') . '</p>';
    }

    /**
     * Podgląd 5 najbliższych terminów na zapisanych ustawieniach.
     */
    public function field_preview()
    {
        $schedule = $this->schedule();
        $slots = $schedule->slots(5);

        if (!$slots) {
            echo '<p class="description">Brak terminów w najbliższych ' . (int) WC_STN_Schedule::HORIZON_DAYS . ' dniach. Sprawdź dni wysyłki.</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>Zamówienia do</th><th>Wysyłka</th><th>Dostawa</th></tr></thead><tbody>';
        foreach ($slots as $slot) {
            printf(
                '<tr><td>%s, %s</td><td>%s, %s</td><td>%s</td></tr>',
                esc_html(WC_STN_Schedule::DAY_NOMINATIVE[(int) $slot['cutoff']->format('N')]),
                esc_html($slot['cutoff']->format('d.m.Y H:i')),
                esc_html(WC_STN_Schedule::DAY_NOMINATIVE[(int) $slot['ship']->format('N')]),
                esc_html($slot['ship']->format('d.m.Y')),
                $slot['delivery'] ? esc_html(WC_STN_Schedule::DAY_NOMINATIVE[(int) $slot['delivery']->format('N')] . ', ' . $slot['delivery']->format('d.m.Y')) : '&ndash;'
            );
        }
        echo '</tbody></table>';
        echo '<p class="description">Według zapisanych ustawień i pobranych świąt. Test innej chwili: dodaj do adresu strony <code>?stn_now=2026-12-20T19:59</code> (tylko dla managera sklepu).</p>';
    }

    public function field_country_code()
    {
        $o = $this->get_options();
        printf(
            '<input type="text" name="%s[country_code]" value="%s" maxlength="2" style="width:60px;text-transform:uppercase" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($o['country_code'])
        );
        echo '<p class="description">';
        esc_html_e('Two-letter ISO 3166-1 country code. Examples: PL (Poland), DE (Germany), CZ (Czech Republic), FR (France), GB (United Kingdom).', 'wc-ship-today-notice');
        echo ' <a href="https://openholidaysapi.org/Countries" target="_blank" rel="noopener noreferrer">' . esc_html__('See supported countries', 'wc-ship-today-notice') . '</a></p>';
    }

    public function field_language_code()
    {
        $o = $this->get_options();
        printf(
            '<input type="text" name="%s[language_code]" value="%s" maxlength="2" style="width:60px;text-transform:uppercase" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($o['language_code'])
        );
        echo '<p class="description">';
        esc_html_e('Two-letter ISO 639-1 language code for holiday names. Examples: PL (Polish), DE (German), EN (English), FR (French).', 'wc-ship-today-notice');
        echo '</p>';
    }

    public function field_holiday_subdivision()
    {
        $o = $this->get_options();
        printf(
            '<input type="text" name="%s[holiday_subdivision]" value="%s" maxlength="20" placeholder="%s" style="width:120px;text-transform:uppercase" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($o['holiday_subdivision']),
            esc_attr($o['country_code'] . '-…')
        );
        echo '<p class="description">';
        esc_html_e('Optional ISO 3166-2 region code, e.g. DE-BY. Empty: only nationwide holidays. With a region, its regional holidays also block shipping.', 'wc-ship-today-notice');
        printf(
            ' <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
            esc_url('https://openholidaysapi.org/Subdivisions?countryIsoCode=' . rawurlencode($o['country_code'])),
            esc_html__('Region codes for this country', 'wc-ship-today-notice')
        );
    }

    /**
     * Field: lista świąt (bieżący i następny rok) z możliwością ignorowania po dacie.
     */
    public function field_holidays_list()
    {
        global $wpdb;
        $o = $this->get_options();
        $table = $wpdb->prefix . self::TABLE_NAME;

        $year = (int) wp_date('Y');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- własna tabela świąt, brak API WP; tylko panel.
        $holidays = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT DISTINCT holiday_date, holiday_name FROM %i WHERE country_code = %s AND YEAR(holiday_date) IN (%d, %d) ORDER BY holiday_date ASC',
                $table,
                $o['country_code'],
                $year,
                $year + 1
            )
        );

        if (empty($holidays)) {
            echo '<p class="description">' . esc_html__('No holidays found. Click "Fetch holidays now" at the bottom of this page.', 'wc-ship-today-notice') . '</p>';
            return;
        }

        $years_found = array_unique(array_map(function ($h) {
            return (int) substr($h->holiday_date, 0, 4);
        }, $holidays));
        if (!in_array($year + 1, $years_found, true)) {
            echo '<p class="description" style="color:#b32d2e">Brak świąt na ' . (int) ($year + 1) . ' r. Kliknij "Pobierz święta" na dole strony.</p>';
        }

        $excluded = (array) ($o['excluded_holiday_dates'] ?? []);

        echo '<div style="max-height:300px;overflow-y:auto;border:1px solid #ddd;padding:10px;background:#fff;max-width:500px">';
        echo '<table class="widefat striped" style="margin:0">';
        echo '<thead><tr>';
        echo '<th style="width:30px">Ignoruj</th>';
        echo '<th>' . esc_html__('Date', 'wc-ship-today-notice') . '</th>';
        echo '<th>' . esc_html__('Holiday name', 'wc-ship-today-notice') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($holidays as $h) {
            $is_excluded = in_array($h->holiday_date, $excluded, true);
            $date_formatted = wp_date(get_option('date_format'), strtotime($h->holiday_date . ' 12:00:00'));
            printf(
                '<tr%s>
                    <td><input type="checkbox" name="%s[excluded_holiday_dates][]" value="%s" %s title="%s" /></td>
                    <td>%s</td>
                    <td>%s</td>
                </tr>',
                $is_excluded ? ' style="opacity:0.5"' : '',
                esc_attr(self::OPTION_KEY),
                esc_attr($h->holiday_date),
                $is_excluded ? 'checked' : '',
                esc_attr__('Check to IGNORE this holiday (shipping will be available)', 'wc-ship-today-notice'),
                esc_html($date_formatted),
                esc_html($h->holiday_name ?: '-')
            );
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '<p class="description">' . esc_html__('Check holidays to IGNORE them (shipping will be available on these days). Unchecked holidays block shipping.', 'wc-ship-today-notice') . '</p>';
    }

    /**
     * Field: Visibility mode
     */
    public function field_visibility_mode()
    {
        $o = $this->get_options();
        $mode = $o['visibility_mode'] ?? 'always';
        $hours = $o['visibility_hours_before'] ?? 1;

        ?>
        <fieldset>
            <label>
                <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[visibility_mode]" value="disabled" <?php checked($mode, 'disabled'); ?> />
                <?php esc_html_e('Disabled (widget completely hidden)', 'wc-ship-today-notice'); ?>
            </label>
            <br>
            <label>
                <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[visibility_mode]" value="always" <?php checked($mode, 'always'); ?> />
                <?php esc_html_e('Always visible', 'wc-ship-today-notice'); ?>
            </label>
            <br>
            <label>
                <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[visibility_mode]" value="before_cutoff_only" <?php checked($mode, 'before_cutoff_only'); ?> />
                <?php esc_html_e('Only before cutoff time (hide after cutoff)', 'wc-ship-today-notice'); ?>
            </label>
            <br>
            <label>
                <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[visibility_mode]" value="hours_before" <?php checked($mode, 'hours_before'); ?> />
                <?php esc_html_e('Only', 'wc-ship-today-notice'); ?>
                <input type="number" name="<?php echo esc_attr(self::OPTION_KEY); ?>[visibility_hours_before]" value="<?php echo esc_attr($hours); ?>" min="0.5" max="168" step="0.5" style="width:60px" />
                <?php esc_html_e('hour(s) before cutoff', 'wc-ship-today-notice'); ?>
            </label>
        </fieldset>
        <p class="description"><?php esc_html_e('Control when the widget is displayed to customers.', 'wc-ship-today-notice'); ?> W trybie "najbliższy termin" opcja "tylko przed godziną graniczną" działa jak "zawsze".</p>
        <?php
    }

    /**
     * Field: Countdown format settings
     */
    public function field_countdown_format()
    {
        $o = $this->get_options();
        $label_h = $o['countdown_label_h'] ?? 'h';
        $label_m = $o['countdown_label_m'] ?? 'min';
        $label_s = $o['countdown_label_s'] ?? 's';
        $show_seconds = !empty($o['countdown_show_seconds']);
        ?>
        <table class="form-table" style="margin:0;width:auto;">
            <tr>
                <td style="padding:5px 10px 5px 0;">
                    <label><?php esc_html_e('Hours label:', 'wc-ship-today-notice'); ?></label>
                </td>
                <td style="padding:5px 10px;">
                    <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[countdown_label_h]" value="<?php echo esc_attr($label_h); ?>" style="width:60px" placeholder="h" />
                </td>
            </tr>
            <tr>
                <td style="padding:5px 10px 5px 0;">
                    <label><?php esc_html_e('Minutes label:', 'wc-ship-today-notice'); ?></label>
                </td>
                <td style="padding:5px 10px;">
                    <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[countdown_label_m]" value="<?php echo esc_attr($label_m); ?>" style="width:60px" placeholder="min" />
                </td>
            </tr>
            <tr>
                <td style="padding:5px 10px 5px 0;">
                    <label><?php esc_html_e('Seconds label:', 'wc-ship-today-notice'); ?></label>
                </td>
                <td style="padding:5px 10px;">
                    <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[countdown_label_s]" value="<?php echo esc_attr($label_s); ?>" style="width:60px" placeholder="s" />
                </td>
            </tr>
            <tr>
                <td colspan="2" style="padding:5px 10px 5px 0;">
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[countdown_show_seconds]" value="1" <?php checked($show_seconds); ?> />
                        <?php esc_html_e('Show seconds in countdown', 'wc-ship-today-notice'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <p class="description">
            <?php esc_html_e('Examples: "1h 30min 10s" or "01:30" (without seconds). Leave label empty to hide it completely.', 'wc-ship-today-notice'); ?>
            Powyżej doby odliczanie pokazuje też dni ("1 dzień", "3 dni").
        </p>
        <?php
    }

    public function field_text_next_slot()
    {
        $o = $this->get_options();
        printf(
            '<textarea name="%s[text_next_slot]" rows="3" class="large-text">%s</textarea><p class="description">%s</p>',
            esc_attr(self::OPTION_KEY),
            esc_textarea($o['text_next_slot']),
            'Np. "Zamów {cutoff_when}, dostarczymy {delivery_when}. Zostało: {countdown}"'
        );
    }

    public function field_text_before_cutoff()
    {
        $o = $this->get_options();
        printf(
            '<textarea name="%s[text_before_cutoff]" rows="3" class="large-text">%s</textarea><p class="description">%s</p>',
            esc_attr(self::OPTION_KEY),
            esc_textarea($o['text_before_cutoff']),
            'Tryb "wysyłka dziś". ' . esc_html__('Available token: {countdown}', 'wc-ship-today-notice')
        );
    }

    public function field_text_after_cutoff()
    {
        $o = $this->get_options();
        $show = !empty($o['show_after_cutoff']);
        ?>
        <label style="display:block;margin-bottom:8px;">
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_after_cutoff]" value="1" <?php checked($show); ?> />
            <?php esc_html_e('Show this message after cutoff time', 'wc-ship-today-notice'); ?>
        </label>
        <textarea name="<?php echo esc_attr(self::OPTION_KEY); ?>[text_after_cutoff]" rows="3" class="large-text"><?php echo esc_textarea($o['text_after_cutoff']); ?></textarea>
        <p class="description">Tryb "wysyłka dziś". <?php esc_html_e('If disabled, the widget will be hidden after cutoff time.', 'wc-ship-today-notice'); ?></p>
        <?php
    }

    public function field_text_not_shipping_day()
    {
        $o = $this->get_options();
        $show = !empty($o['show_not_shipping_day']);
        ?>
        <label style="display:block;margin-bottom:8px;">
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_not_shipping_day]" value="1" <?php checked($show); ?> />
            <?php esc_html_e('Show this message on non-shipping days', 'wc-ship-today-notice'); ?>
        </label>
        <textarea name="<?php echo esc_attr(self::OPTION_KEY); ?>[text_not_shipping_day]" rows="3" class="large-text"><?php echo esc_textarea($o['text_not_shipping_day']); ?></textarea>
        <p class="description">Tryb "wysyłka dziś". <?php esc_html_e('If disabled, the widget will be hidden on weekends, holidays, and excluded dates.', 'wc-ship-today-notice'); ?></p>
        <?php
    }

    /* ---------------------------------------------------------
     * Holidays fetch + cron
     * --------------------------------------------------------- */
    public function cron_sync_holidays()
    {
        if ($this->fetch_and_store_holidays()) {
            return;
        }

        // Błąd sieci / API: ponowna próba za 6 h, chyba że coś jest już zaplanowane wcześniej
        $next = wp_next_scheduled(self::CRON_HOOK);
        if (!$next || $next > time() + 6 * HOUR_IN_SECONDS) {
            wp_schedule_single_event(time() + 6 * HOUR_IN_SECONDS, self::CRON_HOOK);
        }
    }

    /**
     * Pobiera święta na bieżący i następny rok (w grudniu potrzebne są już święta styczniowe).
     */
    private function fetch_and_store_holidays()
    {
        $year = (int) wp_date('Y');
        $ok = true;

        foreach ([$year, $year + 1] as $y) {
            $ok = $this->fetch_and_store_holidays_for_year($y) && $ok;
        }

        $this->schedule = null;

        return $ok;
    }

    private function fetch_and_store_holidays_for_year($year)
    {
        $o = $this->get_options();

        $country = $o['country_code'];
        $lang    = $o['language_code'];
        if (!preg_match('/^[A-Z]{2}$/', $country) || !preg_match('/^[A-Z]{2}$/', $lang)) {
            return false;
        }

        $validFrom = $year . '-01-01';
        $validTo   = $year . '-12-31';

        $url = add_query_arg(array_map('rawurlencode', [
            'countryIsoCode'  => $country,
            'languageIsoCode' => $lang,
            'validFrom'       => $validFrom,
            'validTo'         => $validTo,
        ]), 'https://openholidaysapi.org/PublicHolidays');

        $response = wp_remote_get($url, ['timeout' => 20]);
        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Pusta albo błędna odpowiedź nie kasuje zapisanych świąt (każdy kraj ma choć jedno święto w roku)
        if (!is_array($data) || !$data) {
            return false;
        }

        $subdivision = self::valid_subdivision($o['holiday_subdivision'], $country) ? $o['holiday_subdivision'] : '';
        $holidays    = self::holidays_from_api($data, $lang, $subdivision, $validFrom, $validTo);

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Remove this year for this country before insert (exclusions are stored by date, so they survive)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- własna tabela świąt, brak API WP; po zapisie cache nie ma czego unieważniać.
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE country_code = %s AND holiday_date BETWEEN %s AND %s',
                $table,
                $country,
                $validFrom,
                $validTo
            )
        );

        foreach ($holidays as $date => $name) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- własna tabela świąt.
            $wpdb->insert($table, [
                'country_code' => $country,
                'holiday_date' => $date,
                'holiday_name' => $name,
            ]);
        }

        return true;
    }

    /**
     * Kod regionu ISO 3166-2 danego kraju, np. DE-BY albo DE-BY-AU (kody OpenHolidays są hierarchiczne).
     */
    public static function valid_subdivision($code, $country)
    {
        return is_string($code) && (bool) preg_match('/^' . preg_quote((string) $country, '/') . '(-[A-Z0-9]{1,5})+$/', $code);
    }

    /**
     * Odpowiedź OpenHolidays /PublicHolidays → [Y-m-d => nazwa].
     * Święta regionalne (nationwide: false) tylko dla wybranego regionu albo jego regionu nadrzędnego
     * (region DE-BY-AU dostaje święta DE-BY, region DE-BY nie dostaje świąt DE-BY-AU).
     * Święta wielodniowe (startDate..endDate) rozwijane na kolejne dni, przycięte do zakresu roku.
     */
    public static function holidays_from_api(array $data, $lang, $subdivision, $from, $to)
    {
        $holidays = [];

        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (isset($row['nationwide']) && false === $row['nationwide']) {
                $match = false;
                foreach ((array) ($row['subdivisions'] ?? []) as $sub) {
                    $code = (string) ($sub['code'] ?? '');
                    if ('' !== $subdivision && '' !== $code && ($code === $subdivision || 0 === strpos($subdivision, $code . '-'))) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) {
                    continue;
                }
            }

            // The API returns date in ISO format under 'startDate' or 'date'
            $start = (string) ($row['startDate'] ?? $row['date'] ?? '');
            $end   = (string) ($row['endDate'] ?? $start);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) {
                $end = $start;
            }

            $name = '';
            if (!empty($row['name']) && is_array($row['name'])) {
                // Try to find localized name
                foreach ($row['name'] as $n) {
                    if (($n['language'] ?? '') === $lang) {
                        $name = $n['text'] ?? '';
                        break;
                    }
                }
            }

            // Kolejne dni jako tekst Y-m-d (bez stref czasowych); limit 31 dni na wypadek błędnych danych
            $day = new DateTimeImmutable($start . ' 12:00:00', new DateTimeZone('UTC'));
            for ($i = 0; $i < 31; $i++) {
                $ymd = $day->format('Y-m-d');
                if ($ymd > $end) {
                    break;
                }
                if ($ymd >= $from && $ymd <= $to && !isset($holidays[$ymd])) {
                    $holidays[$ymd] = (string) $name;
                }
                $day = $day->modify('+1 day');
            }
        }

        ksort($holidays);

        return $holidays;
    }

    /* ---------------------------------------------------------
     * Shortcode + frontend logic
     * --------------------------------------------------------- */
    public function register_shortcode()
    {
        add_shortcode('ship_today_notice', [$this, 'shortcode_output']);
    }

    /**
     * Skrypt i styl ładowane na każdej stronie (razem ok. 5 KB): shortcode bywa renderowany
     * z cache Elementora albo w popupach, wtedy enqueue z wnętrza shortcode by nie zadziałał.
     */
    public function register_assets()
    {
        if ('disabled' === $this->get_options()['visibility_mode']) {
            return;
        }

        wp_enqueue_style(
            'wc-ship-today-notice',
            plugins_url('assets/css/notice.css', __FILE__),
            [],
            WC_STN_VERSION
        );
        $style_css = $this->style_css();
        if ('' !== $style_css) {
            wp_add_inline_style('wc-ship-today-notice', $style_css);
        }
        wp_enqueue_script(
            'wc-ship-today-notice',
            plugins_url('assets/js/notice.js', __FILE__),
            [],
            WC_STN_VERSION,
            true
        );
    }

    public function shortcode_output($atts = [])
    {
        $o = $this->get_options();

        if ('disabled' === $o['visibility_mode']) {
            return '';
        }

        $atts = shortcode_atts([
            'count' => 1,
            'text'  => '',
            'mode'  => '',
            'class' => '',
        ], $atts, 'ship_today_notice');

        $mode  = in_array($atts['mode'], ['today', 'next_slot'], true) ? $atts['mode'] : $o['mode'];
        $count = max(1, min(10, (int) $atts['count']));
        $text  = wp_kses_post($atts['text']);

        $config = $this->frontend_config($o, $mode, $count, $text);
        $state  = $this->initial_state($config);

        $classes = array_merge(['wc-ship-today-notice'], array_filter(array_map('sanitize_html_class', preg_split('/\s+/', (string) $atts['class']))));
        if ($count > 1) {
            $classes[] = 'wc-ship-today-notice--list';
        }
        $classes[] = $state['hide'] ? 'wc-ship-today-notice--hidden' : 'wc-ship-today-notice--' . $state['class'];

        return sprintf(
            '<div class="%s" role="status" aria-live="polite" data-stn="%s"%s>%s</div>',
            esc_attr(implode(' ', $classes)),
            esc_attr(wp_json_encode($config)),
            $state['hide'] ? ' style="display:none;"' : '',
            $state['html'] // phpcs:ignore WordPress.Security.EscapeOutput -- teksty z wp_kses_post + etykiety dni.
        );
    }

    /**
     * Dane dla JS: harmonogram na FRONTEND_DAYS dni (absolutne znaczniki czasu, więc działa ze strony z cache).
     */
    private function frontend_config(array $o, $mode, $count, $text)
    {
        $schedule = $this->schedule();
        $now      = $schedule->now();
        $slots    = [];

        foreach ($schedule->slots(40, $now, self::FRONTEND_DAYS) as $slot) {
            $slots[] = $schedule->slot_labels($slot);
        }

        $templates = [
            'next'         => $this->t('text_next_slot', $o['text_next_slot']),
            'before'       => $this->t('text_before_cutoff', $o['text_before_cutoff']),
            'after'        => $this->t('text_after_cutoff', $o['text_after_cutoff']),
            'not_shipping' => $this->t('text_not_shipping_day', $o['text_not_shipping_day']),
        ];
        if ('' !== $text) {
            $templates = array_fill_keys(array_keys($templates), $text);
        }
        foreach ($templates as $state => $template) {
            $templates[$state] = (string) apply_filters('wc_ship_today_notice_text', $template, $state, $mode);
        }

        return [
            'mode'       => $mode,
            'count'      => $count,
            'templates'  => $templates,
            'show'       => [
                'after'        => !empty($o['show_after_cutoff']),
                'not_shipping' => !empty($o['show_not_shipping_day']),
            ],
            'visibility' => [
                'mode'  => $o['visibility_mode'],
                'hours' => (float) $o['visibility_hours_before'],
            ],
            'slots'      => $slots,
            'shipDates'  => $schedule->shipping_dates($now, self::FRONTEND_DAYS),
            'tz'         => $schedule->timezone()->getName(),
            'gmtOffset'  => $schedule->timezone()->getOffset($now),
            'nowOffset'  => $this->now_offset * 1000,
            'countdown'  => [
                'h'       => $o['countdown_label_h'],
                'm'       => $o['countdown_label_m'],
                's'       => $o['countdown_label_s'],
                'seconds' => !empty($o['countdown_show_seconds']),
                'day'     => 'dzień',
                'days'    => 'dni',
            ],
            'words'      => ['today' => 'dziś', 'tomorrow' => 'jutro', 'until' => 'do'],
        ];
    }

    /**
     * Ten sam stan co w notice.js, policzony na serwerze (pierwsze wyświetlenie i brak JS).
     */
    private function initial_state(array $config)
    {
        $schedule = $this->schedule();
        $now      = $schedule->now();
        $today    = $now->format('Y-m-d');
        $slots    = $config['slots'];

        if (!$slots) {
            return ['hide' => true, 'class' => 'hidden', 'html' => ''];
        }

        $first       = $slots[0];
        $hours_left  = ($first['cutoff'] - $now->getTimestamp()) / 3600;
        $vis         = $config['visibility'];
        $hide        = false;

        if ('next_slot' === $config['mode']) {
            $state = 'next';
            $hide  = 'hours_before' === $vis['mode'] && $hours_left > $vis['hours'];
        } elseif ($first['ship_ymd'] === $today) {
            $state = 'before';
            $hide  = 'hours_before' === $vis['mode'] && $hours_left > $vis['hours'];
        } elseif (in_array($today, $config['shipDates'], true)) {
            $state = 'after';
            $hide  = !$config['show']['after'] || 'before_cutoff_only' === $vis['mode'];
        } else {
            $state = 'not_shipping';
            $hide  = !$config['show']['not_shipping'];
        }

        $items = [];
        foreach (array_slice($slots, 0, $config['count']) as $i => $slot) {
            $template = $config['templates'][(0 === $i || 'next' === $state) ? $state : 'next'];
            $html     = $schedule->render_text($template, $slot, $now);
            $html     = str_replace('{countdown}', '<span class="wc-ship-today-countdown"></span>', $html);
            $items[]  = $config['count'] > 1 ? '<div class="wc-ship-today-notice__item">' . $html . '</div>' : $html;
        }

        return [
            'hide'  => $hide,
            'class' => in_array($state, ['before', 'next'], true) ? 'before' : 'after',
            'html'  => implode('', $items),
        ];
    }


    /* ---------------------------------------------------------
     * WPML helpers
     * --------------------------------------------------------- */
    public function wpml_register_strings($old, $new)
    {
        if (!function_exists('icl_register_string')) {
            return;
        }

        icl_register_string(self::WPML_DOMAIN, 'text_next_slot', $new['text_next_slot'] ?? '');
        icl_register_string(self::WPML_DOMAIN, 'text_before_cutoff', $new['text_before_cutoff'] ?? '');
        icl_register_string(self::WPML_DOMAIN, 'text_after_cutoff', $new['text_after_cutoff'] ?? '');
        icl_register_string(self::WPML_DOMAIN, 'text_not_shipping_day', $new['text_not_shipping_day'] ?? '');
    }

    private function t($key, $fallback)
    {
        if (function_exists('icl_t')) {
            return icl_t(self::WPML_DOMAIN, $key, $fallback);
        }
        if (has_filter('wpml_translate_single_string')) {
            return apply_filters('wpml_translate_single_string', $fallback, self::WPML_DOMAIN, $key);
        }
        return $fallback;
    }
}

WC_Ship_Today_Notice::instance();
