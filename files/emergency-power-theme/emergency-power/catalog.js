/* catalog.js — catalog page only.
   Adds search + sort on top of the [data-md-products] grid rendered by
   theme.js. Reuses MD.Theme.buildProductCard / emptyStateHtml so the card
   markup and empty state stay defined in exactly one place. */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var MD = window.Multidrop;
    var Theme = MD && MD.Theme;
    var container = document.querySelector('[data-md-products]');
    var searchInput = document.querySelector('[data-md-catalog-search]');
    var sortSelect = document.querySelector('[data-md-catalog-sort]');
    var countEl = document.querySelector('[data-md-catalog-count]');
    if (!Theme || !container) return;

    var sorters = {
      'featured': function (a, b) { return (b.featured ? 1 : 0) - (a.featured ? 1 : 0); },
      'price-asc': function (a, b) { return (Number(a.price) || 0) - (Number(b.price) || 0); },
      'price-desc': function (a, b) { return (Number(b.price) || 0) - (Number(a.price) || 0); },
      'name-asc': function (a, b) { return (a.name || '').localeCompare(b.name || ''); }
    };

    function render() {
      var term = (searchInput && searchInput.value || '').trim().toLowerCase();
      var sortKey = (sortSelect && sortSelect.value) || 'featured';

      var products = MD.products.filter(function (p) {
        if (!term) return true;
        var haystack = ((p.name || '') + ' ' + (p.description || '')).toLowerCase();
        return haystack.indexOf(term) !== -1;
      });

      products = products.slice().sort(sorters[sortKey] || sorters.featured);

      container.innerHTML = '';
      if (!products.length) {
        container.innerHTML = Theme.emptyStateHtml('No matches', 'Try a different search term.');
      } else {
        container.classList.add('md-grid');
        products.forEach(function (product) {
          container.appendChild(Theme.buildProductCard(product));
        });
      }

      if (countEl) countEl.textContent = products.length;
    }

    var debounceHandle;
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        window.clearTimeout(debounceHandle);
        debounceHandle = window.setTimeout(render, 180);
      });
    }
    if (sortSelect) sortSelect.addEventListener('change', render);

    render();
  });
})();
