<?php
// ==============================================================================
// MINE SERVER - О продукте
// ==============================================================================

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

$version_file = '/var/www/version';
$version = file_exists($version_file) ? trim(file_get_contents($version_file)) : '5';
?>

<div class="max-w-4xl mx-auto space-y-8">
    
    <div class="text-center">
        <img src="logo.png" alt="MINE SERVER" class="w-32 h-32 mx-auto mb-4">
        <h1 class="text-4xl font-bold text-white mb-2">MINE SERVER</h1>
        <p class="text-slate-400">Панель управления VPN-маршрутизатором</p>
        <div class="mt-4 inline-block bg-violet-600/20 text-violet-300 px-4 py-2 rounded-full">
            Версия <?= htmlspecialchars($version) ?>.0
        </div>
    </div>
    
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-xl font-bold text-white mb-6">Возможности</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="flex items-start gap-4 p-4 bg-slate-800/50 rounded-xl">
                <div class="w-10 h-10 bg-green-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                </div>
                <div>
                    <div class="text-white font-medium">Kill Switch</div>
                    <div class="text-slate-400 text-sm">Защита от утечек при обрыве VPN</div>
                </div>
            </div>
            <div class="flex items-start gap-4 p-4 bg-slate-800/50 rounded-xl">
                <div class="w-10 h-10 bg-blue-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                </div>
                <div>
                    <div class="text-white font-medium">Автовосстановление</div>
                    <div class="text-slate-400 text-sm">Автоматический перезапуск VPN</div>
                </div>
            </div>
            <div class="flex items-start gap-4 p-4 bg-slate-800/50 rounded-xl">
                <div class="w-10 h-10 bg-violet-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                </div>
                <div>
                    <div class="text-white font-medium">Мониторинг</div>
                    <div class="text-slate-400 text-sm">CPU, RAM, диск, сеть</div>
                </div>
            </div>
            <div class="flex items-start gap-4 p-4 bg-slate-800/50 rounded-xl">
                <div class="w-10 h-10 bg-orange-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                </div>
                <div>
                    <div class="text-white font-medium">OpenVPN + WireGuard</div>
                    <div class="text-slate-400 text-sm">Поддержка обоих протоколов</div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-xl font-bold text-white mb-6">Ссылки</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <a href="https://minevpn.net/" target="_blank" class="flex items-center gap-4 p-4 bg-slate-800/50 rounded-xl hover:bg-slate-700/50 transition">
                <div class="w-10 h-10 bg-violet-500/20 rounded-lg flex items-center justify-center">🌐</div>
                <div><div class="text-white font-medium">Сайт</div><div class="text-slate-400 text-sm">minevpn.net</div></div>
            </a>
            <a href="https://t.me/MineVpn_Bot" target="_blank" class="flex items-center gap-4 p-4 bg-slate-800/50 rounded-xl hover:bg-slate-700/50 transition">
                <div class="w-10 h-10 bg-blue-500/20 rounded-lg flex items-center justify-center">📱</div>
                <div><div class="text-white font-medium">Telegram</div><div class="text-slate-400 text-sm">@MineVpn_Bot</div></div>
            </a>
            <a href="https://github.com/MineVPN" target="_blank" class="flex items-center gap-4 p-4 bg-slate-800/50 rounded-xl hover:bg-slate-700/50 transition">
                <div class="w-10 h-10 bg-slate-500/20 rounded-lg flex items-center justify-center">💻</div>
                <div><div class="text-white font-medium">GitHub</div><div class="text-slate-400 text-sm">MineVPN</div></div>
            </a>
        </div>
    </div>
    
    <div class="text-center text-slate-500 text-sm">&copy; <?= date('Y') ?> MineVPN</div>
</div>
