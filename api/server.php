<?php
// ==============================================================================
// MINE SERVER - API сервера
// ==============================================================================

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized']));
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'services_status':
        $services = [
            ['name' => 'OpenVPN', 'unit' => 'openvpn@tun0', 'config' => '/etc/openvpn/tun0.conf'],
            ['name' => 'WireGuard', 'unit' => 'wg-quick@tun0', 'config' => '/etc/wireguard/tun0.conf'],
            ['name' => 'DHCP/DNS', 'unit' => 'dnsmasq'],
            ['name' => 'Apache', 'unit' => 'apache2'],
            ['name' => 'Healthcheck', 'unit' => 'vpn-healthcheck.timer'],
            ['name' => 'SSH', 'unit' => 'ssh']
        ];
        
        $result = [];
        foreach ($services as $s) {
            // Проверяем наличие конфига для VPN
            if (isset($s['config']) && !file_exists($s['config'])) {
                $result[] = [
                    'name' => $s['name'],
                    'active' => false,
                    'enabled' => false,
                    'status' => 'Не настроен'
                ];
                continue;
            }
            
            $active = trim(shell_exec("systemctl is-active {$s['unit']} 2>/dev/null")) === 'active';
            $enabled = trim(shell_exec("systemctl is-enabled {$s['unit']} 2>/dev/null")) === 'enabled';
            
            $result[] = [
                'name' => $s['name'],
                'active' => $active,
                'enabled' => $enabled
            ];
        }
        
        echo json_encode($result);
        break;
        
    case 'reboot':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            die(json_encode(['error' => 'POST required']));
        }
        shell_exec('(sleep 2 && sudo reboot) > /dev/null 2>&1 &');
        echo json_encode(['success' => true, 'message' => 'Rebooting...']);
        break;
        
    case 'vpn_restart':
        if (file_exists('/etc/wireguard/tun0.conf')) {
            shell_exec('sudo systemctl restart wg-quick@tun0 2>&1');
        }
        if (file_exists('/etc/openvpn/tun0.conf')) {
            shell_exec('sudo systemctl restart openvpn@tun0 2>&1');
        }
        echo json_encode(['success' => true]);
        break;
        
    case 'hostname':
        echo json_encode(['hostname' => gethostname()]);
        break;
        
    default:
        echo json_encode(['error' => 'Unknown action']);
}
