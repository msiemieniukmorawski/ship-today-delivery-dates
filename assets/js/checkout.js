/**
 * WooCommerce Ship Today Notice: wybór terminu w checkoucie.
 *
 * - zaznaczenie kafelka odświeża podsumowanie zamówienia (update_checkout),
 * - gdy godzina graniczna terminu minie na otwartej stronie, kafelek znika, dochodzi kolejny termin,
 *   a zaznaczenie przechodzi na najbliższy z komunikatem.
 * Ostateczna kontrola jest i tak na serwerze przy składaniu zamówienia.
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

    label.className = 'wc-stn-tile';
    input.type = 'radio';
    input.className = 'wc-stn-tile__input';
    input.name = 'wc_stn_ship_date';
    input.value = tile.value;
    input.setAttribute('data-cutoff', tile.cutoff);
    input.setAttribute('aria-label', tile.aria);
    main.className = 'wc-stn-tile__main';
    main.textContent = tile.main;

    label.appendChild(input);
    label.appendChild(main);
    // Pusta druga linia (ustawienia) = bez elementu, jak w HTML z PHP
    if (tile.sub) {
      var sub = document.createElement('span');
      sub.className = 'wc-stn-tile__sub';
      sub.textContent = tile.sub;
      label.appendChild(sub);
    }

    return label;
  }

  function syncChecked($fs) {
    $fs.find('.wc-stn-tile').each(function () {
      $(this).toggleClass('is-checked', $(this).find('.wc-stn-tile__input').prop('checked'));
    });
  }

  // Termin w komunikacie: tile.label (domyślny duży napis), niezależnie od własnych tekstów kafelka
  function slotLabel(cfg, value) {
    var tile = (cfg.tiles || []).filter(function (t) { return t.value === value; })[0];
    return tile ? (tile.label || tile.main) : value;
  }

  function showNotice($fs, text) {
    $fs.find('.wc-stn-checkout__notice').text(text).prop('hidden', !text);
  }

  function refresh($fs, cfg) {
    var now = nowS(cfg);
    var $tiles = $fs.find('.wc-stn-checkout__tiles');
    var closedValue = null;

    $tiles.find('.wc-stn-tile').each(function () {
      var $input = $(this).find('.wc-stn-tile__input');
      if (parseInt($input.attr('data-cutoff'), 10) <= now) {
        if ($input.prop('checked')) {
          closedValue = $input.val();
        }
        $(this).remove();
      }
    });

    var present = $tiles.find('.wc-stn-tile__input').map(function () {
      return this.value;
    }).get();

    (cfg.tiles || []).forEach(function (tile) {
      if ($tiles.children('.wc-stn-tile').length < cfg.count && tile.cutoff > now && present.indexOf(tile.value) === -1) {
        $tiles.append(buildTile(tile));
        present.push(tile.value);
      }
    });

    if (closedValue !== null) {
      var $first = $tiles.find('.wc-stn-tile__input').first();
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
    $('.wc-stn-checkout[data-stn-checkout]').each(function () {
      var $fs = $(this);
      var cfg;

      if ($fs.data('stnInit')) return;

      try {
        cfg = JSON.parse($fs.attr('data-stn-checkout'));
      } catch (e) {
        return;
      }

      $fs.data('stnInit', true);
      refresh($fs, cfg);
      setInterval(function () {
        refresh($fs, cfg);
      }, 1000);
    });
  }

  $(document).on('change', '.wc-stn-tile__input', function () {
    var $fs = $(this).closest('.wc-stn-checkout');
    syncChecked($fs);
    showNotice($fs, '');
    $(document.body).trigger('update_checkout');
  });

  $(init);
  $(document.body).on('updated_checkout', init);
})(jQuery);
