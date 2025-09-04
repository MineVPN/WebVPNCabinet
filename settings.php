<?php

// Проверка аутентификации
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

// ===================================================================
// ОБНОВЛЕНО: МАССИВ С РУССКИМИ ИМЕНАМИ И ОПИСАНИЯМИ
// ===================================================================
// Теперь для каждого ключа можно задать 'name' (название) и 'description' (описание).
$settingNames = [
    'vpnchecker' => [
        'name' => 'Автовосстановление работоспособности VPN-тоннеля',
        'description' => 'Система будет периодически проверять работу VPN-тоннеля и перезапускать его в случае сбоя. (для случаев когда тонель tun0 висит но интернета нету).'
    ],
    'autoupvpn' => [
        'name' => 'Автозапуск VPN-тоннеля при падении',
        'description' => 'Система будет проверять каждых 30 сек наличие VPN-тоннеля, и при падении запускать его автоматически (для случаев тогда tun0 пропадает).'
    ]
];
// ===================================================================

// Имя файла с настройками
$settingsFile = '../settings';

// --- Функции readSimpleSettings и writeSimpleSettings остаются без изменений ---

/**
 * Читает настройки из файла key=value
 */
function readSimpleSettings($filePath) {
    if (!file_exists($filePath)) {
        return [];
    }
    $settings = [];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $settings[trim($key)] = (trim($value) === 'true');
        }
    }
    return $settings;
}

/**
 * Записывает настройки в файл
 */
function writeSimpleSettings($filePath, $settings) {
    $content = '';
    foreach ($settings as $key => $value) {
        $content .= $key . '=' . ($value ? 'true' : 'false') . "\n";
    }
    file_put_contents($filePath, $content);
}

// Обработка формы сохранения настроек
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $existingSettings = readSimpleSettings($settingsFile);
    $newSettings = [];
    foreach (array_keys($existingSettings) as $key) {
        $newSettings[$key] = isset($_POST[$key]);
    }
    writeSimpleSettings($settingsFile, $newSettings);
    echo "<script>Notice('Настройки успешно сохранены!', 'success');</script>";
}

// Читаем текущие настройки для отображения на странице
$settings = readSimpleSettings($settingsFile);

?>

<form method="post" class="space-y-8">
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-2xl font-bold text-white mb-6 border-b border-slate-700 pb-4">
            Настройки сервера
        </h2>

        <div class="space-y-6">
            <?php if (empty($settings)): ?>
                <p class="text-slate-400">Файл настроек `settings` пуст или не найден.</p>
            <?php else: ?>
                <?php foreach ($settings as $key => $value): ?>
                    <?php
                        // Получаем название и описание из обновленного массива
                        $displayName = isset($settingNames[$key]['name']) ? $settingNames[$key]['name'] : ucfirst(str_replace('_', ' ', $key));
                        $description = isset($settingNames[$key]['description']) ? $settingNames[$key]['description'] : '';
                    ?>
                    <div class="flex justify-between items-center gap-4">
                        <div>
                            <label for="<?= htmlspecialchars($key) ?>" class="text-slate-200 font-medium">
                                <?= htmlspecialchars($displayName) ?>
                            </label>
                            
                            <?php if (!empty($description)): ?>
                                <p class="text-sm text-slate-400 mt-1 max-w-lg">
                                    <?= htmlspecialchars($description) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <label for="<?= htmlspecialchars($key) ?>" class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" id="<?= htmlspecialchars($key) ?>" name="<?= htmlspecialchars($key) ?>" class="sr-only peer" <?= $value ? 'checked' : '' ?>>
                            <div class="w-11 h-6 bg-slate-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-violet-600"></div>
                        </label>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <button type="submit" name="save_settings" class="w-full sm:w-auto bg-violet-600 text-white font-bold py-3 px-8 rounded-lg hover:bg-violet-700 transition-all">
            Сохранить настройки
        </button>
    </div>
</form>
