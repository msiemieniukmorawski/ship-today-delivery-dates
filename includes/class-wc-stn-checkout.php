<?php
/**
 * Moduł "Wybór terminu w checkoucie".
 *
 * Kafelki z najbliższymi terminami z silnika, walidacja w chwili złożenia zamówienia,
 * zapis w zamówieniu (HPOS), wyświetlanie w podsumowaniu, mailach, panelu zamówienia i na liście zamówień.
 * Opcjonalnie wyłącza Iconic Delivery Slots w koszyku i checkoucie (okres przejściowy).
 *
 * Hooki rejestruje tylko, gdy moduł jest włączony w ustawieniach. Wyjątek: wyświetlanie dat z istniejących zamówień
 * (maile, panel tylko do odczytu, lista) przy przełączniku checkout_dates_when_disabled. Każdą część wyświetlania
 * można wyłączyć osobnym przełącznikiem (WC_Ship_Today_Notice::TOGGLES).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WC_STN_Checkout
{
    const FIELD        = 'wc_stn_ship_date';
    const RENDERED     = 'wc_stn_rendered';
    const META_SHIP    = '_wc_stn_ship_date';
    const META_DELIVERY = '_wc_stn_delivery_date';
    const META_CUTOFF  = '_wc_stn_cutoff';
    const COLUMN       = 'wc_stn_ship';
    const FILTER_PARAM = 'wc_stn_ship_filter';

    /** @var WC_Ship_Today_Notice */
    private $plugin;

    /** @var bool Pole zmiany daty w panelu zamówienia (moduł włączony + przełącznik). */
    private $admin_edit = false;

    public function __construct(WC_Ship_Today_Notice $plugin)
    {
        $this->plugin = $plugin;
        $o = $plugin->get_options();

        add_shortcode('ship_today_checkout', [$this, 'shortcode']);

        $enabled = !empty($o['checkout_enabled']);
        if ($enabled || !empty($o['checkout_dates_when_disabled'])) {
            $this->register_display_hooks($o);
        }

        if (!$enabled) {
            return;
        }

        // Checkout
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

        // Panel zamówienia: zmiana daty
        if (!empty($o['checkout_admin_edit'])) {
            $this->admin_edit = true;
            add_action('woocommerce_process_shop_order_meta', [$this, 'admin_order_save'], 20, 2);
        }

        // Okres przejściowy: Iconic aktywny, ale nie działa w koszyku i checkoucie
        if (!empty($o['disable_iconic'])) {
            add_action('wp_loaded', [$this, 'disable_iconic_checkout'], 99);
            add_action('wp_enqueue_scripts', [$this, 'dequeue_iconic_assets'], 999);
        }
    }

    /**
     * Wyświetlanie dat zapisanych w zamówieniach: thank you, maile, Moje konto, panel zamówienia, lista zamówień.
     */
    private function register_display_hooks(array $o)
    {
        // Po złożeniu zamówienia: thank you, maile, Moje konto
        if (!empty($o['checkout_order_rows'])) {
            add_filter('woocommerce_get_order_item_totals', [$this, 'order_item_totals'], 20, 2);
        }

        // Panel zamówienia
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'admin_order_box']);

        if (empty($o['checkout_list_column'])) {
            return;
        }

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

        // Znacznik: walidacja wymaga daty tylko, gdy pole było w formularzu (shortcode bywa niewstawiony albo poza formularzem)
        $marker = '<input type="hidden" name="' . esc_attr(self::RENDERED) . '" value="1" />';

        if (!$all) {
            return '<p class="wc-stn-checkout__empty">' . esc_html__('No delivery dates available. Please contact us.', 'wc-ship-today-notice') . '</p>' . $marker;
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
            'closed'    => $this->switched_message(),
        ];

        $style = '' !== $o['checkout_accent'] ? ' style="--wc-stn-accent:' . esc_attr($o['checkout_accent']) . '"' : '';

        $html  = '<fieldset class="wc-stn-checkout" id="wc-stn-checkout" data-stn-checkout="' . esc_attr(wp_json_encode($config)) . '"' . $style . '>';
        $html .= '<legend class="wc-stn-checkout__legend">' . esc_html($o['checkout_label']) . ' <abbr class="required" title="' . esc_attr__('required', 'wc-ship-today-notice') . '">*</abbr></legend>';
        $html .= '<div class="wc-stn-checkout__tiles">';
        foreach (array_slice($tiles, 0, $count) as $tile) {
            $html .= $this->tile_html($tile, $tile['value'] === $selected);
        }
        $html .= '</div>';
        $html .= '<p class="wc-stn-checkout__notice" role="status" aria-live="polite" hidden></p>';
        $html .= $marker;
        $html .= '</fieldset>';

        return $html;
    }

    /**
     * Domyślne teksty kafelka jako szablony z tokenami (z tłumaczeń), wg "Duży napis na kafelku" i tego, czy termin ma dostawę.
     * Te same szablony pokazuje panel (podpowiedź / wartość drugiej linii), więc wyrenderowane dają dokładnie dotychczasowy kafelek.
     */
    public static function default_tile_texts($primary, $has_delivery)
    {
        if ($has_delivery && 'delivery' === $primary) {
            /* translators: checkout tile, second line. %s: short weekday and date, e.g. "Mon 09.11" */
            return ['main' => '{delivery_short}', 'sub' => sprintf(_x('ship %s', 'checkout tile', 'wc-ship-today-notice'), '{ship_short}')];
        }

        return [
            'main' => '{ship_short}',
            'sub'  => $has_delivery
                /* translators: checkout tile, order review and orders list. %s: short weekday and date, e.g. "Tue 10.11" */
                ? sprintf(_x('delivery %s', 'short date line', 'wc-ship-today-notice'), '{delivery_short}')
                : _x('ship', 'checkout tile without a delivery date', 'wc-ship-today-notice'),
        ];
    }

    public function tile_data(array $slot)
    {
        $o        = $this->plugin->get_options();
        $schedule = $this->plugin->schedule();
        $ship     = $slot['ship'];
        $delivery = $slot['delivery'];
        $name     = function (DateTimeImmutable $d) {
            return WC_STN_Schedule::day_label((int) $d->format('N'), 'name') . ' ' . $d->format('d.m');
        };

        // Teksty kafelka: własne z ustawień (tokeny jak w widgecie + {ship_short}/{delivery_short}) albo domyślne.
        // "Dziś/jutro" w {*_when} liczy PHP przy renderze (strona checkoutu nie jest cache'owana).
        $labels   = $schedule->slot_labels($slot) + [
            'ship_short'     => $this->date_short($ship),
            'delivery_short' => $delivery ? $this->date_short($delivery) : '',
        ];
        $defaults = self::default_tile_texts($o['checkout_primary'], (bool) $delivery);
        $main_tpl = '' !== (string) $o['checkout_tile_main'] ? $this->plugin->t('checkout_tile_main', $o['checkout_tile_main']) : $defaults['main'];
        // Druga linia: null = domyślna, '' = ukryta
        $sub_tpl  = null === $o['checkout_tile_sub'] ? $defaults['sub'] : (string) $o['checkout_tile_sub'];
        if ('' !== $sub_tpl && null !== $o['checkout_tile_sub']) {
            $sub_tpl = $this->plugin->t('checkout_tile_sub', $sub_tpl);
        }

        $main = trim($schedule->render_text($main_tpl, $labels));
        $sub  = '' === $sub_tpl ? '' : trim($schedule->render_text($sub_tpl, $labels));

        /* translators: checkout tile for screen readers. %s: weekday and date, e.g. "Monday 09.11" */
        $aria = sprintf(__('Ship %s', 'wc-ship-today-notice'), $name($ship));
        if ($delivery) {
            /* translators: checkout tile for screen readers, after the ship date. %s: weekday and date */
            $aria .= ', ' . sprintf(__('delivery %s', 'wc-ship-today-notice'), $name($delivery));
        }
        /* translators: checkout tile for screen readers. %1$s: weekday (until form), %2$s: time, e.g. "orders until Sunday 20:00" */
        $aria .= ', ' . sprintf(__('orders until %1$s %2$s', 'wc-ship-today-notice'), WC_STN_Schedule::day_label((int) $slot['cutoff']->format('N'), 'until'), $slot['cutoff']->format('H:i'));

        return [
            'value'  => $ship->format('Y-m-d'),
            'cutoff' => $slot['cutoff']->getTimestamp(),
            'main'   => $main,
            'sub'    => $sub,
            'aria'   => $aria,
            // Termin w komunikatach ({slot}, {next_slot}): domyślny duży napis, niezależnie od własnych tekstów kafelka
            'label'  => $schedule->render_text($defaults['main'], $labels),
        ];
    }

    private function tile_html(array $tile, $checked)
    {
        return sprintf(
            '<label class="wc-stn-tile"><input type="radio" class="wc-stn-tile__input" name="%1$s" value="%2$s" data-cutoff="%3$d" aria-label="%4$s"%5$s /><span class="wc-stn-tile__main">%6$s</span>%7$s</label>',
            esc_attr(self::FIELD),
            esc_attr($tile['value']),
            (int) $tile['cutoff'],
            esc_attr($tile['aria']),
            $checked ? ' checked' : '',
            esc_html($tile['main']),
            '' !== $tile['sub'] ? '<span class="wc-stn-tile__sub">' . esc_html($tile['sub']) . '</span>' : ''
        );
    }

    public function enqueue_assets()
    {
        // Checkout blokowy ma własne pole (WC_STN_Checkout_Blocks), kafelki nie są tam potrzebne.
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page() || has_block('woocommerce/checkout')) {
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

        /* translators: order review row. %s: short weekday and date, e.g. "Mon 09.11" */
        $text = esc_html(sprintf(_x('Ship %s', 'order review row', 'wc-ship-today-notice'), $this->date_short($slot['ship'])));
        if ($slot['delivery']) {
            $text .= '<br>' . esc_html($this->delivery_short($slot['delivery']));
        }

        printf(
            '<tr class="wc-stn-review"><th>%s</th><td data-title="%s">%s</td></tr>',
            esc_html($o['checkout_label']),
            esc_attr($o['checkout_label']),
            wp_kses($text, ['br' => []])
        );
    }

    /**
     * Czy wszystkie wybrane metody wysyłki to odbiór osobisty (wtedy data nie jest wymagana).
     */
    public function only_local_pickup()
    {
        $ids = function_exists('wc_get_chosen_shipping_method_ids') ? wc_get_chosen_shipping_method_ids() : [];
        if (!$ids) {
            return false;
        }

        $pickup = (array) apply_filters('woocommerce_local_pickup_methods', ['legacy_local_pickup', 'local_pickup', 'pickup_location']);

        return !array_diff($ids, $pickup);
    }

    public function validate($data, $errors)
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce weryfikuje nonce checkoutu.
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
     * "Wybierz termin dostawy." - wspólne dla klasycznego i blokowego checkoutu.
     */
    public function choose_message($html = false)
    {
        $o     = $this->plugin->get_options();
        $label = $o['checkout_label'];
        // Po polsku nazwa pola małą literą ("Wybierz termin dostawy."); tłumaczenie "no" zostawia ją bez zmian (np. niemieckie rzeczowniki)
        $lower = 'yes' === _x('yes', 'Lowercase the field label inside "Choose %s."? Translate as "no" to keep it as entered.', 'wc-ship-today-notice')
            ? (function_exists('mb_strtolower') ? mb_strtolower($label) : strtolower($label))
            : $label;

        // Własny komunikat z ustawień: {field} = nazwa pola (jak w domyślnym, pogrubiona w HTML)
        if ('' !== (string) $o['checkout_msg_choose']) {
            $text = $this->plugin->t('checkout_msg_choose', $o['checkout_msg_choose']);
            return $html
                ? str_replace('{field}', '<strong>' . esc_html($lower) . '</strong>', esc_html($text))
                : str_replace('{field}', $lower, $text);
        }

        if ($html) {
            /* translators: checkout error. %s: field label, e.g. "delivery date" */
            return sprintf(esc_html__('Choose %s.', 'wc-ship-today-notice'), '<strong>' . esc_html($lower) . '</strong>');
        }

        /* translators: checkout error. %s: field label, e.g. "delivery date" */
        return sprintf(__('Choose %s.', 'wc-ship-today-notice'), $lower);
    }

    /**
     * Domyślne komunikaty checkoutu z tokenami zamiast %s (podpowiedzi w panelu, wartość, gdy pole w ustawieniach jest puste).
     */
    public static function default_messages()
    {
        return [
            /* translators: checkout error. %s: field label, e.g. "delivery date" */
            'checkout_msg_choose'      => str_replace('%s', '{field}', __('Choose %s.', 'wc-ship-today-notice')),
            /* translators: %s: shipping date, e.g. "pn 26.10" */
            'checkout_msg_closed'      => str_replace('%s', '{slot}', __('Orders for shipping on %s are no longer accepted. Please choose another date.', 'wc-ship-today-notice')),
            /* translators: %s: shipping date, e.g. "pn 26.10" */
            'checkout_msg_unavailable' => str_replace('%s', '{slot}', __('Shipping on %s is not available. Please choose another date.', 'wc-ship-today-notice')),
            /* translators: checkout notice when the selected date closes on an open page. %1$s: closed date, %2$s: newly selected date */
            'checkout_msg_switched'    => str_replace(['%1$s', '%2$s'], ['{slot}', '{next_slot}'], __('The %1$s date has just closed, we selected %2$s.', 'wc-ship-today-notice')),
        ];
    }

    /** Tekst z ustawień albo domyślny (z tokenami). */
    private function message($key)
    {
        $o = $this->plugin->get_options();

        return '' !== (string) $o[$key] ? $this->plugin->t($key, $o[$key]) : self::default_messages()[$key];
    }

    /**
     * Komunikat w JS, gdy wybrany termin zamknie się na otwartej stronie: tokeny {slot} i {next_slot} wstawia JS (tile.label).
     */
    public function switched_message()
    {
        return $this->message('checkout_msg_switched');
    }

    /**
     * Komunikat dla daty wysyłki, której nie ma wśród aktualnych terminów (godzina graniczna minęła albo dzień bez wysyłki).
     */
    public function unavailable_message($ymd)
    {
        $schedule = $this->plugin->schedule();
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

        $o        = $this->plugin->get_options();
        $schedule = $this->plugin->schedule();
        // Etykiety z ustawień, puste = domyślne (przetłumaczone)
        $label    = function ($key, $default) use ($o) {
            return '' !== (string) $o[$key] ? $this->plugin->t($key, $o[$key]) : $default;
        };
        $add      = ['wc_stn_ship' => ['label' => $label('checkout_row_ship_label', __('Ship date:', 'wc-ship-today-notice')), 'value' => $this->date_long($schedule->date($ship))]];

        $delivery = $order->get_meta(self::META_DELIVERY);
        if ($delivery) {
            $add['wc_stn_delivery'] = ['label' => $label('checkout_row_delivery_label', __('Planned delivery:', 'wc-ship-today-notice')), 'value' => $this->date_long($schedule->date($delivery))];
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
        $edit     = $this->admin_edit && ($order->is_editable() || current_user_can('edit_shop_orders'));

        // Bez dat i bez pola zmiany (tylko odczyt) nie ma czego pokazać
        if (!$ship && !$iconic && !$edit) {
            return;
        }

        echo '<div class="wc-stn-admin-box" style="clear:both;padding-top:8px">';
        echo '<h3>' . esc_html__('Shipping date', 'wc-ship-today-notice') . '</h3>';

        if ($ship) {
            echo '<p><strong>' . esc_html__('Ship:', 'wc-ship-today-notice') . '</strong> ' . esc_html($this->date_long($schedule->date($ship)));
            if ($delivery) {
                echo '<br><strong>' . esc_html__('Delivery:', 'wc-ship-today-notice') . '</strong> ' . esc_html($this->date_long($schedule->date($delivery)));
            }
            echo '</p>';
        } elseif ($iconic) {
            echo '<p><strong>' . esc_html__('Ship date (Iconic):', 'wc-ship-today-notice') . '</strong> ' . esc_html($iconic) . '</p>';
        }

        if ($edit) {
            printf(
                '<p><label for="wc_stn_admin_ship_date">%s</label><br><input type="date" id="wc_stn_admin_ship_date" name="wc_stn_admin_ship_date" value="%s" /></p>',
                esc_html__('Change ship date:', 'wc-ship-today-notice'),
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

        if ($new === $old) {
            return;
        }

        if ('' !== $new && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $new, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
            if (class_exists('WC_Admin_Meta_Boxes')) {
                /* translators: %s: date entered in the order edit screen */
                WC_Admin_Meta_Boxes::add_error(sprintf(__('Invalid ship date "%s". The ship date was not changed.', 'wc-ship-today-notice'), $new));
            }
            return;
        }

        $user = wp_get_current_user()->display_name;

        if ('' === $new) {
            $order->delete_meta_data(self::META_SHIP);
            $order->delete_meta_data(self::META_DELIVERY);
            $order->delete_meta_data(self::META_CUTOFF);
            /* translators: order note. %1$s: removed ship date (Y-m-d), %2$s: user name */
            $order->add_order_note(sprintf(__('Ship date %1$s removed (%2$s).', 'wc-ship-today-notice'), $old, $user));
        } else {
            $schedule = $this->plugin->schedule();
            $delivery = $schedule->delivery_for($schedule->date($new));
            $order->update_meta_data(self::META_SHIP, $new);
            $order->update_meta_data(self::META_DELIVERY, $delivery ? $delivery->format('Y-m-d') : '');
            $order->delete_meta_data(self::META_CUTOFF);
            /* translators: order note. %1$s: previous ship date (Y-m-d) or "none", %2$s: new ship date, %3$s: user name */
            $order->add_order_note(sprintf(__('Ship date changed from %1$s to %2$s (%3$s).', 'wc-ship-today-notice'), $old ?: _x('none', 'no previous ship date', 'wc-ship-today-notice'), $new, $user));
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
                $result[self::COLUMN] = _x('Shipping', 'column with the ship date', 'wc-ship-today-notice');
            }
        }
        if (!isset($result[self::COLUMN])) {
            $result[self::COLUMN] = _x('Shipping', 'column with the ship date', 'wc-ship-today-notice');
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
                echo '<br><small>' . esc_html($this->delivery_short($schedule->date($delivery))) . '</small>';
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

        echo '<select name="' . esc_attr(self::FILTER_PARAM) . '"><option value="">' . esc_html__('Shipping: all', 'wc-ship-today-notice') . '</option>';
        foreach ($schedule->shipping_dates($from, 45) as $ymd) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($ymd),
                selected($current, $ymd, false),
                /* translators: orders list filter. %s: short weekday and date, e.g. "Mon 09.11.2026" */
                esc_html(sprintf(__('Shipping: %s', 'wc-ship-today-notice'), $this->date_short($schedule->date($ymd)) . '.' . substr($ymd, 0, 4)))
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
        return WC_STN_Schedule::day_label((int) $d->format('N'), 'short') . ' ' . $d->format('d.m');
    }

    private function date_long(DateTimeImmutable $d)
    {
        return WC_STN_Schedule::day_label((int) $d->format('N'), 'name') . ', ' . $d->format('d.m.Y');
    }

    /** "dostawa wt 10.11" (kafelek, podsumowanie zamówienia, lista zamówień). */
    private function delivery_short(DateTimeImmutable $d)
    {
        /* translators: checkout tile, order review and orders list. %s: short weekday and date, e.g. "Tue 10.11" */
        return sprintf(_x('delivery %s', 'short date line', 'wc-ship-today-notice'), $this->date_short($d));
    }
}
