<?php
/**
 * Automatyczne wyświetlanie widgetu bez shortcode: strona produktu, koszyk, checkout.
 *
 * Trzy niezależne wiersze w ustawieniach (klucz "auto_placement" w głównej opcji). Nowy sklep: strona produktu włączona (pod przyciskiem
 * "Dodaj do koszyka"), koszyk i checkout wyłączone. Sklep z ustawieniami sprzed tej funkcji: wszystko wyłączone (disabled()).
 * - motyw klasyczny / koszyk i checkout z shortcode: hook WooCommerce z listy i priorytet (puste = domyślny dla hooka),
 * - bloki (koszyk, checkout, formularz "dodaj do koszyka" w szablonie blokowym): przed albo za blokiem, przez filtr render_block_{nazwa},
 *   bo te strony nie wywołują hooków szablonów klasycznych. Strona ma albo jedno, albo drugie, więc rejestrujemy oba (produkt: patrz konstruktor).
 * Render zawsze przez WC_Ship_Today_Notice::shortcode_output(), niezależnie od modułu wyboru terminu.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WC_STN_Auto_Placement
{
    /** Największy priorytet przyjmowany z formularza. */
    const MAX_PRIORITY = 999;

    /** Bloki, przy których widget może stanąć (produkt: stary i nowy blok formularza). */
    const BLOCKS = [
        'product'  => ['woocommerce/add-to-cart-form', 'woocommerce/add-to-cart-with-options'],
        'cart'     => ['woocommerce/cart'],
        'checkout' => ['woocommerce/checkout'],
    ];

    /** Hooki z domyślnym priorytetem (wiersz => hook => priorytet); pierwszy = domyślny hook. Wartości wg wc-template-hooks.php. */
    const HOOKS = [
        'product'  => [
            'woocommerce_after_add_to_cart_form'  => 10,
            'woocommerce_before_add_to_cart_form' => 10,
            'woocommerce_single_product_summary'  => 25, // cena 10, opis 20, przycisk 30
        ],
        'cart'     => [
            'woocommerce_before_cart'         => 20, // komunikaty 10
            'woocommerce_before_cart_totals'  => 10,
            'woocommerce_proceed_to_checkout' => 5, // przycisk 20
        ],
        'checkout' => [
            'woocommerce_before_checkout_form'         => 20, // komunikaty, logowanie, kupon 10
            'woocommerce_checkout_before_order_review' => 10,
            'woocommerce_review_order_before_submit'   => 10,
        ],
    ];

    /** @var WC_Ship_Today_Notice */
    private $plugin;

    /** @var array<string,bool> Wiersze już wyrenderowane w tym żądaniu (jeden widget na wiersz). */
    private $rendered = [];

    public function __construct(WC_Ship_Today_Notice $plugin)
    {
        $this->plugin = $plugin;

        foreach (self::settings($plugin->get_options()) as $row => $set) {
            if (empty($set['enabled'])) {
                continue;
            }

            $hook = function () use ($row) {
                echo $this->render($row); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML z shortcode_output().
            };

            if ('product' === $row) {
                // Blok formularza wywołuje hooki woocommerce_*_add_to_cart_form od środka, więc przy szablonie z tym blokiem
                // zostaje samo miejsce przy bloku. Szablon jest znany dopiero przy żądaniu strony.
                add_action('template_redirect', function () use ($set, $hook) {
                    if ('block' !== self::page_type('product')) {
                        add_action($set['hook'], $hook, self::priority('product', $set));
                    }
                });
            } else {
                add_action($set['hook'], $hook, self::priority($row, $set));
            }

            foreach (self::BLOCKS[$row] as $block) {
                add_filter('render_block_' . $block, function ($html) use ($row, $set) {
                    return $this->around_block($html, $row, $set);
                });
            }
        }
    }

    public static function defaults()
    {
        $rows = [];
        foreach (self::HOOKS as $row => $hooks) {
            $rows[$row] = [
                'enabled'  => 'product' === $row,
                'hook'     => (string) array_key_first($hooks),
                'priority' => '',
                'block'    => 'product' === $row ? 'after' : 'before',
            ];
        }

        return $rows;
    }

    /** Domyślne miejsca, ale wyłączone: sklep sprzed tej funkcji nie dostaje widgetu bez swojej decyzji. */
    public static function disabled()
    {
        $rows = self::defaults();
        foreach ($rows as $row => $set) {
            $rows[$row]['enabled'] = false;
        }

        return $rows;
    }

    /** Ustawienia wszystkich wierszy, uzupełnione domyślnymi (zapis z kodu albo starsza opcja). */
    public static function settings(array $options)
    {
        $saved = (array) ($options['auto_placement'] ?? []);
        $rows  = [];

        foreach (self::defaults() as $row => $default) {
            $rows[$row] = array_merge($default, (array) ($saved[$row] ?? []));
        }

        return $rows;
    }

    /** Tylko hooki z listy, priorytet 0-MAX_PRIORITY albo pusty, blok "before"/"after". */
    public static function sanitize(array $input)
    {
        $rows = [];

        foreach (self::defaults() as $row => $default) {
            $set      = (array) ($input[$row] ?? []);
            $hook     = (string) ($set['hook'] ?? '');
            $priority = trim((string) ($set['priority'] ?? ''));

            $rows[$row] = [
                'enabled'  => !empty($set['enabled']),
                'hook'     => isset(self::HOOKS[$row][$hook]) ? $hook : $default['hook'],
                'priority' => is_numeric($priority) ? max(0, min(self::MAX_PRIORITY, (int) $priority)) : '',
                'block'    => 'after' === ($set['block'] ?? '') ? 'after' : 'before',
            ];
        }

        return $rows;
    }

    private static function priority($row, array $set)
    {
        return '' === $set['priority'] ? self::HOOKS[$row][$set['hook']] : (int) $set['priority'];
    }

    /** Czy na bieżącej stronie frontu jest włączony wiersz (JS i CSS widgetu mają być już w <head>). */
    public static function active_on_page(array $options)
    {
        $rows = self::settings($options);

        return (!empty($rows['product']['enabled']) && function_exists('is_product') && is_product())
            || (!empty($rows['cart']['enabled']) && function_exists('is_cart') && is_cart())
            || (!empty($rows['checkout']['enabled']) && function_exists('is_checkout') && is_checkout() && !is_order_received_page());
    }

    private function render($row)
    {
        if (!empty($this->rendered[$row])) {
            return '';
        }
        $this->rendered[$row] = true;

        return $this->plugin->shortcode_output();
    }

    private function around_block($html, $row, array $set)
    {
        // Pusty koszyk: klasyczny koszyk też nie wywołuje swoich hooków. Formularz produktu tylko na stronie produktu (nie w siatkach).
        if ('cart' === $row && function_exists('WC') && WC()->cart && WC()->cart->is_empty()) {
            return $html;
        }
        if ('product' === $row && !(function_exists('is_product') && is_product())) {
            return $html;
        }

        $widget = $this->render($row);

        return 'after' === $set['block'] ? $html . $widget : $widget . $html;
    }

    /**
     * Rodzaj strony danego wiersza w tym sklepie, do podpowiedzi w panelu: 'block' albo 'classic'.
     */
    public static function page_type($row)
    {
        if ('product' === $row) {
            // Motyw blokowy z domyślnym szablonem (woocommerce/legacy-template) wywołuje zwykłe hooki, liczy się treść szablonu
            $template = wp_is_block_theme() ? get_block_template(get_stylesheet() . '//single-product') : null;
            $content  = $template ? (string) $template->content : '';

            return (has_block(self::BLOCKS['product'][0], $content) || has_block(self::BLOCKS['product'][1], $content)) ? 'block' : 'classic';
        }

        $page_id = function_exists('wc_get_page_id') ? (int) wc_get_page_id($row) : 0;
        $content = $page_id > 0 ? (string) get_post_field('post_content', $page_id) : '';

        return has_block('woocommerce/' . $row, $content) ? 'block' : 'classic';
    }
}
