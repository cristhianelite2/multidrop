(function () {
  if (!/\/item\/\d+/i.test(location.pathname + location.href)) return;
  if (document.getElementById('multidrop-ae-btn')) return;
  var btn = document.createElement('button');
  btn.id = 'multidrop-ae-btn';
  btn.type = 'button';
  btn.textContent = 'Enviar a Product Hunter';
  btn.addEventListener('click', function () {
    btn.disabled = true;
    btn.textContent = 'Enviando…';
    chrome.runtime.sendMessage({ type: 'MULTIDROP_RUN_CAPTURE' }, function (res) {
      btn.disabled = false;
      if (res && res.ok) {
        btn.textContent = 'Enviado ✓';
        setTimeout(function () { btn.textContent = 'Enviar a Product Hunter'; }, 2500);
      } else {
        btn.textContent = (res && res.error) ? String(res.error).slice(0, 48) : 'Error — revisa el popup';
        setTimeout(function () { btn.textContent = 'Enviar a Product Hunter'; }, 4500);
      }
    });
  });
  document.documentElement.appendChild(btn);
})();
