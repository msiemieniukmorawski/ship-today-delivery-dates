/**
 * WooCommerce Ship Today Notice - zakładka "Styl"
 * Podgląd na żywo: pola ustawiają zmienne CSS z notice.css na kontenerze podglądu (bez zapisu).
 */
(function ($) {
  'use strict';

  var JUSTIFY = { left: 'flex-start', center: 'center', right: 'flex-end' };

  $(function () {
    var $form = $('#wc-stn-style-form');
    var preview = document.getElementById('wc-stn-style-preview');

    if (!$form.length || !preview) {
      return;
    }

    function setVar(name, value) {
      if (value === '') {
        preview.style.removeProperty(name);
      } else {
        preview.style.setProperty(name, value);
      }
    }

    // value: opcjonalnie wartość z color pickera (pole tekstowe bywa jeszcze nieaktualne)
    function apply(el, value) {
      var $el = $(el);

      value = String(value === undefined ? $el.val() || '' : value).trim();

      if ($el.is('[data-stn-align]')) {
        setVar('--wc-ship-today-notice-align', value);
        setVar('--wc-ship-today-notice-justify', JUSTIFY[value] || '');
        return;
      }

      if (value !== '' && $el.data('stn-unit')) {
        var n = parseInt(value, 10);
        if (isNaN(n)) {
          value = '';
        } else {
          n = Math.max(parseInt(el.min, 10) || 0, Math.min(parseInt(el.max, 10) || n, n));
          value = n + $el.data('stn-unit');
        }
      }

      setVar($el.data('stn-var'), value);
    }

    function applyAll() {
      $form.find('[data-stn-var], [data-stn-align]').each(function () {
        apply(this);
      });
    }

    $form.find('.wc-stn-color').wpColorPicker({
      change: function (event, ui) {
        apply(event.target, ui.color.toString());
      },
      clear: function (event) {
        var input = $(event.target).closest('.wp-picker-container').find('.wc-stn-color')[0];
        if (input) {
          apply(input, '');
        }
      }
    });

    $form.on('input change', 'input[type="number"], select', function () {
      apply(this);
    });

    $('#wc-stn-style-reset').on('click', function () {
      $form.find('.wc-stn-color').each(function () {
        $(this).closest('.wp-picker-container').find('.wp-picker-clear').trigger('click');
        $(this).val('');
      });
      $form.find('input[type="number"], select').val('');
      applyAll();
    });

    applyAll();
  });
})(jQuery);
