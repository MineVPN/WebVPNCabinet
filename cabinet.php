<?php
// ==============================================================================
// MINE SERVER - Главная панель управления
// Версия: 2.1
// ==============================================================================

session_start();

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

if (isset($_SESSION['user_ip']) && $_SESSION['user_ip'] !== $_SERVER['REMOTE_ADDR']) {
    session_destroy();
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_POST['menu'])) {
    $_GET['menu'] = $_POST['menu'];
}

$wireguard_config_path = '/etc/wireguard/tun0.conf';
$openvpn_config_path = '/etc/openvpn/tun0.conf';

if (isset($_GET['menu'])) {
    $menu_item = $_GET['menu'];
} else {
    if (file_exists($wireguard_config_path)) {
        $menu_item = 'wireguard';
    } elseif (file_exists($openvpn_config_path)) {
        $menu_item = 'openvpn';
    } else {
        $menu_item = 'monitoring';
    }
}

$active_vpn_type = null;
if (file_exists($wireguard_config_path)) {
    $active_vpn_type = 'wireguard';
} elseif (file_exists($openvpn_config_path)) {
    $active_vpn_type = 'openvpn';
}

// Карта страниц - добавлен DHCP
$menu_pages = [
    'monitoring' => 'monitoring.php',
    'openvpn' => 'openvpn.php',
    'wireguard' => 'wireguard.php',
    'ping' => 'pinger.php',
    'logs' => 'logs.php',
    'dhcp' => 'dhcp.php',
    'netsettings' => 'netsettings.php',
    'settings' => 'settings.php',
    'about' => 'about.php'
];

if (!array_key_exists($menu_item, $menu_pages)) {
    $menu_item = 'monitoring';
}

$page_file = $menu_pages[$menu_item];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>MINE SERVER</title>
    <script src="tailwindcss.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #0F172A; }
        .glassmorphism { 
            background: rgba(30, 41, 59, 0.6);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .sidebar-link { transition: all 0.2s ease; }
        .sidebar-link:hover { background: rgba(139, 92, 246, 0.1); }
        .sidebar-link.active { background: rgba(139, 92, 246, 0.2); border-left: 3px solid #8b5cf6; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #1e293b; }
        ::-webkit-scrollbar-thumb { background-color: #475569; border-radius: 10px; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); position: fixed; z-index: 50; height: 100vh; }
            .sidebar.open { transform: translateX(0); }
            .overlay { display: none; }
            .overlay.open { display: block; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 40; }
        }
    </style>
    <script>
    window.CSRF_TOKEN = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';
    
    function Notice(text, type = 'success') {
        const notice = document.querySelector('.notice');
        if (notice) {
            notice.textContent = text;
            notice.classList.remove('hidden', 'bg-green-500/20', 'text-green-300', 'border-green-500/30',
                                    'bg-red-500/20', 'text-red-300', 'border-red-500/30');
            notice.classList.add(type === 'error' ? 'bg-red-500/20' : 'bg-green-500/20');
            notice.classList.add(type === 'error' ? 'text-red-300' : 'text-green-300');
            notice.classList.add(type === 'error' ? 'border-red-500/30' : 'border-green-500/30');
            setTimeout(() => notice.classList.add('hidden'), 5000);
        }
    }

    function updateSidebarPing() {
        const el = document.getElementById('sidebar-ping');
        if (!el) return;
        fetch('ping.php?host=8.8.8.8&interface=tun0')
            .then(r => r.text())
            .then(data => {
                if (data.indexOf("NO PING") === -1) {
                    const v = Math.round(parseFloat(data));
                    el.textContent = v + 'мс';
                    el.className = 'ml-auto text-xs font-mono px-2 py-0.5 rounded-full ' + 
                        (v < 50 ? 'bg-green-500/20 text-green-400' : v < 100 ? 'bg-yellow-500/20 text-yellow-400' : 'bg-red-500/20 text-red-400');
                } else {
                    el.textContent = 'X';
                    el.className = 'ml-auto text-xs font-mono px-2 py-0.5 rounded-full bg-red-500/20 text-red-400';
                }
            }).catch(() => {
                el.textContent = 'X';
                el.className = 'ml-auto text-xs font-mono px-2 py-0.5 rounded-full bg-red-500/20 text-red-400';
            });
    }
    
    function toggleSidebar() {
        document.querySelector('.sidebar').classList.toggle('open');
        document.querySelector('.overlay').classList.toggle('open');
    }
    
    document.addEventListener('DOMContentLoaded', () => {
        updateSidebarPing();
        setInterval(updateSidebarPing, 3000);
    });
    </script>
</head>
<body class="text-slate-300">
    <div class="overlay" onclick="toggleSidebar()"></div>
    
    <div class="flex min-h-screen">
        <aside class="sidebar w-56 flex-shrink-0 bg-slate-900 p-3 flex flex-col border-r border-slate-800 transition-transform duration-300">
            <a href="cabinet.php" class="p-3 mb-2 text-center block">
                <img src="logo.png" alt="Logo" class="w-20 h-20 mx-auto mb-1">
                <h1 class="text-base font-bold text-white">MINE SERVER</h1>
            </a>
            
            <nav class="flex flex-col gap-0.5 flex-grow text-sm">
                <a href="cabinet.php?menu=monitoring" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg <?= $menu_item == 'monitoring' ? 'active text-white' : 'text-slate-400' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    <span>Мониторинг</span>
                </a>
                
                <div class="border-t border-slate-800 my-1"></div>
                
                <a href="cabinet.php?menu=openvpn" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg <?= $menu_item == 'openvpn' ? 'active text-white' : 'text-slate-400' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    <span>OpenVPN</span>
                    <?php if ($active_vpn_type == 'openvpn'): ?>
                        <span id="sidebar-ping" class="ml-auto text-xs font-mono bg-slate-700 px-2 py-0.5 rounded-full">--</span>
                    <?php endif; ?>
                </a>
                
                <a href="cabinet.php?menu=wireguard" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg <?= $menu_item == 'wireguard' ? 'active text-white' : 'text-slate-400' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                    <span>WireGuard</span>
                    <?php if ($active_vpn_type == 'wireguard'): ?>
                        <span id="sidebar-ping" class="ml-auto text-xs font-mono bg-slate-700 px-2 py-0.5 rounded-full">--</span>
                    <?php endif; ?>
                </a>
                
                <div class="border-t border-slate-800 my-1"></div>
                
                <a href="cabinet.php?menu=ping" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg <?= $menu_item == 'ping' ? 'active text-white' : 'text-slate-400' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    <span>Ping тест</span>
                </a>
                
                <a href="cabinet.php?menu=logs" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg <?= $menu_item == 'logs' ? 'active text-white' : 'text-slate-400' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    <span>Логи</span>
                </a>
                
                <div class="border-t border-slate-800 my-1"></div>
                
                <a href="cabinet.php?menu=dhcp" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg <?= $menu_item == 'dhcp' ? 'active text-white' : 'text-slate-400' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"></path></svg>
                    <span>DHCP</span>
                </a>
                
                <a href="cabinet.php?menu=netsettings" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg <?= $menu_item == 'netsettings' ? 'active text-white' : 'text-slate-400' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.6 9h16.8M3.6 15h16.8"></path></svg>
                    <span>Сеть</span>
                </a>
                
                <a href="cabinet.php?menu=settings" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg <?= $menu_item == 'settings' ? 'active text-white' : 'text-slate-400' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <span>Настройки</span>
                </a>
                
                <a href="cabinet.php?menu=about" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg <?= $menu_item == 'about' ? 'active text-white' : 'text-slate-400' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span>О продукте</span>
                </a>
                
                <div class="flex-grow"></div>
                
                <a href="logout.php" class="sidebar-link flex items-center gap-2 px-3 py-2 rounded-lg text-slate-400 hover:bg-red-500/10 hover:text-red-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    <span>Выход</span>
                </a>
            </nav>
        </aside>
        
        <main class="flex-grow p-4 md:p-6 w-full overflow-auto">
            <!-- Мобильная кнопка меню -->
            <button onclick="toggleSidebar()" class="md:hidden fixed top-3 left-3 z-30 bg-slate-800 p-2 rounded-lg">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </button>
            
            <div class="glassmorphism rounded-2xl p-4 md:p-6 min-h-full">
                <div class="notice hidden p-4 rounded-xl border mb-4"></div>
                <?php include_once $page_file; ?>
                <div class="bg-sky-500/20 text-sky-300 p-3 rounded-xl border border-sky-500/30 mt-6 text-center text-sm">
                    VPN конфиги: <a href='https://minevpn.net/' target="_blank" class="font-bold hover:underline">MineVPN.net</a> | <a href='https://t.me/MineVpn_Bot' target="_blank" class="font-bold hover:underline">Telegram</a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
