<?php
/**
 * Publiczne API dla innych wtyczek i motywu.
 *
 * Daty jako Y-m-d w strefie czasowej sklepu.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Najbliższe terminy, których godzina graniczna jeszcze nie minęła.
 *
 * @param int                    $count Ile terminów.
 * @param DateTimeInterface|null $now   Inna chwila niż "teraz" (testy).
 * @return array<int,array{cutoff:DateTimeImmutable,ship:DateTimeImmutable,delivery:?DateTimeImmutable}>
 */
function wc_stn_get_slots($count = 1, $now = null)
{
    if ($now instanceof DateTime) {
        $now = DateTimeImmutable::createFromMutable($now); // PHP 7.4 nie ma createFromInterface().
    }

    return WC_Ship_Today_Notice::instance()->schedule()->slots((int) $count, $now);
}

/**
 * Data dostawy dla daty wysyłki (np. wybranej w kalendarzu w checkoucie).
 *
 * @param string $ship_ymd Data wysyłki Y-m-d (przyjmuje też Ymd).
 * @return string|null Y-m-d albo null, gdy dostawa jest wyłączona w ustawieniach.
 */
function wc_stn_delivery_for_ship_date($ship_ymd)
{
    $ship_ymd = (string) $ship_ymd;

    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ship_ymd, $m)) {
        $ship_ymd = $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ship_ymd)) {
        return null;
    }

    $schedule = WC_Ship_Today_Notice::instance()->schedule();
    $delivery = $schedule->delivery_for($schedule->date($ship_ymd));

    return $delivery ? $delivery->format('Y-m-d') : null;
}

/**
 * Czy w danym dniu nie ma wysyłki z powodu święta albo daty wykluczonej (bez względu na dzień tygodnia).
 */
function wc_stn_is_blocked_date($ymd)
{
    return WC_Ship_Today_Notice::instance()->schedule()->is_blocked((string) $ymd);
}

/**
 * Czy dany dzień jest dniem wysyłki (dzień tygodnia z ustawień, nie święto, nie data wykluczona).
 */
function wc_stn_is_shipping_day($ymd)
{
    $schedule = WC_Ship_Today_Notice::instance()->schedule();

    return $schedule->is_shipping_day($schedule->date((string) $ymd));
}
