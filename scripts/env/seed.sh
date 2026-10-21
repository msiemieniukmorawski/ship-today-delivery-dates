#!/usr/bin/env bash
# Dane testowe (kontener cli): produkt z wysyłką, klasyczny checkout, święta PL na bieżący i następny rok.
set -euo pipefail

if [ -z "$(wp post list --post_type=product --name=stn-produkt-testowy --field=ID)" ]; then
    wp wc product create --user=1 --name="Produkt testowy STN" --slug=stn-produkt-testowy \
        --regular_price=49.99 --status=publish
fi

# Wtyczka obsługuje tylko klasyczny checkout, a nowy WooCommerce tworzy stronę z blokiem.
checkout_id="$(wp option get woocommerce_checkout_page_id)"
wp post update "$checkout_id" --post_content='[woocommerce_checkout]'
cart_id="$(wp option get woocommerce_cart_page_id)"
wp post update "$cart_id" --post_content='[woocommerce_cart]'

# Darmowa wysyłka w strefie "Polska", żeby dało się złożyć zamówienie.
wp eval '
foreach (WC_Shipping_Zones::get_zones() as $z) {
    if ("Polska" === $z["zone_name"]) { return; }
}
$zone = new WC_Shipping_Zone();
$zone->set_zone_name("Polska");
$zone->add_location("PL", "country");
$zone->save();
$zone->add_shipping_method("free_shipping");
echo "Strefa wysyłki Polska utworzona\n";
'

# Ten sam kod co przycisk "Fetch holidays now" (cron wtyczki): święta dla kraju z ustawień, bieżący i następny rok.
wp eval 'do_action("wc_ship_today_notice_cron_sync_holidays");'
wp eval 'global $wpdb; foreach ($wpdb->get_results("SELECT YEAR(holiday_date) y, COUNT(*) n FROM {$wpdb->prefix}wc_ship_today_notice_holidays GROUP BY y") as $r) { echo "Święta {$r->y}: {$r->n}\n"; }'
