<?php
/**
 * "Ship date choice at checkout" module.
 *
 * Tiles with the next slots, validation when the order is placed, storage in the order (HPOS), and display in the order review,
 * emails, order admin and the orders list.
 *
 * Hooks are registered only with the module on. Exception: showing dates of existing orders (emails, read-only admin, list)
 * with the checkout_dates_when_disabled toggle. Each part of the display has its own toggle, see STDD_Plugin::TOGGLES.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class STDD_Checkout
{
    const FIELD        = 'stdd_ship_date';
    const RENDERED     = 'stdd_rendered';
    const META_SHIP    = '_stdd_ship_date';
    const META_DELIVERY = '_stdd_delivery_date';
    const META_CUTOFF  = '_stdd_cutoff';
    const COLUMN       = 'stdd_ship';
    const FILTER_PARAM = 'stdd_ship_filter';

    /** Markup of the classic checkout field, for wp_kses() when printing. */
    const ALLOWED_HTML = [
        'fieldset' => ['class' => true, 'id' => true, 'data-stdd-checkout' => true, 'style' => true],
        'legend'   => ['class' => true],
        'abbr'     => ['class' => true, 'title' => true],
        'div'      => ['class' => true],
        'label'    => ['class' => true],
        'input'    => ['type' => true, 'class' => true, 'name' => true, 'value' => true, 'data-cutoff' => true, 'aria-label' => true, 'checked' => true],
        'span'     => ['class' => true],
        'p'        => ['class' => true, 'role' => true, 'aria-live' => true, 'hidden' => true],
    ];

    /** Attributes of the #stdd-checkout-data script tag. */
    const DATA_ATTRS = ['type' => 'application/json', 'id' => 'stdd-checkout-data', 'class' => 'stdd-checkout__data'];

    /** @var STDD_Plugin */
    private $plugin;

    /** @var bool Date change field in the order admin (module on and toggle on). */
    private $admin_edit = false;

    public function __construct(STDD_Plugin $plugin)
    {
        $this->plugin = $plugin;
        $o = $plugin->get_options();

        add_shortcode('stdd_checkout', [$this, 'shortcode']);

        $enabled = !empty($o['checkout_enabled']);
        if ($enabled || !empty($o['checkout_dates_when_disabled'])) {
            $this->register_display_hooks($o);
        }

        if (!$enabled) {
            return;
        }

        if ('after_order_notes' === $o['checkout_position']) {
            add_action('woocommerce_after_order_notes', [$this, 'print_field']);
        } elseif ('before_order_review' === $o['checkout_position']) {
            add_action('woocommerce_checkout_before_order_review', [$this, 'print_field']);
        }
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        if (!empty($o['checkout_review_row'])) {
            add_action('woocommerce_review_order_after_shipping', [$this, 'review_row']);
        }
        add_action('woocommerce_after_checkout_validation', [$this, 'validate'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'save_to_order'], 10, 2);
        // update_checkout sends the slots of the newly selected method, JS rebuilds the tiles
        add_filter('woocommerce_update_order_review_fragments', [$this, 'checkout_fragments']);

        if (!empty($o['checkout_admin_edit'])) {
            $this->admin_edit = true;
            add_action('woocommerce_process_shop_order_meta', [$this, 'admin_order_save'], 20, 2);
        }
    }

    /**
     * Display of dates stored in orders: thank-you page, emails, My Account, order admin, orders list.
     */
    private function register_display_hooks(array $o)
    {
        if (!empty($o['checkout_order_rows'])) {
            add_filter('woocommerce_get_order_item_totals', [$this, 'order_item_totals'], 20, 2);
        }

        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'admin_order_box']);

        if (empty($o['checkout_list_column'])) {
            return;
        }

        // Orders list: HPOS and the legacy posts table
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'list_columns'], 20);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'list_column_content'], 20, 2);
        add_filter('manage_woocommerce_page_wc-orders_sortable_columns', [$this, 'list_sortable_columns']);
        add_filter('woocommerce_order_list_table_prepare_items_query_args', [$this, 'list_query_args']);
        add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'list_filter_dropdown'], 20, 2);
        add_filter('manage_edit-shop_order_columns', [$this, 'list_columns'], 20);
        add_action('manage_shop_order_posts_custom_column', [$this, 'list_column_content'], 20, 2);
        add_filter('manage_edit-shop_order_sortable_columns', [$this, 'list_sortable_columns']);
        add_filter('request', [$this, 'legacy_list_request']);
        add_action('restrict_manage_posts', [$this, 'legacy_filter_dropdown'], 20);
    }

    /* ---------------------------------------------------------
     * Checkout field
     * --------------------------------------------------------- */

    public function shortcode()
    {
        if (empty($this->plugin->get_options()['checkout_enabled']) || !function_exists('WC')) {
            return '';
        }

        $field = $this->field();

        return $field['html'] . (null !== $field['data'] ? wp_get_inline_script_tag($field['data'], self::DATA_ATTRS) : '') . $field['close'];
    }

    public function print_field()
    {
        $field = $this->field();

        echo wp_kses($field['html'], self::ALLOWED_HTML);
        if (null !== $field['data']) {
            wp_print_inline_script_tag($field['data'], self::DATA_ATTRS);
        }
        if ('' !== $field['close']) {
            echo '</fieldset>';
        }
    }

    /**
     * Tiles with the next slots and data for JS, which adds the next slots when a cutoff passes.
     * html: the field up to the data element, data: JSON for #stdd-checkout-data or null, close: the closing tag.
     *
     * @return array{html:string,data:?string,close:string}
     */
    private function field()
    {
        $o        = $this->plugin->get_options();
        $method   = $this->current_method();
        $schedule = $this->plugin->schedule($method);
        $now      = $schedule->now();
        $all      = $schedule->slots(40, $now, STDD_Plugin::FRONTEND_DAYS);
        $count    = max(1, (int) $o['checkout_count']);
        // Data element for JS (refreshed by update_checkout), only when some method has its own hours.
        // Without method schedules the field HTML stays the same.
        $per_method = (bool) $this->plugin->methods_with_schedule();

        // Validation requires a date only if the field was in the form (the shortcode can be missing or outside the form)
        $marker = '<input type="hidden" name="' . esc_attr(self::RENDERED) . '" value="1" />';

        if (!$all && !$per_method) {
            return [
                'html'  => '<p class="stdd-checkout__empty">' . esc_html__('No delivery dates available. Please contact us.', 'ship-today-delivery-dates') . '</p>' . $marker,
                'data'  => null,
                'close' => '',
            ];
        }

        $selected = $this->posted_ship_date();
        $values   = array_map(function ($slot) {
            return $slot['ship']->format('Y-m-d');
        }, $all);
        if (!in_array($selected, array_slice($values, 0, $count), true)) {
            $selected = !empty($o['checkout_preselect']) && $values ? $values[0] : '';
        }

        $tiles = [];
        foreach ($all as $slot) {
            $tiles[] = $this->tile_data($slot);
        }

        $config = [
            'count'     => $count,
            'tiles'     => $tiles,
            'nowOffset' => $this->plugin->now_offset() * 1000,
            'closed'    => $this->switched_message(),
        ];

        $style = '' !== $o['checkout_accent'] ? ' style="--stdd-accent:' . esc_attr($o['checkout_accent']) . '"' : '';

        $html  = '<fieldset class="stdd-checkout" id="stdd-checkout" data-stdd-checkout="' . esc_attr(wp_json_encode($config)) . '"' . $style . '>';
        $html .= '<legend class="stdd-checkout__legend">' . esc_html($o['checkout_label']) . ' <abbr class="required" title="' . esc_attr__('required', 'ship-today-delivery-dates') . '">*</abbr></legend>';
        $html .= '<div class="stdd-checkout__tiles">';
        foreach (array_slice($tiles, 0, $count) as $tile) {
            $html .= $this->tile_html($tile, $tile['value'] === $selected);
        }
        $html .= '</div>';
        $html .= '<p class="stdd-checkout__notice" role="status" aria-live="polite"' . ($all ? ' hidden>' : '>' . esc_html__('No delivery dates available. Please contact us.', 'ship-today-delivery-dates')) . '</p>';
        $html .= $marker;

        return [
            'html'  => $html,
            'data'  => $per_method ? $this->data_json($method, $tiles, $count) : null,
            'close' => '</fieldset>',
        ];
    }

    /**
     * Slots for a method as JSON in #stdd-checkout-data. The update_checkout fragment replaces this element,
     * checkout.js compares the method and rebuilds the tiles, keeping the selection if the date exists for the new method.
     */
    private function data_element($method, array $tiles, $count)
    {
        return wp_get_inline_script_tag($this->data_json($method, $tiles, $count), self::DATA_ATTRS);
    }

    private function data_json($method, array $tiles, $count)
    {
        $data = [
            'method'    => (string) $method,
            'count'     => $count,
            'tiles'     => $tiles,
            'nowOffset' => $this->plugin->now_offset() * 1000,
            'closed'    => $this->switched_message(),
            'moved'     => $this->message('checkout_msg_method'),
            'empty'     => __('No delivery dates available. Please contact us.', 'ship-today-delivery-dates'),
        ];

        return (string) wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP);
    }

    public function checkout_fragments($fragments)
    {
        if (!$this->plugin->methods_with_schedule()) {
            return $fragments;
        }

        $method   = $this->current_method();
        $schedule = $this->plugin->schedule($method);
        $tiles    = [];
        foreach ($schedule->slots(40, $schedule->now(), STDD_Plugin::FRONTEND_DAYS) as $slot) {
            $tiles[] = $this->tile_data($slot);
        }
        $fragments['#stdd-checkout-data'] = $this->data_element($method, $tiles, max(1, (int) $this->plugin->get_options()['checkout_count']));

        return $fragments;
    }

    /**
     * Shipping method selected in the cart or checkout (first package): "method_id:instance_id" or null.
     * Taken from the package rate, because rate IDs from plugins are not always "method:instance".
     */
    public function current_method()
    {
        if (!function_exists('WC') || !WC()->session) {
            return null;
        }

        $chosen  = (array) WC()->session->get('chosen_shipping_methods', []);
        $rate_id = (string) ($chosen[0] ?? '');
        if ('' === $rate_id) {
            return null;
        }

        foreach ((array) WC()->shipping()->get_packages() as $package) {
            $rate = $package['rates'][$rate_id] ?? null;
            if ($rate instanceof WC_Shipping_Rate) {
                return $rate->get_method_id() . ':' . $rate->get_instance_id();
            }
        }

        return STDD_Plugin::valid_method_key($rate_id) ? $rate_id : null;
    }

    /** Shipping method of an order (first shipping item) or null. */
    private function order_method(WC_Order $order)
    {
        foreach ($order->get_shipping_methods() as $item) {
            return $item->get_method_id() . ':' . $item->get_instance_id();
        }

        return null;
    }

    /**
     * Default tile texts as token templates (from translations), by "large text" setting and whether the slot has a delivery date.
     * The panel shows the same templates, so rendered they match the default tile exactly.
     */
    public static function default_tile_texts($primary, $has_delivery)
    {
        if ($has_delivery && 'delivery' === $primary) {
            /* translators: checkout tile, second line. %s: short weekday and date, e.g. "Mon 09.11" */
            return ['main' => '{delivery_short}', 'sub' => sprintf(_x('ship %s', 'checkout tile', 'ship-today-delivery-dates'), '{ship_short}')];
        }

        return [
            'main' => '{ship_short}',
            'sub'  => $has_delivery
                /* translators: checkout tile, order review and orders list. %s: short weekday and date, e.g. "Tue 10.11" */
                ? sprintf(_x('delivery %s', 'short date line', 'ship-today-delivery-dates'), '{delivery_short}')
                : _x('ship', 'checkout tile without a delivery date', 'ship-today-delivery-dates'),
        ];
    }

    public function tile_data(array $slot)
    {
        $o        = $this->plugin->get_options();
        $schedule = $this->plugin->schedule();
        $ship     = $slot['ship'];
        $delivery = $slot['delivery'];
        $name     = function (DateTimeImmutable $d) {
            return STDD_Schedule::day_label((int) $d->format('N'), 'name') . ' ' . $d->format('d.m');
        };

        // Custom tile texts (tokens as in the widget plus {ship_short}, {delivery_short}) or defaults.
        // "today/tomorrow" in {*_when} is computed by PHP at render time: the checkout page is not cached.
        $labels   = $schedule->slot_labels($slot) + [
            'ship_short'     => $this->date_short($ship),
            'delivery_short' => $delivery ? $this->date_short($delivery) : '',
        ];
        $defaults = self::default_tile_texts($o['checkout_primary'], (bool) $delivery);
        $main_tpl = '' !== (string) $o['checkout_tile_main'] ? $this->plugin->t('checkout_tile_main', $o['checkout_tile_main']) : $defaults['main'];
        // Second line: null = default, '' = hidden
        $sub_tpl  = null === $o['checkout_tile_sub'] ? $defaults['sub'] : (string) $o['checkout_tile_sub'];
        if ('' !== $sub_tpl && null !== $o['checkout_tile_sub']) {
            $sub_tpl = $this->plugin->t('checkout_tile_sub', $sub_tpl);
        }

        $main = trim($schedule->render_text($main_tpl, $labels));
        $sub  = '' === $sub_tpl ? '' : trim($schedule->render_text($sub_tpl, $labels));

        /* translators: checkout tile for screen readers. %s: weekday and date, e.g. "Monday 09.11" */
        $aria = sprintf(__('Ship %s', 'ship-today-delivery-dates'), $name($ship));
        if ($delivery) {
            /* translators: checkout tile for screen readers, after the ship date. %s: weekday and date */
            $aria .= ', ' . sprintf(__('delivery %s', 'ship-today-delivery-dates'), $name($delivery));
        }
        /* translators: checkout tile for screen readers. %1$s: weekday (until form), %2$s: time, e.g. "orders until Sunday 20:00" */
        $aria .= ', ' . sprintf(__('orders until %1$s %2$s', 'ship-today-delivery-dates'), STDD_Schedule::day_label((int) $slot['cutoff']->format('N'), 'until'), $slot['cutoff']->format('H:i'));

        return [
            'value'  => $ship->format('Y-m-d'),
            'cutoff' => $slot['cutoff']->getTimestamp(),
            'main'   => $main,
            'sub'    => $sub,
            'aria'   => $aria,
            // Slot in messages ({slot}, {next_slot}): the default large text, regardless of custom tile texts
            'label'  => $schedule->render_text($defaults['main'], $labels),
        ];
    }

    private function tile_html(array $tile, $checked)
    {
        return sprintf(
            '<label class="stdd-tile"><input type="radio" class="stdd-tile__input" name="%1$s" value="%2$s" data-cutoff="%3$d" aria-label="%4$s"%5$s /><span class="stdd-tile__main">%6$s</span>%7$s</label>',
            esc_attr(self::FIELD),
            esc_attr($tile['value']),
            (int) $tile['cutoff'],
            esc_attr($tile['aria']),
            $checked ? ' checked' : '',
            esc_html($tile['main']),
            '' !== $tile['sub'] ? '<span class="stdd-tile__sub">' . esc_html($tile['sub']) . '</span>' : ''
        );
    }

    public function enqueue_assets()
    {
        // The block checkout has its own field
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page() || has_block('woocommerce/checkout')) {
            return;
        }

        wp_enqueue_style('stdd-checkout', plugins_url('assets/css/checkout.css', STDD_FILE), [], STDD_VERSION);
        wp_enqueue_script('stdd-checkout', plugins_url('assets/js/checkout.js', STDD_FILE), ['jquery'], STDD_VERSION, true);
    }

    /**
     * Date from the form: $_POST when placing the order, post_data when refreshing the review.
     */
    private function posted_ship_date()
    {
        // phpcs:disable WordPress.Security.NonceVerification -- WooCommerce verifies the checkout nonce.
        $value = '';

        if (isset($_POST[self::FIELD])) {
            $value = sanitize_text_field(wp_unslash($_POST[self::FIELD]));
        } elseif (isset($_POST['post_data'])) {
            // Our key and its Y-m-d value are never percent-encoded, so sanitizing the whole string keeps them intact.
            parse_str(sanitize_text_field(wp_unslash($_POST['post_data'])), $data);
            $value = $data[self::FIELD] ?? '';
        }
        // phpcs:enable

        $value = sanitize_text_field((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    /**
     * Slot for a ship date, as long as its cutoff has not passed.
     */
    public function find_slot($ship_ymd, ?DateTimeImmutable $now = null, $method = null)
    {
        // By default the method selected at checkout (validation, saving, review row)
        $method = null === $method ? $this->current_method() : $method;

        foreach ($this->plugin->schedule($method)->slots(40, $now) as $slot) {
            if ($slot['ship']->format('Y-m-d') === $ship_ymd) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Row in the order review table, refreshed with it by update_checkout.
     */
    public function review_row()
    {
        $o   = $this->plugin->get_options();
        $ymd = $this->posted_ship_date();

        if ('' === $ymd && !empty($o['checkout_preselect'])) {
            $first = $this->plugin->schedule($this->current_method())->slots(1);
            $ymd   = $first ? $first[0]['ship']->format('Y-m-d') : '';
        }

        $slot = '' !== $ymd ? $this->find_slot($ymd) : null;
        if (!$slot) {
            return;
        }

        $text = implode('<br>', array_map('esc_html', array_filter($this->review_lines($slot))));

        printf(
            '<tr class="stdd-review"><th>%s</th><td data-title="%s">%s</td></tr>',
            esc_html($o['checkout_label']),
            esc_attr($o['checkout_label']),
            wp_kses($text, ['br' => []])
        );
    }

    /**
     * Order summary row lines: [ship, delivery or '']. Shared by the classic and block checkout.
     */
    public function review_lines(array $slot)
    {
        return [
            /* translators: order review row. %s: short weekday and date, e.g. "Mon 09.11" */
            sprintf(_x('Ship %s', 'order review row', 'ship-today-delivery-dates'), $this->date_short($slot['ship'])),
            $slot['delivery'] ? $this->delivery_short($slot['delivery']) : '',
        ];
    }

    /**
     * Whether all selected shipping methods are local pickup (no date needed then).
     */
    public function only_local_pickup()
    {
        $ids = function_exists('wc_get_chosen_shipping_method_ids') ? wc_get_chosen_shipping_method_ids() : [];
        if (!$ids) {
            return false;
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce filter.
        $pickup = (array) apply_filters('woocommerce_local_pickup_methods', ['legacy_local_pickup', 'local_pickup', 'pickup_location']);

        return !array_diff($ids, $pickup);
    }

    public function validate($data, $errors)
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verifies the checkout nonce.
        if (empty($_POST[self::RENDERED]) || !WC()->cart || !WC()->cart->needs_shipping() || $this->only_local_pickup()) {
            return;
        }

        $ymd = $this->posted_ship_date();

        if ('' === $ymd) {
            $errors->add(self::FIELD, $this->choose_message(true));
            return;
        }

        if (!$this->find_slot($ymd)) {
            $errors->add(self::FIELD, esc_html($this->unavailable_message($ymd)));
        }
    }

    /**
     * "Choose delivery date." message, shared by the classic and block checkout.
     */
    public function choose_message($html = false)
    {
        $o     = $this->plugin->get_options();
        $label = $o['checkout_label'];
        // Polish lowercases the field label. Translating this as "no" keeps it as entered (e.g. German nouns).
        $lower = 'yes' === _x('yes', 'Lowercase the field label inside "Choose %s."? Translate as "no" to keep it as entered.', 'ship-today-delivery-dates')
            ? (function_exists('mb_strtolower') ? mb_strtolower($label) : strtolower($label))
            : $label;

        // Custom message from settings: {field} = field label (bold in HTML, like the default)
        if ('' !== (string) $o['checkout_msg_choose']) {
            $text = $this->plugin->t('checkout_msg_choose', $o['checkout_msg_choose']);
            return $html
                ? str_replace('{field}', '<strong>' . esc_html($lower) . '</strong>', esc_html($text))
                : str_replace('{field}', $lower, $text);
        }

        if ($html) {
            /* translators: checkout error. %s: field label, e.g. "delivery date" */
            return sprintf(esc_html__('Choose %s.', 'ship-today-delivery-dates'), '<strong>' . esc_html($lower) . '</strong>');
        }

        /* translators: checkout error. %s: field label, e.g. "delivery date" */
        return sprintf(__('Choose %s.', 'ship-today-delivery-dates'), $lower);
    }

    /**
     * Default checkout messages with tokens instead of %s (panel placeholders, value for an empty setting).
     */
    public static function default_messages()
    {
        return [
            /* translators: checkout error. %s: field label, e.g. "delivery date" */
            'checkout_msg_choose'      => str_replace('%s', '{field}', __('Choose %s.', 'ship-today-delivery-dates')),
            /* translators: %s: shipping date, e.g. "pn 26.10" */
            'checkout_msg_closed'      => str_replace('%s', '{slot}', __('Orders for shipping on %s are no longer accepted. Please choose another date.', 'ship-today-delivery-dates')),
            /* translators: %s: shipping date, e.g. "pn 26.10" */
            'checkout_msg_unavailable' => str_replace('%s', '{slot}', __('Shipping on %s is not available. Please choose another date.', 'ship-today-delivery-dates')),
            /* translators: checkout notice when the selected date closes on an open page. %1$s: closed date, %2$s: newly selected date */
            'checkout_msg_switched'    => str_replace(['%1$s', '%2$s'], ['{slot}', '{next_slot}'], __('The %1$s date has just closed, we selected %2$s.', 'ship-today-delivery-dates')),
            'checkout_msg_method'      => __('The {slot} date is not available for this shipping method, we selected {next_slot}.', 'ship-today-delivery-dates'),
        ];
    }

    /** Text from settings or the default, with tokens. */
    public function message($key)
    {
        $o = $this->plugin->get_options();

        return '' !== (string) $o[$key] ? $this->plugin->t($key, $o[$key]) : self::default_messages()[$key];
    }

    /**
     * JS message when the selected slot closes on an open page. JS fills {slot} and {next_slot} from tile.label.
     */
    public function switched_message()
    {
        return $this->message('checkout_msg_switched');
    }

    /**
     * Message for a ship date outside the current slots (cutoff passed or not a ship day).
     */
    public function unavailable_message($ymd)
    {
        $schedule = $this->plugin->schedule($this->current_method());
        $date     = $schedule->date($ymd);
        $closed   = $schedule->is_shipping_day($date) && $schedule->cutoff_for($date) <= $schedule->now();

        return str_replace('{slot}', $this->date_short($date), $this->message($closed ? 'checkout_msg_closed' : 'checkout_msg_unavailable'));
    }

    public function save_to_order($order, $data)
    {
        $slot = $this->find_slot($this->posted_ship_date());

        if (!$slot) {
            return;
        }

        $order->update_meta_data(self::META_SHIP, $slot['ship']->format('Y-m-d'));
        $order->update_meta_data(self::META_DELIVERY, $slot['delivery'] ? $slot['delivery']->format('Y-m-d') : '');
        $order->update_meta_data(self::META_CUTOFF, $slot['cutoff']->getTimestamp());
    }

    /* ---------------------------------------------------------
     * After the order is placed
     * --------------------------------------------------------- */

    /**
     * Thank-you page, emails (customer and admin), My Account.
     */
    public function order_item_totals($rows, $order)
    {
        if (!$order instanceof WC_Order) {
            return $rows;
        }

        $ship = $order->get_meta(self::META_SHIP);
        if (!$ship) {
            return $rows;
        }

        $o        = $this->plugin->get_options();
        $schedule = $this->plugin->schedule();
        // Labels from settings, empty = translated default
        $label    = function ($key, $default) use ($o) {
            return '' !== (string) $o[$key] ? $this->plugin->t($key, $o[$key]) : $default;
        };
        $add      = ['stdd_ship' => ['label' => $label('checkout_row_ship_label', __('Ship date:', 'ship-today-delivery-dates')), 'value' => $this->date_long($schedule->date($ship))]];

        $delivery = $order->get_meta(self::META_DELIVERY);
        if ($delivery) {
            $add['stdd_delivery'] = ['label' => $label('checkout_row_delivery_label', __('Planned delivery:', 'ship-today-delivery-dates')), 'value' => $this->date_long($schedule->date($delivery))];
        }

        $result = [];
        $added  = false;
        foreach ($rows as $key => $row) {
            if (!$added && 'order_total' === $key) {
                $result += $add;
                $added   = true;
            }
            $result[$key] = $row;
            if (!$added && 'shipping' === $key) {
                $result += $add;
                $added   = true;
            }
        }

        return $added ? $result : $rows + $add;
    }

    public function admin_order_box($order)
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        $schedule = $this->plugin->schedule();
        $ship     = $order->get_meta(self::META_SHIP);
        $delivery = $order->get_meta(self::META_DELIVERY);
        $edit     = $this->admin_edit && ($order->is_editable() || current_user_can('edit_shop_orders'));

        // No dates and no change field (read-only): nothing to show
        if (!$ship && !$edit) {
            return;
        }

        echo '<div class="stdd-admin-box" style="clear:both;padding-top:8px">';
        echo '<h3>' . esc_html__('Shipping date', 'ship-today-delivery-dates') . '</h3>';

        if ($ship) {
            echo '<p><strong>' . esc_html__('Ship:', 'ship-today-delivery-dates') . '</strong> ' . esc_html($this->date_long($schedule->date($ship)));
            if ($delivery) {
                echo '<br><strong>' . esc_html__('Delivery:', 'ship-today-delivery-dates') . '</strong> ' . esc_html($this->date_long($schedule->date($delivery)));
            }
            echo '</p>';
        }

        if ($edit) {
            printf(
                '<p><label for="stdd_admin_ship_date">%s</label><br><input type="date" id="stdd_admin_ship_date" name="stdd_admin_ship_date" value="%s" /></p>',
                esc_html__('Change ship date:', 'ship-today-delivery-dates'),
                esc_attr($ship)
            );
            wp_nonce_field('stdd_admin_ship_date', 'stdd_admin_nonce');
        }

        echo '</div>';
    }

    public function admin_order_save($order_id, $order = null)
    {
        if (
            !isset($_POST['stdd_admin_nonce'], $_POST['stdd_admin_ship_date']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['stdd_admin_nonce'])), 'stdd_admin_ship_date') ||
            !current_user_can('edit_shop_orders')
        ) {
            return;
        }

        $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $new = sanitize_text_field(wp_unslash($_POST['stdd_admin_ship_date']));
        $old = (string) $order->get_meta(self::META_SHIP);

        if ($new === $old) {
            return;
        }

        if ('' !== $new && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $new, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
            if (class_exists('WC_Admin_Meta_Boxes')) {
                /* translators: %s: date entered in the order edit screen */
                WC_Admin_Meta_Boxes::add_error(sprintf(__('Invalid ship date "%s". The ship date was not changed.', 'ship-today-delivery-dates'), $new));
            }
            return;
        }

        $user = wp_get_current_user()->display_name;

        if ('' === $new) {
            $order->delete_meta_data(self::META_SHIP);
            $order->delete_meta_data(self::META_DELIVERY);
            $order->delete_meta_data(self::META_CUTOFF);
            /* translators: order note. %1$s: removed ship date (Y-m-d), %2$s: user name */
            $order->add_order_note(sprintf(__('Ship date %1$s removed (%2$s).', 'ship-today-delivery-dates'), $old, $user));
        } else {
            // Delivery by the order's shipping method (a method can have its own delivery days)
            $schedule = $this->plugin->schedule($this->order_method($order));
            $delivery = $schedule->delivery_for($schedule->date($new));
            $order->update_meta_data(self::META_SHIP, $new);
            $order->update_meta_data(self::META_DELIVERY, $delivery ? $delivery->format('Y-m-d') : '');
            $order->delete_meta_data(self::META_CUTOFF);
            /* translators: order note. %1$s: previous ship date (Y-m-d) or "none", %2$s: new ship date, %3$s: user name */
            $order->add_order_note(sprintf(__('Ship date changed from %1$s to %2$s (%3$s).', 'ship-today-delivery-dates'), $old ?: _x('none', 'no previous ship date', 'ship-today-delivery-dates'), $new, $user));
        }

        $order->save();
    }

    /* ---------------------------------------------------------
     * Orders list
     * --------------------------------------------------------- */

    public function list_columns($columns)
    {
        $result = [];
        foreach ($columns as $key => $label) {
            $result[$key] = $label;
            if ('order_date' === $key) {
                $result[self::COLUMN] = _x('Shipping', 'column with the ship date', 'ship-today-delivery-dates');
            }
        }
        if (!isset($result[self::COLUMN])) {
            $result[self::COLUMN] = _x('Shipping', 'column with the ship date', 'ship-today-delivery-dates');
        }

        return $result;
    }

    public function list_column_content($column, $order)
    {
        if (self::COLUMN !== $column) {
            return;
        }

        $order = $order instanceof WC_Order ? $order : wc_get_order($order);
        if (!$order) {
            return;
        }

        $schedule = $this->plugin->schedule();
        $ship     = $order->get_meta(self::META_SHIP);

        if (!$ship) {
            return;
        }

        echo esc_html($this->date_short($schedule->date($ship)));
        $delivery = $order->get_meta(self::META_DELIVERY);
        if ($delivery) {
            echo '<br><small>' . esc_html($this->delivery_short($schedule->date($delivery))) . '</small>';
        }
    }

    public function list_sortable_columns($columns)
    {
        $columns[self::COLUMN] = self::COLUMN;
        return $columns;
    }

    /**
     * HPOS: sorting by ship date (named meta_query clause) and filter by day.
     */
    public function list_query_args($args)
    {
        // phpcs:disable WordPress.Security.NonceVerification -- admin list params, read-only.
        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : '';
        $filter  = isset($_GET[self::FILTER_PARAM]) ? sanitize_text_field(wp_unslash($_GET[self::FILTER_PARAM])) : '';
        // phpcs:enable

        $meta_query = isset($args['meta_query']) && is_array($args['meta_query']) ? $args['meta_query'] : [];

        if (self::COLUMN === $orderby) {
            $meta_query[self::COLUMN] = ['key' => self::META_SHIP, 'compare' => 'EXISTS'];
            $args['orderby']          = self::COLUMN;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter)) {
            $meta_query[] = ['key' => self::META_SHIP, 'value' => $filter, 'compare' => '='];
        }

        if ($meta_query) {
            $args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery -- orders list filter, admin only.
        }

        return $args;
    }

    public function list_filter_dropdown($order_type = 'shop_order', $which = 'top')
    {
        if ('shop_order' !== $order_type || 'top' !== $which) {
            return;
        }

        $this->print_filter_dropdown();
    }

    public function legacy_filter_dropdown($post_type)
    {
        if ('shop_order' === $post_type) {
            $this->print_filter_dropdown();
        }
    }

    private function print_filter_dropdown()
    {
        $schedule = $this->plugin->schedule();
        $current  = isset($_GET[self::FILTER_PARAM]) ? sanitize_text_field(wp_unslash($_GET[self::FILTER_PARAM])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $from     = $schedule->now()->modify('-14 days');

        echo '<select name="' . esc_attr(self::FILTER_PARAM) . '"><option value="">' . esc_html__('Shipping: all', 'ship-today-delivery-dates') . '</option>';
        foreach ($schedule->shipping_dates($from, 45) as $ymd) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($ymd),
                selected($current, $ymd, false),
                /* translators: orders list filter. %s: short weekday and date, e.g. "Mon 09.11.2026" */
                esc_html(sprintf(__('Shipping: %s', 'ship-today-delivery-dates'), $this->date_short($schedule->date($ymd)) . '.' . substr($ymd, 0, 4)))
            );
        }
        echo '</select>';
    }

    /**
     * Legacy posts table (shops without HPOS).
     */
    public function legacy_list_request($vars)
    {
        if (!is_admin() || ($vars['post_type'] ?? '') !== 'shop_order') {
            return $vars;
        }

        if (($vars['orderby'] ?? '') === self::COLUMN) {
            $vars['meta_key'] = self::META_SHIP; // phpcs:ignore WordPress.DB.SlowDBQuery
            $vars['orderby']  = 'meta_value';
        }

        $filter = isset($_GET[self::FILTER_PARAM]) ? sanitize_text_field(wp_unslash($_GET[self::FILTER_PARAM])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter)) {
            $vars['meta_query'][] = ['key' => self::META_SHIP, 'value' => $filter]; // phpcs:ignore WordPress.DB.SlowDBQuery
        }

        return $vars;
    }

    /* ---------------------------------------------------------
     * Date formatting
     * --------------------------------------------------------- */

    private function date_short(DateTimeImmutable $d)
    {
        return STDD_Schedule::day_label((int) $d->format('N'), 'short') . ' ' . $d->format('d.m');
    }

    private function date_long(DateTimeImmutable $d)
    {
        return STDD_Schedule::day_label((int) $d->format('N'), 'name') . ', ' . $d->format('d.m.Y');
    }

    /** Delivery line: tile, order review, orders list. */
    private function delivery_short(DateTimeImmutable $d)
    {
        /* translators: checkout tile, order review and orders list. %s: short weekday and date, e.g. "Tue 10.11" */
        return sprintf(_x('delivery %s', 'short date line', 'ship-today-delivery-dates'), $this->date_short($d));
    }
}
