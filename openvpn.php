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
// Проверяем, была ли отправлена форма
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["config_file"])) {
    // Проверяем тип файла
    $allowed_extensions = array('ovpn');
    $file_extension = strtolower(pathinfo($_FILES["config_file"]["name"], PATHINFO_EXTENSION));

    if (!in_array($file_extension, $allowed_extensions)) {
        echo "Разрешены только файлы с расширением .ovpn";
        exit();
    }

    shell_exec('sudo systemctl stop wg-quick@tun0');
    shell_exec('systemctl disable wg-quick@tun0');
    shell_exec('rm /etc/openvpn/*.conf');
    shell_exec('rm /etc/wireguard/*.conf');

    // Путь для сохранения файла
    $upload_dir = '/etc/openvpn/';
    $config_file_ovpn = $upload_dir . "tun0.conf"; // Имя файла задано явно
    $config_file_conf = $upload_dir . pathinfo($config_file_ovpn, PATHINFO_FILENAME) . ".conf";


    // Перемещаем загруженный файл в нужную директорию
    if (move_uploaded_file($_FILES["config_file"]["tmp_name"], $config_file_ovpn)) {
        // Переименовываем файл
        if (rename($config_file_ovpn, $config_file_conf)) {
            // Запускаем OpenVPN

            
            shell_exec('sudo systemctl stop openvpn');
            $service_name = pathinfo($config_file_conf, PATHINFO_FILENAME);
            shell_exec('sudo systemctl start openvpn@' . $service_name);

            // Выводим результат
            echo "<div style='background-color: #4caf50; color: white; padding: 10px; border-radius: 4px; margin-bottom: 10px;'>
            OpenVPN конфигурация успешно установлена и готова к работе!
        </div>";
        } else {
            echo "Ошибка при переименовании файла.";
        }
    } else {
        echo "Ошибка при загрузке файла.";
    }
}
?>

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
    <div class="container">
        <h2>Установка и запуск OpenVPN</h2>
        <form method="post" enctype="multipart/form-data" class="container-form">
            <label for="config_file">Выберите файл конфигурации (только *.ovpn):</label><br>
            <input type="file" id="config_file" name="config_file" accept=".ovpn"><br><br>
            <input type="hidden" name="menu" value="openvpn">
            <input type="submit" value="Установить и запустить">
        </form>
    </div>


