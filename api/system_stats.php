<?php
// ==============================================================================
// MINE SERVER - API системных метрик (исправленная версия)
// ==============================================================================

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

/**
 * Получение загрузки CPU (улучшенная версия)
 */
function getCpuUsage() {
    // Метод 1: через /proc/stat (наиболее точный)
    $stat1 = @file_get_contents('/proc/stat');
    if ($stat1 === false) {
        return getTopCpuUsage();
    }
    
    usleep(250000); // 250ms для более точного измерения
    
    $stat2 = @file_get_contents('/proc/stat');
    if ($stat2 === false) {
        return getTopCpuUsage();
    }
    
    // Парсим первую строку (cpu)
    preg_match('/^cpu\s+(.*)$/m', $stat1, $m1);
    preg_match('/^cpu\s+(.*)$/m', $stat2, $m2);
    
    if (empty($m1[1]) || empty($m2[1])) {
        return getTopCpuUsage();
    }
    
    $info1 = array_map('intval', preg_split('/\s+/', trim($m1[1])));
    $info2 = array_map('intval', preg_split('/\s+/', trim($m2[1])));
    
    // Разница: user, nice, system, idle, iowait, irq, softirq
    $diff = [];
    for ($i = 0; $i < min(count($info1), count($info2), 7); $i++) {
        $diff[$i] = $info2[$i] - $info1[$i];
    }
    
    $total = array_sum($diff);
    if ($total == 0) {
        return getTopCpuUsage();
    }
    
    // idle = idle + iowait
    $idle = ($diff[3] ?? 0) + ($diff[4] ?? 0);
    $usage = round(($total - $idle) / $total * 100, 1);
    
    // Проверка на адекватность
    if ($usage < 0 || $usage > 100) {
        return getTopCpuUsage();
    }
    
    return $usage;
}

/**
 * Fallback: через top
 */
function getTopCpuUsage() {
    $output = shell_exec("top -bn1 | grep 'Cpu(s)' | awk '{print \$2}'");
    if ($output !== null) {
        $usage = floatval(trim($output));
        if ($usage >= 0 && $usage <= 100) {
            return round($usage, 1);
        }
    }
    
    // Ещё один fallback через vmstat
    $output = shell_exec("vmstat 1 2 | tail -1 | awk '{print 100 - \$15}'");
    if ($output !== null) {
        $usage = floatval(trim($output));
        if ($usage >= 0 && $usage <= 100) {
            return round($usage, 1);
        }
    }
    
    return 0;
}

/**
 * Получение информации о памяти
 */
function getMemoryInfo() {
    $meminfo = @file_get_contents('/proc/meminfo');
    if ($meminfo === false) {
        return [
            'total' => 0, 'used' => 0, 'available' => 0,
            'percent' => 0, 'swap_total' => 0, 'swap_used' => 0, 'swap_percent' => 0
        ];
    }
    
    $values = [];
    $keys = ['MemTotal', 'MemFree', 'MemAvailable', 'Buffers', 'Cached', 'SwapTotal', 'SwapFree'];
    
    foreach ($keys as $key) {
        if (preg_match("/{$key}:\s+(\d+)/", $meminfo, $m)) {
            $values[$key] = (int)$m[1];
        } else {
            $values[$key] = 0;
        }
    }
    
    $total = $values['MemTotal'];
    $available = $values['MemAvailable'] ?: ($values['MemFree'] + $values['Buffers'] + $values['Cached']);
    $used = $total - $available;
    
    $swapTotal = $values['SwapTotal'];
    $swapUsed = $swapTotal - $values['SwapFree'];
    
    return [
        'total' => round($total / 1024),
        'used' => round($used / 1024),
        'available' => round($available / 1024),
        'percent' => $total > 0 ? round($used / $total * 100, 1) : 0,
        'swap_total' => round($swapTotal / 1024),
        'swap_used' => round($swapUsed / 1024),
        'swap_percent' => $swapTotal > 0 ? round($swapUsed / $swapTotal * 100, 1) : 0
    ];
}

/**
 * Получение информации о дисках
 */
function getDiskInfo() {
    $total = @disk_total_space('/');
    $free = @disk_free_space('/');
    
    if ($total === false || $free === false) {
        return [];
    }
    
    $used = $total - $free;
    
    return [[
        'mount' => '/',
        'total' => round($total / 1024 / 1024 / 1024, 1),
        'used' => round($used / 1024 / 1024 / 1024, 1),
        'free' => round($free / 1024 / 1024 / 1024, 1),
        'percent' => $total > 0 ? round($used / $total * 100, 1) : 0
    ]];
}

/**
 * Получение Uptime
 */
function getUptime() {
    $uptime = @file_get_contents('/proc/uptime');
    if ($uptime === false) {
        return ['seconds' => 0, 'formatted' => 'N/A'];
    }
    
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
    $load = @sys_getloadavg();
    if ($load === false) {
        $load = [0, 0, 0];
    }
    
    $cpuCount = (int)shell_exec('nproc 2>/dev/null') ?: 1;
    
    return [
        'load1' => round($load[0], 2),
        'load5' => round($load[1], 2),
        'load15' => round($load[2], 2),
        'cpu_count' => $cpuCount,
        'load_percent' => round($load[0] / $cpuCount * 100, 1)
    ];
}

/**
 * Получение температуры CPU (расширенная версия)
 */
function getCpuTemperature() {
    // Список возможных путей к температуре
    $paths = [
        // Общие thermal zones
        '/sys/class/thermal/thermal_zone0/temp',
        '/sys/class/thermal/thermal_zone1/temp',
        '/sys/class/thermal/thermal_zone2/temp',
        
        // hwmon (разные устройства)
        '/sys/class/hwmon/hwmon0/temp1_input',
        '/sys/class/hwmon/hwmon1/temp1_input',
        '/sys/class/hwmon/hwmon2/temp1_input',
        '/sys/class/hwmon/hwmon0/temp2_input',
        '/sys/class/hwmon/hwmon1/temp2_input',
        
        // Специфичные для CPU
        '/sys/devices/platform/coretemp.0/hwmon/hwmon*/temp1_input',
        '/sys/devices/virtual/thermal/thermal_zone0/temp',
        
        // Raspberry Pi
        '/sys/class/thermal/thermal_zone0/temp',
        
        // AMD
        '/sys/class/hwmon/hwmon0/temp1_input',
    ];
    
    foreach ($paths as $pattern) {
        // Поддержка glob паттернов
        $files = glob($pattern);
        if (empty($files)) {
            $files = [$pattern];
        }
        
        foreach ($files as $path) {
            if (file_exists($path) && is_readable($path)) {
                $temp = @file_get_contents($path);
                if ($temp !== false) {
                    $tempValue = (int)trim($temp);
                    
                    // Температура в милли-градусах
                    if ($tempValue > 1000) {
                        $tempValue = $tempValue / 1000;
                    }
                    
                    // Проверка на адекватность (0-120°C)
                    if ($tempValue > 0 && $tempValue < 120) {
                        return round($tempValue, 1);
                    }
                }
            }
        }
    }
    
    // Попытка через sensors
    $output = shell_exec('sensors 2>/dev/null | grep -i "core 0\|cpu\|temp1" | head -1');
    if ($output && preg_match('/[+]?([\d.]+)°C/', $output, $m)) {
        return round((float)$m[1], 1);
    }
    
    // Попытка через vcgencmd (Raspberry Pi)
    $output = shell_exec('vcgencmd measure_temp 2>/dev/null');
    if ($output && preg_match('/temp=([\d.]+)/', $output, $m)) {
        return round((float)$m[1], 1);
    }
    
    return null;
}

/**
 * Информация о сетевом трафике
 */
function getNetworkStats() {
    $stats = [];
    $interfaces = ['tun0', 'eth0', 'enp0s3', 'ens33'];
    
    foreach ($interfaces as $iface) {
        $rxPath = "/sys/class/net/$iface/statistics/rx_bytes";
        $txPath = "/sys/class/net/$iface/statistics/tx_bytes";
        
        if (file_exists($rxPath)) {
            $stats[$iface] = [
                'rx' => (int)@file_get_contents($rxPath),
                'tx' => (int)@file_get_contents($txPath)
            ];
        }
    }
    
    return $stats;
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
    'load' => getLoadAverage(),
    'network' => getNetworkStats()
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
