<?php
// ==============================================================================
// MINE SERVER - API управления сервером
// ==============================================================================

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Проверка CSRF для POST запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $csrf_token = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit();
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    
    // ==================== ПЕРЕЗАГРУЗКА СЕРВЕРА ====================
    case 'reboot':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }
        
        // Запускаем перезагрузку с задержкой
        shell_exec('(sleep 2 && sudo reboot) > /dev/null 2>&1 &');
        
        echo json_encode([
            'success' => true,
            'message' => 'Сервер будет перезагружен через 2 секунды'
        ]);
        break;
    
    // ==================== ПЕРЕЗАГРУЗКА СЕТИ ====================
    case 'restart_network':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }
        
        shell_exec('sudo netplan apply 2>&1');
        
        echo json_encode([
            'success' => true,
            'message' => 'Сеть перезапущена'
        ]);
        break;
    
    // ==================== УПРАВЛЕНИЕ VPN ====================
    case 'vpn_start':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }
        
        $type = detectVpnType();
        
        if ($type === 'wireguard') {
            shell_exec('sudo systemctl start wg-quick@tun0 2>&1');
        } elseif ($type === 'openvpn') {
            shell_exec('sudo systemctl start openvpn@tun0 2>&1');
        } else {
            echo json_encode(['error' => 'VPN config not found']);
            exit();
        }
        
        sleep(3);
        
        echo json_encode([
            'success' => true,
            'message' => 'VPN запущен',
            'type' => $type
        ]);
        break;
        
    case 'vpn_stop':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }
        
        // Останавливаем оба типа
        shell_exec('sudo systemctl stop wg-quick@tun0 2>&1');
        shell_exec('sudo systemctl stop openvpn@tun0 2>&1');
        
        sleep(2);
        
        echo json_encode([
            'success' => true,
            'message' => 'VPN остановлен'
        ]);
        break;
        
    case 'vpn_restart':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }
        
        $type = detectVpnType();
        
        if ($type === 'wireguard') {
            shell_exec('sudo systemctl restart wg-quick@tun0 2>&1');
        } elseif ($type === 'openvpn') {
            shell_exec('sudo systemctl restart openvpn@tun0 2>&1');
        } else {
            echo json_encode(['error' => 'VPN config not found']);
            exit();
        }
        
        sleep(3);
        
        echo json_encode([
            'success' => true,
            'message' => 'VPN перезапущен',
            'type' => $type
        ]);
        break;
    
    // ==================== СТАТУС СЛУЖБ ====================
    case 'services_status':
        $services = [
            'openvpn@tun0' => 'OpenVPN',
            'wg-quick@tun0' => 'WireGuard',
            'dnsmasq' => 'DNS/DHCP',
            'apache2' => 'Web Server',
            'vpn-healthcheck.timer' => 'VPN Monitor',
            'ssh' => 'SSH'
        ];
        
        $status = [];
        
        foreach ($services as $service => $name) {
            $isActive = trim(shell_exec("systemctl is-active $service 2>/dev/null")) === 'active';
            $isEnabled = trim(shell_exec("systemctl is-enabled $service 2>/dev/null")) === 'enabled';
            
            $status[] = [
                'id' => $service,
                'name' => $name,
                'active' => $isActive,
                'enabled' => $isEnabled
            ];
        }
        
        echo json_encode($status);
        break;
    
    // ==================== ПЕРЕЗАПУСК СЛУЖБЫ ====================
    case 'service_restart':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $service = $input['service'] ?? '';
        
        // Белый список разрешённых служб
        $allowed = ['openvpn@tun0', 'wg-quick@tun0', 'dnsmasq', 'apache2', 'vpn-healthcheck.timer'];
        
        if (!in_array($service, $allowed)) {
            http_response_code(403);
            echo json_encode(['error' => 'Service not allowed']);
            exit();
        }
        
        $service = escapeshellarg($service);
        shell_exec("sudo systemctl restart $service 2>&1");
        
        echo json_encode([
            'success' => true,
            'message' => "Служба перезапущена"
        ]);
        break;
    
    // ==================== ПОЛУЧЕНИЕ CSRF ТОКЕНА ====================
    case 'csrf':
        echo json_encode(['csrf_token' => $_SESSION['csrf_token']]);
        break;
    
    // ==================== HOSTNAME ====================
    case 'hostname':
        echo json_encode(['hostname' => gethostname()]);
        break;
    
    // ==================== SPEEDTEST ====================
    case 'speedtest':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }
        
        // Проверяем наличие speedtest-cli
        $hasSpeedtest = trim(shell_exec('which speedtest-cli 2>/dev/null'));
        
        if (empty($hasSpeedtest)) {
            echo json_encode(['error' => 'speedtest-cli not installed']);
            exit();
        }
        
        // Запускаем speedtest (может занять время)
        $output = shell_exec('speedtest-cli --simple 2>&1');
        
        $result = [
            'ping' => null,
            'download' => null,
            'upload' => null,
            'raw' => $output
        ];
        
        if (preg_match('/Ping:\s*([\d.]+)\s*ms/', $output, $m)) {
            $result['ping'] = (float)$m[1];
        }
        if (preg_match('/Download:\s*([\d.]+)\s*Mbit/', $output, $m)) {
            $result['download'] = (float)$m[1];
        }
        if (preg_match('/Upload:\s*([\d.]+)\s*Mbit/', $output, $m)) {
            $result['upload'] = (float)$m[1];
        }
        
        echo json_encode($result);
        break;
    
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}

/**
 * Определение типа VPN по конфигу
 */
function detectVpnType() {
    if (file_exists('/etc/wireguard/tun0.conf')) {
        return 'wireguard';
    }
    if (file_exists('/etc/openvpn/tun0.conf')) {
        return 'openvpn';
    }
    return null;
}
