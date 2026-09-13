/* product.js — product detail page only.
   theme.js already binds Multidrop.product into [data-md-bind] fields and
   wires [data-md-add-to-cart] / [data-md-qty]. This file only adds the
   gallery thumbnail strip and the quantity stepper buttons. */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var MD = window.Multidrop;
    var product = MD && MD.product;
    var mainImg = document.querySelector('[data-md-gallery-main]');
    var thumbsWrap = document.querySelector('[data-md-gallery-thumbs]');

    if (product && thumbsWrap && mainImg && !thumbsWrap.hasAttribute('data-md-gallery-locked')) {
      var images = Array.isArray(product.images) && product.images.length
        ? product.images
        : (product.image ? [product.image] : []);

      if (images.length > 1) {
        images.forEach(function (src, index) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = index === 0 ? 'is-active' : '';
          btn.innerHTML = '<img src="' + src + '" alt="" loading="lazy">';
          btn.addEventListener('click', function () {
            mainImg.src = src;
            thumbsWrap.querySelectorAll('button').forEach(function (b) { b.classList.remove('is-active'); });
            btn.classList.add('is-active');
          });
          thumbsWrap.appendChild(btn);
        });
      }
    }

    var qtyInput = document.querySelector('[data-md-qty]');
    var decr = document.querySelector('[data-md-qty-decr]');
    var incr = document.querySelector('[data-md-qty-incr]');
    if (qtyInput && decr && incr) {
      decr.addEventListener('click', function () {
        qtyInput.value = Math.max(1, (parseInt(qtyInput.value, 10) || 1) - 1);
      });
      incr.addEventListener('click', function () {
        qtyInput.value = (parseInt(qtyInput.value, 10) || 1) + 1;
      });
    }
  });
})();
