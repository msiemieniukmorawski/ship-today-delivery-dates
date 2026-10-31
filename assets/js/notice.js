/**
 * Ship Today & Delivery Dates for WooCommerce - widget [ship_today_notice]
 *
 * No AJAX: slots come in the data-stn attribute as absolute timestamps, so the widget stays right on a page
 * cached for days. The states mirror WC_Ship_Today_Notice::initial_state() in PHP.
 */
(function () {
  'use strict';

  function pad(n) {
    return n < 10 ? '0' + n : '' + n;
  }

  function nowMs(cfg) {
    return Date.now() + (cfg.nowOffset || 0);
  }

  /** Y-m-d in the shop timezone. */
  function ymd(ms, cfg) {
    try {
      return new Intl.DateTimeFormat('en-CA', {
        timeZone: cfg.tz,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
      }).format(new Date(ms));
    } catch (e) {
      return new Date(ms + (cfg.gmtOffset || 0) * 1000).toISOString().slice(0, 10);
    }
  }

  function addDays(dateYmd, days) {
    var d = new Date(dateYmd + 'T12:00:00Z');
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
  }

  function formatCountdown(sec, c) {
    var d = Math.floor(sec / 86400);
    var h = Math.floor((sec % 86400) / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var s = sec % 60;
    var parts = [];

    if (d > 0) {
      // Forms from _n() in PHP
      parts.push((d === 1 ? c.day : c.days).replace('%d', d));
    }
    if (d > 0 || h > 0 || c.h) {
      parts.push(c.h ? pad(h) + c.h : pad(h));
    }
    parts.push(c.m ? pad(m) + c.m : pad(m));
    if (c.seconds) {
      parts.push(c.s ? pad(s) + c.s : pad(s));
    }

    return parts.join(' ');
  }

  /** sprintf with %1$s, %2$s (formats from WC_STN_Schedule::words()). */
  function format(fmt, a, b) {
    return fmt.replace('%1$s', a).replace('%2$s', b);
  }

  function renderText(tpl, slot, today, tomorrow, w) {
    function when(dateYmd, fallback) {
      if (dateYmd === today) return w.today;
      return dateYmd === tomorrow ? w.tomorrow : fallback;
    }

    var cutoffWhen = (slot.cutoff_ymd === today || slot.cutoff_ymd === tomorrow)
      ? format(w.relative, when(slot.cutoff_ymd, ''), slot.cutoff_time)
      : format(w.until, slot.cutoff_day, slot.cutoff_time);

    var map = {
      '{countdown}': '<span class="wc-ship-today-countdown"></span>',
      '{cutoff_when}': cutoffWhen,
      '{cutoff_day}': slot.cutoff_day,
      '{cutoff_time}': slot.cutoff_time,
      '{cutoff_date}': slot.cutoff_date,
      '{ship_when}': when(slot.ship_ymd, slot.ship_day),
      '{ship_day}': slot.ship_day,
      '{ship_date}': slot.ship_date,
      '{delivery_when}': slot.delivery_ymd ? when(slot.delivery_ymd, slot.delivery_day) : '',
      '{delivery_day}': slot.delivery_day,
      '{delivery_date}': slot.delivery_date
    };

    return tpl.replace(/\{[a-z_]+\}/g, function (token) {
      return Object.prototype.hasOwnProperty.call(map, token) ? map[token] : token;
    });
  }

  function compute(cfg) {
    var now = nowMs(cfg);
    var nowS = Math.floor(now / 1000);
    var today = ymd(now, cfg);
    var tomorrow = addDays(today, 1);
    var vis = cfg.visibility || {};
    var future = (cfg.slots || []).filter(function (s) {
      return s.cutoff > nowS;
    });

    if (!future.length) {
      return { hide: true, today: today, items: [] };
    }

    var first = future[0];
    var hoursLeft = (first.cutoff - nowS) / 3600;
    var state;
    var hide = false;

    if (cfg.mode === 'next_slot') {
      state = 'next';
      hide = vis.mode === 'hours_before' && hoursLeft > vis.hours;
    } else if (first.ship_ymd === today) {
      state = 'before';
      hide = vis.mode === 'hours_before' && hoursLeft > vis.hours;
    } else if ((cfg.shipDates || []).indexOf(today) !== -1) {
      state = 'after';
      hide = !cfg.show.after || vis.mode === 'before_cutoff_only';
    } else {
      state = 'not_shipping';
      hide = !cfg.show.not_shipping;
    }

    var items = future.slice(0, cfg.count || 1).map(function (slot, i) {
      var tpl = cfg.templates[(i === 0 || state === 'next') ? state : 'next'] || '';
      return { html: renderText(tpl, slot, today, tomorrow, cfg.words), cutoff: slot.cutoff };
    });

    return {
      hide: hide,
      state: state,
      today: today,
      items: items,
      // Next content change: the nearest cutoff or the visibility threshold.
      changeAt: vis.mode === 'hours_before' && hoursLeft > vis.hours ? first.cutoff - vis.hours * 3600 : first.cutoff
    };
  }

  function render(el, cfg) {
    var s = compute(cfg);

    el.classList.remove(
      'wc-ship-today-notice--loading',
      'wc-ship-today-notice--before',
      'wc-ship-today-notice--after',
      'wc-ship-today-notice--hidden'
    );

    if (s.hide) {
      el.style.display = 'none';
      el.classList.add('wc-ship-today-notice--hidden');
      return s;
    }

    // Wrapped even for a single slot: the container is a flex box with a gap, so loose text and the
    // countdown would become separate items with a double space between them.
    el.innerHTML = s.items.map(function (item) {
      return '<div class="wc-ship-today-notice__item">' + item.html + '</div>';
    }).join('');
    el.style.display = '';
    el.classList.add(s.state === 'before' || s.state === 'next' ? 'wc-ship-today-notice--before' : 'wc-ship-today-notice--after');

    return s;
  }

  function tick(el, cfg, s) {
    var nowS = Math.floor(nowMs(cfg) / 1000);
    var spans = el.querySelectorAll('.wc-ship-today-countdown');

    for (var i = 0; i < spans.length && i < s.items.length; i++) {
      spans[i].textContent = formatCountdown(Math.max(0, s.items[i].cutoff - nowS), cfg.countdown);
    }
  }

  function init(el) {
    if (el.__stn) return;

    var cfg;
    try {
      cfg = JSON.parse(el.getAttribute('data-stn'));
    } catch (e) {
      return;
    }

    el.__stn = true;
    var s = render(el, cfg);
    tick(el, cfg, s);

    var timer = setInterval(function () {
      // Replaced by AJAX (cart, checkout): the new element has its own timer
      if (!document.documentElement.contains(el)) {
        clearInterval(timer);
        return;
      }

      var nowS = Math.floor(nowMs(cfg) / 1000);

      // Next slot after the cutoff, and "today/tomorrow" after midnight.
      if ((s.changeAt && nowS >= s.changeAt) || ymd(nowMs(cfg), cfg) !== s.today) {
        s = render(el, cfg);
      }

      if (!s.hide) {
        tick(el, cfg, s);
      }
    }, 1000);
  }

  function initAll(root) {
    var nodes = (root || document).querySelectorAll('.wc-ship-today-notice[data-stn]');
    for (var i = 0; i < nodes.length; i++) {
      init(nodes[i]);
    }
  }

  // For content loaded with AJAX (popups, theme PJAX).
  window.WcShipTodayNotice = { init: initAll };

  // The classic cart and checkout replace HTML fragments. These are jQuery events, addEventListener doesn't see them.
  function bindAjax() {
    if (window.jQuery) {
      window.jQuery(document.body).on('updated_cart_totals updated_wc_div updated_checkout', function () {
        initAll();
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initAll();
      bindAjax();
    });
  } else {
    initAll();
    bindAjax();
  }
})();
