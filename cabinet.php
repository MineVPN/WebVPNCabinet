<?php
session_start(); // Начало сессии

// Проверяем, установлена ли сессия
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    // Сессия не установлена или пользователь не аутентифицирован, перенаправляем на страницу входа
    header("Location: login.php");
    exit(); // Важно вызвать exit() после перенаправления, чтобы предотвратить дальнейшее выполнение кода
}

if(isset($_POST['menu'])){
    $_GET['menu'] = $_POST['menu'];
}

// Проверяем, был ли передан параметр меню
$menu_item = isset($_GET['menu']) ? $_GET['menu'] : 'openvpn'; // По умолчанию открывается страница OpenVPN



// Пути к страницам меню
$menu_pages = [
    'openvpn' => 'openvpn.php',
    'wireguard' => 'wireguard.php',
    'ping' => 'pinger.php'
];

// Проверяем, существует ли запрошенная страница в меню
if (!array_key_exists($menu_item, $menu_pages)) {
    // Если страница не найдена, перенаправляем на страницу OpenVPN
    $menu_item = 'openvpn';
}

// Весь ваш код для страницы кабинета может быть добавлен здесь
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>SERVER</title>
    <style>
        /* Стили для бокового меню */
        .sidebar {
            width: 200px;
            height: 100%;
            position: fixed;
            top: 0;
            left: 0;
            background-color: #f4f4f4;
            padding-top: 10px;

        }

        .menu-item {
            padding: 10px 20px;
            border-bottom: 1px solid #ddd;
            text-decoration: none;
            color: #333;
            display: block;
        }

        .menu-item:hover {
            background-color: #ddd;
        }

        .content {
            margin-left: 200px; /* Чтобы контент не налезал на меню */
            padding: 20px;
            
        }

        .logo{
            height: 175px;
            margin: 10px;
            margin-bottom: 50px;
        }

    </style>
</head>
<body>
    <!-- Боковое меню -->
    <div class="sidebar">
        <img src="logo.png" class="logo">
        <a class="menu-item" href="cabinet.php?menu=openvpn">OpenVPN</a>
        <a class="menu-item" href="cabinet.php?menu=wireguard">WireGuard</a>
        <a class="menu-item" href="cabinet.php?menu=ping">Ping</a>
        <a class="menu-item" href="logout.php">Выход</a>
    </div>

    <!-- Основной контент -->
    <div class="content">

        <?php
        // Подключаем выбранную страницу из меню
        
        include_once 'get_ip.php';
        echo "<br>";
        include_once $menu_pages[$menu_item];

        ?>
        
        
    </div>
</body>
</html>
