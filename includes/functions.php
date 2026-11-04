<?php
/**
 * Public API for other plugins and themes.
 *
 * Dates are Y-m-d in the shop timezone.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Next slots whose cutoff has not passed.
 *
 * @param int                    $count  Number of slots.
 * @param DateTimeInterface|null $now    Another moment than "now" (tests).
 * @param string|null            $method Shipping method instance ID ("flat_rate:3"). Null or a method without its own hours = global.
 * @return array<int,array{cutoff:DateTimeImmutable,ship:DateTimeImmutable,delivery:?DateTimeImmutable}>
 */
function stdd_get_slots($count = 1, $now = null, $method = null)
{
    if ($now instanceof DateTime) {
        $now = DateTimeImmutable::createFromMutable($now); // PHP 7.4 has no createFromInterface().
    }

    return STDD_Plugin::instance()->schedule($method)->slots((int) $count, $now);
}

/**
 * Delivery date for a ship date (e.g. picked in a checkout calendar).
 *
 * @param string      $ship_ymd Ship date Y-m-d (also accepts Ymd).
 * @param string|null $method   Shipping method instance ID (a method can have its own delivery days).
 * @return string|null Y-m-d, or null when delivery is turned off.
 */
function stdd_delivery_for_ship_date($ship_ymd, $method = null)
{
    $ship_ymd = stdd_normalize_ymd($ship_ymd);

    if (null === $ship_ymd) {
        return null;
    }

    $schedule = STDD_Plugin::instance()->schedule($method);
    $delivery = $schedule->delivery_for($schedule->date($ship_ymd));

    return $delivery ? $delivery->format('Y-m-d') : null;
}

/**
 * Whether shipping is blocked on a day by a holiday or an excluded date, regardless of the weekday.
 */
function stdd_is_blocked_date($ymd)
{
    $ymd = stdd_normalize_ymd($ymd);

    return null !== $ymd && STDD_Plugin::instance()->schedule()->is_blocked($ymd);
}

/**
 * Whether a day is a ship day (weekday from settings, not a holiday, not an excluded date).
 */
function stdd_is_shipping_day($ymd, $method = null)
{
    $ymd = stdd_normalize_ymd($ymd);
    if (null === $ymd) {
        return false;
    }

    $schedule = STDD_Plugin::instance()->schedule($method);

    return $schedule->is_shipping_day($schedule->date($ymd));
}

/**
 * Y-m-d (also accepts Ymd), or null if it is not a valid date.
 *
 * @internal
 */
function stdd_normalize_ymd($ymd)
{
    if (!is_scalar($ymd) || !preg_match('/^(\d{4})(-?)(\d{2})\2(\d{2})$/', (string) $ymd, $m) || !checkdate((int) $m[3], (int) $m[4], (int) $m[1])) {
        return null;
    }

    return $m[1] . '-' . $m[3] . '-' . $m[4];
}
