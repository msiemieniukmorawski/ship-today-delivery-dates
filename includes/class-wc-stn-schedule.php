<?php
/**
 * Slot engine: next shipments, cutoffs and delivery dates.
 *
 * Plain DateTimeImmutable logic in the shop timezone, no HTML.
 *
 * - ship day: a selected weekday that is not a holiday or an excluded date,
 * - cutoff: the time set for that weekday, moved N days back (0 = same day),
 * - delivery: the Nth delivery day after shipping, skipping no-delivery days and holidays (0 = no date).
 *
 * A method schedule is the same options with overridden days, cutoffs, offsets and optionally delivery days,
 * see WC_Ship_Today_Notice::schedule($method). Holidays, excluded dates and no-delivery days are shared.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WC_STN_Schedule
{
    /** How many days ahead to look for slots. */
    const HORIZON_DAYS = 60;

    /** @var array */
    private $options;

    /** @var DateTimeZone */
    private $tz;

    /** @var array<string,string>|null Holidays: Y-m-d => name. */
    private $holidays = null;

    /** @var array<string,bool>|null */
    private $excluded = null;

    /** @var string|null Shipping method instance ID ("flat_rate:3"), null = global. Last argument of the filters. */
    private $method;

    public function __construct(array $options, ?DateTimeZone $tz = null, ?string $method = null)
    {
        $this->options = $options;
        $this->tz      = $tz ?: wp_timezone();
        $this->method  = $method;
    }

    public function method(): ?string
    {
        return $this->method;
    }

    /**
     * Weekday name in the form the sentence needs (ISO-8601: 1 = Monday).
     * Each form is a separate string because Polish declension and the "w"/"we" preposition depend on the day.
     *
     * @param string $form 'name' (Monday), 'until' (after "until"), 'on' (on Monday), 'short' (Mon).
     */
    public static function day_label(int $weekday, string $form): string
    {
        switch ($form) {
            case 'until':
                $days = [
                    1 => _x('Monday', 'weekday after "until", e.g. "until Monday 20:00" (Polish: genitive)', 'wc-ship-today-notice'),
                    2 => _x('Tuesday', 'weekday after "until", e.g. "until Monday 20:00" (Polish: genitive)', 'wc-ship-today-notice'),
                    3 => _x('Wednesday', 'weekday after "until", e.g. "until Monday 20:00" (Polish: genitive)', 'wc-ship-today-notice'),
                    4 => _x('Thursday', 'weekday after "until", e.g. "until Monday 20:00" (Polish: genitive)', 'wc-ship-today-notice'),
                    5 => _x('Friday', 'weekday after "until", e.g. "until Monday 20:00" (Polish: genitive)', 'wc-ship-today-notice'),
                    6 => _x('Saturday', 'weekday after "until", e.g. "until Monday 20:00" (Polish: genitive)', 'wc-ship-today-notice'),
                    7 => _x('Sunday', 'weekday after "until", e.g. "until Monday 20:00" (Polish: genitive)', 'wc-ship-today-notice'),
                ];
                break;
            case 'on':
                $days = [
                    1 => _x('on Monday', 'weekday with preposition, e.g. "we will ship on Monday"', 'wc-ship-today-notice'),
                    2 => _x('on Tuesday', 'weekday with preposition, e.g. "we will ship on Monday"', 'wc-ship-today-notice'),
                    3 => _x('on Wednesday', 'weekday with preposition, e.g. "we will ship on Monday"', 'wc-ship-today-notice'),
                    4 => _x('on Thursday', 'weekday with preposition, e.g. "we will ship on Monday"', 'wc-ship-today-notice'),
                    5 => _x('on Friday', 'weekday with preposition, e.g. "we will ship on Monday"', 'wc-ship-today-notice'),
                    6 => _x('on Saturday', 'weekday with preposition, e.g. "we will ship on Monday"', 'wc-ship-today-notice'),
                    7 => _x('on Sunday', 'weekday with preposition, e.g. "we will ship on Monday"', 'wc-ship-today-notice'),
                ];
                break;
            case 'short':
                $days = [
                    1 => _x('Mon', 'short weekday name, e.g. on checkout tiles "Mon 09.11"', 'wc-ship-today-notice'),
                    2 => _x('Tue', 'short weekday name, e.g. on checkout tiles "Mon 09.11"', 'wc-ship-today-notice'),
                    3 => _x('Wed', 'short weekday name, e.g. on checkout tiles "Mon 09.11"', 'wc-ship-today-notice'),
                    4 => _x('Thu', 'short weekday name, e.g. on checkout tiles "Mon 09.11"', 'wc-ship-today-notice'),
                    5 => _x('Fri', 'short weekday name, e.g. on checkout tiles "Mon 09.11"', 'wc-ship-today-notice'),
                    6 => _x('Sat', 'short weekday name, e.g. on checkout tiles "Mon 09.11"', 'wc-ship-today-notice'),
                    7 => _x('Sun', 'short weekday name, e.g. on checkout tiles "Mon 09.11"', 'wc-ship-today-notice'),
                ];
                break;
            default:
                $days = [
                    1 => _x('Monday', 'weekday name inside a sentence, e.g. "Ship date: Monday, 09.11.2026" (Polish: lowercase nominative)', 'wc-ship-today-notice'),
                    2 => _x('Tuesday', 'weekday name inside a sentence, e.g. "Ship date: Monday, 09.11.2026" (Polish: lowercase nominative)', 'wc-ship-today-notice'),
                    3 => _x('Wednesday', 'weekday name inside a sentence, e.g. "Ship date: Monday, 09.11.2026" (Polish: lowercase nominative)', 'wc-ship-today-notice'),
                    4 => _x('Thursday', 'weekday name inside a sentence, e.g. "Ship date: Monday, 09.11.2026" (Polish: lowercase nominative)', 'wc-ship-today-notice'),
                    5 => _x('Friday', 'weekday name inside a sentence, e.g. "Ship date: Monday, 09.11.2026" (Polish: lowercase nominative)', 'wc-ship-today-notice'),
                    6 => _x('Saturday', 'weekday name inside a sentence, e.g. "Ship date: Monday, 09.11.2026" (Polish: lowercase nominative)', 'wc-ship-today-notice'),
                    7 => _x('Sunday', 'weekday name inside a sentence, e.g. "Ship date: Monday, 09.11.2026" (Polish: lowercase nominative)', 'wc-ship-today-notice'),
                ];
        }

        return $days[$weekday] ?? '';
    }

    /**
     * Relative day words, shared by render_text() and notice.js (through frontend_config()).
     */
    public static function words(): array
    {
        return [
            'today'    => _x('today', 'relative day, e.g. "we will ship today"', 'wc-ship-today-notice'),
            'tomorrow' => _x('tomorrow', 'relative day, e.g. "we will ship tomorrow"', 'wc-ship-today-notice'),
            /* translators: {cutoff_when} for today/tomorrow. %1$s: "today" or "tomorrow", %2$s: time, e.g. "today until 14:00" */
            'relative' => __('%1$s until %2$s', 'wc-ship-today-notice'),
            /* translators: {cutoff_when} for later days. %1$s: weekday (until form), %2$s: time, e.g. "until Sunday 20:00" */
            'until'    => __('until %1$s %2$s', 'wc-ship-today-notice'),
        ];
    }

    public function timezone(): DateTimeZone
    {
        return $this->tz;
    }

    /**
     * "Now" in the shop timezone. The filter lets test mode replace it.
     */
    public function now(): DateTimeImmutable
    {
        $now = apply_filters('wc_ship_today_notice_now', new DateTimeImmutable('now', $this->tz));

        return $now instanceof DateTimeImmutable ? $now->setTimezone($this->tz) : new DateTimeImmutable('now', $this->tz);
    }

    public function date(string $ymd): DateTimeImmutable
    {
        return new DateTimeImmutable($ymd . ' 00:00:00', $this->tz);
    }

    /* ---------------------------------------------------------
     * Ship days
     * --------------------------------------------------------- */

    public function is_shipping_weekday(DateTimeImmutable $day): bool
    {
        return in_array((int) $day->format('N'), array_map('intval', (array) ($this->options['delivery_days'] ?? [])), true);
    }

    /**
     * A holiday not ignored in settings, or a manually excluded date.
     */
    public function is_blocked(string $ymd): bool
    {
        if ($this->is_excluded_date($ymd)) {
            return true;
        }

        return $this->is_holiday($ymd) && !in_array($ymd, (array) ($this->options['excluded_holiday_dates'] ?? []), true);
    }

    public function is_shipping_day(DateTimeImmutable $day): bool
    {
        $ymd    = $day->format('Y-m-d');
        $result = $this->is_shipping_weekday($day) && !$this->is_blocked($ymd);

        return (bool) apply_filters('wc_ship_today_notice_is_shipping_day', $result, $ymd, $this->method);
    }

    /**
     * Cutoff for a ship day.
     */
    public function cutoff_for(DateTimeImmutable $ship_day): DateTimeImmutable
    {
        $weekday = (int) $ship_day->format('N');
        $time    = (string) apply_filters('wc_ship_today_notice_cutoff_time', $this->cutoff_time($weekday), $weekday, $ship_day->format('Y-m-d'), $this->method);

        if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $m)) {
            $time = '14:00';
            $m    = [null, '14', '00'];
        }

        $offset = $this->cutoff_offset($weekday);

        return $ship_day->setTime(0, 0)->modify('-' . $offset . ' days')->setTime((int) $m[1], (int) $m[2]);
    }

    public function cutoff_time(int $weekday): string
    {
        $times = (array) ($this->options['cutoff_times'] ?? []);

        if (isset($times[$weekday]) && preg_match('/^\d{2}:\d{2}$/', $times[$weekday])) {
            return $times[$weekday];
        }

        return $this->options['cutoff_time'] ?? '14:00';
    }

    public function cutoff_offset(int $weekday): int
    {
        $offsets = (array) ($this->options['cutoff_offsets'] ?? []);

        return max(0, min(6, (int) ($offsets[$weekday] ?? 0)));
    }

    /* ---------------------------------------------------------
     * Delivery
     * --------------------------------------------------------- */

    /**
     * Delivery day: not a no-delivery day and not a public holiday
     * (ignoring a holiday only affects shipping, couriers still do not run).
     */
    public function is_delivery_day(DateTimeImmutable $day): bool
    {
        $no_delivery = array_map('intval', (array) ($this->options['no_delivery_days'] ?? [7]));

        return !in_array((int) $day->format('N'), $no_delivery, true) && !$this->is_holiday($day->format('Y-m-d'));
    }

    public function delivery_for(DateTimeImmutable $ship_day): ?DateTimeImmutable
    {
        $days = (int) ($this->options['delivery_offset'] ?? 0);

        if ($days <= 0) {
            return null;
        }

        $day = $ship_day->setTime(0, 0);

        for ($guard = 0; $days > 0 && $guard < 60; $guard++) {
            $day = $day->modify('+1 day');
            if ($this->is_delivery_day($day)) {
                $days--;
            }
        }

        return $day;
    }

    /* ---------------------------------------------------------
     * Slots
     * --------------------------------------------------------- */

    /**
     * Next slots whose cutoff has not passed.
     *
     * @return array<int,array{cutoff:DateTimeImmutable,ship:DateTimeImmutable,delivery:?DateTimeImmutable}>
     */
    public function slots(int $count = 1, ?DateTimeImmutable $now = null, int $horizon_days = self::HORIZON_DAYS): array
    {
        $now   = $now ? $now->setTimezone($this->tz) : $this->now();
        $today = $now->setTime(0, 0);
        $slots = [];

        for ($i = 0; $i <= $horizon_days && count($slots) < $count; $i++) {
            $day = $today->modify('+' . $i . ' days');

            if (!$this->is_shipping_day($day)) {
                continue;
            }

            $cutoff = $this->cutoff_for($day);

            if ($cutoff <= $now) {
                continue;
            }

            $slots[] = [
                'cutoff'   => $cutoff,
                'ship'     => $day,
                'delivery' => $this->delivery_for($day),
            ];
        }

        return apply_filters('wc_ship_today_notice_slots', $slots, $count, $now, $this->method);
    }

    /**
     * Ship dates (Y-m-d) in a day range, regardless of the cutoff.
     */
    public function shipping_dates(?DateTimeImmutable $from = null, int $days = self::HORIZON_DAYS): array
    {
        $from  = ($from ?: $this->now())->setTime(0, 0);
        $dates = [];

        for ($i = 0; $i <= $days; $i++) {
            $day = $from->modify('+' . $i . ' days');
            if ($this->is_shipping_day($day)) {
                $dates[] = $day->format('Y-m-d');
            }
        }

        return $dates;
    }

    /**
     * Slot labels for text tokens. Relative words are added by JS and render_text().
     */
    public function slot_labels(array $slot): array
    {
        $cutoff   = $slot['cutoff'];
        $ship     = $slot['ship'];
        $delivery = $slot['delivery'];

        return [
            'cutoff'        => $cutoff->getTimestamp(),
            'cutoff_ymd'    => $cutoff->format('Y-m-d'),
            'cutoff_time'   => $cutoff->format('H:i'),
            'cutoff_day'    => self::day_label((int) $cutoff->format('N'), 'until'),
            'cutoff_date'   => $cutoff->format('d.m'),
            'ship_ymd'      => $ship->format('Y-m-d'),
            'ship_day'      => self::day_label((int) $ship->format('N'), 'on'),
            'ship_date'     => $ship->format('d.m'),
            'delivery_ymd'  => $delivery ? $delivery->format('Y-m-d') : '',
            'delivery_day'  => $delivery ? self::day_label((int) $delivery->format('N'), 'on') : '',
            'delivery_date' => $delivery ? $delivery->format('d.m') : '',
        ];
    }

    /**
     * Replaces slot tokens in a text. {countdown} stays as a marker for JS.
     * Optional \$labels['ship_short'] and ['delivery_short'] provide {ship_short} and {delivery_short} (checkout tiles).
     */
    public function render_text(string $text, array $labels, ?DateTimeImmutable $now = null): string
    {
        $now      = $now ?: $this->now();
        $today    = $now->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');

        $words    = self::words();

        $when = function ($ymd, $default) use ($today, $tomorrow, $words) {
            if ($ymd === $today) {
                return $words['today'];
            }
            return $ymd === $tomorrow ? $words['tomorrow'] : $default;
        };

        $cutoff_when = $labels['cutoff_ymd'] === $today || $labels['cutoff_ymd'] === $tomorrow
            ? sprintf($words['relative'], $when($labels['cutoff_ymd'], ''), $labels['cutoff_time'])
            : sprintf($words['until'], $labels['cutoff_day'], $labels['cutoff_time']);

        $replace = [
            '{cutoff_when}'   => $cutoff_when,
            '{cutoff_day}'    => $labels['cutoff_day'],
            '{cutoff_time}'   => $labels['cutoff_time'],
            '{cutoff_date}'   => $labels['cutoff_date'],
            '{ship_when}'     => $when($labels['ship_ymd'], $labels['ship_day']),
            '{ship_day}'      => $labels['ship_day'],
            '{ship_date}'     => $labels['ship_date'],
            '{delivery_when}' => $labels['delivery_ymd'] ? $when($labels['delivery_ymd'], $labels['delivery_day']) : '',
            '{delivery_day}'  => $labels['delivery_day'],
            '{delivery_date}' => $labels['delivery_date'],
        ];
        // Short forms from the checkout tiles, only when the caller passes them (the widget doesn't)
        foreach (['ship_short', 'delivery_short'] as $key) {
            if (isset($labels[$key])) {
                $replace['{' . $key . '}'] = (string) $labels[$key];
            }
        }

        return strtr($text, $replace);
    }

    /* ---------------------------------------------------------
     * Holidays and excluded dates
     * --------------------------------------------------------- */

    public function is_holiday(string $ymd): bool
    {
        $this->load_holidays();

        return isset($this->holidays[$ymd]);
    }

    public function holiday_name(string $ymd): string
    {
        $this->load_holidays();

        return $this->holidays[$ymd] ?? '';
    }

    public function is_excluded_date(string $ymd): bool
    {
        if (null === $this->excluded) {
            $this->excluded = [];
            $raw            = str_replace(["\r\n", "\r", "\n"], ',', (string) ($this->options['excluded_dates'] ?? ''));

            foreach (array_filter(array_map('trim', explode(',', $raw))) as $item) {
                $dt = DateTime::createFromFormat('!d-m-Y', $item, $this->tz);
                if ($dt) {
                    $this->excluded[$dt->format('Y-m-d')] = true;
                }
            }
        }

        return isset($this->excluded[$ymd]);
    }

    private function load_holidays(): void
    {
        if (null !== $this->holidays) {
            return;
        }

        global $wpdb;

        $this->holidays = [];
        $table          = $wpdb->prefix . WC_Ship_Today_Notice::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own holiday table, no WP API, kept in $this->holidays for the request.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT holiday_date, holiday_name FROM %i WHERE country_code = %s', $table, $this->options['country_code'] ?? 'PL'));

        foreach ((array) $rows as $row) {
            $this->holidays[$row->holiday_date] = (string) $row->holiday_name;
        }
    }
}
