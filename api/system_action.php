<?php
/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ══════════════════════════════════════════════════════════════════
 *          A P I   S Y S T E M   A C T I O N   F I L E
 * ══════════════════════════════════════════════════════════════════
 * @category    VPN Subsystem
 * @package     MineVPN\Server
 * @version     5.0.0
 * [WARNING]
 * This source code is strictly proprietary and confidential.
 * Unauthorized reproduction, distribution, or decompilation
 * is strictly prohibited and heavily monitored.
 * @copyright   2026 MineVPN Systems. All rights reserved.
 *
 * MineVPN — System Action API (JSON endpoint)
 *
 * AJAX-эндпоинт для системных действий над сервером (перезагрузка, выключение).
 * Вызывается со страницы Настройки → секция «Управление сервером».
 *
 * Request:  POST  application/json
 *   { "action": "reboot" | "poweroff" }
 *
 * Response: application/json
 *   { "ok": true,  "message": "Сервер перезапускается через 3 секунды..." }
 *   { "ok": false, "error":   "..." }
 *
 * Технические детали:
 *   • Команда отправляется через `nohup bash -c "sleep 3 && sudo systemctl ..." &` —
 *     это даёт PHP вернуть HTTP 200 ДО того как сервер начнёт shutdown.
 *     Без задержки браузер увидит "no response" / connection reset вместо ответа.
 *   • Логирует событие в /var/log/minevpn/events.log с типом 'system' для аудита
 *     "кто и когда нажал кнопку" (отображается на странице Обзор → События).
 *
 * Sudoers (требуется в /etc/sudoers.d/minevpn-www-data):
 *   www-data ALL=(ALL) NOPASSWD: /bin/systemctl reboot
 *   www-data ALL=(ALL) NOPASSWD: /bin/systemctl poweroff
 *
 *   Эти правила добавляются автоматически update.sh (sanity-check блок —
 *   выполняется при каждом обновлении и добавляет недостающие строки в sudoers).
 *
 * Взаимодействует с:
 *   • pages/settings.php — содержит UI и JS-обработчик который вызывает этот endpoint
 *   • assets/js/app.js — showVpnLoading / Toast / MineVPN.confirm используются клиентом
 *
 * Пишет:
 *   • /var/log/minevpn/events.log — событие system|{reboot,poweroff}|panel
 *
 * Вызывает:
 *   • sudo systemctl reboot   — graceful перезагрузка (стопает сервисы)
 *   • sudo systemctl poweroff — graceful выключение
 */

session_start();

// ── Auth ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json');

// ── Parse body (JSON или form) ───────────────────────────────────────
$raw = file_get_contents('php://input');
$body = [];
if (!empty($raw)) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $body = $decoded;
}
if (empty($body)) $body = $_POST;

$action = (string)($body['action'] ?? '');

/**
 * Запись в events.log для аудита (отображается в журнале на стр. Обзор).
 * Формат: TIMESTAMP|system|TYPE|SOURCE
 */
function logSystemEvent(string $type, string $source = 'panel'): void {
    $logFile = '/var/log/minevpn/events.log';
    $time = date('Y-m-d H:i:s');
    $line = $time . '|system|' . $type . '|' . $source . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function respond(bool $ok, string $msg): void {
    echo json_encode(
        $ok ? ['ok' => true, 'message' => $msg] : ['ok' => false, 'error' => $msg],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// ── Dispatch ─────────────────────────────────────────────────────────
switch ($action) {

case 'reboot':
    logSystemEvent('reboot');
    // sleep 3 — даём PHP вернуть HTTP 200 ДО того как systemd начнёт shutdown процессов.
    // nohup + & — отвязываем процесс от php-fpm, чтобы он жил после завершения запроса.
    // 2>&1 → /dev/null — буферы stdout/stderr закрыты, иначе nohup может зависнуть.
    exec('nohup bash -c "sleep 3 && /usr/bin/sudo /bin/systemctl reboot" > /dev/null 2>&1 &');
    respond(true, 'Сервер перезапускается. Панель будет недоступна 30-60 секунд.');
    break;

case 'poweroff':
    logSystemEvent('poweroff');
    exec('nohup bash -c "sleep 3 && /usr/bin/sudo /bin/systemctl poweroff" > /dev/null 2>&1 &');
    respond(true, 'Сервер выключается. Для включения нужен физический доступ.');
    break;

default:
    respond(false, 'Неизвестное действие: ' . htmlspecialchars($action));
}
