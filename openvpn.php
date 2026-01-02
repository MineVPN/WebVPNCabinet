<?php
// ==============================================================================
// MINE SERVER - Управление OpenVPN
// ==============================================================================

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

// --- ОПРЕДЕЛЕНИЕ СТАТУСА ---
$openvpn_config_path = '/etc/openvpn/tun0.conf';
$wireguard_config_path = '/etc/wireguard/tun0.conf';
$type = null;
$connection_status = 'disconnected';
$ip_address = 'Не определен';
$config_type = 'Нет';

if (file_exists($openvpn_config_path)) {
    $openvpn_config_content = file_get_contents($openvpn_config_path);
    if (preg_match('/^\s*remote\s+([^\s]+)/m', $openvpn_config_content, $matches)) {
        $ip_address = $matches[1];
        $config_type = "OpenVPN";
        $type = "openvpn";
    }
}

if (file_exists($wireguard_config_path)) {
    $wireguard_config_content = file_get_contents($wireguard_config_path);
    if (preg_match('/^\s*Endpoint\s*=\s*([\d\.]+):\d+/m', $wireguard_config_content, $matches)) {
        $ip_address = $matches[1];
        $config_type = "WireGuard";
        $type = "wireguard";
    }
}

$status_output = shell_exec("ifconfig tun0 2>&1");
if (strpos($status_output, 'Device not found') === false && strpos($status_output, 'error') === false) {
    $connection_status = 'connected';
}

$settings_file_path = '../settings';
$autostart_status_text = 'Выключен';
if (file_exists($settings_file_path) && is_readable($settings_file_path)) {
    $settings_content = file_get_contents($settings_file_path);
    if (strpos($settings_content, 'autoupvpn=true') !== false) {
        $autostart_status_text = 'Включен';
    }
}

// --- ОБРАБОТКА ФОРМ ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Проверка CSRF для действий
    $csrf_valid = isset($_POST['csrf_token']) && isset($_SESSION['csrf_token']) 
                  && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
    
    if (isset($_POST['openvpn_start']) && $csrf_valid) {
        shell_exec("sudo systemctl start openvpn@tun0");
        sleep(5);
        exit();
    }
    
    if (isset($_POST['openvpn_stop']) && $csrf_valid) {
        shell_exec("sudo systemctl stop openvpn@tun0");
        sleep(3);
        exit();
    }
    
    // Загрузка конфигурации
    if (isset($_FILES["config_file"]) && $csrf_valid) {
        if (!empty($_FILES["config_file"]["name"])) {
            $allowed_extensions = array('ovpn');
            $file_extension = strtolower(pathinfo($_FILES["config_file"]["name"], PATHINFO_EXTENSION));

            if (in_array($file_extension, $allowed_extensions)) {
                shell_exec('sudo systemctl stop wg-quick@tun0 2>/dev/null');
                shell_exec('sudo systemctl stop openvpn@tun0 2>/dev/null');
                shell_exec('rm -f /etc/openvpn/*.conf 2>/dev/null');
                shell_exec('rm -f /etc/wireguard/*.conf 2>/dev/null');

                $upload_dir = '/etc/openvpn/';
                $config_file_ovpn = $upload_dir . "tun0.conf";
                
                if (move_uploaded_file($_FILES["config_file"]["tmp_name"], $config_file_ovpn)) {
                    shell_exec('sudo systemctl daemon-reload');
                    shell_exec('sudo systemctl start openvpn@tun0');
                    sleep(4);
                    echo "<script>Notice('OpenVPN конфигурация успешно установлена!');</script>";
                    echo "<script>window.location = 'cabinet.php?menu=openvpn';</script>";
                } else {
                    echo "<script>Notice('Ошибка при загрузке файла.', 'error');</script>";
                }
            } else {
                echo "<script>Notice('Разрешены только файлы .ovpn', 'error');</script>";
            }
        }
    }
}

$csrf_token = $_SESSION['csrf_token'] ?? '';
?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

    <!-- Статус VPN -->
    <div class="glassmorphism rounded-2xl p-6 flex flex-col">
        <h2 class="text-2xl font-bold text-white mb-6">Статус VPN</h2>
        <div class="space-y-4 text-slate-300 flex-grow">
            <div class="flex justify-between">
                <span class="font-medium">Конфигурация:</span>
                <span class="text-white font-semibold"><?= htmlspecialchars($config_type) ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="font-medium">IP-адрес:</span>
                <div class="flex items-center gap-2">
                    <span class="text-white font-semibold font-mono"><?= htmlspecialchars($ip_address) ?></span>
                    <span id="ping-display" class="bg-slate-700 text-xs font-mono px-2 py-1 rounded-full hidden">--</span>
                </div>
            </div>
            <div class="flex justify-between items-center">
                <span class="font-medium">Соединение:</span>
                <span id="connection-status-badge">
                    <?php if ($connection_status == 'connected'): ?>
                        <span class="bg-green-500/20 text-green-300 px-3 py-1 rounded-full text-sm font-semibold">Установлено</span>
                    <?php else: ?>
                        <span class="bg-red-500/20 text-red-300 px-3 py-1 rounded-full text-sm font-semibold">Разорвано</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="flex justify-between items-center">
                <span class="font-medium">Автовосстановление:</span>
                <?php if ($autostart_status_text == 'Включен'): ?>
                    <span class="bg-green-500/20 text-green-300 px-3 py-1 rounded-full text-sm font-semibold">Включен</span>
                <?php else: ?>
                    <span class="bg-red-500/20 text-red-300 px-3 py-1 rounded-full text-sm font-semibold">Выключен</span>
                <?php endif; ?>
            </div>
        </div>
        
        <form id="vpn-control-form" method="post" class="mt-8">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <?php if ($type == "openvpn"): ?>
                <button type="submit" name="openvpn_start" id="openvpn-start-btn" 
                    class="w-full bg-green-600 text-white font-bold py-3 rounded-lg hover:bg-green-700 transition-all <?= $connection_status == 'connected' ? 'hidden' : '' ?>">
                    Запустить OpenVPN
                </button>
                <button type="submit" name="openvpn_stop" id="openvpn-stop-btn" 
                    class="w-full bg-red-600 text-white font-bold py-3 rounded-lg hover:bg-red-700 transition-all <?= $connection_status == 'disconnected' ? 'hidden' : '' ?>">
                    Остановить OpenVPN
                </button>
            <?php else: ?>
                <button disabled class="w-full bg-slate-700 text-slate-500 font-bold py-3 rounded-lg cursor-not-allowed">
                    <?= $type == "wireguard" ? "Активен WireGuard" : "Конфигурация не загружена" ?>
                </button>
            <?php endif; ?>
        </form>
    </div>

    <!-- Загрузка конфигурации -->
    <div class="glassmorphism rounded-2xl p-6 flex flex-col">
        <h2 class="text-2xl font-bold text-white mb-6">Установка конфигурации OVPN</h2>
        <form id="upload-form" method="post" enctype="multipart/form-data" class="flex flex-col flex-grow">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="flex-grow">
                <label id="drop-zone" for="config_file" 
                    class="flex flex-col items-center justify-center w-full h-full min-h-[200px] border-2 border-dashed border-slate-600 rounded-xl cursor-pointer hover:border-violet-500 transition-colors">
                    <div class="flex flex-col items-center justify-center pt-5 pb-6">
                        <svg class="w-12 h-12 mb-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                        <p id="drop-zone-text" class="mb-2 text-sm text-slate-400">
                            <span class="font-semibold">Кликните для выбора</span> или перетащите файл
                        </p>
                        <p class="text-xs text-slate-500">только *.ovpn</p>
                    </div>
                    <input type="file" id="config_file" name="config_file" accept=".ovpn" class="hidden">
                </label>
            </div>
            <input type="hidden" name="menu" value="openvpn">
            <button type="submit" class="w-full bg-violet-600 text-white font-bold py-3 mt-8 rounded-lg hover:bg-violet-700 transition-all">
                Установить и запустить
            </button>
        </form>
    </div>
</div>

<script>
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('config_file');
    const dropZoneText = document.getElementById('drop-zone-text');

    dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('border-violet-500'); });
    dropZone.addEventListener('dragleave', (e) => { e.preventDefault(); dropZone.classList.remove('border-violet-500'); });
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('border-violet-500');
        if (e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files;
            dropZoneText.innerHTML = `<span class="text-green-400 font-semibold">Файл:</span> ${e.dataTransfer.files[0].name}`;
        }
    });
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
            dropZoneText.innerHTML = `<span class="text-green-400 font-semibold">Файл:</span> ${fileInput.files[0].name}`;
        }
    });

    let liveUpdateInterval;

    function updateLiveStatus() {
        const pingDisplay = document.getElementById('ping-display');
        fetch('ping.php?host=8.8.8.8&interface=tun0')
            .then(r => r.text())
            .then(data => {
                pingDisplay.classList.remove('hidden', 'text-green-300', 'text-orange-300', 'text-red-300');
                if (data.indexOf("NO PING") === -1) {
                    const v = Math.round(parseFloat(data));
                    pingDisplay.textContent = v + 'мс';
                    pingDisplay.classList.add(v < 100 ? 'text-green-300' : v < 200 ? 'text-orange-300' : 'text-red-300');
                } else {
                    pingDisplay.textContent = 'X';
                    pingDisplay.classList.add('text-red-300');
                }
            });

        fetch('status_check.php')
            .then(r => r.text())
            .then(status => {
                const badge = document.getElementById('connection-status-badge');
                const startBtn = document.getElementById('openvpn-start-btn');
                const stopBtn = document.getElementById('openvpn-stop-btn');
                
                if (status.trim() === 'connected') {
                    badge.innerHTML = '<span class="bg-green-500/20 text-green-300 px-3 py-1 rounded-full text-sm font-semibold">Установлено</span>';
                    if (startBtn) startBtn.classList.add('hidden');
                    if (stopBtn) stopBtn.classList.remove('hidden');
                } else {
                    badge.innerHTML = '<span class="bg-red-500/20 text-red-300 px-3 py-1 rounded-full text-sm font-semibold">Разорвано</span>';
                    if (startBtn) startBtn.classList.remove('hidden');
                    if (stopBtn) stopBtn.classList.add('hidden');
                }
            });
    }

    document.addEventListener('DOMContentLoaded', () => {
        updateLiveStatus();
        liveUpdateInterval = setInterval(updateLiveStatus, 3000);
    });

    const vpnForm = document.getElementById('vpn-control-form');
    if (vpnForm) {
        vpnForm.addEventListener('submit', function(e) {
            e.preventDefault();
            clearInterval(liveUpdateInterval);
            
            const formData = new FormData(this);
            const submitter = e.submitter;
            if (submitter) formData.append(submitter.name, '');

            const startBtn = document.getElementById('openvpn-start-btn');
            const stopBtn = document.getElementById('openvpn-stop-btn');
            
            if (startBtn) startBtn.disabled = true;
            if (stopBtn) stopBtn.disabled = true;
            
            if (submitter?.name === 'openvpn_start' && startBtn) {
                startBtn.textContent = 'Запускается...';
            } else if (stopBtn) {
                stopBtn.textContent = 'Останавливается...';
            }

            fetch(window.location.href, { method: 'POST', body: formData })
                .then(() => setTimeout(updateLiveStatus, 2000))
                .finally(() => {
                    setTimeout(() => {
                        if (startBtn) { startBtn.disabled = false; startBtn.textContent = 'Запустить OpenVPN'; }
                        if (stopBtn) { stopBtn.disabled = false; stopBtn.textContent = 'Остановить OpenVPN'; }
                        liveUpdateInterval = setInterval(updateLiveStatus, 3000);
                    }, 2500);
                });
        });
    }
</script>
