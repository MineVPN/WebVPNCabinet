<?php
// ==============================================================================
// MINE SERVER - Настройки сети
// ==============================================================================

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

$csrf_token = $_SESSION['csrf_token'] ?? '';

// Обработка POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        
        if (isset($_POST['apply_netplan'])) {
            $output = shell_exec('sudo netplan apply 2>&1');
            echo "<script>Notice('Сетевые настройки применены!');</script>";
        }
        
        if (isset($_POST['restart_dnsmasq'])) {
            shell_exec('sudo systemctl restart dnsmasq');
            echo "<script>Notice('DNS/DHCP сервер перезапущен!');</script>";
        }
    }
}
?>

<div class="space-y-8">
    
    <!-- Внешний IP -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="glassmorphism rounded-2xl p-6">
            <h3 class="text-lg font-bold text-white mb-4">Реальный IP (без VPN)</h3>
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-slate-700 rounded-xl flex items-center justify-center">
                    <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path>
                    </svg>
                </div>
                <div>
                    <div class="text-2xl font-bold text-white font-mono" id="real-ip">Загрузка...</div>
                    <div class="text-slate-400 text-sm">Ваш провайдер</div>
                </div>
            </div>
        </div>
        
        <div class="glassmorphism rounded-2xl p-6">
            <h3 class="text-lg font-bold text-white mb-4">VPN IP (через туннель)</h3>
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-500/20 rounded-xl flex items-center justify-center">
                    <svg class="w-6 h-6 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                </div>
                <div>
                    <div class="text-2xl font-bold text-green-400 font-mono" id="vpn-ip">Загрузка...</div>
                    <div class="text-slate-400 text-sm">VPN сервер</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Сетевые интерфейсы -->
    <div class="glassmorphism rounded-2xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-white">Сетевые интерфейсы</h2>
            <button onclick="refreshInterfaces()" class="bg-slate-700 hover:bg-slate-600 px-4 py-2 rounded-lg text-sm transition">
                Обновить
            </button>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="text-left text-slate-400 text-sm border-b border-slate-700">
                        <th class="pb-3 font-medium">Интерфейс</th>
                        <th class="pb-3 font-medium">Тип</th>
                        <th class="pb-3 font-medium">IP адрес</th>
                        <th class="pb-3 font-medium">MAC</th>
                        <th class="pb-3 font-medium">Статус</th>
                        <th class="pb-3 font-medium">Трафик ↓/↑</th>
                    </tr>
                </thead>
                <tbody id="interfaces-table" class="text-sm">
                    <tr><td colspan="6" class="py-4 text-slate-400">Загрузка...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Подключённые устройства -->
    <div class="glassmorphism rounded-2xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-white">Устройства в локальной сети</h2>
            <button onclick="refreshDevices()" class="bg-slate-700 hover:bg-slate-600 px-4 py-2 rounded-lg text-sm transition">
                Сканировать
            </button>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="text-left text-slate-400 text-sm border-b border-slate-700">
                        <th class="pb-3 font-medium">IP адрес</th>
                        <th class="pb-3 font-medium">MAC адрес</th>
                        <th class="pb-3 font-medium">Hostname</th>
                        <th class="pb-3 font-medium">Производитель</th>
                    </tr>
                </thead>
                <tbody id="devices-table" class="text-sm">
                    <tr><td colspan="4" class="py-4 text-slate-400">Загрузка...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Действия -->
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-xl font-bold text-white mb-6">Управление сетью</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <button type="submit" name="apply_netplan" class="w-full bg-violet-600 hover:bg-violet-700 text-white font-medium py-3 px-4 rounded-lg transition">
                    Применить Netplan
                </button>
            </form>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <button type="submit" name="restart_dnsmasq" class="w-full bg-slate-700 hover:bg-slate-600 text-white font-medium py-3 px-4 rounded-lg transition">
                    Перезапустить DNS/DHCP
                </button>
            </form>
            
            <button onclick="runSpeedtest()" id="speedtest-btn" class="w-full bg-slate-700 hover:bg-slate-600 text-white font-medium py-3 px-4 rounded-lg transition">
                Тест скорости
            </button>
        </div>
        
        <!-- Результат speedtest -->
        <div id="speedtest-result" class="hidden mt-6 p-4 bg-slate-800/50 rounded-xl">
            <div class="grid grid-cols-3 gap-4 text-center">
                <div>
                    <div class="text-slate-400 text-sm">Ping</div>
                    <div class="text-2xl font-bold text-white" id="st-ping">--</div>
                </div>
                <div>
                    <div class="text-slate-400 text-sm">Download</div>
                    <div class="text-2xl font-bold text-green-400" id="st-download">--</div>
                </div>
                <div>
                    <div class="text-slate-400 text-sm">Upload</div>
                    <div class="text-2xl font-bold text-blue-400" id="st-upload">--</div>
                </div>
            </div>
        </div>
    </div>
    
</div>

<script>
// Загрузка IP адресов
async function loadIPs() {
    try {
        const response = await fetch('api/network.php?action=all');
        const data = await response.json();
        
        document.getElementById('real-ip').textContent = data.external_ip || 'Не определён';
        document.getElementById('vpn-ip').textContent = data.external_ip_vpn || 'VPN не активен';
        
        if (data.external_ip_vpn) {
            document.getElementById('vpn-ip').classList.remove('text-slate-400');
            document.getElementById('vpn-ip').classList.add('text-green-400');
        } else {
            document.getElementById('vpn-ip').classList.remove('text-green-400');
            document.getElementById('vpn-ip').classList.add('text-slate-400');
        }
    } catch (error) {
        console.error('Error loading IPs:', error);
    }
}

// Загрузка интерфейсов
async function refreshInterfaces() {
    try {
        const response = await fetch('api/network.php?action=interfaces');
        const interfaces = await response.json();
        
        const tbody = document.getElementById('interfaces-table');
        tbody.innerHTML = '';
        
        interfaces.forEach(iface => {
            const statusClass = iface.status === 'up' ? 'bg-green-500' : 'bg-red-500';
            const statusText = iface.status === 'up' ? 'UP' : 'DOWN';
            
            tbody.innerHTML += `
                <tr class="border-b border-slate-800">
                    <td class="py-3 font-mono text-white">${iface.name}</td>
                    <td class="py-3 text-slate-400">${iface.type}</td>
                    <td class="py-3 font-mono text-slate-300">${iface.ipv4 || '-'}</td>
                    <td class="py-3 font-mono text-slate-400 text-xs">${iface.mac || '-'}</td>
                    <td class="py-3">
                        <span class="px-2 py-1 rounded text-xs ${statusClass}/20 text-${iface.status === 'up' ? 'green' : 'red'}-400">${statusText}</span>
                    </td>
                    <td class="py-3 text-slate-400">${iface.rx_formatted} / ${iface.tx_formatted}</td>
                </tr>
            `;
        });
    } catch (error) {
        console.error('Error loading interfaces:', error);
    }
}

// Загрузка устройств
async function refreshDevices() {
    const tbody = document.getElementById('devices-table');
    tbody.innerHTML = '<tr><td colspan="4" class="py-4 text-slate-400">Сканирование сети...</td></tr>';
    
    try {
        const response = await fetch('api/network.php?action=devices');
        const devices = await response.json();
        
        tbody.innerHTML = '';
        
        if (devices.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="py-4 text-slate-400">Устройства не найдены</td></tr>';
            return;
        }
        
        devices.forEach(device => {
            tbody.innerHTML += `
                <tr class="border-b border-slate-800">
                    <td class="py-3 font-mono text-white">${device.ip}</td>
                    <td class="py-3 font-mono text-slate-400 text-xs">${device.mac}</td>
                    <td class="py-3 text-slate-300">${device.hostname || '-'}</td>
                    <td class="py-3 text-slate-400">${device.vendor || '-'}</td>
                </tr>
            `;
        });
    } catch (error) {
        console.error('Error loading devices:', error);
        tbody.innerHTML = '<tr><td colspan="4" class="py-4 text-red-400">Ошибка загрузки</td></tr>';
    }
}

// Speedtest
async function runSpeedtest() {
    const btn = document.getElementById('speedtest-btn');
    const result = document.getElementById('speedtest-result');
    
    btn.disabled = true;
    btn.textContent = 'Тестирование...';
    result.classList.remove('hidden');
    
    document.getElementById('st-ping').textContent = '...';
    document.getElementById('st-download').textContent = '...';
    document.getElementById('st-upload').textContent = '...';
    
    try {
        const response = await fetch('api/server.php?action=speedtest', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: window.CSRF_TOKEN })
        });
        const data = await response.json();
        
        if (data.error) {
            Notice(data.error, 'error');
        } else {
            document.getElementById('st-ping').textContent = data.ping ? data.ping + ' мс' : '--';
            document.getElementById('st-download').textContent = data.download ? data.download + ' Мбит/с' : '--';
            document.getElementById('st-upload').textContent = data.upload ? data.upload + ' Мбит/с' : '--';
        }
    } catch (error) {
        Notice('Ошибка теста скорости', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Тест скорости';
    }
}

// Инициализация
document.addEventListener('DOMContentLoaded', () => {
    loadIPs();
    refreshInterfaces();
    refreshDevices();
});
</script>
