<?php
/**
 * Moduł "Wybór terminu w checkoucie".
 *
 * Kafelki z najbliższymi terminami z silnika, walidacja w chwili złożenia zamówienia,
 * zapis w zamówieniu (HPOS), wyświetlanie w podsumowaniu, mailach, panelu zamówienia i na liście zamówień.
 * Opcjonalnie wyłącza Iconic Delivery Slots w koszyku i checkoucie (okres przejściowy).
 *
 * Hooki rejestruje tylko, gdy moduł jest włączony w ustawieniach.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WC_STN_Checkout
{
    const FIELD        = 'wc_stn_ship_date';
    const META_SHIP    = '_wc_stn_ship_date';
    const META_DELIVERY = '_wc_stn_delivery_date';
    const META_CUTOFF  = '_wc_stn_cutoff';
    const COLUMN       = 'wc_stn_ship';
    const FILTER_PARAM = 'wc_stn_ship_filter';

    /** @var WC_Ship_Today_Notice */
    private $plugin;

    public function __construct(WC_Ship_Today_Notice $plugin)
    {
        $this->plugin = $plugin;
        $o = $plugin->get_options();

        add_shortcode('ship_today_checkout', [$this, 'shortcode']);

        if (empty($o['checkout_enabled'])) {
            return;
        }

        // Checkout
        if ('after_order_notes' === $o['checkout_position']) {
            add_action('woocommerce_after_order_notes', [$this, 'print_field']);
        } elseif ('before_order_review' === $o['checkout_position']) {
            add_action('woocommerce_checkout_before_order_review', [$this, 'print_field']);
        }
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('woocommerce_review_order_after_shipping', [$this, 'review_row']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'save_to_order'], 10, 2);

        // Po złożeniu zamówienia: thank you, maile, Moje konto
        add_filter('woocommerce_get_order_item_totals', [$this, 'order_item_totals'], 20, 2);

        // Panel zamówienia
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'admin_order_box']);
        add_action('woocommerce_process_shop_order_meta', [$this, 'admin_order_save'], 20, 2);

        // Lista zamówień: HPOS i stara tabela postów
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

        // Okres przejściowy: Iconic aktywny, ale nie działa w koszyku i checkoucie
        if (!empty($o['disable_iconic'])) {
            add_action('wp_loaded', [$this, 'disable_iconic_checkout'], 99);
            add_action('wp_enqueue_scripts', [$this, 'dequeue_iconic_assets'], 999);
        }
    }

    public static function iconic_active()
    {
        return class_exists('\Iconic_WDS\Checkout');
    }

    /* ---------------------------------------------------------
     * Checkout: pole
     * --------------------------------------------------------- */

    public function shortcode()
    {
        if (empty($this->plugin->get_options()['checkout_enabled']) || !function_exists('WC')) {
            return '';
        }

        return $this->render_field();
    }

    public function print_field()
    {
        echo $this->render_field(); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Kafelki z najbliższymi terminami + dane dla JS (dokładanie kolejnych terminów, gdy godzina graniczna minie).
     */
    private function render_field()
    {
        $o        = $this->plugin->get_options();
        $schedule = $this->plugin->schedule();
        $now      = $schedule->now();
        $all      = $schedule->slots(40, $now, WC_Ship_Today_Notice::FRONTEND_DAYS);
        $count    = max(1, (int) $o['checkout_count']);

        if (!$all) {
            return '<p class="wc-stn-checkout__empty">Brak dostępnych terminów dostawy. Skontaktuj się z nami.</p>';
        }

        $selected = $this->posted_ship_date();
        $values   = array_map(function ($slot) {
            return $slot['ship']->format('Y-m-d');
        }, $all);
        if (!in_array($selected, array_slice($values, 0, $count), true)) {
            $selected = !empty($o['checkout_preselect']) ? $values[0] : '';
        }

        $tiles = [];
        foreach ($all as $slot) {
            $tiles[] = $this->tile_data($slot);
        }

        $config = [
            'count'     => $count,
            'tiles'     => $tiles,
            'nowOffset' => $this->plugin->now_offset() * 1000,
            'closed'    => 'Termin %1$s właśnie się zamknął, zaznaczyliśmy %2$s.',
        ];

        $style = '' !== $o['checkout_accent'] ? ' style="--wc-stn-accent:' . esc_attr($o['checkout_accent']) . '"' : '';

        $html  = '<fieldset class="wc-stn-checkout" id="wc-stn-checkout" data-stn-checkout="' . esc_attr(wp_json_encode($config)) . '"' . $style . '>';
        $html .= '<legend class="wc-stn-checkout__legend">' . esc_html($o['checkout_label']) . ' <abbr class="required" title="wymagane">*</abbr></legend>';
        $html .= '<div class="wc-stn-checkout__tiles">';
        foreach (array_slice($tiles, 0, $count) as $tile) {
            $html .= $this->tile_html($tile, $tile['value'] === $selected);
        }
        $html .= '</div>';
        $html .= '<p class="wc-stn-checkout__notice" role="status" aria-live="polite" hidden></p>';
        $html .= '</fieldset>';

        return $html;
    }

    private function tile_data(array $slot)
    {
        $o        = $this->plugin->get_options();
        $ship     = $slot['ship'];
        $delivery = $slot['delivery'];
        $short    = function (DateTimeImmutable $d) {
            return WC_STN_Schedule::DAY_SHORT[(int) $d->format('N')] . ' ' . $d->format('d.m');
        };

        if ($delivery && 'delivery' === $o['checkout_primary']) {
            $main = $short($delivery);
            $sub  = 'wysyłka ' . $short($ship);
        } else {
            $main = $short($ship);
            $sub  = $delivery ? 'dostawa ' . $short($delivery) : 'wysyłka';
        }

        $aria = 'Wysyłka ' . WC_STN_Schedule::DAY_NOMINATIVE[(int) $ship->format('N')] . ' ' . $ship->format('d.m');
        if ($delivery) {
            $aria .= ', dostawa ' . WC_STN_Schedule::DAY_NOMINATIVE[(int) $delivery->format('N')] . ' ' . $delivery->format('d.m');
        }
        $aria .= ', zamówienia do ' . WC_STN_Schedule::DAY_GENITIVE[(int) $slot['cutoff']->format('N')] . ' ' . $slot['cutoff']->format('H:i');

        return [
            'value'  => $ship->format('Y-m-d'),
            'cutoff' => $slot['cutoff']->getTimestamp(),
            'main'   => $main,
            'sub'    => $sub,
            'aria'   => $aria,
        ];
    }

    private function tile_html(array $tile, $checked)
    {
        return sprintf(
            '<label class="wc-stn-tile"><input type="radio" class="wc-stn-tile__input" name="%1$s" value="%2$s" data-cutoff="%3$d" aria-label="%4$s"%5$s /><span class="wc-stn-tile__main">%6$s</span><span class="wc-stn-tile__sub">%7$s</span></label>',
            esc_attr(self::FIELD),
            esc_attr($tile['value']),
            (int) $tile['cutoff'],
            esc_attr($tile['aria']),
            $checked ? ' checked' : '',
            esc_html($tile['main']),
            esc_html($tile['sub'])
        );
    }

    public function enqueue_assets()
    {
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
            return;
        }

        wp_enqueue_style('wc-stn-checkout', plugins_url('assets/css/checkout.css', WC_STN_FILE), [], WC_STN_VERSION);
        wp_enqueue_script('wc-stn-checkout', plugins_url('assets/js/checkout.js', WC_STN_FILE), ['jquery'], WC_STN_VERSION, true);
    }

    /**
     * Data z formularza: przy składaniu zamówienia z $_POST, przy odświeżaniu podsumowania z post_data.
     */
    private function posted_ship_date()
    {
        // phpcs:disable WordPress.Security.NonceVerification -- WooCommerce weryfikuje nonce checkoutu.
        $value = '';

        if (isset($_POST[self::FIELD])) {
            $value = wp_unslash($_POST[self::FIELD]);
        } elseif (isset($_POST['post_data'])) {
            parse_str(wp_unslash($_POST['post_data']), $data);
            $value = $data[self::FIELD] ?? '';
        }
        // phpcs:enable

        $value = sanitize_text_field((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    /**
     * Termin (z silnika) dla daty wysyłki, o ile jego godzina graniczna jeszcze nie minęła.
     */
    public function find_slot($ship_ymd, ?DateTimeImmutable $now = null)
    {
        foreach ($this->plugin->schedule()->slots(40, $now) as $slot) {
            if ($slot['ship']->format('Y-m-d') === $ship_ymd) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Wiersz w tabeli podsumowania zamówienia (odświeżany razem z nią przez update_checkout).
     */
    public function review_row()
    {
        $o   = $this->plugin->get_options();
        $ymd = $this->posted_ship_date();

        if ('' === $ymd && !empty($o['checkout_preselect'])) {
            $first = $this->plugin->schedule()->slots(1);
            $ymd   = $first ? $first[0]['ship']->format('Y-m-d') : '';
        }

        $slot = '' !== $ymd ? $this->find_slot($ymd) : null;
        if (!$slot) {
            return;
        }

        $text = 'Wysyłka ' . $this->date_short($slot['ship']);
        if ($slot['delivery']) {
            $text .= '<br>dostawa ' . $this->date_short($slot['delivery']);
        }

        printf(
            '<tr class="wc-stn-review"><th>%s</th><td data-title="%s">%s</td></tr>',
            esc_html($o['checkout_label']),
            esc_attr($o['checkout_label']),
            wp_kses($text, ['br' => []])
        );
    }

    public function validate($data, $errors)
    {
        if (!WC()->cart || !WC()->cart->needs_shipping()) {
            return;
        }

        $label = $this->plugin->get_options()['checkout_label'];
        $ymd   = $this->posted_ship_date();

        if ('' === $ymd) {
            $lower = function_exists('mb_strtolower') ? mb_strtolower($label) : strtolower($label);
            $errors->add(self::FIELD, sprintf('Wybierz <strong>%s</strong>.', esc_html($lower)));
            return;
        }

        if (!$this->find_slot($ymd)) {
            $schedule = $this->plugin->schedule();
            $date     = $schedule->date($ymd);
            $closed   = $schedule->is_shipping_day($date) && $schedule->cutoff_for($date) <= $schedule->now();
            $message  = $closed
                ? 'Przyjmowanie zamówień na wysyłkę %s już się zakończyło. Wybierz inny termin.'
                : 'Termin z wysyłką %s jest niedostępny. Wybierz inny termin.';
            $errors->add(self::FIELD, sprintf($message, esc_html($this->date_short($date))));
        }
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
     * Po złożeniu zamówienia
     * --------------------------------------------------------- */

    /**
     * Thank you page, maile (klient i admin), Moje konto.
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

        $schedule = $this->plugin->schedule();
        $add      = ['wc_stn_ship' => ['label' => 'Data wysyłki:', 'value' => $this->date_long($schedule->date($ship))]];

        $delivery = $order->get_meta(self::META_DELIVERY);
        if ($delivery) {
            $add['wc_stn_delivery'] = ['label' => 'Planowana dostawa:', 'value' => $this->date_long($schedule->date($delivery))];
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
        $iconic   = $order->get_meta('jckwds_date');

        echo '<div class="wc-stn-admin-box" style="clear:both;padding-top:8px">';
        echo '<h3>Termin wysyłki</h3>';

        if ($ship) {
            echo '<p><strong>Wysyłka:</strong> ' . esc_html($this->date_long($schedule->date($ship)));
            if ($delivery) {
                echo '<br><strong>Dostawa:</strong> ' . esc_html($this->date_long($schedule->date($delivery)));
            }
            echo '</p>';
        } elseif ($iconic) {
            echo '<p><strong>Data wysyłki (Iconic):</strong> ' . esc_html($iconic) . '</p>';
        }

        if ($order->is_editable() || current_user_can('edit_shop_orders')) {
            printf(
                '<p><label for="wc_stn_admin_ship_date">Zmień datę wysyłki:</label><br><input type="date" id="wc_stn_admin_ship_date" name="wc_stn_admin_ship_date" value="%s" /></p>',
                esc_attr($ship)
            );
            wp_nonce_field('wc_stn_admin_ship_date', 'wc_stn_admin_nonce');
        }

        echo '</div>';
    }

    public function admin_order_save($order_id, $order = null)
    {
        if (
            !isset($_POST['wc_stn_admin_nonce'], $_POST['wc_stn_admin_ship_date']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wc_stn_admin_nonce'])), 'wc_stn_admin_ship_date') ||
            !current_user_can('edit_shop_orders')
        ) {
            return;
        }

        $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $new = sanitize_text_field(wp_unslash($_POST['wc_stn_admin_ship_date']));
        $old = (string) $order->get_meta(self::META_SHIP);

        if ($new === $old || ('' !== $new && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $new))) {
            return;
        }

        $user = wp_get_current_user()->display_name;

        if ('' === $new) {
            $order->delete_meta_data(self::META_SHIP);
            $order->delete_meta_data(self::META_DELIVERY);
            $order->delete_meta_data(self::META_CUTOFF);
            $order->add_order_note(sprintf('Usunięto datę wysyłki %s (%s).', $old, $user));
        } else {
            $schedule = $this->plugin->schedule();
            $delivery = $schedule->delivery_for($schedule->date($new));
            $order->update_meta_data(self::META_SHIP, $new);
            $order->update_meta_data(self::META_DELIVERY, $delivery ? $delivery->format('Y-m-d') : '');
            $order->delete_meta_data(self::META_CUTOFF);
            $order->add_order_note(sprintf('Zmieniono datę wysyłki z %s na %s (%s).', $old ?: 'brak', $new, $user));
        }

        $order->save();
    }

    /* ---------------------------------------------------------
     * Lista zamówień
     * --------------------------------------------------------- */

    public function list_columns($columns)
    {
        $result = [];
        foreach ($columns as $key => $label) {
            $result[$key] = $label;
            if ('order_date' === $key) {
                $result[self::COLUMN] = 'Wysyłka';
            }
        }
        if (!isset($result[self::COLUMN])) {
            $result[self::COLUMN] = 'Wysyłka';
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

        if ($ship) {
            echo esc_html($this->date_short($schedule->date($ship)));
            $delivery = $order->get_meta(self::META_DELIVERY);
            if ($delivery) {
                echo '<br><small>dostawa ' . esc_html($this->date_short($schedule->date($delivery))) . '</small>';
            }
            return;
        }

        // Zamówienia sprzed przełączenia: data z Iconic, tylko do wglądu (bez migracji).
        $iconic = (string) $order->get_meta('jckwds_date_ymd');
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $iconic, $m)) {
            echo esc_html($this->date_short($schedule->date("{$m[1]}-{$m[2]}-{$m[3]}"))) . '<br><small>Iconic</small>';
        }
    }

    public function list_sortable_columns($columns)
    {
        $columns[self::COLUMN] = self::COLUMN;
        return $columns;
    }

    /**
     * HPOS: sortowanie po dacie wysyłki (nazwana klauzula meta_query) i filtr po dniu.
     */
    public function list_query_args($args)
    {
        // phpcs:disable WordPress.Security.NonceVerification -- parametry listy w panelu, tylko odczyt.
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
            $args['meta_query'] = $meta_query;
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

        echo '<select name="' . esc_attr(self::FILTER_PARAM) . '"><option value="">Wysyłka: wszystkie</option>';
        foreach ($schedule->shipping_dates($from, 45) as $ymd) {
            printf(
                '<option value="%s"%s>Wysyłka: %s</option>',
                esc_attr($ymd),
                selected($current, $ymd, false),
                esc_html($this->date_short($schedule->date($ymd)) . '.' . substr($ymd, 0, 4))
            );
        }
        echo '</select>';
    }

    /**
     * Stara tabela postów (sklepy bez HPOS).
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
     * Okres przejściowy: wyłączenie Iconic w koszyku i checkoucie
     * --------------------------------------------------------- */

    /**
     * Iconic rejestruje hooki na wp_loaded (10), my zdejmujemy je na wp_loaded (99), także dla AJAX checkoutu.
     * Sprawdzone na Iconic 2.13.0. Sam filtr iconic_wds_delivery_slots_allowed nie wystarcza:
     * Checkout::checkout_fields dodaje wymagane pole "jckwds" bez sprawdzania tego filtra.
     */
    public function disable_iconic_checkout()
    {
        if (!self::iconic_active()) {
            return;
        }

        add_filter('iconic_wds_delivery_slots_allowed', '__return_false', 99);

        remove_filter('woocommerce_checkout_fields', ['Iconic_WDS\Checkout', 'checkout_fields'], 10);
        remove_filter('woocommerce_checkout_posted_data', ['Iconic_WDS\Checkout', 'checkout_posted_data'], 10);
        remove_action('woocommerce_checkout_process', ['Iconic_WDS\Checkout', 'classic_checkout_process'], 10);
        remove_action('woocommerce_after_checkout_validation', ['Iconic_WDS\Checkout', 'catch_shipping'], 10);
        remove_action('woocommerce_checkout_update_order_meta', ['Iconic_WDS\Order', 'update_order_meta'], 10);

        add_shortcode('iconic-wds-fields', '__return_empty_string');
    }

    public function dequeue_iconic_assets()
    {
        if (!self::iconic_active() || !function_exists('is_checkout') || !(is_checkout() || is_cart())) {
            return;
        }

        wp_dequeue_script('jckwds-script');
        wp_dequeue_style('jckwds-style');
    }

    /* ---------------------------------------------------------
     * Formatowanie dat
     * --------------------------------------------------------- */

    private function date_short(DateTimeImmutable $d)
    {
        return WC_STN_Schedule::DAY_SHORT[(int) $d->format('N')] . ' ' . $d->format('d.m');
    }

    private function date_long(DateTimeImmutable $d)
    {
        return WC_STN_Schedule::DAY_NOMINATIVE[(int) $d->format('N')] . ', ' . $d->format('d.m.Y');
    }
}
