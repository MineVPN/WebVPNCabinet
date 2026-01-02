<?php
// ==============================================================================
// MINE SERVER - API системных метрик
// ==============================================================================

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

/**
 * Получение загрузки CPU
 */
function getCpuUsage() {
    // Метод 1: через /proc/stat
    $stat1 = file_get_contents('/proc/stat');
    usleep(100000); // 100ms
    $stat2 = file_get_contents('/proc/stat');
    
    $info1 = explode(' ', preg_replace('!cpu +!', '', explode("\n", $stat1)[0]));
    $info2 = explode(' ', preg_replace('!cpu +!', '', explode("\n", $stat2)[0]));
    
    $diff = array();
    $diff['user'] = $info2[0] - $info1[0];
    $diff['nice'] = $info2[1] - $info1[1];
    $diff['system'] = $info2[2] - $info1[2];
    $diff['idle'] = $info2[3] - $info1[3];
    $diff['iowait'] = $info2[4] - $info1[4];
    
    $total = array_sum($diff);
    
    if ($total == 0) {
        return 0;
    }
    
    $idle = $diff['idle'] + $diff['iowait'];
    $usage = round(($total - $idle) / $total * 100, 1);
    
    return $usage;
}

/**
 * Получение информации о памяти
 */
function getMemoryInfo() {
    $meminfo = file_get_contents('/proc/meminfo');
    
    preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
    preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available);
    preg_match('/Buffers:\s+(\d+)/', $meminfo, $buffers);
    preg_match('/Cached:\s+(\d+)/', $meminfo, $cached);
    preg_match('/SwapTotal:\s+(\d+)/', $meminfo, $swapTotal);
    preg_match('/SwapFree:\s+(\d+)/', $meminfo, $swapFree);
    
    $totalKb = isset($total[1]) ? (int)$total[1] : 0;
    $availableKb = isset($available[1]) ? (int)$available[1] : 0;
    $buffersKb = isset($buffers[1]) ? (int)$buffers[1] : 0;
    $cachedKb = isset($cached[1]) ? (int)$cached[1] : 0;
    $swapTotalKb = isset($swapTotal[1]) ? (int)$swapTotal[1] : 0;
    $swapFreeKb = isset($swapFree[1]) ? (int)$swapFree[1] : 0;
    
    $usedKb = $totalKb - $availableKb;
    $swapUsedKb = $swapTotalKb - $swapFreeKb;
    
    return [
        'total' => round($totalKb / 1024, 0),      // МБ
        'used' => round($usedKb / 1024, 0),        // МБ
        'available' => round($availableKb / 1024, 0), // МБ
        'buffers' => round($buffersKb / 1024, 0),  // МБ
        'cached' => round($cachedKb / 1024, 0),    // МБ
        'percent' => $totalKb > 0 ? round($usedKb / $totalKb * 100, 1) : 0,
        'swap_total' => round($swapTotalKb / 1024, 0),
        'swap_used' => round($swapUsedKb / 1024, 0),
        'swap_percent' => $swapTotalKb > 0 ? round($swapUsedKb / $swapTotalKb * 100, 1) : 0
    ];
}

/**
 * Получение информации о дисках
 */
function getDiskInfo() {
    $disks = [];
    
    // Основной диск
    $total = disk_total_space('/');
    $free = disk_free_space('/');
    $used = $total - $free;
    
    $disks[] = [
        'mount' => '/',
        'total' => round($total / 1024 / 1024 / 1024, 1),  // ГБ
        'used' => round($used / 1024 / 1024 / 1024, 1),    // ГБ
        'free' => round($free / 1024 / 1024 / 1024, 1),    // ГБ
        'percent' => $total > 0 ? round($used / $total * 100, 1) : 0
    ];
    
    return $disks;
}

/**
 * Получение Uptime
 */
function getUptime() {
    $uptime = file_get_contents('/proc/uptime');
    $seconds = (int)explode(' ', $uptime)[0];
    
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    $parts = [];
    if ($days > 0) $parts[] = "{$days}д";
    if ($hours > 0) $parts[] = "{$hours}ч";
    $parts[] = "{$minutes}м";
    
    return [
        'seconds' => $seconds,
        'formatted' => implode(' ', $parts)
    ];
}

/**
 * Получение Load Average
 */
function getLoadAverage() {
    $load = sys_getloadavg();
    
    // Количество CPU для нормализации
    $cpuCount = (int)shell_exec('nproc');
    if ($cpuCount < 1) $cpuCount = 1;
    
    return [
        'load1' => round($load[0], 2),
        'load5' => round($load[1], 2),
        'load15' => round($load[2], 2),
        'cpu_count' => $cpuCount,
        'load_percent' => round($load[0] / $cpuCount * 100, 1)
    ];
}

/**
 * Получение температуры CPU (если доступно)
 */
function getCpuTemperature() {
    $temp = null;
    
    // Попытка 1: thermal zone
    $thermal_file = '/sys/class/thermal/thermal_zone0/temp';
    if (file_exists($thermal_file)) {
        $temp = (int)file_get_contents($thermal_file) / 1000;
    }
    
    // Попытка 2: hwmon
    if ($temp === null) {
        $hwmon_path = '/sys/class/hwmon/hwmon0/temp1_input';
        if (file_exists($hwmon_path)) {
            $temp = (int)file_get_contents($hwmon_path) / 1000;
        }
    }
    
    return $temp !== null ? round($temp, 1) : null;
}

// Собираем все данные
$response = [
    'timestamp' => time(),
    'cpu' => [
        'usage' => getCpuUsage(),
        'temperature' => getCpuTemperature()
    ],
    'memory' => getMemoryInfo(),
    'disk' => getDiskInfo(),
    'uptime' => getUptime(),
    'load' => getLoadAverage()
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
