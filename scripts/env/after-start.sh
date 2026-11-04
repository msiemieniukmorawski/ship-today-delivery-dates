#!/usr/bin/env bash
# wp-env afterStart (cli container, cwd /var/www/html). Idempotent, runs on every env:start.
# wp-env activates WooCommerce from "plugins". The plugin comes from "mappings", so it is activated here.
set -euo pipefail

# WooCommerce filters some options and update_option returns false. That must not stop the start.
opt() {
    wp option update "$@" >/dev/null || echo "Warning: option $1 not set"
}

opt timezone_string Europe/Warsaw
wp language core install pl_PL --activate >/dev/null

wp plugin activate ship-today-delivery-dates
wp theme activate storefront

# WooCommerce without the setup wizard and suggestions.
opt woocommerce_onboarding_profile '{"skipped":true}' --format=json
opt woocommerce_task_list_hidden yes
opt woocommerce_extended_task_list_hidden yes
opt woocommerce_show_marketplace_suggestions no
opt woocommerce_allow_tracking no
opt woocommerce_coming_soon no
opt woocommerce_default_country PL
opt woocommerce_currency PLN
wp transient delete _wc_activation_redirect >/dev/null || true

echo "ship-today-delivery-dates: http://localhost:8894/wp-admin/admin.php?page=wc-settings&tab=stdd_notice (admin / password)"
