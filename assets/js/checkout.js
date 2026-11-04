/**
 * Ship Today & Delivery Dates for WooCommerce: ship date tiles in the classic checkout.
 *
 * Selecting a tile refreshes the order review. When a cutoff passes on an open page the tile goes, the next slot joins
 * and the selection moves to the nearest slot with a notice. With method schedules, a method change rebuilds the tiles
 * from the #stdd-checkout-data fragment. The server has the final say when the order is placed.
 */
(function ($) {
  'use strict';

  function nowS(cfg) {
    return Math.floor((Date.now() + (cfg.nowOffset || 0)) / 1000);
  }

  function buildTile(tile) {
    var label = document.createElement('label');
    var input = document.createElement('input');
    var main = document.createElement('span');

    label.className = 'stdd-tile';
    input.type = 'radio';
    input.className = 'stdd-tile__input';
    input.name = 'stdd_ship_date';
    input.value = tile.value;
    input.setAttribute('data-cutoff', tile.cutoff);
    input.setAttribute('aria-label', tile.aria);
    main.className = 'stdd-tile__main';
    main.textContent = tile.main;

    label.appendChild(input);
    label.appendChild(main);
    // Empty second line = no element, same as the PHP markup
    if (tile.sub) {
      var sub = document.createElement('span');
      sub.className = 'stdd-tile__sub';
      sub.textContent = tile.sub;
      label.appendChild(sub);
    }

    return label;
  }

  function syncChecked($fs) {
    $fs.find('.stdd-tile').each(function () {
      $(this).toggleClass('is-checked', $(this).find('.stdd-tile__input').prop('checked'));
    });
  }

  // tile.label: the default large text, regardless of custom tile texts
  function slotLabel(cfg, value) {
    var tile = (cfg.tiles || []).filter(function (t) { return t.value === value; })[0];
    return tile ? (tile.label || tile.main) : value;
  }

  function showNotice($fs, text) {
    $fs.find('.stdd-checkout__notice').text(text).prop('hidden', !text);
  }

  // From #stdd-checkout-data (method schedules only), otherwise from the data-stdd-checkout attribute
  function readConfig($fs) {
    var $data = $fs.find('.stdd-checkout__data');
    try {
      return JSON.parse($data.length ? $data.text() : $fs.attr('data-stdd-checkout'));
    } catch (e) {
      return null;
    }
  }

  function applyMethod($fs, cfg) {
    var prev = $fs.data('stnCfg');
    var prevValue = $fs.find('.stdd-tile__input:checked').val();
    var $tiles = $fs.find('.stdd-checkout__tiles');
    var now = nowS(cfg);
    var open = (cfg.tiles || []).filter(function (tile) { return tile.cutoff > now; }).slice(0, cfg.count);

    $fs.data('stnCfg', cfg);
    $tiles.empty();
    open.forEach(function (tile) {
      $tiles.append(buildTile(tile));
    });

    if (!open.length) {
      showNotice($fs, cfg.empty || '');
      return;
    }

    // Same visible slot first (e.g. same delivery date from another ship date), then same ship date: no notice
    var prevLabel = prevValue ? slotLabel(prev || cfg, prevValue) : '';
    var same = open.filter(function (tile) { return prevLabel && (tile.label || tile.main) === prevLabel; })[0] ||
      open.filter(function (tile) { return tile.value === prevValue; })[0];
    showNotice($fs, '');
    if (same) {
      $tiles.find('.stdd-tile__input').filter(function () { return this.value === same.value; }).prop('checked', true);
      if (same.value !== prevValue) {
        $(document.body).trigger('update_checkout');
      }
    } else if (prevValue) {
      $tiles.find('.stdd-tile__input').first().prop('checked', true);
      showNotice($fs, (cfg.moved || '')
        .split('{slot}').join(prevLabel)
        .split('{next_slot}').join(open[0].label || open[0].main));
      $(document.body).trigger('update_checkout');
    }
    syncChecked($fs);
  }

  function refresh($fs) {
    var cfg = $fs.data('stnCfg');
    var now = nowS(cfg);
    var $tiles = $fs.find('.stdd-checkout__tiles');
    var closedValue = null;

    $tiles.find('.stdd-tile').each(function () {
      var $input = $(this).find('.stdd-tile__input');
      if (parseInt($input.attr('data-cutoff'), 10) <= now) {
        if ($input.prop('checked')) {
          closedValue = $input.val();
        }
        $(this).remove();
      }
    });

    var present = $tiles.find('.stdd-tile__input').map(function () {
      return this.value;
    }).get();

    (cfg.tiles || []).forEach(function (tile) {
      if ($tiles.children('.stdd-tile').length < cfg.count && tile.cutoff > now && present.indexOf(tile.value) === -1) {
        $tiles.append(buildTile(tile));
        present.push(tile.value);
      }
    });

    if (closedValue !== null) {
      var $first = $tiles.find('.stdd-tile__input').first();
      if ($first.length) {
        $first.prop('checked', true);
        showNotice($fs, cfg.closed
          .split('{slot}').join(slotLabel(cfg, closedValue))
          .split('{next_slot}').join(slotLabel(cfg, $first.val())));
      }
      $(document.body).trigger('update_checkout');
    }

    syncChecked($fs);
  }

  function init() {
    $('.stdd-checkout[data-stdd-checkout]').each(function () {
      var $fs = $(this);
      var cfg = readConfig($fs);

      if (!cfg) return;

      if ($fs.data('stnInit')) {
        // Another shipping method means other slots
        if ((cfg.method || '') !== (($fs.data('stnCfg') || {}).method || '')) {
          applyMethod($fs, cfg);
        }
        return;
      }

      $fs.data('stnInit', true);
      $fs.data('stnCfg', cfg);
      refresh($fs);
      setInterval(function () {
        refresh($fs);
      }, 1000);
    });
  }

  $(document).on('change', '.stdd-tile__input', function () {
    var $fs = $(this).closest('.stdd-checkout');
    syncChecked($fs);
    showNotice($fs, '');
    $(document.body).trigger('update_checkout');
  });

  $(init);
  $(document.body).on('updated_checkout', init);
})(jQuery);
