<?php
/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ══════════════════════════════════════════════════════════════════
 *                V P N   H E L P E R S   F I L E
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
 * MineVPN Server — VPN Helpers / Shared library для VPN-операций
 *
 * Общие функции для всех файлов, которым нужны VPN примитивы. Не содержит HTTP логики —
 * только чистые helpers: чтение/запись метаданных, state machine, парсинг конфигов,
 * валидация, логирование событий, управление systemd-сервисами.
 *
 * Константы (defined-once, переопределяемые в caller при необходимости):
 *   • MINEVPN_CONFIG_PATH       — /var/www/vpn-configs (директория с *.conf)
 *   • MINEVPN_METADATA_FILE     — ./configs.json (metadata: id, name, role, priority, ...)
 *   • MINEVPN_STATE_FILE        — /var/www/minevpn-state (key=value, runtime)
 *   • MINEVPN_ACTIVE_OVPN       — /etc/openvpn/tun0.conf (активный OpenVPN конфиг)
 *   • MINEVPN_ACTIVE_WG         — /etc/wireguard/tun0.conf (активный WireGuard конфиг)
 *   • MINEVPN_EVENTS_FILE       — /var/log/minevpn/events.log
 *   • MINEVPN_MAX_CONFIGS       — 30 (лимит в UI при загрузке)
 *   • MINEVPN_MAX_CONFIG_NAME   — 64 (лимит длины имени конфига)
 *
 * Группы функций (префикс mv_):
 *   • METADATA       — mv_loadConfigs, mv_saveConfigs, mv_dedupConfigs (flock SH/EX, atomic write)
 *   • STATE MACHINE  — mv_readState, mv_saveState (whitelist-парсинг, без eval/extract)
 *   • VALIDATION     — mv_isValidConfigId (vpn_[hex16]), mv_safeSubstr (UTF-8 fallback)
 *   • CONFIG FILES   — mv_detectConfigType, mv_extractConfigInfo (server/port/proto), mv_generateConfigId
 *   • ACTIVE VPN     — mv_getActiveConfig (через systemctl), mv_checkVPNStatus (tun0 UP)
 *   • EVENTS LOG     — mv_logEvent (формат TIME|TYPE|F1|F2|F3, ротация 256KB → 500 строк)
 *   • SERVICES       — mv_stopAllServices, mv_disableAllServices, mv_cleanActiveConfigFiles,
 *                       mv_activateServiceFromFile (copy + systemctl), mv_pollVpnUp (ждёт tun0 UP)
 *
 * Безопасность:
 *   • mv_isValidConfigId — защита от path traversal (только vpn_[a-f0-9]{16})
 *   • escapeshellarg() в mv_cleanActiveConfigFiles
 *   • atomic write tmp+rename в mv_saveConfigs (нет частичных JSON при краше)
 *   • flock LOCK_SH/LOCK_EX через .lock-файл (не по оригиналу) — избегаем lock-on-truncate кверка
 *
 * Кто использует (require_once):
 *   • api/vpn_action.php          — AJAX endpoint для всех действий над конфигами
 *   • pages/vpn-manager.handler.php — form-POST handler (PRG pattern)
 *   • pages/vpn-manager.php       — для вывода списка конфигов и статуса
 *   • api/stats_api.php           — для overview/history (resolve config_id → name)
 *
 * Что читает:
 *   • /var/www/vpn-configs/configs.json + .lock
 *   • /var/www/vpn-configs/*.conf — в mv_detectConfigType / mv_extractConfigInfo
 *   • /var/www/minevpn-state
 *   • /etc/wireguard/tun0.conf, /etc/openvpn/tun0.conf — в mv_getActiveConfig
 *   • systemctl is-active wg-quick@tun0, openvpn@tun0
 *
 * Что пишет:
 *   • /var/www/vpn-configs/configs.json (atomic, flock)
 *   • /var/www/minevpn-state
 *   • /etc/wireguard/tun0.conf, /etc/openvpn/tun0.conf — при mv_activateServiceFromFile
 *   • /var/log/minevpn/events.log (append, LOCK_EX, ротация)
 *
 * Что вызывает:
 *   • sudo systemctl start/stop/enable/disable wg-quick@tun0, openvpn@tun0
 *   • ip link show tun0 (для mv_checkVPNStatus)
 *   • random_bytes() — крипто-стойкий ID в mv_generateConfigId
 *
 * НЕ вызывать этот файл напрямую через HTTP — includes/.htaccess блокирует доступ через Require all denied.
 */

// Константы (можно переопределить в вызывающем файле)
if (!defined('MINEVPN_CONFIG_PATH'))     define('MINEVPN_CONFIG_PATH', '/var/www/vpn-configs');
if (!defined('MINEVPN_METADATA_FILE'))   define('MINEVPN_METADATA_FILE', MINEVPN_CONFIG_PATH . '/configs.json');
if (!defined('MINEVPN_STATE_FILE'))      define('MINEVPN_STATE_FILE', '/var/www/minevpn-state');
if (!defined('MINEVPN_ACTIVE_OVPN'))     define('MINEVPN_ACTIVE_OVPN', '/etc/openvpn/tun0.conf');
if (!defined('MINEVPN_ACTIVE_WG'))       define('MINEVPN_ACTIVE_WG', '/etc/wireguard/tun0.conf');
if (!defined('MINEVPN_EVENTS_FILE'))     define('MINEVPN_EVENTS_FILE', '/var/log/minevpn/events.log');
if (!defined('MINEVPN_MAX_CONFIGS'))     define('MINEVPN_MAX_CONFIGS', 30);
if (!defined('MINEVPN_MAX_CONFIG_NAME')) define('MINEVPN_MAX_CONFIG_NAME', 64);

// ══════════════════════════════════════════════════════════════════════════
// METADATA — loading/saving configs.json с flock
// ══════════════════════════════════════════════════════════════════════════

/**
 * Загрузить metadata всех конфигов (shared lock).
 * Сложные баги могли привести к дубликатам (разные config_id → один файл)
 * — после загрузки дедуплицируем по filename. Дедуп пишет обратно в JSON или нет —
 * решает caller. При реальных дублях mv_saveConfigs очистит при следующей записи.
 */
function mv_loadConfigs(string $metadataFile = MINEVPN_METADATA_FILE): array {
    if (!file_exists($metadataFile)) return [];
    $lockFile = $metadataFile . '.lock';
    $fp = fopen($lockFile, 'c');
    $content = '';
    if ($fp && flock($fp, LOCK_SH)) {
        $content = file_get_contents($metadataFile);
        flock($fp, LOCK_UN);
        fclose($fp);
    } else {
        if ($fp) fclose($fp);
        $content = file_get_contents($metadataFile);
    }
    $configs = json_decode($content, true) ?: [];
    return mv_dedupConfigs($configs);
}

/**
 * Дедупликация конфигов по filename. Если найдены два+ записи
 * с одинаковым filename (это всегда ошибка — 1 файл на диске = 1 конфиг),
 * оставляем тот что старше по created_at (без этого поля — первый в dict).
 * Аналогично для записей с пустым filename — исключаем.
 */
function mv_dedupConfigs(array $configs): array {
    if (empty($configs)) return [];
    $byFilename = [];
    $orphans    = []; // записи без filename — сломаны, выкидываем
    foreach ($configs as $id => $cfg) {
        $filename = $cfg['filename'] ?? '';
        if (empty($filename)) { $orphans[$id] = $cfg; continue; }

        if (!isset($byFilename[$filename])) {
            $byFilename[$filename] = [$id => $cfg];
        } else {
            $byFilename[$filename][$id] = $cfg;
        }
    }

    $clean = [];
    foreach ($byFilename as $filename => $candidates) {
        if (count($candidates) === 1) {
            // Нет дублей — берём как есть
            foreach ($candidates as $id => $cfg) $clean[$id] = $cfg;
            continue;
        }
        // Дубли — оставляем старший по created_at ("" или null → конец списка)
        uasort($candidates, function($a, $b) {
            $ta = $a['created_at'] ?? '';
            $tb = $b['created_at'] ?? '';
            return strcmp($ta, $tb);
        });
        // Первый в отсортированном — самый старый (первоисточник)
        $first = array_key_first($candidates);
        $clean[$first] = $candidates[$first];
    }
    return $clean;
}

/**
 * Сохранить metadata (exclusive lock, атомарная запись через tmp+rename).
 * Перед записью дедуплицируем — safety net от багов.
 */
function mv_saveConfigs(array $configs, string $metadataFile = MINEVPN_METADATA_FILE): void {
    $configs = mv_dedupConfigs($configs);
    $lockFile = $metadataFile . '.lock';
    $tmpFile  = $metadataFile . '.tmp';
    $json = json_encode($configs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $fp = fopen($lockFile, 'c');
    if ($fp && flock($fp, LOCK_EX)) {
        if (file_put_contents($tmpFile, $json) !== false) {
            rename($tmpFile, $metadataFile);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
    } else {
        if ($fp) fclose($fp);
        if (file_put_contents($tmpFile, $json) !== false) {
            rename($tmpFile, $metadataFile);
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════
// STATE MACHINE — чтение/запись /var/www/minevpn-state
// ══════════════════════════════════════════════════════════════════════════

/**
 * Безопасный парсинг VPN state (whitelist ключей).
 */
function mv_readState(string $stateFile = MINEVPN_STATE_FILE): array {
    $state = [
        'STATE'        => 'stopped',
        'ACTIVE_ID'    => '',
        'PRIMARY_ID'   => '',
        'ACTIVATED_BY' => '',
    ];
    if (!file_exists($stateFile)) return $state;
    $allowed = ['STATE', 'ACTIVE_ID', 'PRIMARY_ID', 'ACTIVATED_BY'];
    $lines = @file($stateFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m) && in_array($m[1], $allowed, true)) {
            $state[$m[1]] = $m[2];
        }
    }
    return $state;
}

/**
 * Запись VPN state (файл предсоздан installer.sh с chmod 666).
 */
function mv_saveState(string $state, string $activeId, string $primaryId, string $activatedBy, string $stateFile = MINEVPN_STATE_FILE): void {
    $content = "STATE=$state\nACTIVE_ID=$activeId\nPRIMARY_ID=$primaryId\nACTIVATED_BY=$activatedBy\n";
    file_put_contents($stateFile, $content, LOCK_EX);
}

// ══════════════════════════════════════════════════════════════════════════
// VALIDATION
// ══════════════════════════════════════════════════════════════════════════

/**
 * Валидация configId: разрешаем только vpn_[hex16].
 * Защищает от path traversal + манипуляций с JSON-ключами.
 */
function mv_isValidConfigId(string $id): bool {
    return (bool) preg_match('/^vpn_[a-f0-9]{16}$/', $id);
}

/**
 * UTF-8 safe substr (fallback на byte-level substr если mbstring не установлен).
 */
function mv_safeSubstr(string $str, int $start, int $length): string {
    if (function_exists('mb_substr')) {
        return mb_substr($str, $start, $length);
    }
    return substr($str, $start, $length);
}

// ══════════════════════════════════════════════════════════════════════════
// CONFIG FILES — detect type, extract info
// ══════════════════════════════════════════════════════════════════════════

/**
 * Определить тип конфига по содержимому файла.
 * @return string 'wireguard' | 'openvpn' | 'unknown'
 */
function mv_detectConfigType(string $filePath): string {
    if (!file_exists($filePath)) return 'unknown';
    $content = file_get_contents($filePath);

    if (preg_match('/\[Interface\]/i', $content) && preg_match('/PrivateKey\s*=/i', $content)) {
        return 'wireguard';
    }
    if (preg_match('/^(client|remote|proto|dev|cipher)/mi', $content)) {
        return 'openvpn';
    }
    return 'unknown';
}

/**
 * Извлечь server/port/protocol из конфига.
 */
function mv_extractConfigInfo(string $filePath, string $type): array {
    $info = ['server' => 'Неизвестно', 'port' => '', 'protocol' => ''];
    if (!file_exists($filePath)) return $info;
    $content = file_get_contents($filePath);

    if ($type === 'openvpn') {
        if (preg_match('/^\s*remote\s+([^\s]+)\s*(\d+)?/mi', $content, $m)) {
            $info['server'] = $m[1];
            $info['port']   = $m[2] ?? '1194';
        }
        if (preg_match('/^\s*proto\s+(\w+)/mi', $content, $m)) {
            $info['protocol'] = strtoupper($m[1]);
        }
    } elseif ($type === 'wireguard') {
        if (preg_match('/^\s*Endpoint\s*=\s*([^:]+):(\d+)/mi', $content, $m)) {
            $info['server'] = $m[1];
            $info['port']   = $m[2];
        }
        $info['protocol'] = 'UDP';
    }
    return $info;
}

/**
 * Сгенерировать уникальный ID формата vpn_[hex16].
 */
function mv_generateConfigId(): string {
    return 'vpn_' . bin2hex(random_bytes(8));
}

// ══════════════════════════════════════════════════════════════════════════
// ACTIVE VPN — какой сервис реально активен
// ══════════════════════════════════════════════════════════════════════════

/**
 * Определить активный VPN по systemctl + существованию конфиг-файла.
 * @return array|null ['type' => 'wireguard'|'openvpn', 'file' => path] или null
 */
function mv_getActiveConfig(): ?array {
    $wgActive   = (trim(shell_exec("systemctl is-active wg-quick@tun0 2>/dev/null")) === 'active');
    $ovpnActive = (trim(shell_exec("systemctl is-active openvpn@tun0 2>/dev/null")) === 'active');

    if ($wgActive && file_exists(MINEVPN_ACTIVE_WG)) {
        return ['type' => 'wireguard', 'file' => MINEVPN_ACTIVE_WG];
    }
    if ($ovpnActive && file_exists(MINEVPN_ACTIVE_OVPN)) {
        return ['type' => 'openvpn', 'file' => MINEVPN_ACTIVE_OVPN];
    }
    // Fallback: файл есть, сервис нет
    if (file_exists(MINEVPN_ACTIVE_WG))   return ['type' => 'wireguard', 'file' => MINEVPN_ACTIVE_WG];
    if (file_exists(MINEVPN_ACTIVE_OVPN)) return ['type' => 'openvpn', 'file' => MINEVPN_ACTIVE_OVPN];
    return null;
}

/**
 * tun0 существует и имеет состояние UP.
 */
function mv_checkVPNStatus(): bool {
    $output = shell_exec("ip link show tun0 2>&1");
    return (strpos($output, 'does not exist') === false
         && strpos($output, 'Device not found') === false
         && strpos($output, ',UP') !== false);
}

// ══════════════════════════════════════════════════════════════════════════
// EVENTS LOG — запись событий в events.log
// ══════════════════════════════════════════════════════════════════════════

/**
 * Добавить событие в events.log. Формат: TIME|TYPE|F1|F2|F3|F4
 * Поля не могут содержать '|' '\n' '\r' — заменяются на '/'/пробел.
 * HC daemon пишет свои события сам, PHP пишет действия пользователя.
 */
function mv_logEvent(string $type, string ...$fields): void {
    $ts = date('Y-m-d H:i:s');
    $sanitize = fn($v) => str_replace(['|', "\n", "\r"], ['/', ' ', ' '], (string)$v);
    $parts = array_map($sanitize, $fields);
    $line = $ts . '|' . $type;
    if (!empty($parts)) $line .= '|' . implode('|', $parts);
    $line .= "\n";
    @file_put_contents(MINEVPN_EVENTS_FILE, $line, FILE_APPEND | LOCK_EX);

    // Ротация: если > 256KB → оставить последние 500 строк
    $size = @filesize(MINEVPN_EVENTS_FILE);
    if ($size !== false && $size > 262144) {
        $lines = @file(MINEVPN_EVENTS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (count($lines) > 500) {
            $keep = array_slice($lines, -500);
            @file_put_contents(MINEVPN_EVENTS_FILE, implode("\n", $keep) . "\n", LOCK_EX);
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════
// SERVICES — запуск/стоп VPN
// ══════════════════════════════════════════════════════════════════════════

/**
 * Остановить оба возможных VPN-сервиса и почистить конфиги.
 * Используется как при старте нового конфига (чтобы чисто встать), так и
 * при явной остановке.
 */
function mv_stopAllServices(): void {
    shell_exec('sudo systemctl stop openvpn@tun0 2>/dev/null');
    shell_exec('sudo systemctl stop wg-quick@tun0 2>/dev/null');
}

function mv_disableAllServices(): void {
    shell_exec('sudo systemctl disable wg-quick@tun0 2>/dev/null');
    shell_exec('sudo systemctl disable openvpn@tun0 2>/dev/null');
}

function mv_cleanActiveConfigFiles(): void {
    shell_exec('rm -f ' . escapeshellarg(MINEVPN_ACTIVE_OVPN) . ' ' . escapeshellarg(MINEVPN_ACTIVE_WG) . ' 2>/dev/null');
}

/**
 * Скопировать конфиг в /etc/ и запустить соответствующий сервис.
 * @return bool true если copy успешный (НЕ гарантирует что VPN реально поднялся — это должен делать caller через mv_pollVpnUp).
 */
function mv_activateServiceFromFile(string $sourceFile, string $type): bool {
    if ($type === 'wireguard') {
        if (!copy($sourceFile, MINEVPN_ACTIVE_WG)) return false;
        chmod(MINEVPN_ACTIVE_WG, 0600);
        shell_exec('sudo systemctl disable openvpn@tun0 2>/dev/null');
        shell_exec('sudo systemctl enable wg-quick@tun0 2>/dev/null');
        shell_exec('sudo systemctl start wg-quick@tun0');
        return true;
    } else {
        if (!copy($sourceFile, MINEVPN_ACTIVE_OVPN)) return false;
        chmod(MINEVPN_ACTIVE_OVPN, 0600);
        shell_exec('sudo systemctl disable wg-quick@tun0 2>/dev/null');
        shell_exec('sudo systemctl enable openvpn@tun0 2>/dev/null');
        shell_exec('sudo systemctl start openvpn@tun0');
        return true;
    }
}

/**
 * Ждать пока tun0 поднимется (до $timeoutSec секунд, шаг 1с).
 * @return bool true если поднялся, false если таймаут
 */
function mv_pollVpnUp(int $timeoutSec = 15): bool {
    for ($i = 0; $i < $timeoutSec; $i++) {
        sleep(1);
        if (mv_checkVPNStatus()) return true;
    }
    return false;
}
