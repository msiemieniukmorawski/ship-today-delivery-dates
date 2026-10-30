<?php
/**
 * Integracja z checkoutem blokowym: skrypt bloku "wc-stn/ship-date" i jego dane (wcSettings "wc-stn-ship-date_data").
 *
 * Ładowane dopiero w woocommerce_blocks_checkout_block_registration, bo interfejs istnieje tylko z WooCommerce Blocks.
 */

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

if (!defined('ABSPATH')) {
    exit;
}

final class WC_STN_Blocks_Integration implements IntegrationInterface
{
    /** @var WC_STN_Checkout_Blocks */
    private $blocks;

    public function __construct(WC_STN_Checkout_Blocks $blocks)
    {
        $this->blocks = $blocks;
    }

    public function get_name()
    {
        return 'wc-stn-ship-date';
    }

    public function initialize()
    {
        // Kafelki mają wygląd z checkout.css (te same klasy co w klasycznym checkoucie)
        wp_enqueue_style('wc-stn-checkout');
    }

    public function get_script_handles()
    {
        return ['wc-stn-ship-date-frontend'];
    }

    public function get_editor_script_handles()
    {
        return [];
    }

    public function get_script_data()
    {
        return $this->blocks->script_data();
    }
}
