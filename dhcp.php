<?php
// ==============================================================================
// MINE SERVER - Управление DHCP
// ==============================================================================

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

$csrf_token = $_SESSION['csrf_token'] ?? '';
$dnsmasq_static_file = '/etc/dnsmasq.d/static-leases.conf';
$dnsmasq_leases_file = '/var/lib/misc/dnsmasq.leases';

// Функция чтения статических записей
function getStaticLeases() {
    global $dnsmasq_static_file;
    $leases = [];
    
    if (file_exists($dnsmasq_static_file)) {
        $content = file_get_contents($dnsmasq_static_file);
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            // Формат: dhcp-host=MAC,IP,hostname
            if (preg_match('/dhcp-host=([0-9a-f:]+),([0-9.]+)(?:,(.+))?/i', $line, $m)) {
                $leases[] = [
                    'mac' => strtolower($m[1]),
                    'ip' => $m[2],
                    'hostname' => $m[3] ?? ''
                ];
            }
        }
    }
    
    return $leases;
}

// Функция сохранения статических записей
function saveStaticLeases($leases) {
    global $dnsmasq_static_file;
    
    $content = "# Static DHCP leases - MineServer\n";
    $content .= "# Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    foreach ($leases as $lease) {
        $mac = strtolower(trim($lease['mac']));
        $ip = trim($lease['ip']);
        $hostname = trim($lease['hostname'] ?? '');
        
        if (!empty($mac) && !empty($ip)) {
            $content .= "dhcp-host=$mac,$ip";
            if (!empty($hostname)) {
                $content .= ",$hostname";
            }
            $content .= "\n";
        }
    }
    
    // Создаём директорию если нет
    $dir = dirname($dnsmasq_static_file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    file_put_contents($dnsmasq_static_file, $content);
    
    // Перезагружаем dnsmasq
    shell_exec('sudo systemctl reload dnsmasq 2>/dev/null || sudo systemctl restart dnsmasq 2>/dev/null');
    
    return true;
}

// Функция получения активных leases
function getActiveLeases() {
    global $dnsmasq_leases_file;
    $leases = [];
    
    if (file_exists($dnsmasq_leases_file)) {
        $content = file_get_contents($dnsmasq_leases_file);
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 4) {
                $leases[] = [
                    'expire' => (int)$parts[0],
                    'mac' => strtolower($parts[1]),
                    'ip' => $parts[2],
                    'hostname' => $parts[3] !== '*' ? $parts[3] : '',
                    'client_id' => $parts[4] ?? ''
                ];
            }
        }
    }
    
    return $leases;
}

// Обработка POST
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        
        // Добавление статического lease
        if (isset($_POST['add_static'])) {
            $mac = trim($_POST['mac'] ?? '');
            $ip = trim($_POST['ip'] ?? '');
            $hostname = trim($_POST['hostname'] ?? '');
            
            // Валидация MAC
            if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $mac)) {
                $message = 'Неверный формат MAC адреса';
                $messageType = 'error';
            }
            // Валидация IP
            elseif (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $message = 'Неверный формат IP адреса';
                $messageType = 'error';
            }
            else {
                $leases = getStaticLeases();
                
                // Проверка на дубликаты
                $duplicate = false;
                foreach ($leases as $lease) {
                    if ($lease['mac'] === strtolower($mac) || $lease['ip'] === $ip) {
                        $duplicate = true;
                        break;
                    }
                }
                
                if ($duplicate) {
                    $message = 'Такой MAC или IP уже существует';
                    $messageType = 'error';
                } else {
                    $leases[] = ['mac' => $mac, 'ip' => $ip, 'hostname' => $hostname];
                    saveStaticLeases($leases);
                    $message = 'Статический lease добавлен';
                }
            }
        }
        
        // Удаление статического lease
        if (isset($_POST['delete_static'])) {
            $mac = strtolower(trim($_POST['delete_mac'] ?? ''));
            $leases = getStaticLeases();
            $newLeases = array_filter($leases, fn($l) => $l['mac'] !== $mac);
            
            if (count($newLeases) < count($leases)) {
                saveStaticLeases(array_values($newLeases));
                $message = 'Статический lease удалён';
            }
        }
        
        // Закрепление из активных
        if (isset($_POST['pin_lease'])) {
            $mac = strtolower(trim($_POST['pin_mac'] ?? ''));
            $ip = trim($_POST['pin_ip'] ?? '');
            $hostname = trim($_POST['pin_hostname'] ?? '');
            
            if (!empty($mac) && !empty($ip)) {
                $leases = getStaticLeases();
                
                // Проверка на дубликаты
                $exists = false;
                foreach ($leases as $lease) {
                    if ($lease['mac'] === $mac) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $leases[] = ['mac' => $mac, 'ip' => $ip, 'hostname' => $hostname];
                    saveStaticLeases($leases);
                    $message = "IP $ip закреплён за $mac";
                } else {
                    $message = 'Этот MAC уже закреплён';
                    $messageType = 'error';
                }
            }
        }
        
        // Перезапуск DHCP
        if (isset($_POST['restart_dhcp'])) {
            shell_exec('sudo systemctl restart dnsmasq 2>&1');
            $message = 'DHCP сервер перезапущен';
        }
    }
}

$staticLeases = getStaticLeases();
$activeLeases = getActiveLeases();

// Определяем какие активные leases уже закреплены
$pinnedMacs = array_column($staticLeases, 'mac');
?>

<div class="space-y-6">
    
    <!-- Сообщения -->
    <?php if ($message): ?>
        <div class="p-4 rounded-xl border <?= $messageType === 'error' ? 'bg-red-500/20 border-red-500/30 text-red-300' : 'bg-green-500/20 border-green-500/30 text-green-300' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    
    <!-- Добавление статического lease -->
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-xl font-bold text-white mb-6">Добавить статический IP</h2>
        
        <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            
            <div>
                <label class="block text-sm text-slate-400 mb-1">MAC адрес</label>
                <input type="text" name="mac" placeholder="aa:bb:cc:dd:ee:ff" required
                    pattern="([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}"
                    class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white font-mono text-sm focus:ring-2 focus:ring-violet-500 focus:outline-none">
            </div>
            
            <div>
                <label class="block text-sm text-slate-400 mb-1">IP адрес</label>
                <input type="text" name="ip" placeholder="10.10.1.100" required
                    pattern="\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}"
                    class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white font-mono text-sm focus:ring-2 focus:ring-violet-500 focus:outline-none">
            </div>
            
            <div>
                <label class="block text-sm text-slate-400 mb-1">Имя (опционально)</label>
                <input type="text" name="hostname" placeholder="my-device"
                    class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white text-sm focus:ring-2 focus:ring-violet-500 focus:outline-none">
            </div>
            
            <div class="flex items-end">
                <button type="submit" name="add_static" class="w-full bg-violet-600 hover:bg-violet-700 text-white font-medium py-2 px-4 rounded-lg transition">
                    Добавить
                </button>
            </div>
        </form>
    </div>
    
    <!-- Статические leases -->
    <div class="glassmorphism rounded-2xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-white">Закреплённые IP адреса</h2>
            <span class="text-sm text-slate-400"><?= count($staticLeases) ?> записей</span>
        </div>
        
        <?php if (empty($staticLeases)): ?>
            <div class="text-slate-400 text-center py-8">Нет закреплённых адресов</div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="text-left text-slate-400 text-sm border-b border-slate-700">
                            <th class="pb-3 font-medium">MAC адрес</th>
                            <th class="pb-3 font-medium">IP адрес</th>
                            <th class="pb-3 font-medium">Имя</th>
                            <th class="pb-3 font-medium w-20">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <?php foreach ($staticLeases as $lease): ?>
                            <tr class="border-b border-slate-800">
                                <td class="py-3 font-mono text-slate-300"><?= htmlspecialchars($lease['mac']) ?></td>
                                <td class="py-3 font-mono text-white"><?= htmlspecialchars($lease['ip']) ?></td>
                                <td class="py-3 text-slate-400"><?= htmlspecialchars($lease['hostname'] ?: '-') ?></td>
                                <td class="py-3">
                                    <form method="POST" class="inline" onsubmit="return confirm('Удалить закрепление?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="delete_mac" value="<?= htmlspecialchars($lease['mac']) ?>">
                                        <button type="submit" name="delete_static" class="text-red-400 hover:text-red-300 transition">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Активные leases -->
    <div class="glassmorphism rounded-2xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-white">Активные DHCP клиенты</h2>
            <div class="flex items-center gap-2">
                <span class="text-sm text-slate-400"><?= count($activeLeases) ?> устройств</span>
                <button onclick="location.reload()" class="bg-slate-700 hover:bg-slate-600 px-3 py-1 rounded text-sm transition">
                    Обновить
                </button>
            </div>
        </div>
        
        <?php if (empty($activeLeases)): ?>
            <div class="text-slate-400 text-center py-8">Нет активных клиентов</div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="text-left text-slate-400 text-sm border-b border-slate-700">
                            <th class="pb-3 font-medium">IP адрес</th>
                            <th class="pb-3 font-medium">MAC адрес</th>
                            <th class="pb-3 font-medium">Имя</th>
                            <th class="pb-3 font-medium">Истекает</th>
                            <th class="pb-3 font-medium w-24">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <?php foreach ($activeLeases as $lease): ?>
                            <?php 
                            $isPinned = in_array($lease['mac'], $pinnedMacs);
                            $expireTime = $lease['expire'] > 0 ? date('H:i d.m', $lease['expire']) : 'Бессрочно';
                            $isExpired = $lease['expire'] > 0 && $lease['expire'] < time();
                            ?>
                            <tr class="border-b border-slate-800 <?= $isExpired ? 'opacity-50' : '' ?>">
                                <td class="py-3 font-mono text-white"><?= htmlspecialchars($lease['ip']) ?></td>
                                <td class="py-3 font-mono text-slate-300"><?= htmlspecialchars($lease['mac']) ?></td>
                                <td class="py-3 text-slate-400"><?= htmlspecialchars($lease['hostname'] ?: '-') ?></td>
                                <td class="py-3 text-slate-500 text-xs"><?= $expireTime ?></td>
                                <td class="py-3">
                                    <?php if ($isPinned): ?>
                                        <span class="text-green-400 text-xs">Закреплён</span>
                                    <?php else: ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="pin_mac" value="<?= htmlspecialchars($lease['mac']) ?>">
                                            <input type="hidden" name="pin_ip" value="<?= htmlspecialchars($lease['ip']) ?>">
                                            <input type="hidden" name="pin_hostname" value="<?= htmlspecialchars($lease['hostname']) ?>">
                                            <button type="submit" name="pin_lease" class="text-violet-400 hover:text-violet-300 text-xs transition">
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
        <?php endif; ?>
    </div>
    
    <!-- Управление DHCP -->
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-xl font-bold text-white mb-6">Управление DHCP сервером</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <button type="submit" name="restart_dhcp" class="w-full bg-slate-700 hover:bg-slate-600 text-white font-medium py-3 px-4 rounded-lg transition flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    Перезапустить DHCP
                </button>
            </form>
            
            <div class="bg-slate-800/50 rounded-lg p-4">
                <div class="text-slate-400 text-sm">Диапазон DHCP</div>
                <div class="text-white font-mono">10.10.1.2 - 10.10.15.254</div>
            </div>
            
            <div class="bg-slate-800/50 rounded-lg p-4">
                <div class="text-slate-400 text-sm">DNS серверы</div>
                <div class="text-white font-mono">8.8.8.8, 1.1.1.1</div>
            </div>
        </div>
    </div>
    
</div>
