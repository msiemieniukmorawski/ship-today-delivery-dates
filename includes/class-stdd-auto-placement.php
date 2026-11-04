<?php
/**
 * Automatic widget display without a shortcode: product page, cart, checkout.
 *
 * Three independent rows in settings (key "auto_placement" in the main option). New shops: product page on, below the add to cart
 * button, cart and checkout off.
 * - classic pages: a WooCommerce hook from the list and a priority (empty = default for the hook),
 * - blocks (cart, checkout, add to cart form in a block template): above or below the block through render_block_{name},
 *   because these pages do not run the classic template hooks. A page has one or the other, so both are registered.
 * Always renders through STDD_Plugin::shortcode_output(), independent of the ship date module.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class STDD_Auto_Placement
{
    /** Highest priority accepted from the form. */
    const MAX_PRIORITY = 999;

    /** Blocks the widget can stand next to (product: old and new form block). */
    const BLOCKS = [
        'product'  => ['woocommerce/add-to-cart-form', 'woocommerce/add-to-cart-with-options'],
        'cart'     => ['woocommerce/cart'],
        'checkout' => ['woocommerce/checkout'],
    ];

    /** Hooks with default priorities (row => hook => priority). The first one is the default hook. Values follow wc-template-hooks.php. */
    const HOOKS = [
        'product'  => [
            'woocommerce_after_add_to_cart_form'  => 10,
            'woocommerce_before_add_to_cart_form' => 10,
            'woocommerce_single_product_summary'  => 25, // price 10, description 20, button 30
        ],
        'cart'     => [
            'woocommerce_before_cart'         => 20, // notices 10
            'woocommerce_before_cart_totals'  => 10,
            'woocommerce_proceed_to_checkout' => 5, // button 20
        ],
        'checkout' => [
            'woocommerce_before_checkout_form'         => 20, // notices, login, coupon 10
            'woocommerce_checkout_before_order_review' => 10,
            'woocommerce_review_order_before_submit'   => 10,
        ],
    ];

    /** @var STDD_Plugin */
    private $plugin;

    /** @var array<string,bool> Rows already rendered in this request (one widget per row). */
    private $rendered = [];

    public function __construct(STDD_Plugin $plugin)
    {
        $this->plugin = $plugin;

        foreach (self::settings($plugin->get_options()) as $row => $set) {
            if (empty($set['enabled'])) {
                continue;
            }

            $hook = function () use ($row) {
                echo $this->render($row); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML from shortcode_output().
            };

            if ('product' === $row) {
                // The form block runs the woocommerce_*_add_to_cart_form hooks from inside, so with that block only the block position
                // applies. The template is known only when the page is requested.
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

    /** Settings of all rows, filled with defaults. */
    public static function settings(array $options)
    {
        $saved = (array) ($options['auto_placement'] ?? []);
        $rows  = [];

        foreach (self::defaults() as $row => $default) {
            $rows[$row] = array_merge($default, (array) ($saved[$row] ?? []));
        }

        return $rows;
    }

    /** Listed hooks only, priority 0-MAX_PRIORITY or empty, block "before" or "after". */
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

    /** Whether a row is on for the current page (the widget JS and CSS then go to <head>). */
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
        // Classic empty cart doesn't run its hooks either. The form block counts only on the product page, not in grids.
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
     * Page type of a row in this shop, for the panel: 'block' or 'classic'.
     */
    public static function page_type($row)
    {
        if ('product' === $row) {
            // A block theme with the default template (woocommerce/legacy-template) runs the classic hooks, so check the template content
            $template = wp_is_block_theme() ? get_block_template(get_stylesheet() . '//single-product') : null;
            $content  = $template ? (string) $template->content : '';

            return (has_block(self::BLOCKS['product'][0], $content) || has_block(self::BLOCKS['product'][1], $content)) ? 'block' : 'classic';
        }

        $page_id = function_exists('wc_get_page_id') ? (int) wc_get_page_id($row) : 0;
        $content = $page_id > 0 ? (string) get_post_field('post_content', $page_id) : '';

        return has_block('woocommerce/' . $row, $content) ? 'block' : 'classic';
    }
}
