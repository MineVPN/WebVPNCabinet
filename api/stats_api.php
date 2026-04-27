<?php
/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ══════════════════════════════════════════════════════════════════
 *                  A P I   S T A T S   F I L E
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
 * MineVPN Server — Stats API / Endpoint для всех метрик страницы «Обзор»
 *
 * GET-запрос с параметром ?action=X возвращает разные блоки данных в JSON.
 * Один файл реализует все endpoint-ы для stats.php — раньше были отдельные файлы,
 * объединили ради общего аутентификационного барьера и helper функций.
 *
 * Actions (GET, в одиночном вызове):
 *   • ?action=cpu        — CPU% (из /proc/stat с кэшем для разницы), load avg, cores
 *   • ?action=memory     — RAM total/used/available из /proc/meminfo
 *   • ?action=disk       — disk total/used/free через disk_total_space()
 *   • ?action=network    — общий rx/tx байты по интерфейсам из /sys/class/net/ /statistics
 *   • ?action=bandwidth  — скорость rx/tx за последнюю секунду (с кэшем)
 *   • ?action=uptime     — время работы сервера из /proc/uptime
 *   • ?action=vpn        — статус + имя активного конфига
 *   • ?action=history    — журнал событий из events.log + статистика по конфигам (длительности)
 *
 * Actions (GET, комбинированные — для снижения нагрузки):
 *   • ?action=live       — fast-changing (cpu+memory+disk+network+bandwidth+vpn), polling раз в 2с
 *   • ?action=slow       — slow-changing (uptime+history+last_disconnection), polling раз в 30с,
 *                          поддерживает If-Modified-Since (304 без парсинга логов)
 *                          + pagination для events (?events_offset=0&events_limit=20)
 *   • ?action=all        — всё вместе (legacy, default если action не указан)
 *
 * Actions (POST):
 *   • action=clear_events — очищает events.log через truncate (LOCK_EX), пишет маркер
 *                          events_cleared для fallback current-session calc в getVpnHistory
 *
 * Взаимодействует с:
 *   • assets/js/pages/stats.js — вызывает все actions на странице stats.php («Обзор»)
 *   • includes/vpn_helpers.php — хелперы для работы с configs.json (при ?action=overview/history)
 *
 * Читает:
 *   • /proc/stat            — CPU % (всё + idle, разница с кэшевым снимком)
 *   • /proc/meminfo         — RAM total/available
 *   • /proc/net/dev         — (legacy, сейчас используется /sys/class/net/<if>/statistics/* для rx/tx)
 *   • /var/log/minevpn/events.log — журнал событий от HC daemon
 *   • /var/www/minevpn-state      — текущий статус VPN
 *   • /var/www/vpn-configs/configs.json — имена/роли конфигов для overview
 *
 * Пишет:
 *   • /tmp/minevpn_cpu_cache       — кэш idle/total между вызовами (атомарная запись tmp→rename)
 *   • /tmp/minevpn_bandwidth_cache — кэш rx/tx снапшотов по интерфейсам для вычисления скорости
 *   • /var/log/minevpn/events.log — только при clear_events (запись events_cleared маркера)
 */
session_start();
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
header('Content-Type: application/json; charset=utf-8');

// Функция безопасного выполнения команд
function safe_exec($cmd) {
    $output = [];
    $result = 0;
    exec($cmd . ' 2>/dev/null', $output, $result);
    return implode("\n", $output);
}

// Функция получения CPU (реальный % через /proc/stat)
function getCpuUsage() {
    $cores = (int)safe_exec("nproc");
    if ($cores < 1) $cores = 1;
    $load = sys_getloadavg();
    
    // Читаем /proc/stat для реального CPU%
    $cacheFile = '/tmp/minevpn_cpu_cache';
    $percent = 0;
    
    $statLine = @file_get_contents('/proc/stat');
    if ($statLine) {
        // Первая строка: cpu user nice system idle [iowait irq softirq steal guest guest_nice]
        if (preg_match('/^cpu\s+(.+)/m', $statLine, $rawMatch)) {
            $fields = preg_split('/\s+/', trim($rawMatch[1]));
            // fields[0]=user [1]=nice [2]=system [3]=idle [4]=iowait ...
            $idle = (float)($fields[3] ?? 0) + (float)($fields[4] ?? 0);
            $total = 0;
            foreach ($fields as $f) $total += (float)$f;
            
            $now = microtime(true);
            
            if (file_exists($cacheFile)) {
                $prev = json_decode(@file_get_contents($cacheFile), true);
                if ($prev && isset($prev['idle']) && isset($prev['total'])) {
                    $dIdle = $idle - $prev['idle'];
                    $dTotal = $total - $prev['total'];
                    if ($dTotal > 0) {
                        $percent = round((1 - $dIdle / $dTotal) * 100, 1);
                        if ($percent < 0) $percent = 0;
                        if ($percent > 100) $percent = 100;
                    }
                }
            }
            
            // Атомарная запись — защита от race condition при параллельных запросах
            $tmp = $cacheFile . '.tmp';
            file_put_contents($tmp, json_encode(['idle' => $idle, 'total' => $total, 'time' => $now]));
            rename($tmp, $cacheFile);
        }
    }
    
    return [
        'percent' => $percent,
        'load_1' => round($load[0], 2),
        'load_5' => round($load[1], 2),
        'load_15' => round($load[2], 2),
        'cores' => $cores
    ];
}

// Функция получения RAM
function getMemoryUsage() {
    // Читаем напрямую из /proc/meminfo — надёжнее чем парсинг free(1)
    $meminfo = @file_get_contents('/proc/meminfo');
    if ($meminfo) {
        $vals = [];
        preg_match_all('/^(\w+):\s+(\d+)/m', $meminfo, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) $vals[$m[1]] = (float)$m[2] * 1024; // kB → bytes

        $total     = $vals['MemTotal']     ?? 0;
        $available = $vals['MemAvailable'] ?? ($vals['MemFree'] ?? 0);
        $used      = $total - $available;

        if ($total > 0) {
            return [
                'total'     => $total,
                'used'      => $used,
                'available' => $available,
                'percent'   => round(($used / $total) * 100, 1),
                'total_gb'  => round($total / 1073741824, 2),
                'used_gb'   => round($used  / 1073741824, 2),
            ];
        }
    }
    return ['percent' => 0, 'total_gb' => 0, 'used_gb' => 0, 'total' => 0, 'used' => 0, 'available' => 0];
}

// Функция получения диска
function getDiskUsage() {
    $total = @disk_total_space('/');
    $free  = @disk_free_space('/');

    // Проверяем что значения настоящие (могут вернуть false)
    if ($total === false || $free === false || $total <= 0) {
        return ['percent' => 0, 'total_gb' => 0, 'used_gb' => 0, 'free_gb' => 0,
                'total' => 0, 'used' => 0, 'free' => 0];
    }

    $used = $total - $free;
    return [
        'total'    => $total,
        'used'     => $used,
        'free'     => $free,
        'percent'  => round(($used / $total) * 100, 1),
        'total_gb' => round($total / 1073741824, 2),
        'used_gb'  => round($used  / 1073741824, 2),
        'free_gb'  => round($free  / 1073741824, 2),
    ];
}

// Общая функция определения интерфейсов
function getActiveInterfaces() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $list = [];
    foreach (scandir('/sys/class/net') as $iface) {
        if ($iface === '.' || $iface === '..' || $iface === 'lo') continue;
        if (preg_match('/^(docker|veth|br-)/', $iface)) continue;
        $list[] = $iface;
    }
    $cache = $list;
    return $list;
}

// Функция получения сетевой статистики
function getNetworkStats() {
    $interfaces = [];
    $iflist = getActiveInterfaces();
    
    foreach ($iflist as $iface) {
        $rx_file = "/sys/class/net/$iface/statistics/rx_bytes";
        $tx_file = "/sys/class/net/$iface/statistics/tx_bytes";
        
        if (file_exists($rx_file) && file_exists($tx_file)) {
            $rx = (float)trim(file_get_contents($rx_file));
            $tx = (float)trim(file_get_contents($tx_file));
            
            $interfaces[$iface] = [
                'rx_bytes' => $rx,
                'tx_bytes' => $tx,
                'rx_human' => formatBytes($rx),
                'tx_human' => formatBytes($tx),
                'total_bytes' => $rx + $tx,
                'total_human' => formatBytes($rx + $tx)
            ];
        }
    }
    
    return $interfaces;
}

// Форматирование байтов
function formatBytes($bytes, $precision = 2) {
    $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Время работы сервера
function getUptime() {
    $uptime = (float)trim(file_get_contents('/proc/uptime'));
    $uptime = explode(' ', $uptime)[0];
    
    $days = floor($uptime / 86400);
    $hours = floor(($uptime % 86400) / 3600);
    $minutes = floor(($uptime % 3600) / 60);
    $seconds = floor($uptime % 60);
    
    $parts = [];
    if ($days > 0) $parts[] = $days . 'д';
    if ($hours > 0) $parts[] = $hours . 'ч';
    if ($minutes > 0) $parts[] = $minutes . 'м';
    if (empty($parts)) $parts[] = $seconds . 'с';
    
    return [
        'seconds' => (float)$uptime,
        'human' => implode(' ', $parts),
        'days' => $days,
        'hours' => $hours,
        'minutes' => $minutes
    ];
}

// Получение текущего VPN конфига
function getCurrentVpnConfig() {
    // Проверяем WireGuard
    if (file_exists('/etc/wireguard/tun0.conf')) {
        $content = @file_get_contents('/etc/wireguard/tun0.conf');
        if (preg_match('/^#\s*Name:\s*(.+)$/m', $content, $m)) {
            return ['type' => 'WireGuard', 'name' => trim($m[1])];
        }
        if (preg_match('/Endpoint\s*=\s*([^:]+)/', $content, $m)) {
            return ['type' => 'WireGuard', 'name' => trim($m[1])];
        }
        return ['type' => 'WireGuard', 'name' => 'tun0'];
    }
    
    // Проверяем OpenVPN
    if (file_exists('/etc/openvpn/tun0.conf')) {
        $content = @file_get_contents('/etc/openvpn/tun0.conf');
        if (preg_match('/^#\s*Name:\s*(.+)$/m', $content, $m)) {
            return ['type' => 'OpenVPN', 'name' => trim($m[1])];
        }
        if (preg_match('/remote\s+(\S+)/', $content, $m)) {
            return ['type' => 'OpenVPN', 'name' => trim($m[1])];
        }
        return ['type' => 'OpenVPN', 'name' => 'tun0'];
    }
    
    return ['type' => 'none', 'name' => 'Не настроен'];
}

// Статус VPN интерфейса
function getVpnStatus() {
    $interface = file_exists('/sys/class/net/tun0') ? 'tun0' : null;
    
    if ($interface) {
        $state = trim(safe_exec("cat /sys/class/net/$interface/operstate"));
        $ip = trim(safe_exec("ip -4 addr show $interface | grep -oP '(?<=inet\\s)\\d+(\\.\\d+){3}'"));
        
        // WireGuard показывает operstate=unknown — проверяем реальную связь
        $reallyActive = false;
        if ($state === 'up') {
            $reallyActive = true;
        } elseif ($state === 'unknown') {
            // WG: проверяем last handshake (<3мин)
            $handshake = trim(safe_exec("wg show $interface latest-handshakes 2>/dev/null | awk '{print \$2}'"));
            if (!empty($handshake) && is_numeric($handshake) && $handshake > 0) {
                $reallyActive = (time() - (int)$handshake) < 180;
            } else {
                // НЕ делаем ping (=1с блокировки) — проверяем наличие IP-адреса на интерфейсе (0мс)
                $reallyActive = (bool)trim(@file_get_contents("/sys/class/net/{$interface}/operstate") ?: '');
                // Fallback: если есть IP-адрес — туннель, скорее всего, работает
                $ip = trim(safe_exec("ip -4 addr show $interface 2>/dev/null | grep -oP '(?<=inet\\s)\\d+(\\.\\d+){3}'"));
                $reallyActive = !empty($ip);
            }
        }
        
        return [
            'active' => $reallyActive,
            'interface' => $interface,
            'state' => $state,
            'ip' => $ip ?: 'N/A'
        ];
    }
    
    return ['active' => false, 'interface' => null, 'state' => 'down', 'ip' => 'N/A'];
}

// История VPN событий (читает events.log, рендерит человеческие сообщения)
//
// Формат events.log: TIME|TYPE|F1|F2|F3
//
// Типы событий (см. в vpn-healthcheck.sh и vpn-manager.php):
//   HC daemon:
//     vpn_down|CFG_ID|reason
//     recovery_attempt|CFG_ID|reason
//     recovery_succeeded|CFG_ID|reason       — restart активного помог (закрытие recovery_attempt)
//     recovery_failed|reason
//     failover|NEW_CFG_ID|OLD_CFG_ID|reason
//     failover_restored|CFG_ID               — возврат на основной (try_primary_first)
//     auto_start|CFG_ID                      — демон увидел стабильный VPN при старте (напр. после reboot)
//     isp_down                               — интернет провайдера лёг, VPN не виноват
//     isp_restored|down_seconds              — интернет провайдера восстановлен
//     firewall_restored                      — Kill Switch (iptables) самовосстановился
//   PHP (vpn-manager.php):
//     manual_activate|CFG_ID                 — пользователь нажал "Подключить"
//     rollback|FAILED_ID|ROLLED_TO_ID        — новый конфиг нерабочий, вернулись
//     vpn_stopped|CFG_ID
//     vpn_restarted|CFG_ID
//     config_added|CFG_ID
//     config_deleted|CFG_ID|name|server      — name/server inline (в configs.json уже нет)
//     config_renamed|CFG_ID|old|new
//     role_changed|CFG_ID|new_role           — new_role: backup | none
//   PHP (api/system_action.php):
//     system|reboot|panel                    — пользователь нажал «Перезапустить сервер»
//     system|poweroff|panel                  — пользователь нажал «Выключить сервер»
//   Legacy (до v5 HC):
//     disconnect|reason
//     config_change|CFG_ID|by
function getVpnHistory() {
    $eventsFile  = '/var/log/minevpn/events.log';
    $configsFile = '/var/www/vpn-configs/configs.json';
    $stateFile   = '/var/www/minevpn-state';

    $configs = [];
    if (file_exists($configsFile)) {
        $configs = json_decode(file_get_contents($configsFile), true) ?: [];
    }

    // Резолв cfg_id → "name (server)" или cfg_id как фолбэк
    $cfgLabel = function($cfgId, $nameOverride = null, $serverOverride = null) use ($configs) {
        if (!$cfgId && !$nameOverride) return 'неизвестный конфиг';
        $c = $configs[$cfgId] ?? null;
        $name   = $nameOverride   ?? ($c['name']   ?? $cfgId);
        $server = $serverOverride ?? ($c['server'] ?? '');
        return $server ? "$name ($server)" : $name;
    };

    // Перевод ключей причин падения (из HC) на человеческую русскую
    $translateReason = function($raw) {
        $map = [
            'No connectivity'    => 'нет связи',
            'No IP'              => 'нет IP',
            'Interface lost'     => 'интерфейс пропал',
            'WG fwmark rule lost'=> 'WG маршрут потерян',
            'OVPN routes lost'   => 'маршруты потеряны',
            'iptables rules lost'=> 'сброс iptables',
            'restart'            => 'перезапуск',
            'VPN unreachable after WAN recovery' => 'VPN-сервер недоступен после возвращения интернета',
        ];
        if (isset($map[$raw])) return $map[$raw];
        if (strpos($raw, 'IP leak') === 0) return 'утечка IP';
        return $raw ?: 'неизвестно';
    };

    // Стили для UI-рендера
    $style = function($kind) {
        static $s = [
            'success'    => ['icon' => '✅', 'badge' => 'OK',             'color' => 'green'],
            'activate'   => ['icon' => '⚡', 'badge' => 'Активация',     'color' => 'green'],
            'failover'   => ['icon' => '🔄', 'badge' => 'Резерв',         'color' => 'yellow'],
            'restore'    => ['icon' => '↩️',  'badge' => 'Восстановлен',  'color' => 'green'],
            'rollback'   => ['icon' => '↪️',  'badge' => 'Откат',          'color' => 'orange'],
            'stop'       => ['icon' => '⏹️',  'badge' => 'Остановлен',    'color' => 'slate'],
            'restart'    => ['icon' => '🔄', 'badge' => 'Перезапуск',    'color' => 'blue'],
            'down'       => ['icon' => '⚠️',  'badge' => 'Проблема',      'color' => 'red'],
            'recovery'   => ['icon' => '🔧', 'badge' => 'Восстановление', 'color' => 'yellow'],
            'recovered'  => ['icon' => '✅', 'badge' => 'Восстановлен',  'color' => 'green'],
            'firewall'   => ['icon' => '🛡️',  'badge' => 'Защита',         'color' => 'green'],
            'failed'     => ['icon' => '❌', 'badge' => 'Неудача',        'color' => 'red'],
            'added'      => ['icon' => '➕', 'badge' => 'Добавлен',       'color' => 'blue'],
            'deleted'    => ['icon' => '🗑️', 'badge' => 'Удалён',         'color' => 'slate'],
            'renamed'    => ['icon' => '✏️',  'badge' => 'Переименован',  'color' => 'slate'],
            'role'       => ['icon' => '🔗', 'badge' => 'Резерв',         'color' => 'blue'],
            'isp_down'   => ['icon' => '🌐', 'badge' => 'Интернет',       'color' => 'purple'],
            'isp_ok'     => ['icon' => '🌐', 'badge' => 'Интернет',       'color' => 'green'],
            'sys_reboot' => ['icon' => '🔄', 'badge' => 'Перезагрузка',   'color' => 'blue'],
            'sys_off'    => ['icon' => '⏻',  'badge' => 'Выключение',     'color' => 'slate'],
            'other'      => ['icon' => '•',  'badge' => '',                'color' => 'slate'],
        ];
        return $s[$kind] ?? $s['other'];
    };

    // Парсим events.log
    $rawEvents = [];
    if (file_exists($eventsFile)) {
        $lines = @file($eventsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $parts = explode('|', $line);
            if (count($parts) < 2) continue;
            $rawEvents[] = $parts;  // [time, type, f1, f2, f3, ...]
        }
    }

    $events = [];
    $activations = []; // [time => cfgId] — для обчислення durations
    $lastClearedTime = null; // timestamp останнього events_cleared — для fallback коли журнал чистий

    foreach ($rawEvents as $p) {
        $time = $p[0] ?? '';
        $type = $p[1] ?? '';
        $f1 = $p[2] ?? ''; $f2 = $p[3] ?? ''; $f3 = $p[4] ?? '';

        $kind = 'other'; $text = '';

        switch ($type) {
            // ===== HC daemon типы =====
            case 'auto_start':
                $kind = 'activate';
                $text = "Автоматически запущен " . $cfgLabel($f1);
                $activations[] = ['time' => $time, 'cfg' => $f1];
                break;

            case 'failover':
                // failover|NEW_ID|OLD_ID|reason
                $kind = 'failover';
                $text = "Активирован резерв " . $cfgLabel($f1)
                      . " вместо основного " . $cfgLabel($f2)
                      . ". Причина: " . $translateReason($f3);
                $activations[] = ['time' => $time, 'cfg' => $f1];
                break;

            case 'failover_restored':
                $kind = 'restore';
                $text = "Возвращение на основной " . $cfgLabel($f1);
                $activations[] = ['time' => $time, 'cfg' => $f1];
                break;

            case 'vpn_down':
                $kind = 'down';
                $text = "VPN " . $cfgLabel($f1) . " потерял связь (" . $translateReason($f2) . ")";
                break;

            case 'recovery_attempt':
                $kind = 'recovery';
                $text = "Попытка восстановить " . $cfgLabel($f1) . ". Причина: " . $translateReason($f2);
                break;

            case 'recovery_succeeded':
                // recovery_succeeded|CFG_ID|reason — закрытие vpn_down + recovery_attempt.
                // Пишется в do_recovery после успешного restart активного конфига (Шаг 1).
                // Юзер видит happy path: vpn_down → recovery_attempt → recovery_succeeded.
                $kind = 'recovered';
                $text = "VPN " . $cfgLabel($f1) . " восстановлен после перезапуска";
                break;

            case 'firewall_restored':
                // firewall_restored — без полей, закрытие vpn_down с причиной "iptables правила потеряны".
                // Пишется в check_iptables после успешного восстановления Kill Switch.
                $kind = 'firewall';
                $text = "Защита от утечек восстановлена автоматически";
                break;

            case 'recovery_failed':
                $kind = 'failed';
                $text = "Восстановление не удалось: " . $translateReason($f1);
                break;

            case 'isp_down':
                // isp_down (без полей) — HC обнаружил что WAN ping тоже не проходит
                $kind = 'isp_down';
                $text = "Пропал интернет провайдера — VPN не трогаем, ждём возвращения";
                break;

            case 'isp_restored':
                // isp_restored|down_seconds
                $kind = 'isp_ok';
                $dur = (int)$f1;
                if ($dur > 0) {
                    $durText = $dur < 60 ? "{$dur} сек" : (($dur < 3600) ? round($dur / 60) . " мин" : round($dur / 3600, 1) . " ч");
                    $text = "Интернет провайдера восстановлен (отсутствовал $durText)";
                } else {
                    $text = "Интернет провайдера восстановлен";
                }
                break;

            case 'system':
                // system|TYPE|SOURCE — пишет api/system_action.php при reboot/poweroff из панели.
                // f1 = reboot|poweroff, f2 = panel (будущее: cron и др.).
                if ($f1 === 'reboot') {
                    $kind = 'sys_reboot';
                    $text = ($f2 === 'panel') ? "Сервер перезапущен из панели" : "Сервер перезапущен";
                } elseif ($f1 === 'poweroff') {
                    $kind = 'sys_off';
                    $text = ($f2 === 'panel') ? "Сервер выключен из панели" : "Сервер выключен";
                } else {
                    continue 2;  // неизвестный подтип system — пропускаем
                }
                break;

            // ===== PHP типы =====
            case 'manual_activate':
                $kind = 'activate';
                $text = "Конфиг " . $cfgLabel($f1) . " активирован вручную";
                $activations[] = ['time' => $time, 'cfg' => $f1];
                break;

            case 'rollback':
                // rollback|FAILED_ID|ROLLED_TO_ID
                $kind = 'rollback';
                $text = "Конфиг " . $cfgLabel($f1) . " не заработал — возврат на " . $cfgLabel($f2);
                $activations[] = ['time' => $time, 'cfg' => $f2];
                break;

            case 'vpn_stopped':
                $kind = 'stop';
                $text = "Конфиг " . $cfgLabel($f1) . " остановлен пользователем";
                break;

            case 'vpn_restarted':
                $kind = 'restart';
                $text = "Конфиг " . $cfgLabel($f1) . " перезапущен пользователем";
                break;

            case 'config_added':
                $kind = 'added';
                $text = "Добавлен новый конфиг " . $cfgLabel($f1);
                break;

            case 'config_deleted':
                // config_deleted|CFG_ID|name|server (name/server inline — в configs.json уже нет)
                $kind = 'deleted';
                $text = "Конфиг " . $cfgLabel($f1, $f2, $f3) . " удалён";
                break;

            case 'config_renamed':
                // config_renamed|CFG_ID|old|new
                $kind = 'renamed';
                $text = "Конфиг «" . ($f2 ?: '?') . "» переименован в «" . ($f3 ?: '?') . "»";
                break;

            case 'role_changed':
                // role_changed|CFG_ID|new_role
                $kind = 'role';
                if ($f2 === 'backup') {
                    $text = "Конфиг " . $cfgLabel($f1) . " добавлен в резерв";
                } else {
                    $text = "Конфиг " . $cfgLabel($f1) . " убран из резерва";
                }
                break;

            case 'events_cleared':
                // Маркер очистки журнала — записывается самим stats_api.php когда юзер жмёт "Очистить"
                $kind = 'other';
                $text = 'Журнал событий очищен пользователем';
                // Запоминаем timestamp очистки — використовується як fallback початкова точка для
                // поточної сесії якщо після очистки не було жодної activation але VPN продовжує працювати.
                $t = strtotime($time);
                if ($t !== false) $lastClearedTime = $t;
                break;

            // ===== Legacy =====
            case 'disconnect':
                $kind = 'down';
                // Старый формат: f1 = свободный текст причины
                $text = "VPN потерял связь (" . $translateReason($f1) . ")";
                break;

            case 'config_change':
                // Старый формат: f1=cfg_id, f2=by(manual|failover)
                $kind = ($f2 === 'failover') ? 'failover' : 'activate';
                $prefix = ($f2 === 'failover') ? 'Активирован резерв ' : 'Активирован ';
                $text = $prefix . $cfgLabel($f1);
                $activations[] = ['time' => $time, 'cfg' => $f1];
                break;

            default:
                // Неизвестный тип — пропускаем
                continue 2;
        }

        $st = $style($kind);
        $events[] = [
            'time'        => $time,
            'kind'        => $kind,
            'text'        => $text,
            'icon'        => $st['icon'],
            'badge'       => $st['badge'],
            'badge_color' => $st['color'],
        ];
    }

    // Ограничиваем до последних 200 событий
    $events = array_slice($events, -200);

    // ===== config_stats: durations по конфигам =====
    $stats = [];
    $prevCfg = null; $prevTime = null;
    foreach ($activations as $a) {
        $curTime = strtotime($a['time']);
        if ($curTime === false) continue;

        if ($prevCfg !== null && $prevTime !== null) {
            $duration = $curTime - $prevTime;
            if ($duration > 0 && $duration < 86400) {
                if (!isset($stats[$prevCfg])) $stats[$prevCfg] = ['total_seconds' => 0, 'sessions' => 0];
                $stats[$prevCfg]['total_seconds'] += $duration;
            }
        }
        if (!isset($stats[$a['cfg']])) $stats[$a['cfg']] = ['total_seconds' => 0, 'sessions' => 0];
        $stats[$a['cfg']]['sessions']++;

        $prevCfg = $a['cfg'];
        $prevTime = $curTime;
    }

    // Текущая сессия — если STATE=running
    $state = [];
    if (file_exists($stateFile)) {
        $allowed = ['STATE', 'ACTIVE_ID', 'PRIMARY_ID', 'ACTIVATED_BY'];
        $stateLines = @file($stateFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($stateLines as $line) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m) && in_array($m[1], $allowed, true)) {
                $state[$m[1]] = $m[2];
            }
        }
    }
    $activeId = $state['ACTIVE_ID'] ?? '';
    $vpnState = $state['STATE'] ?? 'stopped';

    // Fallback: если в журнале нет activations для вычисления текущей сессии, но есть маркер events_cleared —
    // значит юзер очистил журнал, но VPN дальше работает. Считаем что активный конфиг был активен на момент очистки
    // (это true в 99% случаев — юзер жмёт "Очистить" когда VPN работает). Добавляем imaginary activation от этой точки —
    // так current session считается от момента очистки. Если юзер потом сделает manual_activate — та activation перебьёт и логика вернётся
    // к обычной.
    if ($prevCfg === null && $activeId && $vpnState === 'running' && $lastClearedTime) {
        $prevCfg  = $activeId;
        $prevTime = $lastClearedTime;
        if (!isset($stats[$activeId])) $stats[$activeId] = ['total_seconds' => 0, 'sessions' => 0];
        $stats[$activeId]['sessions']++;
    }

    if ($activeId && $vpnState === 'running' && $prevCfg === $activeId && $prevTime) {
        $sessionSeconds = time() - $prevTime;
        if ($sessionSeconds > 0 && $sessionSeconds < 86400 * 7) {
            if (!isset($stats[$activeId])) $stats[$activeId] = ['total_seconds' => 0, 'sessions' => 0];
            $stats[$activeId]['total_seconds'] += $sessionSeconds;
            $stats[$activeId]['current_session'] = $sessionSeconds;
        }
    }

    // Резолв ID → имя (пропускаем удалённые конфиги)
    $resolved = [];
    foreach ($stats as $id => $s) {
        if (isset($configs[$id]['name'])) {
            $resolved[$configs[$id]['name']] = $s;
        }
    }

    return [
        'events'       => $events,
        'config_stats' => $resolved,
    ];
}

// Последнее отключение интернета
function getLastDisconnection() {
    $logFile = '/var/log/minevpn/vpn.log';
    $lastDisconnect = null;

    if (!file_exists($logFile)) {
        return ['timestamp' => null, 'ago_human' => 'Нет данных'];
    }

    // Читаем только последние 500 строк с конца файла.
    // file() + array_reverse() может съесть 100+MB памяти если лог большой.
    $fp = @fopen($logFile, 'rb');
    if (!$fp) {
        return ['timestamp' => null, 'ago_human' => 'Нет данных'];
    }

    // Ищем с конца: читаем чанками по 8KB назад
    $chunkSize = 8192;
    fseek($fp, 0, SEEK_END);
    $fileSize = ftell($fp);
    $pos      = $fileSize;
    $buffer   = '';
    $found    = null;

    while ($pos > 0 && $found === null) {
        $readSize = min($chunkSize, $pos);
        $pos -= $readSize;
        fseek($fp, $pos);
        $buffer = fread($fp, $readSize) . $buffer;

        // Ограничиваем буфер (100KB макс)
        if (strlen($buffer) > 102400) $buffer = substr($buffer, -102400);

        $lines = explode("\n", $buffer);
        // Идём с конца массива
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = $lines[$i];
            if (strpos($line, 'WARN') !== false || strpos($line, 'CRIT') !== false) {
                if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
                    $found = $m[1];
                    break;
                }
            }
        }
    }
    fclose($fp);

    if ($found) {
        $timestamp = strtotime($found);
        $diff = time() - $timestamp;
        return [
            'timestamp'   => $found,
            'ago_seconds' => $diff,
            'ago_human'   => formatTimeDiff($diff),
        ];
    }

    return ['timestamp' => null, 'ago_human' => 'Нет данных'];
}

// Форматирование разницы времени
function formatTimeDiff($seconds) {
    if ($seconds < 60) return $seconds . ' сек назад';
    if ($seconds < 3600) return floor($seconds / 60) . ' мин назад';
    if ($seconds < 86400) return floor($seconds / 3600) . ' ч назад';
    return floor($seconds / 86400) . ' дн назад';
}

// Текущее время сервера
function getServerTime() {
    return [
        'timestamp' => time(),
        'datetime' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get()
    ];
}

// Пропускная способность (скорость за последнюю секунду)
function getBandwidth() {
    $cacheFile = '/tmp/minevpn_bandwidth_cache';
    $now = microtime(true);
    
    $currentStats = [];
    $interfaces = getActiveInterfaces();
    
    foreach ($interfaces as $iface) {
        $rx_file = "/sys/class/net/$iface/statistics/rx_bytes";
        $tx_file = "/sys/class/net/$iface/statistics/tx_bytes";
        
        if (file_exists($rx_file)) {
            $currentStats[$iface] = [
                'rx' => (float)trim(file_get_contents($rx_file)),
                'tx' => (float)trim(file_get_contents($tx_file)),
                'time' => $now
            ];
        }
    }
    
    $bandwidth = [];
    
    if (file_exists($cacheFile)) {
        $prevData = json_decode(file_get_contents($cacheFile), true);
        
        if ($prevData && isset($prevData['stats'])) {
            foreach ($currentStats as $iface => $current) {
                if (isset($prevData['stats'][$iface])) {
                    $prev = $prevData['stats'][$iface];
                    $timeDiff = $current['time'] - $prev['time'];
                    
                    if ($timeDiff > 0) {
                        $rxSpeed = ($current['rx'] - $prev['rx']) / $timeDiff;
                        $txSpeed = ($current['tx'] - $prev['tx']) / $timeDiff;
                        
                        if ($rxSpeed < 0) $rxSpeed = 0;
                        if ($txSpeed < 0) $txSpeed = 0;
                        
                        $bandwidth[$iface] = [
                            'rx_speed' => $rxSpeed,
                            'tx_speed' => $txSpeed,
                            'rx_speed_human' => formatBytes($rxSpeed) . '/с',
                            'tx_speed_human' => formatBytes($txSpeed) . '/с'
                        ];
                    }
                }
            }
        }
    }
    
    // Атомарная запись
    $tmp = $cacheFile . '.tmp';
    file_put_contents($tmp, json_encode(['stats' => $currentStats, 'time' => $now]));
    rename($tmp, $cacheFile);

    return $bandwidth;
}

// Собираем все данные
$action = $_GET['action'] ?? 'all';

// ==================================================================
// Split endpoints (для stats.php v6+ — снижение нагрузки):
//   action=live — fast-changing (CPU/RAM/disk/network/bandwidth/vpn),
//                  polling раз в 2с. Не читает никаких лог-файлов.
//   action=slow — slow-changing (uptime/history/last_disconnection),
//                  polling раз в 30с. Читает events.log + vpn.log.
//                  Поддерживает If-Modified-Since — если log-файлы не
//                  изменились, возвращает 304 без парсинга.
//   action=all  — совместимость с v5 (legacy).
// ==================================================================

switch ($action) {
    // ── POST: clear_events — очистка events.log (пользователь нажал "Очистить") ──────
    case 'clear_events':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
            break;
        }
        $eventsFile = '/var/log/minevpn/events.log';
        // Очищаем truncate'ом в 0 байт — не удаляем файл, чтобы HC демон дальше писал в него
        // (у HC file_put_contents(...,FILE_APPEND) всё равно создаст, но permissions потеряются).
        if (!file_exists($eventsFile)) {
            // Нечего очищать — а возвращаем успех (UI-результат тот же)
            echo json_encode(['ok' => true, 'message' => 'Журнал событий уже пуст']);
            break;
        }
        // Атомарно: file_put_contents с LOCK_EX для coordinated записи с HC
        $truncated = @file_put_contents($eventsFile, '', LOCK_EX);
        if ($truncated === false) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Не удалось очистить журнал (права доступа?)']);
            break;
        }
        // Регистрируем факт очистки как единственное событие — чтобы журнал не выглядел
        // разрывом без причины. log_event из vpn_helpers тут недоступен — пишем напрямую.
        $line = date('Y-m-d H:i:s') . '|events_cleared' . "\n";
        @file_put_contents($eventsFile, $line, FILE_APPEND | LOCK_EX);
        echo json_encode(['ok' => true, 'message' => 'Журнал событий очищен']);
        break;

    case 'cpu':
        echo json_encode(getCpuUsage());
        break;
    case 'memory':
        echo json_encode(getMemoryUsage());
        break;
    case 'disk':
        echo json_encode(getDiskUsage());
        break;
    case 'network':
        echo json_encode(getNetworkStats());
        break;
    case 'bandwidth':
        echo json_encode(getBandwidth());
        break;
    case 'uptime':
        echo json_encode(getUptime());
        break;
    case 'vpn':
        echo json_encode([
            'status' => getVpnStatus(),
            'config' => getCurrentVpnConfig()
        ]);
        break;
    case 'history':
        echo json_encode(getVpnHistory());
        break;

    // ── Live polling (2с) ───────────────────────────────────────────────
    case 'live':
        echo json_encode([
            'cpu'         => getCpuUsage(),
            'memory'      => getMemoryUsage(),
            'disk'        => getDiskUsage(),
            'network'     => getNetworkStats(),
            'bandwidth'   => getBandwidth(),
            'server_time' => getServerTime(),
            'vpn'         => [
                'status' => getVpnStatus(),
                'config' => getCurrentVpnConfig(),
            ],
        ]);
        break;

    // ── Slow polling (30с, с If-Modified-Since) ─────────────────────────────────────────
    case 'slow':
        // Определяем Last-Modified как max(mtime) лог-файлов.
        // Если клиент прислал If-Modified-Since >= эта дата — 304 назад.
        $mtimes = array_filter([
            @filemtime('/var/log/minevpn/events.log'),
            @filemtime('/var/log/minevpn/vpn.log'),
        ]);
        $lastMtime = $mtimes ? max($mtimes) : time();

        $ifMod = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
        if ($ifMod) {
            $ifModTs = strtotime($ifMod);
            // Если у клиента свежая версия — 304 без парсинга логов
            if ($ifModTs !== false && $ifModTs >= $lastMtime) {
                http_response_code(304);
                exit;
            }
        }

        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastMtime) . ' GMT');
        header('Cache-Control: private, must-revalidate');

        // Pagination для events: ?events_offset=0&events_limit=20 (0=новейшие)
        $history = getVpnHistory();
        $eventsAll = $history['events'] ?? [];
        $totalEvents = count($eventsAll);

        $offset = isset($_GET['events_offset']) ? max(0, (int)$_GET['events_offset']) : 0;
        $limit  = isset($_GET['events_limit'])  ? max(1, min(100, (int)$_GET['events_limit'])) : 20;

        // events в getVpnHistory() в хронологическом порядке (старые первые).
        // Для UI — реверсируем (новые первые), и берём окно offset..offset+limit.
        $eventsReversed = array_reverse($eventsAll);
        $eventsSlice    = array_slice($eventsReversed, $offset, $limit);

        echo json_encode([
            'uptime'             => getUptime(),
            'last_disconnection' => getLastDisconnection(),
            'events'             => $eventsSlice,
            'events_total'       => $totalEvents,
            'events_offset'      => $offset,
            'events_limit'       => $limit,
            'events_has_more'    => ($offset + $limit) < $totalEvents,
            'config_stats'       => $history['config_stats'] ?? [],
        ]);
        break;

    case 'all':
    default:
        echo json_encode([
            'cpu' => getCpuUsage(),
            'memory' => getMemoryUsage(),
            'disk' => getDiskUsage(),
            'network' => getNetworkStats(),
            'bandwidth' => getBandwidth(),
            'uptime' => getUptime(),
            'server_time' => getServerTime(),
            'vpn' => [
                'status' => getVpnStatus(),
                'config' => getCurrentVpnConfig()
            ],
            'last_disconnection' => getLastDisconnection(),
            'history' => getVpnHistory()
        ]);
        break;
}
