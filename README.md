# WooCommerce Ship Today Notice

![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue)
![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-purple)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4)
![License](https://img.shields.io/badge/license-GPL--2.0%2B-green)

Wtyczka WordPress/WooCommerce wyświetlająca dynamiczne powiadomienie z odliczaniem do wysyłki tego samego dnia. Informuje klientów ile czasu pozostało na złożenie zamówienia, aby przesyłka została wysłana jeszcze dzisiaj.

## Funkcjonalności

- ✅ **Dwa tryby** (2.0): "wysyłka dziś" (jak w 1.x) albo "najbliższy termin": zawsze odliczanie do najbliższej godziny granicznej i tekst z konkretnymi dniami, np. "Zamów do niedzieli 20:00, dostarczymy we wtorek"
- ✅ **Godzina graniczna przed dniem wysyłki** (2.0): np. wysyłka w poniedziałek, zamówienia do niedzieli 20:00
- ✅ **Data dostawy** (2.0): N dni doręczeń po wysyłce, z pominięciem dni bez doręczeń i świąt
- ✅ **Tokeny z polską odmianą dni** (2.0): `{cutoff_when}`, `{delivery_when}` itd. ("dziś" / "jutro" / "do niedzieli" / "we wtorek")
- ✅ **Działa ze stron w cache** (2.0): harmonogram w HTML zamiast AJAX z nonce, automatyczne przełączanie terminu po godzinie granicznej
- ✅ **Podgląd 5 najbliższych terminów** w panelu i tryb testowy `?stn_now=`
- ✅ **API dla innych wtyczek** (2.0): funkcje i filtry, np. do synchronizacji kalendarza dostaw w checkoucie
- ✅ **Dynamiczny countdown** — odliczanie w czasie rzeczywistym do godziny granicznej
- ✅ **Różne cutoff times per dzień** — możliwość ustawienia różnych godzin granicznych dla każdego dnia tygodnia
- ✅ **Automatyczne pobieranie świąt** — integracja z [OpenHolidays API](https://openholidaysapi.org/)
- ✅ **Lista świąt z możliwością wykluczenia** — po fetch można zaznaczyć które święta ignorować
- ✅ **Konfigurowalne dni wysyłki** — wybór dni tygodnia, w których realizowana jest wysyłka
- ✅ **Wykluczenie dat** — textarea do wprowadzenia dat wykluczonych
- ✅ **Trzy komunikaty z checkboxami** — osobne teksty przed cutoff, po cutoff i w dni bez wysyłki, z możliwością włączenia/wyłączenia
- ✅ **Ustawienia widoczności widgetu** — zawsze / tylko przed cutoff / X godzin przed cutoff
- ✅ **Informacja o shortcode** — widoczna na stronie ustawień
- ✅ **Opisy pól ISO** — wyjaśnienia dla Country/Language ISO codes
- ✅ **Tłumaczenie polskie** — pełny słownik pl_PL
- ✅ **Kompatybilność z WPML** — pełne wsparcie dla tłumaczeń stringów
- ✅ **Responsywny design** — gotowe style CSS

## Instalacja

1. Pobierz wtyczkę i rozpakuj do katalogu `/wp-content/plugins/wc-ship-today-notice/`
2. Aktywuj wtyczkę w panelu WordPress → Wtyczki
3. Przejdź do WooCommerce → Ship Today Notice aby skonfigurować ustawienia
4. Dodaj shortcode `[ship_today_notice]` w wybranym miejscu

## Użycie

### Shortcode

```
[ship_today_notice]
```

Umieść shortcode na stronie produktu, w koszyku, w widgetach lub dowolnym innym miejscu.

Atrybuty (2.0):

```
[ship_today_notice count="2" text="Zamów do {cutoff_day} {cutoff_time}, a dostaniesz ją {delivery_day}."]
[ship_today_notice mode="next_slot"]
```

- `count`: lista kolejnych terminów (np. w popupie), domyślnie 1
- `text`: własny tekst z tokenami zamiast tekstu z ustawień
- `mode`: `today` albo `next_slot`, nadpisuje tryb z ustawień
- `class`: dodatkowe klasy CSS na kontenerze (np. osobny wygląd na karcie produktu)

### Tokeny w tekstach

| Token | Przykład |
|-------|----------|
| `{countdown}` | 1 dzień 05h 12min |
| `{cutoff_when}` | dziś do 20:00 / jutro do 20:00 / do niedzieli 20:00 |
| `{cutoff_day}`, `{cutoff_time}`, `{cutoff_date}` | niedzieli, 20:00, 25.10 |
| `{ship_when}`, `{ship_day}`, `{ship_date}` | jutro / w poniedziałek, w poniedziałek, 26.10 |
| `{delivery_when}`, `{delivery_day}`, `{delivery_date}` | jutro / we wtorek, we wtorek, 27.10 (wymaga ustawienia dostawy) |

Określenia "dziś" i "jutro" liczy przeglądarka, więc są poprawne także na stronie z cache.

### Przykładowe umiejscowienia

- Strona pojedynczego produktu (przez edycję szablonu lub Elementor/Gutenberg)
- Strona koszyka
- Sidebar jako widget tekstowy
- Header lub footer sklepu

## Konfiguracja

### Ustawienia główne

| Opcja | Opis |
|-------|------|
| **Tryb komunikatu** | "Wysyłka dziś" (1.x) albo "Najbliższy termin" |
| **Delivery weekdays & cutoff times** | Tabela dni tygodnia z checkboxami, godziną graniczną i dniem, w którym ona wypada (tego samego dnia / dzień wcześniej / ...) |
| **Dostawa** | Ile dni doręczeń po wysyłce (0 = bez daty dostawy) i dni bez doręczeń |
| **Excluded dates** | Textarea z datami wykluczonymi z wysyłki (format dd-mm-yyyy, jedna na linię lub przecinkami) |
| **Najbliższe terminy** | Podgląd 5 terminów na zapisanych ustawieniach |

### Widoczność widgetu

| Opcja | Opis |
|-------|------|
| **Always visible** | Widget widoczny zawsze |
| **Only before cutoff time** | Widget ukrywa się po godzinie granicznej |
| **X hour(s) before cutoff** | Widget pokazuje się tylko określoną liczbę godzin przed cutoff |

### API świąt publicznych

| Opcja | Opis |
|-------|------|
| **Country ISO code** | Dwuliterowy kod kraju (np. PL, DE, CZ) z opisem i linkiem do dokumentacji |
| **Language ISO code** | Dwuliterowy kod języka dla nazw świąt |
| **Fetched holidays** | Lista pobranych świąt z checkboxami do ignorowania wybranych |

### Teksty frontend

| Opcja | Opis |
|-------|------|
| **Text before cutoff** | Komunikat przed godziną graniczną. Token: `{countdown}` |
| **Text after cutoff** | Komunikat po przekroczeniu godziny granicznej + checkbox aktywacji |
| **Text when not a shipping day** | Komunikat w dni bez wysyłki + checkbox aktywacji |

### Przykładowe teksty

```
Text before cutoff:
🚚 Zamów w ciągu {countdown} — wyślemy dziś!

Text after cutoff:
📦 Zamówienie zostanie wysłane jutro.

Text when not a shipping day:
📦 Wyślemy w najbliższy dzień roboczy.
```

## Struktura plików

```
wc-ship-today-notice/
├── wc-ship-today-notice.php    # Główny plik wtyczki (panel, shortcode, święta, cache)
├── index.php                   # Pusty plik zabezpieczający
├── includes/
│   ├── class-wc-stn-schedule.php  # Silnik terminów (wysyłka, godzina graniczna, dostawa)
│   ├── class-wc-stn-checkout.php  # Moduł wyboru terminu w checkoucie (+ przełącznik Iconic)
│   └── functions.php              # Publiczne API wc_stn_*
├── tests/
│   └── cli-schedule-test.php      # Tabela przypadków (wp eval-file)
├── assets/
│   ├── css/
│   │   ├── notice.css          # Style widgetu
│   │   └── checkout.css        # Kafelki terminów w checkoucie
│   └── js/
│       ├── notice.js           # Logika countdown
│       └── checkout.js         # Kafelki: wybór, upływ godziny granicznej
├── languages/
│   ├── wc-ship-today-notice.pot           # Template tłumaczeń
│   ├── wc-ship-today-notice-pl_PL.po      # Polskie tłumaczenia (source)
│   └── wc-ship-today-notice-pl_PL.mo      # Polskie tłumaczenia (compiled)
└── README.md
```

## Wybór terminu w checkoucie (2.1)

Moduł domyślnie wyłączony (WooCommerce → Ship Today Notice → "Wybór terminu w checkoucie"). Gdy jest wyłączony, nie rejestruje żadnych hooków.

- Kafelki z najbliższymi N terminami (domyślnie 6), najbliższy zaznaczony, duży napis: data dostawy albo wysyłki. Shortcode `[ship_today_checkout]` (musi być wewnątrz formularza checkoutu) albo wstawienie automatyczne.
- Gdy godzina graniczna minie na otwartej stronie, kafelek znika, dochodzi kolejny termin, zaznaczenie przechodzi na najbliższy z komunikatem. Przy składaniu zamówienia serwer sprawdza termin jeszcze raz.
- Wiersz z terminem w podsumowaniu zamówienia (odświeżany przez `update_checkout`).
- Zapis w zamówieniu (HPOS): `_wc_stn_ship_date`, `_wc_stn_delivery_date` (Y-m-d), `_wc_stn_cutoff` (znacznik czasu).
- Wiersze "Data wysyłki" i "Planowana dostawa" na stronie podziękowania, w mailach i w "Moje konto" (`woocommerce_get_order_item_totals`).
- Panel zamówienia: daty i pole zmiany daty wysyłki (dostawa przeliczana, notatka w zamówieniu).
- Lista zamówień: kolumna "Wysyłka", sortowanie i filtr "Wysyłka w dniu". Sortowanie pokazuje tylko zamówienia z datą z tego modułu (jak przy sortowaniu po polu meta w WordPressie). Zamówienia z Iconic mają w kolumnie datę z dopiskiem "Iconic" (tylko odczyt).
- Kolor zaznaczenia: ustawienie albo zmienna CSS `--wc-stn-accent`.

### Okres przejściowy z Iconic Delivery Slots

Gdy Iconic jest aktywny, w ustawieniach pojawia się przełącznik "Wyłącz Iconic Delivery Slots w koszyku i checkoucie". Moduł zdejmuje wtedy hooki Iconic od pól, walidacji i zapisu (na `wp_loaded` 99), zwraca `false` w `iconic_wds_delivery_slots_allowed`, wycisza shortcode `[iconic-wds-fields]` i jego skrypty w koszyku i checkoucie. Iconic zostaje aktywny w panelu dla starych zamówień, ale nowe zamówienia już do niego nie trafiają. Sprawdzone na Iconic 2.13.0.

## API, hooki i filtry (2.0)

Funkcje (daty jako `Y-m-d` w strefie czasowej sklepu):

| Funkcja | Zwraca |
|---------|--------|
| `wc_stn_get_slots( $count = 1, $now = null )` | najbliższe terminy: `cutoff`, `ship`, `delivery` (`DateTimeImmutable`) |
| `wc_stn_delivery_for_ship_date( $ymd )` | data dostawy dla daty wysyłki (przyjmuje też `Ymd`) albo `null` |
| `wc_stn_is_blocked_date( $ymd )` | czy data to święto (nieignorowane) albo data wykluczona |
| `wc_stn_is_shipping_day( $ymd )` | czy to dzień wysyłki |

Filtry:

| Filtr | Argumenty |
|-------|-----------|
| `wc_ship_today_notice_now` | `DateTimeImmutable $now` (podmiana "teraz", np. testy) |
| `wc_ship_today_notice_is_shipping_day` | `bool $is`, `string $ymd` |
| `wc_ship_today_notice_cutoff_time` | `string $time` (H:i), `int $weekday`, `string $ship_ymd` |
| `wc_ship_today_notice_slots` | `array $slots`, `int $count`, `DateTimeImmutable $now` |
| `wc_ship_today_notice_text` | `string $text`, `string $state` (`next`, `before`, `after`, `not_shipping`), `string $mode` |

Akcja `wc_ship_today_notice_purge_cache`: wywoływana o godzinie granicznej i po zapisie ustawień (obok `litespeed_purge_all`), do czyszczenia innych cache.

W JS: `window.WcShipTodayNotice.init( root )` inicjuje widgety w treści doładowanej AJAX-em.

### Tryb testowy

Manager sklepu może dodać do adresu dowolnej strony `?stn_now=2026-12-20T19:59`, żeby zobaczyć widget w innej chwili (PHP i JS).

### Testy

`wp eval-file wp-content/plugins/wc-ship-today-notice/tests/cli-schedule-test.php`: tabela przypadków silnika (godziny graniczne, święta, przełom roku, zmiana czasu, teksty). Wymaga pobranych świąt PL na 2026-2027.

## Wymagania

- WordPress 5.0+
- WooCommerce 5.0+ (zalecane, ale nie wymagane)
- PHP 7.4+

## Changelog

### 2.1.0
- ✅ Moduł "Wybór terminu w checkoucie" (kafelki, walidacja przy składaniu zamówienia, zapis w zamówieniu, maile, panel zamówienia z edycją daty, kolumna z sortowaniem i filtrem na liście zamówień)
- ✅ Przełącznik wyłączający Iconic Delivery Slots w koszyku i checkoucie (okres przejściowy)

### 2.0.0
- ✅ Tryb "najbliższy termin" i godzina graniczna przed dniem wysyłki (przesunięcie dnia)
- ✅ Data dostawy (dni doręczeń po wysyłce, dni bez doręczeń, święta)
- ✅ Tokeny z datami i polską odmianą dni, atrybuty shortcode `count`, `text`, `mode`
- ✅ Odliczanie z dniami ("1 dzień", "3 dni") i automatyczne przełączanie terminu po godzinie granicznej
- ✅ Harmonogram w HTML zamiast AJAX: usunięty endpoint `wc_ship_today_notice_get` z nonce, który na stronach z cache po 12-24 h zwracał 403
- ✅ Święta na bieżący i następny rok, ignorowanie świąt po dacie (wcześniej po ID, które zmieniały się przy każdym pobraniu); migracja ustawień z 1.x
- ✅ Podgląd 5 najbliższych terminów w panelu, tryb testowy `?stn_now=`
- ✅ API (funkcje `wc_stn_*`) i filtry, tabela testów silnika
- ✅ Czyszczenie cache LiteSpeed o godzinie granicznej i po zapisie ustawień

### 1.1.0
- ✅ Różne godziny cutoff dla każdego dnia tygodnia
- ✅ Excluded dates jako textarea (jedna data per linia)
- ✅ Opisy dla Country/Language ISO code z linkami do dokumentacji
- ✅ Lista pobranych świąt z możliwością wykluczenia (ignorowania)
- ✅ Checkbox aktywacji dla "Text after cutoff"
- ✅ Checkbox aktywacji dla "Text when not a shipping day"
- ✅ Ustawienia widoczności widgetu (zawsze / przed cutoff / X godzin przed)
- ✅ Informacja o shortcode na stronie ustawień
- ✅ Przeniesienie przycisku "Fetch holidays" na dół strony
- ✅ Pełne tłumaczenie polskie (pl_PL)
- ✅ Ulepszone style CSS dla różnych stanów widgetu

### 1.0.0
- Pierwsza wersja wtyczki
- Podstawowa funkcjonalność countdown
- Integracja z OpenHolidays API
- Panel administracyjny w WooCommerce
- Wsparcie WPML

---

## Planowane usprawnienia (TODO)

### 🚀 Funkcjonalności

- [x] **Hooki i filtry** (2.0)

- [ ] **Wsparcie dla stref czasowych per użytkownik**  
  Opcjonalnie wykrywać strefę czasową klienta i dostosowywać countdown.

- [ ] **Integracja z WooCommerce Shipping Zones**  
  Możliwość różnych cutoff times dla różnych stref wysyłkowych.

- [x] **Podgląd terminów w panelu** (2.0, tabela 5 najbliższych terminów)

- [x] **Automatyczne odświeżanie po cutoff** (2.0)

---

## Autorzy

- [MS-M.pl](https://ms-m.pl)

## Licencja

GPL-2.0+ — możesz swobodnie używać, modyfikować i dystrybuować.
