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
    $allowed_extensions = array('conf');
    $file_extension = strtolower(pathinfo($_FILES["config_file"]["name"], PATHINFO_EXTENSION));

    if (!in_array($file_extension, $allowed_extensions)) {
        echo "Разрешены только файлы с расширением .conf";
        exit();
    }
    shell_exec('sudo systemctl stop openvpn');
    shell_exec('sudo systemctl stop wg-quick@tun0');
    shell_exec('rm /etc/wireguard/*.conf'); // Удаляем все предыдущие конфигурационные файлы WireGuard
    shell_exec('rm /etc/openvpn/*.conf');

    // Путь для сохранения файла
    $upload_dir = '/etc/wireguard/';
    $config_file_conf = $upload_dir . 'tun0.conf'; // Имя файла конфигурации

    // Перемещаем загруженный файл в нужную директорию
    if (move_uploaded_file($_FILES["config_file"]["tmp_name"], $config_file_conf)) {
        // Запускаем WireGuard
        sleep(1);
        shell_exec('sudo systemctl enable wg-quick@tun0'); // Включаем автозапуск для WireGuard с файлом конфигурации tun0.conf
        shell_exec('sudo systemctl start wg-quick@tun0'); // Запускаем WireGuard с указанным конфигурационным файлом
        
        // Выводим результат
        echo "<div style='background-color: #4caf50; color: white; padding: 10px; border-radius: 4px; margin-bottom: 10px;'>
            WireGuard конфигурация успешно установлена и готова к работе!
        </div>";
    } else {
        echo "Ошибка при загрузке файла.";
    }
}
?>


<style>
    .login-container {
        background-color: #fff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    }
    .login-container h2 {
        margin-top: 0;
    }
    .login-form {
        display: flex;
        flex-direction: column;
    }
    .login-form label {
        margin-bottom: 10px;
    }
    .login-form input[type="text"],
    .login-form input[type="password"],
    .login-form input[type="file"] {
        padding: 8px;
        margin-bottom: 15px;
        border-radius: 4px;
        border: 1px solid #ccc;
        font-size: 16px;
    }
    .login-form input[type="submit"] {
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

<div class="login-container">
    <h2>Установка и запуск WireGuard</h2>
    <form method="post" enctype="multipart/form-data" class="login-form">
        <label for="config_file">Выберите файл конфигурации (только *.conf):</label><br>
        <input type="file" id="config_file" name="config_file" accept=".conf"><br><br>
        <input type="hidden" name="menu" value="wireguard">
        <input type="submit" value="Установить и запустить">
    </form>
</div>
