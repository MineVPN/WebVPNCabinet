<?php
// ==============================================================================
// MINE SERVER - Страница авторизации
// Версия: 2.0 (с исправлениями безопасности)
// ==============================================================================

session_start();

// Генерация CSRF-токена
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error_message = '';

// Обработка формы
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Проверка CSRF-токена
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = "Ошибка безопасности. Обновите страницу.";
    } else {
        $password = $_POST["password"] ?? '';
        
        // Защита от брутфорса - задержка между попытками
        if (isset($_SESSION['last_login_attempt'])) {
            $time_diff = time() - $_SESSION['last_login_attempt'];
            if ($time_diff < 2) {
                sleep(2 - $time_diff);
            }
        }
        $_SESSION['last_login_attempt'] = time();
        
        // Счётчик неудачных попыток
        if (!isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = 0;
        }
        
        // Блокировка после 5 неудачных попыток на 5 минут
        if ($_SESSION['login_attempts'] >= 5) {
            if (isset($_SESSION['lockout_time']) && (time() - $_SESSION['lockout_time']) < 300) {
                $remaining = 300 - (time() - $_SESSION['lockout_time']);
                $error_message = "Слишком много попыток. Подождите " . ceil($remaining / 60) . " мин.";
            } else {
                // Сброс блокировки
                $_SESSION['login_attempts'] = 0;
                unset($_SESSION['lockout_time']);
            }
        }
        
        if (empty($error_message)) {
            // Безопасная проверка пароля
            $auth_success = verify_system_password('root', $password);
            
            if ($auth_success) {
                // Успешная авторизация
                
                // Регенерация ID сессии для защиты от session fixation
                session_regenerate_id(true);
                
                $_SESSION["authenticated"] = true;
                $_SESSION['login_attempts'] = 0;
                $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'];
                $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $_SESSION['login_time'] = time();
                
                // Генерируем новый CSRF-токен после логина
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                
                header("Location: index.php");
                exit();
            } else {
                $_SESSION['login_attempts']++;
                
                if ($_SESSION['login_attempts'] >= 5) {
                    $_SESSION['lockout_time'] = time();
                    $error_message = "Слишком много неудачных попыток. Подождите 5 минут.";
                } else {
                    $error_message = "Неверный пароль. Осталось попыток: " . (5 - $_SESSION['login_attempts']);
                }
            }
        }
    }
}

/**
 * Безопасная проверка системного пароля
 * Использует /etc/shadow вместо command injection через su
 */
function verify_system_password($username, $password) {
    // Метод 1: Через /etc/shadow (если readable)
    $shadow_file = '/etc/shadow';
    
    if (is_readable($shadow_file)) {
        $shadow_content = @file_get_contents($shadow_file);
        if ($shadow_content !== false) {
            $lines = explode("\n", $shadow_content);
            
            foreach ($lines as $line) {
                $parts = explode(':', $line);
                if (isset($parts[0]) && $parts[0] === $username && !empty($parts[1])) {
                    $stored_hash = $parts[1];
                    
                    // Проверяем, не заблокирован ли аккаунт
                    if ($stored_hash[0] === '!' || $stored_hash[0] === '*') {
                        return false;
                    }
                    
                    // Проверяем пароль через crypt()
                    $computed = crypt($password, $stored_hash);
                    return hash_equals($stored_hash, $computed);
                }
            }
        }
    }
    
    // Метод 2: Fallback через su с proc_open (безопаснее чем exec с echo)
    return verify_password_via_proc('root', $password);
}

/**
 * Fallback: проверка через su с proc_open
 * Безопаснее чем echo password | su, так как пароль идёт через stdin
 */
function verify_password_via_proc($username, $password) {
    $descriptorspec = array(
        0 => array("pipe", "r"),  // stdin
        1 => array("pipe", "w"),  // stdout
        2 => array("pipe", "w")   // stderr
    );
    
    // Экранируем username для безопасности
    $safe_username = escapeshellarg($username);
    
    $process = proc_open(
        "su $safe_username -c 'echo AUTH_OK' 2>&1",
        $descriptorspec,
        $pipes
    );
    
    if (is_resource($process)) {
        // Отправляем пароль через stdin (не через echo!)
        fwrite($pipes[0], $password . "\n");
        fclose($pipes[0]);
        
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        proc_close($process);
        
        return (strpos($output, 'AUTH_OK') !== false);
    }
    
    return false;
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>MINE SERVER - Вход</title>
    <script src="tailwindcss.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #0F172A; }
        .glassmorphism { 
            background: rgba(30, 41, 59, 0.6); 
            backdrop-filter: blur(12px); 
            border: 1px solid rgba(255, 255, 255, 0.1); 
        }
        .neon-glow:hover { 
            box-shadow: 0 0 20px rgba(139, 92, 246, 0.5); 
        }
        .shake {
            animation: shake 0.5s ease-in-out;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
    </style>
</head>
<body class="text-slate-300 min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md">
        <div class="glassmorphism rounded-2xl p-8 <?= !empty($error_message) ? 'shake' : '' ?>">
            
            <!-- Логотип -->
            <div class="text-center mb-8">
                <img src="logo.png" alt="MINE SERVER" class="w-32 h-32 mx-auto mb-4">
                <h1 class="text-2xl font-bold text-white">MINE SERVER</h1>
                <p class="text-slate-400 text-sm mt-1">Панель управления VPN</p>
            </div>
            
            <!-- Сообщение об ошибке -->
            <?php if (!empty($error_message)): ?>
                <div class="bg-red-500/20 border border-red-500/50 text-red-300 px-4 py-3 rounded-lg mb-6 text-sm text-center">
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>
            
            <!-- Форма входа -->
            <form method="POST" action="login.php" class="space-y-6">
                <!-- CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                
                <div>
                    <label for="password" class="block mb-2 text-sm font-medium text-slate-400">
                        Пароль root:
                    </label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        required 
                        autocomplete="current-password"
                        autofocus
                        class="w-full bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white placeholder-slate-400 focus:ring-2 focus:ring-violet-500 focus:border-transparent focus:outline-none transition"
                        placeholder="Введите пароль"
                    >
                </div>
                
                <button 
                    type="submit" 
                    class="w-full bg-violet-600 text-white font-bold py-3 rounded-lg hover:bg-violet-700 transition-all duration-300 neon-glow"
                >
                    Войти
                </button>
            </form>
            
            <!-- Подсказка -->
            <p class="text-center text-slate-500 text-xs mt-6">
                Используйте пароль пользователя root системы
            </p>
            
        </div>
        
        <!-- Копирайт -->
        <p class="text-center text-slate-600 text-xs mt-4">
            &copy; <?= date("Y") ?> <a href="https://minevpn.net" class="hover:text-violet-400 transition">MineVPN</a>
        </p>
    </div>

</body>
</html>
