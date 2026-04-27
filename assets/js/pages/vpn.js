/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ══════════════════════════════════════════════════════════════════
 *                 J S   V P N   P A G E   F I L E
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
 * MineVPN Server — VPN Manager / Логика страницы VPN Manager (самый большой JS-файл)
 *
 * AJAX-клиент для api/vpn_action.php + 8 UX-фич. Заменяет POST→reload flow на toast-уведомления
 * без reload-ов (где возможно). Регистрируется как IIFE (нет public API — всё работает через event delegation на data-action).
 *
 * 8 UX features:
 *   1. AJAX actions (activate/delete/rename/move/toggle_role/stop/restart/bulk_delete)
 *      — все через apiCall() в api/vpn_action.php (POST + JSON body + X-Requested-With)
 *      — toast на результат + reload там где нужно атомарное обновление (activate/restart/stop/toggle_role)
 *      — Подтверждения delete/stop/bulk_delete — через MineVPN.confirm() (кастомный modal + danger=true)
 *   2. Drag & Drop priority (native HTML5):
 *      — dragover/drop НА КОНТЕЙНЕРЕ (не на карточках) — работает в gap-зонах
 *      — findDropTarget(y) — ближайшая карточка по Y, threshold 20% (раньше было 50%)
 *      — После drop — POST reorder в api, UI-приоритеты обновляются без reload
 *   3. Inline rename:
 *      — double-click на .config-name → contentEditable=true + selectAll
 *      — Enter commits, Escape cancels, focusout (blur) commits с 100ms delay
 *      — 64-char limit с toast warning
 *   4. Search filter — моментальная фильтрация по name/server/type (input event)
 *   5. Bulk selection:
 *      — toggle mode (body.is-bulk-mode), checkbox на каждой карточке
 *      — Активация drag-and-drop блокируется в bulk mode (путаница)
 *      — bulk_delete — одним POST удаление нескольких конфигов + reload
 *   6. Action menus — dropdowns с toggle, auto-close при клике вне + Escape
 *   7. Upload zone — drag-drop файла в зону загрузки (вызывает multipart в vpn-manager.handler.php)
 *      — Двойной submit blocked через form.__submitting + 30с timeout
 *   8. Status polling:
 *      — ping subscribe → app.js MineVPN.ping — обновляет ping/status badges
 *      — checkStateChange — каждые 10с GET status из vpn_action.php
 *      — Детект failover: running → running с изменённым active_id → markFailoverPending() + reload
 *
 * apiCall() helper:
 *   • Progress.start() перед fetch и Progress.done() в finally (надёжный try/finally)
 *   • fetch ожидает JSON ответ { ok: true|false, message?, error?, data? }
 *   • X-Requested-With: XMLHttpRequest — бэкенд проверяет этот header (CSRF-защита)
 *
 * Failover detection logic:
 *   Детектит резервирование без отдельного API — из двух последовательных status-поллов:
 *     — wasRunning      = (knownActiveId !== '' && knownState === 'running')
 *     — isStillRunning  = (active_id    !== '' && state      === 'running')
 *     — activeIdChanged = (active_id    !== knownActiveId)
 *   Если все 3 истины → это было переключение на бэкап. Не реагируем на stopped/recovering/restarting.
 *   Перед reload → markFailoverPending(toName) → sessionStorage → app.js после reload покажет toast с «Купить» CTA.
 *
 * Drag-drop threshold trick (20% вместо 50%):
 *   Обычные D&D реализации используют 50% (середину) карточки для определения before/after.
 *   Но юзер ожидает «быструю замену» — навёл на верх нижней карточки (при движении вниз) → уже встала после неё.
 *   20% даёт этот эффект. Асимметрия thresholdRatio = draggingDown ? 0.2 : 0.8.
 *
 * Взаимодействует с:
 *   • pages/vpn-manager.php — этот JS подключается на странице ?menu=vpn
 *   • api/vpn_action.php — все actions идут сюда (POST JSON dispatcher)
 *   • assets/js/lib/toast.js — Toast.success/error/warning для обратной связи
 *   • assets/js/lib/progress.js — Progress.start/done в apiCall() helper
 *   • assets/js/lib/confirm.js — MineVPN.confirm для delete/stop/bulk_delete (с fallback)
 *   • assets/js/app.js — showVpnLoading/hideVpnLoading (loading overlay), MineVPN.ping (subscribe),
 *                       MineVPN.markFailoverPending (failover toast протокол)
 *
 * Init flow:
 *   DOMContentLoaded → setupDragDrop → setupSearch → setupBulkSelection →
 *   setupActionMenus → setupUploadZone → setupEventDelegation → startPolling.
 */

(function() {
    'use strict';

    const API = 'api/vpn_action.php';

    // ══════════════════════════════════════════════════════════════════
    // API helper
    // ══════════════════════════════════════════════════════════════════

    async function apiCall(action, payload = {}) {
        Progress.start();
        try {
            const res = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action, ...payload }),
            });
            const data = await res.json();
            return data;
        } catch (err) {
            return { ok: false, error: 'Ошибка соединения: ' + err.message };
        } finally {
            Progress.done();
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // ACTION: activate (с full-screen overlay т.к. длительная операция)
    // ══════════════════════════════════════════════════════════════════

    async function doActivate(configId, configName) {
        window.showVpnLoading && showVpnLoading('Подключение VPN...');
        const result = await apiCall('activate', { config_id: configId });
        window.hideVpnLoading && hideVpnLoading();

        if (result.ok) {
            Toast.success(result.message || 'Конфиг активирован');
            // Активация меняет многое: роли, приоритеты, активный конфиг.
            // Проще — перезагрузить чтобы всё обновилось атомарно.
            setTimeout(() => location.reload(), 500);
        } else {
            Toast.error(result.error || 'Ошибка активации');
            // Тоже reload — состояние может быть stopped после failed rollback
            setTimeout(() => location.reload(), 1000);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // ACTION: delete
    // ══════════════════════════════════════════════════════════════════

    async function doDelete(configId, configName) {
        // Кастомный confirm-modal (вместо нативного окна браузера).
        // Fallback на нативный confirm() если lib не подключена.
        const ok = window.MineVPN && window.MineVPN.confirm
            ? await MineVPN.confirm({
                title:       'Удалить конфиг?',
                message:     `Конфигурация «${configName}» будет удалена безвозвратно.`,
                confirmText: 'Удалить',
                cancelText:  'Отмена',
                danger:      true,
            })
            : confirm(`Удалить конфигурацию "${configName}"?`);
        if (!ok) return;

        const item = document.querySelector(`[data-config-id="${configId}"]`);
        const result = await apiCall('delete', { config_id: configId });

        if (result.ok) {
            Toast.success(result.message || 'Конфиг удалён');
            if (item) {
                // Плавное удаление
                item.style.transition = 'opacity 200ms, transform 200ms';
                item.style.opacity = '0';
                item.style.transform = 'translateX(-20px)';
                setTimeout(() => {
                    item.remove();
                    updateConfigCount();
                    showEmptyStateIfNoConfigs();
                }, 220);
            }
        } else {
            Toast.error(result.error || 'Ошибка удаления');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // ACTION: rename (inline)
    // ══════════════════════════════════════════════════════════════════

    function startInlineRename(nameEl) {
        if (nameEl.classList.contains('is-editing')) return;
        const original = nameEl.textContent.trim();
        nameEl.dataset.original = original;
        nameEl.contentEditable = 'true';
        nameEl.classList.add('is-editing');
        // Фокус + select all
        nameEl.focus();
        const range = document.createRange();
        range.selectNodeContents(nameEl);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }

    function cancelInlineRename(nameEl) {
        nameEl.textContent = nameEl.dataset.original || '';
        finishInlineRename(nameEl);
    }

    function finishInlineRename(nameEl) {
        nameEl.contentEditable = 'false';
        nameEl.classList.remove('is-editing');
        nameEl.blur();
    }

    async function commitInlineRename(nameEl) {
        const configItem = nameEl.closest('.config-item');
        const configId   = configItem?.dataset.configId;
        const newName    = nameEl.textContent.trim();
        const original   = nameEl.dataset.original || '';

        if (!configId || newName === original) {
            cancelInlineRename(nameEl);
            return;
        }
        if (!newName) {
            Toast.warning('Название не может быть пустым');
            cancelInlineRename(nameEl);
            return;
        }
        if (newName.length > 64) {
            Toast.warning('Максимум 64 символа');
            nameEl.textContent = newName.substring(0, 64);
        }

        finishInlineRename(nameEl);
        const result = await apiCall('rename', { config_id: configId, new_name: nameEl.textContent.trim() });
        if (result.ok) {
            Toast.success('Переименовано');
        } else {
            Toast.error(result.error || 'Ошибка');
            nameEl.textContent = original;
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // ACTION: toggle_role
    // ══════════════════════════════════════════════════════════════════

    async function doToggleRole(configId) {
        const result = await apiCall('toggle_role', { config_id: configId });
        if (result.ok) {
            Toast.success(result.message);
            // Роль изменилась — нужен reload чтобы обновить бейджи и UI
            setTimeout(() => location.reload(), 400);
        } else {
            Toast.error(result.error || 'Ошибка');
        }
    }

    // ═════════════════════════════════════════════════════════════════
    // ACTION: move (click-перемещение по стрелкам вверх/вниз)
    // ═════════════════════════════════════════════════════════════════
    // Alternative для touch-устройств и тех кому неудобно drag-and-drop.
    // Backend (api/vpn_action.php, case 'move') просто меняет местами priority двух соседних.

    async function doMove(configId, direction) {
        const list = document.getElementById('config-items');
        const item = document.querySelector(`[data-config-id="${configId}"]`);
        if (!list || !item) return;

        // Проверяем сверху/снизу: нельзя поднять первый и опустить последний
        if (direction === 'up' && !item.previousElementSibling) return;
        if (direction === 'down' && !item.nextElementSibling) return;

        const result = await apiCall('move', { config_id: configId, direction });
        if (!result.ok) {
            Toast.error(result.error || 'Ошибка перемещения');
            return;
        }

        // Меняем DOM-позицию без reload
        if (direction === 'up') {
            list.insertBefore(item, item.previousElementSibling);
        } else {
            list.insertBefore(item.nextElementSibling, item);
        }

        // Обновляем номера приоритетов
        list.querySelectorAll('.config-item').forEach((el, idx) => {
            const pill = el.querySelector('.config-priority');
            if (pill) pill.textContent = idx + 1;
        });
    }

    // ══════════════════════════════════════════════════════════════════
    // ACTION: stop / restart
    // ══════════════════════════════════════════════════════════════════

    async function doStop() {
        const ok = window.MineVPN && window.MineVPN.confirm
            ? await MineVPN.confirm({
                title:       'Остановить VPN?',
                message:     'Соединение будет разорвано и весь VPN-трафик пойдёт напрямую.',
                confirmText: 'Остановить',
                cancelText:  'Отмена',
                danger:      true,
            })
            : confirm('Остановить VPN?');
        if (!ok) return;
        window.showVpnLoading && showVpnLoading('Остановка VPN...');
        const result = await apiCall('stop');
        window.hideVpnLoading && hideVpnLoading();
        if (result.ok) {
            Toast.success(result.message || 'VPN остановлен');
            setTimeout(() => location.reload(), 400);
        } else {
            Toast.error(result.error || 'Ошибка');
        }
    }

    async function doRestart() {
        window.showVpnLoading && showVpnLoading('Перезапуск VPN...');
        const result = await apiCall('restart');
        window.hideVpnLoading && hideVpnLoading();
        if (result.ok) {
            Toast.success(result.message || 'VPN перезапущен');
            setTimeout(() => location.reload(), 400);
        } else {
            Toast.error(result.error || 'Ошибка');
            setTimeout(() => location.reload(), 1000);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // DRAG & DROP — расчёт позиции на уровне контейнера
    // ══════════════════════════════════════════════════════════════════
    //
    // Проблемы предыдущей версии:
    //   1. dragover/drop были на каждой карточке — если курсор в промежутке
    //      между карточками (gap) — событие не срабатывало.
    //   2. Тащишь верхнюю карточку вниз — не обменивалась с нижней, т.к.
    //      dragover смотрел на целевую в верхней половине, а юзер ожидал увидеть
    //      замену при наведении куда-угодно в зоне нижней карточки.
    //
    // Решение:
    //   — dragover/drop на уровне контейнера (срабатывают везде — на карточке и в
    //     пространстве между ними).
    //   — findDropTarget(y) ищет ближайшую карточку по Y и возвращает её
    //     вместе с позицией (before/after).
    //   — CSS-индикатор — тонкая синяя линия между элементами через ::before/::after.

    let draggedItem = null;

    /**
     * Находит ближайшую к курсору карточку и определяет, вставлять до неё или после.
     *
     * 20%-threshold логика (раньше было 50% — середина):
     *   Если тянем вниз (dragged выше closest в DOM) — достаточно зайти на 20% в верх closest,
     *   чтобы pos='after' (быстрая замена). Аналогично при движении вверх.
     *
     * @returns {{target: Element, pos: 'before'|'after'}|null}
     */
    function findDropTarget(clientY, items) {
        if (!items.length) return null;

        // Выше первой карточки — вставляем перед ней
        const firstRect = items[0].getBoundingClientRect();
        if (clientY < firstRect.top) return { target: items[0], pos: 'before' };

        // Ниже последней — вставляем после неё
        const lastRect = items[items.length - 1].getBoundingClientRect();
        if (clientY > lastRect.bottom) return { target: items[items.length - 1], pos: 'after' };

        // Иначе — ближайшая карточка по расстоянию от центра
        let closest = null;
        let closestDist = Infinity;
        for (const item of items) {
            const rect = item.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            const dist = Math.abs(clientY - midY);
            if (dist < closestDist) { closestDist = dist; closest = item; }
        }
        if (!closest) return null;

        // Определяем направление перетаскивания относительно closest в DOM.
        // Позиция draggedItem — в полном списке (не фильтрованном).
        const list = closest.parentElement;
        const allItems = Array.from(list.querySelectorAll('.config-item'));
        const draggedIdx = allItems.indexOf(draggedItem);
        const closestIdx = allItems.indexOf(closest);
        const draggingDown = draggedIdx >= 0 && draggedIdx < closestIdx;

        const rect = closest.getBoundingClientRect();
        // Threshold: 20% вместо 50%. Если тянем вниз — порог на 20% от верхнего края closest.
        // Если тянем вверх — порог на 80% (20% от низа).
        const thresholdRatio = draggingDown ? 0.2 : 0.8;
        const thresholdY = rect.top + rect.height * thresholdRatio;

        return {
            target: closest,
            pos: clientY < thresholdY ? 'before' : 'after',
        };
    }

    function clearDropIndicators(list) {
        list.querySelectorAll('.is-drop-before, .is-drop-after')
            .forEach(el => el.classList.remove('is-drop-before', 'is-drop-after'));
    }

    function setupDragDrop() {
        const list = document.getElementById('config-items');
        if (!list) return;

        // dragstart/dragend — на каждой карточке (управление состоянием drag)
        list.querySelectorAll('.config-item').forEach(item => {
            item.setAttribute('draggable', 'true');

            item.addEventListener('dragstart', (e) => {
                if (document.body.classList.contains('is-bulk-mode')) {
                    e.preventDefault();
                    return;
                }
                draggedItem = item;
                item.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', item.dataset.configId || '');
            });

            item.addEventListener('dragend', () => {
                item.classList.remove('is-dragging');
                clearDropIndicators(list);
                draggedItem = null;
            });
        });

        // dragover/drop — НА КОНТЕЙНЕРЕ, а не на карточках.
        // Это заставляет события срабатывать даже в промежутках между карточками (gap)
        // и выше первой/ниже последней. Без этого дроп в gap-зоне игнорировался.
        list.addEventListener('dragover', (e) => {
            if (!draggedItem) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';

            const items = Array.from(list.querySelectorAll('.config-item:not(.is-dragging)'));
            const result = findDropTarget(e.clientY, items);

            clearDropIndicators(list);
            if (result) {
                result.target.classList.add(result.pos === 'before' ? 'is-drop-before' : 'is-drop-after');
            }
        });

        list.addEventListener('drop', async (e) => {
            if (!draggedItem) return;
            e.preventDefault();
            e.stopPropagation();

            const items = Array.from(list.querySelectorAll('.config-item:not(.is-dragging)'));
            const result = findDropTarget(e.clientY, items);
            clearDropIndicators(list);
            if (!result) return;

            // Запомним старый порядок — если ничего не изменилось, не дёргаем API
            const oldOrder = Array.from(list.querySelectorAll('.config-item'))
                .map(el => el.dataset.configId).join(',');

            if (result.pos === 'before') {
                list.insertBefore(draggedItem, result.target);
            } else {
                list.insertBefore(draggedItem, result.target.nextSibling);
            }

            const newOrder = Array.from(list.querySelectorAll('.config-item'))
                .map(el => el.dataset.configId).join(',');

            if (oldOrder !== newOrder) {
                await persistReorder();
            }
        });

        // Покинули контейнер целиком — чистим индикаторы
        list.addEventListener('dragleave', (e) => {
            if (!list.contains(e.relatedTarget)) clearDropIndicators(list);
        });
    }

    async function persistReorder() {
        const list = document.getElementById('config-items');
        if (!list) return;
        const order = Array.from(list.querySelectorAll('.config-item'))
            .map(el => el.dataset.configId)
            .filter(Boolean);
        const result = await apiCall('reorder', { order });
        if (result.ok) {
            Toast.success('Порядок сохранён');
            // Обновить номера приоритетов в UI без reload
            list.querySelectorAll('.config-item').forEach((el, idx) => {
                const pill = el.querySelector('.config-priority');
                if (pill) pill.textContent = idx + 1;
            });
        } else {
            Toast.error(result.error || 'Ошибка сохранения порядка');
            setTimeout(() => location.reload(), 800);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // SEARCH filter (моментальная фильтрация)
    // ══════════════════════════════════════════════════════════════════

    function setupSearch() {
        const input = document.getElementById('config-search-input');
        if (!input) return;
        input.addEventListener('input', () => {
            const q = input.value.toLowerCase().trim();
            const items = document.querySelectorAll('.config-item');
            items.forEach(item => {
                if (!q) {
                    item.style.display = '';
                    return;
                }
                const name   = (item.querySelector('.config-name')?.textContent || '').toLowerCase();
                const server = (item.querySelector('.config-meta-server')?.textContent || '').toLowerCase();
                const type   = (item.dataset.configType || '').toLowerCase();
                const match = name.includes(q) || server.includes(q) || type.includes(q);
                item.style.display = match ? '' : 'none';
            });
        });
    }

    // ══════════════════════════════════════════════════════════════════
    // BULK SELECTION
    // ══════════════════════════════════════════════════════════════════

    function toggleBulkMode() {
        document.body.classList.toggle('is-bulk-mode');
        if (!document.body.classList.contains('is-bulk-mode')) {
            // Очищаем выбор
            document.querySelectorAll('.bulk-check').forEach(cb => cb.checked = false);
            document.querySelectorAll('.config-item.is-selected').forEach(el => el.classList.remove('is-selected'));
            updateBulkToolbar();
        }
    }

    function setupBulkSelection() {
        document.addEventListener('change', (e) => {
            if (!e.target.classList.contains('bulk-check')) return;
            const item = e.target.closest('.config-item');
            if (item) item.classList.toggle('is-selected', e.target.checked);
            updateBulkToolbar();
        });
    }

    function updateBulkToolbar() {
        const selected = document.querySelectorAll('.bulk-check:checked');
        const countEl = document.getElementById('bulk-count');
        if (countEl) countEl.textContent = selected.length;
    }

    function getSelectedIds() {
        return Array.from(document.querySelectorAll('.bulk-check:checked'))
            .map(cb => cb.closest('.config-item')?.dataset.configId)
            .filter(Boolean);
    }

    async function doBulkDelete() {
        const ids = getSelectedIds();
        if (!ids.length) { Toast.warning('Нет выделенных'); return; }
        const ok = window.MineVPN && window.MineVPN.confirm
            ? await MineVPN.confirm({
                title:       `Удалить ${ids.length} конфигураций?`,
                message:     'Выбранные конфиги будут удалены. Действие нельзя отменить.',
                confirmText: 'Удалить все',
                cancelText:  'Отмена',
                danger:      true,
            })
            : confirm(`Удалить ${ids.length} конфигураций?`);
        if (!ok) return;

        const result = await apiCall('bulk_delete', { ids });
        if (result.ok) {
            Toast.success(result.message);
            setTimeout(() => location.reload(), 400);
        } else {
            Toast.error(result.error || 'Ошибка');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // ACTION MENU (dropdowns)
    // ══════════════════════════════════════════════════════════════════

    function setupActionMenus() {
        document.addEventListener('click', (e) => {
            // Клик на кнопку меню — toggle
            const btn = e.target.closest('.action-menu-btn');
            if (btn) {
                e.stopPropagation();
                const menu = btn.closest('.action-menu');
                // Закрыть все остальные
                document.querySelectorAll('.action-menu.is-open').forEach(m => {
                    if (m !== menu) m.classList.remove('is-open');
                });
                menu.classList.toggle('is-open');
                return;
            }
            // Клик вне меню — закрыть все
            if (!e.target.closest('.action-menu-dropdown')) {
                document.querySelectorAll('.action-menu.is-open').forEach(m => m.classList.remove('is-open'));
            }
        });

        // Escape закрывает все меню
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.action-menu.is-open').forEach(m => m.classList.remove('is-open'));
            }
        });
    }

    // ══════════════════════════════════════════════════════════════════
    // UPLOAD ZONE (drag-drop файла)
    // ══════════════════════════════════════════════════════════════════

    function setupUploadZone() {
        const zone  = document.getElementById('upload-zone');
        const input = document.getElementById('config-file-input');
        const label = document.getElementById('upload-zone-text');
        if (!zone || !input || !label) return;

        input.addEventListener('change', () => {
            const name = input.files[0]?.name || '';
            label.textContent = name || '.ovpn или .conf';
        });

        ['dragenter', 'dragover'].forEach(ev => {
            zone.addEventListener(ev, (e) => {
                e.preventDefault();
                zone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(ev => {
            zone.addEventListener(ev, (e) => {
                e.preventDefault();
                zone.classList.remove('is-dragover');
            });
        });
        zone.addEventListener('drop', (e) => {
            const file = e.dataTransfer.files[0];
            if (!file) return;
            input.files = e.dataTransfer.files;
            label.textContent = file.name;
        });

        // Предотвращаем двойной submit (второй раз кликнул "Загрузить" пока первый ёще в полёте).
        const form = zone.closest('form');
        if (form) {
            form.addEventListener('submit', (e) => {
                if (form.__submitting) {
                    e.preventDefault();
                    return;
                }
                form.__submitting = true;
                // Разблокируем через 30с на случай ошибки (обычно браузер перезагрузит страницу после PRG redirect).
                setTimeout(() => { form.__submitting = false; }, 30000);
            });
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // STATUS POLLING (ping + state check, детект failover/смены конфига)
    // ══════════════════════════════════════════════════════════════════

    let knownActiveId = '';
    let knownState    = 'stopped';

    // Подписка на unified ping service (app.js) — без собственного fetch interval
    function subscribeToPing() {
        const pingEl   = document.getElementById('ping-display');
        const statusEl = document.getElementById('connection-status');
        if (!pingEl || !window.MineVPN || !window.MineVPN.ping) return;

        window.MineVPN.ping.subscribe(({ status, ms }) => {
            if (status === 'ok') {
                pingEl.textContent = ms + ' мс';
                pingEl.className = 'status-value mono text-emerald';
                if (statusEl) {
                    statusEl.className = 'status-pill status-pill--ok';
                    statusEl.innerHTML = '<span class="status-dot status-dot--ok status-dot--pulse"></span>Подключено';
                }
            } else if (status === 'slow') {
                pingEl.textContent = ms + ' мс';
                pingEl.className = 'status-value mono text-amber';
                if (statusEl) {
                    statusEl.className = 'status-pill status-pill--ok';
                    statusEl.innerHTML = '<span class="status-dot status-dot--warn"></span>Подключено';
                }
            } else if (status === 'bad') {
                pingEl.textContent = ms !== null ? (ms + ' мс') : '—';
                pingEl.className = 'status-value mono text-rose';
                if (statusEl) {
                    statusEl.className = 'status-pill status-pill--err';
                    statusEl.innerHTML = '<span class="status-dot status-dot--err"></span>Отключено';
                }
            } else {
                pingEl.textContent = '—';
                pingEl.className = 'status-value mono text-muted';
            }
        });
    }

    async function checkStateChange() {
        const result = await apiCall('status');
        if (!result.ok || !result.data) return;
        const { state, active_id } = result.data;

        // Детект failover: был один активный конфиг, стал другой, и оба running.
        // Это именно переключение на резервный — не вызываться при stopped/recovering/restarting.
        const wasRunning      = (knownActiveId !== '' && knownState === 'running');
        const isStillRunning  = (active_id !== ''   && state === 'running');
        const activeIdChanged = (active_id !== knownActiveId && active_id !== '');

        if (wasRunning && isStillRunning && activeIdChanged) {
            // Имя нового конфига берём из DOM — список конфигов рендерится сервером
            const newItem = document.querySelector(`[data-config-id="${active_id}"] .config-name`);
            const newName = newItem ? newItem.textContent.trim() : '';
            if (window.MineVPN && MineVPN.markFailoverPending) {
                MineVPN.markFailoverPending({ to: newName, toId: active_id });
            }
        }

        if (active_id !== knownActiveId || state !== knownState) {
            // State или active config поменялся — reload чтобы обновить UI
            location.reload();
        }
    }

    function startPolling() {
        const initial = document.getElementById('vpn-state-data');
        if (initial) {
            knownActiveId = initial.dataset.activeId || '';
            knownState    = initial.dataset.state || 'stopped';
        }
        subscribeToPing();
        // Check state change — отдельный polling для детекта failover/смены конфига
        setInterval(checkStateChange, 10000);
    }

    // ══════════════════════════════════════════════════════════════════
    // Helpers для обновления UI без reload
    // ══════════════════════════════════════════════════════════════════

    function updateConfigCount() {
        const countEl = document.getElementById('config-count');
        if (!countEl) return;
        const n = document.querySelectorAll('.config-item').length;
        countEl.textContent = n + ' шт.';
    }

    function showEmptyStateIfNoConfigs() {
        const list = document.getElementById('config-items');
        const empty = document.getElementById('config-empty-state');
        if (!list || !empty) return;
        if (list.querySelectorAll('.config-item').length === 0) {
            list.style.display = 'none';
            empty.style.display = '';
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // Event delegation для всех кнопок с data-action
    // ══════════════════════════════════════════════════════════════════

    function setupEventDelegation() {
        document.addEventListener('click', (e) => {
            const el = e.target.closest('[data-action]');
            if (!el) return;
            const action = el.dataset.action;
            const configId   = el.dataset.configId   || '';
            const configName = el.dataset.configName || '';

            switch (action) {
                case 'activate':     e.preventDefault(); doActivate(configId, configName); break;
                case 'delete':       e.preventDefault(); doDelete(configId, configName); break;
                case 'toggle-role':  e.preventDefault(); doToggleRole(configId); break;
                case 'stop':         e.preventDefault(); doStop(); break;
                case 'restart':      e.preventDefault(); doRestart(); break;
                case 'bulk-toggle':  e.preventDefault(); toggleBulkMode(); break;
                case 'bulk-delete':  e.preventDefault(); doBulkDelete(); break;
                case 'move-up':      e.preventDefault(); doMove(configId, 'up'); break;
                case 'move-down':    e.preventDefault(); doMove(configId, 'down'); break;
                case 'rename':
                    e.preventDefault();
                    const configItem = document.querySelector(`[data-config-id="${configId}"]`);
                    const nameEl = configItem?.querySelector('.config-name');
                    if (nameEl) startInlineRename(nameEl);
                    // Закрываем меню
                    document.querySelectorAll('.action-menu.is-open').forEach(m => m.classList.remove('is-open'));
                    break;
            }
        });

        // Double-click на name → inline rename
        document.addEventListener('dblclick', (e) => {
            const nameEl = e.target.closest('.config-name');
            if (nameEl) startInlineRename(nameEl);
        });

        // Inline rename — keyboard
        document.addEventListener('keydown', (e) => {
            const nameEl = e.target.closest('.config-name.is-editing');
            if (!nameEl) return;
            if (e.key === 'Enter')  { e.preventDefault(); commitInlineRename(nameEl); }
            if (e.key === 'Escape') { e.preventDefault(); cancelInlineRename(nameEl); }
        });

        // Inline rename — blur (клик вне)
        document.addEventListener('focusout', (e) => {
            const nameEl = e.target.closest('.config-name.is-editing');
            if (nameEl) {
                // Даём немного времени чтобы focusin на intended element пришёл раньше
                setTimeout(() => {
                    if (nameEl.classList.contains('is-editing')) commitInlineRename(nameEl);
                }, 100);
            }
        }, true);
    }

    // ══════════════════════════════════════════════════════════════════
    // Init
    // ══════════════════════════════════════════════════════════════════

    function init() {
        setupDragDrop();
        setupSearch();
        setupBulkSelection();
        setupActionMenus();
        setupUploadZone();
        setupEventDelegation();
        startPolling();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
