<?php
/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ══════════════════════════════════════════════════════════════════
 *                 A P I   P I N G   F I L E
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
 * MineVPN Server — Ping API / Endpoint проверки доступности хоста
 *
 * GET-запрос выполняет `ping -c 1 -W 1 [-I interface] host` и возвращает:
 *   • число миллисекунд (из "time=X ms")
 *   • "OK" — если ping прошёл но время не найдено
 *   • "NO PING" — в случае провала или невалидных параметров
 *
 * Параметры GET:
 *   • host       — IPv4 адрес или hostname (валидация через regex)
 *   • interface  — имя сетевого интерфейса (опционально), или "detect_netplan"
 *                  — тогда определяется из netplan yaml автоматически
 *
 * Безопасность (Command Injection):
 *   • host валидируется как IPv4 или RFC-1123 hostname (regex)
 *   • interface валидируется как [a-zA-Z0-9_:@-]{1,20}
 *   • escapeshellarg() на оба параметра перед exec()
 *   • Auth через $_SESSION['authenticated'] (403 без сессии)
 *
 * Взаимодействует с:
 *   • pages/pinger.php — inline <script> в странице «Пинг» вызывает этот endpoint setInterval-ом
 *   • system — вызывает /bin/ping (без sudo, хватает cap_net_raw)
 *   • /etc/netplan/01-network-manager-all.yaml — читает при detect_netplan (через yaml_parse_file)
 */
session_start();
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(403);
    die('Unauthorized');
}
$host = trim($_GET['host'] ?? '');
$interface_param = trim($_GET['interface'] ?? '');

if (empty($host)) {
    die("NO PING");
}

// Валидация host: разрешаем только IPv4 или валидный hostname
$isIp  = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
$isHostname = preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]{0,253}[a-zA-Z0-9])?$/', $host)
              && strlen($host) <= 255;
if (!$isIp && !$isHostname) {
    die("NO PING");
}

// Валидация интерфейса: только безопасные символы
if (!empty($interface_param) && !preg_match('/^[a-zA-Z0-9_:@-]{1,20}$/', $interface_param)
    && $interface_param !== 'detect_netplan') {
    die("NO PING");
}

$interface = $interface_param;

if ($interface === 'detect_netplan') {
    $interface = '';
    $yamlFilePath = '/etc/netplan/01-network-manager-all.yaml';
    if (function_exists('yaml_parse_file') && file_exists($yamlFilePath) && is_readable($yamlFilePath)) {
        $data = @yaml_parse_file($yamlFilePath);
        if (isset($data['network']['ethernets'])) {
            foreach ($data['network']['ethernets'] as $if_name => $config) {
                if (!isset($config['optional']) || $config['optional'] !== true) {
                    $interface = $if_name;
                    break;
                }
            }
        }
    }
}

$escaped_host = escapeshellarg($host);
$command = "ping -c 1 -W 1";
if (!empty($interface)) {
    $escaped_interface = escapeshellarg($interface);
    $command .= " -I " . $escaped_interface;
}
$command .= " " . $escaped_host;
exec($command, $output, $result);

if ($result == 0) {
    $found = false;
    foreach ($output as $line) {
        if (strpos($line, "time=") !== false) {
            $time_part = explode("time=", $line)[1];
            $time = trim(explode(" ", $time_part)[0]);
            echo $time;
            $found = true;
            break;
        }
    }
    if (!$found) {
        echo "OK";
    }
} else {
    echo "NO PING";
}
?>
