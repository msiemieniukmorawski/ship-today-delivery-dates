#!/usr/bin/env bash
# Test data (cli container): a shippable product, classic checkout, PL holidays for the current and next year.
set -euo pipefail

if [ -z "$(wp post list --post_type=product --name=stn-produkt-testowy --field=ID)" ]; then
    wp wc product create --user=1 --name="Produkt testowy STN" --slug=stn-produkt-testowy \
        --regular_price=49.99 --status=publish
fi

# New WooCommerce installs create block pages, the seed uses the classic checkout.
checkout_id="$(wp option get woocommerce_checkout_page_id)"
wp post update "$checkout_id" --post_content='[woocommerce_checkout]'
cart_id="$(wp option get woocommerce_cart_page_id)"
wp post update "$cart_id" --post_content='[woocommerce_cart]'

# Free shipping in a "Polska" zone, so an order can be placed.
wp eval '
foreach (WC_Shipping_Zones::get_zones() as $z) {
    if ("Polska" === $z["zone_name"]) { return; }
}
$zone = new WC_Shipping_Zone();
$zone->set_zone_name("Polska");
$zone->add_location("PL", "country");
$zone->save();
$zone->add_shipping_method("free_shipping");
echo "Shipping zone Polska created\n";
'

# Cash on delivery, so an order can be placed from the browser.
wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Płatność przy odbiorze"}' --format=json >/dev/null

# Pretty permalinks. --hard writes .htaccess for Apache in the container.
wp rewrite structure '/%postname%/' --hard >/dev/null

# Same code as the "Fetch holidays now" button: current and next year for the country from settings.
wp eval 'do_action("wc_ship_today_notice_cron_sync_holidays");'
wp eval 'global $wpdb; foreach ($wpdb->get_results("SELECT YEAR(holiday_date) y, COUNT(*) n FROM {$wpdb->prefix}wc_ship_today_notice_holidays GROUP BY y") as $r) { echo "Holidays {$r->y}: {$r->n}\n"; }'
