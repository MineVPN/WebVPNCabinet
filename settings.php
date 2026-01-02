<?php
// ==============================================================================
// MINE SERVER - Настройки системы
// ==============================================================================

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

$settings_file = '/var/www/settings';
$version_file = '/var/www/version';

// Чтение текущих настроек
function getSettings() {
    global $settings_file;
    $settings = [
        'vpnchecker' => true,
        'autoupvpn' => true
    ];
    
    if (file_exists($settings_file)) {
        $content = file_get_contents($settings_file);
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $settings[trim($key)] = trim($value) === 'true';
            }
        }
    }
    
    return $settings;
}

// Сохранение настроек
function saveSettings($settings) {
    global $settings_file;
    $content = '';
    foreach ($settings as $key => $value) {
        $content .= $key . '=' . ($value ? 'true' : 'false') . "\n";
    }
    file_put_contents($settings_file, $content);
}

// Получение версии
function getVersion() {
    global $version_file;
    if (file_exists($version_file)) {
        return trim(file_get_contents($version_file));
    }
    return '1';
}

// Обработка POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        
        if (isset($_POST['save_settings'])) {
            $settings = [
                'vpnchecker' => isset($_POST['vpnchecker']),
                'autoupvpn' => isset($_POST['autoupvpn'])
            ];
            saveSettings($settings);
            echo "<script>Notice('Настройки сохранены!');</script>";
        }
        
        if (isset($_POST['reboot_server'])) {
            shell_exec('(sleep 2 && sudo reboot) > /dev/null 2>&1 &');
            echo "<script>Notice('Сервер перезагружается...');</script>";
        }
        
        if (isset($_POST['restart_vpn'])) {
            shell_exec('sudo systemctl restart openvpn@tun0 2>/dev/null');
            shell_exec('sudo systemctl restart wg-quick@tun0 2>/dev/null');
            echo "<script>Notice('VPN перезапущен!');</script>";
        }
        
        if (isset($_POST['update_now'])) {
            shell_exec('sudo /usr/local/bin/mineserver-update.sh > /dev/null 2>&1 &');
            echo "<script>Notice('Обновление запущено в фоне');</script>";
        }
    }
}

$settings = getSettings();
$version = getVersion();
$csrf_token = $_SESSION['csrf_token'] ?? '';
?>

<div class="space-y-8">
    
    <!-- Информация о системе -->
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-xl font-bold text-white mb-6">Информация о системе</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-slate-800/50 rounded-xl p-4">
                <div class="text-slate-400 text-sm mb-1">Версия панели</div>
                <div class="text-white text-xl font-bold">v<?= htmlspecialchars($version) ?>.0</div>
            </div>
            <div class="bg-slate-800/50 rounded-xl p-4">
                <div class="text-slate-400 text-sm mb-1">Hostname</div>
                <div class="text-white text-xl font-bold"><?= htmlspecialchars(gethostname()) ?></div>
            </div>
            <div class="bg-slate-800/50 rounded-xl p-4">
                <div class="text-slate-400 text-sm mb-1">PHP версия</div>
                <div class="text-white text-xl font-bold"><?= phpversion() ?></div>
            </div>
            <div class="bg-slate-800/50 rounded-xl p-4">
                <div class="text-slate-400 text-sm mb-1">ОС</div>
                <div class="text-white text-xl font-bold"><?= php_uname('s') . ' ' . php_uname('r') ?></div>
            </div>
        </div>
    </div>
    
    <!-- Настройки VPN -->
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-xl font-bold text-white mb-6">Настройки VPN</h2>
        
        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            
            <div class="space-y-4">
                <!-- VPN Checker -->
                <label class="flex items-center justify-between p-4 bg-slate-800/50 rounded-xl cursor-pointer hover:bg-slate-800 transition">
                    <div>
                        <div class="text-white font-medium">Мониторинг VPN</div>
                        <div class="text-slate-400 text-sm">Проверка состояния VPN каждые 30 секунд</div>
                    </div>
                    <div class="relative">
                        <input type="checkbox" name="vpnchecker" class="sr-only peer" <?= $settings['vpnchecker'] ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-slate-600 rounded-full peer peer-checked:bg-violet-600 transition-colors"></div>
                        <div class="absolute left-1 top-1 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-5"></div>
                    </div>
                </label>
                
                <!-- Auto VPN -->
                <label class="flex items-center justify-between p-4 bg-slate-800/50 rounded-xl cursor-pointer hover:bg-slate-800 transition">
                    <div>
                        <div class="text-white font-medium">Автовосстановление VPN</div>
                        <div class="text-slate-400 text-sm">Автоматический перезапуск при обрыве соединения</div>
                    </div>
                    <div class="relative">
                        <input type="checkbox" name="autoupvpn" class="sr-only peer" <?= $settings['autoupvpn'] ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-slate-600 rounded-full peer peer-checked:bg-violet-600 transition-colors"></div>
                        <div class="absolute left-1 top-1 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-5"></div>
                    </div>
                </label>
            </div>
            
            <button type="submit" name="save_settings" class="w-full bg-violet-600 text-white font-bold py-3 rounded-lg hover:bg-violet-700 transition">
                Сохранить настройки
            </button>
        </form>
    </div>
    
    <!-- Управление сервером -->
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-xl font-bold text-white mb-6">Управление сервером</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <button type="submit" name="restart_vpn" class="w-full bg-slate-700 hover:bg-slate-600 text-white font-medium py-3 px-4 rounded-lg transition flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    Перезапустить VPN
                </button>
            </form>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <button type="submit" name="update_now" class="w-full bg-slate-700 hover:bg-slate-600 text-white font-medium py-3 px-4 rounded-lg transition flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                    Обновить панель
                </button>
            </form>
            
            <form method="POST" onsubmit="return confirm('Вы уверены, что хотите перезагрузить сервер?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <button type="submit" name="reboot_server" class="w-full bg-orange-600 hover:bg-orange-700 text-white font-medium py-3 px-4 rounded-lg transition flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    Перезагрузить
                </button>
            </form>
            
            <a href="logout.php" class="w-full bg-red-600 hover:bg-red-700 text-white font-medium py-3 px-4 rounded-lg transition flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                Выйти
            </a>
        </div>
    </div>
    
    <!-- Статус служб -->
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-xl font-bold text-white mb-6">Статус служб</h2>
        
        <div class="space-y-2" id="services-container">
            <div class="text-slate-400">Загрузка...</div>
        </div>
    </div>
    
</div>

<script>
async function loadServices() {
    try {
        const response = await fetch('api/server.php?action=services_status');
        const services = await response.json();
        
        const container = document.getElementById('services-container');
        container.innerHTML = '';
        
        services.forEach(service => {
            const statusClass = service.active 
                ? 'bg-green-500/20 text-green-400' 
                : 'bg-red-500/20 text-red-400';
            const statusText = service.active ? 'Активна' : 'Остановлена';
            const enabledBadge = service.enabled 
                ? '<span class="text-xs bg-blue-500/20 text-blue-400 px-2 py-0.5 rounded">Автозапуск</span>' 
                : '';
            
            container.innerHTML += `
                <div class="flex items-center justify-between p-3 bg-slate-800/50 rounded-lg">
                    <div class="flex items-center gap-3">
                        <div class="w-2 h-2 rounded-full ${service.active ? 'bg-green-500' : 'bg-red-500'}"></div>
                        <span class="text-white font-medium">${service.name}</span>
                        ${enabledBadge}
                    </div>
                    <span class="text-xs px-2 py-1 rounded ${statusClass}">${statusText}</span>
                </div>
            `;
        });
    } catch (error) {
        console.error('Error loading services:', error);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadServices();
    setInterval(loadServices, 10000);
});
</script>

<style>
/* Custom toggle switch */
input[type="checkbox"]:checked + div + div {
    transform: translateX(1.25rem);
}
</style>
