<?php
// ==============================================================================
// MINE SERVER - Управление DHCP сервером
// ==============================================================================

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

$csrf_token = $_SESSION['csrf_token'] ?? '';

// Файлы конфигурации
$dnsmasq_conf = '/etc/dnsmasq.conf';
$dnsmasq_static = '/etc/dnsmasq.d/static-leases.conf';
$dnsmasq_leases = '/var/lib/misc/dnsmasq.leases';
$netplan_file = '/etc/netplan/01-mineserver.yaml';

// Получение текущих настроек dnsmasq
function getDnsmasqConfig() {
    global $dnsmasq_conf;
    $config = [
        'interface' => 'eth1',
        'dhcp_start' => '10.10.1.2',
        'dhcp_end' => '10.10.15.254',
        'netmask' => '255.255.240.0',
        'gateway' => '10.10.1.1',
        'dns1' => '8.8.8.8',
        'dns2' => '1.1.1.1',
        'lease_time' => '12h'
    ];
    
    if (file_exists($dnsmasq_conf)) {
        $content = file_get_contents($dnsmasq_conf);
        
        if (preg_match('/interface=(\S+)/', $content, $m)) $config['interface'] = $m[1];
        if (preg_match('/dhcp-range=([^,]+),([^,]+),([^,]+),(\S+)/', $content, $m)) {
            $config['dhcp_start'] = $m[1];
            $config['dhcp_end'] = $m[2];
            $config['netmask'] = $m[3];
            $config['lease_time'] = $m[4];
        }
        if (preg_match('/dhcp-option=3,(\S+)/', $content, $m)) $config['gateway'] = $m[1];
        if (preg_match('/dhcp-option=6,([^,\s]+)(?:,([^,\s]+))?/', $content, $m)) {
            $config['dns1'] = $m[1];
            $config['dns2'] = $m[2] ?? '';
        }
    }
    
    return $config;
}

// Сохранение настроек dnsmasq
function saveDnsmasqConfig($config) {
    global $dnsmasq_conf;
    
    $content = "# MINE SERVER - dnsmasq configuration\n";
    $content .= "# Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $content .= "interface={$config['interface']}\n";
    $content .= "bind-interfaces\n";
    $content .= "dhcp-range={$config['dhcp_start']},{$config['dhcp_end']},{$config['netmask']},{$config['lease_time']}\n";
    $content .= "dhcp-option=3,{$config['gateway']}\n";
    
    $dns = $config['dns1'];
    if (!empty($config['dns2'])) $dns .= ",{$config['dns2']}";
    $content .= "dhcp-option=6,$dns\n\n";
    
    $content .= "# Logging\n";
    $content .= "log-queries\n";
    $content .= "log-dhcp\n\n";
    
    $content .= "# Static leases\n";
    $content .= "conf-dir=/etc/dnsmasq.d/,*.conf\n";
    
    file_put_contents($dnsmasq_conf, $content);
    shell_exec('sudo systemctl restart dnsmasq 2>&1');
    
    return true;
}

// Обновление IP сервера в netplan
function updateServerIP($new_ip, $interface) {
    global $netplan_file;
    
    if (!file_exists($netplan_file)) return false;
    
    // Вычисляем маску из IP (для 10.10.x.x используем /20)
    $mask = 20;
    if (preg_match('/^192\.168\./', $new_ip)) $mask = 24;
    if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $new_ip)) $mask = 16;
    
    $content = file_get_contents($netplan_file);
    
    // Простая замена IP для LAN интерфейса
    // Формат: addresses: [10.10.1.1/20]
    $content = preg_replace(
        '/(\s+' . preg_quote($interface, '/') . ':.*?addresses:\s*\[)[^\]]+(\])/s',
        '${1}' . $new_ip . '/' . $mask . '${2}',
        $content
    );
    
    file_put_contents($netplan_file, $content);
    shell_exec('sudo netplan apply 2>&1');
    
    return true;
}

// Получение статических leases
function getStaticLeases() {
    global $dnsmasq_static;
    $leases = [];
    
    if (file_exists($dnsmasq_static)) {
        $content = file_get_contents($dnsmasq_static);
        preg_match_all('/dhcp-host=([0-9a-f:]+),([0-9.]+)(?:,([^\n]+))?/i', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $leases[] = [
                'mac' => strtolower($m[1]),
                'ip' => $m[2],
                'hostname' => trim($m[3] ?? '')
            ];
        }
    }
    
    return $leases;
}

// Сохранение статических leases
function saveStaticLeases($leases) {
    global $dnsmasq_static;
    
    $dir = dirname($dnsmasq_static);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    
    $content = "# Static DHCP leases - MineServer\n";
    foreach ($leases as $l) {
        if (!empty($l['mac']) && !empty($l['ip'])) {
            $line = "dhcp-host={$l['mac']},{$l['ip']}";
            if (!empty($l['hostname'])) $line .= ",{$l['hostname']}";
            $content .= $line . "\n";
        }
    }
    
    file_put_contents($dnsmasq_static, $content);
    shell_exec('sudo systemctl reload dnsmasq 2>&1');
}

// Получение активных leases с hostname
function getActiveLeases() {
    global $dnsmasq_leases;
    $leases = [];
    
    if (file_exists($dnsmasq_leases)) {
        $lines = file($dnsmasq_leases, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 4) {
                $leases[] = [
                    'expire' => (int)$parts[0],
                    'mac' => strtolower($parts[1]),
                    'ip' => $parts[2],
                    'hostname' => $parts[3] !== '*' ? $parts[3] : ''
                ];
            }
        }
    }
    
    // Дополняем из ARP если нет в leases
    $arp = shell_exec('arp -an 2>/dev/null') ?: '';
    preg_match_all('/\(([0-9.]+)\)\s+at\s+([0-9a-f:]+)/i', $arp, $matches, PREG_SET_ORDER);
    
    $existing_ips = array_column($leases, 'ip');
    foreach ($matches as $m) {
        if (!in_array($m[1], $existing_ips) && $m[2] !== '(incomplete)') {
            $leases[] = [
                'expire' => 0,
                'mac' => strtolower($m[2]),
                'ip' => $m[1],
                'hostname' => ''
            ];
        }
    }
    
    return $leases;
}

// Определение LAN интерфейса
function getLanInterface() {
    global $netplan_file;
    
    if (file_exists($netplan_file)) {
        $content = file_get_contents($netplan_file);
        // Второй интерфейс обычно LAN
        preg_match_all('/^\s{4}(\w+):/m', $content, $matches);
        if (count($matches[1]) >= 2) {
            return $matches[1][1];
        }
    }
    
    // Fallback
    $interfaces = glob('/sys/class/net/*');
    foreach ($interfaces as $iface) {
        $name = basename($iface);
        if (preg_match('/^(eth|enp|ens)/', $name)) {
            return $name;
        }
    }
    
    return 'eth1';
}

// Обработка POST
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        
        // Сохранение настроек DHCP
        if (isset($_POST['save_dhcp'])) {
            $config = [
                'interface' => preg_replace('/[^a-z0-9]/', '', $_POST['interface'] ?? ''),
                'dhcp_start' => filter_var($_POST['dhcp_start'] ?? '', FILTER_VALIDATE_IP) ?: '10.10.1.2',
                'dhcp_end' => filter_var($_POST['dhcp_end'] ?? '', FILTER_VALIDATE_IP) ?: '10.10.15.254',
                'netmask' => filter_var($_POST['netmask'] ?? '', FILTER_VALIDATE_IP) ?: '255.255.240.0',
                'gateway' => filter_var($_POST['gateway'] ?? '', FILTER_VALIDATE_IP) ?: '10.10.1.1',
                'dns1' => filter_var($_POST['dns1'] ?? '', FILTER_VALIDATE_IP) ?: '8.8.8.8',
                'dns2' => filter_var($_POST['dns2'] ?? '', FILTER_VALIDATE_IP) ?: '',
                'lease_time' => preg_replace('/[^0-9hm]/', '', $_POST['lease_time'] ?? '12h')
            ];
            
            saveDnsmasqConfig($config);
            
            // Обновляем IP сервера если изменился gateway
            if (!empty($_POST['gateway'])) {
                updateServerIP($_POST['gateway'], $config['interface']);
            }
            
            $message = 'Настройки DHCP сохранены';
        }
        
        // Добавление статического lease
        if (isset($_POST['add_static'])) {
            $mac = trim($_POST['mac'] ?? '');
            $ip = trim($_POST['ip'] ?? '');
            $hostname = trim($_POST['hostname'] ?? '');
            
            if (preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $mac) && filter_var($ip, FILTER_VALIDATE_IP)) {
                $leases = getStaticLeases();
                
                // Проверка дубликатов
                $exists = false;
                foreach ($leases as $l) {
                    if ($l['mac'] === strtolower($mac) || $l['ip'] === $ip) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $leases[] = ['mac' => $mac, 'ip' => $ip, 'hostname' => $hostname];
                    saveStaticLeases($leases);
                    $message = 'Резервирование добавлено';
                } else {
                    $message = 'MAC или IP уже существует';
                    $messageType = 'error';
                }
            } else {
                $message = 'Неверный формат MAC или IP';
                $messageType = 'error';
            }
        }
        
        // Удаление статического lease
        if (isset($_POST['delete_static'])) {
            $mac = strtolower($_POST['delete_mac'] ?? '');
            $leases = getStaticLeases();
            $leases = array_filter($leases, fn($l) => $l['mac'] !== $mac);
            saveStaticLeases(array_values($leases));
            $message = 'Резервирование удалено';
        }
        
        // Закрепление из активных
        if (isset($_POST['pin_lease'])) {
            $mac = strtolower($_POST['pin_mac'] ?? '');
            $ip = $_POST['pin_ip'] ?? '';
            $hostname = $_POST['pin_hostname'] ?? '';
            
            $leases = getStaticLeases();
            if (!in_array($mac, array_column($leases, 'mac'))) {
                $leases[] = ['mac' => $mac, 'ip' => $ip, 'hostname' => $hostname];
                saveStaticLeases($leases);
                $message = "IP $ip закреплён";
            }
        }
        
        // Перезапуск DHCP
        if (isset($_POST['restart_dhcp'])) {
            shell_exec('sudo systemctl restart dnsmasq 2>&1');
            $message = 'DHCP сервер перезапущен';
        }
    }
}

$config = getDnsmasqConfig();
$staticLeases = getStaticLeases();
$activeLeases = getActiveLeases();
$lanInterface = getLanInterface();
$pinnedMacs = array_column($staticLeases, 'mac');
?>

<?php if ($message): ?>
<div class="p-4 rounded-xl border mb-4 <?= $messageType === 'error' ? 'bg-red-500/20 border-red-500/30 text-red-300' : 'bg-green-500/20 border-green-500/30 text-green-300' ?>">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<div class="space-y-6">
    
    <!-- Настройки DHCP сервера -->
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-xl font-bold text-white mb-6">Настройки DHCP сервера</h2>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                <div>
                    <label class="block text-sm text-slate-400 mb-1">IP сервера (Gateway)</label>
                    <input type="text" name="gateway" value="<?= htmlspecialchars($config['gateway']) ?>" 
                        class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white font-mono text-sm">
                </div>
                <div>
                    <label class="block text-sm text-slate-400 mb-1">Начало диапазона</label>
                    <input type="text" name="dhcp_start" value="<?= htmlspecialchars($config['dhcp_start']) ?>" 
                        class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white font-mono text-sm">
                </div>
                <div>
                    <label class="block text-sm text-slate-400 mb-1">Конец диапазона</label>
                    <input type="text" name="dhcp_end" value="<?= htmlspecialchars($config['dhcp_end']) ?>" 
                        class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white font-mono text-sm">
                </div>
                <div>
                    <label class="block text-sm text-slate-400 mb-1">Маска подсети</label>
                    <input type="text" name="netmask" value="<?= htmlspecialchars($config['netmask']) ?>" 
                        class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white font-mono text-sm">
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                <div>
                    <label class="block text-sm text-slate-400 mb-1">DNS 1</label>
                    <input type="text" name="dns1" value="<?= htmlspecialchars($config['dns1']) ?>" 
                        class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white font-mono text-sm">
                </div>
                <div>
                    <label class="block text-sm text-slate-400 mb-1">DNS 2 (опционально)</label>
                    <input type="text" name="dns2" value="<?= htmlspecialchars($config['dns2']) ?>" 
                        class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white font-mono text-sm">
                </div>
                <div>
                    <label class="block text-sm text-slate-400 mb-1">Время аренды</label>
                    <select name="lease_time" class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white text-sm">
                        <option value="1h" <?= $config['lease_time'] === '1h' ? 'selected' : '' ?>>1 час</option>
                        <option value="6h" <?= $config['lease_time'] === '6h' ? 'selected' : '' ?>>6 часов</option>
                        <option value="12h" <?= $config['lease_time'] === '12h' ? 'selected' : '' ?>>12 часов</option>
                        <option value="24h" <?= $config['lease_time'] === '24h' ? 'selected' : '' ?>>24 часа</option>
                        <option value="48h" <?= $config['lease_time'] === '48h' ? 'selected' : '' ?>>48 часов</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-slate-400 mb-1">Интерфейс</label>
                    <input type="text" name="interface" value="<?= htmlspecialchars($config['interface']) ?>" 
                        class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white font-mono text-sm">
                </div>
            </div>
            
            <input type="hidden" name="save_dhcp" value="1">
            <button type="submit" class="bg-violet-600 hover:bg-violet-700 text-white font-medium py-2 px-6 rounded-lg transition">
                Сохранить настройки
            </button>
        </form>
    </div>
    
    <!-- Резервирование IP -->
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-xl font-bold text-white mb-4">Резервирование IP адресов</h2>
        
        <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="text" name="mac" placeholder="aa:bb:cc:dd:ee:ff" required
                class="bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white font-mono text-sm">
            <input type="text" name="ip" placeholder="10.10.1.100" required
                class="bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white font-mono text-sm">
            <input type="text" name="hostname" placeholder="Имя устройства"
                class="bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white text-sm">
            <button type="submit" name="add_static" class="bg-violet-600 hover:bg-violet-700 text-white font-medium rounded-lg transition">
                Добавить
            </button>
        </form>
        
        <?php if (!empty($staticLeases)): ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 border-b border-slate-700">
                        <th class="pb-2">MAC</th>
                        <th class="pb-2">IP</th>
                        <th class="pb-2">Имя</th>
                        <th class="pb-2 w-16"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staticLeases as $lease): ?>
                    <tr class="border-b border-slate-800">
                        <td class="py-2 font-mono text-slate-300"><?= htmlspecialchars($lease['mac']) ?></td>
                        <td class="py-2 font-mono text-white"><?= htmlspecialchars($lease['ip']) ?></td>
                        <td class="py-2 text-slate-400"><?= htmlspecialchars($lease['hostname'] ?: '-') ?></td>
                        <td class="py-2">
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="delete_mac" value="<?= htmlspecialchars($lease['mac']) ?>">
                                <button type="submit" name="delete_static" class="text-red-400 hover:text-red-300">✕</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-slate-400 text-center py-4">Нет зарезервированных адресов</div>
        <?php endif; ?>
    </div>
    
    <!-- Активные клиенты -->
    <div class="glassmorphism rounded-2xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-bold text-white">Подключённые устройства</h2>
            <div class="flex gap-2">
                <span class="text-sm text-slate-400"><?= count($activeLeases) ?> устройств</span>
                <form method="POST" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <button type="submit" name="restart_dhcp" class="text-xs bg-slate-700 hover:bg-slate-600 px-2 py-1 rounded transition">
                        Обновить
                    </button>
                </form>
            </div>
        </div>
        
        <?php if (!empty($activeLeases)): ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 border-b border-slate-700">
                        <th class="pb-2">IP</th>
                        <th class="pb-2">MAC</th>
                        <th class="pb-2">Имя</th>
                        <th class="pb-2">Истекает</th>
                        <th class="pb-2 w-24"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeLeases as $lease): ?>
                    <?php $isPinned = in_array($lease['mac'], $pinnedMacs); ?>
                    <tr class="border-b border-slate-800">
                        <td class="py-2 font-mono text-white"><?= htmlspecialchars($lease['ip']) ?></td>
                        <td class="py-2 font-mono text-slate-300 text-xs"><?= htmlspecialchars($lease['mac']) ?></td>
                        <td class="py-2 text-slate-400"><?= htmlspecialchars($lease['hostname'] ?: '-') ?></td>
                        <td class="py-2 text-slate-500 text-xs">
                            <?= $lease['expire'] > 0 ? date('H:i d.m', $lease['expire']) : '-' ?>
                        </td>
                        <td class="py-2">
                            <?php if ($isPinned): ?>
                                <span class="text-green-400 text-xs">✓ Закреплён</span>
                            <?php else: ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="pin_mac" value="<?= htmlspecialchars($lease['mac']) ?>">
                                    <input type="hidden" name="pin_ip" value="<?= htmlspecialchars($lease['ip']) ?>">
                                    <input type="hidden" name="pin_hostname" value="<?= htmlspecialchars($lease['hostname']) ?>">
                                    <button type="submit" name="pin_lease" class="text-violet-400 hover:text-violet-300 text-xs">
                                        Закрепить
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-slate-400 text-center py-4">Нет активных клиентов</div>
        <?php endif; ?>
    </div>
</div>
