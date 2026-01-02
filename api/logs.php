<?php
// ==============================================================================
// MINE SERVER - API логов VPN (исправленная версия)
// ==============================================================================

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;
$lines = min($lines, 1000);
$type = $_GET['type'] ?? 'system';

/**
 * Получение логов через sudo journalctl
 */
function getJournalLogs($unit, $lines) {
    $unit = escapeshellarg($unit);
    $lines = (int)$lines;
    
    // ВАЖНО: используем sudo для доступа к journalctl
    $cmd = "sudo journalctl -u $unit -n $lines --no-pager -o short-iso 2>&1";
    $output = shell_exec($cmd);
    
    // Fallback без sudo если не работает
    if (empty($output) || strpos($output, 'No journal files') !== false) {
        $cmd = "journalctl -u $unit -n $lines --no-pager -o short-iso 2>&1";
        $output = shell_exec($cmd);
    }
    
    return parseJournalOutput($output);
}

/**
 * Получение всех системных логов
 */
function getAllSystemLogs($lines) {
    $lines = (int)$lines;
    
    // Логи VPN сервисов
    $cmd = "sudo journalctl -u 'openvpn@*' -u 'wg-quick@*' -u vpn-healthcheck -n $lines --no-pager -o short-iso 2>&1";
    $output = shell_exec($cmd);
    
    if (empty($output) || strpos($output, 'No journal files') !== false) {
        $cmd = "journalctl -u 'openvpn@*' -u 'wg-quick@*' -u vpn-healthcheck -n $lines --no-pager -o short-iso 2>&1";
        $output = shell_exec($cmd);
    }
    
    return parseJournalOutput($output);
}

/**
 * Парсинг вывода journalctl
 */
function parseJournalOutput($output) {
    if (empty($output)) {
        return [];
    }
    
    // Фильтруем служебные сообщения
    if (strpos($output, 'No journal files') !== false || 
        strpos($output, 'insufficient permissions') !== false ||
        strpos($output, 'Hint:') !== false) {
        return [];
    }
    
    $result = [];
    $lines = explode("\n", trim($output));
    
    foreach ($lines as $line) {
        if (empty($line)) continue;
        
        // Пропускаем hint сообщения
        if (strpos($line, 'Hint:') !== false || 
            strpos($line, 'Users in groups') !== false ||
            strpos($line, 'Pass -q') !== false ||
            strpos($line, 'No journal files') !== false) {
            continue;
        }
        
        // Парсим строку: 2024-01-02T10:30:45+0000 hostname unit[pid]: message
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T[\d:]+[^\s]*)\s+(\S+)\s+(\S+?)(?:\[\d+\])?:\s*(.*)$/', $line, $matches)) {
            $result[] = [
                'time' => $matches[1],
                'host' => $matches[2],
                'unit' => $matches[3],
                'message' => $matches[4],
                'level' => detectLogLevel($matches[4])
            ];
        } else {
            // Строка не соответствует формату
            $result[] = [
                'time' => null,
                'host' => null,
                'unit' => null,
                'message' => $line,
                'level' => detectLogLevel($line)
            ];
        }
    }
    
    return $result;
}

/**
 * Определение уровня лога
 */
function detectLogLevel($message) {
    $msg = strtolower($message);
    
    if (preg_match('/error|fail|fatal|crash|exception/i', $msg)) {
        return 'error';
    }
    if (preg_match('/warn|warning/i', $msg)) {
        return 'warning';
    }
    if (preg_match('/started|connected|success|established|up\b|running/i', $msg)) {
        return 'success';
    }
    
    return 'info';
}

/**
 * Получение логов из файла
 */
function getFileLogs($file, $lines) {
    if (!file_exists($file)) {
        return [];
    }
    
    // Пробуем читать напрямую
    if (is_readable($file)) {
        $cmd = "tail -n " . (int)$lines . " " . escapeshellarg($file) . " 2>&1";
    } else {
        // Через sudo
        $cmd = "sudo tail -n " . (int)$lines . " " . escapeshellarg($file) . " 2>&1";
    }
    
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

/**
 * Получение логов healthcheck
 */
function getHealthcheckLogs($lines) {
    $logs = [];
    
    // Из journalctl
    $journalLogs = getJournalLogs('vpn-healthcheck', $lines);
    if (!empty($journalLogs)) {
        $logs = array_merge($logs, $journalLogs);
    }
    
    // Из файла лога
    $fileLogs = getFileLogs('/var/log/vpn-healthcheck.log', $lines);
    if (!empty($fileLogs)) {
        $logs = array_merge($logs, $fileLogs);
    }
    
    // Сортируем и ограничиваем
    return array_slice($logs, -$lines);
}

// Выбор источника логов
switch ($type) {
    case 'openvpn':
        $logs = getJournalLogs('openvpn@tun0', $lines);
        if (empty($logs)) {
            $logs = getFileLogs('/var/log/openvpn.log', $lines);
        }
        if (empty($logs)) {
            $logs = getFileLogs('/var/log/openvpn/openvpn.log', $lines);
        }
        break;
        
    case 'wireguard':
        $logs = getJournalLogs('wg-quick@tun0', $lines);
        break;
        
    case 'system':
        $logs = getAllSystemLogs($lines);
        break;
        
    case 'healthcheck':
        $logs = getHealthcheckLogs($lines);
        break;
        
    case 'dnsmasq':
        $logs = getJournalLogs('dnsmasq', $lines);
        if (empty($logs)) {
            $logs = getFileLogs('/var/log/dnsmasq.log', $lines);
        }
        break;
        
    case 'apache':
        $logs = getFileLogs('/var/log/apache2/error.log', $lines);
        break;
        
    case 'auth':
        $logs = getFileLogs('/var/log/auth.log', $lines);
        break;
        
    case 'syslog':
        $logs = getFileLogs('/var/log/syslog', $lines);
        break;
        
    default:
        $logs = [];
}

// Если логи пустые, возвращаем информативное сообщение
if (empty($logs)) {
    $logs = [[
        'time' => date('c'),
        'unit' => 'system',
        'message' => "Логи для '$type' пусты или сервис не запущен",
        'level' => 'info'
    ]];
}

echo json_encode([
    'type' => $type,
    'count' => count($logs),
    'logs' => $logs
], JSON_UNESCAPED_UNICODE);
