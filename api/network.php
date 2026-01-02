<?php
// ==============================================================================
// MINE SERVER - API сети
// ==============================================================================

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized']));
}

$action = $_GET['action'] ?? 'interfaces';

function formatBytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

switch ($action) {
    case 'interfaces':
        $interfaces = [];
        $dir = '/sys/class/net/';
        
        foreach (scandir($dir) as $name) {
            if ($name === '.' || $name === '..' || $name === 'lo') continue;
            
            $iface = [
                'name' => $name,
                'status' => 'down',
                'mac' => '',
                'ipv4' => '',
                'rx' => 0,
                'tx' => 0,
                'rx_formatted' => '0 B',
                'tx_formatted' => '0 B'
            ];
            
            // Статус
            $operstate = @file_get_contents("$dir$name/operstate");
            if ($operstate) {
                $iface['status'] = trim($operstate) === 'up' || trim($operstate) === 'unknown' ? 'up' : 'down';
            }
            
            // MAC
            $address = @file_get_contents("$dir$name/address");
            if ($address) $iface['mac'] = trim($address);
            
            // IP
            $ipOutput = shell_exec("ip -4 addr show $name 2>/dev/null | grep -oP '(?<=inet\s)\d+(\.\d+){3}/\d+'");
            if ($ipOutput) $iface['ipv4'] = trim($ipOutput);
            
            // Трафик
            $rx = @file_get_contents("$dir$name/statistics/rx_bytes");
            $tx = @file_get_contents("$dir$name/statistics/tx_bytes");
            if ($rx) {
                $iface['rx'] = (int)$rx;
                $iface['rx_formatted'] = formatBytes((int)$rx);
            }
            if ($tx) {
                $iface['tx'] = (int)$tx;
                $iface['tx_formatted'] = formatBytes((int)$tx);
            }
            
            $interfaces[] = $iface;
        }
        
        echo json_encode($interfaces);
        break;
        
    case 'devices':
        $devices = [];
        $leases_file = '/var/lib/misc/dnsmasq.leases';
        
        // Из dnsmasq leases (с hostname)
        if (file_exists($leases_file)) {
            $lines = file($leases_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $parts = preg_split('/\s+/', $line);
                if (count($parts) >= 4) {
                    $devices[$parts[1]] = [
                        'mac' => strtolower($parts[1]),
                        'ip' => $parts[2],
                        'hostname' => $parts[3] !== '*' ? $parts[3] : ''
                    ];
                }
            }
        }
        
        // Дополняем из ARP
        $arp = shell_exec('arp -an 2>/dev/null') ?: '';
        preg_match_all('/\(([0-9.]+)\)\s+at\s+([0-9a-f:]+)/i', $arp, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $m) {
            $mac = strtolower($m[2]);
            if (!isset($devices[$mac]) && $mac !== '(incomplete)') {
                $devices[$mac] = [
                    'mac' => $mac,
                    'ip' => $m[1],
                    'hostname' => ''
                ];
            }
        }
        
        echo json_encode(array_values($devices));
        break;
        
    case 'external_ip':
        $interface = $_GET['interface'] ?? '';
        $cmd = 'curl -s --max-time 5 ';
        if ($interface && preg_match('/^[a-z0-9]+$/i', $interface)) {
            $cmd .= "--interface $interface ";
        }
        $cmd .= 'https://api.ipify.org 2>/dev/null';
        
        $ip = trim(shell_exec($cmd) ?: '');
        echo json_encode(['ip' => $ip ?: null]);
        break;
        
    default:
        echo json_encode(['error' => 'Unknown action']);
}
