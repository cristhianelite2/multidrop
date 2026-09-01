(function () {
  var href = location.href;
  var isAe = /\/item\/\d+/i.test(href);
  var isCj = /cjdropshipping\.com/i.test(href) && /\/product\//i.test(href);
  if (!isAe && !isCj) return;
  if (document.getElementById('multidrop-ae-btn')) return;
  var btn = document.createElement('button');
  btn.id = 'multidrop-ae-btn';
  btn.type = 'button';
  btn.textContent = isCj ? 'Multidrop · extraer' : 'Enviar a borrador';
  btn.title = 'Abre el popup de Multidrop para capturar o extraer al producto por SKU';
  btn.addEventListener('click', function () {
    btn.disabled = true;
    btn.textContent = 'Abre el popup →';
    setTimeout(function () {
      btn.disabled = false;
      btn.textContent = isCj ? 'Multidrop · extraer' : 'Enviar a borrador';
    }, 2500);
  });
  document.documentElement.appendChild(btn);
})();
