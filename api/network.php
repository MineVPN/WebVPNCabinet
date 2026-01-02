<?php
// ==============================================================================
// MINE SERVER - API сетевой информации
// ==============================================================================

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

/**
 * Получение списка сетевых интерфейсов
 */
function getNetworkInterfaces() {
    $interfaces = [];
    
    // Читаем из /sys/class/net
    $netDir = '/sys/class/net';
    $dirs = scandir($netDir);
    
    foreach ($dirs as $iface) {
        if ($iface === '.' || $iface === '..' || $iface === 'lo') {
            continue;
        }
        
        $info = [
            'name' => $iface,
            'type' => getInterfaceType($iface),
            'status' => 'down',
            'mac' => null,
            'ipv4' => null,
            'ipv6' => null,
            'rx_bytes' => 0,
            'tx_bytes' => 0,
            'rx_packets' => 0,
            'tx_packets' => 0,
            'mtu' => null
        ];
        
        // Статус интерфейса
        $operstate = @file_get_contents("$netDir/$iface/operstate");
        if ($operstate !== false) {
            $info['status'] = trim($operstate) === 'up' ? 'up' : 'down';
        }
        
        // MAC адрес
        $address = @file_get_contents("$netDir/$iface/address");
        if ($address !== false) {
            $info['mac'] = trim($address);
        }
        
        // MTU
        $mtu = @file_get_contents("$netDir/$iface/mtu");
        if ($mtu !== false) {
            $info['mtu'] = (int)trim($mtu);
        }
        
        // Статистика трафика
        $rxBytes = @file_get_contents("$netDir/$iface/statistics/rx_bytes");
        $txBytes = @file_get_contents("$netDir/$iface/statistics/tx_bytes");
        $rxPackets = @file_get_contents("$netDir/$iface/statistics/rx_packets");
        $txPackets = @file_get_contents("$netDir/$iface/statistics/tx_packets");
        
        if ($rxBytes !== false) $info['rx_bytes'] = (int)trim($rxBytes);
        if ($txBytes !== false) $info['tx_bytes'] = (int)trim($txBytes);
        if ($rxPackets !== false) $info['rx_packets'] = (int)trim($rxPackets);
        if ($txPackets !== false) $info['tx_packets'] = (int)trim($txPackets);
        
        // Форматированный трафик
        $info['rx_formatted'] = formatBytes($info['rx_bytes']);
        $info['tx_formatted'] = formatBytes($info['tx_bytes']);
        
        // IP адрес через ip command
        $ipOutput = shell_exec("ip -4 addr show $iface 2>/dev/null");
        if (preg_match('/inet (\d+\.\d+\.\d+\.\d+)\/(\d+)/', $ipOutput, $matches)) {
            $info['ipv4'] = $matches[1];
            $info['ipv4_mask'] = $matches[2];
        }
        
        $interfaces[] = $info;
    }
    
    return $interfaces;
}

/**
 * Определение типа интерфейса
 */
function getInterfaceType($iface) {
    if (strpos($iface, 'tun') === 0 || strpos($iface, 'tap') === 0) {
        return 'vpn';
    }
    if (strpos($iface, 'wg') === 0) {
        return 'wireguard';
    }
    if (strpos($iface, 'eth') === 0 || strpos($iface, 'enp') === 0 || strpos($iface, 'ens') === 0) {
        return 'ethernet';
    }
    if (strpos($iface, 'wlan') === 0 || strpos($iface, 'wlp') === 0) {
        return 'wifi';
    }
    if (strpos($iface, 'br') === 0) {
        return 'bridge';
    }
    if (strpos($iface, 'docker') === 0 || strpos($iface, 'veth') === 0) {
        return 'docker';
    }
    if (strpos($iface, 'ppp') === 0) {
        return 'pppoe';
    }
    
    return 'other';
}

/**
 * Форматирование байтов
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Получение внешнего IP
 */
function getExternalIP($interface = null) {
    $services = [
        'https://api.ipify.org',
        'https://ifconfig.me/ip',
        'https://icanhazip.com'
    ];
    
    foreach ($services as $service) {
        $cmd = "curl -s --max-time 5";
        if ($interface) {
            $cmd .= " --interface " . escapeshellarg($interface);
        }
        $cmd .= " " . escapeshellarg($service) . " 2>/dev/null";
        
        $ip = trim(shell_exec($cmd));
        
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    
    return null;
}

/**
 * Получение информации о подключённых устройствах (через ARP)
 */
function getConnectedDevices() {
    $devices = [];
    
    // Читаем ARP таблицу
    $arp = shell_exec('arp -an 2>/dev/null');
    
    if ($arp) {
        foreach (explode("\n", trim($arp)) as $line) {
            // Формат: ? (192.168.1.1) at aa:bb:cc:dd:ee:ff [ether] on eth0
            if (preg_match('/\((\d+\.\d+\.\d+\.\d+)\) at ([0-9a-f:]+)/i', $line, $matches)) {
                $ip = $matches[1];
                $mac = $matches[2];
                
                // Пропускаем неполные записи
                if ($mac === '<incomplete>') {
                    continue;
                }
                
                $devices[] = [
                    'ip' => $ip,
                    'mac' => $mac,
                    'vendor' => getVendorByMac($mac)
                ];
            }
        }
    }
    
    // Также читаем из DHCP leases (dnsmasq)
    $leasesFile = '/var/lib/misc/dnsmasq.leases';
    if (file_exists($leasesFile)) {
        $leases = file_get_contents($leasesFile);
        foreach (explode("\n", trim($leases)) as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 4) {
                $mac = $parts[1];
                $ip = $parts[2];
                $hostname = $parts[3] !== '*' ? $parts[3] : null;
                
                // Проверяем, не добавлен ли уже
                $found = false;
                foreach ($devices as &$device) {
                    if ($device['ip'] === $ip || $device['mac'] === $mac) {
                        $device['hostname'] = $hostname;
                        $device['lease_time'] = (int)$parts[0];
                        $found = true;
                        break;
                    }
                }
                
                if (!$found) {
                    $devices[] = [
                        'ip' => $ip,
                        'mac' => $mac,
                        'hostname' => $hostname,
                        'vendor' => getVendorByMac($mac),
                        'lease_time' => (int)$parts[0]
                    ];
                }
            }
        }
    }
    
    // Сортируем по IP
    usort($devices, function($a, $b) {
        return ip2long($a['ip']) - ip2long($b['ip']);
    });
    
    return $devices;
}

/**
 * Получение производителя по MAC (базовая функция)
 */
function getVendorByMac($mac) {
    // Упрощённая база MAC-адресов
    $vendors = [
        '00:50:56' => 'VMware',
        '00:0c:29' => 'VMware',
        '08:00:27' => 'VirtualBox',
        'b8:27:eb' => 'Raspberry Pi',
        'dc:a6:32' => 'Raspberry Pi',
        'e4:5f:01' => 'Raspberry Pi',
        '00:1a:79' => 'Mikrotik',
        '48:8f:5a' => 'Mikrotik',
        'cc:2d:e0' => 'Mikrotik',
        '74:4d:28' => 'Mikrotik',
        '00:e0:4c' => 'Realtek',
        '52:54:00' => 'QEMU/KVM',
        '00:15:5d' => 'Hyper-V',
    ];
    
    $prefix = strtolower(substr($mac, 0, 8));
    
    return $vendors[$prefix] ?? null;
}

/**
 * Получение маршрутов
 */
function getRoutes() {
    $routes = [];
    
    $output = shell_exec('ip route 2>/dev/null');
    
    foreach (explode("\n", trim($output)) as $line) {
        if (empty($line)) continue;
        
        $routes[] = $line;
    }
    
    return $routes;
}

/**
 * Проверка статуса VPN
 */
function getVpnStatus() {
    $status = [
        'type' => null,
        'active' => false,
        'interface' => 'tun0',
        'ip' => null,
        'server' => null,
        'uptime' => null
    ];
    
    // Проверяем OpenVPN
    if (file_exists('/etc/openvpn/tun0.conf')) {
        $status['type'] = 'openvpn';
        $config = file_get_contents('/etc/openvpn/tun0.conf');
        if (preg_match('/remote\s+([^\s]+)/', $config, $matches)) {
            $status['server'] = $matches[1];
        }
    }
    
    // Проверяем WireGuard
    if (file_exists('/etc/wireguard/tun0.conf')) {
        $status['type'] = 'wireguard';
        $config = file_get_contents('/etc/wireguard/tun0.conf');
        if (preg_match('/Endpoint\s*=\s*([^:]+)/', $config, $matches)) {
            $status['server'] = $matches[1];
        }
    }
    
    // Проверяем активен ли интерфейс
    $ifconfig = shell_exec('ip link show tun0 2>/dev/null');
    if ($ifconfig && strpos($ifconfig, 'state UP') !== false) {
        $status['active'] = true;
        
        // Получаем IP туннеля
        $ipOutput = shell_exec('ip -4 addr show tun0 2>/dev/null');
        if (preg_match('/inet (\d+\.\d+\.\d+\.\d+)/', $ipOutput, $matches)) {
            $status['ip'] = $matches[1];
        }
    }
    
    return $status;
}

// Обработка запросов
$action = $_GET['action'] ?? 'all';

switch ($action) {
    case 'interfaces':
        echo json_encode(getNetworkInterfaces(), JSON_UNESCAPED_UNICODE);
        break;
        
    case 'external_ip':
        $interface = $_GET['interface'] ?? null;
        echo json_encode(['ip' => getExternalIP($interface)]);
        break;
        
    case 'devices':
        echo json_encode(getConnectedDevices(), JSON_UNESCAPED_UNICODE);
        break;
        
    case 'routes':
        echo json_encode(getRoutes());
        break;
        
    case 'vpn':
        echo json_encode(getVpnStatus());
        break;
        
    case 'all':
    default:
        echo json_encode([
            'interfaces' => getNetworkInterfaces(),
            'vpn' => getVpnStatus(),
            'external_ip' => getExternalIP(),
            'external_ip_vpn' => getExternalIP('tun0'),
            'devices_count' => count(getConnectedDevices())
        ], JSON_UNESCAPED_UNICODE);
}
