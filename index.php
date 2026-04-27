<?php
/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ══════════════════════════════════════════════════════════════════
 *                       I N D E X   F I L E
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
 * MineVPN Server — Router / Точка входа
 *
 * Маршрутизирует запросы к корню панели:
 *   • Сессия валидна      → include cabinet.php (рендер панели)
 *   • Сессия отсутствует → include login.php  (форма входа)
 *
 * Особенность: НЕ делает header('Location:...'), а include — URL в браузере остаётся "/".
 * Предотвращает лишние редиректы и скрывает внутреннюю структуру от пользователя.
 *
 * Взаимодействует с:
 *   • cabinet.php — полная оболочка панели (sidebar + контент, своя аутентификация)
 *   • login.php   — форма входа (при отсутствии валидной сессии)
 *   • PHP session storage — стандартное хранилище $_SESSION
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION["authenticated"]) && $_SESSION["authenticated"] === true) {
    include __DIR__ . '/cabinet.php';
} else {
    include __DIR__ . '/login.php';
}
?>
