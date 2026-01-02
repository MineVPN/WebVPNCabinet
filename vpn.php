<?php
// ==============================================================================
// MINE SERVER - Управление VPN (OpenVPN + WireGuard)
// ==============================================================================

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

$csrf_token = $_SESSION['csrf_token'] ?? '';

// Пути к конфигам
$openvpn_config = '/etc/openvpn/tun0.conf';
$wireguard_config = '/etc/wireguard/tun0.conf';

// Определяем активный тип VPN
$active_type = null;
$config_content = '';
$server_ip = 'Не определен';

if (file_exists($wireguard_config)) {
    $active_type = 'wireguard';
    $config_content = file_get_contents($wireguard_config);
    if (preg_match('/Endpoint\s*=\s*([^:]+)/', $config_content, $m)) {
        $server_ip = $m[1];
    }
} elseif (file_exists($openvpn_config)) {
    $active_type = 'openvpn';
    $config_content = file_get_contents($openvpn_config);
    if (preg_match('/remote\s+([^\s]+)/', $config_content, $m)) {
        $server_ip = $m[1];
    }
}

// Проверка статуса tun0
$tun0_status = 'down';
$status_output = shell_exec("ip link show tun0 2>&1");
if ($status_output && strpos($status_output, 'state UP') !== false) {
    $tun0_status = 'up';
} elseif ($status_output && strpos($status_output, 'state UNKNOWN') !== false) {
    $tun0_status = 'up'; // WireGuard показывает UNKNOWN
}

// Настройки healthcheck
$settings_file = '/var/www/settings';
$autoupvpn = true;
if (file_exists($settings_file)) {
    $settings = file_get_contents($settings_file);
    $autoupvpn = strpos($settings, 'autoupvpn=false') === false;
}

// Обработка POST
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        
        // Запуск VPN
        if (isset($_POST['vpn_start'])) {
            if ($active_type === 'wireguard') {
                shell_exec('sudo systemctl start wg-quick@tun0 2>&1');
            } elseif ($active_type === 'openvpn') {
                shell_exec('sudo systemctl start openvpn@tun0 2>&1');
            }
            sleep(3);
            header("Location: cabinet.php?menu=vpn");
            exit();
        }
        
        // Остановка VPN
        if (isset($_POST['vpn_stop'])) {
            shell_exec('sudo systemctl stop wg-quick@tun0 2>&1');
            shell_exec('sudo systemctl stop openvpn@tun0 2>&1');
            sleep(2);
            header("Location: cabinet.php?menu=vpn");
            exit();
        }
        
        // Удаление конфига
        if (isset($_POST['vpn_delete'])) {
            shell_exec('sudo systemctl stop wg-quick@tun0 2>&1');
            shell_exec('sudo systemctl stop openvpn@tun0 2>&1');
            shell_exec('sudo systemctl disable wg-quick@tun0 2>&1');
            shell_exec('sudo systemctl disable openvpn@tun0 2>&1');
            @unlink($wireguard_config);
            @unlink($openvpn_config);
            $message = 'Конфигурация удалена';
            $active_type = null;
        }
        
        // Загрузка конфига
        if (isset($_FILES['config_file']) && $_FILES['config_file']['error'] === UPLOAD_ERR_OK) {
            $filename = $_FILES['config_file']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            // Останавливаем текущие VPN
            shell_exec('sudo systemctl stop wg-quick@tun0 2>&1');
            shell_exec('sudo systemctl stop openvpn@tun0 2>&1');
            shell_exec('sudo systemctl disable wg-quick@tun0 2>&1');
            shell_exec('sudo systemctl disable openvpn@tun0 2>&1');
            
            // Удаляем старые конфиги
            @unlink($wireguard_config);
            @unlink($openvpn_config);
            
            if ($ext === 'conf') {
                // WireGuard
                move_uploaded_file($_FILES['config_file']['tmp_name'], $wireguard_config);
                chmod($wireguard_config, 0600);
                shell_exec('sudo systemctl enable wg-quick@tun0 2>&1');
                shell_exec('sudo systemctl start wg-quick@tun0 2>&1');
                $message = 'WireGuard конфигурация установлена';
                $active_type = 'wireguard';
            } elseif ($ext === 'ovpn') {
                // OpenVPN
                move_uploaded_file($_FILES['config_file']['tmp_name'], $openvpn_config);
                chmod($openvpn_config, 0600);
                shell_exec('sudo systemctl daemon-reload 2>&1');
                shell_exec('sudo systemctl enable openvpn@tun0 2>&1');
                shell_exec('sudo systemctl start openvpn@tun0 2>&1');
                $message = 'OpenVPN конфигурация установлена';
                $active_type = 'openvpn';
            } else {
                $message = 'Неподдерживаемый формат. Используйте .conf (WireGuard) или .ovpn (OpenVPN)';
                $messageType = 'error';
            }
            
            sleep(3);
            header("Location: cabinet.php?menu=vpn");
            exit();
        }
    }
}

// Перечитываем статус после действий
$status_output = shell_exec("ip link show tun0 2>&1");
$tun0_status = 'down';
if ($status_output && (strpos($status_output, 'state UP') !== false || strpos($status_output, 'state UNKNOWN') !== false)) {
    $tun0_status = 'up';
}
?>

<?php if ($message): ?>
<div class="p-4 rounded-xl border mb-4 <?= $messageType === 'error' ? 'bg-red-500/20 border-red-500/30 text-red-300' : 'bg-green-500/20 border-green-500/30 text-green-300' ?>">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- Статус VPN -->
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-xl font-bold text-white mb-6">Статус VPN</h2>
        
        <?php if ($active_type): ?>
            <div class="space-y-4">
                <div class="flex justify-between items-center">
                    <span class="text-slate-400">Тип:</span>
                    <span class="text-white font-bold"><?= $active_type === 'wireguard' ? 'WireGuard' : 'OpenVPN' ?></span>
                </div>
                
                <div class="flex justify-between items-center">
                    <span class="text-slate-400">Сервер:</span>
                    <span class="text-white font-mono"><?= htmlspecialchars($server_ip) ?></span>
                </div>
                
                <div class="flex justify-between items-center">
                    <span class="text-slate-400">Статус:</span>
                    <?php if ($tun0_status === 'up'): ?>
                        <span class="bg-green-500/20 text-green-400 px-3 py-1 rounded-full text-sm font-medium">Подключен</span>
                    <?php else: ?>
                        <span class="bg-red-500/20 text-red-400 px-3 py-1 rounded-full text-sm font-medium">Отключен</span>
                    <?php endif; ?>
                </div>
                
                <div class="flex justify-between items-center">
                    <span class="text-slate-400">Ping:</span>
                    <span id="vpn-ping" class="text-white font-mono">--</span>
                </div>
                
                <div class="flex justify-between items-center">
                    <span class="text-slate-400">Автовосстановление:</span>
                    <span class="<?= $autoupvpn ? 'text-green-400' : 'text-red-400' ?>"><?= $autoupvpn ? 'Включено' : 'Выключено' ?></span>
                </div>
            </div>
            
            <div class="mt-6 space-y-2">
                <form method="POST" class="flex gap-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <?php if ($tun0_status === 'up'): ?>
                        <button type="submit" name="vpn_stop" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-medium py-2 rounded-lg transition">
                            Остановить
                        </button>
                    <?php else: ?>
                        <button type="submit" name="vpn_start" class="flex-1 bg-green-600 hover:bg-green-700 text-white font-medium py-2 rounded-lg transition">
                            Запустить
                        </button>
                    <?php endif; ?>
                    <button type="submit" name="vpn_delete" onclick="return confirm('Удалить конфигурацию VPN?')" class="px-4 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition">
                        🗑️
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="text-center py-8">
                <div class="text-6xl mb-4">🔒</div>
                <div class="text-slate-400">VPN не настроен</div>
                <div class="text-slate-500 text-sm mt-2">Загрузите конфигурацию справа</div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Загрузка конфига -->
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-xl font-bold text-white mb-6">Загрузка конфигурации</h2>
        
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            
            <label id="drop-zone" class="flex flex-col items-center justify-center w-full h-48 border-2 border-dashed border-slate-600 rounded-xl cursor-pointer hover:border-violet-500 transition-colors">
                <div class="flex flex-col items-center justify-center py-6">
                    <svg class="w-12 h-12 mb-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                    <p class="mb-2 text-sm text-slate-400" id="drop-text">
                        <span class="font-semibold">Нажмите для выбора</span> или перетащите файл
                    </p>
                    <p class="text-xs text-slate-500">.conf (WireGuard) или .ovpn (OpenVPN)</p>
                </div>
                <input type="file" name="config_file" id="config_file" accept=".conf,.ovpn" class="hidden">
            </label>
            
            <button type="submit" class="w-full mt-4 bg-violet-600 hover:bg-violet-700 text-white font-medium py-3 rounded-lg transition">
                Загрузить и активировать
            </button>
        </form>
    </div>
</div>

<!-- График пинга -->
<?php if ($active_type && $tun0_status === 'up'): ?>
<div class="glassmorphism rounded-2xl p-6 mt-6">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-bold text-white">История пинга</h3>
        <div class="flex gap-4 text-sm">
            <span>Min: <span id="ping-min" class="text-green-400">--</span></span>
            <span>Avg: <span id="ping-avg" class="text-blue-400">--</span></span>
            <span>Max: <span id="ping-max" class="text-red-400">--</span></span>
            <span>Loss: <span id="ping-loss" class="text-yellow-400">--%</span></span>
        </div>
    </div>
    <div class="h-32 bg-slate-800/50 rounded-lg relative overflow-hidden">
        <canvas id="ping-chart" class="w-full h-full"></canvas>
    </div>
</div>
<?php endif; ?>

<script>
// Drag & Drop
const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('config_file');
const dropText = document.getElementById('drop-text');

if (dropZone) {
    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('border-violet-500'); });
    dropZone.addEventListener('dragleave', e => { e.preventDefault(); dropZone.classList.remove('border-violet-500'); });
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('border-violet-500');
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            dropText.innerHTML = '<span class="text-green-400">✓ ' + e.dataTransfer.files[0].name + '</span>';
        }
    });
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) {
            dropText.innerHTML = '<span class="text-green-400">✓ ' + fileInput.files[0].name + '</span>';
        }
    });
}

// Ping обновление
let pingHistory = [];
const maxHistory = 60;

async function updatePing() {
    try {
        const r = await fetch('ping.php?host=8.8.8.8&interface=tun0');
        const data = await r.text();
        const el = document.getElementById('vpn-ping');
        
        if (data.indexOf('NO PING') === -1) {
            const ping = Math.round(parseFloat(data));
            el.textContent = ping + ' мс';
            el.className = 'font-mono ' + (ping < 50 ? 'text-green-400' : ping < 100 ? 'text-yellow-400' : 'text-red-400');
            pingHistory.push(ping);
        } else {
            el.textContent = 'X';
            el.className = 'text-red-400 font-mono';
            pingHistory.push(null);
        }
        
        if (pingHistory.length > maxHistory) pingHistory.shift();
        updateStats();
        drawChart();
    } catch (e) {
        pingHistory.push(null);
        if (pingHistory.length > maxHistory) pingHistory.shift();
    }
}

function updateStats() {
    const valid = pingHistory.filter(p => p !== null);
    if (valid.length === 0) return;
    
    document.getElementById('ping-min').textContent = Math.min(...valid) + ' мс';
    document.getElementById('ping-avg').textContent = Math.round(valid.reduce((a,b) => a+b, 0) / valid.length) + ' мс';
    document.getElementById('ping-max').textContent = Math.max(...valid) + ' мс';
    document.getElementById('ping-loss').textContent = Math.round((pingHistory.length - valid.length) / pingHistory.length * 100) + '%';
}

function drawChart() {
    const canvas = document.getElementById('ping-chart');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    const rect = canvas.parentElement.getBoundingClientRect();
    canvas.width = rect.width;
    canvas.height = rect.height;
    
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
    const valid = pingHistory.filter(p => p !== null);
    if (valid.length < 2) return;
    
    const min = Math.max(0, Math.min(...valid) - 10);
    const max = Math.max(...valid) + 10;
    const range = max - min || 1;
    
    // Gradient fill
    const gradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
    gradient.addColorStop(0, 'rgba(139, 92, 246, 0.3)');
    gradient.addColorStop(1, 'rgba(139, 92, 246, 0)');
    
    ctx.beginPath();
    ctx.moveTo(0, canvas.height);
    
    let lastX = 0, lastY = canvas.height;
    for (let i = 0; i < pingHistory.length; i++) {
        const x = (canvas.width / (maxHistory - 1)) * i;
        if (pingHistory[i] !== null) {
            const y = canvas.height - ((pingHistory[i] - min) / range) * canvas.height;
            ctx.lineTo(x, y);
            lastX = x;
            lastY = y;
        }
    }
    
    ctx.lineTo(lastX, canvas.height);
    ctx.closePath();
    ctx.fillStyle = gradient;
    ctx.fill();
    
    // Line
    ctx.beginPath();
    let first = true;
    for (let i = 0; i < pingHistory.length; i++) {
        if (pingHistory[i] !== null) {
            const x = (canvas.width / (maxHistory - 1)) * i;
            const y = canvas.height - ((pingHistory[i] - min) / range) * canvas.height;
            if (first) { ctx.moveTo(x, y); first = false; }
            else ctx.lineTo(x, y);
        }
    }
    ctx.strokeStyle = '#8b5cf6';
    ctx.lineWidth = 2;
    ctx.stroke();
}

<?php if ($active_type && $tun0_status === 'up'): ?>
setInterval(updatePing, 1000);
updatePing();
<?php endif; ?>
</script>
