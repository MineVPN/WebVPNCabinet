<?php
// Получаем хост и интерфейс из GET-запроса
$host = $_GET['host'] ?? '';
$interface_param = $_GET['interface'] ?? '';

// Проверяем, был ли передан хост
if (empty($host)) {
    die("Ошибка: параметр 'host' не указан.");
}

$interface = $interface_param;

// --- НОВАЯ ЛОГИКА: Определение интерфейса из Netplan ---
if ($interface === 'detect_netplan') {
    $interface = ''; // Значение по умолчанию, если ничего не найдем
    $yamlFilePath = '/etc/netplan/01-network-manager-all.yaml';

    if (function_exists('yaml_parse_file') && file_exists($yamlFilePath) && is_readable($yamlFilePath)) {
        $data = @yaml_parse_file($yamlFilePath);

        if (isset($data['network']['ethernets'])) {
            foreach ($data['network']['ethernets'] as $if_name => $config) {
                // Входной интерфейс - это тот, у которого НЕТ флага 'optional: true'
                if (!isset($config['optional']) || $config['optional'] !== true) {
                    $interface = $if_name; // Мы нашли его!
                    break; // Прерываем цикл
                }
            }
        }
    }
}
// --- КОНЕЦ НОВОЙ ЛОГИКИ ---

// БЕЗОПАСНОСТЬ: Экранируем аргументы для предотвращения инъекций команд
$escaped_host = escapeshellarg($host);

// Собираем базовую команду
$command = "ping -c 1 -W 1";

// Если интерфейс определен (вручную или автоматически), добавляем его в команду
if (!empty($interface)) {
    $escaped_interface = escapeshellarg($interface);
    $command .= " -I " . $escaped_interface;
}

// Добавляем хост в конец команды
$command .= " " . $escaped_host;

// Выполняем команду
exec($command, $output, $result);

// Обрабатываем результаты (эта часть без изменений)
if ($result == 0) {
    $found = false;
    foreach ($output as $line) {
        if (strpos($line, "time=") !== false) {
            $time_part = explode("time=", $line)[1];
            $time = trim(explode(" ", $time_part)[0]);
            echo $time;
            $found = true;
            break;
        }
    }
    if (!$found) {
        echo "OK";
    }
} else {
    echo "NO PING";
}
?>
