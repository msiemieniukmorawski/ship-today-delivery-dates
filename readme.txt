=== Ship Today & Delivery Dates for WooCommerce ===
Contributors: smmarcin
Tags: woocommerce, shipping, countdown, delivery date, cutoff time
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Countdown to your order cutoff time with the next ship and delivery dates, holidays included. Optional ship date choice at checkout.

== Description ==

Show customers how long they have to order so the parcel ships in the next slot: a live countdown to the cutoff time, the ship day and, optionally, the planned delivery day. An optional module adds a ship date choice to the checkout and stores it in the order, emails and admin.

**Notice widget**

* Automatic display on the product page, cart and checkout without a shortcode, with a position and priority for each. New shops show it on the product page, below the add to cart button, right after activation (block pages: above or below the block).
* Shortcode `[stdd_notice]` with two modes: "ships today" (countdown to today's cutoff) and "next slot" (always the nearest cutoff).
* Shipping weekdays with a cutoff time per day. The cutoff can fall 0-6 days before the ship day (e.g. ships on Monday, order by Sunday 8 pm).
* Delivery date: N delivery days after shipping, skipping days without deliveries and public holidays.
* Public holidays from the OpenHolidays API for the current and next year: nationwide holidays, optionally plus those of one region (e.g. DE-BY), with the option to ignore selected holidays, plus a manual list of excluded dates.
* Tokens such as `{countdown}`, `{cutoff_when}`, `{ship_when}`, `{delivery_when}` with weekday forms in the shop language (e.g. Polish "do niedzieli 20:00", "we wtorek"; German "bis Sonntag 20:00", "am Dienstag").
* Works with full-page cache: the next 42 days of slots are in the HTML and the browser switches slots itself. Optional cache purge (LiteSpeed Cache or your own hook) at each cutoff time.
* "Style" section: colors, border, font and alignment of the widget with a live preview, output as CSS variables.
* Preview of the next 5 slots in the settings and a test mode (`?stdd_now=2026-12-20T19:59`) for shop managers.

**Ship date at checkout (optional, off by default)**

* Tiles with the next ship dates in the classic checkout, validated again when the order is placed (a slot whose cutoff has passed is rejected).
* Stored in the order (HPOS compatible), shown on the thank-you page, in emails, in My Account and in the order admin, where the date can be changed.
* "Shipping" column with sorting and a filter in the orders list.
* Not required for local pickup.

**For developers**

Functions `stdd_get_slots()`, `stdd_delivery_for_ship_date()`, `stdd_is_blocked_date()`, `stdd_is_shipping_day()`, filters for the current time, shipping days, cutoff times, slots and texts, the `stdd_purge_cache` action and `window.StddNotice.init()` in JavaScript. The widget's JS and CSS load only on pages that contain the widget; use the `stdd_load_assets` filter to force them, e.g. for content loaded with AJAX.

**Language note**

All plugin texts are translatable (English source): the admin, weekday names and their forms, "today"/"tomorrow", checkout tiles, validation messages and email rows. Polish and German translations are included. The widget texts are editable in the settings and can be translated with WPML. In the "after cutoff" and "not a shipping day" texts use `{ship_when}` (e.g. "We will ship {ship_when}.") rather than a fixed "tomorrow".

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP via Plugins → Add New → Upload Plugin. WooCommerce must be active.
2. Activate the plugin. It creates a table for public holidays and downloads them on the next WP-Cron run.
3. Go to WooCommerce → Settings → Delivery dates. Set the shipping weekdays and cutoff times.
4. Click "Fetch holidays now" at the bottom of the settings page to download holidays right away.
5. Optionally set the delivery days, then save and check the "Next slots" preview.
6. The widget already shows on product pages. Change it in "Automatic display" (product page, cart, checkout), or add `[stdd_notice]` anywhere (e.g. the header or a popup).
7. Optionally enable the ship date choice in the Checkout section.

== Frequently Asked Questions ==

= I installed the plugin but see no message. =

After activation the widget shows on product pages, below the add to cart button. To show it elsewhere, use WooCommerce → Settings → Delivery dates → "Automatic display" for the product page, cart or checkout, or add the `[stdd_notice]` shortcode.

= Does it work with the block-based checkout? =

Yes, with WooCommerce 10.1 or newer. In both checkouts the customer picks a date from tiles. In the block-based checkout the tiles are the "Ship date" block: the plugin adds it once below the shipping options of the checkout page, and you can move it, remove it or add it again (search for "Ship date") in the checkout page editor like other checkout blocks. The date is validated on the server and saved to the order like in the classic checkout. The selected date is also shown as a row in the order summary (the "Ship date summary row" block, added once below the shipping row; it follows the "Row with the date in the order summary" setting). Without the block (or with older WooCommerce versions) the block-based checkout shows no date choice and orders are placed without a ship date. The notice widget `[stdd_notice]` works independently of the checkout type.

= The block checkout does not show the ship date tiles. =

Check WooCommerce → Settings → Delivery dates → Checkout: it tells you whether the "Ship date" block is on the checkout page. If the checkout is part of a block theme template (Site Editor) or you removed the block, add the "Ship date" block inside the checkout form in the editor. The block is shown only when the cart needs shipping and local pickup is not selected.

= Can I change the texts on the date tiles and in the checkout messages? =

Yes, in WooCommerce → Settings → Delivery dates → Checkout → "Texts": the large text and the second line of the tiles (with tokens such as `{delivery_short}`, `{ship_short}`, `{ship_when}`, `{cutoff_when}`; an empty second line hides it), the labels of the ship and delivery date rows in emails, and the four checkout messages (`{field}`, `{slot}`, `{next_slot}`). Empty fields use the default, translated texts.

= Can shipping methods have different cutoff times? =

Yes. In WooCommerce → Settings → Delivery dates → "Shipping methods" every method of your WooCommerce shipping zones (including methods from other plugins, e.g. parcel lockers) can get its own shipping days, cutoff times and delivery days, e.g. parcel lockers until 15:00 and courier until 13:00. Both checkouts then show the dates of the selected method and recalculate them when the customer changes the method. Methods without own times use the main table. For the notice widget use `[stdd_notice method="flat_rate:3"]`.

= The checkout module is enabled, but I don't see the date field. =

With the default position "shortcode only" you have to place `[stdd_checkout]` inside the checkout form. Without it the field is not shown and orders are placed without a ship date. You can also choose an automatic position in the settings.

= Where can I get help? =

Bugs and questions about features: GitHub Issues (https://github.com/msiemieniukmorawski/ship-today-delivery-dates/issues), free, with no guaranteed response time. Configuration for your store, matching your theme, carrier integrations and custom changes are paid: https://ms-m.pl

= Does it work with page caching? =

Yes. The widget does not use AJAX: the schedule for the next 42 days is embedded in the page, so a cached page keeps showing the correct countdown and switches to the next slot after the cutoff. If LiteSpeed Cache is active (or you hook into `stdd_purge_cache`), the cache is also purged at each cutoff time and after saving the settings, so the text rendered on the server is fresh too.

= Where do the public holidays come from? =

From the OpenHolidays API, for the country and language set in the settings (default: PL), for the current and the next year. They are downloaded right after activation, about every 4 months, after you change the country, language or region, and when you click "Fetch holidays now". If the service is unreachable or returns no data, the holidays already saved are kept and the download is retried later. You can ignore single holidays (shipping possible on that day) and add your own excluded dates.

= What about regional holidays? =

By default only nationwide holidays are used. To include the holidays of one region, enter its ISO 3166-2 code in the "Region" field (e.g. DE-BY for Bavaria). Holidays of parent regions are included too. Holidays that last several days are saved as separate days.

= Who can change the settings? =

Users with the `manage_woocommerce` capability (Administrator and Shop Manager).

= How can I test the widget at a different time? =

As a shop manager add `?stdd_now=2026-12-20T19:59` to any URL. The widget and the checkout validation then use that time.

= What happens to my data when I delete the plugin? =

Deleting the plugin removes its settings, the holiday table and its scheduled events. Ship and delivery dates stored in orders stay in the orders.

== External services ==

This plugin connects to the OpenHolidays API (https://www.openholidaysapi.org/), an open data service with public holidays, to know on which days there is no shipping or delivery.

* When: by WP-Cron right after activation and then about every 4 months (with a retry after 6 hours if a download fails), when an administrator saves the settings with a changed country, language or region, and when an administrator clicks "Fetch holidays now". The storefront never calls the service. The automatic downloads can be turned off with the "Download holidays automatically" option; then the service is called only when an administrator clicks "Fetch holidays now".
* What is sent: a GET request to `https://openholidaysapi.org/PublicHolidays` with the country code and language code from the settings and the date range (start and end of the year), once for the current and once for the next year. No customer or order data is sent. As with any request made by WordPress, the service receives your server's IP address and the WordPress user agent, which contains your site URL.
* Provider: OpenHolidays API project (https://www.openholidaysapi.org/), operated by STÜBER SYSTEMS GmbH, Berlin. The service does not publish separate terms of use. Privacy policy: https://www.stueber.de/en/legal/privacy.php. Legal notice: https://www.stueber.de/en/legal/imprint.php. The holiday data is published under the Open Database License (ODbL 1.0): https://github.com/openpotato/openholidaysapi.data

== Screenshots ==

1. The notice widget with the countdown to today's cutoff time.
2. The "next slot" mode with the delivery date.
3. Ship date tiles in the classic checkout.
4. Shipping weekdays, cutoff times and the next slots preview in the settings.
5. The "Style" section with the live preview.
6. Ship date column and filter in the orders list.
7. Automatic display of the widget: page, position and priority.
8. The Checkout section: tiles, texts and dates in orders.
9. The Shipping methods section: own cutoff times and upcoming dates for a method.

== Changelog ==

= 1.0.0 =
* First release.
