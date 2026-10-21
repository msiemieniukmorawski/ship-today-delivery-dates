<?php
/**
 * Silnik terminów: najbliższe wysyłki, godziny graniczne, daty dostawy.
 *
 * Czysta logika na DateTimeImmutable w strefie czasowej sklepu, bez HTML.
 *
 * Model:
 * - dzień wysyłki = dzień tygodnia zaznaczony w ustawieniach, który nie jest świętem ani datą wykluczoną,
 * - godzina graniczna = godzina dla tego dnia tygodnia, przesunięta o N dni wstecz (0 = ten sam dzień),
 * - dostawa = N-ty kolejny dzień doręczeń po wysyłce (bez dni bez doręczeń i świąt), 0 = bez daty dostawy.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WC_STN_Schedule
{
    /** Ile dni do przodu szukamy terminów. */
    const HORIZON_DAYS = 60;

    /** Nazwy dni (ISO-8601: 1 = poniedziałek ... 7 = niedziela). */
    const DAY_NOMINATIVE = [1 => 'poniedziałek', 2 => 'wtorek', 3 => 'środa', 4 => 'czwartek', 5 => 'piątek', 6 => 'sobota', 7 => 'niedziela'];
    const DAY_GENITIVE   = [1 => 'poniedziałku', 2 => 'wtorku', 3 => 'środy', 4 => 'czwartku', 5 => 'piątku', 6 => 'soboty', 7 => 'niedzieli'];
    const DAY_ON         = [1 => 'w poniedziałek', 2 => 'we wtorek', 3 => 'w środę', 4 => 'w czwartek', 5 => 'w piątek', 6 => 'w sobotę', 7 => 'w niedzielę'];
    const DAY_SHORT      = [1 => 'pn', 2 => 'wt', 3 => 'śr', 4 => 'czw', 5 => 'pt', 6 => 'sob', 7 => 'nd'];

    /** @var array */
    private $options;

    /** @var DateTimeZone */
    private $tz;

    /** @var array<string,string>|null Święta: Y-m-d => nazwa. */
    private $holidays = null;

    /** @var array<string,bool>|null */
    private $excluded = null;

    public function __construct(array $options, ?DateTimeZone $tz = null)
    {
        $this->options = $options;
        $this->tz      = $tz ?: wp_timezone();
    }

    public function timezone(): DateTimeZone
    {
        return $this->tz;
    }

    /**
     * "Teraz" w strefie sklepu. Filtr pozwala podmienić czas (tryb testowy).
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
     * Dni wysyłki
     * --------------------------------------------------------- */

    public function is_shipping_weekday(DateTimeImmutable $day): bool
    {
        return in_array((int) $day->format('N'), array_map('intval', (array) ($this->options['delivery_days'] ?? [])), true);
    }

    /**
     * Święto, którego nie zignorowano w ustawieniach, albo data wykluczona ręcznie.
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

        return (bool) apply_filters('wc_ship_today_notice_is_shipping_day', $result, $ymd);
    }

    /**
     * Godzina graniczna dla danego dnia wysyłki.
     */
    public function cutoff_for(DateTimeImmutable $ship_day): DateTimeImmutable
    {
        $weekday = (int) $ship_day->format('N');
        $time    = (string) apply_filters('wc_ship_today_notice_cutoff_time', $this->cutoff_time($weekday), $weekday, $ship_day->format('Y-m-d'));

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
     * Dostawa
     * --------------------------------------------------------- */

    /**
     * Dzień doręczeń: nie jest w "dniach bez doręczeń" i nie jest świętem państwowym
     * (ignorowanie święta dotyczy tylko wysyłki, kurier i tak w święto nie jeździ).
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
     * Terminy
     * --------------------------------------------------------- */

    /**
     * Najbliższe terminy, których godzina graniczna jeszcze nie minęła.
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

        return apply_filters('wc_ship_today_notice_slots', $slots, $count, $now);
    }

    /**
     * Daty wysyłki (Y-m-d) w zakresie dni, niezależnie od godziny granicznej.
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
     * Etykiety terminu do tokenów w tekstach (bez określeń względnych, te liczy JS i render_text()).
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
            'cutoff_day'    => self::DAY_GENITIVE[(int) $cutoff->format('N')],
            'cutoff_date'   => $cutoff->format('d.m'),
            'ship_ymd'      => $ship->format('Y-m-d'),
            'ship_day'      => self::DAY_ON[(int) $ship->format('N')],
            'ship_date'     => $ship->format('d.m'),
            'delivery_ymd'  => $delivery ? $delivery->format('Y-m-d') : '',
            'delivery_day'  => $delivery ? self::DAY_ON[(int) $delivery->format('N')] : '',
            'delivery_date' => $delivery ? $delivery->format('d.m') : '',
        ];
    }

    /**
     * Wstawia tokeny terminu do tekstu. {countdown} zostaje jako znacznik do podmiany przez JS.
     */
    public function render_text(string $text, array $labels, ?DateTimeImmutable $now = null): string
    {
        $now      = $now ?: $this->now();
        $today    = $now->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');

        $when = function ($ymd, $default) use ($today, $tomorrow) {
            if ($ymd === $today) {
                return 'dziś';
            }
            return $ymd === $tomorrow ? 'jutro' : $default;
        };

        $cutoff_when = $labels['cutoff_ymd'] === $today || $labels['cutoff_ymd'] === $tomorrow
            ? $when($labels['cutoff_ymd'], '') . ' do ' . $labels['cutoff_time']
            : 'do ' . $labels['cutoff_day'] . ' ' . $labels['cutoff_time'];

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

        return strtr($text, $replace);
    }

    /* ---------------------------------------------------------
     * Święta i daty wykluczone
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

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare("SELECT holiday_date, holiday_name FROM {$table} WHERE country_code = %s", $this->options['country_code'] ?? 'PL'));

        foreach ((array) $rows as $row) {
            $this->holidays[$row->holiday_date] = (string) $row->holiday_name;
        }
    }
}
