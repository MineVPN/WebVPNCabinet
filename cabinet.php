<?php
/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ══════════════════════════════════════════════════════════════════
 *              C A B I N E T   P A G E   F I L E
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
 * MineVPN Server — Cabinet / Главная оболочка панели
 * Версия: 5 — редизайн (синяя палитра, увеличенный UI, Dashboard поглощён Stats)
 *
 * Ответственность:
 *   • Управление сессиями (timeout 8ч, idle 30мин, проверка IP)
 *   • Роутинг к pages/*.php через параметр ?menu=X
 *   • Sidebar + layout каркас (рендер навигации, cache-buster версия CSS/JS)
 *
 * POST handlers (PRG pattern):
 *   Форма-POST идёт в cabinet.php, но обработчик выбирается по ?menu=X:
 *     ?menu=vpn + POST → pages/vpn-manager.handler.php
 *   Хэндлер выполняет действие и делает header('Location:...') + exit.
 *   Без PRG — F5 или location.reload() в JS повторяет form submission (дубли конфигов).
 *
 * Взаимодействует с:
 *   • index.php — входная точка, include этого файла при валидной сессии
 *   • login.php — редирект сюда при отсутствии/timeout сессии
 *   • logout.php — ссылка в sidebar (пункт «Выход»)
 *   • pages/*.php — все страницы панели (stats, vpn-manager, pinger, console,
 *                  netsettings, settings, about) — включаются в основной layout
 *   • pages/*.handler.php — POST handlers для PRG (vpn-manager.handler.php и др.)
 *   • assets/css/{tokens,base,components,layout}.css — глобальные стили
 *   • assets/js/lib/{toast,progress,shortcuts,custom-select,confirm}.js — компоненты UI
 *   • assets/js/app.js — bootstrap (ping service, loading overlay, flash messages, failover toast)
 *
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ── Аутентификация ────────────────────────────────────────────────
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

// ── Таймауты сессии ────────────────────────────────────────────────
define('SESSION_TIMEOUT', 8 * 3600);
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > SESSION_TIMEOUT) {
    session_unset(); session_destroy();
    header('Location: login.php?reason=timeout'); exit();
}
$inactiveTimeout = 30 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $inactiveTimeout) {
    session_unset(); session_destroy();
    header('Location: login.php?reason=timeout'); exit();
}
$_SESSION['last_activity'] = time();

// ── Защита от session hijacking (смена IP) ─────────────────────────
if (isset($_SESSION['ip']) && !empty($_SESSION['ip'])) {
    $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($currentIp !== $_SESSION['ip']) {
        session_unset(); session_destroy();
        header('Location: login.php'); exit();
    }
}

// ── Роутинг меню ───────────────────────────────────────────────────
// Стартовая страница — stats (пункт "Обзор" в меню).
// Dashboard удалён в v5 — его функции (быстрый обзор) поглощены Stats.
if (isset($_POST['menu'])) {
    $_GET['menu'] = $_POST['menu'];
}
$menu_item = $_GET['menu'] ?? 'stats';

$menu_pages = [
    'stats'       => 'pages/stats.php',
    'vpn'         => 'pages/vpn-manager.php',
    'ping'        => 'pages/pinger.php',
    'console'     => 'pages/console.php',
    'netsettings' => 'pages/netsettings.php',
    'settings'    => 'pages/settings.php',
    'about'       => 'pages/about.php',
];
if (!array_key_exists($menu_item, $menu_pages)) {
    $menu_item = 'stats';
}

// ── POST handlers ───────────────────────────────────────────────────────────────
// Обработчики form POST отделены от render-файлов и выполняются ДО вывода HTML.
// Это позволяет им делать header('Location: ...') для PRG паттерна.
// Без PRG — повторный reload в браузере (в т. ч. вызванный location.reload() из JS)
// повторяет form submission → дублируются загруженные конфиги. ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Имя хэндлера = имя render-файла без .php + .handler.php
    // (напр. pages/vpn-manager.php → pages/vpn-manager.handler.php)
    $renderFile  = $menu_pages[$menu_item];
    $handlerPath = __DIR__ . '/' . preg_replace('/\.php$/', '.handler.php', $renderFile);
    if (file_exists($handlerPath)) {
        include_once $handlerPath;
        // Хэндлер должен сам вызвать header('Location:...') + exit для PRG.
        // Если этого не случилось (хэндлер не сработал) — продолжаем обычный render.
    }
}

// Хелпер для active-класса меню
function menuActive(string $current, string $item): string {
    return $current === $item ? 'menu-item is-active' : 'menu-item';
}

// ── Версия CSS/JS для cache-busting ───────────────────────────────
// Инкрементировать при каждом изменении файлов assets/css или assets/js.
// Клиентский кэш задан на 1 год в .htaccess — смена ?v= — единственный способ
// заставить браузер скачать новые ассеты.
//
// 5.6.4 → 5.6.5: BUGFIXES для модального вікна:
//                1. Прибрано backdrop-filter: blur(6px) в .modal-backdrop — він їв ресурс на слабкому залізі.
//                   Залишено рівномірне затемнення rgba(0.85) — контраст достатній без blur.
//                2. confirm.js: компенсація scrollbar gutter shift (body padding-right = scrollbarWidth)
//                   — контент не дьоргається вправо коли викликається модалка.
//                + about.php max-width 1100→1600px (розтягується ширше).
//
// 5.6.5 → 5.6.6: КОСМЕТИКА сидбару:
//                Sidebar scrollbar приховано (scrollbar-width: none + ::-webkit-scrollbar
//                display: none). Меню коротке, скрол рідко потрібний, а 12px брендова смужка
//                візуально перекривалася з gradient-бордером (rose-violet-orange).
//                Скрол все ще працює (коліскою/свайпом) — просто без відображення індикатора.
$cssVer = '5.6.6';
?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <title>MineVPN Server Panel</title>

    <link rel="stylesheet" href="assets/css/tokens.css?v=<?php echo $cssVer; ?>">
    <link rel="stylesheet" href="assets/css/base.css?v=<?php echo $cssVer; ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?php echo $cssVer; ?>">
    <link rel="stylesheet" href="assets/css/layout.css?v=<?php echo $cssVer; ?>">
</head>
<body data-page="<?php echo htmlspecialchars($menu_item); ?>">

<div class="app">

    <!-- ════════════════════ Sidebar ════════════════════ -->
    <aside class="sidebar">

        <!-- Брендинг / лого -->
        <a href="cabinet.php" class="sidebar-brand" aria-label="MineVPN">
            <img src="assets/img/logo.png" alt="">
            <div class="sidebar-brand-text">
                <div class="sidebar-brand-name">MineVPN</div>
                <div class="sidebar-brand-sub">Server Panel</div>
            </div>
        </a>

        <!-- Навигация -->
        <nav class="sidebar-nav" aria-label="Навигация">

            <a href="cabinet.php?menu=stats" class="<?php echo menuActive($menu_item, 'stats'); ?>" data-accent="blue">
                <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
                <span class="menu-label">Обзор</span>
            </a>

            <a href="cabinet.php?menu=vpn" class="<?php echo menuActive($menu_item, 'vpn'); ?>" data-accent="emerald">
                <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                <span class="menu-label">VPN</span>
                <span class="menu-meta">
                    <span id="sidebar-vpn-dot" class="status-dot status-dot--off" aria-hidden="true"></span>
                    <span id="sidebar-ping-display" class="menu-ping">—</span>
                </span>
            </a>

            <a href="cabinet.php?menu=ping" class="<?php echo menuActive($menu_item, 'ping'); ?>" data-accent="cyan">
                <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                <span class="menu-label">Пинг</span>
            </a>

            <a href="cabinet.php?menu=console" class="<?php echo menuActive($menu_item, 'console'); ?>" data-accent="purple">
                <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <span class="menu-label">Консоль</span>
            </a>

            <a href="cabinet.php?menu=netsettings" class="<?php echo menuActive($menu_item, 'netsettings'); ?>" data-accent="amber">
                <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                </svg>
                <span class="menu-label">Сеть</span>
            </a>

            <a href="cabinet.php?menu=settings" class="<?php echo menuActive($menu_item, 'settings'); ?>" data-accent="slate">
                <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                <span class="menu-label">Настройки</span>
            </a>

            <a href="cabinet.php?menu=about" class="<?php echo menuActive($menu_item, 'about'); ?>" data-accent="pink">
                <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <circle cx="12" cy="12" r="10"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16v-4m0-4h.01"/>
                </svg>
                <span class="menu-label">О продукте</span>
            </a>

            <div class="sidebar-divider"></div>

            <a href="logout.php" class="menu-item menu-item--logout">
                <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                <span class="menu-label">Выход</span>
            </a>
        </nav>

        <!-- Футер: посылка на магазин MineVPN с иконкой. Brand-gradient text и tonal hover. -->
        <div class="sidebar-footer">
            <a href="https://minevpn.net/" target="_blank" rel="noopener noreferrer" class="sidebar-footer-link">
                <svg class="sidebar-footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                </svg>
                <span class="sidebar-footer-text">minevpn.net</span>
                <svg class="sidebar-footer-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                </svg>
            </a>
        </div>
    </aside>

    <!-- ════════════════════ Основной контент ════════════════════ -->
    <main class="main">
        <div class="page-content">
            <?php
            $pagePath = __DIR__ . '/' . $menu_pages[$menu_item];
            if (file_exists($pagePath)) {
                include_once $pagePath;
            } else {
                echo '<div class="card"><div class="empty-state">';
                echo '<div class="empty-state-title text-rose">Страница не найдена</div>';
                echo '<div class="empty-state-text">Проверьте параметр ?menu=</div>';
                echo '</div></div>';
            }
            ?>
        </div>
    </main>

</div>

<!-- ════════════════════ Скрипты ════════════════════ -->
<script src="assets/js/lib/toast.js?v=<?php echo $cssVer; ?>"></script>
<script src="assets/js/lib/progress.js?v=<?php echo $cssVer; ?>"></script>
<script src="assets/js/lib/shortcuts.js?v=<?php echo $cssVer; ?>"></script>
<script src="assets/js/lib/custom-select.js?v=<?php echo $cssVer; ?>"></script>
<script src="assets/js/lib/confirm.js?v=<?php echo $cssVer; ?>"></script>
<script src="assets/js/app.js?v=<?php echo $cssVer; ?>"></script>

</body>
</html>
