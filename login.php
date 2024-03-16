<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Page</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-color: #f4f4f4;
        }
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
        .login-form input[type="password"] {
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
</head>
<body>
    <div class="login-container">
        <h2>Вход</h2>
        <form class="login-form" action="login.php" method="POST">
            <label for="username">Пользователь:</label>
            <input type="text" id="username" name="username" required value='root'>
            <label for="password">Пароль:</label>
            <input type="password" id="password" name="password" required>
            <input type="submit" value="Войти">
        </form>
        <?php
        // Проверка, была ли отправлена форма
        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            // Получение пароля из формы
            $password = $_POST["password"];

            // Команда для проверки аутентификации пользователя root
            $command = "echo '$password' | su -c 'id'";

            // Выполнение команды и получение результата
            exec($command, $output, $return_var);

            // Проверка успешности выполнения команды
            if ($return_var == 0) {
                // Стартуем сессию
                session_start();
                // Устанавливаем флаг авторизации
                $_SESSION["authenticated"] = true;
                // Перенаправляем на защищенную страницу
                header("Location: index.php");
                exit();
            } else {
                // Авторизация неуспешна, показываем сообщение об ошибке
                echo "<p class='error-message'>Неверный пароль.</p>";
            }
        }
        ?>
    </div>
</body>
</html>
