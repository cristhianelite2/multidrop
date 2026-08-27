{{-- Toast global admin: flash session + API window.AdminToast --}}
@php
    $adminToastFlash = [
        'success' => session('success'),
        'error' => session('error'),
        'warning' => session('warning'),
        'info' => session('info'),
        'errors' => (isset($errors) && $errors->any()) ? $errors->all() : [],
    ];
@endphp
<div id="admin-toast-host" class="admin-toast-host" aria-live="polite" aria-relevant="additions"></div>

<script>
(function () {
  var ICONS = { success: '✓', error: '!', warning: '!', info: 'i' };
  var TITLES = { success: 'Listo', error: 'Error', warning: 'Atención', info: 'Info' };
  var DEFAULT_MS = { success: 3800, error: 6500, warning: 5000, info: 4000 };

  function ensureHost() {
    var host = document.getElementById('admin-toast-host');
    if (!host) {
      host = document.createElement('div');
      host.id = 'admin-toast-host';
      host.className = 'admin-toast-host';
      host.setAttribute('aria-live', 'polite');
      document.body.appendChild(host);
    }
    return host;
  }

  function dismiss(el) {
    if (!el || el.classList.contains('is-leaving')) return;
    el.classList.add('is-leaving');
    setTimeout(function () {
      if (el.parentNode) el.parentNode.removeChild(el);
    }, 220);
  }

  function show(type, message, opts) {
    opts = opts || {};
    type = ['success', 'error', 'warning', 'info'].indexOf(type) >= 0 ? type : 'info';
    message = String(message == null ? '' : message).trim();
    if (!message) return null;

    var host = ensureHost();
    var el = document.createElement('div');
    el.className = 'admin-toast admin-toast-' + type;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');

    var title = opts.title != null ? String(opts.title) : TITLES[type];
    el.innerHTML =
      '<span class="admin-toast-ico" aria-hidden="true">' + ICONS[type] + '</span>' +
      '<div class="admin-toast-body">' +
        (title ? '<div class="admin-toast-title"></div>' : '') +
        '<div class="admin-toast-msg"></div>' +
      '</div>' +
      '<button type="button" class="admin-toast-close" aria-label="Cerrar">×</button>';

    if (title) el.querySelector('.admin-toast-title').textContent = title;
    el.querySelector('.admin-toast-msg').textContent = message;
    el.querySelector('.admin-toast-close').addEventListener('click', function () { dismiss(el); });

    host.appendChild(el);

    var ms = opts.duration != null ? Number(opts.duration) : DEFAULT_MS[type];
    if (ms > 0) {
      setTimeout(function () { dismiss(el); }, ms);
    }
    return el;
  }

  window.AdminToast = {
    show: show,
    success: function (msg, opts) { return show('success', msg, opts); },
    error: function (msg, opts) { return show('error', msg, opts); },
    warning: function (msg, opts) { return show('warning', msg, opts); },
    info: function (msg, opts) { return show('info', msg, opts); },
  };

  var flash = @json($adminToastFlash);

  document.addEventListener('DOMContentLoaded', function () {
    if (flash.success) window.AdminToast.success(flash.success);
    if (flash.error) window.AdminToast.error(flash.error);
    if (flash.warning) window.AdminToast.warning(flash.warning);
    if (flash.info) window.AdminToast.info(flash.info);
    if (flash.errors && flash.errors.length) {
      window.AdminToast.error(flash.errors.slice(0, 5).join('\n'), {
        title: flash.errors.length > 1 ? 'Revisa el formulario' : 'Error de validación',
        duration: 7000,
      });
    }
  });
})();
</script>
