/**
 * Blok "wc-stn/ship-date" w edytorze (checkout blokowy): podgląd kafelków z aktualnych ustawień.
 * Bez kroku build: wp.element.createElement zamiast JSX. Metadane bloku z block.json (rejestracja w PHP).
 */
(function (wp) {
  'use strict';

  var el = wp.element.createElement;
  var useBlockProps = wp.blockEditor.useBlockProps;
  var data = window.wcStnShipDateEditor || { label: '', tiles: [], accent: '', hint: '' };

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
          el('span', { className: 'wc-stn-tile__sub' }, tile.sub)
        );
      })),
      el('p', { className: 'wc-stn-checkout__notice' }, data.hint)
    );
  }

  wp.blocks.registerBlockType('wc-stn/ship-date', {
    edit: Preview,
    // Front renderuje komponent z frontend.js w miejscu tego znacznika (inner block checkoutu)
    save: function () {
      return el('div', wp.blockEditor.useBlockProps.save({ 'data-block-name': 'wc-stn/ship-date' }));
    }
  });
})(window.wp);
