<?php
/**
 * Case table for the slot engine.
 *
 * Run: wp eval-file wp-content/plugins/wc-ship-today-notice/tests/cli-schedule-test.php
 * Needs PL holidays for 2026-2027 and the pl_PL locale: templates and expected texts are Polish because they check the pl_PL translation.
 * Does not change saved settings, configurations go straight to WC_STN_Schedule.
 */

if (!defined('ABSPATH')) {
    exit;
}

$tz = new DateTimeZone('Europe/Warsaw');

// Ship Mon and Wed, orders until 20:00 the day before, delivery on the next delivery day.
$two_days = [
    'delivery_days'    => [1, 3],
    'cutoff_times'     => [1 => '20:00', 3 => '20:00'],
    'cutoff_offsets'   => [1 => 1, 3 => 1],
    'delivery_offset'  => 1,
    'no_delivery_days' => [7],
    'country_code'     => 'PL',
];

// 1.x mode: Mon-Fri until 14:00 the same day, no delivery date.
$legacy = [
    'delivery_days'  => [1, 2, 3, 4, 5],
    'cutoff_times'   => [1 => '14:00', 2 => '14:00', 3 => '14:00', 4 => '14:00', 5 => '14:00'],
    'cutoff_offsets' => [],
    'country_code'   => 'PL',
];

// [label, config, now, expected: cutoff, ship, delivery]
$cases = [
    ['Sunday before 20:00 (DST change day)', $two_days, '2026-10-25 19:59', '2026-10-25 20:00', '2026-10-26', '2026-10-27'],
    ['Sunday exactly 20:00', $two_days, '2026-10-25 20:00', '2026-10-27 20:00', '2026-10-28', '2026-10-29'],
    ['Tuesday after 20:00, Nov 1 is a Sunday', $two_days, '2026-10-27 20:01', '2026-11-01 20:00', '2026-11-02', '2026-11-03'],
    ['Wednesday Nov 11 is a holiday', $two_days, '2026-11-08 21:00', '2026-11-15 20:00', '2026-11-16', '2026-11-17'],
    ['ship Dec 23, delivery after Christmas', $two_days, '2026-12-22 19:00', '2026-12-22 20:00', '2026-12-23', '2026-12-28'],
    ['turn of the year', $two_days, '2026-12-29 21:00', '2027-01-03 20:00', '2027-01-04', '2027-01-05'],
    ['Jan 6 2027 is a holiday', $two_days, '2027-01-04 10:00', '2027-01-10 20:00', '2027-01-11', '2027-01-12'],
    ['excluded date Oct 28', $two_days + ['excluded_dates' => '28-10-2026'], '2026-10-25 20:00', '2026-11-01 20:00', '2026-11-02', '2026-11-03'],
    ['ignored holiday Nov 11', $two_days + ['excluded_holiday_dates' => ['2026-11-11']], '2026-11-08 21:00', '2026-11-10 20:00', '2026-11-11', '2026-11-12'],
    ['1.x: Friday before 14:00', $legacy, '2026-10-16 13:00', '2026-10-16 14:00', '2026-10-16', ''],
    ['1.x: Friday at 14:00', $legacy, '2026-10-16 14:00', '2026-10-19 14:00', '2026-10-19', ''],
    ['1.x: Saturday', $legacy, '2026-10-17 10:00', '2026-10-19 14:00', '2026-10-19', ''],
    ['1.x: Nov 11 is a holiday', $legacy, '2026-11-10 15:00', '2026-11-12 14:00', '2026-11-12', ''],
];

// [label, now, text, expected] on the $two_days config
$texts = [
    ['today', '2026-10-25 15:00', 'Zamów {cutoff_when}, dostarczymy {delivery_when}.', 'Zamów dziś do 20:00, dostarczymy we wtorek.'],
    ['tomorrow', '2026-10-24 12:00', 'Zamów {cutoff_when}, dostarczymy {delivery_when}.', 'Zamów jutro do 20:00, dostarczymy we wtorek.'],
    ['weekday', '2026-10-21 21:00', 'Zamów {cutoff_when}, dostarczymy {delivery_when}.', 'Zamów do niedzieli 20:00, dostarczymy we wtorek.'],
    ['popup', '2026-10-21 21:00', 'Zamów do {cutoff_day} {cutoff_time}, a dostaniesz ją {delivery_day}.', 'Zamów do niedzieli 20:00, a dostaniesz ją we wtorek.'],
    ['ship tomorrow', '2026-10-27 10:00', 'Wyślemy {ship_when} ({ship_date}), dostawa {delivery_day} {delivery_date}.', 'Wyślemy jutro (28.10), dostawa w czwartek 29.10.'],
];

$fail = 0;

foreach ($cases as [$label, $options, $now, $cutoff, $ship, $delivery]) {
    $schedule = new WC_STN_Schedule($options, $tz);
    $slot     = $schedule->slots(1, new DateTimeImmutable($now, $tz))[0] ?? null;
    $got      = $slot ? [$slot['cutoff']->format('Y-m-d H:i'), $slot['ship']->format('Y-m-d'), $slot['delivery'] ? $slot['delivery']->format('Y-m-d') : ''] : ['-', '-', '-'];
    $ok       = $got === [$cutoff, $ship, $delivery];
    $fail    += $ok ? 0 : 1;

    printf("%s  %-42s now %s, cutoff %s, ship %s, delivery %s%s\n", $ok ? 'OK  ' : 'FAIL', $label, $now, $got[0], $got[1], $got[2] ?: '-', $ok ? '' : "  (expected: {$cutoff}, {$ship}, {$delivery})");
}

// Tile tokens: {ship_short} and {delivery_short} only when passed in the labels (unchanged in the widget)
$texts[] = ['tile: short forms', '2026-10-25 15:00', '{delivery_short} | wysyłka {ship_short} | {ship_when}', 'wt 27.10 | wysyłka pn 26.10 | jutro', ['ship_short' => 'pn 26.10', 'delivery_short' => 'wt 27.10']];
$texts[] = ['widget: no short forms', '2026-10-25 15:00', 'Wyślemy {ship_when} {ship_short}', 'Wyślemy jutro {ship_short}', []];

foreach ($texts as $row) {
    [$label, $now, $text, $expected] = $row;
    $extra = $row[4] ?? [];
    $schedule = new WC_STN_Schedule($two_days, $tz);
    $now      = new DateTimeImmutable($now, $tz);
    $slot     = $schedule->slots(1, $now)[0];
    $got      = $schedule->render_text($text, $extra + $schedule->slot_labels($slot), $now);
    $ok       = $got === $expected;
    $fail    += $ok ? 0 : 1;

    printf("%s  text: %-14s %s%s\n", $ok ? 'OK  ' : 'FAIL', $label, $got, $ok ? '' : "  (expected: {$expected})");
}

// Method schedules. Engine: the same options with overridden days and hours (see WC_Ship_Today_Notice::method_overrides).
// [label, options, now, expected: cutoff, ship, delivery]
$method_cases = [
    ['method: until 15:00 instead of 14:00', array_merge($legacy, ['cutoff_times' => array_fill(1, 5, '15:00')]), '2026-10-16 14:30', '2026-10-16 15:00', '2026-10-16', ''],
    ['method: no shipping on Friday', array_merge($legacy, ['delivery_days' => [1, 2, 3, 4]]), '2026-10-16 10:00', '2026-10-19 14:00', '2026-10-19', ''],
    ['method: 2 delivery days', array_merge($two_days, ['delivery_offset' => 2]), '2026-10-25 19:59', '2026-10-25 20:00', '2026-10-26', '2026-10-28'],
];
foreach ($method_cases as [$label, $options, $now, $cutoff, $ship, $delivery]) {
    $schedule = new WC_STN_Schedule($options, $tz, 'flat_rate:901');
    $slot     = $schedule->slots(1, new DateTimeImmutable($now, $tz))[0] ?? null;
    $got      = $slot ? [$slot['cutoff']->format('Y-m-d H:i'), $slot['ship']->format('Y-m-d'), $slot['delivery'] ? $slot['delivery']->format('Y-m-d') : ''] : ['-', '-', '-'];
    $ok       = $got === [$cutoff, $ship, $delivery];
    $fail    += $ok ? 0 : 1;
    printf("%s  %-42s now %s, cutoff %s, ship %s, delivery %s%s\n", $ok ? 'OK  ' : 'FAIL', $label, $now, $got[0], $got[1], $got[2] ?: '-', $ok ? '' : "  (expected: {$cutoff}, {$ship}, {$delivery})");
}

// Plugin: schedule($method) picks the method schedule only for an enabled entry of an existing method (no settings saved:
// the option comes through pre_option, the method list through reflection). Filters get the method ID as the last argument.
$plugin  = WC_Ship_Today_Notice::instance();
$methods = new ReflectionProperty($plugin, 'shipping_methods');
$cache   = new ReflectionProperty($plugin, 'schedule');
$methods->setAccessible(true);
$cache->setAccessible(true);
$methods_before = $methods->getValue($plugin);
$methods->setValue($plugin, ['flat_rate:901' => 'Test - courier', 'flat_rate:902' => 'Test - locker']);
$cache->setValue($plugin, null);
$fixture = function () {
    return [
        'flat_rate:901' => ['enabled' => true, 'delivery_days' => [1, 2, 3, 4], 'cutoff_times' => array_fill(1, 7, '13:00'), 'cutoff_offsets' => [], 'delivery_offset' => ''],
        'flat_rate:902' => ['enabled' => false, 'delivery_days' => [1], 'cutoff_times' => array_fill(1, 7, '09:00'), 'cutoff_offsets' => [], 'delivery_offset' => ''],
        'flat_rate:999' => ['enabled' => true, 'delivery_days' => [1], 'cutoff_times' => array_fill(1, 7, '09:00'), 'cutoff_offsets' => [], 'delivery_offset' => ''],
    ];
};
add_filter('pre_option_' . WC_Ship_Today_Notice::METHOD_KEY, $fixture);
$seen = [];
$spy  = function ($slots, $count, $now, $method = 'no argument') use (&$seen) {
    $seen[] = $method;
    return $slots;
};
add_filter('wc_ship_today_notice_slots', $spy, 10, 4);

$at     = new DateTimeImmutable('2026-10-15 10:00', $tz); // Thursday
$global = $plugin->schedule()->slots(1, $at)[0]['cutoff']->format('Y-m-d H:i');
// [label, method, expected cutoff (null = like global)]
$plugin_cases = [
    ['no method = global', null, null],
    ['entry on: until 13:00', 'flat_rate:901', '2026-10-15 13:00'],
    ['entry off = global', 'flat_rate:902', null],
    ['method removed in WC = global', 'flat_rate:999', null],
    ['no entry = global', 'free_shipping:77', null],
    ['bad key = global', 'garbage', null],
];
foreach ($plugin_cases as [$label, $method, $expected]) {
    $got  = $plugin->schedule($method)->slots(1, $at)[0]['cutoff']->format('Y-m-d H:i');
    $ok   = $got === ($expected ?? $global);
    $fail += $ok ? 0 : 1;
    printf("%s  method: %-34s %s%s\n", $ok ? 'OK  ' : 'FAIL', $label, $got, $ok ? '' : '  (expected: ' . ($expected ?? $global) . ')');
}
$ok    = in_array('flat_rate:901', $seen, true) && in_array(null, $seen, true) && in_array('free_shipping:77', $seen, true);
$fail += $ok ? 0 : 1;
printf("%s  method: slots filter gets the method ID  %s\n", $ok ? 'OK  ' : 'FAIL', implode(', ', array_map('var_export', array_unique($seen, SORT_REGULAR), array_fill(0, count(array_unique($seen, SORT_REGULAR)), true))));

remove_filter('wc_ship_today_notice_slots', $spy, 10);
remove_filter('pre_option_' . WC_Ship_Today_Notice::METHOD_KEY, $fixture);
$methods->setValue($plugin, $methods_before);
$cache->setValue($plugin, null);

// API with a bad date: false/null instead of an exception. [label, call, expected]
$api = [
    ['is_shipping_day("garbage")', function () { return wc_stn_is_shipping_day('garbage'); }, false],
    ['is_shipping_day("2026-02-31")', function () { return wc_stn_is_shipping_day('2026-02-31'); }, false],
    ['is_blocked_date("garbage")', function () { return wc_stn_is_blocked_date('garbage'); }, false],
    ['is_blocked_date(null)', function () { return wc_stn_is_blocked_date(null); }, false],
    ['delivery_for_ship_date("2026-1015")', function () { return wc_stn_delivery_for_ship_date('2026-1015'); }, null],
    ['normalize_ymd("20261015")', function () { return wc_stn_normalize_ymd('20261015'); }, '2026-10-15'],
    ['normalize_ymd("2026-10-15")', function () { return wc_stn_normalize_ymd('2026-10-15'); }, '2026-10-15'],
];

foreach ($api as [$label, $call, $expected]) {
    try {
        $got = $call();
        $ok  = $got === $expected;
    } catch (Throwable $e) {
        $got = get_class($e) . ': ' . $e->getMessage();
        $ok  = false;
    }
    $fail += $ok ? 0 : 1;

    printf("%s  api: %-36s %s\n", $ok ? 'OK  ' : 'FAIL', $label, var_export($got, true));
}

// OpenHolidays response to holidays (same shape as the real API: DE 2026, RS 2026)
$api_rows = [
    ['startDate' => '2026-01-01', 'endDate' => '2026-01-01', 'nationwide' => true, 'name' => [['language' => 'DE', 'text' => 'Neujahr']]],
    ['startDate' => '2026-08-15', 'endDate' => '2026-08-15', 'nationwide' => false, 'subdivisions' => [['code' => 'DE-SL'], ['code' => 'DE-BY']], 'name' => [['language' => 'DE', 'text' => 'Mariä Himmelfahrt']]],
    ['startDate' => '2026-08-08', 'endDate' => '2026-08-08', 'nationwide' => false, 'subdivisions' => [['code' => 'DE-BY-AU']], 'name' => [['language' => 'DE', 'text' => 'Friedensfest']]],
    ['startDate' => '2026-12-31', 'endDate' => '2027-01-02', 'nationwide' => true, 'name' => [['language' => 'DE', 'text' => 'Multi-day']]],
    ['startDate' => 'garbage', 'nationwide' => true],
];
// [label, region, expected dates]
$holiday_cases = [
    ['no region: nationwide only, multi-day clipped to the year', '', ['2026-01-01', '2026-12-31']],
    ['region DE-BY: plus DE-BY holiday', 'DE-BY', ['2026-01-01', '2026-08-15', '2026-12-31']],
    ['region DE-BY-AU: plus DE-BY (parent) and DE-BY-AU', 'DE-BY-AU', ['2026-01-01', '2026-08-08', '2026-08-15', '2026-12-31']],
    ['region DE-BE: no holidays of other states', 'DE-BE', ['2026-01-01', '2026-12-31']],
];
foreach ($holiday_cases as [$label, $sub, $expected]) {
    $got  = array_keys(WC_Ship_Today_Notice::holidays_from_api($api_rows, 'DE', $sub, '2026-01-01', '2026-12-31'));
    $ok   = $got === $expected;
    $fail += $ok ? 0 : 1;
    printf("%s  holidays: %-58s %s%s\n", $ok ? 'OK  ' : 'FAIL', $label, implode(',', $got), $ok ? '' : '  (expected: ' . implode(',', $expected) . ')');
}
$got  = array_keys(WC_Ship_Today_Notice::holidays_from_api($api_rows, 'DE', '', '2027-01-01', '2027-12-31'));
$ok   = $got === ['2027-01-01', '2027-01-02'];
$fail += $ok ? 0 : 1;
printf("%s  holidays: %-58s %s\n", $ok ? 'OK  ' : 'FAIL', 'multi-day into the next year', implode(',', $got));

// [code, country, expected]
foreach ([['DE-BY', 'DE', true], ['DE-BY-AU', 'DE', true], ['CH-AG-BA-BA', 'CH', true], ['PL-BY', 'DE', false], ['DE', 'DE', false], ['de-by', 'DE', false], ['DE-BY;x', 'DE', false]] as [$code, $country, $expected]) {
    $got  = WC_Ship_Today_Notice::valid_subdivision($code, $country);
    $ok   = $got === $expected;
    $fail += $ok ? 0 : 1;
    printf("%s  region: %-14s (%s) %s\n", $ok ? 'OK  ' : 'FAIL', $code, $country, var_export($got, true));
}

echo $fail ? "\n{$fail} failed\n" : "\nAll cases OK\n";
