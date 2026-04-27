/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ══════════════════════════════════════════════════════════════════
 *                   J S   T O A S T   F I L E
 * ══════════════════════════════════════════════════════════════════
 * * @category    VPN Subsystem
 * * @package     MineVPN\Server
 * * @version     5.0.0
 * * [WARNING] 
 * This source code is strictly proprietary and confidential. 
 * Unauthorized reproduction, distribution, or decompilation 
 * is strictly prohibited and heavily monitored.
 * * @copyright   2026 MineVPN Systems. All rights reserved.
 *
 * MineVPN Server — Toast / Компонент toast-уведомлений
 *
 * Глобальный UI-компонент для всех неблокирующих уведомлений в панели. Регистрируется как
 * window.Toast — доступен из любого места. Без зависимостей, vanilla JS.
 *
 * Public API:
 *   Toast.success(text)              — зелёный toast 4с
 *   Toast.success(title, body)       — с заголовком
 *   Toast.error(text)                — красный toast 6с
 *   Toast.warning(text)              — оранжевый toast 4с
 *   Toast.info(text)                 — синий toast 4с
 *   Toast.show({ ... })              — полный вариант с опциями
 *   Toast.dismiss(toast)             — ручное закрытие
 *
 * Полные опции (Toast.show):
 *   {
 *     type:     'success' | 'error' | 'warning' | 'info',
 *     title:    'Optional title',
 *     body:     'Основной текст',
 *     action:   { text: 'Купить', href: '...', target: '_blank' },
 *     duration: 12000  // переопределить default timeout
 *   }
 *
 * Action button:
 *   v5+ фича — кнопка-CTA внутри toast (используется в failover toast: «Купить в MineVPN»).
 *   Рендерится как <a> с target="_blank" и rel="noopener noreferrer". HTML экранирован.
 *   action-toasts имеют повышенный default timeout (9с) — юзеру нужно время на принятие решения.
 *
 * Авто-время жизни:
 *   • success/warning/info  — 4с
 *   • error                  — 6с (больше времени прочитать что сломалось)
 *   • + action               — 9с (вне зависимости от типа)
 *   • или явный duration  — переопределяет всё выше
 *
 * Безопасность (XSS):
 *   • Весь пользовательский текст (title, body, action.text/href/target) экранируется через escapeHtml()
 *   • escapeHtml использует textContent → innerHTML (надёжный браузерный экранир)
 *   • ICONS / CLOSE_ICON — хардкод SVG, не входят от юзера
 *
 * Container management:
 *   Один .toast-container в DOM (создаётся lazy при первом вызове). Все toast-ы стакаются внутрь.
 *   getContainer() ре-создаёт контейнер если его убрали из DOM (HMR / dynamic content).
 *
 * Кто использует:
 *   • assets/js/app.js — flash messages после PRG redirect, failover toast (action CTA)
 *   • assets/js/pages/vpn.js — результаты activate/delete/rename/etc. AJAX-действий
 *   • assets/js/pages/stats.js — уведомления clear_events
 *   • pages/pinger.php — inline JS ("Введите адрес для проверки пинга")
 *
 * Frontend assets:
 *   • assets/css/components.css — стили .toast-container, .toast, .toast--*, .toast-action
 */

(function() {
    'use strict';

    let container = null;

    function getContainer() {
        if (container && document.body.contains(container)) return container;
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
        return container;
    }

    const ICONS = {
        success: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>',
        error:   '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        warning: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>',
        info:    '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
    };
    const CLOSE_ICON = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>';

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str ?? '');
        return div.innerHTML;
    }

    function show(type, titleOrText, text) {
        const c = getContainer();

        // Первый аргумент может быть объектом с расширенными опциями:
        //   { title, body, action: { text, href, target='_blank' }, duration }
        // Иначе — легаси формат (titleOrText, text).
        let title, body, action, customDuration;
        if (titleOrText && typeof titleOrText === 'object') {
            title = titleOrText.title || null;
            body = titleOrText.body || titleOrText.text || '';
            action = titleOrText.action || null;
            customDuration = titleOrText.duration;
        } else if (text === undefined) {
            title = null;
            body = titleOrText;
        } else {
            title = titleOrText;
            body = text;
        }

        const toast = document.createElement('div');
        toast.className = 'toast toast--' + type;

        // action button HTML — рендерится как <a> или <button>, target/href берём из action.
        // href и text экранируем — это единственное место в toast где приходит HTML от кода.
        let actionHtml = '';
        if (action && action.text) {
            const safeText = escapeHtml(action.text);
            const safeHref = escapeHtml(action.href || '#');
            const safeTarget = escapeHtml(action.target || '_blank');
            actionHtml = `<a class="toast-action" href="${safeHref}" target="${safeTarget}" rel="noopener noreferrer">${safeText}</a>`;
        }

        toast.innerHTML = `
            <div class="toast-icon">${ICONS[type] || ICONS.info}</div>
            <div class="toast-body">
                ${title ? '<div class="toast-title">' + escapeHtml(title) + '</div>' : ''}
                <div>${escapeHtml(body)}</div>
                ${actionHtml}
            </div>
            <button type="button" class="toast-close" aria-label="Закрыть">
                ${CLOSE_ICON}
            </button>
        `;

        c.appendChild(toast);

        // Duration: action-toasts держим дольше (юзеру нужно время среагировать)
        const defaultTimeout = action ? 9000 : (type === 'error' ? 6000 : 4000);
        const timeout = (typeof customDuration === 'number') ? customDuration : defaultTimeout;
        const timer = setTimeout(() => dismiss(toast), timeout);

        toast.querySelector('.toast-close').addEventListener('click', () => {
            clearTimeout(timer);
            dismiss(toast);
        });

        return toast;
    }

    function dismiss(toast) {
        if (!toast || !toast.parentNode) return;
        toast.classList.add('is-leaving');
        setTimeout(() => {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 200);
    }

    // Public API
    window.Toast = {
        success: (t, b) => show('success', t, b),
        error:   (t, b) => show('error', t, b),
        warning: (t, b) => show('warning', t, b),
        info:    (t, b) => show('info', t, b),
        // Полный вариант с опциями: Toast.show({ type, title, body, action, duration })
        show:    (options) => show(options.type || 'info', options),
        dismiss
    };
})();
