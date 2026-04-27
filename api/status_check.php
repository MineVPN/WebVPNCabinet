<?php
/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ══════════════════════════════════════════════════════════════════
 *           A P I   S T A T U S   C H E C K   F I L E
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
 * MineVPN Server — VPN State API / Live polling состояния VPN для sidebar
 *
 * GET-запрос возвращает текущее состояние VPN в JSON для живого обновления UI
 * без перезагрузки страницы. Используется для:
 *   • Индикатора «VPN connected/disconnected» в sidebar
 *   • Обнаружения failover (изменение active_id при running→running)
 *   • Показа «recovering» статуса когда HC daemon пытается восстановить
 *
 * Response:
 *   {
 *     "state":        "running" | "stopped" | "recovering" | "restarting",
 *     "active_id":    "vpn_<hash>",
 *     "primary_id":   "vpn_<hash>",
 *     "activated_by": "manual" | "failover" | "",
 *     "connected":    true | false       // реальный статус tun0 (existence + UP)
 *   }
 *
 * Безопасный парсинг: только ALLOWED ключи (без eval/extract), все значения явные.
 *
 * Взаимодействует с:
 *   • assets/js/app.js — поллит этот endpoint для live state (failover toast и статус-бейдж)
 *   • /var/www/minevpn-state — читает STATE/ACTIVE_ID/PRIMARY_ID/ACTIVATED_BY (пишет HC daemon)
 *   • system — вызывает `ip link show tun0` для реальной проверки (файл state может быть stale)
 */
session_start();
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(403);
    die('Unauthorized');
}

header('Content-Type: application/json');

$state = [
    'STATE' => 'stopped',
    'ACTIVE_ID' => '',
    'PRIMARY_ID' => '',
    'ACTIVATED_BY' => ''
];

// Безопасный парсинг: только ожидаемые ключи, без eval/extract
$stateFile = '/var/www/minevpn-state';
if (file_exists($stateFile)) {
    $allowed = ['STATE', 'ACTIVE_ID', 'PRIMARY_ID', 'ACTIVATED_BY'];
    $lines = @file($stateFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m) && in_array($m[1], $allowed, true)) {
            $state[$m[1]] = $m[2];
        }
    }
}

// Реальный статус tun0 (существует и UP)
$tun0output = shell_exec("ip link show tun0 2>&1") ?? '';
$tun0up = (strpos($tun0output, 'does not exist') === false && strpos($tun0output, ',UP') !== false);

echo json_encode([
    'state' => $state['STATE'],
    'active_id' => $state['ACTIVE_ID'],
    'primary_id' => $state['PRIMARY_ID'],
    'activated_by' => $state['ACTIVATED_BY'],
    'connected' => $tun0up
], JSON_UNESCAPED_UNICODE);
