<?php
/**
 * Wybór terminu w checkoucie blokowym (WooCommerce Blocks).
 *
 * Pole przez Additional Checkout Fields API (select w sekcji "order"), bez własnego JS:
 * - opcje = najbliższe terminy z silnika, liczone przy każdym żądaniu (render strony i Store API),
 * - wymagane i widoczne tylko, gdy koszyk wymaga wysyłki (reguła na cart.needs_shipping),
 * - termin spoza aktualnych opcji (np. godzina graniczna minęła na otwartej stronie) odrzucamy przed walidacją Store API
 *   z własnym komunikatem,
 * - przy składaniu zamówienia zapis tych samych meta co w klasycznym checkoucie, więc maile, panel i lista działają bez zmian.
 *
 * Hooki pola rejestruje tylko, gdy moduł jest włączony w ustawieniach.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WC_STN_Checkout_Blocks
{
    const FIELD_ID = 'wc-stn/ship-date';

    /**
     * Reguły warunkowe required/hidden są od WooCommerce 9.9, ukrywanie pola w potwierdzeniu i mailach
     * (show_in_order_confirmation, woocommerce_filter_fields_for_order_confirmation) od 10.1. Na starszych pole się nie rejestruje.
     */
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

        // Po trybie testowym (init 1), żeby opcje pola liczyły się od podmienionego "teraz".
        add_action('init', [$this, 'register_field'], 2);
        add_filter('woocommerce_get_default_value_for_' . self::FIELD_ID, [$this, 'default_value'], 10, 3);
        add_filter('rest_pre_dispatch', [$this, 'reject_closed_slot'], 10, 3);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'save_to_order'], 10, 2);

        // Daty pokazuje nasz box w panelu zamówienia, edytowalny select WooCommerce byłby dublem.
        add_filter('woocommerce_admin_shipping_fields', [$this, 'hide_admin_field'], 20);
    }

    public static function supported()
    {
        return defined('WC_VERSION') && version_compare(WC_VERSION, self::MIN_WC_VERSION, '>=');
    }

    public function register_field()
    {
        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        $options = $this->field_options();
        if (!$options) {
            return; // Select bez opcji WooCommerce odrzuca; bez terminów nie ma czego wybierać.
        }

        $needs_shipping = function ($value) {
            return ['cart' => ['properties' => ['needs_shipping' => ['const' => $value]]]];
        };

        woocommerce_register_additional_checkout_field([
            'id'                         => self::FIELD_ID,
            'label'                      => $this->plugin->get_options()['checkout_label'],
            'location'                   => 'order',
            'type'                       => 'select',
            'options'                    => $options,
            'required'                   => $needs_shipping(true),
            'hidden'                     => $needs_shipping(false),
            'show_in_order_confirmation' => false, // wiersze dodaje order_item_totals() z WC_STN_Checkout
        ]);
    }

    /**
     * Najbliższe terminy jako opcje selecta: wartość Y-m-d wysyłki, etykieta jak na kafelku.
     */
    private function field_options()
    {
        $o        = $this->plugin->get_options();
        $schedule = $this->plugin->schedule();
        $options  = [];

        foreach ($schedule->slots(max(1, (int) $o['checkout_count'])) as $slot) {
            $tile      = $this->checkout->tile_data($slot);
            $options[] = ['value' => $tile['value'], 'label' => $tile['main'] . ', ' . $tile['sub']];
        }

        return $options;
    }

    /**
     * "Zaznacz najbliższy termin": pierwsza opcja, gdy klient jeszcze nic nie wybrał.
     */
    public function default_value($value, $group = '', $wc_object = null)
    {
        if (null !== $value || empty($this->plugin->get_options()['checkout_preselect'])) {
            return $value;
        }

        $options = $this->field_options();

        return $options ? $options[0]['value'] : $value;
    }

    /**
     * Store API sprawdza select przez enum z aktualnych opcji i dla terminu, który właśnie się zamknął,
     * zwróciłby techniczny błąd parametru. Zwracamy wcześniej czytelny komunikat.
     */
    public function reject_closed_slot($result, $server, $request)
    {
        if (null !== $result || 'POST' !== $request->get_method() || '/wc/store/v1/checkout' !== untrailingslashit($request->get_route())) {
            return $result;
        }

        $fields = $request->get_param('additional_fields');
        $ymd    = is_array($fields) && isset($fields[self::FIELD_ID]) ? sanitize_text_field((string) $fields[self::FIELD_ID]) : '';

        if ('' === $ymd || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) || in_array($ymd, array_column($this->field_options(), 'value'), true)) {
            return $result;
        }

        $schedule = $this->plugin->schedule();
        $date     = $schedule->date($ymd);
        $label    = WC_STN_Schedule::day_label((int) $date->format('N'), 'short') . ' ' . $date->format('d.m');
        $closed   = $schedule->is_shipping_day($date) && $schedule->cutoff_for($date) <= $schedule->now();

        $message = $closed
            /* translators: %s: shipping date, e.g. "pn 26.10" */
            ? __('Orders for shipping on %s are no longer accepted. Please choose another date.', 'wc-ship-today-notice')
            /* translators: %s: shipping date, e.g. "pn 26.10" */
            : __('Shipping on %s is not available. Please choose another date.', 'wc-ship-today-notice');

        return new WP_Error('wc_stn_slot_unavailable', sprintf($message, $label), ['status' => 400]);
    }

    /**
     * Te same meta co w klasycznym checkoucie (WC_STN_Checkout::save_to_order).
     */
    public function save_to_order($order, $request)
    {
        $fields = $request->get_param('additional_fields');
        $ymd    = is_array($fields) && isset($fields[self::FIELD_ID]) ? sanitize_text_field((string) $fields[self::FIELD_ID]) : '';
        $slot   = '' !== $ymd ? $this->checkout->find_slot($ymd) : null;

        if (!$slot) {
            return;
        }

        $order->update_meta_data(WC_STN_Checkout::META_SHIP, $slot['ship']->format('Y-m-d'));
        $order->update_meta_data(WC_STN_Checkout::META_DELIVERY, $slot['delivery'] ? $slot['delivery']->format('Y-m-d') : '');
        $order->update_meta_data(WC_STN_Checkout::META_CUTOFF, $slot['cutoff']->getTimestamp());
    }

    public function hide_admin_field($fields)
    {
        unset($fields[self::FIELD_ID], $fields['_wc_other/' . self::FIELD_ID]);

        return $fields;
    }
}
