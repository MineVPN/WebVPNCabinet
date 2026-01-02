<?php
// ==============================================================================
// MINE SERVER - API системных метрик
// ==============================================================================

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized']));
}

// CPU usage через /proc/stat
function getCpuUsage() {
    $stat1 = @file_get_contents('/proc/stat');
    usleep(200000); // 200ms
    $stat2 = @file_get_contents('/proc/stat');
    
    if (!$stat1 || !$stat2) return 0;
    
    preg_match('/^cpu\s+(.*)$/m', $stat1, $m1);
    preg_match('/^cpu\s+(.*)$/m', $stat2, $m2);
    
    if (empty($m1[1]) || empty($m2[1])) return 0;
    
    $info1 = array_map('intval', preg_split('/\s+/', trim($m1[1])));
    $info2 = array_map('intval', preg_split('/\s+/', trim($m2[1])));
    
    $diff = [];
    for ($i = 0; $i < min(7, count($info1), count($info2)); $i++) {
        $diff[$i] = $info2[$i] - $info1[$i];
    }
    
    $total = array_sum($diff);
    if ($total == 0) return 0;
    
    $idle = ($diff[3] ?? 0) + ($diff[4] ?? 0);
    return round(($total - $idle) / $total * 100, 1);
}

// CPU temperature
function getCpuTemp() {
    $paths = [
        '/sys/class/thermal/thermal_zone0/temp',
        '/sys/class/hwmon/hwmon0/temp1_input',
        '/sys/class/hwmon/hwmon1/temp1_input'
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            $temp = (int)@file_get_contents($path);
            if ($temp > 1000) $temp = $temp / 1000;
            if ($temp > 0 && $temp < 120) return round($temp, 1);
        }
    }
    
    // vcgencmd для Raspberry Pi
    $output = shell_exec('vcgencmd measure_temp 2>/dev/null');
    if ($output && preg_match('/temp=([\d.]+)/', $output, $m)) {
        return round((float)$m[1], 1);
    }
    
    return null;
}

// Memory
function getMemory() {
    $meminfo = @file_get_contents('/proc/meminfo');
    if (!$meminfo) return ['total' => 0, 'used' => 0, 'percent' => 0];
    
    $values = [];
    foreach (['MemTotal', 'MemFree', 'MemAvailable', 'Buffers', 'Cached'] as $key) {
        if (preg_match("/{$key}:\s+(\d+)/", $meminfo, $m)) {
            $values[$key] = (int)$m[1];
        }
    }
    
    $total = $values['MemTotal'] ?? 0;
    $available = $values['MemAvailable'] ?? ($values['MemFree'] + $values['Buffers'] + $values['Cached']);
    $used = $total - $available;
    
    return [
        'total' => round($total / 1024),
        'used' => round($used / 1024),
        'available' => round($available / 1024),
        'percent' => $total > 0 ? round($used / $total * 100, 1) : 0
    ];
}

// Disk
function getDisk() {
    $total = @disk_total_space('/');
    $free = @disk_free_space('/');
    
    if (!$total) return [];
    
    $used = $total - $free;
    return [[
        'mount' => '/',
        'total' => round($total / 1024 / 1024 / 1024, 1),
        'used' => round($used / 1024 / 1024 / 1024, 1),
        'free' => round($free / 1024 / 1024 / 1024, 1),
        'percent' => round($used / $total * 100, 1)
    ]];
}

// Uptime
function getUptime() {
    $uptime = @file_get_contents('/proc/uptime');
    if (!$uptime) return ['seconds' => 0, 'formatted' => '--'];
    
    $seconds = (int)explode(' ', $uptime)[0];
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    $parts = [];
    if ($days > 0) $parts[] = "{$days}д";
    if ($hours > 0) $parts[] = "{$hours}ч";
    $parts[] = "{$minutes}м";
    
    return ['seconds' => $seconds, 'formatted' => implode(' ', $parts)];
}

// Load
function getLoad() {
    $load = sys_getloadavg() ?: [0, 0, 0];
    $cpus = (int)shell_exec('nproc 2>/dev/null') ?: 1;
    
    return [
        'load1' => round($load[0], 2),
        'load5' => round($load[1], 2),
        'load15' => round($load[2], 2),
        'cpu_count' => $cpus
    ];
}

echo json_encode([
    'timestamp' => time(),
    'cpu' => [
        'usage' => getCpuUsage(),
        'temperature' => getCpuTemp()
    ],
    'memory' => getMemory(),
    'disk' => getDisk(),
    'uptime' => getUptime(),
    'load' => getLoad()
], JSON_UNESCAPED_UNICODE);
