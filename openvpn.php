<?php
// ----- ВСЯ PHP-ЛОГИКА ОСТАЕТСЯ ЗДЕСЬ БЕЗ ИЗМЕНЕНИЙ -----

// --- ЛОГИКА ИЗ get_ip.php ---
$openvpn_config_path = '/etc/openvpn/tun0.conf';
$wireguard_config_path = '/etc/wireguard/tun0.conf';
$type = null;
$connection_status = 'disconnected';
$ip_address = 'Не определен';
$config_type = 'Нет';

if (file_exists($openvpn_config_path)) {
    $openvpn_config_content = file_get_contents($openvpn_config_path);
    if (preg_match('/^\s*remote\s+([^\s]+)/m', $openvpn_config_content, $matcheso)) {
        $ip_address = $matcheso[1];
        $config_type = "OpenVPN";
        $type = "openvpn";
    }
}
if (file_exists($wireguard_config_path)) {
    $wireguard_config_content = file_get_contents($wireguard_config_path);
    if (preg_match('/^\s*Endpoint\s*=\s*([\d\.]+):\d+/m', $wireguard_config_content, $matchesw)) {
        $ip_address = $matchesw[1];
        $config_type = "WireGuard";
        $type = "wireguard";
    }
}

$status_output = shell_exec("ifconfig tun0 2>&1");
if (strpos($status_output, 'Device not found') === false) {
    $connection_status = 'connected';
}

// --- ЛОГИКА ОБРАБОТКИ ФОРМ (start/stop/upload) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['openvpn_start'])) {
        shell_exec("sudo systemctl start openvpn@tun0");
        sleep(5);
        echo "<script>window.location = 'cabinet.php?menu=openvpn';</script>";
        exit();
    }
    if (isset($_POST['openvpn_stop'])) {
        shell_exec("sudo systemctl stop openvpn@tun0");
        sleep(3);
        echo "<script>window.location = 'cabinet.php?menu=openvpn';</script>";
        exit();
    }
    
    if (isset($_FILES["config_file"])) {
        $allowed_extensions = array('ovpn');
        if (!empty($_FILES["config_file"]["name"])) {
            $file_extension = strtolower(pathinfo($_FILES["config_file"]["name"], PATHINFO_EXTENSION));

            if (in_array($file_extension, $allowed_extensions)) {
                shell_exec('sudo systemctl stop wg-quick@tun0');
                shell_exec('systemctl disable wg-quick@tun0');
                shell_exec('rm /etc/openvpn/*.conf');
                shell_exec('rm /etc/wireguard/*.conf');

                $upload_dir = '/etc/openvpn/';
                $config_file_ovpn = $upload_dir . "tun0.conf";
                
                if (move_uploaded_file($_FILES["config_file"]["tmp_name"], $config_file_ovpn)) {
                    shell_exec('sudo systemctl daemon-reload');
                    shell_exec('sudo systemctl start openvpn@tun0');
                    sleep(4);
                    echo "<script>Notice('OpenVPN конфигурация успешно установлена и готова к работе!');</script>";
                    echo "<script>window.location = 'cabinet.php?menu=openvpn';</script>";
                } else {
                    echo "<script>Notice('Ошибка при загрузке файла.');</script>";
                }
            } else {
                echo "<script>Notice('Разрешены только файлы с расширением .ovpn');</script>";
            }
        }
    }
}
?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

    <div class="glassmorphism rounded-2xl p-6 flex flex-col">
        <h2 class="text-2xl font-bold text-white mb-6">Статус VPN</h2>
        <div class="space-y-4 text-slate-300 flex-grow">
            <div class="flex justify-between"><span class="font-medium">Конфигурация:</span><span class="text-white font-semibold"><?= htmlspecialchars($config_type) ?></span></div>
            <div class="flex justify-between"><span class="font-medium">IP-адрес:</span><span class="text-white font-semibold font-mono"><?= htmlspecialchars($ip_address) ?></span></div>
            <div class="flex justify-between items-center">
                <span class="font-medium">Соединение:</span>
                <?php if ($connection_status == 'connected'): ?>
                    <span class="bg-green-500/20 text-green-300 px-3 py-1 rounded-full text-sm font-semibold">Установлено</span>
                <?php else: ?>
                    <span class="bg-red-500/20 text-red-300 px-3 py-1 rounded-full text-sm font-semibold">Разорвано</span>
                <?php endif; ?>
            </div>
        </div>
        <form method="post" class="mt-8">
            <?php if ($type == "openvpn"): ?>
                <?php if ($connection_status == "disconnected"): ?>
                    <button type="submit" name="openvpn_start" class="w-full bg-green-600 text-white font-bold py-3 rounded-lg hover:bg-green-700 transition-all">Запустить OpenVPN</button>
                <?php else: ?>
                    <button type="submit" name="openvpn_stop" class="w-full bg-red-600 text-white font-bold py-3 rounded-lg hover:bg-red-700 transition-all">Остановить OpenVPN</button>
                <?php endif; ?>
            <?php else: ?>
                 <button disabled class="w-full bg-slate-700 text-slate-500 font-bold py-3 rounded-lg cursor-not-allowed">Действий нет</button>
            <?php endif; ?>
        </form>
    </div>

    <div class="glassmorphism rounded-2xl p-6 flex flex-col">
        <h2 class="text-2xl font-bold text-white mb-6">Установка конфигурации</h2>
        <form id="upload-form" method="post" enctype="multipart/form-data" class="flex flex-col flex-grow">
            <div class="flex-grow">
                <label id="drop-zone" for="config_file" class="flex flex-col items-center justify-center w-full h-full border-2 border-dashed border-slate-600 rounded-xl cursor-pointer hover:border-violet-500 transition-colors">
                    <div class="flex flex-col items-center justify-center pt-5 pb-6">
                        <p id="drop-zone-text" class="mb-2 text-sm text-slate-400"><span class="font-semibold">Кликните для выбора</span> или перетащите файл</p>
                        <p class="text-xs text-slate-500">только *.ovpn</p>
                    </div>
                    <input type="file" id="config_file" name="config_file" accept=".ovpn" class="hidden">
                </label>
            </div>
            <input type="hidden" name="menu" value="openvpn">
            <button type="submit" class="w-full bg-violet-600 text-white font-bold py-3 mt-8 rounded-lg hover:bg-violet-700 transition-all">Установить и запустить</button>
        </form>
    </div>

</div>

<script>
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('config_file');
    const dropZoneText = document.getElementById('drop-zone-text');

    // Подсветка зоны при перетаскивании файла
    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('border-violet-500');
        dropZone.classList.remove('border-slate-600');
    });

    // Убираем подсветку, когда файл уходит из зоны
    dropZone.addEventListener('dragleave', (e) => {
        e.preventDefault();
        dropZone.classList.remove('border-violet-500');
        dropZone.classList.add('border-slate-600');
    });

    // Обработка сброса файла в зону
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('border-violet-500');
        dropZone.classList.add('border-slate-600');

        const files = e.dataTransfer.files;
        if (files.length > 0) {
            // Присваиваем файл нашему скрытому инпуту
            fileInput.files = files;
            // Показываем имя файла
            dropZoneText.innerHTML = `<span class="font-semibold text-green-400">Файл выбран:</span> ${files[0].name}`;
        }
    });
    
    // Обновление текста при выборе файла через клик
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
            dropZoneText.innerHTML = `<span class="font-semibold text-green-400">Файл выбран:</span> ${fileInput.files[0].name}`;
        }
    });

</script>