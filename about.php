<?php
// Путь к файлу версии относительно about.php (каталог выше)
$version_file = __DIR__ . '/../version';
$product_version = 'N/A'; // Значение по умолчанию

// Чтение версии продукта
if (file_exists($version_file)) {
    // Читаем содержимое файла и удаляем пробельные символы
    $product_version = trim(file_get_contents($version_file));
    // Добавляем префикс "v" для лучшей читаемости
    $product_version = 'v' . $product_version;
}
?>

<div class="p-8 md:p-10 bg-gray-900 border border-slate-800 rounded-lg shadow-xl text-slate-100 max-w-lg mx-auto transition-all duration-300 hover:shadow-2xl hover:border-violet-700/50">
    
    <div class="flex flex-col items-center mb-6">
        <img src="logo.png" alt="Логотип MINE SERVER" class="w-50 h-50 mb-4 ">
        <h1 class="text-4xl font-extrabold tracking-tight text-violet-400">MINE SERVER</h1>
    </div>

    <hr class="border-t border-gray-700 my-6">

    <div class="mb-6 text-center">
        <p class="text-lg font-medium text-slate-300">
            Текущая версия: 
            <span class="text-green-400 font-bold ml-2 tracking-wider"><?php echo htmlspecialchars($product_version); ?></span>
        </p>
    </div>

    <p class="text-base text-slate-400 text-center leading-relaxed">
        Веб-панель MINE SERVER – это инструмент для управления, установки и мониторинга VPN-соединений (WireGuard и OpenVPN). Она обеспечивает централизованную настройку сети и контроль безопасности сервера.
    </p>

    <div class="pt-6 mt-6 border-t border-gray-800 text-center">
    <p class="text-sm text-slate-500">
        &copy; <?php echo date("Y"); ?> Все права принадлежат проекту 
        <a href="https://minevpn.net" target="_blank" class="font-bold text-violet-500 hover:text-violet-400 transition-colors duration-200">
            MineVPN
        </a>.
    </p>
</div>
</div>