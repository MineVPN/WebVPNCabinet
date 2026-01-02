<?php
// ==============================================================================
// MINE SERVER - Главная панель v5
// ==============================================================================

session_start();

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

// Защита от перехвата сессии
if (isset($_SESSION['user_ip']) && $_SESSION['user_ip'] !== $_SERVER['REMOTE_ADDR']) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// CSRF токен
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Определяем страницу
$menu = $_GET['menu'] ?? $_POST['menu'] ?? '';

// Проверяем есть ли VPN
$hasVpn = file_exists('/etc/wireguard/tun0.conf') || file_exists('/etc/openvpn/tun0.conf');

// Если не указано меню - выбираем по умолчанию
if (empty($menu)) {
    $menu = $hasVpn ? 'vpn' : 'monitoring';
}

// Карта страниц
$pages = [
    'monitoring' => 'monitoring.php',
    'vpn' => 'vpn.php',
    'logs' => 'logs.php',
    'dhcp' => 'dhcp.php',
    'netsettings' => 'netsettings.php',
    'settings' => 'settings.php',
    'about' => 'about.php'
];

if (!isset($pages[$menu])) {
    $menu = 'monitoring';
}

// Проверяем статус VPN для бейджа
$vpnStatus = 'off';
$tun0 = shell_exec('ip link show tun0 2>/dev/null');
if ($tun0 && (strpos($tun0, 'UP') !== false || strpos($tun0, 'UNKNOWN') !== false)) {
    $vpnStatus = 'on';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MINE SERVER</title>
    <link rel="icon" href="favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; background: #0f172a; }
        .glassmorphism { 
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .sidebar-link { transition: all 0.15s; }
        .sidebar-link:hover { background: rgba(139, 92, 246, 0.15); }
        .sidebar-link.active { 
            background: rgba(139, 92, 246, 0.25); 
            border-left: 3px solid #8b5cf6;
        }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #1e293b; }
        ::-webkit-scrollbar-thumb { background: #475569; border-radius: 3px; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); position: fixed; z-index: 50; height: 100vh; }
            .sidebar.open { transform: translateX(0); }
        }
    </style>
    <script>
        window.CSRF_TOKEN = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('open');
        }
    </script>
</head>
<body class="text-slate-300 min-h-screen">
    
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <aside class="sidebar w-52 flex-shrink-0 bg-slate-900/95 p-3 flex flex-col border-r border-slate-800">
            <a href="cabinet.php" class="p-3 text-center mb-2">
                <img src="logo.png" alt="Logo" class="w-16 h-16 mx-auto mb-1" onerror="this.style.display='none'">
                <h1 class="text-base font-bold text-white">MINE SERVER</h1>
            </a>
            
            <nav class="flex flex-col gap-0.5 flex-grow text-sm">
                <a href="?menu=monitoring" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg <?= $menu === 'monitoring' ? 'active text-white' : 'text-slate-400' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    Мониторинг
                </a>
                
                <div class="border-t border-slate-800 my-1.5"></div>
                
                <a href="?menu=vpn" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg <?= $menu === 'vpn' ? 'active text-white' : 'text-slate-400' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    VPN
                    <?php if ($vpnStatus === 'on'): ?>
                        <span id="vpn-ping" class="ml-auto text-xs font-mono bg-green-500/20 text-green-400 px-1.5 py-0.5 rounded">--</span>
                    <?php elseif ($hasVpn): ?>
                        <span class="ml-auto text-xs bg-red-500/20 text-red-400 px-1.5 py-0.5 rounded">OFF</span>
                    <?php endif; ?>
                </a>
                
                <a href="?menu=logs" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg <?= $menu === 'logs' ? 'active text-white' : 'text-slate-400' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Логи
                </a>
                
                <div class="border-t border-slate-800 my-1.5"></div>
                
                <a href="?menu=dhcp" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg <?= $menu === 'dhcp' ? 'active text-white' : 'text-slate-400' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"></path></svg>
                    DHCP
                </a>
                
                <a href="?menu=netsettings" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg <?= $menu === 'netsettings' ? 'active text-white' : 'text-slate-400' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"></path></svg>
                    Сеть
                </a>
                
                <a href="?menu=settings" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg <?= $menu === 'settings' ? 'active text-white' : 'text-slate-400' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    Настройки
                </a>
                
                <a href="?menu=about" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg <?= $menu === 'about' ? 'active text-white' : 'text-slate-400' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    О продукте
                </a>
                
                <div class="flex-grow"></div>
                
                <a href="logout.php" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg text-slate-400 hover:text-red-400 hover:bg-red-500/10">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    Выход
                </a>
            </nav>
        </aside>
        
        <!-- Main -->
        <main class="flex-grow p-4 md:p-6 overflow-auto">
            <!-- Mobile menu button -->
            <button onclick="toggleSidebar()" class="md:hidden fixed top-3 left-3 z-30 bg-slate-800 p-2 rounded-lg">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </button>
            
            <div class="glassmorphism rounded-2xl p-4 md:p-6 min-h-full">
                <?php include $pages[$menu]; ?>
                
                <div class="mt-6 p-3 bg-sky-500/10 border border-sky-500/20 rounded-xl text-center text-sm">
                    VPN конфиги: <a href="https://minevpn.net/" target="_blank" class="text-sky-400 hover:underline font-medium">MineVPN.net</a> | 
                    <a href="https://t.me/MineVpn_Bot" target="_blank" class="text-sky-400 hover:underline font-medium">Telegram</a>
                </div>
            </div>
        </main>
    </div>
    
    <?php if ($vpnStatus === 'on'): ?>
    <script>
    function updatePingBadge() {
        fetch('ping.php?host=8.8.8.8&interface=tun0')
            .then(r => r.text())
            .then(d => {
                const el = document.getElementById('vpn-ping');
                if (!el) return;
                if (d.indexOf('NO PING') === -1) {
                    el.textContent = Math.round(parseFloat(d)) + 'мс';
                    el.className = 'ml-auto text-xs font-mono px-1.5 py-0.5 rounded bg-green-500/20 text-green-400';
                } else {
                    el.textContent = '✕';
                    el.className = 'ml-auto text-xs font-mono px-1.5 py-0.5 rounded bg-red-500/20 text-red-400';
                }
            })
            .catch(() => {});
    }
    setInterval(updatePingBadge, 3000);
    updatePingBadge();
    </script>
    <?php endif; ?>
</body>
</html>
