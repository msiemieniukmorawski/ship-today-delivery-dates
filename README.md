# WooCommerce Ship Today Notice

![WordPress](https://img.shields.io/badge/WordPress-6.8%2B-blue)
![WooCommerce](https://img.shields.io/badge/WooCommerce-wymagany-purple)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4)
![License](https://img.shields.io/badge/license-GPL--2.0%2B-green)

Wtyczka WooCommerce, która pokazuje klientowi, do kiedy musi zamówić, żeby paczka wyszła w najbliższym terminie:
odliczanie do godziny granicznej, dzień wysyłki i (opcjonalnie) planowany dzień dostawy. Opcjonalny moduł dodaje
do klasycznego checkoutu wybór terminu wysyłki, który trafia do zamówienia, maili i panelu.

Teksty na stronie sklepu (dni tygodnia, "dziś", "jutro", kafelki, maile) są po polsku.

## Funkcje w skrócie

- **Widget z odliczaniem** - shortcode `[ship_today_notice]`, dwa tryby: "wysyłka dziś" i "najbliższy termin".
- **Harmonogram wysyłek** - dni wysyłki, godzina graniczna dla każdego dnia, godzina graniczna wypadająca 0-6 dni przed dniem wysyłki
  (np. wysyłka w poniedziałek, zamówienia do niedzieli 20:00).
- **Data dostawy** - N dni doręczeń po wysyłce, z pominięciem dni bez doręczeń i świąt.
- **Święta** z [OpenHolidays API](https://www.openholidaysapi.org/) na bieżący i następny rok, z możliwością ignorowania wybranych.
  Do tego ręczna lista dat wykluczonych.
- **Tokeny z polską odmianą dni**: "dziś do 20:00", "do niedzieli 20:00", "we wtorek".
- **Działa ze stron w cache** - harmonogram na 42 dni jest w HTML, przeglądarka sama przełącza terminy. Czyszczenie cache LiteSpeed
  o godzinie granicznej.
- **Wybór terminu w checkoucie** (klasyczny i blokowy checkout) - kafelki, walidacja, zapis w zamówieniu, maile, panel zamówienia,
  kolumna z sortowaniem i filtrem na liście zamówień, przełącznik wyłączający Iconic Delivery Slots.
- **Zakładka „Styl”** - kolory, ramka, czcionka i wyrównanie widgetu wyklikane w panelu, z podglądem na żywo.
- **Podgląd 5 najbliższych terminów** w panelu i **tryb testowy** `?stn_now=`.
- **API dla motywu i innych wtyczek** - funkcje `wc_stn_*`, filtry, akcja, JS.

## Wymagania

- WordPress 6.8+ (od 6.8 WordPress sam ładuje tłumaczenia z `languages/` wtyczki; sprawdzone na 6.8 z WooCommerce 9.4.3)
- PHP 7.4+
- WooCommerce - wymagany: strona ustawień jest w menu WooCommerce, a dostęp sprawdza uprawnienie `manage_woocommerce`.
- Checkout blokowy (WooCommerce Blocks): wybór terminu przez Additional Checkout Fields API z regułami warunkowymi,
  od **WooCommerce 10.1** (sprawdzone na 11.2.1, patrz „Checkout blokowy”). Na starszym WooCommerce pole w checkoucie
  blokowym się nie pojawia, a zamówienia przechodzą bez daty jak wcześniej. Sam widget `[ship_today_notice]` działa niezależnie od checkoutu.
- Zgodność z HPOS i z checkoutem blokowym jest zadeklarowana (`FeaturesUtil::declare_compatibility('custom_order_tables')`,
  `'cart_checkout_blocks'`): kod zamówień używa CRUD WooCommerce i obsługuje listę zamówień HPOS oraz starą tabelę postów.

## Instalacja

1. Skopiuj katalog do `wp-content/plugins/wc-ship-today-notice/` (albo wgraj ZIP w Wtyczki → Dodaj nową).
2. Aktywuj wtyczkę. Aktywacja tworzy tabelę `{prefix}wc_ship_today_notice_holidays` i planuje pobieranie świąt
   (pierwsze po ok. godzinie, potem co 4 miesiące).
3. Wejdź w **WooCommerce → Ship Today Notice**.

Strona ustawień i zapis obu zakładek wymagają uprawnienia `manage_woocommerce`, więc kierownik sklepu może je zmieniać
(filtr `option_page_capability_*` dla grup `wc_ship_today_notice_options` i `wc_ship_today_notice_style`).

## Szybki start

1. **Dni wysyłki i godziny graniczne** - zaznacz dni, ustaw godzinę i to, kiedy wypada (tego samego dnia / N dni wcześniej).
2. **Pobierz święta** - przycisk "Fetch holidays now" na dole strony ustawień (od razu, bez czekania na cron).
3. Opcjonalnie **Dostawa** - liczba dni doręczeń po wysyłce i dni bez doręczeń.
4. Zapisz i sprawdź tabelę **Najbliższe terminy**.
5. Wstaw `[ship_today_notice]` na kartę produktu, do koszyka, nagłówka albo popupu.
6. Opcjonalnie włącz **Wybór terminu w checkoucie** (sekcja niżej).

## Co działa od razu, a co trzeba włączyć

| Funkcja | Domyślnie aktywna? | Gdzie się włącza | Uwagi |
|---|---|---|---|
| Widget `[ship_today_notice]` | tak, po wstawieniu shortcode | shortcode | "Wyświetlanie widgetu" = Zawsze. JS i CSS widgetu ładują się na każdej stronie. |
| Tryb komunikatu | "Wysyłka dziś" | Tryb komunikatu / atrybut `mode` | |
| Dni wysyłki | pn-pt, do 14:00 tego samego dnia | Delivery weekdays & cutoff times | sob/nd mają domyślnie 12:00, ale nie są zaznaczone. |
| Data dostawy | nie (0 dni) | Dostawa | Dni bez doręczeń domyślnie: niedziela. |
| Święta | po pobraniu | cron ok. 1 h po aktywacji albo "Fetch holidays now" | Kraj i język: PL. Po zmianie kraju trzeba pobrać święta ręcznie. |
| Daty wykluczone | brak | Excluded dates | |
| Tekst po godzinie granicznej / w dzień bez wysyłki | tak | checkboxy przy tekstach | Dotyczy trybu "wysyłka dziś". |
| Sekundy w odliczaniu | tak | Countdown format | |
| Własny wygląd widgetu | nie (domyślne style z `notice.css`) | zakładka **Styl** | Puste pole = domyślny wygląd. |
| Wybór terminu w checkoucie | **nie** | Wybór terminu w checkoucie → Moduł | Domyślne miejsce: tylko shortcode `[ship_today_checkout]`. |
| Wiersz w podsumowaniu, maile, strona podziękowania, Moje konto | z modułem | j.w. | |
| Panel zamówienia (daty + zmiana daty wysyłki) | z modułem | j.w. | |
| Kolumna "Wysyłka", sortowanie, filtr na liście zamówień | z modułem | j.w. | HPOS i stara tabela postów. |
| Wyłączenie Iconic Delivery Slots | nie | Iconic Delivery Slots | Pole widać tylko przy aktywnym Iconic, działa tylko z włączonym modułem. |
| Czyszczenie cache o godzinie granicznej | tak, gdy aktywny LiteSpeed Cache albo podpięta akcja `wc_ship_today_notice_purge_cache` | automatycznie | Też po każdym zapisie ustawień. |
| WPML | gdy aktywny WPML | automatycznie | Cztery teksty widgetu rejestrowane przy zapisie ustawień. |
| Tryb testowy `?stn_now=` | tak | parametr w adresie | Tylko dla `manage_woocommerce`. |
| Funkcje, filtry, JS API | tak | kod | |

Gdy moduł checkoutu jest wyłączony, wtyczka nie rejestruje jego hooków. Daty zapisane wcześniej w zamówieniach zostają
w meta, ale nie są wtedy pokazywane w mailach, panelu ani na liście.

## Shortcode `[ship_today_notice]`

```
[ship_today_notice]
[ship_today_notice mode="next_slot"]
[ship_today_notice count="2" text="Zamów do {cutoff_day} {cutoff_time}, a dostaniesz ją {delivery_day}."]
[ship_today_notice class="moja-klasa"]
```

| Atrybut | Domyślnie | Opis |
|---|---|---|
| `count` | `1` | Ile kolejnych terminów pokazać (1-10). Przy `count > 1` kontener ma klasę `wc-ship-today-notice--list`, a każdy termin jest w `.wc-ship-today-notice__item`. |
| `text` | z ustawień | Własny tekst z tokenami. Zastępuje wszystkie teksty z ustawień (przed / po godzinie granicznej / dzień bez wysyłki / najbliższy termin). HTML filtrowany przez `wp_kses_post`. |
| `mode` | z ustawień | `today` albo `next_slot`. |
| `class` | - | Dodatkowe klasy CSS kontenera (oddzielone spacją). |

Gdy "Wyświetlanie widgetu" = Wyłączony, shortcode zwraca pusty tekst, a skrypt i styl nie są ładowane.

### Tryby

- **Wysyłka dziś** (`today`) - przed dzisiejszą godziną graniczną: "Text before cutoff" z odliczaniem. Po niej, w dzień wysyłki:
  "Text after cutoff". W dzień bez wysyłki (dzień tygodnia, święto, data wykluczona): "Text when not a shipping day".
- **Najbliższy termin** (`next_slot`) - zawsze odliczanie do najbliższej godziny granicznej i jeden tekst
  ("Tekst w trybie najbliższy termin"). Opcja widoczności "tylko przed godziną graniczną" działa tu jak "zawsze".

Przy `count > 1` pierwszy termin ma tekst stanu, kolejne tekst trybu "najbliższy termin".

### Widoczność

| Ustawienie | Działanie |
|---|---|
| Disabled | Widget i jego zasoby wyłączone. |
| Always visible | Zawsze. |
| Only before cutoff time | W trybie "wysyłka dziś" ukryty po godzinie granicznej. |
| Only N hour(s) before cutoff | Widoczny dopiero N godzin (0,5-168) przed godziną graniczną. |

Niezależnie od tego widget jest ukryty, gdy w najbliższych 42 dniach nie ma żadnego terminu.

### Tokeny

| Token | Przykład |
|---|---|
| `{countdown}` | `1 dzień 05h 12min 10s` (format w "Countdown format") |
| `{cutoff_when}` | `dziś do 20:00` / `jutro do 20:00` / `do niedzieli 20:00` |
| `{cutoff_day}` | `niedzieli` (dopełniacz) |
| `{cutoff_time}` | `20:00` |
| `{cutoff_date}` | `25.10` |
| `{ship_when}` | `dziś` / `jutro` / `w poniedziałek` |
| `{ship_day}` | `w poniedziałek` |
| `{ship_date}` | `26.10` |
| `{delivery_when}` | `jutro` / `we wtorek` |
| `{delivery_day}` | `we wtorek` |
| `{delivery_date}` | `27.10` |

Tokeny `{delivery_*}` są puste, gdy dostawa = 0. "Dziś" i "jutro" liczy przeglądarka w strefie czasowej sklepu, więc są
poprawne także na stronie z cache. Nieznane tokeny zostają w tekście bez zmian.

Domyślne teksty:

```
Najbliższy termin:  Zamów {cutoff_when}, wyślemy {ship_when}. Zostało: {countdown}
Before cutoff:      Order within: {countdown} — we will ship today!   (przetłumaczone przez .po)
After cutoff:       We will ship tomorrow.
Not shipping day:   We will ship on the next business day.
```

### Odliczanie

Etykiety godzin, minut i sekund (domyślnie `h`, `min`, `s`), przełącznik sekund. Pusta etykieta = sama liczba
(np. `01:30`). Powyżej doby odliczanie pokazuje dni ("1 dzień", "3 dni").

### Wygląd

Klasy stanu kontenera: `wc-ship-today-notice--before` (przed godziną graniczną i tryb "najbliższy termin"),
`--after` (po godzinie granicznej, dzień bez wysyłki), `--hidden`. Odliczanie: `.wc-ship-today-countdown`.

Zmienne CSS: `--wc-ship-today-notice-bg`, `-border`, `-color`, `-font-size`, `-before-bg`, `-before-border`, `-before-color`,
`-after-bg`, `-after-border`, `-after-color`, `-countdown-color`, `-border-width`, `-radius`, `-padding`, `-font-weight`,
`-align`, `-justify`. Można je nadpisać własnym CSS, np. na klasie z atrybutu `class`.

### Zakładka „Styl”

WooCommerce → Ship Today Notice → **Styl**: wygląd `[ship_today_notice]` bez pisania CSS, z podglądem na żywo stanów
"przed" i "po" godzinie granicznej.

| Opcja | Opis |
|-------|------|
| **Przed / po godzinie granicznej** | Tło, kolor ramki i kolor tekstu dla każdego stanu. Dzień bez wysyłki używa kolorów "po". |
| **Kolor odliczania** | Kolor `{countdown}`. |
| **Grubość ramki, zaokrąglenie, odstęp wewnętrzny, rozmiar czcionki** | W px. Odstęp jest jeden na wszystkie boki. |
| **Grubość czcionki, wyrównanie** | 400-800; do lewej / do środka / do prawej. |

Puste pole = domyślny wygląd. "Przywróć domyślne" czyści formularz, a zapis następuje dopiero po kliknięciu "Zapisz zmiany". Styl jest
zapisany w opcji `wc_ship_today_notice_style` i trafia na stronę jako zmienne CSS na `:root` (inline przy `notice.css`),
więc działa też ze stron w cache. Po zapisie cache jest czyszczony jak po zapisie ustawień.

## Wybór terminu w checkoucie

Włączenie: **WooCommerce → Ship Today Notice → Wybór terminu w checkoucie → Moduł**. Działa w klasycznym i blokowym checkoucie
(różnice w „Checkout blokowy”).

| Ustawienie | Domyślnie | Opis |
|---|---|---|
| Moduł | wyłączony | Pole jest wymagane, gdy koszyk wymaga wysyłki, pole było w formularzu i wybrana metoda to nie odbiór osobisty. |
| Miejsce | tylko shortcode | `[ship_today_checkout]` (musi być wewnątrz formularza checkoutu), pod uwagami do zamówienia (`woocommerce_after_order_notes`) albo nad podsumowaniem (`woocommerce_checkout_before_order_review`). |
| Nazwa pola | `Termin dostawy` | Legenda pola, wiersz w podsumowaniu, komunikat walidacji. |
| Liczba terminów | 6 | 1-12 kafelków. |
| Zaznacz najbliższy termin | tak | |
| Duży napis na kafelku | data dostawy | Albo data wysyłki. Bez ustawionej dostawy zawsze data wysyłki. |
| Kolor zaznaczenia | ciemnoszary | HEX albo zmienna CSS `--wc-stn-accent`. |
| Iconic Delivery Slots | - | Patrz niżej. |

> **Uwaga:** przy miejscu "tylko shortcode" trzeba wstawić `[ship_today_checkout]` do formularza checkoutu. Bez tego
> (albo z shortcode poza formularzem) pole się nie pokaże, a zamówienia będą składane bez daty wysyłki. Panel pokazuje
> przy tym ustawieniu ostrzeżenie.

Działanie:

- Kafelki z najbliższymi terminami. Gdy godzina graniczna minie na otwartej stronie, kafelek znika, dochodzi kolejny,
  a zaznaczenie przechodzi na najbliższy z komunikatem. Zmiana kafelka odświeża podsumowanie (`update_checkout`).
- Wiersz z terminem w tabeli podsumowania zamówienia.
- Przy składaniu zamówienia serwer sprawdza termin jeszcze raz (`woocommerce_after_checkout_validation`). Termin, którego
  godzina graniczna minęła, jest odrzucany z komunikatem.
- Data jest wymagana tylko wtedy, gdy pole było w wysłanym formularzu (ukryte pole `wc_stn_rendered`). Brak shortcode
  albo shortcode poza formularzem nie blokuje zamówień.
- Odbiór osobisty: gdy wszystkie wybrane metody wysyłki to odbiór (`local_pickup`, `pickup_location`, filtr
  `woocommerce_local_pickup_methods`), data nie jest wymagana. Kafelki zostają: wybrany termin zapisze się w zamówieniu,
  brak wyboru też przejdzie.
- Zapis w zamówieniu przez CRUD (`woocommerce_checkout_create_order`) - działa z HPOS i starą tabelą postów.
- Wiersze "Data wysyłki" i "Planowana dostawa" na stronie podziękowania, w mailach (klient i admin) i w Moje konto
  (`woocommerce_get_order_item_totals`).
- Panel zamówienia: daty i pole "Zmień datę wysyłki" (dostawa przeliczana automatycznie, notatka w zamówieniu z nazwą użytkownika).
  Puste pole usuwa daty. Błędna data (np. 31.02) nie jest zapisywana, panel pokazuje komunikat. Klient nie dostaje powiadomienia o zmianie.
- Lista zamówień (HPOS i stara): kolumna "Wysyłka" za datą zamówienia, sortowanie i filtr "Wysyłka: dd.mm.rrrr"
  (dni wysyłki od 14 dni wstecz do 31 dni w przód). Sortowanie pokazuje tylko zamówienia z datą z tego modułu.
  Zamówienia z Iconic mają datę z dopiskiem "Iconic" (tylko odczyt, bez migracji).

### Checkout blokowy

Wymaga WooCommerce 10.1+: reguły warunkowe pól są od 9.9, ukrywanie pola w potwierdzeniu i mailach od 10.1.
Na starszej wersji pole się nie rejestruje.

W checkoucie blokowym nie ma kafelków. Wtyczka rejestruje pole `wc-stn/ship-date` (Additional Checkout Fields API,
`select` w sekcji dodatkowych informacji o zamówieniu), bez własnego JS:

- Opcje to najbliższe terminy (ustawienie „Liczba terminów”), etykieta jak na kafelku, np. `pt 23.10, wysyłka czw 22.10`.
  „Zaznacz najbliższy termin” ustawia pierwszą opcję. Ustawienia „Miejsce”, „Duży napis” i „Kolor zaznaczenia” nie mają tu znaczenia.
- Pole jest wymagane i widoczne tylko, gdy koszyk wymaga wysyłki.
- Serwer (Store API) przyjmuje tylko termin z aktualnej listy. Termin, którego godzina graniczna minęła na otwartej stronie,
  jest odrzucany komunikatem „Przyjmowanie zamówień na wysyłkę … już się zakończyło. Wybierz inny termin.”.
  Lista w polu odświeża się dopiero po przeładowaniu strony.
- Zapis tych samych meta co w klasycznym checkoucie (`woocommerce_store_api_checkout_update_order_from_request`), więc maile,
  strona podziękowania, panel i lista zamówień działają tak samo. WooCommerce zapisuje też własną kopię
  `_wc_other/wc-stn/ship-date`. Nie pokazuje jej w potwierdzeniu ani w mailach, a w panelu zamówienia jest ukryta.
- Przełącznik Iconic działa tylko w klasycznym checkoucie.
- Na stronie z blokiem checkoutu nie są ładowane `checkout.css` i `checkout.js` (kafelki klasycznego checkoutu).

### Okres przejściowy z Iconic Delivery Slots

Gdy Iconic jest aktywny, w ustawieniach pojawia się "Wyłącz Iconic Delivery Slots w koszyku i checkoucie". Przy włączonym
module wtyczka wtedy:

- zwraca `false` w `iconic_wds_delivery_slots_allowed`,
- zdejmuje (na `wp_loaded` 99) hooki Iconic od pól, danych, walidacji i zapisu meta,
- zastępuje shortcode `[iconic-wds-fields]` pustym tekstem,
- wyłącza skrypt `jckwds-script` i styl `jckwds-style` w koszyku i checkoucie.

Iconic zostaje aktywny w panelu (stare zamówienia, jego kolumna i strona Deliveries), ale nowe zamówienia już do niego nie
trafiają. Sprawdzone na Iconic 2.13.0. Wycofanie: odznacz przełącznik i przywróć `[iconic-wds-fields]` w checkoucie.

## Meta zamówień

| Klucz | Wartość |
|---|---|
| `_wc_stn_ship_date` | data wysyłki `Y-m-d` |
| `_wc_stn_delivery_date` | data dostawy `Y-m-d` albo pusty tekst |
| `_wc_stn_cutoff` | znacznik czasu godziny granicznej wybranego terminu (usuwany przy ręcznej zmianie daty) |

Odczytywane (Iconic, tylko do wyświetlania): `jckwds_date` (panel zamówienia), `jckwds_date_ymd` (lista zamówień).

## API dla programistów

### Funkcje PHP

Daty jako `Y-m-d` w strefie czasowej sklepu.

| Funkcja | Zwraca |
|---|---|
| `wc_stn_get_slots( $count = 1, $now = null )` | najbliższe terminy, których godzina graniczna jeszcze nie minęła: tablica `['cutoff' => DateTimeImmutable, 'ship' => DateTimeImmutable, 'delivery' => ?DateTimeImmutable]`. `$now` przyjmuje `DateTime` lub `DateTimeImmutable`. |
| `wc_stn_delivery_for_ship_date( $ship_ymd )` | data dostawy dla daty wysyłki (`Y-m-d` lub `Ymd`) albo `null`, gdy dostawa = 0 lub data jest błędna |
| `wc_stn_is_blocked_date( $ymd )` | czy data to nieignorowane święto albo data wykluczona (bez względu na dzień tygodnia); `false` dla błędnej daty |
| `wc_stn_is_shipping_day( $ymd )` | czy to dzień wysyłki (dzień tygodnia z ustawień, nie święto, nie data wykluczona); `false` dla błędnej daty |

### Filtry

| Filtr | Argumenty | Do czego |
|---|---|---|
| `wc_ship_today_notice_now` | `DateTimeImmutable $now` | Podmiana "teraz" (używa go tryb testowy). |
| `wc_ship_today_notice_is_shipping_day` | `bool $is`, `string $ymd` | Własne dni wysyłki / blokady. |
| `wc_ship_today_notice_cutoff_time` | `string $time` (`H:i`), `int $weekday` (1 = pn), `string $ship_ymd` | Inna godzina graniczna dla konkretnego dnia. |
| `wc_ship_today_notice_slots` | `array $slots`, `int $count`, `DateTimeImmutable $now` | Zmiana listy terminów (widget, checkout, walidacja). |
| `wc_ship_today_notice_text` | `string $text`, `string $state` (`next`, `before`, `after`, `not_shipping`), `string $mode` | Tekst widgetu przed wstawieniem tokenów. |

### Akcje

| Akcja | Kiedy |
|---|---|
| `wc_ship_today_notice_purge_cache` | O godzinie granicznej (+5 s, WP-Cron) i po zapisie ustawień, razem z `litespeed_purge_all`. Podepnij czyszczenie innego cache. |

Zdarzenia WP-Cron: `wc_ship_today_notice_cron_sync_holidays` (święta, co ok. 4 miesiące) i
`wc_ship_today_notice_cron_purge_cache` (pojedyncze zdarzenie na najbliższą godzinę graniczną).

### JS

```js
window.WcShipTodayNotice.init(root); // inicjuje widgety w treści doładowanej AJAX-em (popupy, PJAX); root domyślnie document
```

Widget czyta konfigurację z atrybutu `data-stn`. Każdy element jest inicjowany tylko raz.

## Tryb testowy

Użytkownik z `manage_woocommerce` może dodać do adresu dowolnej strony `?stn_now=2026-12-20T19:59`. "Teraz" zmienia się
wtedy w PHP i w JS (widget, kafelki, walidacja checkoutu). Data jest interpretowana w strefie czasowej sklepu.

## Testy

```bash
scripts/lint.sh                                     # php -l + node --check, bez WordPressa
wp eval-file wp-content/plugins/wc-ship-today-notice/tests/cli-schedule-test.php
```

`cli-schedule-test.php` to tabela przypadków silnika (godziny graniczne, święta, przełom roku, zmiana czasu, teksty).
Wymaga WordPressa i pobranych świąt PL na lata 2026-2027. Nie zmienia zapisanych ustawień.

## Tłumaczenia i WPML

- Text domain: `wc-ship-today-notice`. Pliki `.pot`, `pl_PL` i `de_DE` w `languages/`. Plik z `wp-content/languages/plugins/`
  ma pierwszeństwo.
- Tłumaczona jest część panelu i domyślne teksty widgetu. Część panelu i cały front (dni tygodnia, "dziś"/"jutro",
  kafelki, komunikaty walidacji, wiersze w mailach) są zapisane w kodzie po polsku.
- WPML: teksty widgetu (`text_next_slot`, `text_before_cutoff`, `text_after_cutoff`, `text_not_shipping_day`) są rejestrowane
  jako stringi w domenie `wc-ship-today-notice` przy zapisie ustawień.

## Dane w bazie

- Opcja `wc_ship_today_notice_options` - wszystkie ustawienia (tablica).
- Opcja `wc_ship_today_notice_style` - ustawienia zakładki „Styl” (tablica, puste wartości = domyślne).
- Opcja `wc_ship_today_notice_version` - wersja do migracji (1.x → 2.0: ignorowane święta z ID na daty).
- Tabela `{prefix}wc_ship_today_notice_holidays` - pobrane święta.

Dezaktywacja usuwa wszystkie zdarzenia cron wtyczki (`wp_clear_scheduled_hook`). Usunięcie wtyczki (`uninstall.php`) kasuje trzy opcje,
tabelę świąt i zdarzenia cron (także na każdej stronie multisite). Meta zamówień (`_wc_stn_*`) zostają w zamówieniach.

## Struktura plików

```
wc-ship-today-notice/
├── wc-ship-today-notice.php        # Główny plik: panel, shortcode widgetu, święta, cron, cache, tryb testowy, WPML
├── uninstall.php                   # Usuwanie: opcje, tabela świąt, cron (meta zamówień zostają)
├── readme.txt                      # Readme w formacie WordPress.org (po angielsku)
├── index.php
├── includes/
│   ├── class-wc-stn-schedule.php   # Silnik terminów (dni wysyłki, godzina graniczna, dostawa, tokeny)
│   ├── class-wc-stn-checkout.php   # Wybór terminu w checkoucie, zamówienia, lista zamówień, przełącznik Iconic
│   └── functions.php               # Publiczne API wc_stn_*
├── assets/
│   ├── css/notice.css              # Widget
│   ├── css/checkout.css            # Kafelki w checkoucie
│   ├── js/notice.js                # Widget: stany, odliczanie, przełączanie terminów
│   ├── js/checkout.js              # Kafelki: wybór, upływ godziny granicznej
│   └── js/style-preview.js         # Podgląd na żywo w zakładce "Styl"
├── languages/
│   ├── wc-ship-today-notice.pot
│   ├── wc-ship-today-notice-pl_PL.po / .mo
│   └── wc-ship-today-notice-de_DE.po / .mo
├── tests/cli-schedule-test.php     # Tabela przypadków silnika (wp eval-file)
├── scripts/lint.sh                 # Lint PHP i JS
├── scripts/build-zip.sh            # Paczka build/wc-ship-today-notice.zip (bez plików z .distignore)
├── .distignore                     # Co nie trafia do paczki (testy, skrypty, README.md, wp-env)
└── README.md
```

`.wordpress-org/` to zrzuty ekranu (`screenshot-N.png`, opisy w sekcji Screenshots w `readme.txt`) i docelowo ikona i banner
do katalogu `assets/` w SVN WordPress.org. Nie wchodzi do paczki.

## Changelog

### Niewydane
- Wybór terminu w checkoucie blokowym, WooCommerce 10.1+ (pole Additional Checkout Fields, walidacja w Store API, te same meta zamówienia);
  deklaracja zgodności z checkoutem blokowym.
- Przygotowanie do WordPress.org: `readme.txt`, nagłówek wtyczki (licencja, wymagania WP/PHP/WooCommerce,
  `Requires Plugins`), `uninstall.php`, `.distignore` i `scripts/build-zip.sh`.
- Minimalna wersja WordPressa: 6.8 (tłumaczenia ładuje WordPress, bez `load_plugin_textdomain`).
- Zapytania do tabeli świąt przez `$wpdb->prepare()` z `%i`, poprawki escapingu w panelu.
- Zakładka "Styl": kolory przed/po godzinie granicznej, kolor odliczania, ramka, zaokrąglenie, odstęp, czcionka
  i wyrównanie, z podglądem na żywo i "Przywróć domyślne".
- Nowe zmienne CSS w `notice.css` (domyślny wygląd bez zmian).
- Poprawka: włączony moduł checkoutu bez wyrenderowanego pola (brak shortcode albo shortcode poza formularzem) blokował
  wszystkie zamówienia z wysyłką. Teraz data jest wymagana tylko, gdy pole było w formularzu. W panelu jest ostrzeżenie
  przy miejscu "tylko shortcode".
- Odbiór osobisty (`local_pickup`, `pickup_location`) nie wymaga daty.
- Kierownik sklepu (`manage_woocommerce`) może zapisywać ustawienia i styl (wcześniej `options.php` wymagał `manage_options`).
- Deklaracja zgodności z HPOS.
- Tłumaczenia nie ładują się przed `init` (notice `_load_textdomain_just_in_time` w WP 6.7+): moduł checkoutu startuje na `init`.
- Pierwszy zapis ustawień (`add_option`) rejestruje stringi WPML i czyści cache, tak jak kolejne zapisy.
- Dezaktywacja usuwa wszystkie zaplanowane zdarzenia cron wtyczki, a nie tylko najbliższe.
- Edycja daty wysyłki w zamówieniu odrzuca nieistniejące daty (np. 2026-02-31) z komunikatem.
- `wc_stn_is_shipping_day()` i `wc_stn_is_blocked_date()` zwracają `false` dla błędnej daty zamiast rzucać wyjątek.

### 2.1.0
- Moduł "Wybór terminu w checkoucie": kafelki, walidacja przy składaniu zamówienia, zapis w zamówieniu, maile, panel
  zamówienia z edycją daty, kolumna z sortowaniem i filtrem na liście zamówień (HPOS i stara tabela).
- Przełącznik wyłączający Iconic Delivery Slots w koszyku i checkoucie (okres przejściowy).

### 2.0.0
- Tryb "najbliższy termin" i godzina graniczna przed dniem wysyłki.
- Data dostawy (dni doręczeń po wysyłce, dni bez doręczeń, święta).
- Tokeny z datami i polską odmianą dni, atrybuty shortcode `count`, `text`, `mode`, `class`.
- Odliczanie z dniami i automatyczne przełączanie terminu po godzinie granicznej.
- Harmonogram w HTML zamiast AJAX: usunięty endpoint `wc_ship_today_notice_get` z nonce, który na stronach z cache
  po 12-24 h zwracał 403.
- Święta na bieżący i następny rok, ignorowanie świąt po dacie (wcześniej po ID); migracja ustawień z 1.x.
- Podgląd 5 najbliższych terminów w panelu, tryb testowy `?stn_now=`.
- Funkcje `wc_stn_*`, filtry, tabela testów silnika.
- Czyszczenie cache LiteSpeed o godzinie granicznej i po zapisie ustawień.

### 1.1.0
- Godziny graniczne dla każdego dnia tygodnia, daty wykluczone jako textarea.
- Lista pobranych świąt z możliwością ignorowania.
- Checkboxy dla tekstów po godzinie granicznej i w dni bez wysyłki, ustawienia widoczności widgetu.
- Tłumaczenie pl_PL.

### 1.0.0
- Pierwsza wersja: odliczanie, OpenHolidays API, panel w WooCommerce, WPML.

## TODO

- [ ] Kafelki zamiast selecta w checkoucie blokowym (wymaga własnego bloku JS).
- [ ] Tłumaczenie tekstów frontu i reszty panelu.
- [ ] Różne godziny graniczne dla stref wysyłki WooCommerce.

## Autorzy

- [MS-M.pl](https://ms-m.pl)

## Licencja

GPL-2.0-or-later.
