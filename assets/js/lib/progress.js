 /**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ══════════════════════════════════════════════════════════════════
 *               J S   P R O G R E S S   F I L E
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
 * MineVPN Server — Progress / Компонент top progress bar
 *
 * Глобальный верхний progress bar (как у YouTube/GitHub) — показывается при длительных
 * AJAX-действиях или навигации. Регистрируется как window.Progress.
 *
 * Public API:
 *   Progress.start();   // показать, идёт до 85% (CSS-animation)
 *   Progress.done();    // завершить на 100% и скрыть
 *
 * Ручной вызов — нет fetch или link interceptor-ов. Вызываем явно в коде где нужен feedback,
 * обязательно с try/finally чтобы done() выполнился даже при ошибке.
 *
 * Multi-active counter:
 *   activeCount увеличивается на каждый start(), уменьшается на done(). Скрываем только когда достигли 0.
 *   Позволяет одновременные запросы (напр. fetch A + fetch B) — progress останется пока оба не завершатся.
 *
 * Reflow trick:
 *   Перед добавлением класса 'is-active' выполняем `void b.offsetWidth` — форсируем reflow.
 *   Без этого браузер оптимизирует add+remove 'is-done' в одном фрейме и transition не запускается.
 *
 * Container management:
 *   Один .progress-bar в DOM (создаётся lazy при первом start()). getBar() ре-создаёт если убрали.
 *
 * Кто использует (вызывает Progress.start/done):
 *   • assets/js/pages/vpn.js — activate/restart длительные операции (15+ секунд ждём poll VPN up)
 *   • assets/js/app.js — переходы между menu items (избежание ощущения зависания)
 *
 * Frontend assets:
 *   • assets/css/components.css — стили .progress-bar, .progress-bar-fill, .is-active, .is-done
 */

(function() {
    'use strict';

    let bar = null;
    let activeCount = 0;

    function getBar() {
        if (bar && document.body.contains(bar)) return bar;
        bar = document.createElement('div');
        bar.className = 'progress-bar';
        bar.innerHTML = '<div class="progress-bar-fill"></div>';
        document.body.appendChild(bar);
        return bar;
    }

    function start() {
        activeCount++;
        const b = getBar();
        // Reset state если был is-done
        b.classList.remove('is-done');
        // Trigger reflow чтобы transition сработал
        void b.offsetWidth;
        b.classList.add('is-active');
    }

    function done() {
        activeCount = Math.max(0, activeCount - 1);
        if (activeCount > 0) return;
        const b = getBar();
        b.classList.remove('is-active');
        b.classList.add('is-done');
        // Убрать класс после того как opacity transition завершится
        setTimeout(() => {
            if (activeCount === 0) b.classList.remove('is-done');
        }, 400);
    }

    // Public API
    window.Progress = { start, done };
})();
