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
 * @param int                    $count  Ile terminów.
 * @param DateTimeInterface|null $now    Inna chwila niż "teraz" (testy).
 * @param string|null            $method ID instancji metody wysyłki ("flat_rate:3"); null albo metoda bez własnych godzin = globalne.
 * @return array<int,array{cutoff:DateTimeImmutable,ship:DateTimeImmutable,delivery:?DateTimeImmutable}>
 */
function wc_stn_get_slots($count = 1, $now = null, $method = null)
{
    if ($now instanceof DateTime) {
        $now = DateTimeImmutable::createFromMutable($now); // PHP 7.4 nie ma createFromInterface().
    }

    return WC_Ship_Today_Notice::instance()->schedule($method)->slots((int) $count, $now);
}

/**
 * Data dostawy dla daty wysyłki (np. wybranej w kalendarzu w checkoucie).
 *
 * @param string      $ship_ymd Data wysyłki Y-m-d (przyjmuje też Ymd).
 * @param string|null $method   ID instancji metody wysyłki (liczba dni dostawy może być inna dla metody).
 * @return string|null Y-m-d albo null, gdy dostawa jest wyłączona w ustawieniach.
 */
function wc_stn_delivery_for_ship_date($ship_ymd, $method = null)
{
    $ship_ymd = wc_stn_normalize_ymd($ship_ymd);

    if (null === $ship_ymd) {
        return null;
    }

    $schedule = WC_Ship_Today_Notice::instance()->schedule($method);
    $delivery = $schedule->delivery_for($schedule->date($ship_ymd));

    return $delivery ? $delivery->format('Y-m-d') : null;
}

/**
 * Czy w danym dniu nie ma wysyłki z powodu święta albo daty wykluczonej (bez względu na dzień tygodnia).
 */
function wc_stn_is_blocked_date($ymd)
{
    $ymd = wc_stn_normalize_ymd($ymd);

    return null !== $ymd && WC_Ship_Today_Notice::instance()->schedule()->is_blocked($ymd);
}

/**
 * Czy dany dzień jest dniem wysyłki (dzień tygodnia z ustawień, nie święto, nie data wykluczona).
 */
function wc_stn_is_shipping_day($ymd, $method = null)
{
    $ymd = wc_stn_normalize_ymd($ymd);
    if (null === $ymd) {
        return false;
    }

    $schedule = WC_Ship_Today_Notice::instance()->schedule($method);

    return $schedule->is_shipping_day($schedule->date($ymd));
}

/**
 * Y-m-d (przyjmuje też Ymd) albo null, gdy to nie jest poprawna data.
 *
 * @internal
 */
function wc_stn_normalize_ymd($ymd)
{
    if (!is_scalar($ymd) || !preg_match('/^(\d{4})(-?)(\d{2})\2(\d{2})$/', (string) $ymd, $m) || !checkdate((int) $m[3], (int) $m[4], (int) $m[1])) {
        return null;
    }

    return $m[1] . '-' . $m[3] . '-' . $m[4];
}
