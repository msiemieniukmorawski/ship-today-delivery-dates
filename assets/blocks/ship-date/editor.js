/**
 * Editor side of the checkout blocks: previews from the current settings.
 * No build step, so wp.element.createElement instead of JSX. Block metadata comes from block.json (registered in PHP).
 */
(function (wp) {
  'use strict';

  var el = wp.element.createElement;
  var useBlockProps = wp.blockEditor.useBlockProps;
  var data = window.wcStnShipDateEditor || { label: '', tiles: [], accent: '', hint: '', row: ['', ''], rowHint: '' };

  function Preview() {
    var props = useBlockProps({
      className: 'wc-stn-checkout',
      style: data.accent ? { '--wc-stn-accent': data.accent } : undefined
    });

    return el('div', props,
      el('p', { className: 'wc-stn-checkout__legend' }, data.label),
      el('div', { className: 'wc-stn-checkout__tiles' }, data.tiles.map(function (tile, i) {
        return el('div', { key: tile.value, className: 'wc-stn-tile' + (i === 0 ? ' is-checked' : '') },
          el('span', { className: 'wc-stn-tile__main' }, tile.main),
          tile.sub ? el('span', { className: 'wc-stn-tile__sub' }, tile.sub) : null
        );
      })),
      el('p', { className: 'wc-stn-checkout__notice' }, data.hint)
    );
  }

  /** Summary row: the first current slot, styled like a WooCommerce totals row. */
  function RowPreview() {
    var props = useBlockProps({ className: 'wc-block-components-totals-wrapper' });

    return el('div', props,
      el('div', { className: 'wc-block-components-totals-item wc-stn-review' },
        el('span', { className: 'wc-block-components-totals-item__label' }, data.label),
        el('span', { className: 'wc-block-components-totals-item__value' }, data.row[0], data.row[1] ? el('br') : null, data.row[1] || null)
      ),
      el('p', { className: 'wc-stn-checkout__notice' }, data.rowHint)
    );
  }

  wp.blocks.registerBlockType('wc-stn/ship-date-row', {
    edit: RowPreview,
    save: function () {
      return el('div', wp.blockEditor.useBlockProps.save({ 'data-block-name': 'wc-stn/ship-date-row' }));
    }
  });

  wp.blocks.registerBlockType('wc-stn/ship-date', {
    edit: Preview,
    // frontend.js renders the component in place of this marker
    save: function () {
      return el('div', wp.blockEditor.useBlockProps.save({ 'data-block-name': 'wc-stn/ship-date' }));
    }
  });
})(window.wp);
