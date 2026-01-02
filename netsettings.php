<?php
// ==============================================================================
// MINE SERVER - Настройки сети (WAN интерфейс)
// ==============================================================================

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

$csrf_token = $_SESSION['csrf_token'] ?? '';
$netplan_file = '/etc/netplan/01-mineserver.yaml';

// Определение WAN интерфейса (первый в netplan)
function getWanInterface() {
    global $netplan_file;
    
    if (file_exists($netplan_file)) {
        $content = file_get_contents($netplan_file);
        preg_match_all('/^\s{4}(\w+):/m', $content, $matches);
        if (!empty($matches[1][0])) {
            return $matches[1][0];
        }
    }
    
    // Fallback - интерфейс с default route
    $route = shell_exec('ip route | grep default | awk \'{print $5}\' | head -1');
    return trim($route) ?: 'eth0';
}

// Получение текущих настроек WAN
function getWanConfig() {
    global $netplan_file;
    $wan = getWanInterface();
    
    $config = [
        'interface' => $wan,
        'mode' => 'dhcp',
        'ip' => '',
        'gateway' => '',
        'dns1' => '8.8.8.8',
        'dns2' => '1.1.1.1'
    ];
    
    if (file_exists($netplan_file)) {
        $content = file_get_contents($netplan_file);
        
        // Ищем секцию WAN интерфейса
        if (preg_match('/\s{4}' . preg_quote($wan, '/') . ':(.+?)(?=\n\s{4}\w+:|\n\s{2}\w|\Z)/s', $content, $m)) {
            $section = $m[1];
            
            if (strpos($section, 'dhcp4: true') !== false || strpos($section, 'dhcp4: yes') !== false) {
                $config['mode'] = 'dhcp';
            } else {
                $config['mode'] = 'static';
                
                if (preg_match('/addresses:\s*\[\s*([^\]]+)\s*\]/', $section, $ip)) {
                    $config['ip'] = trim($ip[1]);
                }
                
                // routes с gateway
                if (preg_match('/via:\s*([0-9.]+)/', $section, $gw)) {
                    $config['gateway'] = $gw[1];
                }
                // Старый формат gateway4
                if (preg_match('/gateway4:\s*([0-9.]+)/', $section, $gw)) {
                    $config['gateway'] = $gw[1];
                }
                
                if (preg_match('/nameservers:.*?addresses:\s*\[([^\]]+)\]/s', $section, $dns)) {
                    $servers = array_map('trim', explode(',', $dns[1]));
                    $config['dns1'] = $servers[0] ?? '8.8.8.8';
                    $config['dns2'] = $servers[1] ?? '';
                }
            }
        }
    }
    
    // Получаем текущий IP из системы
    $current_ip = trim(shell_exec("ip -4 addr show {$wan} | grep -oP '(?<=inet\s)\d+(\.\d+){3}/\d+' | head -1") ?: '');
    if ($config['mode'] === 'dhcp' && $current_ip) {
        $config['current_ip'] = $current_ip;
    }
    
    return $config;
}

// Сохранение настроек WAN
function saveWanConfig($config) {
    global $netplan_file;
    
    if (!file_exists($netplan_file)) {
        return ['success' => false, 'error' => 'Файл netplan не найден'];
    }
    
    $content = file_get_contents($netplan_file);
    $wan = $config['interface'];
    
    // Формируем новую конфигурацию WAN
    if ($config['mode'] === 'dhcp') {
        $new_config = "    {$wan}:\n";
        $new_config .= "      dhcp4: true\n";
    } else {
        $new_config = "    {$wan}:\n";
        $new_config .= "      dhcp4: false\n";
        $new_config .= "      addresses: [{$config['ip']}]\n";
        $new_config .= "      routes:\n";
        $new_config .= "        - to: default\n";
        $new_config .= "          via: {$config['gateway']}\n";
        $new_config .= "      nameservers:\n";
        $dns = $config['dns1'];
        if (!empty($config['dns2'])) $dns .= ", {$config['dns2']}";
        $new_config .= "        addresses: [{$dns}]\n";
    }
    
    // Заменяем секцию WAN интерфейса
    $pattern = '/(\s{4}' . preg_quote($wan, '/') . ':)(.+?)(?=\n\s{4}\w+:|\n\s{2}\w|\Z)/s';
    
    if (preg_match($pattern, $content)) {
        $content = preg_replace($pattern, rtrim($new_config), $content);
    }
    
    // Бэкап
    copy($netplan_file, $netplan_file . '.bak');
    file_put_contents($netplan_file, $content);
    
    // Применяем с try (10 сек откат)
    $output = shell_exec('sudo netplan try --timeout 10 2>&1');
    
    if (strpos($output, 'error') !== false || strpos($output, 'Error') !== false) {
        // Откат
        copy($netplan_file . '.bak', $netplan_file);
        shell_exec('sudo netplan apply 2>&1');
        return ['success' => false, 'error' => 'Ошибка применения: ' . $output];
    }
    
    shell_exec('sudo netplan apply 2>&1');
    return ['success' => true];
}

// Получение информации об интерфейсах
function getInterfacesInfo() {
    $interfaces = [];
    
    $output = shell_exec('ip -j addr 2>/dev/null') ?: '';
    $data = @json_decode($output, true) ?: [];
    
    foreach ($data as $iface) {
        if ($iface['ifname'] === 'lo') continue;
        
        $ipv4 = '';
        foreach ($iface['addr_info'] ?? [] as $addr) {
            if ($addr['family'] === 'inet') {
                $ipv4 = $addr['local'] . '/' . $addr['prefixlen'];
                break;
            }
        }
        
        // Получаем трафик
        $rx = @file_get_contents("/sys/class/net/{$iface['ifname']}/statistics/rx_bytes") ?: 0;
        $tx = @file_get_contents("/sys/class/net/{$iface['ifname']}/statistics/tx_bytes") ?: 0;
        
        $interfaces[] = [
            'name' => $iface['ifname'],
            'status' => in_array('UP', $iface['flags'] ?? []) ? 'up' : 'down',
            'mac' => $iface['address'] ?? '',
            'ipv4' => $ipv4,
            'rx' => formatBytes((int)$rx),
            'tx' => formatBytes((int)$tx)
        ];
    }
    
    return $interfaces;
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// Обработка POST
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        
        if (isset($_POST['save_wan'])) {
            $config = [
                'interface' => getWanInterface(),
                'mode' => $_POST['mode'] === 'static' ? 'static' : 'dhcp',
                'ip' => trim($_POST['ip'] ?? ''),
                'gateway' => trim($_POST['gateway'] ?? ''),
                'dns1' => trim($_POST['dns1'] ?? '8.8.8.8'),
                'dns2' => trim($_POST['dns2'] ?? '')
            ];
            
            if ($config['mode'] === 'static') {
                if (!preg_match('/^\d+\.\d+\.\d+\.\d+\/\d+$/', $config['ip'])) {
                    $message = 'Неверный формат IP. Используйте: 192.168.1.100/24';
                    $messageType = 'error';
                } elseif (!filter_var($config['gateway'], FILTER_VALIDATE_IP)) {
                    $message = 'Неверный формат шлюза';
                    $messageType = 'error';
                } else {
                    $result = saveWanConfig($config);
                    if ($result['success']) {
                        $message = 'Настройки сохранены';
                    } else {
                        $message = $result['error'];
                        $messageType = 'error';
                    }
                }
            } else {
                $result = saveWanConfig($config);
                $message = $result['success'] ? 'DHCP активирован' : $result['error'];
                $messageType = $result['success'] ? 'success' : 'error';
            }
        }
        
        if (isset($_POST['apply_netplan'])) {
            shell_exec('sudo netplan apply 2>&1');
            $message = 'Сетевые настройки применены';
        }
    }
}

$wanConfig = getWanConfig();
$interfaces = getInterfacesInfo();

// Внешний IP
$external_ip = trim(shell_exec('curl -s --max-time 5 https://api.ipify.org 2>/dev/null') ?: 'Не определён');
$vpn_ip = trim(shell_exec('curl -s --max-time 5 --interface tun0 https://api.ipify.org 2>/dev/null') ?: '');
?>

<?php if ($message): ?>
<div class="p-4 rounded-xl border mb-4 <?= $messageType === 'error' ? 'bg-red-500/20 border-red-500/30 text-red-300' : 'bg-green-500/20 border-green-500/30 text-green-300' ?>">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<div class="space-y-6">
    
    <!-- IP адреса -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="glassmorphism rounded-xl p-4">
            <div class="text-slate-400 text-sm mb-1">Внешний IP (реальный)</div>
            <div class="text-2xl font-bold text-white font-mono"><?= htmlspecialchars($external_ip) ?></div>
        </div>
        <div class="glassmorphism rounded-xl p-4">
            <div class="text-slate-400 text-sm mb-1">VPN IP</div>
            <div class="text-2xl font-bold font-mono <?= $vpn_ip ? 'text-green-400' : 'text-slate-500' ?>">
                <?= $vpn_ip ?: 'VPN не активен' ?>
            </div>
        </div>
    </div>
    
    <!-- Настройка WAN -->
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-xl font-bold text-white mb-6">
            Настройка WAN интерфейса 
            <span class="text-sm text-slate-400 font-normal">(<?= htmlspecialchars($wanConfig['interface']) ?>)</span>
        </h2>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            
            <div class="mb-4">
                <label class="block text-sm text-slate-400 mb-2">Режим подключения</label>
                <div class="flex gap-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="mode" value="dhcp" <?= $wanConfig['mode'] === 'dhcp' ? 'checked' : '' ?> 
                            onchange="toggleMode()" class="text-violet-600">
                        <span class="text-white">DHCP (автоматически)</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="mode" value="static" <?= $wanConfig['mode'] === 'static' ? 'checked' : '' ?>
                            onchange="toggleMode()" class="text-violet-600">
                        <span class="text-white">Статический IP</span>
                    </label>
                </div>
            </div>
            
            <?php if ($wanConfig['mode'] === 'dhcp' && !empty($wanConfig['current_ip'])): ?>
            <div class="mb-4 p-3 bg-slate-800/50 rounded-lg">
                <span class="text-slate-400 text-sm">Текущий IP (получен по DHCP):</span>
                <span class="text-white font-mono ml-2"><?= htmlspecialchars($wanConfig['current_ip']) ?></span>
            </div>
            <?php endif; ?>
            
            <div id="static-fields" class="<?= $wanConfig['mode'] === 'dhcp' ? 'hidden' : '' ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm text-slate-400 mb-1">IP адрес с маской</label>
                        <input type="text" name="ip" value="<?= htmlspecialchars($wanConfig['ip']) ?>" 
                            placeholder="192.168.1.100/24"
                            class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white font-mono text-sm">
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1">Шлюз (Gateway)</label>
                        <input type="text" name="gateway" value="<?= htmlspecialchars($wanConfig['gateway']) ?>" 
                            placeholder="192.168.1.1"
                            class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white font-mono text-sm">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm text-slate-400 mb-1">DNS 1</label>
                        <input type="text" name="dns1" value="<?= htmlspecialchars($wanConfig['dns1']) ?>" 
                            class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white font-mono text-sm">
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1">DNS 2</label>
                        <input type="text" name="dns2" value="<?= htmlspecialchars($wanConfig['dns2']) ?>" 
                            class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white font-mono text-sm">
                    </div>
                </div>
            </div>
            
            <div class="flex gap-2">
                <button type="submit" name="save_wan" class="bg-violet-600 hover:bg-violet-700 text-white font-medium py-2 px-6 rounded-lg transition">
                    Сохранить
                </button>
                <button type="submit" name="apply_netplan" class="bg-slate-700 hover:bg-slate-600 text-white font-medium py-2 px-4 rounded-lg transition">
                    Применить netplan
                </button>
            </div>
        </form>
    </div>
    
    <!-- Интерфейсы -->
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-xl font-bold text-white mb-4">Сетевые интерфейсы</h2>
        
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 border-b border-slate-700">
                        <th class="pb-2">Интерфейс</th>
                        <th class="pb-2">Статус</th>
                        <th class="pb-2">IP адрес</th>
                        <th class="pb-2">MAC</th>
                        <th class="pb-2">RX / TX</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($interfaces as $iface): ?>
                    <tr class="border-b border-slate-800">
                        <td class="py-2 font-mono text-white"><?= htmlspecialchars($iface['name']) ?></td>
                        <td class="py-2">
                            <span class="px-2 py-0.5 rounded text-xs <?= $iface['status'] === 'up' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' ?>">
                                <?= $iface['status'] === 'up' ? 'UP' : 'DOWN' ?>
                            </span>
                        </td>
                        <td class="py-2 font-mono text-slate-300"><?= htmlspecialchars($iface['ipv4'] ?: '-') ?></td>
                        <td class="py-2 font-mono text-slate-400 text-xs"><?= htmlspecialchars($iface['mac']) ?></td>
                        <td class="py-2 text-slate-400"><?= $iface['rx'] ?> / <?= $iface['tx'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function toggleMode() {
    const staticFields = document.getElementById('static-fields');
    const isStatic = document.querySelector('input[name="mode"]:checked').value === 'static';
    staticFields.classList.toggle('hidden', !isStatic);
}
</script>
