/**
 * Frontend of the checkout blocks:
 * - "wc-stn/ship-date": slot tiles, like checkout.js in the classic checkout,
 * - "wc-stn/ship-date-row": selected slot in the order summary, like review_row() in the classic checkout.
 * They share the selected date through a small wp.data store (tiles write, the row reads).
 *
 * The date goes to the Store API with setExtensionData, together with 'rendered' (the block was visible). The server has
 * the final say, see WC_STN_Checkout_Blocks::validate_and_save(). Slots follow the cart rate (cfg.methods["method:instance"],
 * otherwise cfg.tiles).
 * No build step, so wp.element.createElement instead of JSX.
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

  /** All slots of a shipping method ("flat_rate:3"). A method without its own hours gets the global ones. */
  function tilesFor(method) {
    return (cfg.methods && cfg.methods[method]) || cfg.tiles;
  }

  /** First cfg.count slots whose cutoff has not passed. */
  function openTiles(now, method) {
    return tilesFor(method).filter(function (tile) {
      return tile.cutoff > now;
    }).slice(0, cfg.count);
  }

  /** Shipping method selected in the cart (first package): "method_id:instance_id" or ''. */
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
    // A string, not the error object: useSelect compares results and an object would re-render every time
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

    // Shipping method change: keep the selection if possible, otherwise move to the nearest slot with a notice
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
        // Same visible slot first (e.g. same delivery date from another ship date), then same ship date: no notice
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
            // tile.label: the default large text, regardless of custom tile texts
            notice: open.length && closed
              ? cfg.closed.split('{slot}').join(closed.label || closed.main).split('{next_slot}').join(open[0].label || open[0].main)
              : ''
          };
        });
      }, 1000);
      return function () { clearInterval(timer); };
    }, []);

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

  /** Summary rows of a method (ship date => [ship, delivery]). A method without its own hours gets the global ones. */
  function rowsFor(method) {
    return (cfg.methodRows && cfg.methodRows[method]) || cfg.rows;
  }

  /**
   * Summary row, only when the tiles block is on the page, visible and has a selected date.
   * Follows the cart method: the same ship date can have another delivery date there.
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
    // Not forced: WooCommerce appends forced blocks below the place order button and they can't be removed.
    // PHP inserts it into the checkout page once, see WC_STN_Checkout_Blocks::maybe_insert_block().
    force: false
  });
})(window.wc, window.wp);
