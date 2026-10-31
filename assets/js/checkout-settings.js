/**
 * Zakładka "Checkout": pola modułu widoczne od razu po zaznaczeniu checkboxa, bez zapisu.
 * Ukryte pola zostają w formularzu i dalej się zapisują.
 */
(function () {
  'use strict';

  var form = document.getElementById('wc-stn-checkout-settings');
  if (!form) {
    return;
  }

  var module = form.querySelector('input[name$="[checkout_enabled]"]');
  var dates = form.querySelector('input[name$="[checkout_dates_when_disabled]"]');

  function toggle(selector, visible) {
    form.querySelectorAll(selector).forEach(function (el) {
      el.classList.toggle('hidden', !visible);
    });
  }

  function sync() {
    toggle('.wc-stn-if-module', module.checked);
    // Wiersze dat i kolumna listy działają też bez modułu, gdy włączone są daty przy wyłączonym module
    toggle('.wc-stn-if-dates', module.checked || dates.checked);
  }

  module.addEventListener('change', sync);
  dates.addEventListener('change', sync);
})();
