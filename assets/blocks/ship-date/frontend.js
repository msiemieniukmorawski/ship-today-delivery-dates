/**
 * Bloki checkoutu blokowego na froncie:
 * - "wc-stn/ship-date": kafelki z terminami (jak checkout.js w klasycznym checkoucie),
 * - "wc-stn/ship-date-row": wiersz z wybranym terminem w podsumowaniu zamówienia (jak review_row() w klasycznym).
 * Wybrany termin oba bloki dzielą przez mały store wp.data "wc-stn/ship-date" (kafelki zapisują, wiersz czyta).
 *
 * - termin idzie do Store API przez setExtensionData('wc-stn', 'ship_date'), razem z 'rendered' (blok był widoczny),
 * - bez wybranego terminu blok zgłasza błąd walidacji (wc/store/validation), więc zamówienie nie wyjdzie bez daty,
 * - gdy godzina graniczna minie na otwartej stronie, kafelek znika, dochodzi kolejny, a zaznaczenie przechodzi na
 *   najbliższy z komunikatem. Ostateczna kontrola i tak jest na serwerze (WC_STN_Checkout_Blocks::validate_and_save),
 * - harmonogramy metod wysyłki: terminy dla stawki wybranej w koszyku (cfg.methods["metoda:instancja"], inaczej cfg.tiles);
 *   zmiana metody zostawia zaznaczenie, jeśli termin istnieje dla nowej metody, inaczej przechodzi na najbliższy z komunikatem.
 * Bez kroku build: wp.element.createElement zamiast JSX.
 */
(function (wc, wp) {
  'use strict';

  var el = wp.element.createElement;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var useRef = wp.element.useRef;
  var useSelect = wp.data.useSelect;
  var useDispatch = wp.data.useDispatch;
  var cfg = wc.wcSettings.getSetting('wc-stn-ship-date_data', null);
  var ERROR_ID = 'wc-stn-ship-date';

  if (!cfg) {
    return;
  }

  var STORE = 'wc-stn/ship-date';
  wp.data.register(wp.data.createReduxStore(STORE, {
    reducer: function (state, action) {
      state = state || { shipDate: '' };
      return 'SET_SHIP_DATE' === action.type ? { shipDate: action.shipDate } : state;
    },
    actions: {
      setShipDate: function (shipDate) {
        return { type: 'SET_SHIP_DATE', shipDate: shipDate };
      }
    },
    selectors: {
      getShipDate: function (state) {
        return state.shipDate;
      }
    }
  }));

  function nowS() {
    return Math.floor((Date.now() + (cfg.nowOffset || 0)) / 1000);
  }

  /** Wszystkie terminy dla metody wysyłki ("flat_rate:3"); metoda bez własnych godzin = globalne. */
  function tilesFor(method) {
    return (cfg.methods && cfg.methods[method]) || cfg.tiles;
  }

  /** Terminy, których godzina graniczna jeszcze nie minęła (pierwsze cfg.count). */
  function openTiles(now, method) {
    return tilesFor(method).filter(function (tile) {
      return tile.cutoff > now;
    }).slice(0, cfg.count);
  }

  /** Metoda wysyłki wybrana w koszyku (pierwsza paczka): "method_id:instance_id" albo ''. */
  function selectedMethod(select) {
    var packages = select('wc/store/cart').getShippingRates() || [];
    var rate = packages[0] && (packages[0].shipping_rates || []).filter(function (r) { return r.selected; })[0];
    return rate ? rate.method_id + ':' + rate.instance_id : '';
  }

  function label(tile) {
    return tile.label || tile.main;
  }

  function ShipDate(props) {
    var setExtensionData = props.checkoutExtensionData.setExtensionData;
    var visible = useSelect(function (select) {
      return select('wc/store/cart').getNeedsShipping() && !select('wc/store/checkout').prefersCollection();
    }, []);
    var validation = useDispatch('wc/store/validation');
    var store = useDispatch(STORE);
    // Sam tekst błędu (string), nie obiekt: useSelect porównuje wynik i obiekt dawałby zbędne odświeżenia
    var errorText = useSelect(function (select) {
      var error = select('wc/store/validation').getValidationError(ERROR_ID);
      return error && !error.hidden ? error.message : '';
    }, []);

    var method = useSelect(selectedMethod, []);
    var methodRef = useRef(method);
    var initial = openTiles(nowS(), method);
    var state = useState({ now: nowS(), value: cfg.preselect && initial.length ? initial[0].value : '', notice: '' });
    var current = state[0];
    var setCurrent = state[1];
    var tiles = openTiles(current.now, method);

    // Zmiana metody wysyłki: terminy nowej metody, zaznaczenie zostaje albo przechodzi na najbliższy z komunikatem
    useEffect(function () {
      var prevMethod = methodRef.current;
      methodRef.current = method;
      if (prevMethod === method) {
        return;
      }
      setCurrent(function (prev) {
        var now = nowS();
        var open = openTiles(now, method);
        if (!prev.value) {
          return prev;
        }
        var old = tilesFor(prevMethod).filter(function (tile) { return tile.value === prev.value; })[0];
        // Ten sam widoczny termin (duży napis, np. ta sama data dostawy z innej daty wysyłki), potem ta sama data wysyłki - bez komunikatu
        var same = (old && open.filter(function (tile) { return label(tile) === label(old); })[0]) ||
          open.filter(function (tile) { return tile.value === prev.value; })[0];
        if (same) {
          return { now: now, value: same.value, notice: '' };
        }
        return {
          now: now,
          value: open.length ? open[0].value : '',
          notice: open.length && old && cfg.moved
            ? cfg.moved.split('{slot}').join(label(old)).split('{next_slot}').join(label(open[0]))
            : ''
        };
      });
    }, [method]);

    // Zegar: co sekundę sprawdza, czy wybrany termin się nie zamknął
    useEffect(function () {
      var timer = setInterval(function () {
        setCurrent(function (prev) {
          var now = nowS();
          var open = openTiles(now, methodRef.current);
          var still = !prev.value || open.some(function (tile) { return tile.value === prev.value; });
          if (still) {
            return prev.now === now ? prev : { now: now, value: prev.value, notice: prev.notice };
          }
          var closed = tilesFor(methodRef.current).filter(function (tile) { return tile.value === prev.value; })[0];
          return {
            now: now,
            value: open.length ? open[0].value : '',
            // {slot} / {next_slot}: tile.label (domyślny duży napis), niezależnie od własnych tekstów kafelka
            notice: open.length && closed
              ? cfg.closed.split('{slot}').join(closed.label || closed.main).split('{next_slot}').join(open[0].label || open[0].main)
              : ''
          };
        });
      }, 1000);
      return function () { clearInterval(timer); };
    }, []);

    // Dane dla Store API i walidacja po stronie klienta
    useEffect(function () {
      setExtensionData('wc-stn', 'rendered', visible);
      setExtensionData('wc-stn', 'ship_date', visible ? current.value : '');
      store.setShipDate(visible ? current.value : '');

      if (visible && !current.value) {
        var errors = {};
        errors[ERROR_ID] = { message: cfg.choose, hidden: true };
        validation.setValidationErrors(errors);
      } else {
        validation.clearValidationError(ERROR_ID);
      }
    }, [visible, current.value]);

    if (!visible) {
      return null;
    }

    var style = cfg.accent ? { '--wc-stn-accent': cfg.accent } : undefined;

    if (!tiles.length) {
      return el('p', { className: 'wc-stn-checkout__empty' }, cfg.empty);
    }

    return el('fieldset', { className: 'wc-stn-checkout wc-block-components-checkout-step', style: style },
      el('legend', { className: 'wc-stn-checkout__legend' },
        cfg.label, ' ', el('abbr', { className: 'required', title: cfg.required }, '*')
      ),
      el('div', { className: 'wc-stn-checkout__tiles' }, tiles.map(function (tile) {
        var checked = tile.value === current.value;
        return el('label', { key: tile.value, className: 'wc-stn-tile' + (checked ? ' is-checked' : '') },
          el('input', {
            type: 'radio',
            className: 'wc-stn-tile__input',
            name: 'wc_stn_ship_date',
            value: tile.value,
            checked: checked,
            'aria-label': tile.aria,
            onChange: function () {
              setCurrent({ now: nowS(), value: tile.value, notice: '' });
            }
          }),
          el('span', { className: 'wc-stn-tile__main' }, tile.main),
          tile.sub ? el('span', { className: 'wc-stn-tile__sub' }, tile.sub) : null
        );
      })),
      current.notice ? el('p', { className: 'wc-stn-checkout__notice', role: 'status', 'aria-live': 'polite' }, current.notice) : null,
      errorText ? el('p', { className: 'wc-block-components-validation-error', role: 'alert' }, errorText) : null
    );
  }

  /** Wiersze podsumowania dla metody wysyłki (data wysyłki => [wysyłka, dostawa]); metoda bez własnych godzin = globalne. */
  function rowsFor(method) {
    return (cfg.methodRows && cfg.methodRows[method]) || cfg.rows;
  }

  /**
   * Wiersz w podsumowaniu: tylko gdy blok kafelków jest na stronie, widoczny i ma wybrany termin.
   * Treść wg metody wybranej w koszyku (ta sama data wysyłki może mieć u metody inną datę dostawy).
   */
  function ShipDateRow() {
    var shipDate = useSelect(function (select) {
      return select(STORE).getShipDate();
    }, []);
    var method = useSelect(selectedMethod, []);
    var lines = cfg.reviewRow && shipDate ? rowsFor(method)[shipDate] : null;

    if (!lines) {
      return null;
    }

    return el(wc.blocksCheckout.TotalsItem, {
      className: 'wc-block-components-totals-wrapper wc-stn-review',
      label: cfg.label,
      value: el('span', null, lines[0], lines[1] ? el('br') : null, lines[1] || null)
    });
  }

  wc.blocksCheckout.registerCheckoutBlock({
    metadata: { name: 'wc-stn/ship-date-row', parent: ['woocommerce/checkout-order-summary-totals-block'] },
    component: ShipDateRow,
    force: false
  });

  wc.blocksCheckout.registerCheckoutBlock({
    metadata: { name: 'wc-stn/ship-date', parent: ['woocommerce/checkout-fields-block'] },
    component: ShipDate,
    // Nie wymuszony: wymuszone bloki WooCommerce dokłada na koniec formularza (pod "Złóż zamówienie") i nie da się ich usunąć.
    // Do strony zamówienia wstawia go raz PHP (WC_STN_Checkout_Blocks::maybe_insert_block), dalej decyduje sklep.
    force: false
  });
})(window.wc, window.wp);
