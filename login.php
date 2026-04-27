<?php
/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ══════════════════════════════════════════════════════════════════
 *                L O G I N   P A G E   F I L E
 * ══════════════════════════════════════════════════════════════════
 * * @category    VPN Subsystem
 * * @package     MineVPN\Server
 * * @version     5.0.0
 * * [WARNING] 
 * This source code is strictly proprietary and confidential. 
 * Unauthorized reproduction, distribution, or decompilation 
 * is strictly prohibited and heavily monitored.
 * * @copyright   2026 MineVPN Systems. All rights reserved.
 *
 * MineVPN Server — Login page / Форма авторизации
 *
 * Standalone страница (не через cabinet.php) — подключает CSS сама, не использует layout.css
 * (нет sidebar). Проверяет введённый пароль против системного root через su + proc_open.
 *
 * При успешной авторизации:
 *   • session_regenerate_id(true) — защита от session fixation
 *   • $_SESSION["authenticated"] = true
 *   • $_SESSION["login_time"] + $_SESSION["ip"] — для проверок timeout/hijacking в cabinet.php
 *   • Redirect на index.php
 *
 * Безопасность:
 *   • Command Injection protection — пароль передаётся через stdin proc_open, не в cmdline
 *   • Brute force: 5 попыток / 5 мин lockout в /tmp/minevpn_login_attempts_{md5_ip}
 *     с flock от race condition при параллельных POST
 *   • IP проверка на смену IP — в cabinet.php при каждом запросе
 *
 * Взаимодействует с:
 *   • index.php — редирект после успешного входа
 *   • cabinet.php — получает валидную сессию для доступа к панели
 *   • logout.php — редирект сюда после выхода
 *   • /tmp/minevpn_login_attempts_* — rate limiting (создаётся/читается/очищается)
 *   • system — вызывает /bin/su -c "id" для проверки root пароля (через sudoers NOPASSWD)
 *   • assets/css/{tokens,base,components}.css — стилизация формы
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Если уже авторизован — сразу в кабинет
if (isset($_SESSION["authenticated"]) && $_SESSION["authenticated"] === true) {
    header("Location: index.php");
    exit();
}

$error_message = "";
$login_attempts_file = "/tmp/minevpn_login_attempts_" . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');

// Сообщение о выходе через таймаут
if (($_GET['reason'] ?? '') === 'timeout') {
    $error_message = "Сессия завершена из-за неактивности. Введите пароль снова.";
}

/**
 * Brute force protection (flock — исключает race condition при параллельных POST).
 */
function check_brute_force(string $attempts_file): bool {
    $max_attempts  = 5;
    $lockout_time  = 300;

    if (!file_exists($attempts_file)) return true;

    $fp = fopen($attempts_file, 'c+');
    if (!$fp) return true;
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $data = json_decode($content, true);
    if (!$data) return true;

    if (isset($data['lockout_until']) && time() < $data['lockout_until']) return false;

    $recent = array_filter($data['attempts'] ?? [], fn($t) => $t > (time() - $lockout_time));
    return count($recent) < $max_attempts;
}

function record_failed_attempt(string $attempts_file): void {
    $max_attempts = 5;
    $lockout_time = 300;

    $fp = fopen($attempts_file, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);

    $content = stream_get_contents($fp);
    $data = json_decode($content, true) ?: ['attempts' => []];
    $data['attempts'][] = time();
    $data['attempts'] = array_values(array_filter($data['attempts'], fn($t) => $t > (time() - $lockout_time)));

    if (count($data['attempts']) >= $max_attempts) {
        $data['lockout_until'] = time() + $lockout_time;
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function clear_attempts(string $attempts_file): void {
    if (file_exists($attempts_file)) unlink($attempts_file);
}

/**
 * Проверка root пароля через proc_open + stdin (безопасно против Command Injection).
 */
function verify_root_password(string $password): bool {
    if (empty($password) || strlen($password) > 256) return false;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open('su -c "id" root', $descriptors, $pipes);
    if (!is_resource($process)) return false;

    fwrite($pipes[0], $password . "\n");
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]);            fclose($pipes[2]);
    $return_code = proc_close($process);

    return ($return_code === 0 && strpos($stdout, 'uid=0') !== false);
}

// POST handler
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["password"])) {
    if (!check_brute_force($login_attempts_file)) {
        $error_message = "Слишком много попыток. Подождите 5 минут.";
    } else {
        $password = $_POST["password"];
        if (verify_root_password($password)) {
            session_regenerate_id(true);
            $_SESSION["authenticated"] = true;
            $_SESSION["login_time"]    = time();
            $_SESSION["ip"]            = $_SERVER['REMOTE_ADDR'] ?? '';
            clear_attempts($login_attempts_file);
            header("Location: index.php");
            exit();
        } else {
            record_failed_attempt($login_attempts_file);
            $error_message = "Неверный пароль.";
        }
    }
}

$cssVer = '5.5.1';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <title>Server VPN Panel — Вход</title>

    <link rel="stylesheet" href="assets/css/tokens.css?v=<?php echo $cssVer; ?>">
    <link rel="stylesheet" href="assets/css/base.css?v=<?php echo $cssVer; ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?php echo $cssVer; ?>">

    <style>
    /* ── Page-specific styles для login ── */
    .login-viewport {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: var(--space-4);
    }

    .login-card {
        width: 100%;
        max-width: 420px;
        background: var(--surface-1);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius-2xl);
        padding: var(--space-10) var(--space-8);
        text-align: center;
    }

    .login-logo {
        width: 160px;
        height: 160px;
        object-fit: contain;
        margin: 0 auto var(--space-5);
        animation: login-float 6s ease-in-out infinite;
    }

    @keyframes login-float {
        0%, 100% { transform: translateY(0); }
        50%      { transform: translateY(-8px); }
    }

    .login-title {
        font-size: var(--text-2xl);
        font-weight: 800;
        letter-spacing: var(--tracking-tight);
        margin-bottom: var(--space-1);
        background: var(--grad-text-brand);
        -webkit-background-clip: text;
        background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .login-sub {
        color: var(--text-muted);
        font-size: var(--text-sm);
        margin-bottom: var(--space-8);
    }

    .login-form {
        display: flex;
        flex-direction: column;
        gap: var(--space-4);
        text-align: left;
    }

    .login-pass-wrap {
        position: relative;
    }

    .login-pass-toggle {
        position: absolute;
        right: var(--space-3);
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        padding: var(--space-2);
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: var(--radius-sm);
        transition: color var(--dur-fast) var(--ease);
    }
    .login-pass-toggle:hover { color: var(--text-primary); }

    .login-error {
        padding: var(--space-3);
        background: var(--rose-soft);
        border: 1px solid var(--rose-border);
        border-radius: var(--radius-md);
        color: #FDA4AF;
        font-size: var(--text-sm);
        text-align: center;
    }
    </style>
</head>
<body>

<div class="login-viewport">
    <div class="login-card">

        <img src="assets/img/logo.png" alt="" class="login-logo">
        <h1 class="login-title">Server VPN Panel</h1>
        <div class="login-sub">Панель управления</div>

        <form method="POST" action="login.php" class="login-form" autocomplete="off">
            <div>
                <label for="password" class="label">Пароль администратора (root)</label>
                <div class="login-pass-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        required
                        autocomplete="current-password"
                        class="input"
                        placeholder="••••••••"
                        style="padding-right: 44px;">
                    <button type="button" class="login-pass-toggle" onclick="togglePassword()" aria-label="Показать пароль">
                        <svg id="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" width="18" height="18">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                    </button>
                </div>
            </div>

            <?php if (!empty($error_message)): ?>
            <div class="login-error">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn--primary btn--lg btn--block">
                Войти
            </button>
        </form>
    </div>
</div>

<script>
function togglePassword() {
    const input = document.getElementById('password');
    const icon  = document.getElementById('eye-icon');
    const open = `
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>`;
    const closed = `
        <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>`;

    if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = closed;
    } else {
        input.type = 'password';
        icon.innerHTML = open;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('password').focus();
});
</script>

</body>
</html>
