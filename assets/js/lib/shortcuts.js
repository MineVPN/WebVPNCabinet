/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ══════════════════════════════════════════════════════════════════
 *               J S   S H O R T C U T S   F I L E
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
 * MineVPN Server — Shortcuts / Компонент keyboard shortcuts (Vim-style)
 *
 * Навигация клавиатурой по всей панели. Близко к UX Gmail / Linear / GitHub / Twitter — является power-user фичей
 * без обязательного использования. Регистрируется как window.MineVPNShortcuts.
 *
 * Shortcuts:
 *   ?       — показать окно справки
 *   g o     — перейти на Обзор (стартовая)
 *   g s     — алиас к Обзор (Stats)
 *   g d     — алиас к Обзор (была Dashboard в старых версиях, оставлен для обратной совместимости)
 *   g v     — перейти на VPN Manager
 *   g p     — перейти на Пинг
 *   g c     — перейти на Консоль
 *   g n     — перейти на Настройки сети
 *   g t     — перейти на Настройки
 *   g a     — перейти на О продукте
 *   r       — перезагрузить текущую страницу
 *   /       — фокус на поиск (если есть на странице)
 *   Escape  — закрыть модальное окно / action menu
 *
 * Sequence detection («g X» комбо):
 *   Нажали 'g' → seqPending = 'g', запускаем таймер 1000мс (SEQ_TIMEOUT).
 *   Следующая клавиша в GO_TARGETS → навигация. Иначе (или по timeout) → reset.
 *
 * Когда НЕ срабатывают (isTyping check):
 *   • Фокус в input/textarea/select — юзер печатает текст
 *   • contenteditable элемент активен (inline rename в vpn-manager)
 *   • Модификаторы Ctrl/Cmd/Alt нажаты — не конфликт с нативными shortcut-ами (Ctrl+R остаётся релоадом)
 *   Escape — исключение: работает всегда (закрыть modal/menu важнее чем любой input).
 *
 * Help modal:
 *   Lazy-built (создаётся при первом '?'). Использует .modal-backdrop / .modal из components.css.
 *   Закрывается по Escape, click вне modal и клику по кнопке ×.
 *
 * Public API:
 *   window.MineVPNShortcuts.openHelp()  — программно открыть окно справки
 *   window.MineVPNShortcuts.closeHelp() — программно закрыть
 *
 * Взаимодействует с:
 *   • Все страницы панели — cabinet.php подключает этот JS глобально
 *   • vpn-manager.php / vpn.js — Escape закрывает .action-menu.is-open
 *   • vpn-manager.php — '/' фокусит #config-search-input
 *
 * Frontend assets:
 *   • assets/css/components.css — стили .modal-*, .kbd, .shortcut-row, .shortcut-group
 */

(function() {
    'use strict';

    // ── Sequence state ────────────────────────────────────────────────
    // "g o" = пользователь нажал "g" потом "o" в течение SEQ_TIMEOUT мс.
    let seqPending = null;   // 'g' или null
    let seqTimer = null;
    const SEQ_TIMEOUT = 1000; // 1с чтобы завершить комбо

    function resetSeq() {
        seqPending = null;
        if (seqTimer) { clearTimeout(seqTimer); seqTimer = null; }
    }

    function startSeq(prefix) {
        seqPending = prefix;
        if (seqTimer) clearTimeout(seqTimer);
        seqTimer = setTimeout(resetSeq, SEQ_TIMEOUT);
    }

    // ── Навигационные таргеты ─────────────────────────────────────────
    // Алиасы 'd' и 's' указывают на ту же страницу 'stats' что и 'o'.
    // 'd' исторически был Dashboard (отдельная страница, удалена в v5 — её
    // функциональность поглотила Stats). Оставлен для обратной совместимости
    // со старыми привычками юзеров.
    const GO_TARGETS = {
        'o': { url: 'cabinet.php?menu=stats',       label: 'Обзор' },
        'd': { url: 'cabinet.php?menu=stats',       label: 'Обзор' },
        's': { url: 'cabinet.php?menu=stats',       label: 'Обзор' },
        'v': { url: 'cabinet.php?menu=vpn',         label: 'VPN Manager' },
        'p': { url: 'cabinet.php?menu=ping',        label: 'Пинг' },
        'c': { url: 'cabinet.php?menu=console',     label: 'Консоль' },
        'n': { url: 'cabinet.php?menu=netsettings', label: 'Сеть' },
        't': { url: 'cabinet.php?menu=settings',    label: 'Настройки' },
        'a': { url: 'cabinet.php?menu=about',       label: 'О продукте' },
    };

    // ── Проверка: в фокусе ли input/textarea/contenteditable ──────────
    function isTyping(event) {
        const t = event.target;
        if (!t) return false;
        const tag = t.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
        if (t.isContentEditable) return true;
        return false;
    }

    // ── Help modal ────────────────────────────────────────────────────
    let helpModal = null;

    function buildHelpModal() {
        if (helpModal) return helpModal;
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal" role="dialog" aria-labelledby="shortcuts-title">
                <div class="modal-header">
                    <h3 class="modal-title" id="shortcuts-title">Горячие клавиши</h3>
                    <button type="button" class="modal-close" aria-label="Закрыть">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="shortcut-group">
                        <div class="shortcut-group-title">Навигация</div>
                        <div class="shortcut-row"><span><kbd class="kbd">g</kbd> <kbd class="kbd">o</kbd></span><span>Обзор (стартовая)</span></div>
                        <div class="shortcut-row"><span><kbd class="kbd">g</kbd> <kbd class="kbd">v</kbd></span><span>VPN Manager</span></div>
                        <div class="shortcut-row"><span><kbd class="kbd">g</kbd> <kbd class="kbd">p</kbd></span><span>Пинг</span></div>
                        <div class="shortcut-row"><span><kbd class="kbd">g</kbd> <kbd class="kbd">c</kbd></span><span>Консоль</span></div>
                        <div class="shortcut-row"><span><kbd class="kbd">g</kbd> <kbd class="kbd">n</kbd></span><span>Настройки сети</span></div>
                        <div class="shortcut-row"><span><kbd class="kbd">g</kbd> <kbd class="kbd">t</kbd></span><span>Настройки</span></div>
                        <div class="shortcut-row"><span><kbd class="kbd">g</kbd> <kbd class="kbd">a</kbd></span><span>О продукте</span></div>
                    </div>
                    <div class="shortcut-group">
                        <div class="shortcut-group-title">Действия</div>
                        <div class="shortcut-row"><span><kbd class="kbd">r</kbd></span><span>Перезагрузить страницу</span></div>
                        <div class="shortcut-row"><span><kbd class="kbd">/</kbd></span><span>Фокус на поиск (если есть)</span></div>
                        <div class="shortcut-row"><span><kbd class="kbd">?</kbd></span><span>Показать эту справку</span></div>
                        <div class="shortcut-row"><span><kbd class="kbd">Esc</kbd></span><span>Закрыть модалку / меню</span></div>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);

        // Закрываем при клике на backdrop или на кнопку
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop || e.target.closest('.modal-close')) {
                closeHelp();
            }
        });

        helpModal = backdrop;
        return backdrop;
    }

    function openHelp() {
        const m = buildHelpModal();
        m.classList.add('is-open');
    }

    function closeHelp() {
        if (helpModal) helpModal.classList.remove('is-open');
    }

    function isHelpOpen() {
        return helpModal && helpModal.classList.contains('is-open');
    }

    // ── Основной обработчик клавиш ────────────────────────────────────
    function handleKey(e) {
        // Escape — специальный случай, работает всегда (кроме если уже игнорируется)
        if (e.key === 'Escape') {
            if (isHelpOpen()) {
                e.preventDefault();
                closeHelp();
                return;
            }
            // Закрываем action-menus в VPN Manager если они открыты
            const openMenus = document.querySelectorAll('.action-menu.is-open');
            if (openMenus.length) {
                e.preventDefault();
                openMenus.forEach(m => m.classList.remove('is-open'));
                return;
            }
            return;
        }

        // Все остальные shortcuts игнорируем когда юзер печатает текст
        if (isTyping(e)) return;

        // Модификаторы (Ctrl/Cmd/Alt) — пропускаем чтобы не конфликтовать
        if (e.ctrlKey || e.metaKey || e.altKey) return;

        const key = e.key.toLowerCase();

        // ── Sequence: g → X ───────────────────────────────────────────
        if (seqPending === 'g') {
            if (GO_TARGETS[key]) {
                e.preventDefault();
                const target = GO_TARGETS[key];
                resetSeq();
                window.location.href = target.url;
                return;
            }
            // Неизвестное комбо — сбрасываем
            resetSeq();
        }

        // ── Однокнопочные ─────────────────────────────────────────────
        if (key === '?' || (e.key === '/' && e.shiftKey)) {
            // "?" = shift+/ на стандартных раскладках
            e.preventDefault();
            openHelp();
            return;
        }

        if (key === 'g') {
            e.preventDefault();
            startSeq('g');
            return;
        }

        if (key === 'r') {
            e.preventDefault();
            location.reload();
            return;
        }

        if (key === '/') {
            // Фокус на поиск (если существует)
            const search = document.getElementById('config-search-input')
                        || document.querySelector('input[type="search"]')
                        || document.querySelector('input[name="search"]');
            if (search) {
                e.preventDefault();
                search.focus();
                search.select();
            }
            return;
        }
    }

    document.addEventListener('keydown', handleKey);

    // Public API
    window.MineVPNShortcuts = {
        openHelp,
        closeHelp,
    };
})();
