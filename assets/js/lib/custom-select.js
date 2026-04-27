/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ==================================================================
 *            J S   C U S T O M  -  S E L E C T   F I L E
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
 * MineVPN Server — Custom Select / Компонент кастомного dropdown (progressive enhancement)
 *
 * Превращает любой <select class="select"> в кастомный dropdown с полной CSS-стилизацией
 * (border-radius, hover, animations — не возможны с native select-ом).
 *
 * Принцип progressive enhancement:
 *   • Нативный <select> ОСТАЁТСЯ в DOM (скрыт через .mv-select-native), form submit работает как раньше
 *   • JS читает <option>'ы и строит кастомный UI поверх (.mv-select / .mv-select-trigger / .mv-select-menu)
 *   • При клике на пункт — обновляется .selectedIndex нативного <select> и вызывается событие 'change'
 *   • inline onchange="..." в HTML продолжает работать (вызывается вручную бо он не тригривается синтетическим событием)
 *
 * Почему это нужно:
 *   Native <select> НЕВОЗМОЖНО полностью стилизовать в кросс-браузерном способе (особенно dropdown меню в Safari/
 *   Firefox/Chrome). Кастомный dropdown даёт единый look across browsers + возможность animations/icons.
 *
 * Поддержка ввода:
 *   • Mouse: click trigger → menu открывается, click option → выбирается
 *   • Keyboard: Tab focus → Enter/Space/Arrow открывает
 *               Up/Down навигация по опциям (подсветка hover-классом)
 *               Enter/Space выбирает выделенный
 *               Escape  закрывает
 *               Home/End первый/последний
 *   • Click outside — закрывает любой открытый select (global handler)
 *   • <select disabled> игнорируется — нативный показывается
 *
 * Singleton open dropdown:
 *   Переменная openSelect хранит ссылку на текущий открытый. Открытие другого автоматически
 *   закрывает предыдущий — предотвращает «бахрому» из открытых дропдаунов.
 *
 * Public API:
 *   MineVPN.customSelect.refresh(root)  — переинициализовать в конкретном поддереве (или document)
 *                                          Для динамически вставленных select-ов (напр. AJAX-рендер конфигов).
 *
 * Initialization:
 *   Автоматически по DOMContentLoaded — обрабатывает все select.select на странице.
 *   data-custom-built="1" attribute — защита от двойной инициализации при повторных refresh().
 *
 * inline onchange handling:
 *   При выборе опции вызываем ДВА обработчика:
 *     1. dispatchEvent(new Event('change', { bubbles })) — работают addEventListener('change')
 *     2. nativeSelect.onchange() — ручной вызов inline-handler-а (npm. netsettings.php onchange="toggleStaticFields()")
 *   2-й вызов нужен потому что dispatchEvent НЕ тригривает inline onchange="" атрибут (баг/фича DOM).
 *
 * Кто использует (рендерит select.select в HTML):
 *   • pages/netsettings.php — выбор интерфейсов (input/output) + connection_type с inline onchange
 *   • pages/pinger.php — select  интерфейса (По умолчанию / Белый Интернет / VPN Туннель)
 *   • pages/vpn-manager.php — любые дропдауны которые могут быть добавлены в UI
 *
 * Frontend assets:
 *   • assets/css/components.css — стили .mv-select, .mv-select-trigger, .mv-select-menu, .mv-select-option,
 *                                  .is-open, .is-selected, .is-hover, .is-disabled, .mv-select-native
 */

(function() {
    'use strict';

    let openSelect = null; // глобально открытый dropdown (закрываем при открытии другого)

    function buildCustomSelect(nativeSelect) {
        // Защита от двойного построения
        if (nativeSelect.dataset.customBuilt === '1') return;
        if (nativeSelect.disabled) return;

        nativeSelect.dataset.customBuilt = '1';

        // Контейнер
        const wrap = document.createElement('div');
        wrap.className = 'mv-select';
        wrap.setAttribute('tabindex', '0');
        wrap.setAttribute('role', 'combobox');
        wrap.setAttribute('aria-haspopup', 'listbox');
        wrap.setAttribute('aria-expanded', 'false');

        // Триггер (видимая "кнопка")
        const trigger = document.createElement('div');
        trigger.className = 'mv-select-trigger';

        const labelSpan = document.createElement('span');
        labelSpan.className = 'mv-select-label';
        trigger.appendChild(labelSpan);

        const arrow = document.createElement('span');
        arrow.className = 'mv-select-arrow';
        arrow.innerHTML = '<svg viewBox="0 0 12 8" width="14" height="10"><path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M1 1l5 5 5-5"/></svg>';
        trigger.appendChild(arrow);

        wrap.appendChild(trigger);

        // Меню
        const menu = document.createElement('ul');
        menu.className = 'mv-select-menu';
        menu.setAttribute('role', 'listbox');
        wrap.appendChild(menu);

        // Заполняем опциями
        function rebuildOptions() {
            menu.innerHTML = '';
            Array.from(nativeSelect.options).forEach((opt, idx) => {
                const item = document.createElement('li');
                item.className = 'mv-select-option';
                item.setAttribute('role', 'option');
                item.setAttribute('data-value', opt.value);
                item.setAttribute('data-index', idx);
                item.textContent = opt.textContent;
                if (opt.selected) {
                    item.classList.add('is-selected');
                    labelSpan.textContent = opt.textContent;
                }
                if (opt.disabled) item.classList.add('is-disabled');
                menu.appendChild(item);
            });
            // Если ничего не выбрано — берём первый
            if (!labelSpan.textContent && nativeSelect.options.length) {
                labelSpan.textContent = nativeSelect.options[0].textContent;
            }
        }
        rebuildOptions();

        // Вставляем в DOM перед нативным select-ом, нативный прячем
        nativeSelect.parentNode.insertBefore(wrap, nativeSelect);
        nativeSelect.classList.add('mv-select-native');

        // ── Поведение ─────────────────────────────────────────

        function open() {
            if (wrap.classList.contains('is-open')) return;
            // Закрываем другой открытый
            if (openSelect && openSelect !== wrap) {
                openSelect.classList.remove('is-open');
                openSelect.setAttribute('aria-expanded', 'false');
            }
            wrap.classList.add('is-open');
            wrap.setAttribute('aria-expanded', 'true');
            openSelect = wrap;
            // Скроллим к выбранному
            const selected = menu.querySelector('.mv-select-option.is-selected');
            if (selected) selected.scrollIntoView({ block: 'nearest' });
        }

        function close() {
            wrap.classList.remove('is-open');
            wrap.setAttribute('aria-expanded', 'false');
            if (openSelect === wrap) openSelect = null;
        }

        function toggle() {
            wrap.classList.contains('is-open') ? close() : open();
        }

        function selectByIndex(idx) {
            if (idx < 0 || idx >= nativeSelect.options.length) return;
            const opt = nativeSelect.options[idx];
            if (opt.disabled) return;
            nativeSelect.selectedIndex = idx;
            labelSpan.textContent = opt.textContent;
            // Подсветка в меню
            menu.querySelectorAll('.mv-select-option').forEach(li => li.classList.remove('is-selected'));
            const li = menu.querySelector(`.mv-select-option[data-index="${idx}"]`);
            if (li) li.classList.add('is-selected');
            // Триггерим change event на нативном <select>
            nativeSelect.dispatchEvent(new Event('change', { bubbles: true }));
            // dispatchEvent НЕ вызывает inline onchange="..." атрибут — зовём вручную
            // (в netsettings.php есть onchange="toggleStaticFields()")
            if (typeof nativeSelect.onchange === 'function') {
                try { nativeSelect.onchange(); } catch (e) { console.error('select onchange:', e); }
            }
        }

        // Click на триггер
        trigger.addEventListener('mousedown', (e) => {
            e.preventDefault(); // не сбрасывать focus с wrap
            toggle();
            wrap.focus();
        });

        // Click на опцию
        menu.addEventListener('click', (e) => {
            const li = e.target.closest('.mv-select-option');
            if (!li || li.classList.contains('is-disabled')) return;
            const idx = parseInt(li.dataset.index, 10);
            selectByIndex(idx);
            close();
            wrap.focus();
        });

        // Hover-подсветка для клавиатурной навигации
        let hoverIdx = -1;
        function setHover(idx) {
            menu.querySelectorAll('.mv-select-option').forEach(li => li.classList.remove('is-hover'));
            const li = menu.querySelector(`.mv-select-option[data-index="${idx}"]`);
            if (li) {
                li.classList.add('is-hover');
                li.scrollIntoView({ block: 'nearest' });
                hoverIdx = idx;
            }
        }

        // Клавиатура
        wrap.addEventListener('keydown', (e) => {
            const isOpen = wrap.classList.contains('is-open');
            const total = nativeSelect.options.length;
            const current = nativeSelect.selectedIndex;

            switch (e.key) {
                case 'Enter':
                case ' ':
                    e.preventDefault();
                    if (!isOpen) {
                        open();
                        hoverIdx = current;
                        setHover(current);
                    } else {
                        if (hoverIdx >= 0) selectByIndex(hoverIdx);
                        close();
                    }
                    break;
                case 'Escape':
                    if (isOpen) { e.preventDefault(); close(); }
                    break;
                case 'ArrowDown':
                    e.preventDefault();
                    if (!isOpen) { open(); hoverIdx = current; setHover(current); }
                    else { setHover(Math.min(total - 1, hoverIdx + 1)); }
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    if (!isOpen) { open(); hoverIdx = current; setHover(current); }
                    else { setHover(Math.max(0, hoverIdx - 1)); }
                    break;
                case 'Home':
                    if (isOpen) { e.preventDefault(); setHover(0); }
                    break;
                case 'End':
                    if (isOpen) { e.preventDefault(); setHover(total - 1); }
                    break;
                case 'Tab':
                    close();
                    break;
            }
        });

        // Внешние изменения нативного <select> (например программно или через старый JS)
        nativeSelect.addEventListener('change', () => {
            const idx = nativeSelect.selectedIndex;
            if (idx >= 0) {
                labelSpan.textContent = nativeSelect.options[idx].textContent;
                menu.querySelectorAll('.mv-select-option').forEach(li => li.classList.remove('is-selected'));
                const li = menu.querySelector(`.mv-select-option[data-index="${idx}"]`);
                if (li) li.classList.add('is-selected');
            }
        });

        return { rebuildOptions, open, close, wrap };
    }

    // Click вне любого открытого select — закрываем
    document.addEventListener('click', (e) => {
        if (!openSelect) return;
        if (!openSelect.contains(e.target)) {
            openSelect.classList.remove('is-open');
            openSelect.setAttribute('aria-expanded', 'false');
            openSelect = null;
        }
    });

    function initAll(root = document) {
        root.querySelectorAll('select.select:not([data-custom-built])').forEach(buildCustomSelect);
    }

    // Инициализация при DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initAll());
    } else {
        initAll();
    }

    // Public API — для случаев когда select добавлен динамически
    window.MineVPN = window.MineVPN || {};
    window.MineVPN.customSelect = {
        refresh: (root) => initAll(root || document),
    };
})();
