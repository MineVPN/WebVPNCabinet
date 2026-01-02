<?php
// ==============================================================================
// MINE SERVER - API логов VPN
// ==============================================================================

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 50;
$lines = min($lines, 500); // Максимум 500 строк

$type = $_GET['type'] ?? 'system';

/**
 * Получение логов из journalctl
 */
function getJournalLogs($unit, $lines) {
    $unit = escapeshellarg($unit);
    $lines = (int)$lines;
    
    $cmd = "journalctl -u $unit -n $lines --no-pager --output=short-iso 2>&1";
    $output = shell_exec($cmd);
    
    if (empty($output)) {
        return [];
    }
    
    $result = [];
    $logLines = explode("\n", trim($output));
    
    foreach ($logLines as $line) {
        if (empty($line)) continue;
        
        // Парсим строку лога
        // Формат: 2024-01-02T10:30:45+0000 hostname unit[pid]: message
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[^\s]*)\s+\S+\s+(\S+)\[\d+\]:\s*(.*)$/', $line, $matches)) {
            $result[] = [
                'time' => $matches[1],
                'unit' => $matches[2],
                'message' => $matches[3],
                'level' => detectLogLevel($matches[3])
            ];
        } else {
            // Строка не соответствует формату - добавляем как есть
            $result[] = [
                'time' => null,
                'unit' => null,
                'message' => $line,
                'level' => 'info'
            ];
        }
    }
    
    return $result;
}

/**
 * Определение уровня лога по содержимому
 */
function detectLogLevel($message) {
    $message = strtolower($message);
    
    if (strpos($message, 'error') !== false || strpos($message, 'failed') !== false || strpos($message, 'fatal') !== false) {
        return 'error';
    }
    if (strpos($message, 'warning') !== false || strpos($message, 'warn') !== false) {
        return 'warning';
    }
    if (strpos($message, 'started') !== false || strpos($message, 'connected') !== false || strpos($message, 'success') !== false) {
        return 'success';
    }
    
    return 'info';
}

/**
 * Получение логов из файла
 */
function getFileLogs($file, $lines) {
    if (!file_exists($file) || !is_readable($file)) {
        return [];
    }
    
    $file = escapeshellarg($file);
    $lines = (int)$lines;
    
    $cmd = "tail -n $lines $file 2>&1";
    $output = shell_exec($cmd);
    
    if (empty($output)) {
        return [];
    }
    
    $result = [];
    foreach (explode("\n", trim($output)) as $line) {
        if (empty($line)) continue;
        
        $result[] = [
            'time' => null,
            'unit' => null,
            'message' => $line,
            'level' => detectLogLevel($line)
        ];
    }
    
    return $result;
}

// Выбор источника логов
switch ($type) {
    case 'openvpn':
        $logs = getJournalLogs('openvpn@tun0', $lines);
        if (empty($logs)) {
            // Fallback на файл
            $logs = getFileLogs('/var/log/openvpn.log', $lines);
        }
        break;
        
    case 'wireguard':
        $logs = getJournalLogs('wg-quick@tun0', $lines);
        break;
        
    case 'system':
        $logs = [];
        // Собираем логи из разных источников
        $vpnLogs = getJournalLogs('openvpn@tun0', $lines / 2);
        $wgLogs = getJournalLogs('wg-quick@tun0', $lines / 2);
        $logs = array_merge($vpnLogs, $wgLogs);
        // Сортируем по времени
        usort($logs, function($a, $b) {
            return strcmp($a['time'] ?? '', $b['time'] ?? '');
        });
        $logs = array_slice($logs, -$lines);
        break;
        
    case 'healthcheck':
        $logs = getJournalLogs('vpn-healthcheck', $lines);
        break;
        
    case 'dnsmasq':
        $logs = getJournalLogs('dnsmasq', $lines);
        break;
        
    case 'apache':
        $logs = getFileLogs('/var/log/apache2/error.log', $lines);
        break;
        
    case 'auth':
        $logs = getFileLogs('/var/log/auth.log', $lines);
        break;
        
    default:
        $logs = [];
}

echo json_encode([
    'type' => $type,
    'count' => count($logs),
    'logs' => $logs
], JSON_UNESCAPED_UNICODE);
