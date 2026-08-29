(function () {
  if (!/\/item\/\d+/i.test(location.pathname + location.href)) return;
  if (document.getElementById('multidrop-ae-btn')) return;
  var btn = document.createElement('button');
  btn.id = 'multidrop-ae-btn';
  btn.type = 'button';
  btn.textContent = 'Enviar a borrador';
  btn.addEventListener('click', function () {
    btn.disabled = true;
    btn.textContent = 'Enviando…';
    chrome.runtime.sendMessage({ type: 'MULTIDROP_RUN_CAPTURE' }, function (res) {
      btn.disabled = false;
      if (res && res.ok) {
        btn.textContent = res.message
          ? String(res.message).slice(0, 42)
          : ('En borrador ✓' + (res.store_name ? ' · ' + String(res.store_name).slice(0, 18) : ''));
        setTimeout(function () { btn.textContent = 'Enviar a borrador'; }, 4000);
      } else {
        btn.textContent = (res && res.error) ? String(res.error).slice(0, 48) : 'Error — revisa el popup';
        setTimeout(function () { btn.textContent = 'Enviar a borrador'; }, 4500);
      }
    });
  });
  document.documentElement.appendChild(btn);
})();
