<?php
/**
 * Tabela przypadków dla silnika terminów.
 *
 * Uruchomienie: wp eval-file wp-content/plugins/wc-ship-today-notice/tests/cli-schedule-test.php
 * Wymaga pobranych świąt PL na lata 2026-2027 (WooCommerce → Ship Today Notice → Pobierz święta).
 * Nie zmienia zapisanych ustawień: konfiguracje są podawane wprost do WC_STN_Schedule.
 */

if (!defined('ABSPATH')) {
    exit;
}

$tz = new DateTimeZone('Europe/Warsaw');

// Wysyłka pn i śr, zamówienia do 20:00 dzień wcześniej, dostawa następnego dnia doręczeń.
$two_days = [
    'delivery_days'    => [1, 3],
    'cutoff_times'     => [1 => '20:00', 3 => '20:00'],
    'cutoff_offsets'   => [1 => 1, 3 => 1],
    'delivery_offset'  => 1,
    'no_delivery_days' => [7],
    'country_code'     => 'PL',
];

// Tryb 1.x: pn-pt do 14:00 tego samego dnia, bez daty dostawy.
$legacy = [
    'delivery_days'  => [1, 2, 3, 4, 5],
    'cutoff_times'   => [1 => '14:00', 2 => '14:00', 3 => '14:00', 4 => '14:00', 5 => '14:00'],
    'cutoff_offsets' => [],
    'country_code'   => 'PL',
];

// [opis, konfiguracja, teraz, oczekiwane: godzina graniczna, wysyłka, dostawa]
$cases = [
    ['niedziela przed 20:00 (dzień zmiany czasu)', $two_days, '2026-10-25 19:59', '2026-10-25 20:00', '2026-10-26', '2026-10-27'],
    ['niedziela dokładnie 20:00', $two_days, '2026-10-25 20:00', '2026-10-27 20:00', '2026-10-28', '2026-10-29'],
    ['wtorek po 20:00, 1.11 to niedziela', $two_days, '2026-10-27 20:01', '2026-11-01 20:00', '2026-11-02', '2026-11-03'],
    ['środa 11.11 to święto', $two_days, '2026-11-08 21:00', '2026-11-15 20:00', '2026-11-16', '2026-11-17'],
    ['wysyłka 23.12, dostawa po świętach', $two_days, '2026-12-22 19:00', '2026-12-22 20:00', '2026-12-23', '2026-12-28'],
    ['przełom roku', $two_days, '2026-12-29 21:00', '2027-01-03 20:00', '2027-01-04', '2027-01-05'],
    ['6.01.2027 to święto', $two_days, '2027-01-04 10:00', '2027-01-10 20:00', '2027-01-11', '2027-01-12'],
    ['data wykluczona 28.10', $two_days + ['excluded_dates' => '28-10-2026'], '2026-10-25 20:00', '2026-11-01 20:00', '2026-11-02', '2026-11-03'],
    ['ignorowane święto 11.11', $two_days + ['excluded_holiday_dates' => ['2026-11-11']], '2026-11-08 21:00', '2026-11-10 20:00', '2026-11-11', '2026-11-12'],
    ['1.x: piątek przed 14:00', $legacy, '2026-10-16 13:00', '2026-10-16 14:00', '2026-10-16', ''],
    ['1.x: piątek o 14:00', $legacy, '2026-10-16 14:00', '2026-10-19 14:00', '2026-10-19', ''],
    ['1.x: sobota', $legacy, '2026-10-17 10:00', '2026-10-19 14:00', '2026-10-19', ''],
    ['1.x: 11.11 to święto', $legacy, '2026-11-10 15:00', '2026-11-12 14:00', '2026-11-12', ''],
];

// [opis, teraz, tekst, oczekiwany wynik] na konfiguracji $two_days
$texts = [
    ['dziś', '2026-10-25 15:00', 'Zamów {cutoff_when}, dostarczymy {delivery_when}.', 'Zamów dziś do 20:00, dostarczymy we wtorek.'],
    ['jutro', '2026-10-24 12:00', 'Zamów {cutoff_when}, dostarczymy {delivery_when}.', 'Zamów jutro do 20:00, dostarczymy we wtorek.'],
    ['dzień tygodnia', '2026-10-21 21:00', 'Zamów {cutoff_when}, dostarczymy {delivery_when}.', 'Zamów do niedzieli 20:00, dostarczymy we wtorek.'],
    ['popup', '2026-10-21 21:00', 'Zamów do {cutoff_day} {cutoff_time}, a dostaniesz ją {delivery_day}.', 'Zamów do niedzieli 20:00, a dostaniesz ją we wtorek.'],
    ['wysyłka jutro', '2026-10-27 10:00', 'Wyślemy {ship_when} ({ship_date}), dostawa {delivery_day} {delivery_date}.', 'Wyślemy jutro (28.10), dostawa w czwartek 29.10.'],
];

$fail = 0;

foreach ($cases as [$label, $options, $now, $cutoff, $ship, $delivery]) {
    $schedule = new WC_STN_Schedule($options, $tz);
    $slot     = $schedule->slots(1, new DateTimeImmutable($now, $tz))[0] ?? null;
    $got      = $slot ? [$slot['cutoff']->format('Y-m-d H:i'), $slot['ship']->format('Y-m-d'), $slot['delivery'] ? $slot['delivery']->format('Y-m-d') : ''] : ['-', '-', '-'];
    $ok       = $got === [$cutoff, $ship, $delivery];
    $fail    += $ok ? 0 : 1;

    printf("%s  %-42s teraz %s → do %s, wysyłka %s, dostawa %s%s\n", $ok ? 'OK  ' : 'FAIL', $label, $now, $got[0], $got[1], $got[2] ?: '-', $ok ? '' : "  (oczekiwano: {$cutoff}, {$ship}, {$delivery})");
}

foreach ($texts as [$label, $now, $text, $expected]) {
    $schedule = new WC_STN_Schedule($two_days, $tz);
    $now      = new DateTimeImmutable($now, $tz);
    $slot     = $schedule->slots(1, $now)[0];
    $got      = $schedule->render_text($text, $schedule->slot_labels($slot), $now);
    $ok       = $got === $expected;
    $fail    += $ok ? 0 : 1;

    printf("%s  tekst: %-14s %s%s\n", $ok ? 'OK  ' : 'FAIL', $label, $got, $ok ? '' : "  (oczekiwano: {$expected})");
}

echo $fail ? "\n{$fail} błędów\n" : "\nWszystkie przypadki OK\n";
