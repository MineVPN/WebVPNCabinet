<?php
// Проверка аутентификации
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}
// --- ЛОГИКА СТАТУСА ---
$openvpn_config_path = '/etc/openvpn/tun0.conf';
$wireguard_config_path = '/etc/wireguard/tun0.conf';
$type = null;
$connection_status = 'disconnected';
$ip_address = 'Не определен';
$config_type = 'Нет';

// Проверяем OpenVPN
if (file_exists($openvpn_config_path)) {
    $openvpn_config_content = file_get_contents($openvpn_config_path);
    if (preg_match('/^\s*remote\s+([^\s]+)/m', $openvpn_config_content, $matcheso)) {
        $ip_address = $matcheso[1];
        $config_type = "OpenVPN";
        $type = "openvpn";
    }
}
// Проверяем WireGuard
if (file_exists($wireguard_config_path)) {
    $wireguard_config_content = file_get_contents($wireguard_config_path);
    if (preg_match('/^\s*Endpoint\s*=\s*([\d\.]+):\d+/m', $wireguard_config_content, $matchesw)) {
        $ip_address = $matchesw[1];
        $config_type = "WireGuard";
        $type = "wireguard";
    }
}

// Проверяем статус туннеля
$status_output = shell_exec("ifconfig tun0 2>&1");
if (strpos($status_output, 'Device not found') === false) {
    $connection_status = 'connected';
}

// Проверяем статус автозапуска
$settings_file_path = '../settings';
$autostart_status_text = 'Выключен'; // Значение по умолчанию
if (file_exists($settings_file_path) && is_readable($settings_file_path)) {
    $settings_content = file_get_contents($settings_file_path);
    if (strpos($settings_content, 'autoupvpn=true') !== false) {
        $autostart_status_text = 'Включен';
    }
}


// --- ЛОГИКА ОБРАБОТКИ ФОРМ (start/stop/upload) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['wireguard_start'])) {
        shell_exec("sudo systemctl start wg-quick@tun0");
        sleep(5);
        exit();
    }
    if (isset($_POST['wireguard_stop'])) {
        shell_exec("sudo systemctl stop wg-quick@tun0");
        sleep(3);
        exit();
    }
    if (isset($_FILES["config_file"])) {
        if (!empty($_FILES["config_file"]["name"])) {
            $allowed_extensions = array('conf');
            $file_extension = strtolower(pathinfo($_FILES["config_file"]["name"], PATHINFO_EXTENSION));

            if (in_array($file_extension, $allowed_extensions)) {
                shell_exec('sudo systemctl stop openvpn@tun0');
                shell_exec('sudo systemctl stop wg-quick@tun0');
                shell_exec('rm /etc/wireguard/*.conf');
                shell_exec('rm /etc/openvpn/*.conf');

                $upload_dir = '/etc/wireguard/';
                $config_file_conf = $upload_dir . 'tun0.conf';

                if (move_uploaded_file($_FILES["config_file"]["tmp_name"], $config_file_conf)) {
                    shell_exec('sudo systemctl enable wg-quick@tun0');
                    shell_exec('sudo systemctl start wg-quick@tun0');
                    sleep(4);
                    echo "<script>Notice('WireGuard конфигурация успешно установлена!', 'success');</script>";
                    echo "<script>window.location = 'cabinet.php?menu=wireguard';</script>";
                } else {
                    echo "<script>Notice('Ошибка при загрузке файла.', 'error');</script>";
                }
            } else {
                echo "<script>Notice('Разрешены только файлы с расширением .conf', 'error');</script>";
            }
        }
    }
}
?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

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
                    <span id="ping-display" class="bg-slate-700 text-xs font-mono px-2 py-1 rounded-full hidden transition-colors">--</span>
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
                <span class="font-medium">Автозапуск при падении:</span>
                <?php if ($autostart_status_text == 'Включен'): ?>
                    <span class="bg-green-500/20 text-green-300 px-3 py-1 rounded-full text-sm font-semibold">Включен</span>
                <?php else: ?>
                    <span class="bg-red-500/20 text-red-300 px-3 py-1 rounded-full text-sm font-semibold">Выключен</span>
                <?php endif; ?>
            </div>
        </div>
        
        <form id="vpn-control-form" method="post" class="mt-8">
            <?php if ($type == "wireguard"): ?>
                <button type="submit" name="wireguard_start" id="wireguard-start-btn" class="w-full bg-green-600 text-white font-bold py-3 rounded-lg hover:bg-green-700 transition-all <?= $connection_status == 'connected' ? 'hidden' : '' ?>">Запустить WireGuard</button>
                <button type="submit" name="wireguard_stop" id="wireguard-stop-btn" class="w-full bg-red-600 text-white font-bold py-3 rounded-lg hover:bg-red-700 transition-all <?= $connection_status == 'disconnected' ? 'hidden' : '' ?>">Остановить WireGuard</button>
            <?php else: ?>
                 <button disabled class="w-full bg-slate-700 text-slate-500 font-bold py-3 rounded-lg cursor-not-allowed">Действий нет</button>
            <?php endif; ?>
        </form>
    </div>

    <div class="glassmorphism rounded-2xl p-6 flex flex-col">
        <h2 class="text-2xl font-bold text-white mb-6">Установка конфигурации WG</h2>
        <form id="upload-form" method="post" enctype="multipart/form-data" class="flex flex-col flex-grow">
            <div class="flex-grow">
                <label id="drop-zone" for="config_file" class="flex flex-col items-center justify-center w-full h-full border-2 border-dashed border-slate-600 rounded-xl cursor-pointer hover:border-violet-500 transition-colors">
                    <div class="flex flex-col items-center justify-center pt-5 pb-6">
                        <p id="drop-zone-text" class="mb-2 text-sm text-slate-400"><span class="font-semibold">Кликните для выбора</span> или перетащите файл</p>
                        <p class="text-xs text-slate-500">только *.conf</p>
                    </div>
                    <input type="file" id="config_file" name="config_file" accept=".conf" class="hidden">
                </label>
            </div>
            <input type="hidden" name="menu" value="wireguard">
            <button type="submit" class="w-full bg-violet-600 text-white font-bold py-3 mt-8 rounded-lg hover:bg-violet-700 transition-all">Установить и запустить</button>
        </form>
    </div>

</div>

<script>
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('config_file');
    const dropZoneText = document.getElementById('drop-zone-text');

    dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('border-violet-500'); dropZone.classList.remove('border-slate-600'); });
    dropZone.addEventListener('dragleave', (e) => { e.preventDefault(); dropZone.classList.remove('border-violet-500'); dropZone.classList.add('border-slate-600'); });
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('border-violet-500');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            dropZoneText.innerHTML = `<span class="font-semibold text-green-400">Файл выбран:</span> ${files[0].name}`;
        }
    });
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
            dropZoneText.innerHTML = `<span class="font-semibold text-green-400">Файл выбран:</span> ${fileInput.files[0].name}`;
        }
    });

    let liveUpdateInterval; // Переменная для хранения ID таймера

    function updateLiveStatus() {
        // 1. Обновляем Пинг
        const pingDisplay = document.getElementById('ping-display');
        const pingXhr = new XMLHttpRequest();
        pingXhr.onreadystatechange = function() {
            if (pingXhr.readyState == 4 && pingXhr.status == 200) {
                pingDisplay.classList.remove('hidden');
                const response = pingXhr.responseText;
                pingDisplay.classList.remove('text-green-300', 'text-orange-300', 'text-red-300');
                if (response.indexOf("NO PING") == -1) {
                    const pingValue = Math.round(parseFloat(response));
                    pingDisplay.textContent = `${pingValue}мс`;
                    if (pingValue < 100) pingDisplay.classList.add('text-green-300');
                    else if (pingValue < 200) pingDisplay.classList.add('text-orange-300');
                    else pingDisplay.classList.add('text-red-300');
                } else {
                    pingDisplay.textContent = 'X';
                    pingDisplay.classList.add('text-red-300');
                }
            }
        };
        pingXhr.open("GET", "ping.php?host=8.8.8.8&interface=tun0", true);
        pingXhr.send();

        // 2. Обновляем Статус Соединения и Кнопки
        const statusBadge = document.getElementById('connection-status-badge');
        const startBtn = document.getElementById('wireguard-start-btn');
        const stopBtn = document.getElementById('wireguard-stop-btn');
        const statusXhr = new XMLHttpRequest();
        statusXhr.onreadystatechange = function() {
            if (statusXhr.readyState == 4 && statusXhr.status == 200) {
                const status = statusXhr.responseText.trim();
                if (status === 'connected') {
                    statusBadge.innerHTML = '<span class="bg-green-500/20 text-green-300 px-3 py-1 rounded-full text-sm font-semibold">Установлено</span>';
                    if (startBtn && stopBtn) {
                        startBtn.classList.add('hidden');
                        stopBtn.classList.remove('hidden');
                    }
                } else {
                    statusBadge.innerHTML = '<span class="bg-red-500/20 text-red-300 px-3 py-1 rounded-full text-sm font-semibold">Разорвано</span>';
                    if (startBtn && stopBtn) {
                        startBtn.classList.remove('hidden');
                        stopBtn.classList.add('hidden');
                    }
                }
            }
        };
        statusXhr.open("GET", "status_check.php", true);
        statusXhr.send();
    }

    // Запускаем обновление при загрузке страницы и затем каждые 3 секунды
    document.addEventListener('DOMContentLoaded', () => {
        updateLiveStatus();
        liveUpdateInterval = setInterval(updateLiveStatus, 3000); // Сохраняем ID таймера
    });

    const vpnForm = document.getElementById('vpn-control-form');
    if (vpnForm) {
        vpnForm.addEventListener('submit', function(e) {
            e.preventDefault(); 
            clearInterval(liveUpdateInterval); // 1. ОСТАНАВЛИВАЕМ ТАЙМЕР

            const formData = new FormData(this);
            const submitter = e.submitter;
            
            if (submitter) {
                formData.append(submitter.name, submitter.value || '');
            }

            // 2. МГНОВЕННАЯ ОБРАТНАЯ СВЯЗЬ
            const startBtn = document.getElementById('wireguard-start-btn');
            const stopBtn = document.getElementById('wireguard-stop-btn');
            const originalStartText = startBtn.textContent;
            const originalStopText = stopBtn.textContent;

            startBtn?.setAttribute('disabled', true);
            stopBtn?.setAttribute('disabled', true);
            
            if (submitter && submitter.name === 'wireguard_start') {
                startBtn.textContent = 'Запускается...';
            } else if (submitter) {
                stopBtn.textContent = 'Останавливается...';
            }

            // 3. ОТПРАВЛЯЕМ КОМАНДУ НА СЕРВЕР
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(() => {
                    // 4. ПРИНУДИТЕЛЬНО ПРОВЕРЯЕМ СТАТУС ПОСЛЕ ЗАДЕРЖКИ
                    setTimeout(updateLiveStatus, 2000); 
                })
                .finally(() => {
                    // 5. ВОЗОБНОВЛЯЕМ РАБОТУ ИНТЕРФЕЙСА
                    setTimeout(() => {
                        startBtn?.removeAttribute('disabled');
                        stopBtn?.removeAttribute('disabled');
                        startBtn.textContent = originalStartText;
                        stopBtn.textContent = originalStopText;
                        // 6. ПЕРЕЗАПУСКАЕМ ТАЙМЕР
                        liveUpdateInterval = setInterval(updateLiveStatus, 3000);
                    }, 2500);
                });
        });
    }

</script>

