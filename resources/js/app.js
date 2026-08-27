import './bootstrap';

window.MultidropAdmin = {
    initStoreSwitcher() {
        const $ = window.jQuery;
        if (!$) return;

        const $panel = $('[data-switcher-menu]');
        const $backdrop = $('[data-switcher-backdrop]');
        if (!$panel.length) return;

        const csrf = $('meta[name="csrf-token"]').attr('content');
        const endpoint = $panel.data('endpoint');

        const positionPanel = ($toggle) => {
            const rect = $toggle[0].getBoundingClientRect();
            const panelWidth = Math.min(560, window.innerWidth - 24);
            const panelMaxHeight = Math.min(720, window.innerHeight - 24);
            const gap = 10;

            let left = rect.right + gap;
            let top = rect.top;

            // Si no cabe a la derecha del sidebar, abrir debajo / centrado
            if (left + panelWidth > window.innerWidth - 12) {
                left = Math.max(12, Math.min(rect.left, window.innerWidth - panelWidth - 12));
                top = rect.bottom + gap;
            }

            if (top + 320 > window.innerHeight) {
                top = Math.max(12, window.innerHeight - panelMaxHeight - 12);
            }

            if (window.innerWidth < 768) {
                left = 12;
                top = 12;
            }

            $panel.css({
                left: `${left}px`,
                top: `${top}px`,
                width: `${panelWidth}px`,
            });
        };

        const close = () => {
            $panel.addClass('hidden').attr('data-open', '0');
            $backdrop.addClass('hidden');
            $('[data-switcher-toggle]').attr('aria-expanded', 'false');
        };

        const open = ($toggle) => {
            positionPanel($toggle);
            $panel.removeClass('hidden').attr('data-open', '1');
            $backdrop.removeClass('hidden');
            $('[data-switcher-toggle]').attr('aria-expanded', 'true');
        };

        $(document).on('click', '[data-switcher-toggle]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if ($panel.attr('data-open') === '1') close();
            else open($(this));
        });

        const closeFromUi = function (e) {
            e.preventDefault();
            e.stopPropagation();
            close();
        };

        $panel.on('click', '[data-switcher-close]', closeFromUi);
        $backdrop.on('click', closeFromUi);

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') close();
        });

        $(window).on('resize', function () {
            if ($panel.attr('data-open') === '1') {
                const $openToggle = $('[data-switcher-toggle][aria-expanded="true"]').first();
                if ($openToggle.length) positionPanel($openToggle);
            }
        });

        // No detener bubbling en el botón Cerrar / Administrar
        $panel.on('click', function (e) {
            if ($(e.target).closest('[data-switcher-close]').length) {
                return;
            }
            e.stopPropagation();
        });

        $panel.on('click', '[data-store-id]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const storeId = $(this).data('store-id');
            const name = $(this).data('store-name');

            $.ajax({
                url: endpoint,
                method: 'POST',
                data: {
                    _token: csrf,
                    store_id: storeId,
                },
                success(res) {
                    if (res && res.ok) {
                        $('[data-switcher-label]').text(res.store.name);
                    } else {
                        $('[data-switcher-label]').text(name);
                    }
                    close();
                    window.location.reload();
                },
                error() {
                    alert('No se pudo cambiar de sitio.');
                },
            });
        });
    },

    initMobileNav() {
        const $ = window.jQuery;
        if (!$) return;

        $('[data-mobile-nav-toggle]').on('click', function () {
            $('[data-mobile-nav]').toggleClass('hidden');
            $('body').toggleClass('overflow-hidden');
        });
    },

    initSidebar() {
        const $ = window.jQuery;
        if (!$) return;

        const $layout = $('[data-admin-layout]');
        if (!$layout.length) return;

        const compactKey = 'md.admin.sidebar.compact';
        const sectionsKey = 'md.admin.nav.sections';

        try {
            if (localStorage.getItem(compactKey) === '1') {
                $layout.addClass('is-compact');
                $('[data-sidebar-compact]').text('»');
            }
        } catch (e) {}

        $('[data-sidebar-compact]').on('click', function () {
            $layout.toggleClass('is-compact');
            const compact = $layout.hasClass('is-compact');
            $(this).text(compact ? '»' : '«');
            try { localStorage.setItem(compactKey, compact ? '1' : '0'); } catch (e) {}
        });

        let saved = {};
        try {
            saved = JSON.parse(localStorage.getItem(sectionsKey) || '{}') || {};
        } catch (e) {
            saved = {};
        }

        $('[data-nav-section]').each(function () {
            const key = $(this).data('nav-section');
            const hasActive = $(this).find('.admin-nav-link-active').length > 0;
            let open = saved[key];
            if (typeof open === 'undefined') {
                open = key !== 'modulos';
            }
            if (hasActive) open = true;
            $(this).toggleClass('is-collapsed', !open);
        });

        $('[data-nav-section-toggle]').on('click', function (e) {
            e.preventDefault();
            const $sec = $(this).closest('[data-nav-section]');
            $sec.toggleClass('is-collapsed');
            const key = $sec.data('nav-section');
            saved[key] = ! $sec.hasClass('is-collapsed');
            try { localStorage.setItem(sectionsKey, JSON.stringify(saved)); } catch (err) {}
        });
    },

    initCardCollapse() {
        const $ = window.jQuery;
        if (!$) return;

        const storageKey = (title) => `md.admin.card:${location.pathname}:${String(title || '').trim().slice(0, 80)}`;

        $('.admin-card').each(function () {
            const $card = $(this);
            if ($card.data('collapseReady')) return;
            if ($card.is('a, article, [data-cj-card], [data-no-collapse]')) return;
            if ($card.closest('.fixed, [id*="modal"], [data-admin-sidebar]').length) return;
            if ($card.parents('.admin-card').length) return;

            let $children = $card.children().not('script, style');
            while ($children.length && $children.first().is('input[type=hidden], .pointer-events-none')) {
                $children = $children.slice(1);
            }

            const $head = $children.first();
            if (!$head.length) return;
            const $title = $head.is('h1, h2, h3') ? $head : $head.find('h1, h2, h3, .font-display').first();
            if (!$title.length) return;

            const $after = $children.slice(1);
            if (!$after.length) return;

            if (!$card.find('> .admin-card-body').length) {
                const $body = $('<div class="admin-card-body"></div>');
                $after.appendTo($body);
                $card.append($body);
            }

            $head.addClass('admin-card-head');
            if (!$head.find('.admin-card-chevron').length) {
                $head.append('<span class="admin-card-chevron" aria-hidden="true">▾</span>');
            }

            const key = storageKey($title.text());
            let collapsed = String($card.attr('data-collapse-default') || '') === '1';
            try {
                const saved = localStorage.getItem(key);
                if (saved === '1') collapsed = true;
                if (saved === '0') collapsed = false;
            } catch (e) {}

            $card.toggleClass('is-collapsed', collapsed);
            $head.attr('aria-expanded', collapsed ? 'false' : 'true');
            $card.data('collapseReady', 1);

            $head.on('click.adminCardCollapse', function (e) {
                if ($(e.target).closest('a, button, input, select, textarea, label, .admin-btn, .admin-btn-secondary').length) {
                    return;
                }
                const nowCollapsed = !$card.hasClass('is-collapsed');
                $card.toggleClass('is-collapsed', nowCollapsed);
                $head.attr('aria-expanded', nowCollapsed ? 'false' : 'true');
                try { localStorage.setItem(key, nowCollapsed ? '1' : '0'); } catch (err) {}
            });
        });
    },

    initFixedFormActions() {
        const $ = window.jQuery;
        if (!$) return;
        if (!$('[data-admin-layout]').length) return;

        const excludeText = /agregar|buscar|generar|calcular|importar|entrar|probar|asignar|abrir|resolver|subir|copiar|recalcular|sincronizar/i;
        const saveText = /guardar|crear/i;

        const isSavePrimary = ($btn) => {
            if (!$btn.length) return false;
            if ($btn.hasClass('admin-btn-secondary') || $btn.hasClass('admin-btn-danger')) return false;
            if (!$btn.hasClass('admin-btn')) return false;
            const text = String($btn.text() || '').trim();
            if (!text || excludeText.test(text)) return false;
            return saveText.test(text);
        };

        const findActionGroup = ($form) => {
            if ($form.find('.admin-form-actions').length) {
                return $form.find('.admin-form-actions').last();
            }

            const $direct = $form.children().not('script, style, input[type=hidden], template, .pointer-events-none');
            for (let i = $direct.length - 1; i >= 0; i--) {
                const $el = $($direct[i]);
                if ($el.hasClass('hidden') || $el.is('[data-no-fixed-actions]')) continue;

                const $primary = $el.is('button.admin-btn')
                    ? $el
                    : $el.find('button.admin-btn').filter(function () {
                        return !$(this).hasClass('admin-btn-secondary');
                    }).first();

                if (!isSavePrimary($primary)) continue;
                if ($el.is('button')) return $primary;
                if ($el.find('input:not([type=hidden]), textarea, select, table').length) {
                    return $primary;
                }
                return $el;
            }

            return null;
        };

        const candidates = [];

        $('main form').each(function () {
            const $form = $(this);
            if ($form.attr('data-no-fixed-actions') !== undefined) return;
            if ($form.hasClass('hidden') || $form.closest('.hidden, [id*="modal"], .fixed, [data-switcher-menu]').length) return;
            if ($form.closest('[data-tab-panel]').length) return;
            if ($form.parents('form').length) return;

            const $actions = findActionGroup($form);
            if (!$actions || !$actions.length) return;

            candidates.push({ $form, $actions });
        });

        if (!candidates.length) return;

        if (candidates.length > 1) {
            const maxH = Math.max(...candidates.map((c) => c.$form.outerHeight() || 0));
            const minH = Math.max(400, maxH * 0.55);
            const filtered = candidates.filter((c) => (c.$form.outerHeight() || 0) >= minH);
            if (filtered.length) {
                candidates.length = 0;
                candidates.push(...filtered);
            }
        }

        const syncBarHeight = () => {
            const $bars = $('.admin-form-actions.is-fixed');
            if (!$bars.length) return;
            const h = Math.max(...$bars.map(function () { return $(this).outerHeight() || 0; }).get());
            document.documentElement.style.setProperty('--admin-form-actions-h', `${h}px`);
        };

        candidates.forEach(({ $form, $actions }) => {
            let $bar = $actions;
            if ($bar.is('button')) {
                const $wrap = $('<div class="admin-form-actions"></div>');
                $bar.before($wrap);
                $wrap.append($bar);
                $bar = $wrap;
            } else {
                $bar.addClass('admin-form-actions');
            }

            $bar.addClass('is-fixed');
            $form.addClass('has-admin-form-actions');
        });

        $('main').addClass('admin-main-has-fixed-actions');

        syncBarHeight();
        $(window).off('resize.adminFormActions').on('resize.adminFormActions', syncBarHeight);
    },
};

document.addEventListener('DOMContentLoaded', () => {
    window.MultidropAdmin.initStoreSwitcher();
    window.MultidropAdmin.initMobileNav();
    window.MultidropAdmin.initSidebar();
    window.MultidropAdmin.initCardCollapse();
    window.MultidropAdmin.initFixedFormActions();
});
