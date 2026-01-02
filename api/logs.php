<?php
// ==============================================================================
// MINE SERVER - API логов
// ==============================================================================

header('Content-Type: application/json');
header('Cache-Control: no-cache');

session_start();
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized']));
}

$type = $_GET['type'] ?? 'system';
$lines = min((int)($_GET['lines'] ?? 100), 500);

function getJournalLogs($unit, $lines) {
    // Используем sudo journalctl
    $cmd = "sudo journalctl -u " . escapeshellarg($unit) . " -n $lines --no-pager -o short-iso 2>/dev/null";
    $output = shell_exec($cmd);
    
    if (empty($output)) {
        // Fallback без sudo
        $cmd = "journalctl -u " . escapeshellarg($unit) . " -n $lines --no-pager -o short-iso 2>/dev/null";
        $output = shell_exec($cmd);
    }
    
    return parseLogs($output);
}

function getFileLogs($file, $lines) {
    if (!file_exists($file)) return [];
    
    $cmd = is_readable($file) 
        ? "tail -n $lines " . escapeshellarg($file) . " 2>/dev/null"
        : "sudo tail -n $lines " . escapeshellarg($file) . " 2>/dev/null";
    
    return parseLogs(shell_exec($cmd));
}

function parseLogs($output) {
    if (empty($output)) return [];
    
    $logs = [];
    foreach (explode("\n", trim($output)) as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Пропускаем служебные сообщения
        if (strpos($line, 'Hint:') !== false || 
            strpos($line, 'No journal files') !== false ||
            strpos($line, 'insufficient permissions') !== false) {
            continue;
        }
        
        // Определяем уровень
        $level = 'info';
        $lc = strtolower($line);
        if (preg_match('/error|fail|fatal|crash/i', $lc)) $level = 'error';
        elseif (preg_match('/warn/i', $lc)) $level = 'warning';
        elseif (preg_match('/started|connected|success|established/i', $lc)) $level = 'success';
        
        // Парсим время
        $time = null;
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T[\d:]+)/', $line, $m)) {
            $time = $m[1];
        }
        
        $logs[] = [
            'time' => $time,
            'message' => $line,
            'level' => $level
        ];
    }
    
    return $logs;
}

// Проверяем существование конфига ПЕРЕД получением логов
$hasOpenvpn = file_exists('/etc/openvpn/tun0.conf');
$hasWireguard = file_exists('/etc/wireguard/tun0.conf');

$logs = [];

switch ($type) {
    case 'openvpn':
        if ($hasOpenvpn) {
            $logs = getJournalLogs('openvpn@tun0', $lines);
        } else {
            $logs = [['time' => date('c'), 'message' => 'OpenVPN конфигурация не установлена', 'level' => 'info']];
        }
        break;
        
    case 'wireguard':
        if ($hasWireguard) {
            $logs = getJournalLogs('wg-quick@tun0', $lines);
        } else {
            $logs = [['time' => date('c'), 'message' => 'WireGuard конфигурация не установлена', 'level' => 'info']];
        }
        break;
        
    case 'healthcheck':
        $logs = array_merge(
            getJournalLogs('vpn-healthcheck', $lines / 2),
            getFileLogs('/var/log/vpn-healthcheck.log', $lines / 2)
        );
        break;
        
    case 'dnsmasq':
        $logs = getJournalLogs('dnsmasq', $lines);
        break;
        
    case 'syslog':
        $logs = getFileLogs('/var/log/syslog', $lines);
        break;
        
    case 'system':
    default:
        // Только существующие VPN
        if ($hasWireguard) {
            $logs = array_merge($logs, getJournalLogs('wg-quick@tun0', $lines / 2));
        }
        if ($hasOpenvpn) {
            $logs = array_merge($logs, getJournalLogs('openvpn@tun0', $lines / 2));
        }
        $logs = array_merge($logs, getJournalLogs('vpn-healthcheck', $lines / 4));
        break;
}

// Сортируем по времени если есть
usort($logs, function($a, $b) {
    if (!$a['time'] || !$b['time']) return 0;
    return strcmp($a['time'], $b['time']);
});

echo json_encode([
    'type' => $type,
    'count' => count($logs),
    'logs' => array_slice($logs, -$lines)
], JSON_UNESCAPED_UNICODE);
