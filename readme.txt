=== WooCommerce Ship Today Notice ===
Contributors: TODO
Tags: woocommerce, shipping, countdown, delivery date, cutoff time
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Countdown to your order cutoff time with the next ship and delivery dates, holidays included. Optional ship date choice at checkout.

== Description ==

Show customers how long they have to order so the parcel ships in the next slot: a live countdown to the cutoff time, the ship day and, optionally, the planned delivery day. An optional module adds a ship date choice to the checkout and stores it in the order, emails and admin.

**Notice widget**

* Shortcode `[ship_today_notice]` with two modes: "ships today" (countdown to today's cutoff) and "next slot" (always the nearest cutoff).
* Shipping weekdays with a cutoff time per day. The cutoff can fall 0-6 days before the ship day (e.g. ships on Monday, order by Sunday 8 pm).
* Delivery date: N delivery days after shipping, skipping days without deliveries and public holidays.
* Public holidays from the OpenHolidays API for the current and next year: nationwide holidays, optionally plus those of one region (e.g. DE-BY), with the option to ignore selected holidays, plus a manual list of excluded dates.
* Tokens such as `{countdown}`, `{cutoff_when}`, `{ship_when}`, `{delivery_when}` with weekday forms in the shop language (e.g. Polish "do niedzieli 20:00", "we wtorek"; German "bis Sonntag 20:00", "am Dienstag").
* Works with full-page cache: the next 42 days of slots are in the HTML and the browser switches slots itself. Optional cache purge (LiteSpeed Cache or your own hook) at each cutoff time.
* "Style" tab: colors, border, font and alignment of the widget with a live preview, output as CSS variables.
* Preview of the next 5 slots in the settings and a test mode (`?stn_now=2026-12-20T19:59`) for shop managers.

**Ship date at checkout (optional, off by default)**

* Tiles with the next ship dates in the classic checkout, validated again when the order is placed (a slot whose cutoff has passed is rejected).
* Stored in the order (HPOS compatible), shown on the thank-you page, in emails, in My Account and in the order admin, where the date can be changed.
* "Shipping" column with sorting and a filter in the orders list.
* Not required for local pickup.
* Optional switch that disables Iconic Delivery Slots in the cart and checkout during a migration period (tested with Iconic 2.13.0; it only works when you turn it on).

**For developers**

Functions `wc_stn_get_slots()`, `wc_stn_delivery_for_ship_date()`, `wc_stn_is_blocked_date()`, `wc_stn_is_shipping_day()`, filters for the current time, shipping days, cutoff times, slots and texts, the `wc_ship_today_notice_purge_cache` action and `window.WcShipTodayNotice.init()` in JavaScript. The widget's JS and CSS load only on pages that contain the widget; use the `wc_ship_today_notice_load_assets` filter to force them, e.g. for content loaded with AJAX.

**Language note**

All plugin texts are translatable (English source): the admin, weekday names and their forms, "today"/"tomorrow", checkout tiles, validation messages and email rows. Polish and German translations are included. The widget texts are editable in the settings and can be translated with WPML. In the "after cutoff" and "not a shipping day" texts use `{ship_when}` (e.g. "We will ship {ship_when}.") rather than a fixed "tomorrow".

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP via Plugins → Add New → Upload Plugin. WooCommerce must be active.
2. Activate the plugin. It creates a table for public holidays and downloads them on the next WP-Cron run.
3. Go to WooCommerce → Ship Today Notice. Set the shipping weekdays and cutoff times.
4. Click "Fetch holidays now" at the bottom of the settings page to download holidays right away.
5. Optionally set the delivery days, then save and check the "Next slots" preview.
6. Add `[ship_today_notice]` to the product page, cart, header or a popup.
7. Optionally enable the ship date choice in the Checkout tab.

== Frequently Asked Questions ==

= Does it work with the block-based checkout? =

Yes, with WooCommerce 10.1 or newer. In both checkouts the customer picks a date from tiles. In the block-based checkout the tiles are the "Ship date" block: the plugin adds it once below the shipping options of the checkout page, and you can move it, remove it or add it again (search for "Ship date") in the checkout page editor like other checkout blocks. The date is validated on the server and saved to the order like in the classic checkout. The selected date is also shown as a row in the order summary (the "Ship date summary row" block, added once below the shipping row; it follows the "Row with the date in the order summary" setting). Without the block (or with older WooCommerce versions) the block-based checkout shows no date choice and orders are placed without a ship date. The notice widget `[ship_today_notice]` works independently of the checkout type.

= The block checkout does not show the ship date tiles. =

Check WooCommerce → Ship Today Notice → Checkout: it tells you whether the "Ship date" block is on the checkout page. If the checkout is part of a block theme template (Site Editor) or you removed the block, add the "Ship date" block inside the checkout form in the editor. The block is shown only when the cart needs shipping and local pickup is not selected.

= Can I change the texts on the date tiles and in the checkout messages? =

Yes, in WooCommerce → Ship Today Notice → Checkout → "Texts": the large text and the second line of the tiles (with tokens such as `{delivery_short}`, `{ship_short}`, `{ship_when}`, `{cutoff_when}`; an empty second line hides it), the labels of the ship and delivery date rows in emails, and the four checkout messages (`{field}`, `{slot}`, `{next_slot}`). Empty fields use the default, translated texts.

= Can shipping methods have different cutoff times? =

Yes. In WooCommerce → Ship Today Notice → "Shipping methods" every method of your WooCommerce shipping zones (including methods from other plugins, e.g. parcel lockers) can get its own shipping days, cutoff times and delivery days, e.g. parcel lockers until 15:00 and courier until 13:00. Both checkouts then show the dates of the selected method and recalculate them when the customer changes the method. Methods without own times use the main table. For the notice widget use `[ship_today_notice method="flat_rate:3"]`.

= The checkout module is enabled, but I don't see the date field. =

With the default position "shortcode only" you have to place `[ship_today_checkout]` inside the checkout form. Without it the field is not shown and orders are placed without a ship date. You can also choose an automatic position in the settings.

= Does it work with page caching? =

Yes. The widget does not use AJAX: the schedule for the next 42 days is embedded in the page, so a cached page keeps showing the correct countdown and switches to the next slot after the cutoff. If LiteSpeed Cache is active (or you hook into `wc_ship_today_notice_purge_cache`), the cache is also purged at each cutoff time and after saving the settings, so the text rendered on the server is fresh too.

= Where do the public holidays come from? =

From the OpenHolidays API, for the country and language set in the settings (default: PL), for the current and the next year. They are downloaded right after activation, about every 4 months, after you change the country, language or region, and when you click "Fetch holidays now". If the service is unreachable or returns no data, the holidays already saved are kept and the download is retried later. You can ignore single holidays (shipping possible on that day) and add your own excluded dates.

= What about regional holidays? =

By default only nationwide holidays are used. To include the holidays of one region, enter its ISO 3166-2 code in the "Region" field (e.g. DE-BY for Bavaria). Holidays of parent regions are included too. Holidays that last several days are saved as separate days.

= Who can change the settings? =

Users with the `manage_woocommerce` capability (Administrator and Shop Manager).

= How can I test the widget at a different time? =

As a shop manager add `?stn_now=2026-12-20T19:59` to any URL. The widget and the checkout validation then use that time.

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
5. The "Style" tab with the live preview.
6. Ship date column and filter in the orders list.

== Changelog ==

= 2.4.1 =
* Changed: the plugin name is now WooCommerce Ship Today Notice (Polish admin: Ship Today Notice).

= 2.4.0 =
* New: cutoff times per shipping method. In the "Shipping methods" settings each method of every shipping zone can have its own shipping weekdays, cutoff times and delivery days, with a preview of the next slots. Without them the general schedule applies.
* New: both checkouts show tiles, validate and save the date for the selected shipping method; changing the method recalculates the tiles.
* New: widget attribute method="flat_rate:3" for the schedule of one shipping method.
* New: ship date row in the order summary of the block-based checkout, updated live, movable in the editor. Works with older checkout page layouts too.
* New: optional $method argument in wc_stn_get_slots(), wc_stn_delivery_for_ship_date() and wc_stn_is_shipping_day(); the schedule and text filters get the method ID as the last argument.

= 2.3.0 =
* New: in the block-based checkout the date is picked from tiles, like in the classic checkout, in a "Ship date" block that can be moved, removed and added again in the checkout page editor. It replaces the 2.2.0 select field.
* New: tile texts in the settings (Ship date at checkout → Texts): the big text and the second line with tokens, including {ship_short} and {delivery_short}; an empty second line hides it.
* New: custom labels for the "Ship date" and "Planned delivery" rows (emails, thank-you page, My Account) and for the checkout messages.
* New: admin notice when the block checkout has no ship date block.
* Changed: on upgrade from 2.2.0 the ship date block is added once to the checkout page below the shipping methods. Until then (and without the block) orders go through without a date. Orders placed with the 2.2.0 select keep their dates.

= 2.2.0 =
* New: ship date choice in the block-based checkout (WooCommerce 10.1+), validated by the Store API and stored like in the classic checkout.
* New: "Style" tab with a live preview: colors before and after the cutoff, countdown color, border, radius, padding, font and alignment.
* New: all texts are translatable; complete Polish and new German translations.
* New: optional region for regional holidays (e.g. DE-BY); regional holidays are no longer applied nationwide. Multi-day holidays are stored day by day.
* New: switches for optional features (order date rows, order admin date editing, orders list column, showing dates when the module is off, automatic holiday download, cache purge at the cutoff, test mode, WPML).
* Changed: the default after-cutoff and non-shipping-day texts use {ship_when} ("We will ship on Monday" on a Friday evening instead of "tomorrow"). Saved texts exactly equal to the old defaults are updated on upgrade; custom texts are kept.
* Changed: the widget JS and CSS load only on pages with the widget. Use the wc_ship_today_notice_load_assets filter for widgets loaded via AJAX.
* Changed: holidays are downloaded right after activation and after changing the country, language or region; an empty or failed API response no longer deletes stored holidays.
* Changed: no ship date required for local pickup. Shop managers can save the settings. HPOS and block checkout compatibility declared. Requires WordPress 6.8.
* Fixed: with the checkout module on and the field not rendered (shortcode missing or outside the form) all orders with shipping were blocked.
* Fixed: invalid dates in the order admin, early translation loading notice, double space before the countdown, cutoff time field cut off in 12-hour browsers, settings validation.

= 2.1.0 =
* New: "Ship date at checkout" module: tiles, validation when the order is placed, stored in the order, emails, order admin with date editing, orders list column with sorting and a filter (HPOS and legacy storage).
* New: switch that disables Iconic Delivery Slots in the cart and checkout (migration period).

= 2.0.0 =
* New: "next slot" mode and cutoff times before the ship day.
* New: delivery date (delivery days after shipping, days without deliveries, holidays).
* New: date tokens with Polish day declension, shortcode attributes `count`, `text`, `mode`, `class`.
* New: countdown with days and automatic switch to the next slot after the cutoff.
* Changed: schedule embedded in the HTML instead of AJAX. Removed the `wc_ship_today_notice_get` endpoint, whose nonce returned 403 on cached pages after 12-24 h.
* Changed: holidays for the current and next year, ignored holidays stored by date (previously by ID); settings migration from 1.x.
* New: next 5 slots preview in the settings, test mode `?stn_now=`.
* New: `wc_stn_*` functions, filters, schedule test table.
* New: LiteSpeed Cache purge at the cutoff time and after saving the settings.

= 1.1.0 =
* New: cutoff time per weekday, excluded dates as a text area.
* New: list of downloaded holidays with the option to ignore them.
* New: checkboxes for the after-cutoff and non-shipping-day texts, widget visibility settings.
* New: Polish translation.

= 1.0.0 =
* First version: countdown, OpenHolidays API, settings in WooCommerce, WPML support.

== Upgrade Notice ==

= 2.4.1 =
New plugin name: WooCommerce Ship Today Notice. No functional changes.

= 2.4.0 =
Cutoff times per shipping method and a ship date row in the block checkout order summary. Nothing changes until you set a schedule for a shipping method.

= 2.3.0 =
Block checkout: the date select becomes a movable "Ship date" block with tiles, added on the first admin visit. In a theme template (Site Editor) add the block yourself. New settings for tile texts, order row labels and checkout messages.

= 2.2.0 =
Requires WordPress 6.8. Block checkout support, Style tab, German translation. Old "tomorrow" texts are updated. Widget loaded via AJAX (e.g. popup): add the wc_ship_today_notice_load_assets filter.

= 2.1.0 =
Adds an optional ship date choice at checkout. It is off by default; when you enable it with the "shortcode only" position, place [ship_today_checkout] inside the checkout form.
