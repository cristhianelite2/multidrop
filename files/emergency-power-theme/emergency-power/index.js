/* index.js — landing page only. Purely decorative, degrades silently. */
(function () {
  'use strict';
  var supportsMatchMedia = typeof window.matchMedia === 'function';
  var reduceMotion = supportsMatchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var isNarrow = supportsMatchMedia && window.matchMedia('(max-width: 780px)').matches;
  var bg = document.querySelector('.md-hero__bg');
  if (!bg || reduceMotion || isNarrow) return;

  var hero = document.querySelector('.md-hero');
  hero.addEventListener('mousemove', function (e) {
    var rect = hero.getBoundingClientRect();
    var x = ((e.clientX - rect.left) / rect.width - 0.5) * 14;
    var y = ((e.clientY - rect.top) / rect.height - 0.5) * 14;
    bg.style.transform = 'translate(' + x + 'px,' + y + 'px)';
  });
  hero.addEventListener('mouseleave', function () {
    bg.style.transform = 'translate(0,0)';
  });
})();
