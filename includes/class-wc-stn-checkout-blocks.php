<?php
/**
 * Wybór terminu w checkoucie blokowym (WooCommerce Blocks).
 *
 * Własny blok wewnętrzny checkoutu "wc-stn/ship-date" (rodzic: woocommerce/checkout-fields-block) z kafelkami jak
 * w klasycznym checkoucie, JS bez kroku build (assets/blocks/ship-date/):
 * - wtyczka raz wstawia blok do strony zamówienia z blokami (pod "Metody wysyłki"); dalej sklep może go przesuwać i usuwać
 *   w edytorze jak inne bloki checkoutu, usuniętego nie wstawiamy ponownie (opcja BLOCK_INSERTED_KEY); blok "wymuszony"
 *   (lock.remove) odpada, bo WooCommerce dokłada takie bloki na koniec formularza, pod przycisk zamówienia,
 * - termin trafia do Store API przez setExtensionData (namespace "wc-stn"), razem ze znacznikiem, że blok był widoczny,
 * - walidacja i zapis tych samych meta co w klasycznym checkoucie przy składaniu zamówienia; bez znacznika (np. płatność
 *   ekspresowa, zamówienie z zewnątrz Store API) data nie jest wymagana, więc brak bloku nie blokuje zamówień.
 *
 * Hooki rejestruje tylko, gdy moduł jest włączony w ustawieniach.
 */

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;

if (!defined('ABSPATH')) {
    exit;
}

final class WC_STN_Checkout_Blocks
{
    const BLOCK = 'wc-stn/ship-date';

    /** Wiersz z wybranym terminem w podsumowaniu zamówienia (jak review_row() w klasycznym checkoucie). */
    const ROW_BLOCK = 'wc-stn/ship-date-row';

    /** ID strony zamówienia, do której wiersz podsumowania został już raz wstawiony automatycznie. */
    const ROW_INSERTED_KEY = 'wc_ship_today_notice_row_inserted';

    /** Namespace danych rozszerzenia w Store API (extensions.wc-stn). */
    const API_NAMESPACE = 'wc-stn';

    /** ID strony zamówienia, do której blok został już raz wstawiony automatycznie. */
    const BLOCK_INSERTED_KEY = 'wc_ship_today_notice_block_inserted';

    /** Meta użytkownika: ID strony zamówienia, dla której zamknięto powiadomienie o braku bloku. */
    const NOTICE_DISMISSED_META = 'wc_stn_block_notice_dismissed';

    /** Sprawdzone na WooCommerce 11.x; na starszych blok się nie rejestruje (checkout blokowy bez wyboru terminu). */
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
        // Moduł startuje na init 0, zwykle już po woocommerce_blocks_loaded
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
     * Typ bloku dla edytora (block.json) i skrypty. Skrypt frontu rejestruje komponent przez wc.blocksCheckout.
     */
    public function register_block()
    {
        $dir = plugin_dir_path(WC_STN_FILE) . 'assets/blocks/ship-date/';
        $url = plugins_url('assets/blocks/ship-date/', WC_STN_FILE);

        wp_register_style('wc-stn-checkout', plugins_url('assets/css/checkout.css', WC_STN_FILE), [], WC_STN_VERSION);
        wp_register_script('wc-stn-ship-date-frontend', $url . 'frontend.js', ['wc-blocks-checkout', 'wc-settings', 'wp-element', 'wp-data'], WC_STN_VERSION, true);
        wp_register_script('wc-stn-ship-date-editor', $url . 'editor.js', ['wp-blocks', 'wp-block-editor', 'wp-element'], WC_STN_VERSION, true);
        if (is_admin()) {
            // Tylko panel (edytor): na froncie nie liczymy terminów przy każdym żądaniu
            wp_add_inline_script('wc-stn-ship-date-editor', 'window.wcStnShipDateEditor = ' . wp_json_encode($this->editor_data()) . ';', 'before');
        }

        register_block_type($dir, [
            'editor_script_handles' => ['wc-stn-ship-date-editor'],
            'editor_style_handles'  => ['wc-stn-checkout'],
        ]);
        // Wiersz podsumowania: ten sam skrypt edytora i frontu (frontend.js rejestruje oba bloki)
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
     * Pola extensions.wc-stn w żądaniu POST /wc/store/v1/checkout.
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
     * Dane dla bloku na froncie (wcSettings "wc-stn-ship-date_data"): terminy na FRONTEND_DAYS dni, jak data-stn-checkout
     * w klasycznym checkoucie, więc JS dokłada kolejne terminy, gdy godzina graniczna minie na otwartej stronie.
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
            // Wiersz w podsumowaniu (blok ROW_BLOCK): przełącznik "checkout_review_row", treść jak w klasycznym checkoucie
            'reviewRow' => !empty($o['checkout_review_row']),
            'rows'      => $rows,
        ];

        // Harmonogramy metod wysyłki: terminy i wiersze podsumowania dla każdej metody z własnymi godzinami, z tych samych slotów
        // (JS wybiera wg stawki w koszyku; ta sama data wysyłki może mieć u metody inną datę dostawy). Bez nich dane są jak wcześniej.
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
     * Podgląd w edytorze: etykieta i kilka terminów z aktualnych ustawień.
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
                : __('Hidden: the order summary row is turned off in WooCommerce → Settings → Ship Today Notice → Checkout.', 'wc-ship-today-notice'),
        ];
    }

    /**
     * Jednorazowe wstawienie bloku do strony zamówienia z checkoutem blokowym (pod "Metody wysyłki", inaczej przed płatnością).
     * Po wstawieniu albo gdy blok już jest - zapamiętuje stronę i więcej jej nie zmienia, więc usunięty blok nie wraca.
     */
    public function maybe_insert_block()
    {
        $this->insert_block(true);
    }

    /**
     * Przy aktualizacji wtyczki (WC_Ship_Today_Notice::maybe_upgrade): sklep z checkoutem blokowym miał w 2.2.0 pole select,
     * które zniknęło, więc blok wstawiamy od razu, bez sprawdzania uprawnień bieżącego użytkownika.
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

        // Każdy blok osobno, raz na stronę zamówienia: [blok, znacznik, rodzic, wstaw za, inaczej przed (null = na koniec)]
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
                return; // klasyczny checkout (shortcode) albo checkout w szablonie motywu
            }

            if (!has_block($name, $content)) {
                $blocks = parse_blocks($content);
                // Starszy układ (strony zapisane we wcześniejszych wersjach WooCommerce): wiersze sum bezpośrednio w podsumowaniu,
                // bez bloku sum. Edytor WooCommerce i tak zamyka je w checkout-order-summary-totals-block przy otwarciu strony
                // (gubiąc przy tym nieznane bloki), więc robimy to samo przed wstawieniem wiersza.
                if (self::ROW_BLOCK === $name && !has_block($parent, $content)) {
                    $this->wrap_legacy_totals($blocks);
                }
                if (!$this->insert_into($blocks, $name, $parent, $after, $before)) {
                    continue; // bez znacznika: status w ustawieniach pokazuje, że trzeba dodać ręcznie
                }
                wp_update_post(['ID' => $page_id, 'post_content' => wp_slash(serialize_blocks($blocks))]);
            }

            update_option($key, $page_id, false);
        }
    }

    /**
     * Starszy układ podsumowania: zamyka wiersze sum (subtotal, fee, discount, shipping, taxes) w bloku
     * woocommerce/checkout-order-summary-totals-block, w miejscu pierwszego z nich - jak migracja układu w edytorze WooCommerce.
     * Zwraca, czy coś zmienił.
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

            // Treść rodzica od nowa: otwierający i zamykający HTML z oryginału, bloki rozdzielone pustą linią
            $first = $block['innerContent'][0] ?? '';
            $last  = end($block['innerContent']);
            $block['innerBlocks']  = $children;
            $block['innerContent'] = self::inner_content(is_string($first) ? $first : '', count($children), is_string($last) ? $last : '');

            return true;
        }

        return false;
    }

    /** innerContent bloku z $count blokami wewnętrznymi: otwarcie, null, "\n\n", null, ..., zamknięcie. */
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
     * Wstawia blok $name do bloku $parent za $after (albo przed $before, a bez $before na koniec). Zwraca, czy wstawił.
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

            // innerContent: null w miejscu każdego bloku wewnętrznego; nowy null za $index-tym
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
                // za ostatnim blokiem: przed końcowym tekstem (zamknięcie </div> rodzica)
                $last = count($content) - 1;
                array_splice($content, is_string($content[$last]) ? $last : $last + 1, 0, ["\n\n", null]);
            }
            $block['innerContent'] = $content;

            return true;
        }

        return false;
    }

    /**
     * Powiadomienie w całym panelu (manage_woocommerce): checkout blokowy bez bloku terminu, czyli bez wyboru daty.
     * Na stronie ustawień wtyczki jest ten sam status w sekcji checkoutu, więc tam go pomijamy.
     */
    public function missing_block_notice()
    {
        if (!current_user_can('manage_woocommerce') || 'missing' !== self::block_status()) {
            return;
        }

        $page_id = (int) wc_get_page_id('checkout');
        // Zakładka Checkout pokazuje status bloku sama, powiadomienie byłoby dublem
        // phpcs:disable WordPress.Security.NonceVerification -- tylko wybór strony panelu, bez zapisu.
        $on_checkout_tab = 'wc-settings' === sanitize_key(wp_unslash($_GET['page'] ?? '')) && WC_Ship_Today_Notice::SETTINGS_TAB === sanitize_title(wp_unslash($_GET['tab'] ?? '')) && 'checkout' === sanitize_key(wp_unslash($_GET['section'] ?? ''));
        // phpcs:enable
        if ($on_checkout_tab || $page_id === (int) get_user_meta(get_current_user_id(), self::NOTICE_DISMISSED_META, true)) {
            return;
        }

        $dismiss = wp_nonce_url(add_query_arg('wc_stn_dismiss_block_notice', 1), 'wc_stn_dismiss_block_notice');
        printf(
            '<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p><a class="button" href="%3$s">%4$s</a> <a href="%5$s">%6$s</a></p></div>',
            esc_html__('Ship Today Notice:', 'wc-ship-today-notice'),
            esc_html__('the block checkout has no "Ship date" block, so customers cannot choose a ship date (orders are placed without it). Add the block inside the checkout form in the checkout page editor.', 'wc-ship-today-notice'),
            esc_url((string) get_edit_post_link($page_id)),
            esc_html__('Edit the checkout page', 'wc-ship-today-notice'),
            esc_url($dismiss),
            esc_html__('Dismiss', 'wc-ship-today-notice')
        );
    }

    public function maybe_dismiss_notice()
    {
        if (empty($_GET['wc_stn_dismiss_block_notice']) || !current_user_can('manage_woocommerce')) { // phpcs:ignore WordPress.Security.NonceVerification -- nonce niżej.
            return;
        }
        check_admin_referer('wc_stn_dismiss_block_notice');
        update_user_meta(get_current_user_id(), self::NOTICE_DISMISSED_META, (int) wc_get_page_id('checkout'));
    }

    /**
     * Czy strona zamówienia ma checkout blokowy i czy jest w nim blok terminu (status w panelu).
     *
     * @return string 'classic', 'present' albo 'missing'
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
     * Walidacja przy składaniu zamówienia (Store API) i zapis tych samych meta co w klasycznym checkoucie.
     */
    public function validate_and_save($order, $request)
    {
        $data = (array) ($request['extensions'][self::API_NAMESPACE] ?? []);

        // Blok nie był widoczny (brak bloku, płatność ekspresowa, odbiór osobisty) - data nie jest wymagana.
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
