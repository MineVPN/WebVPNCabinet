<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VPN Configuration</title>
    <style>
        .container {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .container h2 {
            margin-top: 0;
        }
        .container-form {
            display: flex;
            flex-direction: column;
        }
        .container-form label {
            margin-bottom: 10px;
        }
        .container-form input[type="text"],
        .container-form input[type="password"],
        .container-form input[type="file"] {
            padding: 8px;
            margin-bottom: 15px;
            border-radius: 4px;
            border: 1px solid #ccc;
            font-size: 16px;
        }
        .container-form input[type="submit"] {
            padding: 10px 20px;
            background-color: #4caf50;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .error-message {
            color: red;
            margin-top: 10px;
        }
    </style>
</head>
<body>

<div class="container">
    <?php
    $openvpn_config_path = '/etc/openvpn/tun0.conf';
    $wireguard_config_path = '/etc/wireguard/tun0.conf';

    $type=null;
    $tun=null;

    // Проверяем наличие файла конфигурации OpenVPN
    if (file_exists($openvpn_config_path)) {
        // Читаем содержимое файла
        $openvpn_config_content = file_get_contents($openvpn_config_path);

        // Находим IP-адрес в конфигурации OpenVPN
        if (preg_match('/^\s*remote\s+([^\s]+)/m', $openvpn_config_content, $matcheso)) {
            $openvpn_ip = $matcheso[1];
            echo "<h3>Установлен OpenVPN конфиг</h3>";
            echo "<h3> VPN IP: $openvpn_ip</h3>";
            $type="openvpn";
        } else {
            echo "<h3>Не корректный OpenVPN конфиг.</h3>";
        }
    }

    // Проверяем наличие файла конфигурации WireGuard
    if (file_exists($wireguard_config_path)) {
        // Читаем содержимое файла
        $wireguard_config_content = file_get_contents($wireguard_config_path);

        if (preg_match('/^\s*Endpoint\s*=\s*([\d\.]+):\d+/m', $wireguard_config_content, $matchesw)) {
            $wireguard_ip = $matchesw[1];
            echo "<h3>Установлен WireGuard конфиг</h3>";
            echo "<h3>VPN IP: $wireguard_ip</h3> (WireGuard)";
            $type="wireguard";
        } else {
            echo "<h3>Не корректный WireGuard конфиг.</h3>";
        }
    }

    // Выполняем команду для проверки статуса туннеля tun0
    $status = shell_exec("ifconfig tun0 2>&1");

    // Проверяем, содержит ли вывод информацию о туннеле
    if (strpos($status, 'Device not found') !== false) {
        echo "Туннель tun0 не поднят.";
        $tun="yes";
    } else {
        echo "Туннель tun0 поднят.";
        $tun="no";
    }

    echo "<br><br>";

    if(isset($_POST['openvpn_start']) && $type == "openvpn") {
        shell_exec("sudo systemctl start openvpn@tun0");
        sleep(5);
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
    }

    if(isset($_POST['openvpn_stop']) && $type == "openvpn") {
        shell_exec("sudo systemctl stop openvpn@tun0");
        sleep(3);
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
    }

    if(isset($_POST['wireguard_start']) && $type == "wireguard") {
        shell_exec("sudo systemctl start wg-quick@tun0");
        sleep(5);
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
    }

    if(isset($_POST['wireguard_stop']) && $type == "wireguard") {
        shell_exec("sudo systemctl stop wg-quick@tun0");
        sleep(3);
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
    }
    ?>
    <form method="post">
        <?php if ($type == "openvpn"): ?>
            <input type="submit" name="openvpn_start" value="Запустить OpenVPN">
            <input type="submit" name="openvpn_stop" value="Остановить OpenVPN">
        <?php endif; ?>
        <?php if ($type == "wireguard"): ?>
            <input type="submit" name="wireguard_start" value="Запустить WireGuard">
            <input type="submit" name="wireguard_stop" value="Остановить WireGuard">
        <?php endif; ?>
    </form>
</div>

</body>
</html>
