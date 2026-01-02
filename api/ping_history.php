<?php
// ==============================================================================
// MINE SERVER - API истории пинга
// ==============================================================================

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$history_file = '/var/www/data/ping_history.json';
$max_entries = 300; // Хранить последние 300 записей (~5 минут при интервале 1 сек)

// Создаём директорию если нет
$data_dir = dirname($history_file);
if (!is_dir($data_dir)) {
    @mkdir($data_dir, 0755, true);
}

/**
 * Получение истории
 */
function getHistory($file) {
    if (!file_exists($file)) {
        return [];
    }
    
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    
    return is_array($data) ? $data : [];
}

/**
 * Сохранение истории
 */
function saveHistory($file, $data, $maxEntries) {
    // Ограничиваем количество записей
    if (count($data) > $maxEntries) {
        $data = array_slice($data, -$maxEntries);
    }
    
    file_put_contents($file, json_encode($data), LOCK_EX);
}

/**
 * Выполнение пинга
 */
function doPing($host, $interface = null) {
    $host = escapeshellarg($host);
    
    $cmd = "ping -c 1 -W 2";
    if ($interface) {
        $cmd .= " -I " . escapeshellarg($interface);
    }
    $cmd .= " $host 2>&1";
    
    $start = microtime(true);
    $output = shell_exec($cmd);
    $elapsed = (microtime(true) - $start) * 1000;
    
    // Парсим результат
    if (preg_match('/time[=<](\d+\.?\d*)/', $output, $matches)) {
        return [
            'success' => true,
            'time' => round((float)$matches[1], 1),
            'timestamp' => time()
        ];
    }
    
    return [
        'success' => false,
        'time' => null,
        'timestamp' => time()
    ];
}

// Обработка запросов
$action = $_GET['action'] ?? 'get';

switch ($action) {
    case 'ping':
        // Выполнить пинг и добавить в историю
        $host = $_GET['host'] ?? '8.8.8.8';
        $interface = $_GET['interface'] ?? 'tun0';
        
        $result = doPing($host, $interface);
        
        // Добавляем в историю
        $history = getHistory($history_file);
        $history[] = [
            'time' => $result['time'],
            'success' => $result['success'],
            'ts' => $result['timestamp']
        ];
        saveHistory($history_file, $history, $max_entries);
        
        echo json_encode($result);
        break;
        
    case 'history':
        // Получить историю
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 60;
        $history = getHistory($history_file);
        
        // Возвращаем последние N записей
        $history = array_slice($history, -$limit);
        
        // Вычисляем статистику
        $successful = array_filter($history, fn($h) => $h['success']);
        $times = array_column($successful, 'time');
        
        $stats = [
            'total' => count($history),
            'success' => count($successful),
            'loss_percent' => count($history) > 0 
                ? round((count($history) - count($successful)) / count($history) * 100, 1) 
                : 0,
            'min' => count($times) > 0 ? round(min($times), 1) : null,
            'max' => count($times) > 0 ? round(max($times), 1) : null,
            'avg' => count($times) > 0 ? round(array_sum($times) / count($times), 1) : null
        ];
        
        echo json_encode([
            'history' => $history,
            'stats' => $stats
        ]);
        break;
        
    case 'clear':
        // Очистить историю
        if (file_exists($history_file)) {
            unlink($history_file);
        }
        echo json_encode(['success' => true]);
        break;
        
    default:
        // По умолчанию - получить последний пинг
        $history = getHistory($history_file);
        $last = end($history);
        echo json_encode($last ?: ['time' => null, 'success' => false]);
}
