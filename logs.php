<?php
// ==============================================================================
// MINE SERVER - Просмотр логов
// ==============================================================================

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}
?>

<div class="space-y-4">
    
    <div class="flex flex-wrap gap-2">
        <button onclick="loadLogs('system')" id="btn-system" class="log-btn active px-4 py-2 rounded-lg bg-violet-600 text-white text-sm font-medium transition">
            Все VPN
        </button>
        <button onclick="loadLogs('openvpn')" id="btn-openvpn" class="log-btn px-4 py-2 rounded-lg bg-slate-700 text-slate-300 text-sm font-medium transition hover:bg-slate-600">
            OpenVPN
        </button>
        <button onclick="loadLogs('wireguard')" id="btn-wireguard" class="log-btn px-4 py-2 rounded-lg bg-slate-700 text-slate-300 text-sm font-medium transition hover:bg-slate-600">
            WireGuard
        </button>
        <button onclick="loadLogs('healthcheck')" id="btn-healthcheck" class="log-btn px-4 py-2 rounded-lg bg-slate-700 text-slate-300 text-sm font-medium transition hover:bg-slate-600">
            Health Check
        </button>
        <button onclick="loadLogs('dnsmasq')" id="btn-dnsmasq" class="log-btn px-4 py-2 rounded-lg bg-slate-700 text-slate-300 text-sm font-medium transition hover:bg-slate-600">
            DNS/DHCP
        </button>
        <button onclick="loadLogs('syslog')" id="btn-syslog" class="log-btn px-4 py-2 rounded-lg bg-slate-700 text-slate-300 text-sm font-medium transition hover:bg-slate-600">
            Syslog
        </button>
    </div>
    
    <div class="flex items-center justify-between flex-wrap gap-2">
        <div class="flex items-center gap-3">
            <select id="lines-select" onchange="loadLogs(currentLogType)" class="bg-slate-700 border border-slate-600 rounded px-2 py-1 text-white text-sm">
                <option value="50">50</option>
                <option value="100" selected>100</option>
                <option value="200">200</option>
                <option value="500">500</option>
            </select>
            <label class="flex items-center gap-2 text-sm text-slate-400">
                <input type="checkbox" id="auto-refresh" class="rounded" checked> Авто
            </label>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="loadLogs(currentLogType)" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 rounded text-sm transition">Обновить</button>
            <button onclick="downloadLogs()" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 rounded text-sm transition">Скачать</button>
        </div>
    </div>
    
    <input type="text" id="log-filter" placeholder="Фильтр..." oninput="filterLogs()"
        class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-4 py-2 text-white text-sm focus:ring-2 focus:ring-violet-500 focus:outline-none">
    
    <div class="glassmorphism rounded-xl">
        <div id="log-window" class="h-[450px] bg-slate-900/70 rounded-lg p-3 font-mono text-xs overflow-y-auto">
            <div class="text-slate-400">Загрузка...</div>
        </div>
    </div>
    
    <div class="flex justify-between text-xs text-slate-500">
        <span id="log-stats">Загружено: 0</span>
        <span id="log-time">--</span>
    </div>
</div>

<script>
let currentLogType = 'system';
let allLogs = [];
let autoRefreshInterval = null;

async function loadLogs(type) {
    currentLogType = type;
    document.querySelectorAll('.log-btn').forEach(btn => {
        btn.classList.remove('active', 'bg-violet-600', 'text-white');
        btn.classList.add('bg-slate-700', 'text-slate-300');
    });
    const activeBtn = document.getElementById('btn-' + type);
    if (activeBtn) {
        activeBtn.classList.add('active', 'bg-violet-600', 'text-white');
        activeBtn.classList.remove('bg-slate-700', 'text-slate-300');
    }
    
    const lines = document.getElementById('lines-select').value;
    const logWindow = document.getElementById('log-window');
    logWindow.innerHTML = '<div class="text-slate-400">Загрузка...</div>';
    
    try {
        const response = await fetch(`api/logs.php?type=${type}&lines=${lines}`);
        const data = await response.json();
        allLogs = data.logs || [];
        renderLogs(allLogs);
        document.getElementById('log-stats').textContent = `Загружено: ${data.count}`;
        document.getElementById('log-time').textContent = new Date().toLocaleTimeString();
    } catch (error) {
        logWindow.innerHTML = `<div class="text-red-400">Ошибка: ${error.message}</div>`;
    }
}

function renderLogs(logs) {
    const logWindow = document.getElementById('log-window');
    if (logs.length === 0) {
        logWindow.innerHTML = '<div class="text-slate-400">Логи пусты</div>';
        return;
    }
    let html = '';
    logs.forEach(log => {
        const levelClass = log.level === 'error' ? 'text-red-400' : log.level === 'warning' ? 'text-yellow-400' : log.level === 'success' ? 'text-green-400' : 'text-slate-300';
        const time = log.time ? `<span class="text-slate-500">${formatTime(log.time)}</span> ` : '';
        html += `<div class="py-0.5 hover:bg-slate-800/30 ${levelClass}">${time}${escapeHtml(log.message)}</div>`;
    });
    logWindow.innerHTML = html;
    logWindow.scrollTop = logWindow.scrollHeight;
}

function filterLogs() {
    const filter = document.getElementById('log-filter').value.toLowerCase();
    renderLogs(filter ? allLogs.filter(l => l.message.toLowerCase().includes(filter)) : allLogs);
}

function downloadLogs() {
    const text = allLogs.map(l => `${l.time || ''} ${l.message}`).join('\n');
    const blob = new Blob([text], { type: 'text/plain' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `${currentLogType}_${new Date().toISOString().slice(0,10)}.log`;
    a.click();
}

function formatTime(t) {
    try { return new Date(t).toLocaleTimeString(); } catch { return t; }
}

function escapeHtml(t) {
    const d = document.createElement('div');
    d.textContent = t;
    return d.innerHTML;
}

document.addEventListener('DOMContentLoaded', () => {
    loadLogs('system');
    const cb = document.getElementById('auto-refresh');
    cb.addEventListener('change', () => {
        clearInterval(autoRefreshInterval);
        if (cb.checked) autoRefreshInterval = setInterval(() => loadLogs(currentLogType), 5000);
    });
    autoRefreshInterval = setInterval(() => loadLogs(currentLogType), 5000);
});
</script>
