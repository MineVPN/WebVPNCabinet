/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ==================================================================
 *                  J S   C O N F I R M   F I L E
 * ==================================================================
 * * @category    VPN Subsystem
 * * @package     MineVPN\Server
 * * @version     5.0.0
 * * [WARNING]
 * This source code is strictly proprietary and confidential.
 * Unauthorized reproduction, distribution, or decompilation
 * is strictly prohibited and heavily monitored.
 * * @copyright   2026 MineVPN Systems. All rights reserved.
 *
 * MineVPN Server — Confirm Modal / Компонент модального окна подтверждения
 *
 * Замена нативному window.confirm() — кастомное модальное окно в стиле панели. Нативный confirm() выглядит
 * уродливо (system look) + блокирует main thread (Promise-подход более гибкий). Регистрируется как
 * window.MineVPN.confirm.
 *
 * Никакого дублирования CSS — использует существующие классы .modal-backdrop / .modal / .modal-header /
 * .modal-body / .modal-footer / .modal-title из components.css.
 *
 * Public API:
 *   const ok = await MineVPN.confirm({
 *       title:       'Удалить конфиг?',
 *       message:     'Это действие нельзя отменить.',
 *       confirmText: 'Удалить',     // default: 'OK'
 *       cancelText:  'Отмена',      // default: 'Отмена'
 *       danger:      true,           // default: false — кнопка confirm красная
 *   });
 *   if (ok) { ... }
 *
 * Возвращает Promise<boolean>:
 *   • true  → пользователь нажал «Подтвердить» / Enter
 *   • false → нажал «Отмена» / Escape / клик по фону
 *
 * Поведение:
 *   • Один modal в DOM (если уже открыт — закрывается старый и открывается новый)
 *   • Focus trap: Tab/Shift+Tab крутится между двумя кнопками
 *   • Escape — отмена
 *   • Enter  — подтверждение (если фокус НЕ на cancel-кнопке)
 *   • Click outside (по backdrop) — отмена
 *   • Body scroll lock пока открыт (через overflow:hidden на body)
 *   • Возврат фокуса элементу который был активен до открытия (a11y правильность)
 *   • ARIA: role="dialog", aria-modal="true", aria-labelledby="mv-confirm-title"
 *
 * Безопасность (XSS):
 *   • Все опции (title, message, confirmText, cancelText) экранируются через escapeHtml()
 *   • escapeHtml — карта замены 5 HTML-спецсимволов
 *
 * Кто использует:
 *   • assets/js/pages/vpn.js — подтверждение delete/bulk_delete конфига (danger=true)
 *   • assets/js/pages/stats.js — подтверждение clear_events (очистка журнала)
 *
 * Frontend assets:
 *   • assets/css/components.css — стили .modal-backdrop, .modal, .modal-header, .modal-body,
 *                                  .modal-footer, .modal-title, .is-open
 */

(function() {
    'use strict';

    let activeBackdrop = null;
    let activeResolve = null;
    let lastFocused = null;
    let prevBodyOverflow = '';
    let prevBodyPaddingRight = '';

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    }

    function close(result) {
        if (!activeBackdrop) return;
        const backdrop = activeBackdrop;
        const resolve = activeResolve;
        activeBackdrop = null;
        activeResolve = null;

        document.removeEventListener('keydown', handleKey, true);

        // Анимация закрытия — backdrop fade-out, modal slide-down
        backdrop.classList.remove('is-open');
        // Удаляем после анимации (--dur-base ≈ 200ms)
        setTimeout(() => {
            backdrop.remove();
            // Восстанавливаем overflow и padding-right body в исходные значения
            document.body.style.overflow = prevBodyOverflow;
            document.body.style.paddingRight = prevBodyPaddingRight;
            if (lastFocused && typeof lastFocused.focus === 'function') {
                try { lastFocused.focus(); } catch (e) {}
            }
            lastFocused = null;
        }, 220);

        if (resolve) resolve(result);
    }

    function handleKey(e) {
        if (!activeBackdrop) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            close(false);
            return;
        }
        if (e.key === 'Enter') {
            const cancel = activeBackdrop.querySelector('[data-mv-confirm-cancel]');
            if (document.activeElement !== cancel) {
                e.preventDefault();
                e.stopPropagation();
                close(true);
            }
            return;
        }
        if (e.key === 'Tab') {
            const focusables = activeBackdrop.querySelectorAll('button');
            if (!focusables.length) return;
            const first = focusables[0];
            const last  = focusables[focusables.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    function open(opts) {
        if (activeBackdrop) close(false);

        const o = opts || {};
        const title       = o.title       || 'Подтвердите действие';
        const message     = o.message     || '';
        const confirmText = o.confirmText || 'OK';
        const cancelText  = o.cancelText  || 'Отмена';
        const danger      = !!o.danger;

        lastFocused = document.activeElement;

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.setAttribute('role', 'dialog');
        backdrop.setAttribute('aria-modal', 'true');
        backdrop.setAttribute('aria-labelledby', 'mv-confirm-title');
        backdrop.innerHTML = `
            <div class="modal" role="document">
                <div class="modal-header">
                    <h3 id="mv-confirm-title" class="modal-title">${escapeHtml(title)}</h3>
                </div>
                ${message ? `<div class="modal-body">${escapeHtml(message)}</div>` : ''}
                <div class="modal-footer">
                    <button type="button" class="btn btn--ghost" data-mv-confirm-cancel>${escapeHtml(cancelText)}</button>
                    <button type="button" class="btn ${danger ? 'btn--danger' : 'btn--primary'}" data-mv-confirm-ok>${escapeHtml(confirmText)}</button>
                </div>
            </div>
        `;

        // Click handlers
        backdrop.addEventListener('click', (e) => {
            // Click по backdrop (не по modal) — отмена
            if (e.target === backdrop) {
                close(false);
                return;
            }
            if (e.target.closest('[data-mv-confirm-cancel]')) {
                close(false);
                return;
            }
            if (e.target.closest('[data-mv-confirm-ok]')) {
                close(true);
                return;
            }
        });

        document.body.appendChild(backdrop);

        // Body scroll lock + компенсация scrollbar gutter shift.
        // Проблема: когда body.overflow=hidden — вертикальный scrollbar выключается
        // и весь контент дёргается вправо на ширину scrollbar (~15px). Для компенсации
        // добавляем равный padding-right на body — контент остаётся на месте.
        const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
        prevBodyOverflow = document.body.style.overflow;
        prevBodyPaddingRight = document.body.style.paddingRight;
        document.body.style.overflow = 'hidden';
        if (scrollbarWidth > 0) {
            document.body.style.paddingRight = scrollbarWidth + 'px';
        }

        activeBackdrop = backdrop;
        document.addEventListener('keydown', handleKey, true);

        // Открываем (next frame для CSS transition).
        // .is-open добавляется ИЗ JS — backdrop становится display:flex и запускается animation.
        requestAnimationFrame(() => {
            backdrop.classList.add('is-open');
            const okBtn = backdrop.querySelector('[data-mv-confirm-ok]');
            if (okBtn) okBtn.focus();
        });

        return new Promise((resolve) => {
            activeResolve = resolve;
        });
    }

    // Public API
    window.MineVPN = window.MineVPN || {};
    window.MineVPN.confirm = open;
})();
