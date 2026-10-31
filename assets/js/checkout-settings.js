/**
 * Checkout section: module fields appear right after ticking the checkbox, without saving.
 * Hidden fields stay in the form and are still saved.
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
    // Date rows and the list column also work without the module when "dates when disabled" is on
    toggle('.wc-stn-if-dates', module.checked || dates.checked);
  }

  module.addEventListener('change', sync);
  dates.addEventListener('change', sync);
})();
