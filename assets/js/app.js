/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ══════════════════════════════════════════════════════════════════
 *                     J S   A P P   F I L E
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
 * MineVPN Server — App / Bootstrap-файл панели
 *
 * Основной координатор JS-логики панели. Подключается на всех страницах через cabinet.php. Регистрируется
 * как window.MineVPN и window.{Notice, showVpnLoading, hideVpnLoading}.
 *
 * 5 основных фичей:
 *
 * 1. UNIFIED PING SERVICE (subscribe pattern):
 *    Один polling на всю страницу, много слушателей (sidebar, VPN page, и др.).
 *    Снижает нагрузку на сервер — ping выполняется один раз в 5с независимо от количества потребителей результата.
 *    Public API:
 *      window.MineVPN.ping.subscribe(cb)  → cb({status, ms}) on every update, возвращает unsub-функцию
 *      window.MineVPN.ping.refresh()      → форсирует немедленное обновление
 *    Status classification:
 *      ms < 100   → 'ok'    (зелёный)
 *      ms < 200   → 'slow'  (жёлтый)
 *      ms >= 200  → 'bad'   (красный)
 *      null       → 'off'   (серый, нет интернета)
 *    Smart polling:
 *      • Polling активен только когда есть подписчики И таб видим (visibilitychange)
 *      • inflight flag — блокирует двойные одновременные fetch-и
 *      • При подписке сразу выдаём lastResult (cache) — не ждём 5с первого fetch
 *
 * 2. SIDEBAR PING CONSUMER:
 *    Подписывается на ping service и обновляет #sidebar-vpn-dot и #sidebar-ping-display.
 *    Широкий spectrum статусов: status-dot--ok/warn/err/off, ping--good/slow/bad.
 *
 * 3. LOADING OVERLAY (window.showVpnLoading / hideVpnLoading):
 *    Full-screen overlay со спиннером для длительных VPN-действий (activate, restart, stop, upload).
 *    Lazy-built при первом вызове. Используется в vpn.js и авто-привязывается к form[data-vpn-action] в bindFormLoaders.
 *
 * 4. NOTICE() BRIDGE:
 *    Совместимость со старым кодом который вызывает Notice('text', 'success'|'error'|'warning').
 *    Bridge переводит это на Toast.success/error/warning — не ломает старые inline-вызовы.
 *
 * 5. PHP FLASH MESSAGES → TOAST:
 *    PHP сторона записывает в $_SESSION['mv_flash'] → рендерится как window.__flashMessage = {text, type}.
 *    showFlashIfAny() читает это при init → Toast.success/error/warning → стирает переменную (защита от двойного показа).
 *
 * 6. FAILOVER TOAST PROTOCOL:
 *    Проблема: failover детектится в vpn.js (перед location.reload), но тост надо показать ПОСЛЕ reload.
 *    Решение: vpn.js → markFailoverPending({to: '<имя>'}) → sessionStorage[mv_failover_pending] →
 *              app.js при init → showFailoverToastIfPending() → Toast с «Купить в MineVPN» action.
 *    sessionStorage (НЕ localStorage) — тост покажется только в той же browser-вкладке.
 *    Длительность toast-а — 12000мс + action button (юзеру нужно время прочитать).
 *
 * 7. FORM LOADERS (bindFormLoaders):
 *    Авто-привязка loading overlay к form[data-vpn-action] при submit. Labels из declarative array:
 *      activate → "Подключение VPN..."
 *      restart  → "Перезапуск VPN..."
 *      stop     → "Остановка VPN..."
 *      delete   → "Удаление..."
 *      upload   → "Загрузка конфига..."
 *    __vpnBound flag на form — защита от двойной привязки при повторных bindFormLoaders().
 *
 * Public API (window.MineVPN.*):
 *   ping             — сервис ping (subscribe/refresh/_stats)
 *   refreshPing()    — alias к ping.refresh() для legacy вызовов
 *   bindFormLoaders  — повторно привязывать form-loaders для динамически добавленных форм
 *   markFailoverPending — vpn.js вызывает перед reload при детекте failover
 *
 * Взаимодействует с:
 *   • cabinet.php — подключает этот JS глобально на всех страницах
 *   • api/ping.php — endpoint для ping service (host=8.8.8.8 interface=tun0)
 *   • assets/js/lib/toast.js — Toast.success/error/warning + Toast.show()
 *   • assets/js/pages/vpn.js — вызывает showVpnLoading + markFailoverPending
 *
 * Зависит от:
 *   toast.js, progress.js, shortcuts.js — должны быть загружены раньше.
 */

(function() {
    'use strict';

    // ══════════════════════════════════════════════════════════════════
    // UNIFIED PING SERVICE
    // ══════════════════════════════════════════════════════════════════
    // API:
    //   window.MineVPN.ping.subscribe(cb)  — cb({status, ms}) на каждое обновление
    //                                         Возвращает unsub-функцию.
    //   window.MineVPN.ping.refresh()      — форсирует немедленное обновление
    //
    // Результат cb:
    //   { status: 'ok'|'slow'|'bad'|'off', ms: 42 | null }
    //
    // Polling активен только когда есть подписчики и таб виден.

    const PING_INTERVAL_MS = 5000;

    const ping = (function() {
        const subscribers = new Set();
        let timer = null;
        let lastResult = { status: 'off', ms: null };
        let inflight = false;

        function classify(msOrNull) {
            if (msOrNull === null) return 'off';
            if (msOrNull < 0)      return 'bad'; // специальный маркер "NO PING"
            if (msOrNull < 100)    return 'ok';
            if (msOrNull < 200)    return 'slow';
            return 'bad';
        }

        function broadcast(result) {
            lastResult = result;
            subscribers.forEach(cb => {
                try { cb(result); } catch (e) { console.error('ping subscriber:', e); }
            });
        }

        function doFetch() {
            if (inflight) return;
            inflight = true;

            fetch('api/ping.php?host=8.8.8.8&interface=tun0', { cache: 'no-store' })
                .then(r => r.text())
                .then(data => {
                    if (data.includes('NO PING')) {
                        broadcast({ status: 'bad', ms: null });
                    } else {
                        const ms = Math.round(parseFloat(data));
                        broadcast({ status: classify(ms), ms });
                    }
                })
                .catch(() => {
                    broadcast({ status: 'off', ms: null });
                })
                .finally(() => {
                    inflight = false;
                });
        }

        function startPolling() {
            if (timer) return;
            doFetch(); // сразу, не ждём 5с
            timer = setInterval(doFetch, PING_INTERVAL_MS);
        }

        function stopPolling() {
            if (timer) { clearInterval(timer); timer = null; }
        }

        function updatePollingState() {
            // Активируем polling только если есть подписчики И таб виден
            if (subscribers.size > 0 && !document.hidden) {
                startPolling();
            } else {
                stopPolling();
            }
        }

        function subscribe(cb) {
            subscribers.add(cb);
            // Сразу отдаём последний известный результат
            try { cb(lastResult); } catch (e) {}
            updatePollingState();
            return function unsubscribe() {
                subscribers.delete(cb);
                updatePollingState();
            };
        }

        // Останавливаем polling когда таб в фоне, возобновляем при возврате
        document.addEventListener('visibilitychange', updatePollingState);

        return {
            subscribe,
            refresh: doFetch,
            _stats: () => ({ subscribers: subscribers.size, polling: !!timer, last: lastResult }),
        };
    })();

    // ══════════════════════════════════════════════════════════════════
    // SIDEBAR ping consumer — подписывается на ping service
    // ══════════════════════════════════════════════════════════════════

    function setupSidebarPing() {
        const dot  = document.getElementById('sidebar-vpn-dot');
        const ping_el = document.getElementById('sidebar-ping-display');
        if (!dot || !ping_el) return;

        ping.subscribe(({ status, ms }) => {
            // Сбрасываем все статусные классы
            dot.className = 'status-dot';
            ping_el.className = 'menu-ping';

            if (status === 'ok') {
                ping_el.textContent = ms + 'ms';
                ping_el.classList.add('ping--good');
                dot.classList.add('status-dot--ok', 'status-dot--pulse');
            } else if (status === 'slow') {
                ping_el.textContent = ms + 'ms';
                ping_el.classList.add('ping--slow');
                dot.classList.add('status-dot--warn');
            } else if (status === 'bad') {
                ping_el.textContent = ms !== null ? ms + 'ms' : '—';
                ping_el.classList.add('ping--bad');
                dot.classList.add('status-dot--err');
            } else {
                // off — ещё не замеряли или ошибка сети
                ping_el.textContent = '—';
                dot.classList.add('status-dot--off');
            }
        });
    }

    // ══════════════════════════════════════════════════════════════════
    // Loading overlay — для длительных VPN-действий
    // ══════════════════════════════════════════════════════════════════

    let overlay = null;

    function getOverlay() {
        if (overlay && document.body.contains(overlay)) return overlay;
        overlay = document.createElement('div');
        overlay.className = 'loading-overlay';
        overlay.innerHTML = `
            <div class="loading-spinner"></div>
            <div class="loading-text" id="vpn-loading-text">Подключение...</div>
            <div class="text-sm text-muted">Пожалуйста, подождите</div>
        `;
        document.body.appendChild(overlay);
        return overlay;
    }

    window.showVpnLoading = function(text) {
        const o = getOverlay();
        const t = o.querySelector('#vpn-loading-text');
        if (t) t.textContent = text || 'Подключение...';
        o.classList.add('is-open');
    };

    window.hideVpnLoading = function() {
        const o = overlay;
        if (o) o.classList.remove('is-open');
    };

    // ══════════════════════════════════════════════════════════════════
    // Notice() bridge — совместимость со старыми вызовами
    // ══════════════════════════════════════════════════════════════════

    window.Notice = function(text, type) {
        if (!window.Toast) return;
        if (type === 'error')        Toast.error(text);
        else if (type === 'warning') Toast.warning(text);
        else                         Toast.success(text);
    };

    // ══════════════════════════════════════════════════════════════════
    // PHP flash-сообщения через Toast
    // ══════════════════════════════════════════════════════════════════

    function showFlashIfAny() {
        if (!window.__flashMessage || !window.Toast) return;
        const { text, type } = window.__flashMessage;
        if (type === 'error')        Toast.error(text);
        else if (type === 'warning') Toast.warning(text);
        else                         Toast.success(text);
        window.__flashMessage = null;
    }

    // ══════════════════════════════════════════════════════════════════
    // Auto-attach loading overlay к формам VPN-действий
    // ══════════════════════════════════════════════════════════════════

    function bindFormLoaders() {
        document.querySelectorAll('form[data-vpn-action]').forEach(form => {
            if (form.__vpnBound) return;
            form.__vpnBound = true;
            form.addEventListener('submit', () => {
                const action = form.getAttribute('data-vpn-action');
                const labels = {
                    'activate': 'Подключение VPN...',
                    'restart':  'Перезапуск VPN...',
                    'stop':     'Остановка VPN...',
                    'delete':   'Удаление...',
                    'upload':   'Загрузка конфига...',
                };
                showVpnLoading(labels[action] || 'Пожалуйста, подождите...');
            });
        });
    }

    // ══════════════════════════════════════════════════════════════════
    // Init
    // ══════════════════════════════════════════════════════════════════

    // ──────────────────────────────────────────────────────────────────
    // FAILOVER toast — показ при срабатывании резервирования.
    //
    // Проблема: failover детектится в vpn.js (poll status каждые 10с) перед location.reload(),
    // но сам toast надо показать ПОСЛЕ reload — иначе юзер его не увидит (DOM уничтожится).
    //
    // Решение: vpn.js записывает в sessionStorage перед reload, app.js при init читает и показывает.
    //
    // sessionStorage (а не localStorage) — toast показывается только в той же браузер-вкладке
    // где юзер реально был при failover. Если зажмёт панель и откроет заново — не будем показывать.
    // ──────────────────────────────────────────────────────────────────

    const FAILOVER_KEY = 'mv_failover_pending';

    function markFailoverPending(opts) {
        try {
            sessionStorage.setItem(FAILOVER_KEY, JSON.stringify(opts || {}));
        } catch (e) {}
    }

    function showFailoverToastIfPending() {
        if (!window.Toast) return;
        let data;
        try {
            const raw = sessionStorage.getItem(FAILOVER_KEY);
            if (!raw) return;
            sessionStorage.removeItem(FAILOVER_KEY);
            data = JSON.parse(raw);
        } catch (e) { return; }

        const newName = data && data.to ? String(data.to).slice(0, 64) : '';
        const body = newName
            ? 'Основной VPN упал. Подключён резервный: ' + newName + '.'
            : 'Основной VPN упал. Подключён резервный конфиг.';

        Toast.show({
            type: 'warning',
            title: 'Сработало резервирование',
            body: body + ' Нужен ещё запас конфигов?',
            action: { text: 'Купить в MineVPN', href: 'https://minevpn.net/' },
            duration: 12000,
        });
    }

    function init() {
        setupSidebarPing();
        showFlashIfAny();
        showFailoverToastIfPending();
        bindFormLoaders();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // ══════════════════════════════════════════════════════════════════
    // Public API
    // ══════════════════════════════════════════════════════════════════

    // ВАЖЛИВО: Object.assign замість прямої заміни window.MineVPN — щоб НЕ перезатерти
    // властивості записані раніше іншими лібами. Найважливіше для confirm.js —
    // confirm.js виконується РАНІШЕ app.js і додає window.MineVPN.confirm.
    // Якщо тут було б window.MineVPN = {…} — confirm би зник, і vpn.js + stats.js
    // падали б на fallback native confirm() (як раніше і було).
    window.MineVPN = window.MineVPN || {};
    Object.assign(window.MineVPN, {
        ping,
        // Legacy — страницы вызывают это после ping action:
        refreshPing: () => ping.refresh(),
        bindFormLoaders,
        // Failover toast протокол: vpn.js вызывает markFailoverPending() перед reload,
        // app.js показывает toast при следующем init (после reload).
        markFailoverPending,
    });
})();
