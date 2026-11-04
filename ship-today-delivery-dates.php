<?php

/**
 * Plugin Name: Ship Today & Delivery Dates for WooCommerce
 * Description: Countdown to the order cutoff time with the next ship and delivery dates (shortcode [stdd_notice]), plus an optional ship date choice at checkout.
 * Version: 1.0.0
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: MS-M.pl
 * Author URI: https://ms-m.pl
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ship-today-delivery-dates
 * Domain Path: /languages
 * WC requires at least: 9.4
 * WC tested up to: 11.2
 */

if (!defined('ABSPATH')) {
    exit;
}

define('STDD_VERSION', '1.0.0');
define('STDD_FILE', __FILE__);

require_once __DIR__ . '/includes/class-stdd-schedule.php';
require_once __DIR__ . '/includes/class-stdd-checkout.php';
require_once __DIR__ . '/includes/class-stdd-checkout-blocks.php';
require_once __DIR__ . '/includes/class-stdd-auto-placement.php';
require_once __DIR__ . '/includes/functions.php';

final class STDD_Plugin
{
    const OPTION_KEY       = 'stdd_options';
    const STYLE_KEY        = 'stdd_style';
    /** Method schedules: ["flat_rate:3" => [enabled, delivery_days, cutoff_times, cutoff_offsets, delivery_offset]]. */
    const METHOD_KEY       = 'stdd_method_schedules';
    const VERSION_KEY      = 'stdd_version';
    const CRON_HOOK        = 'stdd_cron_sync_holidays';
    const CRON_PURGE_HOOK  = 'stdd_cron_purge_cache';
    const TABLE_NAME       = 'stdd_holidays';
    const WPML_DOMAIN      = 'ship-today-delivery-dates';

    /**
     * Optional feature toggles. Saved only from the form (_toggles marker), so writes from code keep the previous values.
     */
    /** Checkout texts from settings (sanitize_text_field, WPML). Empty = translated default. The second tile line is handled separately. */
    const CHECKOUT_TEXTS = [
        'checkout_tile_main',
        'checkout_row_ship_label',
        'checkout_row_delivery_label',
        'checkout_msg_choose',
        'checkout_msg_closed',
        'checkout_msg_unavailable',
        'checkout_msg_switched',
        'checkout_msg_method',
    ];

    /**
     * Option keys of the Checkout section (besides CHECKOUT_TEXTS). All other keys belong to the main section.
     * Both sections save the same option and each form has only its own fields, see limit_to_tab().
     */
    const CHECKOUT_TAB_KEYS = [
        'checkout_enabled',
        'checkout_count',
        'checkout_preselect',
        'checkout_label',
        'checkout_primary',
        'checkout_position',
        'checkout_accent',
        'checkout_tile_sub',
        'checkout_review_row',
        'checkout_order_rows',
        'checkout_admin_edit',
        'checkout_list_column',
        'checkout_dates_when_disabled',
    ];

    /** Tab under WooCommerce > Settings. */
    const SETTINGS_TAB = 'stdd_notice';

    /** Settings API page of the Checkout section. */
    const CHECKOUT_PAGE = 'stdd-notice-checkout';

    const TOGGLES = [
        'checkout_review_row',
        'checkout_order_rows',
        'checkout_admin_edit',
        'checkout_list_column',
        'checkout_dates_when_disabled',
        'holidays_auto_fetch',
        'cache_purge_at_cutoff',
        'test_mode',
        'wpml_strings',
    ];

    /** Days of slots sent to the page. Must cover how long a page can stay in cache. */
    const FRONTEND_DAYS = 42;

    /** @var self|null */
    private static $instance = null;

    /** @var STDD_Schedule|null */
    private $schedule = null;

    /** @var int Test mode offset of "now" in seconds (?stdd_now=). */
    private $now_offset = 0;

    /** @var STDD_Checkout_Blocks|null */
    private $checkout_blocks = null;

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

        // WordPress loads the translations itself, wp-content/languages/plugins/ takes precedence.

        add_action('admin_init', [$this, 'maybe_upgrade'], 5);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        // Late priority so the tab can go before WooCommerce's Advanced tab.
        add_filter('woocommerce_settings_tabs_array', [$this, 'add_settings_tab'], 60);
        add_action('woocommerce_sections_' . self::SETTINGS_TAB, [$this, 'output_sections']);
        add_action('woocommerce_settings_' . self::SETTINGS_TAB, [$this, 'render_settings_tab']);
        add_action('woocommerce_update_options_' . self::SETTINGS_TAB, [$this, 'save_settings']);
        add_action('woocommerce_settings_start', [$this, 'holidays_fetch_message']);
        add_action('admin_post_stdd_fetch_holidays', [$this, 'handle_fetch_holidays']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']);
        add_action('before_woocommerce_init', [$this, 'declare_wc_compatibility']);

        add_action('init', [$this, 'register_shortcode']);
        add_action('init', [$this, 'maybe_schedule_cache_purge']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);

        add_action(self::CRON_HOOK, [$this, 'cron_sync_holidays']);
        add_action(self::CRON_PURGE_HOOK, [$this, 'cron_purge_cache']);
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        add_action('update_option_' . self::OPTION_KEY, [$this, 'on_options_updated'], 10, 2);
        add_action('add_option_' . self::OPTION_KEY, [$this, 'on_options_added'], 10, 2);
        // The first save goes through add_option, later ones through update_option.
        add_action('add_option_' . self::STYLE_KEY, [$this, 'on_style_updated']);
        add_action('update_option_' . self::STYLE_KEY, [$this, 'on_style_updated']);
        add_action('add_option_' . self::METHOD_KEY, [$this, 'on_method_schedules_updated']);
        add_action('update_option_' . self::METHOD_KEY, [$this, 'on_method_schedules_updated']);

        // Not at file load: get_options() calls __() and translations must not load before init.
        add_action('init', [$this, 'init_checkout'], 0);
    }

    public function init_checkout()
    {
        new STDD_Auto_Placement($this);

        // Priority 1: test mode must be active before the block checkout computes slots on init 2.
        if (!empty($this->get_options()['test_mode'])) {
            add_action('init', [$this, 'maybe_enable_test_mode'], 1);
        }

        $this->checkout_blocks = new STDD_Checkout_Blocks($this, new STDD_Checkout($this));
    }

    /**
     * Orders are accessed through CRUD only, so the plugin works with HPOS.
     */
    public function declare_wc_compatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', STDD_FILE, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', STDD_FILE, true);
        }
    }

    /**
     * Test mode offset of "now" in seconds, 0 otherwise.
     */
    public function now_offset()
    {
        return $this->now_offset;
    }

    /**
     * Schedule for the current settings: global or for a shipping method ("flat_rate:3").
     * A method without its own schedule gets the global one, but its ID still reaches the filters.
     */
    public function schedule($method = null)
    {
        $method = self::valid_method_key($method) ? (string) $method : '';

        if (!is_array($this->schedule)) {
            $this->schedule = [];
        }
        if (!isset($this->schedule[$method])) {
            $this->schedule[$method] = new STDD_Schedule(
                array_merge($this->get_options(), $this->method_overrides($method)),
                null,
                '' === $method ? null : $method
            );
        }

        return $this->schedule[$method];
    }

    /** Shipping method instance ID: "method_id:instance_id", e.g. "flat_rate:3". */
    public static function valid_method_key($key)
    {
        return is_string($key) && (bool) preg_match('/^[a-z0-9_\-]+:\d+$/i', $key);
    }

    public function get_method_schedules()
    {
        $saved = get_option(self::METHOD_KEY, []);

        return is_array($saved) ? $saved : [];
    }

    /**
     * Option keys overridden by a method schedule. Empty = global schedule
     * (no entry, disabled entry, or the method no longer exists in WooCommerce).
     */
    private function method_overrides($method)
    {
        $entry = '' !== $method ? ($this->get_method_schedules()[$method] ?? null) : null;
        if (empty($entry['enabled']) || !in_array($method, $this->existing_method_keys(), true)) {
            return [];
        }

        $overrides = [
            'delivery_days'  => array_map('intval', (array) ($entry['delivery_days'] ?? [])),
            'cutoff_times'   => (array) ($entry['cutoff_times'] ?? []),
            'cutoff_offsets' => (array) ($entry['cutoff_offsets'] ?? []),
        ];
        if (isset($entry['delivery_offset']) && '' !== $entry['delivery_offset']) {
            $overrides['delivery_offset'] = (int) $entry['delivery_offset'];
        }

        return $overrides;
    }

    /** @var array<string,string>|null WooCommerce zone methods: key => "Zone - Method title". */
    private $shipping_methods = null;

    /**
     * All shipping method instances from WooCommerce zones, including "Rest of the world" and third-party methods.
     */
    public function shipping_methods()
    {
        if (null !== $this->shipping_methods) {
            return $this->shipping_methods;
        }

        $this->shipping_methods = [];
        if (!class_exists('WC_Shipping_Zones')) {
            return $this->shipping_methods;
        }

        $zones   = WC_Shipping_Zones::get_zones();
        $zones[] = ['id' => 0];
        foreach ($zones as $zone_data) {
            $zone = WC_Shipping_Zones::get_zone((int) $zone_data['id']);
            if (!$zone) {
                continue;
            }
            foreach ($zone->get_shipping_methods() as $method) {
                $key = $method->id . ':' . $method->get_instance_id();
                $this->shipping_methods[$key] = $zone->get_zone_name() . ' - ' . $method->get_title() . ('yes' === $method->enabled ? '' : ' (' . __('disabled', 'ship-today-delivery-dates') . ')');
            }
        }

        return $this->shipping_methods;
    }

    private function existing_method_keys()
    {
        return array_keys($this->shipping_methods());
    }

    /** Methods with an enabled schedule that still exist in WooCommerce. */
    public function methods_with_schedule()
    {
        return array_values(array_filter($this->existing_method_keys(), function ($key) {
            return [] !== $this->method_overrides($key);
        }));
    }

    /* ---------------------------------------------------------
     * Activation / Deactivation / Upgrade
     * --------------------------------------------------------- */
    public function on_activate()
    {
        $this->maybe_create_table();
        $this->schedule_holidays_sync();
    }

    /**
     * Schedules the holiday download: every ~4 months and on the next WP-Cron run.
     * With the toggle off, removes the scheduled events (the "Fetch holidays now" button stays).
     */
    private function schedule_holidays_sync()
    {
        if (empty($this->get_options()['holidays_auto_fetch'])) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'stdd_every_four_months', self::CRON_HOOK);
        }
        // On the next WP-Cron run, so activation doesn't wait for the API.
        wp_schedule_single_event(time(), self::CRON_HOOK);
    }

    public function on_deactivate()
    {
        foreach ([self::CRON_HOOK, self::CRON_PURGE_HOOK] as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    /** Creates the table again after an update that changes it. */
    public function maybe_upgrade()
    {
        if (version_compare(get_option(self::VERSION_KEY, '0'), STDD_VERSION, '>=')) {
            return;
        }

        $this->maybe_create_table();

        update_option(self::VERSION_KEY, STDD_VERSION);
    }

    public function add_cron_schedules($schedules)
    {
        $schedules['stdd_every_four_months'] = [
            'interval' => 4 * 30 * DAY_IN_SECONDS, // ~4 months; 3x per year
            // WooCommerce can schedule events before init, and translations must not load that early.
            'display'  => did_action('init') ? __('Every four months', 'ship-today-delivery-dates') : 'Every four months',
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
     * Test mode and cache
     * --------------------------------------------------------- */

    /**
     * ?stdd_now=2026-10-25T19:59 (shop managers only) overrides "now" in PHP and JS.
     */
    public function maybe_enable_test_mode()
    {
        if (empty($_GET['stdd_now']) || !current_user_can('manage_woocommerce')) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        try {
            $fake = new DateTimeImmutable(sanitize_text_field(wp_unslash($_GET['stdd_now'])), wp_timezone()); // phpcs:ignore WordPress.Security.NonceVerification
        } catch (Exception $e) {
            return;
        }

        $this->now_offset = $fake->getTimestamp() - time();
        $offset           = $this->now_offset;

        // From a timestamp: modify('+N seconds') uses wall clock time and is an hour off across a DST change.
        add_filter('stdd_now', function () use ($offset) {
            return (new DateTimeImmutable('@' . (time() + $offset)))->setTimezone(wp_timezone());
        });
    }

    public function on_options_updated($old, $new)
    {
        $this->schedule = null;
        // Merged with defaults: a write from code may not contain the toggle keys.
        $new_toggles = $this->get_options();

        if (!empty($new_toggles['wpml_strings'])) {
            $this->wpml_register_strings($old, $new);
        }

        if (!empty($old['holidays_auto_fetch'] ?? true) !== !empty($new_toggles['holidays_auto_fetch'])) {
            $this->schedule_holidays_sync();
        }

        // Another country, language or region means other holidays, so fetch them now.
        foreach (['country_code', 'language_code', 'holiday_subdivision'] as $key) {
            if (!empty($new_toggles['holidays_auto_fetch']) && ($old[$key] ?? null) !== ($new[$key] ?? null)) {
                if (!$this->fetch_and_store_holidays()) {
                    $this->settings_error(__('Failed to update holidays.', 'ship-today-delivery-dates'));
                }
                break;
            }
        }

        $this->purge_cache();
        $this->reschedule_cache_purge();
    }

    /** The first save is add_option, not update_option. */
    public function on_options_added($option, $value)
    {
        $this->on_options_updated([], $value);
    }

    public function on_method_schedules_updated()
    {
        $this->schedule = null;
        $this->purge_cache();
        $this->reschedule_cache_purge();
    }

    public function on_style_updated()
    {
        $this->purge_cache();
    }

    /**
     * Cached pages hold the initial text from render time. JS recalculates the slot, but purging at the cutoff
     * keeps the initial text right too.
     */
    private function purge_cache()
    {
        do_action('litespeed_purge_all'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache action.
        do_action('stdd_purge_cache');
    }

    public function cron_purge_cache()
    {
        $this->purge_cache();
        $this->reschedule_cache_purge();
    }

    /** Whether anything needs purging at the cutoff: LiteSpeed Cache or a hooked action (same as purge_cache()). */
    private function cache_purge_enabled()
    {
        return !empty($this->get_options()['cache_purge_at_cutoff']) && (defined('LSCWP_V') || has_action('stdd_purge_cache'));
    }

    public function maybe_schedule_cache_purge()
    {
        $scheduled = wp_next_scheduled(self::CRON_PURGE_HOOK);

        if (!$this->cache_purge_enabled()) {
            // E.g. after LiteSpeed was turned off: stop rescheduling the purge.
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

        // Nearest cutoff across the global and method schedules.
        $next = null;
        foreach (array_merge([null], $this->methods_with_schedule()) as $method) {
            $slots = $this->schedule($method)->slots(1);
            if ($slots && (null === $next || $slots[0]['cutoff'] < $next)) {
                $next = $slots[0]['cutoff'];
            }
        }
        if ($next) {
            wp_schedule_single_event($next->getTimestamp() + 5, self::CRON_PURGE_HOOK);
        }
    }

    /* ---------------------------------------------------------
     * Admin Menu / Settings
     * --------------------------------------------------------- */
    /** URL of the tab or a section ('methods', 'checkout', 'style'). */
    public static function settings_url($section = '')
    {
        return add_query_arg(
            array_filter(['page' => 'wc-settings', 'tab' => self::SETTINGS_TAB, 'section' => $section]),
            admin_url('admin.php')
        );
    }

    public function plugin_action_links($links)
    {
        array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url(self::settings_url()), esc_html__('Settings', 'ship-today-delivery-dates')));

        return $links;
    }

    /** Goes before Advanced, or last if there is no such tab. */
    public function add_settings_tab($tabs)
    {
        $label  = __('Delivery dates', 'ship-today-delivery-dates');
        $result = [];

        foreach ($tabs as $key => $name) {
            if ('advanced' === $key) {
                $result[self::SETTINGS_TAB] = $label;
            }
            $result[$key] = $name;
        }
        $result[self::SETTINGS_TAB] = $label;

        return $result;
    }

    public function output_sections()
    {
        $sections = [
            ''         => __('Settings', 'ship-today-delivery-dates'),
            'methods'  => __('Shipping methods', 'ship-today-delivery-dates'),
            'checkout' => __('Checkout', 'ship-today-delivery-dates'),
            'style'    => __('Style', 'ship-today-delivery-dates'),
        ];
        $current  = $this->current_section();

        echo '<ul class="subsubsub">';
        foreach (array_keys($sections) as $i => $section) {
            printf(
                '<li><a href="%s" class="%s">%s</a>%s</li>',
                esc_url(self::settings_url($section)),
                $section === $current ? 'current' : '',
                esc_html($sections[$section]),
                $i < count($sections) - 1 ? ' | ' : ''
            );
        }
        echo '</ul><br class="clear" />';
    }

    /** Current section: '' (settings), 'methods', 'checkout', 'style'. */
    private function current_section()
    {
        $section = isset($_REQUEST['section']) ? sanitize_title(wp_unslash($_REQUEST['section'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        return in_array($section, ['methods', 'checkout', 'style'], true) ? $section : '';
    }

    /**
     * Saves a section. WooCommerce already checked the nonce and manage_woocommerce. It saves on wp_loaded, before admin_init,
     * so sanitizing happens here and not through register_setting. Style and methods are separate options.
     */
    public function save_settings()
    {
        $section = $this->current_section();
        // phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- sanitized by sanitize_*().
        if ('style' === $section) {
            update_option(self::STYLE_KEY, $this->sanitize_style(wp_unslash($_POST[self::STYLE_KEY] ?? [])));
            return;
        }

        if ('methods' === $section) {
            update_option(self::METHOD_KEY, $this->sanitize_method_schedules(wp_unslash($_POST[self::METHOD_KEY] ?? null)));
            return;
        }

        $input         = (array) wp_unslash($_POST[self::OPTION_KEY] ?? []);
        $input['_tab'] = 'checkout' === $section ? 'checkout' : 'settings';
        update_option(self::OPTION_KEY, $this->sanitize_options($input));
        // phpcs:enable
    }

    /** Error on the WooCommerce settings page. The class is missing outside the admin, e.g. in cron. WooCommerce escapes the text. */
    private function settings_error($message)
    {
        if (class_exists('WC_Admin_Settings')) {
            WC_Admin_Settings::add_error($message);
        }
    }

    /** A link with a nonce: a nested form is not possible inside the WooCommerce form. */
    public function handle_fetch_holidays()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Sorry, you are not allowed to do this.', 'ship-today-delivery-dates'), 403);
        }
        check_admin_referer('stdd_fetch_holidays');

        $result         = $this->fetch_and_store_holidays();
        $this->schedule = null;

        wp_safe_redirect(add_query_arg('stdd_holidays', $result ? 'ok' : 'failed', self::settings_url()));
        exit;
    }

    public function holidays_fetch_message()
    {
        $result = isset($_GET['stdd_holidays']) ? sanitize_key(wp_unslash($_GET['stdd_holidays'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- notice only.

        if ('ok' === $result) {
            WC_Admin_Settings::add_message(__('Holidays updated.', 'ship-today-delivery-dates'));
        } elseif ('failed' === $result) {
            WC_Admin_Settings::add_error(__('Failed to update holidays.', 'ship-today-delivery-dates'));
        }
    }

    public function register_settings()
    {
        add_settings_section(
            'stdd_shortcode',
            __('Shortcode', 'ship-today-delivery-dates'),
            [$this, 'section_shortcode_info'],
            'ship-today-delivery-dates'
        );

        add_settings_section(
            'stdd_main',
            __('Main settings', 'ship-today-delivery-dates'),
            '__return_false',
            'ship-today-delivery-dates'
        );

        add_settings_field('mode', __('Message mode', 'ship-today-delivery-dates'), [$this, 'field_mode'], 'ship-today-delivery-dates', 'stdd_main');
        add_settings_field('delivery_days', __('Delivery weekdays & cutoff times', 'ship-today-delivery-dates'), [$this, 'field_delivery_days'], 'ship-today-delivery-dates', 'stdd_main');
        add_settings_field('delivery', __('Delivery', 'ship-today-delivery-dates'), [$this, 'field_delivery'], 'ship-today-delivery-dates', 'stdd_main');
        add_settings_field('excluded_dates', __('Excluded dates', 'ship-today-delivery-dates'), [$this, 'field_excluded_dates'], 'ship-today-delivery-dates', 'stdd_main');
        add_settings_field('preview', __('Upcoming dates', 'ship-today-delivery-dates'), [$this, 'field_preview'], 'ship-today-delivery-dates', 'stdd_main');

        add_settings_section(
            'stdd_auto',
            __('Automatic display', 'ship-today-delivery-dates'),
            '__return_false',
            'ship-today-delivery-dates'
        );
        add_settings_field('auto_placement', __('Show the widget without a shortcode', 'ship-today-delivery-dates'), [$this, 'field_auto_placement'], 'ship-today-delivery-dates', 'stdd_auto');

        // Module fields are hidden while the module is off (assets/js/checkout-settings.js).
        add_settings_section(
            'stdd_checkout',
            __('Ship date choice at checkout', 'ship-today-delivery-dates'),
            [$this, 'section_checkout_info'],
            self::CHECKOUT_PAGE
        );
        $module_row = ['class' => 'stdd-if-module' . (empty($this->get_options()['checkout_enabled']) ? ' hidden' : '')];
        add_settings_field('checkout_enabled', __('Module', 'ship-today-delivery-dates'), [$this, 'field_checkout_enabled'], self::CHECKOUT_PAGE, 'stdd_checkout');
        add_settings_field('checkout_placement', __('Position', 'ship-today-delivery-dates'), [$this, 'field_checkout_placement'], self::CHECKOUT_PAGE, 'stdd_checkout', $module_row);
        add_settings_field('checkout_tiles', __('Tiles', 'ship-today-delivery-dates'), [$this, 'field_checkout_tiles'], self::CHECKOUT_PAGE, 'stdd_checkout', $module_row);
        add_settings_field('checkout_texts', __('Texts', 'ship-today-delivery-dates'), [$this, 'field_checkout_texts'], self::CHECKOUT_PAGE, 'stdd_checkout', $module_row);
        // No class: this toggle matters exactly when the module is off.
        add_settings_field('checkout_display', __('Dates in orders', 'ship-today-delivery-dates'), [$this, 'field_checkout_display'], self::CHECKOUT_PAGE, 'stdd_checkout');

        add_settings_section(
            'stdd_visibility',
            __('Widget visibility', 'ship-today-delivery-dates'),
            '__return_false',
            'ship-today-delivery-dates'
        );

        add_settings_field('visibility_mode', __('Display widget', 'ship-today-delivery-dates'), [$this, 'field_visibility_mode'], 'ship-today-delivery-dates', 'stdd_visibility');

        add_settings_section(
            'stdd_countdown',
            __('Countdown format', 'ship-today-delivery-dates'),
            '__return_false',
            'ship-today-delivery-dates'
        );

        add_settings_field('countdown_format', __('Time labels', 'ship-today-delivery-dates'), [$this, 'field_countdown_format'], 'ship-today-delivery-dates', 'stdd_countdown');

        add_settings_section(
            'stdd_holidays',
            __('Public holidays API', 'ship-today-delivery-dates'),
            [$this, 'section_holidays_info'],
            'ship-today-delivery-dates'
        );

        add_settings_field('country_code', __('Country ISO code', 'ship-today-delivery-dates'), [$this, 'field_country_code'], 'ship-today-delivery-dates', 'stdd_holidays');
        add_settings_field('language_code', __('Language ISO code', 'ship-today-delivery-dates'), [$this, 'field_language_code'], 'ship-today-delivery-dates', 'stdd_holidays');
        add_settings_field('holiday_subdivision', __('Region', 'ship-today-delivery-dates'), [$this, 'field_holiday_subdivision'], 'ship-today-delivery-dates', 'stdd_holidays');
        add_settings_field('holidays_auto_fetch', __('Automatic download', 'ship-today-delivery-dates'), [$this, 'field_holidays_auto_fetch'], 'ship-today-delivery-dates', 'stdd_holidays');
        add_settings_field('holidays_list', __('Fetched holidays', 'ship-today-delivery-dates'), [$this, 'field_holidays_list'], 'ship-today-delivery-dates', 'stdd_holidays');

        add_settings_section(
            'stdd_texts',
            __('Frontend texts', 'ship-today-delivery-dates'),
            [$this, 'section_texts_info'],
            'ship-today-delivery-dates'
        );
        add_settings_field('text_next_slot', __('Text in "next date" mode', 'ship-today-delivery-dates'), [$this, 'field_text_next_slot'], 'ship-today-delivery-dates', 'stdd_texts');
        add_settings_field('text_before_cutoff', __('Text before cutoff', 'ship-today-delivery-dates'), [$this, 'field_text_before_cutoff'], 'ship-today-delivery-dates', 'stdd_texts');
        add_settings_field('text_after_cutoff', __('Text after cutoff', 'ship-today-delivery-dates'), [$this, 'field_text_after_cutoff'], 'ship-today-delivery-dates', 'stdd_texts');
        add_settings_field('text_not_shipping_day', __('Text when not a shipping day', 'ship-today-delivery-dates'), [$this, 'field_text_not_shipping_day'], 'ship-today-delivery-dates', 'stdd_texts');

        add_settings_section(
            'stdd_advanced',
            __('Advanced', 'ship-today-delivery-dates'),
            '__return_false',
            'ship-today-delivery-dates'
        );
        add_settings_field('advanced_toggles', __('Optional features', 'ship-today-delivery-dates'), [$this, 'field_advanced_toggles'], 'ship-today-delivery-dates', 'stdd_advanced');
    }

    public function sanitize_options($input)
    {
        $options = $this->get_options();

        // Legacy fallback without a form field: keep the previous value.
        if (preg_match('/^\d{2}:\d{2}$/', $input['cutoff_time'] ?? '')) {
            $options['cutoff_time'] = $input['cutoff_time'];
        }

        $options['mode'] = in_array($input['mode'] ?? '', ['today', 'next_slot'], true) ? $input['mode'] : 'today';

        $options['delivery_days'] = array_map('intval', $input['delivery_days'] ?? []);

        foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
            $time = $input['cutoff_times'][$day] ?? '';
            if (preg_match('/^\d{2}:\d{2}$/', $time)) {
                $options['cutoff_times'][$day] = $time;
            }
            $options['cutoff_offsets'][$day] = max(0, min(6, (int) ($input['cutoff_offsets'][$day] ?? 0)));
        }

        $options['delivery_offset']  = max(0, min(14, (int) ($input['delivery_offset'] ?? 0)));
        $options['no_delivery_days'] = array_map('intval', $input['no_delivery_days'] ?? []);

        $options['excluded_dates'] = sanitize_textarea_field($input['excluded_dates'] ?? '');

        $options['excluded_holiday_dates'] = array_values(array_filter(
            array_map('sanitize_text_field', (array) ($input['excluded_holiday_dates'] ?? [])),
            function ($date) {
                return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
            }
        ));

        // Two letters. An invalid value keeps the previous one and shows a notice.
        $codes = [
            'country_code'  => __('Country ISO code', 'ship-today-delivery-dates'),
            'language_code' => __('Language ISO code', 'ship-today-delivery-dates'),
        ];
        foreach ($codes as $key => $label) {
            if (!isset($input[$key])) {
                continue;
            }
            $value = strtoupper(trim(sanitize_text_field($input[$key])));
            if (preg_match('/^[A-Z]{2}$/', $value)) {
                $options[$key] = $value;
            } else {
                $this->settings_error(sprintf(
                    /* translators: 1: field label, 2: entered value, 3: previous value */
                    __('%1$s: "%2$s" is not a two-letter code. The previous value %3$s was kept.', 'ship-today-delivery-dates'),
                    $label,
                    $value,
                    $options[$key]
                ));
            }
        }

        // ISO 3166-2, e.g. DE-BY. Empty = nationwide holidays only.
        if (isset($input['holiday_subdivision'])) {
            $value = strtoupper(trim(sanitize_text_field($input['holiday_subdivision'])));
            if ('' === $value || self::valid_subdivision($value, $options['country_code'])) {
                $options['holiday_subdivision'] = $value;
            } else {
                $options['holiday_subdivision'] = '';
                $this->settings_error(sprintf(
                    /* translators: 1: entered value, 2: country code */
                    __('Region "%1$s" is not a region code of %2$s (e.g. DE-BY). Only nationwide holidays are used.', 'ship-today-delivery-dates'),
                    $value,
                    $options['country_code']
                ));
            }
        }

        $options['text_next_slot'] = wp_kses_post($input['text_next_slot'] ?? '');
        $options['text_before_cutoff'] = wp_kses_post($input['text_before_cutoff'] ?? '');
        $options['text_after_cutoff'] = wp_kses_post($input['text_after_cutoff'] ?? '');
        $options['text_not_shipping_day'] = wp_kses_post($input['text_not_shipping_day'] ?? '');

        $options['show_after_cutoff'] = !empty($input['show_after_cutoff']);
        $options['show_not_shipping_day'] = !empty($input['show_not_shipping_day']);

        $allowed_modes = ['disabled', 'always', 'before_cutoff_only', 'hours_before'];
        $options['visibility_mode'] = in_array($input['visibility_mode'] ?? '', $allowed_modes, true)
            ? $input['visibility_mode']
            : 'always';
        $options['visibility_hours_before'] = max(0, (float) ($input['visibility_hours_before'] ?? 1));

        $options['countdown_label_h'] = sanitize_text_field($input['countdown_label_h'] ?? 'h');
        $options['countdown_label_m'] = sanitize_text_field($input['countdown_label_m'] ?? 'min');
        $options['countdown_label_s'] = sanitize_text_field($input['countdown_label_s'] ?? 's');
        $options['countdown_show_seconds'] = !empty($input['countdown_show_seconds']);

        $options['checkout_enabled']   = !empty($input['checkout_enabled']);
        $options['checkout_count']     = max(1, min(12, (int) ($input['checkout_count'] ?? 6)));
        $options['checkout_preselect'] = !empty($input['checkout_preselect']);
        $options['checkout_label']     = sanitize_text_field($input['checkout_label'] ?? '') ?: __('Delivery date', 'ship-today-delivery-dates');
        $options['checkout_primary']   = in_array($input['checkout_primary'] ?? '', ['delivery', 'ship'], true) ? $input['checkout_primary'] : 'delivery';
        $options['checkout_position']  = in_array($input['checkout_position'] ?? '', ['shortcode', 'after_order_notes', 'before_order_review'], true) ? $input['checkout_position'] : 'shortcode';
        $options['checkout_accent']    = (string) sanitize_hex_color($input['checkout_accent'] ?? '');

        // Empty = translated default. Missing from the input (write from code) = unchanged.
        foreach (self::CHECKOUT_TEXTS as $key) {
            if (isset($input[$key])) {
                $options[$key] = trim(sanitize_text_field((string) $input[$key]));
            }
        }
        // Second tile line: null = default, '' = hidden. The form shows the default as the field value, so sending it back
        // (for the previous or new "large text" and delivery state) stores null and keeps following the translation.
        if (isset($input['checkout_tile_sub'])) {
            $prev = $this->get_options();
            $sub  = trim(sanitize_text_field((string) $input['checkout_tile_sub']));
            $defaults = [
                STDD_Checkout::default_tile_texts($prev['checkout_primary'], (int) $prev['delivery_offset'] > 0)['sub'],
                STDD_Checkout::default_tile_texts($options['checkout_primary'], (int) $options['delivery_offset'] > 0)['sub'],
            ];
            $options['checkout_tile_sub'] = in_array($sub, $defaults, true) ? null : $sub;
        }

        // Form only: a write from code without this key keeps the previous values.
        if (isset($input['auto_placement'])) {
            $options['auto_placement'] = STDD_Auto_Placement::sanitize((array) $input['auto_placement']);
        }

        // An unchecked box is missing from POST, so without the form marker nothing changes.
        if (!empty($input['_toggles'])) {
            foreach (self::TOGGLES as $key) {
                $options[$key] = !empty($input[$key]);
            }
        }

        unset($options['excluded_holidays']);

        return $this->limit_to_tab($options, $input);
    }

    /**
     * Both sections save one option and a checkbox missing from POST reads as false, so only the keys of the submitted form
     * change (_tab marker). Without the marker (write from code, second sanitize pass on add_option) only keys in the input change.
     */
    private function limit_to_tab(array $options, array $input)
    {
        $saved    = $this->get_options();
        $tab      = $input['_tab'] ?? '';
        $checkout = array_merge(self::CHECKOUT_TAB_KEYS, self::CHECKOUT_TEXTS);

        foreach (array_keys($options) as $key) {
            $in_checkout = in_array($key, $checkout, true);

            if ('checkout' === $tab || 'settings' === $tab) {
                $keep = ('checkout' === $tab) !== $in_checkout;
            } else {
                // A toggle missing from a form that has the _toggles marker is unchecked.
                $keep = !array_key_exists($key, $input) && !(!empty($input['_toggles']) && in_array($key, self::TOGGLES, true));
            }

            if ($keep && array_key_exists($key, $saved)) {
                $options[$key] = $saved[$key];
            }
        }

        return $options;
    }

    /** Rendered inside WooCommerce's #mainform: no own <form> or submit buttons. */
    public function render_settings_tab()
    {
        echo '<div class="stdd-settings">';
        $this->render_section($this->current_section());
        echo '</div>';
    }

    private function render_section($section)
    {
        if ('style' === $section) {
            $this->render_style_tab();
            return;
        }

        if ('methods' === $section) {
            echo '<h2>' . esc_html__('Shipping methods', 'ship-today-delivery-dates') . '</h2>';
            $this->field_method_schedules();
            return;
        }

        if ('checkout' === $section) {
            echo '<div id="stdd-checkout-settings">';
            do_settings_sections(self::CHECKOUT_PAGE);
            echo '</div>';
            return;
        }

        do_settings_sections('ship-today-delivery-dates');
        ?>
        <hr style="margin: 30px 0;">

        <h2><?php esc_html_e('Fetch holidays', 'ship-today-delivery-dates'); ?></h2>
        <p class="description"><?php esc_html_e('Fetches holidays for the current and next year. Holidays are also fetched automatically every 4 months.', 'ship-today-delivery-dates'); ?></p>
        <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=stdd_fetch_holidays'), 'stdd_fetch_holidays')); ?>"><?php esc_html_e('Fetch holidays now', 'ship-today-delivery-dates'); ?></a></p>
        <?php
    }

    public function get_options()
    {
        $defaults = [
            'cutoff_time' => '14:00', // fallback global cutoff
            // 'today' = countdown to today's cutoff, 'next_slot' = always the nearest slot
            'mode' => 'today',
            // ISO-8601 numeric: 1=Mon ... 7=Sun
            'delivery_days' => [1, 2, 3, 4, 5],
            'cutoff_times' => [
                1 => '14:00',
                2 => '14:00',
                3 => '14:00',
                4 => '14:00',
                5 => '14:00',
                6 => '12:00',
                7 => '12:00',
            ],
            // Days before the ship day the cutoff falls on (0 = same day)
            'cutoff_offsets' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0],
            // N delivery days after shipping (0 = no delivery date) and days without delivery
            'delivery_offset' => 0,
            'no_delivery_days' => [7],
            'excluded_dates' => '',
            'excluded_holiday_dates' => [], // ignored holidays (shipping allowed), Y-m-d
            'country_code' => 'PL',
            'language_code' => 'PL',
            'holiday_subdivision' => '', // ISO 3166-2 (e.g. DE-BY), empty = nationwide only
            'text_next_slot' => __('Order {cutoff_when}, we will ship {ship_when}. Time left: {countdown}', 'ship-today-delivery-dates'),
            'text_before_cutoff' => __('Order within {countdown} and we will ship today!', 'ship-today-delivery-dates'),
            // {ship_when}: "tomorrow" or a weekday, e.g. Monday on Friday after the cutoff
            'text_after_cutoff' => __('We will ship {ship_when}.', 'ship-today-delivery-dates'),
            'text_not_shipping_day' => __('We will ship {ship_when}.', 'ship-today-delivery-dates'),
            'show_after_cutoff' => true,
            'show_not_shipping_day' => true,
            'visibility_mode' => 'always', // 'disabled', 'always', 'before_cutoff_only', 'hours_before'
            'visibility_hours_before' => 1, // hours before cutoff to show widget
            'countdown_label_h' => 'h',
            'countdown_label_m' => 'min',
            'countdown_label_s' => 's',
            'countdown_show_seconds' => true,
            'checkout_enabled' => false,
            'checkout_count' => 6,
            'checkout_preselect' => true,
            'checkout_label' => __('Delivery date', 'ship-today-delivery-dates'),
            'checkout_primary' => 'delivery', // large text on the tile: 'delivery' or 'ship'
            'checkout_position' => 'shortcode', // 'shortcode', 'after_order_notes', 'before_order_review'
            'checkout_accent' => '',
            // CHECKOUT_TEXTS: empty = translated default; second tile line: null = default, '' = hidden
            'checkout_tile_main' => '',
            'checkout_tile_sub' => null,
            'checkout_row_ship_label' => '',
            'checkout_row_delivery_label' => '',
            'checkout_msg_choose' => '',
            'checkout_msg_closed' => '',
            'checkout_msg_unavailable' => '',
            'checkout_msg_switched' => '',
            'checkout_msg_method' => '',
            'checkout_review_row' => true,
            'checkout_order_rows' => true,
            'checkout_admin_edit' => true,
            'checkout_list_column' => true,
            'checkout_dates_when_disabled' => false, // dates from orders also shown with the module off
            'holidays_auto_fetch' => true,
            'cache_purge_at_cutoff' => true,
            'test_mode' => true,
            'wpml_strings' => true,
            'auto_placement' => STDD_Auto_Placement::defaults(), // new shops get the widget on the product page
        ];

        return wp_parse_args(get_option(self::OPTION_KEY, []), $defaults);
    }

    /* ---------------------------------------------------------
     * Style tab
     * --------------------------------------------------------- */

    /**
     * Style tab fields: key => [type, CSS variable without prefix, min, max].
     * The "not a shipping day" state uses the --after class, so it inherits the "after" colors.
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

    /** Empty value = default look from notice.css. */
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
     * CSS variables for notice.css. On :root, so custom CSS on the element still wins.
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
            $css .= '--stdd-notice-' . $fields[$key][1] . ':' . $value . ('px' === $fields[$key][0] ? 'px' : '') . ';';
            if ('align' === $fields[$key][0]) {
                $css .= '--stdd-notice-justify:' . $justify[$value] . ';';
            }
        }

        return '' === $css ? '' : ':root{' . $css . '}';
    }

    public function admin_assets($hook)
    {
        if ('woocommerce_page_wc-settings' !== $hook || !isset($_GET['tab']) || self::SETTINGS_TAB !== sanitize_title(wp_unslash($_GET['tab']))) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        wp_enqueue_style(
            'stdd-notice-settings',
            plugins_url('assets/css/settings.css', __FILE__),
            [],
            STDD_VERSION
        );

        if ('checkout' === $this->current_section()) {
            wp_enqueue_script(
                'stdd-notice-checkout-settings',
                plugins_url('assets/js/checkout-settings.js', __FILE__),
                [],
                STDD_VERSION,
                true
            );
            return;
        }

        if ('style' !== $this->current_section()) {
            return;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_style(
            'ship-today-delivery-dates',
            plugins_url('assets/css/notice.css', __FILE__),
            [],
            STDD_VERSION
        );
        wp_enqueue_script(
            'stdd-notice-style',
            plugins_url('assets/js/style-preview.js', __FILE__),
            ['jquery', 'wp-color-picker'],
            STDD_VERSION,
            true
        );
    }

    private function style_color_input($key, array $s)
    {
        printf(
            '<input type="text" class="stdd-color" name="%1$s[%2$s]" value="%3$s" data-stdd-var="--stdd-notice-%4$s" />',
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
            '<input type="number" min="%1$d" max="%2$d" step="1" name="%3$s[%4$s]" value="%5$s" placeholder="%6$s" data-stdd-var="--stdd-notice-%7$s" data-stdd-unit="px" style="width:80px" /> px',
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

        // Sample countdown in the configured format (see formatCountdown() in notice.js)
        $countdown = '01' . $o['countdown_label_h'] . ' 25' . $o['countdown_label_m'];
        if (!empty($o['countdown_show_seconds'])) {
            $countdown .= ' 08' . $o['countdown_label_s'];
        }
        $before_html = str_replace(
            '{countdown}',
            '<span class="stdd-countdown">' . esc_html($countdown) . '</span>',
            esc_html__('Order within {countdown} and we will ship today!', 'ship-today-delivery-dates')
        );

        $colors = [
            'bg'     => __('Background', 'ship-today-delivery-dates'),
            'border' => __('Border color', 'ship-today-delivery-dates'),
            'color'  => __('Text color', 'ship-today-delivery-dates'),
        ];
        $col = 'display:inline-block;vertical-align:top;margin:0 16px 8px 0;';
        ?>
        <div style="display:flex;flex-wrap:wrap;gap:30px;align-items:flex-start;">
            <div id="stdd-style-form" style="flex:1 1 520px;min-width:0;">
                <p class="description"><?php esc_html_e('Empty field = default look. Changes are shown in the preview right away; click "Save Changes" to apply them on the site.', 'ship-today-delivery-dates'); ?></p>

                <h2><?php esc_html_e('Colors', 'ship-today-delivery-dates'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php foreach (['before' => __('Before cutoff', 'ship-today-delivery-dates'), 'after' => __('After cutoff', 'ship-today-delivery-dates')] as $state => $label) : ?>
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
                                    <p class="description"><?php esc_html_e('Also used on non-shipping days.', 'ship-today-delivery-dates'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Countdown color', 'ship-today-delivery-dates'); ?></th>
                        <td><?php $this->style_color_input('countdown_color', $s); ?></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Layout and text', 'ship-today-delivery-dates'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Border width', 'ship-today-delivery-dates'); ?></th>
                        <td><?php $this->style_px_input('border_width', $s, '2'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Border radius', 'ship-today-delivery-dates'); ?></th>
                        <td><?php $this->style_px_input('radius', $s, '8'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Padding', 'ship-today-delivery-dates'); ?></th>
                        <td>
                            <?php $this->style_px_input('padding', $s, '10'); ?>
                            <p class="description"><?php esc_html_e('Same on all sides. Default: 10px top and bottom, 14px left and right.', 'ship-today-delivery-dates'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Font size', 'ship-today-delivery-dates'); ?></th>
                        <td><?php $this->style_px_input('font_size', $s, '13'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Font weight', 'ship-today-delivery-dates'); ?></th>
                        <td>
                            <select name="<?php echo esc_attr($name); ?>[font_weight]" data-stdd-var="--stdd-notice-font-weight">
                                <option value=""><?php esc_html_e('Default (600)', 'ship-today-delivery-dates'); ?></option>
                                <?php foreach (['400', '500', '600', '700', '800'] as $weight) : ?>
                                    <option value="<?php echo esc_attr($weight); ?>" <?php selected($s['font_weight'], $weight); ?>><?php echo esc_html($weight); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Alignment', 'ship-today-delivery-dates'); ?></th>
                        <td>
                            <select name="<?php echo esc_attr($name); ?>[align]" data-stdd-align="1">
                                <option value=""><?php esc_html_e('Default', 'ship-today-delivery-dates'); ?></option>
                                <option value="left" <?php selected($s['align'], 'left'); ?>><?php esc_html_e('Left', 'ship-today-delivery-dates'); ?></option>
                                <option value="center" <?php selected($s['align'], 'center'); ?>><?php esc_html_e('Center', 'ship-today-delivery-dates'); ?></option>
                                <option value="right" <?php selected($s['align'], 'right'); ?>><?php esc_html_e('Right', 'ship-today-delivery-dates'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="button" class="button" id="stdd-style-reset"><?php esc_html_e('Restore defaults', 'ship-today-delivery-dates'); ?></button>
                </p>
            </div>

            <div style="flex:0 1 380px;min-width:260px;position:sticky;top:46px;">
                <h2><?php esc_html_e('Preview', 'ship-today-delivery-dates'); ?></h2>
                <div id="stdd-style-preview" style="background:#fff;border:1px solid #c3c4c7;padding:16px;">
                    <p style="margin:0 0 6px;"><?php esc_html_e('Before cutoff', 'ship-today-delivery-dates'); ?></p>
                    <div class="stdd-notice stdd-notice--before"><div class="stdd-notice__item"><?php echo $before_html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?></div></div>
                    <p style="margin:16px 0 6px;"><?php esc_html_e('After cutoff', 'ship-today-delivery-dates'); ?></p>
                    <div class="stdd-notice stdd-notice--after"><div class="stdd-notice__item"><?php echo esc_html(str_replace('{ship_when}', STDD_Schedule::words()['tomorrow'], __('We will ship {ship_when}.', 'ship-today-delivery-dates'))); ?></div></div>
                </div>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------
     * Settings fields
     * --------------------------------------------------------- */

    /**
     * Shows the options that work for this shop's page type (hook or block position). The other value stays in a hidden field,
     * so it survives a change of page type.
     */
    public function field_auto_placement()
    {
        $name  = self::OPTION_KEY . '[auto_placement]';
        $rows  = STDD_Auto_Placement::settings($this->get_options());
        $texts = [
            'product'  => __('Product page', 'ship-today-delivery-dates'),
            'cart'     => __('Cart', 'ship-today-delivery-dates'),
            'checkout' => __('Checkout', 'ship-today-delivery-dates'),
        ];
        $hooks = [
            'woocommerce_before_add_to_cart_form'      => __('Above the add to cart form', 'ship-today-delivery-dates'),
            'woocommerce_after_add_to_cart_form'       => __('Below the add to cart form', 'ship-today-delivery-dates'),
            'woocommerce_single_product_summary'       => __('In the product summary (position set by the priority)', 'ship-today-delivery-dates'),
            'woocommerce_before_cart'                  => __('Above the cart', 'ship-today-delivery-dates'),
            'woocommerce_before_cart_totals'           => __('Above the cart totals', 'ship-today-delivery-dates'),
            'woocommerce_proceed_to_checkout'          => __('Next to the "Proceed to checkout" button', 'ship-today-delivery-dates'),
            'woocommerce_before_checkout_form'         => __('Above the checkout form', 'ship-today-delivery-dates'),
            'woocommerce_checkout_before_order_review' => __('Above the order review', 'ship-today-delivery-dates'),
            'woocommerce_review_order_before_submit'   => __('Above the "Place order" button', 'ship-today-delivery-dates'),
        ];
        $block_texts = [
            'product'  => [__('Above the add to cart block', 'ship-today-delivery-dates'), __('Below the add to cart block', 'ship-today-delivery-dates')],
            'cart'     => [__('Above the cart block', 'ship-today-delivery-dates'), __('Below the cart block', 'ship-today-delivery-dates')],
            'checkout' => [__('Above the checkout block', 'ship-today-delivery-dates'), __('Below the checkout block', 'ship-today-delivery-dates')],
        ];

        echo '<table class="widefat striped" style="max-width:760px"><thead><tr>';
        printf('<th>%s</th><th>%s</th><th>%s</th><th>%s</th>', esc_html__('Page', 'ship-today-delivery-dates'), esc_html__('Show', 'ship-today-delivery-dates'), esc_html__('Position', 'ship-today-delivery-dates'), esc_html__('Priority', 'ship-today-delivery-dates'));
        echo '</tr></thead><tbody>';

        foreach ($rows as $row => $set) {
            $is_block = 'block' === STDD_Auto_Placement::page_type($row);
            $field    = $name . '[' . $row . ']';

            echo '<tr><td>' . esc_html($texts[$row]) . '</td><td>';
            printf('<input type="checkbox" name="%s" value="1" %s />', esc_attr($field . '[enabled]'), checked(!empty($set['enabled']), true, false));
            echo '</td><td>';

            if ($is_block) {
                printf('<select name="%s">', esc_attr($field . '[block]'));
                foreach (['before', 'after'] as $i => $value) {
                    printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($set['block'], $value, false), esc_html($block_texts[$row][$i]));
                }
                echo '</select>';
                printf('<input type="hidden" name="%s" value="%s" />', esc_attr($field . '[hook]'), esc_attr($set['hook']));
                printf('</td><td>-<input type="hidden" name="%s" value="%s" />', esc_attr($field . '[priority]'), esc_attr($set['priority']));
            } else {
                printf('<select name="%s">', esc_attr($field . '[hook]'));
                foreach (STDD_Auto_Placement::HOOKS[$row] as $hook => $default) {
                    printf('<option value="%s" %s>%s</option>', esc_attr($hook), selected($set['hook'], $hook, false), esc_html($hooks[$hook]));
                }
                echo '</select>';
                printf('<input type="hidden" name="%s" value="%s" />', esc_attr($field . '[block]'), esc_attr($set['block']));
                printf(
                    '</td><td><input type="number" min="0" max="%d" step="1" name="%s" value="%s" placeholder="%d" style="width:80px" />',
                    (int) STDD_Auto_Placement::MAX_PRIORITY,
                    esc_attr($field . '[priority]'),
                    esc_attr($set['priority']),
                    (int) STDD_Auto_Placement::HOOKS[$row][$set['hook']]
                );
            }
            echo '</td></tr>';

            if ($is_block) {
                echo '<tr><td colspan="4" class="description">' . esc_html(
                    'product' === $row
                        ? __('The product template uses the add to cart block, so the widget can only be placed above or below this block. The priority is not available.', 'ship-today-delivery-dates')
                        : __('This page uses a WooCommerce block, so the widget can only be placed above or below the whole block. The priority is not available.', 'ship-today-delivery-dates')
                ) . '</td></tr>';
            }
        }

        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('Works next to the shortcode and independently of the ship date choice: a page with both shows two widgets. Priority: elements on the same position are shown from the lowest number. In the product summary the title has 5, the price 10, the description 20, the add to cart button 30, so 25 is above the button. Empty = a default that fits the position.', 'ship-today-delivery-dates') . '</p>';
    }

    /**
     * Section callback: Shortcode info
     */
    public function section_shortcode_info()
    {
        ?>
        <div style="background:#f0f6fc;border:1px solid #c3d9ed;border-radius:4px;padding:12px 16px;margin-bottom:20px;">
            <strong><?php esc_html_e('Use this shortcode to display the widget (or turn on "Automatic display" below):', 'ship-today-delivery-dates'); ?></strong>
            <code style="display:inline-block;margin:8px 0;padding:6px 12px;background:#fff;border-radius:3px;font-size:14px;">[stdd_notice]</code>
            <p class="description" style="margin:0 0 6px;">
                <?php esc_html_e('Place it on product pages, cart, sidebar, or anywhere you want to show the shipping countdown.', 'ship-today-delivery-dates'); ?>
            </p>
            <p class="description" style="margin:0;">
                <?php
                printf(
                    /* translators: %1$s-%5$s: shortcode attribute examples in <code> */
                    esc_html__('Attributes: %1$s (list of next dates), %2$s (custom text), %3$s or %4$s, %5$s (custom CSS class).', 'ship-today-delivery-dates'),
                    '<code>count="2"</code>',
                    '<code>text="' . esc_html__('Order {cutoff_when}, we will deliver {delivery_when}.', 'ship-today-delivery-dates') . '"</code>',
                    '<code>mode="next_slot"</code>',
                    '<code>mode="today"</code>',
                    '<code>class="' . esc_html_x('my-class', 'example CSS class name', 'ship-today-delivery-dates') . '"</code>'
                );
                ?>
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
                esc_html__('Public holidays are fetched from %s. Configure your country and language below.', 'ship-today-delivery-dates'),
                '<a href="https://www.openholidaysapi.org/" target="_blank" rel="noopener noreferrer">OpenHolidays API</a>'
            );
            ?>
        </p>
        <?php
    }

    /**
     * Section callback: tokens list
     */
    public function section_texts_info()
    {
        ?>
        <?php
        // Examples use the frontend translations, so they show the shop language's forms
        $w      = STDD_Schedule::words();
        $sunday = STDD_Schedule::day_label(7, 'until');
        $on_mon = STDD_Schedule::day_label(1, 'on');
        $on_tue = STDD_Schedule::day_label(2, 'on');
        $q      = function ($text) {
            return '"' . esc_html($text) . '"';
        };
        ?>
        <p class="description">
            <?php echo esc_html__('Tokens:', 'ship-today-delivery-dates'); ?> <code>{countdown}</code> <?php echo esc_html__('countdown', 'ship-today-delivery-dates'); ?>,
            <code>{cutoff_when}</code> <?php echo $q(sprintf($w['relative'], $w['today'], '20:00')) . ' / ' . $q(sprintf($w['relative'], $w['tomorrow'], '20:00')) . ' / ' . $q(sprintf($w['until'], $sunday, '20:00')); // phpcs:ignore WordPress.Security.EscapeOutput -- esc_html in $q. ?>,
            <code>{cutoff_day}</code> <?php echo $q($sunday); // phpcs:ignore WordPress.Security.EscapeOutput ?>, <code>{cutoff_time}</code> "20:00", <code>{cutoff_date}</code> "25.10",
            <code>{ship_when}</code> <?php echo $q($w['today']) . ' / ' . $q($w['tomorrow']) . ' / ' . $q($on_mon); // phpcs:ignore WordPress.Security.EscapeOutput ?>, <code>{ship_day}</code> <?php echo $q($on_mon); // phpcs:ignore WordPress.Security.EscapeOutput ?>, <code>{ship_date}</code>,
            <code>{delivery_when}</code> <?php echo $q($w['tomorrow']) . ' / ' . $q($on_tue); // phpcs:ignore WordPress.Security.EscapeOutput ?>, <code>{delivery_day}</code> <?php echo $q($on_tue); // phpcs:ignore WordPress.Security.EscapeOutput ?>, <code>{delivery_date}</code> (<?php echo esc_html__('requires the delivery setting', 'ship-today-delivery-dates'); ?>).
        </p>
        <?php
    }

    public function section_checkout_info()
    {
        ?>
        <p class="description">
            <?php esc_html_e('Tiles with the next dates at checkout. The ship and delivery date go to the order, emails, the thank-you page, the order screen (editable) and the "Shipping" column in the orders list.', 'ship-today-delivery-dates'); ?>
            <?php
            printf(
                /* translators: %s: [stdd_checkout] shortcode */
                esc_html__('Insert it with the %s shortcode (must be inside the checkout form) or automatically.', 'ship-today-delivery-dates'),
                '<code>[stdd_checkout]</code>'
            );
            ?>
        </p>
        <?php
        $o = $this->get_options();
        if (empty($o['checkout_enabled']) || !STDD_Checkout_Blocks::supported()) {
            return;
        }
        $status = STDD_Checkout_Blocks::block_status();
        if ('present' === $status) {
            echo '<p class="description">' . esc_html__('Block checkout: the "Ship date" block is on the checkout page. You can move it in the editor like other checkout blocks.', 'ship-today-delivery-dates') . '</p>';
        } elseif ('missing' === $status) {
            echo '<p class="description" style="color:#b32d2e;">' . esc_html__('Block checkout: the "Ship date" block is not on the checkout page, so customers cannot choose a ship date (orders are placed without it). Add the block in the checkout page editor (inside the checkout form).', 'ship-today-delivery-dates') . '</p>';
        }
        // Inserted once: can be missing if the page layout didn't allow it or the shop removed it
        if ('missing' === STDD_Checkout_Blocks::block_status(STDD_Checkout_Blocks::ROW_BLOCK) && !empty($o['checkout_review_row'])) {
            echo '<p class="description" style="color:#b32d2e;">' . esc_html__('Block checkout: the "Ship date summary row" block is not in the order summary, so the selected date is not shown there. Add it in the checkout page editor (inside the order summary).', 'ship-today-delivery-dates') . '</p>';
        }
    }

    public function field_checkout_enabled()
    {
        $o = $this->get_options();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[checkout_enabled]" value="1" <?php checked(!empty($o['checkout_enabled'])); ?> />
            <?php esc_html_e('Enable the ship date choice at checkout (required for products that need shipping)', 'ship-today-delivery-dates'); ?>
        </label>
        <?php
    }

    public function field_checkout_placement()
    {
        $o = $this->get_options();
        ?>
        <p style="margin-top:0">
            <label>
                <?php esc_html_e('Position:', 'ship-today-delivery-dates'); ?>
                <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[checkout_position]">
                    <option value="shortcode" <?php selected($o['checkout_position'], 'shortcode'); ?>><?php esc_html_e('shortcode [stdd_checkout] only', 'ship-today-delivery-dates'); ?></option>
                    <option value="after_order_notes" <?php selected($o['checkout_position'], 'after_order_notes'); ?>><?php esc_html_e('automatically below the order notes', 'ship-today-delivery-dates'); ?></option>
                    <option value="before_order_review" <?php selected($o['checkout_position'], 'before_order_review'); ?>><?php esc_html_e('automatically above the order review', 'ship-today-delivery-dates'); ?></option>
                </select>
            </label>
        </p>
        <?php if ('shortcode' === $o['checkout_position']) : ?>
            <p class="description" style="color:#b32d2e;">
                <?php esc_html_e('The [stdd_checkout] shortcode must be placed inside the checkout form. If it is missing or outside the form, the date field is not shown and orders are placed without a ship date.', 'ship-today-delivery-dates'); ?>
            </p>
        <?php endif; ?>
        <p>
            <label>
                <?php esc_html_e('Field label:', 'ship-today-delivery-dates'); ?>
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
                <?php esc_html_e('Number of dates:', 'ship-today-delivery-dates'); ?>
                <input type="number" min="1" max="12" name="<?php echo esc_attr(self::OPTION_KEY); ?>[checkout_count]" value="<?php echo esc_attr((int) $o['checkout_count']); ?>" style="width:60px" />
            </label>
            <label style="margin-left:16px">
                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[checkout_preselect]" value="1" <?php checked(!empty($o['checkout_preselect'])); ?> />
                <?php esc_html_e('select the nearest date', 'ship-today-delivery-dates'); ?>
            </label>
        </p>
        <p>
            <?php esc_html_e('Large text on the tile:', 'ship-today-delivery-dates'); ?>
            <label><input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[checkout_primary]" value="delivery" <?php checked($o['checkout_primary'], 'delivery'); ?> /> <?php esc_html_e('delivery date', 'ship-today-delivery-dates'); ?></label>
            <label style="margin-left:10px"><input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[checkout_primary]" value="ship" <?php checked($o['checkout_primary'], 'ship'); ?> /> <?php esc_html_e('ship date', 'ship-today-delivery-dates'); ?></label>
            <br><span class="description"><?php esc_html_e('Sets the default tile texts (see "Texts" below).', 'ship-today-delivery-dates'); ?></span>
        </p>
        <p>
            <label>
                <?php esc_html_e('Selection color:', 'ship-today-delivery-dates'); ?>
                <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[checkout_accent]" value="<?php echo esc_attr($o['checkout_accent']); ?>" placeholder="#8c3215" style="width:90px" />
            </label>
            <span class="description"><?php esc_html_e('(HEX, empty = dark gray)', 'ship-today-delivery-dates'); ?></span>
        </p>
        <?php
    }

    /**
     * Tile, order row and checkout message texts. Empty field = default, shown as a placeholder.
     * Exception: the second tile line. Empty = hidden, and the default is the field value (a button restores it).
     */
    public function field_checkout_texts()
    {
        $o        = $this->get_options();
        $defaults = STDD_Checkout::default_tile_texts($o['checkout_primary'], (int) $o['delivery_offset'] > 0);
        $messages = STDD_Checkout::default_messages();
        $input    = function ($key, $placeholder, $value = null) use ($o) {
            printf(
                '<input type="text" name="%s[%s]" value="%s" placeholder="%s" class="regular-text" />',
                esc_attr(self::OPTION_KEY),
                esc_attr($key),
                esc_attr(null === $value ? (string) $o[$key] : $value),
                esc_attr($placeholder)
            );
        };
        $sub = null === $o['checkout_tile_sub'] ? $defaults['sub'] : (string) $o['checkout_tile_sub'];
        ?>
        <p><strong><?php esc_html_e('Tile', 'ship-today-delivery-dates'); ?></strong></p>
        <p>
            <label><?php esc_html_e('Large text:', 'ship-today-delivery-dates'); ?><br><?php $input('checkout_tile_main', $defaults['main']); ?></label>
        </p>
        <p>
            <label><?php esc_html_e('Second line:', 'ship-today-delivery-dates'); ?><br><?php $input('checkout_tile_sub', '', $sub); ?></label>
            <button type="button" class="button button-small" data-default="<?php echo esc_attr($defaults['sub']); ?>" onclick="this.previousElementSibling.querySelector('input').value = this.dataset.default;"><?php esc_html_e('Restore default', 'ship-today-delivery-dates'); ?></button>
        </p>
        <p class="description"><?php esc_html_e('Large text: empty = default. Second line: empty = no second line.', 'ship-today-delivery-dates'); ?>
            <?php esc_html_e('Tokens:', 'ship-today-delivery-dates'); ?> <code>{ship_short}</code> <code>{delivery_short}</code> (<?php esc_html_e('e.g. "Mon 02.11"', 'ship-today-delivery-dates'); ?>), <code>{ship_when}</code> <code>{ship_day}</code> <code>{ship_date}</code> <code>{delivery_when}</code> <code>{delivery_day}</code> <code>{delivery_date}</code> <code>{cutoff_when}</code> <code>{cutoff_time}</code> <code>{cutoff_date}</code>.
            <?php esc_html_e('The screen reader label of the tile always contains the full ship and delivery date.', 'ship-today-delivery-dates'); ?></p>

        <p style="margin-top:14px"><strong><?php esc_html_e('Rows after the order is placed (emails, thank-you page, My Account)', 'ship-today-delivery-dates'); ?></strong></p>
        <p>
            <label><?php esc_html_e('Ship date label:', 'ship-today-delivery-dates'); ?><br><?php $input('checkout_row_ship_label', __('Ship date:', 'ship-today-delivery-dates')); ?></label>
        </p>
        <p>
            <label><?php esc_html_e('Delivery date label:', 'ship-today-delivery-dates'); ?><br><?php $input('checkout_row_delivery_label', __('Planned delivery:', 'ship-today-delivery-dates')); ?></label>
        </p>

        <p style="margin-top:14px"><strong><?php esc_html_e('Checkout messages', 'ship-today-delivery-dates'); ?></strong></p>
        <p><label><?php esc_html_e('No date selected:', 'ship-today-delivery-dates'); ?><br><?php $input('checkout_msg_choose', $messages['checkout_msg_choose']); ?></label></p>
        <p><label><?php esc_html_e('Cutoff time of the selected date has passed:', 'ship-today-delivery-dates'); ?><br><?php $input('checkout_msg_closed', $messages['checkout_msg_closed']); ?></label></p>
        <p><label><?php esc_html_e('Selected date is not available:', 'ship-today-delivery-dates'); ?><br><?php $input('checkout_msg_unavailable', $messages['checkout_msg_unavailable']); ?></label></p>
        <p><label><?php esc_html_e('Selected date closed while the page was open:', 'ship-today-delivery-dates'); ?><br><?php $input('checkout_msg_switched', $messages['checkout_msg_switched']); ?></label></p>
        <p><label><?php esc_html_e('Selected date does not exist for the newly chosen shipping method:', 'ship-today-delivery-dates'); ?><br><?php $input('checkout_msg_method', $messages['checkout_msg_method']); ?></label></p>
        <p class="description"><?php esc_html_e('Empty = default text (shown in the field), translated to the store language.', 'ship-today-delivery-dates'); ?>
            <?php esc_html_e('Tokens:', 'ship-today-delivery-dates'); ?> <code>{field}</code> (<?php esc_html_e('field name', 'ship-today-delivery-dates'); ?>), <code>{slot}</code> (<?php esc_html_e('date, e.g. "Tue 03.11"', 'ship-today-delivery-dates'); ?>), <code>{next_slot}</code> (<?php esc_html_e('newly selected date', 'ship-today-delivery-dates'); ?>).</p>
        <?php
    }

    /**
     * Toggle checkbox with a description. The _toggles marker tells sanitize_options() that the form had toggles.
     */
    private function toggle_checkbox($key, $label, $description)
    {
        $o = $this->get_options();
        printf(
            '<p style="margin:0 0 2px"><input type="hidden" name="%1$s[_toggles]" value="1" /><label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label></p><p class="description" style="margin:0 0 12px 24px">%5$s</p>',
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            checked(!empty($o[$key]), true, false),
            esc_html($label),
            esc_html($description)
        );
    }

    public function field_checkout_display()
    {
        $o       = $this->get_options();
        $enabled = !empty($o['checkout_enabled']);
        // Classes for checkout-settings.js. With the module off only the rows and the list work (with the "dates when disabled" toggle on)
        $part    = function ($class, $visible, callable $toggle) {
            printf('<div class="%s%s">', esc_attr($class), $visible ? '' : ' hidden');
            $toggle();
            echo '</div>';
        };
        $dates   = $enabled || !empty($o['checkout_dates_when_disabled']);

        $part('stdd-if-module', $enabled, function () {
            $this->toggle_checkbox('checkout_review_row', __('Row with the date in the order summary at checkout', 'ship-today-delivery-dates'), __('The selected date as a row in the order summary: in the classic checkout in the order review table, in the block checkout as the "Ship date summary row" block.', 'ship-today-delivery-dates'));
        });
        $part('stdd-if-dates', $dates, function () {
            $this->toggle_checkbox('checkout_order_rows', __('"Ship date" and "Planned delivery" rows after the order is placed', 'ship-today-delivery-dates'), __('Thank-you page, order emails (customer and admin) and My Account.', 'ship-today-delivery-dates'));
        });
        $part('stdd-if-module', $enabled, function () {
            $this->toggle_checkbox('checkout_admin_edit', __('Change the ship date in the order edit screen', 'ship-today-delivery-dates'), __('Date field in the order admin; the delivery date is recalculated and an order note is added. Off: the dates are shown read-only.', 'ship-today-delivery-dates'));
        });
        $part('stdd-if-dates', $dates, function () {
            $this->toggle_checkbox('checkout_list_column', __('"Shipping" column in the orders list', 'ship-today-delivery-dates'), __('Column with sorting and a ship date filter in WooCommerce → Orders.', 'ship-today-delivery-dates'));
        });
        $this->toggle_checkbox('checkout_dates_when_disabled', __('Show dates of existing orders also when the module is disabled', 'ship-today-delivery-dates'), __('Orders keep their dates when you turn the module off. On: they are still shown in emails, on the thank-you page, in My Account, in the order admin (read-only) and in the orders list, according to the options above. Off: they are hidden until the module is enabled again.', 'ship-today-delivery-dates'));
    }

    public function field_holidays_auto_fetch()
    {
        $this->toggle_checkbox('holidays_auto_fetch', __('Download holidays automatically', 'ship-today-delivery-dates'), __('Connects to OpenHolidays API right after activation, about every 4 months and after changing the country, language or region. Off: holidays are downloaded only with the "Fetch holidays now" button below.', 'ship-today-delivery-dates'));
    }

    public function field_advanced_toggles()
    {
        $this->toggle_checkbox('cache_purge_at_cutoff', __('Purge the page cache at each cutoff time', 'ship-today-delivery-dates'), __('Only with LiteSpeed Cache or a hooked stdd_purge_cache action: a WP-Cron event at each cutoff time, so cached pages get a fresh start text. The cache is still purged after saving the settings.', 'ship-today-delivery-dates'));
        $this->toggle_checkbox('test_mode', __('Test mode ?stdd_now=', 'ship-today-delivery-dates'), __('Shop managers can add ?stdd_now=2026-12-20T19:59 to any URL to see the widget and the checkout at another time.', 'ship-today-delivery-dates'));
        $this->toggle_checkbox('wpml_strings', __('WPML: translate the widget texts', 'ship-today-delivery-dates'), __('With WPML active: the four widget texts are registered as strings when saving and shown translated. Off: the texts are shown as saved.', 'ship-today-delivery-dates'));
    }

    public function field_mode()
    {
        $o = $this->get_options();
        ?>
        <fieldset>
            <label>
                <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[mode]" value="today" <?php checked($o['mode'], 'today'); ?> />
                <?php esc_html_e('Ship today: countdown to today\'s cutoff time, separate texts after it and on non-shipping days', 'ship-today-delivery-dates'); ?>
            </label>
            <br>
            <label>
                <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[mode]" value="next_slot" <?php checked($o['mode'], 'next_slot'); ?> />
                <?php esc_html_e('Next date: always a countdown to the nearest cutoff time and one text with dates (e.g. shipping twice a week)', 'ship-today-delivery-dates'); ?>
            </label>
        </fieldset>
        <?php
    }

    public function field_delivery_days()
    {
        $this->render_days_table(self::OPTION_KEY, $this->get_options());
        echo '<p class="description">' . esc_html__('The cutoff time belongs to the ship day. Example: shipping on Monday, 20:00, "the day before" = orders until Sunday 20:00 are shipped on Monday.', 'ship-today-delivery-dates') . '</p>';
    }

    /**
     * Shipping days, cutoffs and offsets table: global (name = OPTION_KEY) or for a method (name = METHOD_KEY[key]).
     */
    private function render_days_table($name, array $o)
    {
        $days = [
            1 => __('Monday', 'ship-today-delivery-dates'),
            2 => __('Tuesday', 'ship-today-delivery-dates'),
            3 => __('Wednesday', 'ship-today-delivery-dates'),
            4 => __('Thursday', 'ship-today-delivery-dates'),
            5 => __('Friday', 'ship-today-delivery-dates'),
            6 => __('Saturday', 'ship-today-delivery-dates'),
            7 => __('Sunday', 'ship-today-delivery-dates'),
        ];
        $offsets = [0 => __('same day', 'ship-today-delivery-dates'), 1 => __('the day before', 'ship-today-delivery-dates')];
        for ($n = 2; $n <= 6; $n++) {
            /* translators: %d: number of days (2-6) the cutoff time falls before the ship day */
            $offsets[$n] = sprintf(_n('%d day earlier', '%d days earlier', $n, 'ship-today-delivery-dates'), $n);
        }

        $cutoff_times = $o['cutoff_times'] ?? [];
        $cutoff_offsets = $o['cutoff_offsets'] ?? [];

        echo '<table class="widefat striped" style="max-width:640px">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Day', 'ship-today-delivery-dates') . '</th>';
        echo '<th>' . esc_html__('Shipping', 'ship-today-delivery-dates') . '</th>';
        echo '<th>' . esc_html__('Cutoff time', 'ship-today-delivery-dates') . '</th>';
        echo '<th>' . esc_html__('Cutoff time falls', 'ship-today-delivery-dates') . '</th>';
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
                    <td><input type="time" name="%s[cutoff_times][%d]" value="%s" style="width:auto;min-width:100px" /></td>
                    <td><select name="%s[cutoff_offsets][%d]">%s</select></td>
                </tr>',
                esc_html($label),
                esc_attr($name),
                (int)$num,
                checked(in_array($num, array_map('intval', (array) ($o['delivery_days'] ?? [])), true), true, false),
                esc_attr($name),
                (int)$num,
                esc_attr($cutoff),
                esc_attr($name),
                (int)$num,
                wp_kses($options_html, ['option' => ['value' => true, 'selected' => true]])
            );
        }

        echo '</tbody></table>';
    }

    public function field_delivery()
    {
        $o = $this->get_options();
        ?>
        <p>
            <label>
                <?php esc_html_e('Delivery', 'ship-today-delivery-dates'); ?>
                <input type="number" min="0" max="14" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[delivery_offset]" value="<?php echo esc_attr((int) $o['delivery_offset']); ?>" style="width:60px" />
                <?php esc_html_e('delivery day(s) after shipping', 'ship-today-delivery-dates'); ?>
            </label>
            <span class="description"><?php esc_html_e('(0 = do not show the delivery date)', 'ship-today-delivery-dates'); ?></span>
        </p>
        <p>
            <?php esc_html_e('Days without deliveries:', 'ship-today-delivery-dates'); ?>
            <?php foreach (range(1, 7) as $num) : ?>
                <label style="margin-right:10px">
                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[no_delivery_days][]" value="<?php echo (int) $num; ?>" <?php checked(in_array($num, (array) $o['no_delivery_days'], true)); ?> />
                    <?php echo esc_html(STDD_Schedule::day_label($num, 'short')); ?>
                </label>
            <?php endforeach; ?>
        </p>
        <p class="description"><?php esc_html_e('Public holidays are always skipped when counting the delivery date.', 'ship-today-delivery-dates'); ?></p>
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
        echo '<p class="description">' . esc_html__('Enter dates in dd-mm-yyyy format, one per line or comma-separated. These dates are excluded even if they are weekdays.', 'ship-today-delivery-dates') . '</p>';
    }

    /**
     * Preview of the next 5 slots from the saved settings.
     */
    public function field_preview()
    {
        if ($this->render_preview($this->schedule())) {
            printf(
                '<p class="description">%s</p>',
                sprintf(
                    /* translators: %s: example URL parameter ?stdd_now=... */
                    esc_html__('Based on the saved settings and fetched holidays. To test another moment, add %s to the page address (shop managers only).', 'ship-today-delivery-dates'),
                    '<code>?stdd_now=2026-12-20T19:59</code>'
                )
            );
        }
    }

    /** Table of the next 5 slots. Returns whether there were any. */
    private function render_preview(STDD_Schedule $schedule)
    {
        $slots = $schedule->slots(5);

        if (!$slots) {
            /* translators: %d: number of days searched */
            echo '<p class="description">' . esc_html(sprintf(_n('No dates in the next %d day. Check the shipping days.', 'No dates in the next %d days. Check the shipping days.', STDD_Schedule::HORIZON_DAYS, 'ship-today-delivery-dates'), STDD_Schedule::HORIZON_DAYS)) . '</p>';
            return false;
        }

        printf(
            '<table class="widefat striped" style="max-width:640px"><thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>',
            esc_html__('Orders until', 'ship-today-delivery-dates'),
            esc_html_x('Shipping', 'column with the ship date', 'ship-today-delivery-dates'),
            esc_html__('Delivery', 'ship-today-delivery-dates')
        );
        foreach ($slots as $slot) {
            printf(
                '<tr><td>%s, %s</td><td>%s, %s</td><td>%s</td></tr>',
                esc_html(STDD_Schedule::day_label((int) $slot['cutoff']->format('N'), 'name')),
                esc_html($slot['cutoff']->format('d.m.Y H:i')),
                esc_html(STDD_Schedule::day_label((int) $slot['ship']->format('N'), 'name')),
                esc_html($slot['ship']->format('d.m.Y')),
                $slot['delivery'] ? esc_html(STDD_Schedule::day_label((int) $slot['delivery']->format('N'), 'name') . ', ' . $slot['delivery']->format('d.m.Y')) : '-'
            );
        }
        echo '</tbody></table>';

        return true;
    }

    /**
     * Method schedules: WooCommerce zones and methods, each with "own hours" and a table like the global one.
     */
    public function field_method_schedules()
    {
        $methods = $this->shipping_methods();
        $saved   = $this->get_method_schedules();
        $global  = $this->get_options();

        printf('<input type="hidden" name="%s[_form]" value="1" />', esc_attr(self::METHOD_KEY));

        if (!$methods) {
            echo '<p class="description">' . esc_html__('No shipping methods in WooCommerce shipping zones.', 'ship-today-delivery-dates') . '</p>';
            return;
        }

        echo '<p class="description" style="margin-bottom:8px">' . esc_html__('Different shipping days and cutoff times for a shipping method (e.g. parcel lockers until 15:00, courier until 13:00). Without own times the method uses the table in Settings. Holidays, excluded dates and days without deliveries are shared.', 'ship-today-delivery-dates') . '</p>';

        foreach ($methods as $key => $label) {
            $entry   = $saved[$key] ?? [];
            $enabled = !empty($entry['enabled']);
            $name    = self::METHOD_KEY . '[' . $key . ']';
            // Without a saved entry the table starts from the global settings
            $values  = $entry ? $entry : $global;

            printf(
                '<details style="margin:0 0 8px;max-width:640px"%s><summary><strong>%s</strong> <code>%s</code>%s</summary><div style="padding:8px 0 4px 16px">',
                $enabled ? ' open' : '',
                esc_html($label),
                esc_html($key),
                $enabled ? ' - ' . esc_html__('own times', 'ship-today-delivery-dates') : ''
            );
            printf(
                '<p><label><input type="checkbox" name="%s[enabled]" value="1" %s /> %s</label></p>',
                esc_attr($name),
                checked($enabled, true, false),
                esc_html__('Own shipping days and cutoff times for this method', 'ship-today-delivery-dates')
            );
            $this->render_days_table($name, $values);
            printf(
                '<p><label>%s <input type="number" min="0" max="14" name="%s[delivery_offset]" value="%s" placeholder="%s" style="width:70px" /></label> <span class="description">%s</span></p>',
                esc_html__('Delivery days after shipping:', 'ship-today-delivery-dates'),
                esc_attr($name),
                esc_attr(isset($entry['delivery_offset']) ? (string) $entry['delivery_offset'] : ''),
                esc_attr((string) (int) $global['delivery_offset']),
                esc_html__('empty = as in "Delivery"', 'ship-today-delivery-dates')
            );
            if ($enabled) {
                $this->render_preview($this->schedule($key));
            }
            echo '</div></details>';
        }
    }

    /**
     * Method entries from the form. Without the _form marker the saved value stays.
     * Entries of methods missing from the form (removed in WooCommerce) are dropped on save.
     */
    public function sanitize_method_schedules($input)
    {
        if (!is_array($input)) {
            return $this->get_method_schedules();
        }
        // A form without methods (e.g. WooCommerce off) deletes nothing. An array without _form is a write from code or the
        // second sanitize pass on add_option, normalized the same way.
        if (!empty($input['_form']) && !$this->shipping_methods()) {
            return $this->get_method_schedules();
        }

        $clean = [];
        foreach ($input as $key => $entry) {
            if (!self::valid_method_key($key) || !is_array($entry)) {
                continue;
            }

            $times   = [];
            $offsets = [];
            foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
                $time = (string) ($entry['cutoff_times'][$day] ?? '');
                $times[$day]   = preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '14:00';
                $offsets[$day] = max(0, min(6, (int) ($entry['cutoff_offsets'][$day] ?? 0)));
            }
            $delivery = trim((string) ($entry['delivery_offset'] ?? ''));

            $clean[$key] = [
                'enabled'         => !empty($entry['enabled']),
                'delivery_days'   => array_values(array_intersect([1, 2, 3, 4, 5, 6, 7], array_map('intval', (array) ($entry['delivery_days'] ?? [])))),
                'cutoff_times'    => $times,
                'cutoff_offsets'  => $offsets,
                'delivery_offset' => '' === $delivery ? '' : max(0, min(14, (int) $delivery)),
            ];
        }

        return $clean;
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
        esc_html_e('Two-letter ISO 3166-1 country code. Examples: PL (Poland), DE (Germany), CZ (Czech Republic), FR (France), GB (United Kingdom).', 'ship-today-delivery-dates');
        echo ' <a href="https://openholidaysapi.org/Countries" target="_blank" rel="noopener noreferrer">' . esc_html__('See supported countries', 'ship-today-delivery-dates') . '</a></p>';
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
        esc_html_e('Two-letter ISO 639-1 language code for holiday names. Examples: PL (Polish), DE (German), EN (English), FR (French).', 'ship-today-delivery-dates');
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
        esc_html_e('Optional ISO 3166-2 region code, e.g. DE-BY. Empty: only nationwide holidays. With a region, its regional holidays also block shipping.', 'ship-today-delivery-dates');
        printf(
            ' <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
            esc_url('https://openholidaysapi.org/Subdivisions?countryIsoCode=' . rawurlencode($o['country_code'])),
            esc_html__('Region codes for this country', 'ship-today-delivery-dates')
        );
    }

    /**
     * Field: holidays of the current and next year, each can be ignored by date.
     */
    public function field_holidays_list()
    {
        global $wpdb;
        $o = $this->get_options();
        $table = $wpdb->prefix . self::TABLE_NAME;

        $year = (int) wp_date('Y');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own holiday table, no WP API, admin only.
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
            echo '<p class="description">' . esc_html__('No holidays found. Click "Fetch holidays now" at the bottom of this page.', 'ship-today-delivery-dates') . '</p>';
            return;
        }

        $years_found = array_unique(array_map(function ($h) {
            return (int) substr($h->holiday_date, 0, 4);
        }, $holidays));
        if (!in_array($year + 1, $years_found, true)) {
            /* translators: %d: year */
            echo '<p class="description" style="color:#b32d2e">' . esc_html(sprintf(__('No holidays for %d. Click "Fetch holidays now" at the bottom of the page.', 'ship-today-delivery-dates'), $year + 1)) . '</p>';
        }

        $excluded = (array) ($o['excluded_holiday_dates'] ?? []);

        echo '<div style="max-height:300px;overflow-y:auto;border:1px solid #ddd;padding:10px;background:#fff;max-width:500px">';
        echo '<table class="widefat striped" style="margin:0">';
        echo '<thead><tr>';
        echo '<th style="width:30px">' . esc_html__('Ignore', 'ship-today-delivery-dates') . '</th>';
        echo '<th>' . esc_html__('Date', 'ship-today-delivery-dates') . '</th>';
        echo '<th>' . esc_html__('Holiday name', 'ship-today-delivery-dates') . '</th>';
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
                esc_attr__('Check to IGNORE this holiday (shipping will be available)', 'ship-today-delivery-dates'),
                esc_html($date_formatted),
                esc_html($h->holiday_name ?: '-')
            );
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '<p class="description">' . esc_html__('Check holidays to IGNORE them (shipping will be available on these days). Unchecked holidays block shipping.', 'ship-today-delivery-dates') . '</p>';
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
                <?php esc_html_e('Disabled (widget completely hidden)', 'ship-today-delivery-dates'); ?>
            </label>
            <br>
            <label>
                <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[visibility_mode]" value="always" <?php checked($mode, 'always'); ?> />
                <?php esc_html_e('Always visible', 'ship-today-delivery-dates'); ?>
            </label>
            <br>
            <label>
                <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[visibility_mode]" value="before_cutoff_only" <?php checked($mode, 'before_cutoff_only'); ?> />
                <?php esc_html_e('Only before cutoff time (hide after cutoff)', 'ship-today-delivery-dates'); ?>
            </label>
            <br>
            <label>
                <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[visibility_mode]" value="hours_before" <?php checked($mode, 'hours_before'); ?> />
                <?php esc_html_e('Only', 'ship-today-delivery-dates'); ?>
                <input type="number" name="<?php echo esc_attr(self::OPTION_KEY); ?>[visibility_hours_before]" value="<?php echo esc_attr($hours); ?>" min="0.5" max="168" step="0.5" style="width:60px" />
                <?php esc_html_e('hour(s) before cutoff', 'ship-today-delivery-dates'); ?>
            </label>
        </fieldset>
        <p class="description"><?php esc_html_e('Control when the widget is displayed to customers.', 'ship-today-delivery-dates'); ?> <?php esc_html_e('In "next date" mode, "only before cutoff time" works like "always".', 'ship-today-delivery-dates'); ?></p>
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
                    <label><?php esc_html_e('Hours label:', 'ship-today-delivery-dates'); ?></label>
                </td>
                <td style="padding:5px 10px;">
                    <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[countdown_label_h]" value="<?php echo esc_attr($label_h); ?>" style="width:60px" placeholder="h" />
                </td>
            </tr>
            <tr>
                <td style="padding:5px 10px 5px 0;">
                    <label><?php esc_html_e('Minutes label:', 'ship-today-delivery-dates'); ?></label>
                </td>
                <td style="padding:5px 10px;">
                    <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[countdown_label_m]" value="<?php echo esc_attr($label_m); ?>" style="width:60px" placeholder="min" />
                </td>
            </tr>
            <tr>
                <td style="padding:5px 10px 5px 0;">
                    <label><?php esc_html_e('Seconds label:', 'ship-today-delivery-dates'); ?></label>
                </td>
                <td style="padding:5px 10px;">
                    <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[countdown_label_s]" value="<?php echo esc_attr($label_s); ?>" style="width:60px" placeholder="s" />
                </td>
            </tr>
            <tr>
                <td colspan="2" style="padding:5px 10px 5px 0;">
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[countdown_show_seconds]" value="1" <?php checked($show_seconds); ?> />
                        <?php esc_html_e('Show seconds in countdown', 'ship-today-delivery-dates'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <p class="description">
            <?php esc_html_e('Examples: "1h 30min 10s" or "01:30" (without seconds). Leave label empty to hide it completely.', 'ship-today-delivery-dates'); ?>
            <?php
            printf(
                /* translators: %1$s: "1 day", %2$s: "3 days" */
                esc_html__('Above 24 hours the countdown also shows days ("%1$s", "%2$s").', 'ship-today-delivery-dates'),
                esc_html(sprintf(self::countdown_days(1), 1)),
                esc_html(sprintf(self::countdown_days(3), 3))
            );
            ?>
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
            /* translators: %s: example text with tokens */
            esc_html(sprintf(__('E.g. "%s"', 'ship-today-delivery-dates'), __('Order {cutoff_when}, we will deliver {delivery_when}. Time left: {countdown}', 'ship-today-delivery-dates')))
        );
    }

    public function field_text_before_cutoff()
    {
        $o = $this->get_options();
        printf(
            '<textarea name="%s[text_before_cutoff]" rows="3" class="large-text">%s</textarea><p class="description">%s</p>',
            esc_attr(self::OPTION_KEY),
            esc_textarea($o['text_before_cutoff']),
            esc_html__('"Ship today" mode.', 'ship-today-delivery-dates') . ' ' . esc_html__('Available token: {countdown}', 'ship-today-delivery-dates')
        );
    }

    public function field_text_after_cutoff()
    {
        $o = $this->get_options();
        $show = !empty($o['show_after_cutoff']);
        ?>
        <label style="display:block;margin-bottom:8px;">
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_after_cutoff]" value="1" <?php checked($show); ?> />
            <?php esc_html_e('Show this message after cutoff time', 'ship-today-delivery-dates'); ?>
        </label>
        <textarea name="<?php echo esc_attr(self::OPTION_KEY); ?>[text_after_cutoff]" rows="3" class="large-text"><?php echo esc_textarea($o['text_after_cutoff']); ?></textarea>
        <p class="description"><?php esc_html_e('"Ship today" mode.', 'ship-today-delivery-dates'); ?> <?php esc_html_e('If disabled, the widget will be hidden after cutoff time.', 'ship-today-delivery-dates'); ?></p>
        <?php
    }

    public function field_text_not_shipping_day()
    {
        $o = $this->get_options();
        $show = !empty($o['show_not_shipping_day']);
        ?>
        <label style="display:block;margin-bottom:8px;">
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_not_shipping_day]" value="1" <?php checked($show); ?> />
            <?php esc_html_e('Show this message on non-shipping days', 'ship-today-delivery-dates'); ?>
        </label>
        <textarea name="<?php echo esc_attr(self::OPTION_KEY); ?>[text_not_shipping_day]" rows="3" class="large-text"><?php echo esc_textarea($o['text_not_shipping_day']); ?></textarea>
        <p class="description"><?php esc_html_e('"Ship today" mode.', 'ship-today-delivery-dates'); ?> <?php esc_html_e('If disabled, the widget will be hidden on weekends, holidays, and excluded dates.', 'ship-today-delivery-dates'); ?></p>
        <?php
    }

    /* ---------------------------------------------------------
     * Holidays fetch + cron
     * --------------------------------------------------------- */
    public function cron_sync_holidays()
    {
        // Event scheduled before the toggle was turned off: fetch nothing, clear the rest
        if (empty($this->get_options()['holidays_auto_fetch'])) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            return;
        }

        if ($this->fetch_and_store_holidays()) {
            return;
        }

        // Network or API error: retry in 6 hours unless something is scheduled sooner
        $next = wp_next_scheduled(self::CRON_HOOK);
        if (!$next || $next > time() + 6 * HOUR_IN_SECONDS) {
            wp_schedule_single_event(time() + 6 * HOUR_IN_SECONDS, self::CRON_HOOK);
        }
    }

    /**
     * Fetches the current and next year (January holidays are already needed in December).
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

        // An empty or invalid response keeps the saved holidays (every country has a holiday in a year)
        if (!is_array($data) || !$data) {
            return false;
        }

        $subdivision = self::valid_subdivision($o['holiday_subdivision'], $country) ? $o['holiday_subdivision'] : '';
        $holidays    = self::holidays_from_api($data, $lang, $subdivision, $validFrom, $validTo);

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Remove this year for this country before insert (exclusions are stored by date, so they survive)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own holiday table, no WP API, nothing to invalidate after saving.
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
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- own holiday table.
            $wpdb->insert($table, [
                'country_code' => $country,
                'holiday_date' => $date,
                'holiday_name' => $name,
            ]);
        }

        return true;
    }

    /**
     * ISO 3166-2 region code of a country, e.g. DE-BY or DE-BY-AU (OpenHolidays codes are hierarchical).
     */
    public static function valid_subdivision($code, $country)
    {
        return is_string($code) && (bool) preg_match('/^' . preg_quote((string) $country, '/') . '(-[A-Z0-9]{1,5})+$/', $code);
    }

    /**
     * OpenHolidays /PublicHolidays response to [Y-m-d => name].
     * Regional holidays (nationwide: false) only for the selected region or its parent
     * (DE-BY-AU gets DE-BY holidays, DE-BY does not get DE-BY-AU ones).
     * Multi-day holidays (startDate..endDate) expand to single days, clipped to the year.
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

            // Dates as Y-m-d text (no timezones), 31 day limit in case of bad data
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
        add_shortcode('stdd_notice', [$this, 'shortcode_output']);
    }

    /**
     * Registers the widget assets and enqueues them in <head> where the widget is expected.
     * shortcode_output() enqueues them late for any other place.
     */
    public function register_assets()
    {
        if ('disabled' === $this->get_options()['visibility_mode']) {
            return;
        }

        $this->register_widget_assets();

        /**
         * Whether to load the widget JS and CSS in <head> on this page (default: shortcode in the main query posts or automatic display).
         * True forces loading, e.g. for a widget in AJAX-loaded content before StddNotice.init().
         */
        if (apply_filters('stdd_load_assets', $this->page_has_shortcode() || STDD_Auto_Placement::active_on_page($this->get_options()))) {
            $this->enqueue_widget_assets();
        }
    }

    /**
     * Shortcode in the content or excerpt of the main query posts. The Shortcode block stores plain text, so it is detected too.
     */
    private function page_has_shortcode()
    {
        global $wp_query;

        foreach ((array) ($wp_query->posts ?? []) as $post) {
            if ($post instanceof WP_Post && (has_shortcode($post->post_content, 'stdd_notice') || has_shortcode($post->post_excerpt, 'stdd_notice'))) {
                return true;
            }
        }

        return false;
    }

    /** Registers the widget JS and CSS, with the Style tab CSS inline. */
    private function register_widget_assets()
    {
        if (wp_style_is('ship-today-delivery-dates', 'registered')) {
            return;
        }

        wp_register_style(
            'ship-today-delivery-dates',
            plugins_url('assets/css/notice.css', __FILE__),
            [],
            STDD_VERSION
        );
        $style_css = $this->style_css();
        if ('' !== $style_css) {
            wp_add_inline_style('ship-today-delivery-dates', $style_css);
        }
        wp_register_script(
            'ship-today-delivery-dates',
            plugins_url('assets/js/notice.js', __FILE__),
            [],
            STDD_VERSION,
            true
        );
    }

    private function enqueue_widget_assets()
    {
        $this->register_widget_assets();
        wp_enqueue_style('ship-today-delivery-dates');
        wp_enqueue_script('ship-today-delivery-dates');
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
            'mode'   => '',
            'class'  => '',
            'method' => '',
        ], $atts, 'stdd_notice');

        $mode     = in_array($atts['mode'], ['today', 'next_slot'], true) ? $atts['mode'] : $o['mode'];
        $count    = max(1, min(10, (int) $atts['count']));
        $text     = wp_kses_post($atts['text']);
        // method="flat_rate:3": that method's schedule, no customer detection because the page may be cached
        $schedule = $this->schedule(trim((string) $atts['method']));

        $config = $this->frontend_config($o, $mode, $count, $text, $schedule);
        $state  = $this->initial_state($config, $schedule);

        $classes = array_merge(['stdd-notice'], array_filter(array_map('sanitize_html_class', preg_split('/\s+/', (string) $atts['class']))));
        if ($count > 1) {
            $classes[] = 'stdd-notice--list';
        }
        $classes[] = $state['hide'] ? 'stdd-notice--hidden' : 'stdd-notice--' . $state['class'];

        // Widget outside post content (sidebar, template, page builder): the script goes to the footer and the CSS is printed
        // next to the widget if <head> is already out, so there is no unstyled moment
        $this->enqueue_widget_assets();
        $css = '';
        if (did_action('wp_head') && !wp_style_is('ship-today-delivery-dates', 'done')) {
            ob_start();
            wp_print_styles('ship-today-delivery-dates');
            $css = (string) ob_get_clean();
        }

        return $css . sprintf(
            '<div class="%s" role="status" aria-live="polite" data-stdd="%s"%s>%s</div>',
            esc_attr(implode(' ', $classes)),
            esc_attr(wp_json_encode($config)),
            $state['hide'] ? ' style="display:none;"' : '',
            $state['html'] // phpcs:ignore WordPress.Security.EscapeOutput -- texts from wp_kses_post and day labels.
        );
    }

    /**
     * Data for JS: slots for FRONTEND_DAYS days as absolute timestamps, so it works from a cached page.
     */
    private function frontend_config(array $o, $mode, $count, $text, STDD_Schedule $schedule)
    {
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
            $templates[$state] = (string) apply_filters('stdd_text', $template, $state, $mode, $schedule->method());
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
                'day'     => self::countdown_days(1),
                'days'    => self::countdown_days(2),
            ],
            'words'      => STDD_Schedule::words(),
        ];
    }

    /**
     * Same state as notice.js, computed on the server (first paint and no JS).
     */
    private function initial_state(array $config, STDD_Schedule $schedule)
    {
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
            $html     = str_replace('{countdown}', '<span class="stdd-countdown"></span>', $html);
            // A single slot too: the container is a flex box with a gap (see render() in notice.js)
            $items[]  = '<div class="stdd-notice__item">' . $html . '</div>';
        }

        return [
            'hide'  => $hide,
            'class' => in_array($state, ['before', 'next'], true) ? 'before' : 'after',
            'html'  => implode('', $items),
        ];
    }


    /**
     * "%d day" / "%d days" in the countdown. JS gets the forms for 1 and 2 and picks by d === 1,
     * so languages with more plural forms are simplified here.
     */
    private static function countdown_days($n)
    {
        /* translators: %d: number of days in the countdown, e.g. "3 days 05h 12min" */
        return _n('%d day', '%d days', $n, 'ship-today-delivery-dates');
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

        // Checkout texts: custom ones only (empty = plugin translation)
        foreach (array_merge(self::CHECKOUT_TEXTS, ['checkout_tile_sub']) as $key) {
            if (isset($new[$key]) && '' !== (string) $new[$key]) {
                icl_register_string(self::WPML_DOMAIN, $key, (string) $new[$key]);
            }
        }
    }

    /** Text from settings translated by WPML when the toggle is on, otherwise unchanged. */
    public function t($key, $fallback)
    {
        if (empty($this->get_options()['wpml_strings'])) {
            return $fallback;
        }
        if (function_exists('icl_t')) {
            return icl_t(self::WPML_DOMAIN, $key, $fallback);
        }
        if (has_filter('wpml_translate_single_string')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML filter.
            return apply_filters('wpml_translate_single_string', $fallback, self::WPML_DOMAIN, $key);
        }
        return $fallback;
    }
}

STDD_Plugin::instance();
