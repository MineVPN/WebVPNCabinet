<?php
/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ══════════════════════════════════════════════════════════════════
 *                      L O G O U T   F I L E
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
 * MineVPN Server — Logout / Завершение сессии
 *
 * Уничтожает сессию и удаляет cookie PHPSESSID, редиректит на login.php.
 *
 * Защита от speculative prefetch:
 *   Chrome/Edge ("Preload pages for faster browsing") и Firefox делают GET-запрос
 *   при наведении курсора на ссылку. Без проверки Sec-Purpose — юзер вылетает
 *   только от hover на кнопке «Выход».
 *
 * Взаимодействует с:
 *   • cabinet.php — содержит <a href="logout.php"> в sidebar (пункт «Выход»)
 *   • login.php   — редирект сюда после уничтожения сессии
 *   • PHP session storage — удаляет файл сессии /var/lib/php/sessions/sess_*
 *   • HTTP cookies — удаляет PHPSESSID из браузера (setcookie с time()-42000)
 */
session_start();

// ─────────────────────────────────────────────────────────────────
// Защита от спекулятивного prefetch браузеров
// ─────────────────────────────────────────────────────────────────
// Chrome/Edge ("Preload pages for faster browsing") и Firefox делают speculative GET-запрос
// когда юзер наводит курсор на ссылку (чтобы страница открылась мгновенно при клике).
// Без этой проверки session_destroy() выполнится только от наведения курсора на "Выход" —
// юзер вылетает не нажимая кнопку.
//
// Браузеры помечают speculative-запросы стандартными заголовками:
//   Sec-Purpose: prefetch / prerender        — стандарт (Chrome/Edge сучасные)
//   Purpose: prefetch                        — legacy Chrome
//   X-Moz: prefetch                          — Firefox legacy
$purpose     = $_SERVER['HTTP_SEC_PURPOSE'] ?? ($_SERVER['HTTP_PURPOSE'] ?? '');
$mozPrefetch = $_SERVER['HTTP_X_MOZ']       ?? '';

if (stripos($purpose, 'prefetch') !== false
    || stripos($purpose, 'prerender') !== false
    || stripos($mozPrefetch, 'prefetch') !== false) {
    // Это не реальный клик юзера — браузер speculative грузит страницу. Не знищуемо сессію.
    http_response_code(204);
    header('Cache-Control: no-store');
    exit;
}

session_unset();
session_destroy();

// Удаляем cookie PHPSESSID из браузера — без этого старая кука остаётся в браузере
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

header('Location: login.php');
exit();
?>