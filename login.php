<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>SERVER Login</title>
    <script src="tailwindcss.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #0F172A; }
        .glassmorphism { background: rgba(30, 41, 59, 0.6); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.1); }
        .neon-button:hover { box-shadow: 0 0 8px #8b5cf6, 0 0 16px #8b5cf6; }
    </style>
</head>
<body class="text-slate-300">

    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md glassmorphism rounded-2xl p-8">
            
            <form class="space-y-6" action="login.php" method="POST">
                <div>
                    <img src="logo.png" alt="Server Logo" class="w-48 h-48 mx-auto mb-4">
                </div>
                <div>
                    <label for="username" class="block mb-2 text-sm font-medium text-slate-400">Пользователь:</label>
                    <input disabled type="text" id="username" name="username" required value="root" class="w-full bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white placeholder-slate-400 focus:ring-2 focus:ring-violet-500 focus:outline-none transition">
                </div>
                <div>
                    <label for="password" class="block mb-2 text-sm font-medium text-slate-400">Пароль:</label>
                    <input type="password" id="password" name="password" required class="w-full bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white placeholder-slate-400 focus:ring-2 focus:ring-violet-500 focus:outline-none transition">
                </div>
                
                <button type="submit" class="w-full bg-violet-600 text-white font-bold py-3 rounded-lg hover:bg-violet-700 transition-all duration-300 neon-button">
                    Войти
                </button>
            </form>
            
            <?php
            // Твоя логика остается здесь, как и была
            if ($_SERVER["REQUEST_METHOD"] == "POST") {
                $password = $_POST["password"];
                $command = "echo '$password' | su -c 'id'";
                exec($command, $output, $return_var);

                if ($return_var == 0) {
                    session_start();
                    $_SESSION["authenticated"] = true;
                    // Используем JavaScript для редиректа, так как HTML уже отправлен
                    echo '<script>window.location.href = "index.php";</script>';
                    exit();
                } else {
                    // Сообщение об ошибке теперь тоже стилизовано
                    echo "<p class='text-red-400 text-sm text-center mt-4'>Неверный пароль.</p>";
                }
            }
            ?>
        </div>
    </div>

</body>
</html>
