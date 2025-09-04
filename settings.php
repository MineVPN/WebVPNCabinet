<?php
// ----- PHP-ЛОГИКА ДЛЯ СТРАНИЦЫ НАСТРОЕК -----
session_start();

// Проверка аутентификации (как в вашем примере)
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

// Имя файла с настройками
$settingsFile = 'settings';

/**
 * Читает настройки из файла key=value
 * @param string $filePath Путь к файлу
 * @return array Ассоциативный массив с настройками
 */
function readSimpleSettings($filePath) {
    if (!file_exists($filePath)) {
        return []; // Если файла нет, возвращаем пустой массив
    }
    $settings = [];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            // Приводим строковое значение 'true'/'false' к boolean
            $settings[trim($key)] = (trim($value) === 'true');
        }
    }
    return $settings;
}

/**
 * Записывает настройки в файл
 * @param string $filePath Путь к файлу
 * @param array $settings Ассоциативный массив с настройками
 */
function writeSimpleSettings($filePath, $settings) {
    $content = '';
    foreach ($settings as $key => $value) {
        // Приводим boolean к строке 'true'/'false'
        $content .= $key . '=' . ($value ? 'true' : 'false') . "\n";
    }
    file_put_contents($filePath, $content);
}

// Обработка формы сохранения настроек
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    // Сначала читаем существующие настройки, чтобы знать все возможные ключи
    $existingSettings = readSimpleSettings($settingsFile);
    $newSettings = [];

    // Перебираем все ключи, которые были в файле
    foreach (array_keys($existingSettings) as $key) {
        // Если ключ пришел в POST-запросе, значит checkbox был включен (true)
        // Если не пришел - значит выключен (false)
        $newSettings[$key] = isset($_POST[$key]);
    }

    writeSimpleSettings($settingsFile, $newSettings);

    // Показываем уведомление об успехе
    echo "<script>Notice('Настройки успешно сохранены!', 'success');</script>";
}

// Читаем текущие настройки для отображения на странице
$settings = readSimpleSettings($settingsFile);

?>

<form method="post" class="space-y-8">
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-2xl font-bold text-white mb-6 border-b border-slate-700 pb-4">
            Общие настройки
        </h2>

        <div class="space-y-6">
            <?php if (empty($settings)): ?>
                <p class="text-slate-400">Файл настроек `settings` пуст или не найден.</p>
            <?php else: ?>
                <?php foreach ($settings as $key => $value): ?>
                    <div class="grid grid-cols-1 md:grid-cols-3 items-center gap-4">
                        <label for="<?= htmlspecialchars($key) ?>" class="text-slate-300 font-medium">
                            Автовосстановление VPN-туннеля
                        </label>
                        
                        <div class="md:col-span-2">
                            <label for="<?= htmlspecialchars($key) ?>" class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" id="<?= htmlspecialchars($key) ?>" name="<?= htmlspecialchars($key) ?>" class="sr-only peer" <?= $value ? 'checked' : '' ?>>
                                <div class="w-11 h-6 bg-slate-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-violet-600"></div>
                            </label>
                        </div>
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
