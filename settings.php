<?php
session_start(); // Начало сессии

// Проверяем, установлена ли сессия
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    // Сессия не установлена или пользователь не аутентифицирован, перенаправляем на страницу входа
    header("Location: login.php");
    exit(); // Важно вызвать exit() после перенаправления, чтобы предотвратить дальнейшее выполнение кода
}

// Весь ваш код для страницы кабинета может быть добавлен здесь
?>



<?php
// Путь к YAML-файлу
$yamlFilePath = '/etc/netplan/01-network-manager-all.yaml';

// Функция для чтения YAML-файла
function readYamlFile($filePath) {
    if (!file_exists($filePath)) {
        die("Файл не найден: $filePath");
    }
    return yaml_parse_file($filePath);
}

// Функция для сохранения данных в YAML-файл
function writeYamlFile($filePath, $data) {
    $yaml = yaml_emit($data, YAML_UTF8_ENCODING);
    if (file_put_contents($filePath, $yaml) === false) {
        die("Не удалось сохранить файл: $filePath");
    }
}


// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['apply_settings'])) {
        $newType = $_POST['connection_type'];
        $inputInterface = trim($_POST['input_interface']);
        $outputInterface = trim($_POST['output_interface']);

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
                'nameservers' => [
                    'addresses' => $dns,
                ],
            ];
        } else {
            $data['network']['ethernets'][$inputInterface] = [
                'dhcp4' => true,
            ];
        }

        $data['network']['ethernets'][$outputInterface] = [
            'dhcp4' => false,
            'addresses' => ['10.10.1.1/20'],
            'nameservers' => [
                'addresses' => ['10.10.1.1'],
            ],
            'optional' => true,
        ];

        writeYamlFile($yamlFilePath, $data);

        $output = [];
        $returnVar = 0;

        // Выполнение команды netplan try
        exec('sudo netplan try 2>&1', $output, $returnVar);

        if ($returnVar === 0) {
            // Если всё прошло успешно
            exec('sudo netplan apply', $output, $returnVar);
            echo "<script>Notice('Настройки входной сети успешно применены!');</script>";
        } else {
            // Если ошибка
            echo "Ошибка при применении: " . implode("\n", $output);
        }
    }
}

// Чтение текущих данных из файла
$data = readYamlFile($yamlFilePath);

// Определение интерфейсов
$inputInterface = '';
$outputInterface = '';
$inputConfig = [];
$outputConfig = [];

if (isset($data['network']['ethernets'])) {
    foreach ($data['network']['ethernets'] as $interface => $config) {
        if (isset($config['addresses']) && in_array('10.10.1.1/20', $config['addresses'])) {
            $outputInterface = $interface;
            $outputConfig = $config;
        } else {
            $inputInterface = $interface;
            $inputConfig = $config;
        }
    }
}

// Установка интерфейсов по умолчанию
if (empty($inputInterface)) {
    $inputInterface = 'eth0';
}
if (empty($outputInterface)) {
    $outputInterface = 'eth1';
}

// Определение текущих параметров
$inputType = isset($inputConfig['dhcp4']) && $inputConfig['dhcp4'] ? 'dhcp' : 'static';
$inputAddress = '';
$inputSubnetMask = '';
$inputGateway = '';
$inputDNS = '';

if ($inputType === 'static') {
    if (isset($inputConfig['addresses'][0])) {
        list($inputAddress, $inputSubnetMask) = explode('/', $inputConfig['addresses'][0]);
    }
    $inputGateway = $inputConfig['gateway4'] ?? '';
    $inputDNS = isset($inputConfig['nameservers']['addresses']) ? implode(',', $inputConfig['nameservers']['addresses']) : '';
}


// Разбор текущей конфигурации выходного интерфейса
$outputAddress = '';
$outputSubnetMask = '';
$outputGateway = '';
$outputDNS = '';

if (isset($outputConfig['addresses'][0])) {
    list($outputAddress, $outputSubnetMask) = explode('/', $outputConfig['addresses'][0]);
}


?>


<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройка сети сервера</title>
</head>
<body>
    <div class="container-settings">
        <form method="post">
            <h2>Входное подключение (<?= htmlspecialchars($inputInterface) ?>)</h2>
            <input type="hidden" id="input_interface" name="input_interface" value="<?= htmlspecialchars($inputInterface) ?>">
            <input type="hidden" id="output_interface" name="output_interface" value="<?= htmlspecialchars($outputInterface) ?>">

            <table>
                <tr>
                    <td><label for="connection_type">Тип подключения:</label></td>
                    <td>
                        <select name="connection_type" id="connection_type" onchange="toggleStaticFields()">
                            <option value="dhcp" <?= $inputType === 'dhcp' ? 'selected' : '' ?>>DHCP</option>
                            <option value="static" <?= $inputType === 'static' ? 'selected' : '' ?>>Статический</option>
                        </select>
                    </td>
                </tr>
            </table>

            <table id="staticFields" style="display: <?= $inputType === 'static' ? 'table' : 'none' ?>;">
                <tr>
                    <td><label for="address">Адрес:</label></td>
                    <td><input type="text" id="address" name="address" value="<?= htmlspecialchars($inputAddress) ?>" placeholder="255.255.255.255" pattern="^((25[0-5]|2[0-4][0-9]|[0-1]?[0-9]?[0-9])\.){3}(25[0-5]|2[0-4][0-9]|[0-1]?[0-9]?[0-9])$" title="Введите корректный IP-адрес в формате 255.255.255.255" required></td>
                </tr>
                <tr>
                    <td><label for="subnet_mask">Маска подсети:</label></td>
                    <td><input type="number" min=0 max=32 id="subnet_mask" name="subnet_mask" placeholder="24" value="<?= htmlspecialchars($inputSubnetMask) ?>"></td>
                </tr>
                <tr>
                    <td><label for="gateway">Шлюз:</label></td>
                    <td><input type="text" id="gateway" name="gateway" value="<?= htmlspecialchars($inputGateway) ?>" placeholder="255.255.255.255" pattern="^((25[0-5]|2[0-4][0-9]|[0-1]?[0-9]?[0-9])\.){3}(25[0-5]|2[0-4][0-9]|[0-1]?[0-9]?[0-9])$" title="Введите корректный IP-адрес в формате 255.255.255.255" required></td>
                </tr>
                <tr>
                    <td><label for="dns">DNS (через запятую):</label></td>
                    <td><input type="text" id="dns" name="dns" value="<?= htmlspecialchars($inputDNS) ?>" placeholder="8.8.8.8,8.8.4.4" pattern="^((25[0-5]|2[0-4][0-9]|[0-1]?[0-9]?[0-9])\.){3}(25[0-5]|2[0-4][0-9]|[0-1]?[0-9]?[0-9]),((25[0-5]|2[0-4][0-9]|[0-1]?[0-9]?[0-9])\.){3}(25[0-5]|2[0-4][0-9]|[0-1]?[0-9]?[0-9])$" title="Введите два корректных IP-адреса через запятую, например: 8.8.8.8,8.8.4.4" required></td>
                </tr>
            </table>

            <h2>Локальная сеть (<?= htmlspecialchars($outputInterface) ?>)</h2>
            <table>
                <tr>
                    <td>Адрес:</td>
                    <td><?= htmlspecialchars($outputAddress) ?></td>
                </tr>
                <tr>
                    <td>Маска подсети:</td>
                    <td><?= htmlspecialchars($outputSubnetMask) ?></td>
                </tr>
            </table>

            <button type="submit" class='green-button' name="apply_settings">Применить</button>

        </form>
    </div>
    <script>
        function toggleStaticFields() {
            const connectionType = document.getElementById('connection_type').value;
            const staticFields = document.getElementById('staticFields');
            staticFields.style.display = connectionType === 'static' ? 'table' : 'none';
        }
    </script>
</body>
</html>