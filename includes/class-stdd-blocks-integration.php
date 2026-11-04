<?php
/**
 * Block checkout integration: script of the "stdd/ship-date" block and its data (wcSettings "stdd-ship-date_data").
 *
 * Loaded in woocommerce_blocks_checkout_block_registration because the interface exists only with WooCommerce Blocks.
 */

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

if (!defined('ABSPATH')) {
    exit;
}

final class STDD_Blocks_Integration implements IntegrationInterface
{
    /** @var STDD_Checkout_Blocks */
    private $blocks;

    public function __construct(STDD_Checkout_Blocks $blocks)
    {
        $this->blocks = $blocks;
    }

    public function get_name()
    {
        return 'stdd-ship-date';
    }

    public function initialize()
    {
        // Tiles use the same classes as the classic checkout
        wp_enqueue_style('stdd-checkout');
    }

    public function get_script_handles()
    {
        return ['stdd-ship-date-frontend'];
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
