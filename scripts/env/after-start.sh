#!/usr/bin/env bash
# wp-env afterStart (kontener cli, cwd /var/www/html). Idempotentny: odpala się przy każdym env:start.
# WooCommerce z listy "plugins" aktywuje sam wp-env; wtyczka jest podpięta przez "mappings", więc aktywujemy ją tu.
set -euo pipefail

# Część opcji WooCommerce obsługuje własnymi filtrami i update_option zwraca false - to nie powód, by przerwać start.
opt() {
    wp option update "$@" >/dev/null || echo "Uwaga: nie ustawiono opcji $1"
}

opt timezone_string Europe/Warsaw
wp language core install pl_PL --activate >/dev/null

wp plugin activate wc-ship-today-notice
wp theme activate storefront

# WooCommerce bez kreatora i podpowiedzi.
opt woocommerce_onboarding_profile '{"skipped":true}' --format=json
opt woocommerce_task_list_hidden yes
opt woocommerce_extended_task_list_hidden yes
opt woocommerce_show_marketplace_suggestions no
opt woocommerce_allow_tracking no
opt woocommerce_coming_soon no
opt woocommerce_default_country PL
opt woocommerce_currency PLN
wp transient delete _wc_activation_redirect >/dev/null || true

echo "wc-ship-today-notice: http://localhost:8894/wp-admin/admin.php?page=wc-ship-today-notice (admin / password)"
