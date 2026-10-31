<?php
/**
 * Ship date choice in the block checkout.
 *
 * Inner block "wc-stn/ship-date" (parent: woocommerce/checkout-fields-block) with the same tiles as the classic checkout.
 * JS has no build step (assets/blocks/ship-date/).
 * - The block is inserted once into a block checkout page, below the shipping options. After that the shop can move or remove it,
 *   and a removed block is not added back. It is not a forced block (lock.remove) because WooCommerce appends those at the end
 *   of the form, below the place order button.
 * - The date goes to the Store API through setExtensionData (namespace "wc-stn") with a marker that the block was visible.
 * - Validated and saved like in the classic checkout. Without the marker (express payment, external Store API order)
 *   no date is required, so a missing block never blocks orders.
 *
 * Hooks are registered only with the module on.
 */

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;

if (!defined('ABSPATH')) {
    exit;
}

final class WC_STN_Checkout_Blocks
{
    const BLOCK = 'wc-stn/ship-date';

    /** Row with the selected slot in the order summary, like review_row() in the classic checkout. */
    const ROW_BLOCK = 'wc-stn/ship-date-row';

    /** Checkout page ID the summary row was already inserted into. */
    const ROW_INSERTED_KEY = 'wc_ship_today_notice_row_inserted';

    /** Store API extension namespace (extensions.wc-stn). */
    const API_NAMESPACE = 'wc-stn';

    /** Checkout page ID the block was already inserted into. */
    const BLOCK_INSERTED_KEY = 'wc_ship_today_notice_block_inserted';

    /** User meta: checkout page ID the "missing block" notice was dismissed for. */
    const NOTICE_DISMISSED_META = 'wc_stn_block_notice_dismissed';

    /** Tested on WooCommerce 11.x. Older versions get no block, so the block checkout has no ship date choice. */
    const MIN_WC_VERSION = '10.1.0';

    /** @var WC_Ship_Today_Notice */
    private $plugin;

    /** @var WC_STN_Checkout */
    private $checkout;

    public function __construct(WC_Ship_Today_Notice $plugin, WC_STN_Checkout $checkout)
    {
        $this->plugin   = $plugin;
        $this->checkout = $checkout;

        if (empty($plugin->get_options()['checkout_enabled']) || !self::supported()) {
            return;
        }

        add_action('init', [$this, 'register_block'], 2);
        add_action('woocommerce_blocks_checkout_block_registration', [$this, 'register_integration']);
        // The module starts on init 0, usually after woocommerce_blocks_loaded
        if (did_action('woocommerce_blocks_loaded')) {
            $this->register_store_api_data();
        } else {
            add_action('woocommerce_blocks_loaded', [$this, 'register_store_api_data']);
        }
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'validate_and_save'], 10, 2);
        add_action('admin_init', [$this, 'maybe_insert_block']);
        add_action('admin_init', [$this, 'maybe_dismiss_notice']);
        add_action('admin_notices', [$this, 'missing_block_notice']);
    }

    public static function supported()
    {
        return defined('WC_VERSION') && version_compare(WC_VERSION, self::MIN_WC_VERSION, '>=');
    }

    /**
     * Block type for the editor (block.json) and scripts. The frontend script registers the component through wc.blocksCheckout.
     */
    public function register_block()
    {
        $dir = plugin_dir_path(WC_STN_FILE) . 'assets/blocks/ship-date/';
        $url = plugins_url('assets/blocks/ship-date/', WC_STN_FILE);

        wp_register_style('wc-stn-checkout', plugins_url('assets/css/checkout.css', WC_STN_FILE), [], WC_STN_VERSION);
        wp_register_script('wc-stn-ship-date-frontend', $url . 'frontend.js', ['wc-blocks-checkout', 'wc-settings', 'wp-element', 'wp-data'], WC_STN_VERSION, true);
        wp_register_script('wc-stn-ship-date-editor', $url . 'editor.js', ['wp-blocks', 'wp-block-editor', 'wp-element'], WC_STN_VERSION, true);
        if (is_admin()) {
            // Admin only: don't compute slots on every frontend request
            wp_add_inline_script('wc-stn-ship-date-editor', 'window.wcStnShipDateEditor = ' . wp_json_encode($this->editor_data()) . ';', 'before');
        }

        register_block_type($dir, [
            'editor_script_handles' => ['wc-stn-ship-date-editor'],
            'editor_style_handles'  => ['wc-stn-checkout'],
        ]);
        // Same editor and frontend scripts: frontend.js registers both blocks
        register_block_type(plugin_dir_path(WC_STN_FILE) . 'assets/blocks/ship-date-row/', [
            'editor_script_handles' => ['wc-stn-ship-date-editor'],
        ]);
    }

    public function register_integration($registry)
    {
        require_once __DIR__ . '/class-wc-stn-blocks-integration.php';
        $registry->register(new WC_STN_Blocks_Integration($this));
    }

    /**
     * extensions.wc-stn fields of POST /wc/store/v1/checkout.
     */
    public function register_store_api_data()
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        woocommerce_store_api_register_endpoint_data([
            'endpoint'        => CheckoutSchema::IDENTIFIER,
            'namespace'       => self::API_NAMESPACE,
            'schema_callback' => function () {
                return [
                    'ship_date' => ['description' => 'Ship date (Y-m-d)', 'type' => ['string', 'null'], 'context' => ['view', 'edit']],
                    'rendered'  => ['description' => 'The ship date block was shown', 'type' => ['boolean', 'null'], 'context' => ['view', 'edit']],
                ];
            },
        ]);
    }

    /**
     * Data for the frontend block (wcSettings "wc-stn-ship-date_data"): slots for FRONTEND_DAYS days, like data-stn-checkout
     * in the classic checkout, so JS can add the next slots when a cutoff passes on an open page.
     */
    public function script_data()
    {
        $o        = $this->plugin->get_options();
        $schedule = $this->plugin->schedule();
        $tiles    = [];
        $rows     = [];

        foreach ($schedule->slots(40, $schedule->now(), WC_Ship_Today_Notice::FRONTEND_DAYS) as $slot) {
            $tiles[] = $this->checkout->tile_data($slot);
            $rows[$slot['ship']->format('Y-m-d')] = $this->checkout->review_lines($slot);
        }

        $data = [
            'label'     => $o['checkout_label'],
            'count'     => max(1, (int) $o['checkout_count']),
            'preselect' => !empty($o['checkout_preselect']),
            'accent'    => (string) $o['checkout_accent'],
            'tiles'     => $tiles,
            'nowOffset' => $this->plugin->now_offset() * 1000,
            'required'  => __('required', 'wc-ship-today-notice'),
            'choose'    => $this->checkout->choose_message(),
            'empty'     => __('No delivery dates available. Please contact us.', 'wc-ship-today-notice'),
            'closed'    => $this->checkout->switched_message(),
            // Summary row (ROW_BLOCK): "checkout_review_row" toggle, same content as the classic checkout
            'reviewRow' => !empty($o['checkout_review_row']),
            'rows'      => $rows,
        ];

        // Slots and summary rows for each method with its own hours (JS picks by the cart rate;
        // the same ship date can have another delivery date for a method).
        foreach ($this->plugin->methods_with_schedule() as $method) {
            $method_schedule = $this->plugin->schedule($method);
            $data['methods'][$method]    = [];
            $data['methodRows'][$method] = [];
            foreach ($method_schedule->slots(40, $method_schedule->now(), WC_Ship_Today_Notice::FRONTEND_DAYS) as $slot) {
                $data['methods'][$method][] = $this->checkout->tile_data($slot);
                $data['methodRows'][$method][$slot['ship']->format('Y-m-d')] = $this->checkout->review_lines($slot);
            }
            $data['moved'] = $this->checkout->message('checkout_msg_method');
        }

        return $data;
    }

    /**
     * Editor preview: label and a few slots from the current settings.
     */
    private function editor_data()
    {
        $data = $this->script_data();

        return [
            'label'  => $data['label'],
            'tiles'  => array_slice($data['tiles'], 0, $data['count']),
            'accent' => $data['accent'],
            'hint'   => __('Ship date tiles. The dates are calculated at checkout. Without this block the checkout has no ship date choice.', 'wc-ship-today-notice'),
            'row'    => $data['rows'] ? reset($data['rows']) : ['', ''],
            'rowHint' => $data['reviewRow']
                ? __('Shows the ship date selected in the "Ship date" block.', 'wc-ship-today-notice')
                : __('Hidden: the order summary row is turned off in WooCommerce → Settings → Delivery dates → Checkout.', 'wc-ship-today-notice'),
        ];
    }

    /**
     * Inserts the blocks into a block checkout page once. After inserting, or if a block is already there, the page is remembered
     * and not changed again, so a removed block does not come back.
     */
    public function maybe_insert_block()
    {
        $this->insert_block(true);
    }

    /**
     * On plugin update (WC_Ship_Today_Notice::maybe_upgrade): shops that had the select field get the block right away,
     * regardless of the current user's capabilities.
     */
    public function insert_on_upgrade()
    {
        $this->insert_block(false);
    }

    private function insert_block($check_cap)
    {
        if (empty($this->plugin->get_options()['checkout_enabled']) || !self::supported()) {
            return;
        }

        $page_id = function_exists('wc_get_page_id') ? (int) wc_get_page_id('checkout') : 0;
        if ($page_id <= 0 || ($check_cap && !current_user_can('edit_post', $page_id))) {
            return;
        }

        // Each block separately, once per checkout page: [block, marker, parent, insert after, otherwise before (null = at the end)]
        $placements = [
            [self::BLOCK, self::BLOCK_INSERTED_KEY, 'woocommerce/checkout-fields-block', 'woocommerce/checkout-shipping-methods-block', 'woocommerce/checkout-payment-block'],
            [self::ROW_BLOCK, self::ROW_INSERTED_KEY, 'woocommerce/checkout-order-summary-totals-block', 'woocommerce/checkout-order-summary-shipping-block', null],
        ];

        foreach ($placements as list($name, $key, $parent, $after, $before)) {
            if ($page_id === (int) get_option($key)) {
                continue;
            }

            $content = (string) get_post_field('post_content', $page_id);
            if (!has_block('woocommerce/checkout', $content)) {
                return; // classic checkout (shortcode) or checkout in a theme template
            }

            if (!has_block($name, $content)) {
                $blocks = parse_blocks($content);
                // Older layout (pages saved with earlier WooCommerce versions): total rows directly in the summary, without the totals block.
                // The WooCommerce editor wraps them in checkout-order-summary-totals-block when the page opens and drops unknown blocks
                // on the way, so do the same before inserting the row.
                if (self::ROW_BLOCK === $name && !has_block($parent, $content)) {
                    $this->wrap_legacy_totals($blocks);
                }
                if (!$this->insert_into($blocks, $name, $parent, $after, $before)) {
                    continue; // no marker: the settings status tells the shop to add it manually
                }
                wp_update_post(['ID' => $page_id, 'post_content' => wp_slash(serialize_blocks($blocks))]);
            }

            update_option($key, $page_id, false);
        }
    }

    /**
     * Older summary layout: wraps the total rows (subtotal, fee, discount, shipping, taxes) in
     * woocommerce/checkout-order-summary-totals-block at the position of the first one, like the WooCommerce editor migration.
     * Returns whether anything changed.
     */
    private function wrap_legacy_totals(array &$blocks)
    {
        $legacy = [
            'woocommerce/checkout-order-summary-subtotal-block',
            'woocommerce/checkout-order-summary-fee-block',
            'woocommerce/checkout-order-summary-discount-block',
            'woocommerce/checkout-order-summary-shipping-block',
            'woocommerce/checkout-order-summary-taxes-block',
        ];

        foreach ($blocks as &$block) {
            if ('woocommerce/checkout-order-summary-block' !== $block['blockName']) {
                if (!empty($block['innerBlocks']) && $this->wrap_legacy_totals($block['innerBlocks'])) {
                    return true;
                }
                continue;
            }

            $children = [];
            $totals   = [];
            $at       = null;
            foreach ($block['innerBlocks'] as $child) {
                if (in_array($child['blockName'], $legacy, true)) {
                    $at       = null === $at ? count($children) : $at;
                    $totals[] = $child;
                } else {
                    $children[] = $child;
                }
            }
            if (!$totals) {
                return false;
            }

            $open = '<div class="wp-block-woocommerce-checkout-order-summary-totals-block">';
            array_splice($children, $at, 0, [[
                'blockName'    => 'woocommerce/checkout-order-summary-totals-block',
                'attrs'        => [],
                'innerBlocks'  => $totals,
                'innerHTML'    => $open . '</div>',
                'innerContent' => self::inner_content($open, count($totals), '</div>'),
            ]]);

            // Parent content from scratch: opening and closing HTML from the original, blocks separated by a blank line
            $first = $block['innerContent'][0] ?? '';
            $last  = end($block['innerContent']);
            $block['innerBlocks']  = $children;
            $block['innerContent'] = self::inner_content(is_string($first) ? $first : '', count($children), is_string($last) ? $last : '');

            return true;
        }

        return false;
    }

    /** innerContent of a block with $count inner blocks: opening, null, "\n\n", null, ..., closing. */
    private static function inner_content($open, $count, $close)
    {
        $content = [$open];
        for ($i = 0; $i < $count; $i++) {
            if ($i > 0) {
                $content[] = "\n\n";
            }
            $content[] = null;
        }
        $content[] = $close;

        return $content;
    }

    /**
     * Inserts block $name into $parent after $after (or before $before, without $before at the end). Returns whether it did.
     */
    private function insert_into(array &$blocks, $name, $parent, $after, $before)
    {
        foreach ($blocks as &$block) {
            if ($parent !== $block['blockName']) {
                if (!empty($block['innerBlocks']) && $this->insert_into($block['innerBlocks'], $name, $parent, $after, $before)) {
                    return true;
                }
                continue;
            }

            $names = array_column($block['innerBlocks'], 'blockName');
            $found = array_search($after, $names, true);
            if (false !== $found) {
                $index = $found + 1;
            } else {
                $index = null === $before ? count($names) : array_search($before, $names, true);
            }
            if (false === $index) {
                return false;
            }

            $html = '<div data-block-name="' . $name . '" class="wp-block-' . str_replace('/', '-', $name) . '"></div>';
            array_splice($block['innerBlocks'], $index, 0, [[
                'blockName'    => $name,
                'attrs'        => [],
                'innerBlocks'  => [],
                'innerHTML'    => $html,
                'innerContent' => [$html],
            ]]);

            // innerContent has a null for each inner block; the new null goes at position $index
            $content = [];
            $seen    = 0;
            $added   = false;
            foreach ($block['innerContent'] as $chunk) {
                if (null === $chunk && !$added && $seen === $index) {
                    array_push($content, null, "\n\n");
                    $added = true;
                }
                $content[] = $chunk;
                if (null === $chunk) {
                    $seen++;
                }
            }
            if (!$added) {
                // after the last block: before the closing HTML of the parent
                $last = count($content) - 1;
                array_splice($content, is_string($content[$last]) ? $last : $last + 1, 0, ["\n\n", null]);
            }
            $block['innerContent'] = $content;

            return true;
        }

        return false;
    }

    /**
     * Admin notice (manage_woocommerce): block checkout without the ship date block, so no date choice.
     * Skipped on the Checkout section, which shows the same status.
     */
    public function missing_block_notice()
    {
        if (!current_user_can('manage_woocommerce') || 'missing' !== self::block_status()) {
            return;
        }

        $page_id = (int) wc_get_page_id('checkout');
        // phpcs:disable WordPress.Security.NonceVerification -- only picks the admin page, saves nothing.
        $on_checkout_tab = 'wc-settings' === sanitize_key(wp_unslash($_GET['page'] ?? '')) && WC_Ship_Today_Notice::SETTINGS_TAB === sanitize_title(wp_unslash($_GET['tab'] ?? '')) && 'checkout' === sanitize_key(wp_unslash($_GET['section'] ?? ''));
        // phpcs:enable
        if ($on_checkout_tab || $page_id === (int) get_user_meta(get_current_user_id(), self::NOTICE_DISMISSED_META, true)) {
            return;
        }

        $dismiss = wp_nonce_url(add_query_arg('wc_stn_dismiss_block_notice', 1), 'wc_stn_dismiss_block_notice');
        printf(
            '<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p><a class="button" href="%3$s">%4$s</a> <a href="%5$s">%6$s</a></p></div>',
            esc_html__('Ship Today & Delivery Dates:', 'wc-ship-today-notice'),
            esc_html__('the block checkout has no "Ship date" block, so customers cannot choose a ship date (orders are placed without it). Add the block inside the checkout form in the checkout page editor.', 'wc-ship-today-notice'),
            esc_url((string) get_edit_post_link($page_id)),
            esc_html__('Edit the checkout page', 'wc-ship-today-notice'),
            esc_url($dismiss),
            esc_html__('Dismiss', 'wc-ship-today-notice')
        );
    }

    public function maybe_dismiss_notice()
    {
        if (empty($_GET['wc_stn_dismiss_block_notice']) || !current_user_can('manage_woocommerce')) { // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked below.
            return;
        }
        check_admin_referer('wc_stn_dismiss_block_notice');
        update_user_meta(get_current_user_id(), self::NOTICE_DISMISSED_META, (int) wc_get_page_id('checkout'));
    }

    /**
     * Whether the checkout page has the block checkout and the given block in it (status in the panel).
     *
     * @return string 'classic', 'present' or 'missing'
     */
    public static function block_status($block = self::BLOCK)
    {
        $page_id = function_exists('wc_get_page_id') ? (int) wc_get_page_id('checkout') : 0;
        $content = $page_id > 0 ? (string) get_post_field('post_content', $page_id) : '';

        if (!has_block('woocommerce/checkout', $content)) {
            return 'classic';
        }

        return has_block($block, $content) ? 'present' : 'missing';
    }

    /**
     * Validation when the order is placed (Store API) and saving the same meta as the classic checkout.
     */
    public function validate_and_save($order, $request)
    {
        $data = (array) ($request['extensions'][self::API_NAMESPACE] ?? []);

        // Block not visible (no block, express payment, local pickup): no date required.
        if (empty($data['rendered']) || !WC()->cart || !WC()->cart->needs_shipping() || $this->checkout->only_local_pickup()) {
            return;
        }

        $ymd = sanitize_text_field((string) ($data['ship_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            throw new RouteException('wc_stn_ship_date_required', esc_html($this->checkout->choose_message()), 400);
        }

        $slot = $this->checkout->find_slot($ymd);
        if (!$slot) {
            throw new RouteException('wc_stn_slot_unavailable', esc_html($this->checkout->unavailable_message($ymd)), 400);
        }

        $order->update_meta_data(WC_STN_Checkout::META_SHIP, $slot['ship']->format('Y-m-d'));
        $order->update_meta_data(WC_STN_Checkout::META_DELIVERY, $slot['delivery'] ? $slot['delivery']->format('Y-m-d') : '');
        $order->update_meta_data(WC_STN_Checkout::META_CUTOFF, $slot['cutoff']->getTimestamp());
    }
}
