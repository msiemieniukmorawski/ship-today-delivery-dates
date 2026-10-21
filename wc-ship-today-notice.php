<?php

/**
 * Plugin Name: Powiadomienie o wysyłce dziś
 * Description: Odliczanie do godziny granicznej i informacja o najbliższej wysyłce i dostawie. Użycie przez shortcode [ship_today_notice].
 * Version: 2.1.0
 * Author: MS-M.pl
 * Text Domain: wc-ship-today-notice
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WC_STN_VERSION', '2.1.0');
define('WC_STN_FILE', __FILE__);

require_once __DIR__ . '/includes/class-wc-stn-schedule.php';
require_once __DIR__ . '/includes/class-wc-stn-checkout.php';
require_once __DIR__ . '/includes/functions.php';

final class WC_Ship_Today_Notice
{
    const OPTION_KEY       = 'wc_ship_today_notice_options';
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

        // Load translations early (before admin_menu and admin_init)
        add_action('plugins_loaded', [$this, 'load_textdomain']);

        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'maybe_upgrade'], 5);
        add_action('admin_init', [$this, 'register_settings']);

        add_action('init', [$this, 'register_shortcode']);
        add_action('init', [$this, 'maybe_enable_test_mode'], 1);
        add_action('init', [$this, 'maybe_schedule_cache_purge']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);

        add_action(self::CRON_HOOK, [$this, 'cron_sync_holidays']);
        add_action(self::CRON_PURGE_HOOK, [$this, 'cron_purge_cache']);
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        add_action('update_option_' . self::OPTION_KEY, [$this, 'on_options_updated'], 10, 2);

        new WC_STN_Checkout($this);
    }

    /**
     * Przesunięcie "teraz" w sekundach w trybie testowym (0 poza nim).
     */
    public function now_offset()
    {
        return $this->now_offset;
    }

    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain()
    {
        $domain = 'wc-ship-today-notice';
        $locale = determine_locale();

        // Try to load from WP languages directory first (wp-content/languages/plugins/)
        $mofile = WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '.mo';

        if (file_exists($mofile)) {
            load_textdomain($domain, $mofile);
        } else {
            // Fallback to plugin's languages directory
            load_plugin_textdomain(
                $domain,
                false,
                dirname(plugin_basename(__FILE__)) . '/languages/'
            );
        }
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
    }

    public function on_deactivate()
    {
        foreach ([self::CRON_HOOK, self::CRON_PURGE_HOOK] as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
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
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $dates = $wpdb->get_col("SELECT holiday_date FROM {$table} WHERE id IN (" . implode(',', $ids) . ')');
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
        $this->purge_cache();
        $this->reschedule_cache_purge();
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

    public function maybe_schedule_cache_purge()
    {
        if (!defined('LSCWP_V') && !has_action('wc_ship_today_notice_purge_cache')) {
            return;
        }
        if (!wp_next_scheduled(self::CRON_PURGE_HOOK)) {
            $this->reschedule_cache_purge();
        }
    }

    private function reschedule_cache_purge()
    {
        wp_clear_scheduled_hook(self::CRON_PURGE_HOOK);

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
        add_submenu_page(
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

        // Cutoff time - legacy fallback
        $options['cutoff_time'] = preg_match('/^\d{2}:\d{2}$/', $input['cutoff_time'] ?? '') ? $input['cutoff_time'] : '14:00';

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

        // Country & language codes
        $options['country_code'] = strtoupper(sanitize_text_field($input['country_code'] ?? 'PL'));
        $options['language_code'] = strtoupper(sanitize_text_field($input['language_code'] ?? 'PL'));

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
?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

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
                '<a href="https://openholidaysapi.org/" target="_blank">OpenHolidays API</a>'
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
            $checked = in_array($num, (array)$o['delivery_days'], true) ? 'checked' : '';
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
                $checked,
                esc_attr(self::OPTION_KEY),
                (int)$num,
                esc_attr($cutoff),
                esc_attr(self::OPTION_KEY),
                (int)$num,
                $options_html // phpcs:ignore WordPress.Security.EscapeOutput
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
        echo ' <a href="https://openholidaysapi.org/Countries" target="_blank">' . esc_html__('See supported countries', 'wc-ship-today-notice') . '</a></p>';
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

    /**
     * Field: lista świąt (bieżący i następny rok) z możliwością ignorowania po dacie.
     */
    public function field_holidays_list()
    {
        global $wpdb;
        $o = $this->get_options();
        $table = $wpdb->prefix . self::TABLE_NAME;

        $year = (int) wp_date('Y');
        $holidays = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT holiday_date, holiday_name FROM {$table} WHERE country_code = %s AND YEAR(holiday_date) IN (%d, %d) ORDER BY holiday_date ASC",
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
        $this->fetch_and_store_holidays();
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

        $validFrom = $year . '-01-01';
        $validTo   = $year . '-12-31';

        $url = add_query_arg([
            'countryIsoCode'  => $country,
            'languageIsoCode' => $lang,
            'validFrom'       => $validFrom,
            'validTo'         => $validTo,
        ], 'https://openholidaysapi.org/PublicHolidays');

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
        if (!is_array($data)) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Remove this year for this country before insert (exclusions are stored by date, so they survive)
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE country_code = %s AND holiday_date BETWEEN %s AND %s",
                $country,
                $validFrom,
                $validTo
            )
        );

        foreach ($data as $row) {
            // The API returns date in ISO format under 'startDate' or 'date'
            $date = $row['startDate'] ?? $row['date'] ?? null;
            if (!$date) {
                continue;
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

            $wpdb->insert($table, [
                'country_code' => $country,
                'holiday_date' => $date,
                'holiday_name' => $name,
            ]);
        }

        return true;
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
