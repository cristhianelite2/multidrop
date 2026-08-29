(function () {
  var d = window.MULTIDROP_DEFAULTS || {};
  var originEl = document.getElementById('origin');
  var tokenEl = document.getElementById('token');
  var status = document.getElementById('status');

  chrome.storage.sync.get(['origin', 'token'], function (cfg) {
    originEl.value = cfg.origin || d.origin || '';
    tokenEl.value = cfg.token || '';
  });

  document.getElementById('save').addEventListener('click', async function () {
    var origin = String(originEl.value || '').replace(/\/+$/, '');
    var token = String(tokenEl.value || '').trim();
    if (origin && chrome.permissions && chrome.permissions.request) {
      try {
        await chrome.permissions.request({ origins: [origin + '/*'] });
      } catch (e) {}
    }
    chrome.storage.sync.set({ origin: origin, token: token }, function () {
      status.textContent = 'Guardado.';
    });
  });

  document.getElementById('capture').addEventListener('click', function () {
    status.textContent = 'Capturando…';
    chrome.runtime.sendMessage({ type: 'MULTIDROP_RUN_CAPTURE' }, function (res) {
      if (res && res.ok) status.textContent = 'Enviado' + (res.title ? ': ' + res.title : '');
      else status.textContent = (res && res.error) || 'Error al capturar';
    });
  });
})();
