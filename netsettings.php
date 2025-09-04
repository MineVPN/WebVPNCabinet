<?php
// ----- ВСЯ ТВОЯ PHP-ЛОГИКА ОСТАЕТСЯ ЗДЕСЬ БЕЗ ИЗМЕНЕНИЙ -----
session_start();

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

// Путь к YAML-файлу и функции
$yamlFilePath = '/etc/netplan/01-network-manager-all.yaml';

function readYamlFile($filePath) {
    if (!file_exists($filePath)) { die("Файл не найден: $filePath"); }
    return yaml_parse_file($filePath);
}

function writeYamlFile($filePath, $data) {
    $yaml = yaml_emit($data, YAML_UTF8_ENCODING);
    if (file_put_contents($filePath, $yaml) === false) { die("Не удалось сохранить файл: $filePath"); }
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_settings'])) {
    $data = readYamlFile($yamlFilePath); // Перечитаем данные перед изменением
    $newType = $_POST['connection_type'];
    $inputInterface = trim($_POST['input_interface']);
    $outputInterface = trim($_POST['output_interface']);
    
    // Очищаем старую конфигурацию для входного интерфейса
    unset($data['network']['ethernets'][$inputInterface]);

    if ($newType === 'static') {
        $address = trim($_POST['address']);
        $subnetMask = trim($_POST['subnet_mask']);
        $gateway = trim($_POST['gateway']);
        $dns = array_map('trim', explode(',', $_POST['dns']));

        if (empty($address) || empty($subnetMask) || empty($gateway)) {
            die("Все поля для статической конфигурации обязательны.");
        }

        $data['network']['ethernets'][$inputInterface] = [
            'dhcp4' => false,
            'addresses' => ["$address/$subnetMask"],
            'gateway4' => $gateway,
            'nameservers' => ['addresses' => $dns],
        ];
    } else {
        $data['network']['ethernets'][$inputInterface] = ['dhcp4' => true];
    }

    // Эта часть остается как была, если не требует изменений
    if (!isset($data['network']['ethernets'][$outputInterface])) {
        $data['network']['ethernets'][$outputInterface] = [
            'dhcp4' => false,
            'addresses' => ['10.10.10.1/20'],
            'nameservers' => ['addresses' => ['10.10.10.1']],
            'optional' => true,
        ];
    }

    writeYamlFile($yamlFilePath, $data);

    exec('sudo netplan try 2>&1', $output, $returnVar);

    if ($returnVar === 0) {
        exec('sudo netplan apply', $output, $returnVar);
        echo "<script>Notice('Настройки сети успешно применены!', 'success');</script>";
    } else {
        $errorMsg = addslashes("Ошибка Netplan: " . implode(" ", $output));
        echo "<script>Notice('$errorMsg', 'error');</script>";
    }
}

// Чтение текущих данных для отображения
$data = readYamlFile($yamlFilePath);
$inputInterface = ''; $outputInterface = ''; $inputConfig = []; $outputConfig = [];

if (isset($data['network']['ethernets'])) {
    foreach ($data['network']['ethernets'] as $interface => $config) {
        if (isset($config['optional']) && $config['optional'] === true) {
            $outputInterface = $interface;
            $outputConfig = $config;
        } else {
            $inputInterface = $interface;
            $inputConfig = $config;
        }
    }
}

if (empty($inputInterface)) { $inputInterface = 'eth0'; }
if (empty($outputInterface)) { $outputInterface = 'eth1'; }

$inputType = isset($inputConfig['dhcp4']) && $inputConfig['dhcp4'] ? 'dhcp' : 'static';
$inputAddress = ''; $inputSubnetMask = ''; $inputGateway = ''; $inputDNS = '';

if ($inputType === 'static' && !empty($inputConfig)) {
    if (isset($inputConfig['addresses'][0])) {
        list($inputAddress, $inputSubnetMask) = explode('/', $inputConfig['addresses'][0]);
    }
    $inputGateway = $inputConfig['gateway4'] ?? '';
    $inputDNS = isset($inputConfig['nameservers']['addresses']) ? implode(',', $inputConfig['nameservers']['addresses']) : '';
}

$outputAddress = ''; $outputSubnetMask = '';
if (isset($outputConfig['addresses'][0])) {
    list($outputAddress, $outputSubnetMask) = explode('/', $outputConfig['addresses'][0]);
}
?>

<form method="post" class="space-y-8">
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-2xl font-bold text-white mb-6 border-b border-slate-700 pb-4">
            Входное подключение (<?= htmlspecialchars($inputInterface) ?>)
        </h2>
        <input type="hidden" id="input_interface" name="input_interface" value="<?= htmlspecialchars($inputInterface) ?>">
        <input type="hidden" id="output_interface" name="output_interface" value="<?= htmlspecialchars($outputInterface) ?>">
        
        <div class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 items-center gap-4">
                <label for="connection_type" class="text-slate-300 font-medium">Тип подключения:</label>
                <select name="connection_type" id="connection_type" onchange="toggleStaticFields()" class="md:col-span-2 w-full bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white focus:ring-2 focus:ring-violet-500 focus:outline-none transition">
                    <option value="dhcp" <?= $inputType === 'dhcp' ? 'selected' : '' ?>>DHCP (Автоматически)</option>
                    <option value="static" <?= $inputType === 'static' ? 'selected' : '' ?>>Статический IP</option>
                </select>
            </div>
            
            <div id="staticFields" class="space-y-4" style="display: <?= $inputType === 'static' ? 'block' : 'none' ?>;">
                <div class="grid grid-cols-1 md:grid-cols-3 items-center gap-4">
                    <label for="address" class="text-slate-300 font-medium">Адрес:</label>
                    <input type="text" id="address" name="address" value="<?= htmlspecialchars($inputAddress) ?>" placeholder="192.168.1.100" class="md:col-span-2 w-full bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white placeholder-slate-400 focus:ring-2 focus:ring-violet-500 focus:outline-none transition">
                </div>
                 <div class="grid grid-cols-1 md:grid-cols-3 items-center gap-4">
                    <label for="subnet_mask" class="text-slate-300 font-medium">Маска подсети:</label>
                    <input type="number" min="0" max="32" id="subnet_mask" name="subnet_mask" placeholder="24" value="<?= htmlspecialchars($inputSubnetMask) ?>" class="md:col-span-2 w-full bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white placeholder-slate-400 focus:ring-2 focus:ring-violet-500 focus:outline-none transition">
                </div>
                 <div class="grid grid-cols-1 md:grid-cols-3 items-center gap-4">
                    <label for="gateway" class="text-slate-300 font-medium">Шлюз:</label>
                    <input type="text" id="gateway" name="gateway" value="<?= htmlspecialchars($inputGateway) ?>" placeholder="192.168.1.1" class="md:col-span-2 w-full bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white placeholder-slate-400 focus:ring-2 focus:ring-violet-500 focus:outline-none transition">
                </div>
                 <div class="grid grid-cols-1 md:grid-cols-3 items-center gap-4">
                    <label for="dns" class="text-slate-300 font-medium">DNS (через запятую):</label>
                    <input type="text" id="dns" name="dns" value="<?= htmlspecialchars($inputDNS) ?>" placeholder="8.8.8.8,8.8.4.4" class="md:col-span-2 w-full bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white placeholder-slate-400 focus:ring-2 focus:ring-violet-500 focus:outline-none transition">
                </div>
            </div>
        </div>
    </div>

    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-2xl font-bold text-white mb-6 border-b border-slate-700 pb-4">
            Локальная сеть (<?= htmlspecialchars($outputInterface) ?>)
        </h2>
        <div class="space-y-4 text-slate-300">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <span class="font-medium">Адрес:</span>
                <span class="md:col-span-2 text-white font-semibold font-mono"><?= htmlspecialchars($outputAddress) ?></span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <span class="font-medium">Маска подсети:</span>
                <span class="md:col-span-2 text-white font-semibold font-mono">/<?= htmlspecialchars($outputSubnetMask) ?></span>
            </div>
        </div>
    </div>
    
    <div>
        <button type="submit" name="apply_settings" class="w-full sm:w-auto bg-violet-600 text-white font-bold py-3 px-8 rounded-lg hover:bg-violet-700 transition-all">
            Применить настройки
        </button>
    </div>
</form>

<script>
    function toggleStaticFields() {
        const connectionType = document.getElementById('connection_type').value;
        const staticFields = document.getElementById('staticFields');
        staticFields.style.display = connectionType === 'static' ? 'block' : 'none';
        
        // Делаем поля обязательными или нет в зависимости от выбора
        const inputs = staticFields.querySelectorAll('input');
        inputs.forEach(input => {
            input.required = connectionType === 'static';
        });
    }
    // Вызываем функцию при загрузке страницы, чтобы установить правильное состояние
    document.addEventListener('DOMContentLoaded', toggleStaticFields);
</script>
