/**
 * Ship Today & Delivery Dates for WooCommerce: Style section
 * Live preview: fields set the notice.css variables on the preview container, without saving.
 */
(function ($) {
  'use strict';

  var JUSTIFY = { left: 'flex-start', center: 'center', right: 'flex-end' };

  $(function () {
    var $form = $('#stdd-style-form');
    var preview = document.getElementById('stdd-style-preview');

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

    // value: optional color picker value, the text field can still be out of date
    function apply(el, value) {
      var $el = $(el);

      value = String(value === undefined ? $el.val() || '' : value).trim();

      if ($el.is('[data-stdd-align]')) {
        setVar('--stdd-notice-align', value);
        setVar('--stdd-notice-justify', JUSTIFY[value] || '');
        return;
      }

      if (value !== '' && $el.data('stdd-unit')) {
        var n = parseInt(value, 10);
        if (isNaN(n)) {
          value = '';
        } else {
          n = Math.max(parseInt(el.min, 10) || 0, Math.min(parseInt(el.max, 10) || n, n));
          value = n + $el.data('stdd-unit');
        }
      }

      setVar($el.data('stdd-var'), value);
    }

    function applyAll() {
      $form.find('[data-stdd-var], [data-stdd-align]').each(function () {
        apply(this);
      });
    }

    $form.find('.stdd-color').wpColorPicker({
      change: function (event, ui) {
        apply(event.target, ui.color.toString());
      },
      clear: function (event) {
        var input = $(event.target).closest('.wp-picker-container').find('.stdd-color')[0];
        if (input) {
          apply(input, '');
        }
      }
    });

    $form.on('input change', 'input[type="number"], select', function () {
      apply(this);
    });

    $('#stdd-style-reset').on('click', function () {
      $form.find('.stdd-color').each(function () {
        $(this).closest('.wp-picker-container').find('.wp-picker-clear').trigger('click');
        $(this).val('');
      });
      $form.find('input[type="number"], select').val('');
      applyAll();
      // WooCommerce enables the save button only after a change event on a form field
      $form.find('input[type="number"]').first().trigger('change');
    });

    applyAll();
  });
})(jQuery);
