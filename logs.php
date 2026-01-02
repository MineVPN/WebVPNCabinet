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
        <button onclick="loadLogs('system')" id="btn-system" class="log-btn px-4 py-2 rounded-lg bg-violet-600 text-white text-sm font-medium">Все VPN</button>
        <button onclick="loadLogs('openvpn')" id="btn-openvpn" class="log-btn px-4 py-2 rounded-lg bg-slate-700 text-slate-300 text-sm font-medium hover:bg-slate-600">OpenVPN</button>
        <button onclick="loadLogs('wireguard')" id="btn-wireguard" class="log-btn px-4 py-2 rounded-lg bg-slate-700 text-slate-300 text-sm font-medium hover:bg-slate-600">WireGuard</button>
        <button onclick="loadLogs('healthcheck')" id="btn-healthcheck" class="log-btn px-4 py-2 rounded-lg bg-slate-700 text-slate-300 text-sm font-medium hover:bg-slate-600">HealthCheck</button>
        <button onclick="loadLogs('dnsmasq')" id="btn-dnsmasq" class="log-btn px-4 py-2 rounded-lg bg-slate-700 text-slate-300 text-sm font-medium hover:bg-slate-600">DNS/DHCP</button>
        <button onclick="loadLogs('syslog')" id="btn-syslog" class="log-btn px-4 py-2 rounded-lg bg-slate-700 text-slate-300 text-sm font-medium hover:bg-slate-600">Syslog</button>
    </div>
    
    <div class="flex items-center justify-between flex-wrap gap-2">
        <div class="flex items-center gap-3">
            <select id="lines" onchange="loadLogs(currentType)" class="bg-slate-700 border border-slate-600 rounded px-2 py-1 text-white text-sm">
                <option value="50">50 строк</option>
                <option value="100" selected>100 строк</option>
                <option value="200">200 строк</option>
                <option value="500">500 строк</option>
            </select>
            <label class="flex items-center gap-2 text-sm text-slate-400 cursor-pointer">
                <input type="checkbox" id="auto" checked class="rounded"> Авто
            </label>
        </div>
        <div class="flex gap-2">
            <button onclick="loadLogs(currentType)" class="px-3 py-1 bg-slate-700 hover:bg-slate-600 rounded text-sm">Обновить</button>
            <button onclick="downloadLogs()" class="px-3 py-1 bg-slate-700 hover:bg-slate-600 rounded text-sm">Скачать</button>
        </div>
    </div>
    
    <input type="text" id="filter" placeholder="Фильтр..." oninput="filterLogs()"
        class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-2 text-white text-sm">
    
    <div id="log-window" class="h-96 bg-slate-900/70 rounded-lg p-3 font-mono text-xs overflow-y-auto">
        <div class="text-slate-400">Загрузка...</div>
    </div>
    
    <div class="flex justify-between text-xs text-slate-500">
        <span id="stats">--</span>
        <span id="time">--</span>
    </div>
</div>

<script>
let currentType = 'system';
let allLogs = [];
let autoInterval = null;
let isLoading = false;

async function loadLogs(type) {
    if (isLoading) return;
    isLoading = true;
    
    currentType = type;
    
    // Обновляем кнопки
    document.querySelectorAll('.log-btn').forEach(b => {
        b.className = 'log-btn px-4 py-2 rounded-lg text-sm font-medium ' + 
            (b.id === 'btn-' + type ? 'bg-violet-600 text-white' : 'bg-slate-700 text-slate-300 hover:bg-slate-600');
    });
    
    const lines = document.getElementById('lines').value;
    
    try {
        const r = await fetch(`api/logs.php?type=${type}&lines=${lines}`);
        const data = await r.json();
        
        allLogs = data.logs || [];
        renderLogs(allLogs);
        
        document.getElementById('stats').textContent = `Загружено: ${data.count}`;
        document.getElementById('time').textContent = new Date().toLocaleTimeString();
    } catch (e) {
        document.getElementById('log-window').innerHTML = '<div class="text-red-400">Ошибка загрузки</div>';
    }
    
    isLoading = false;
}

function renderLogs(logs) {
    const win = document.getElementById('log-window');
    const wasAtBottom = win.scrollHeight - win.scrollTop <= win.clientHeight + 50;
    
    if (logs.length === 0) {
        win.innerHTML = '<div class="text-slate-400">Логи пусты</div>';
        return;
    }
    
    win.innerHTML = logs.map(l => {
        const cls = l.level === 'error' ? 'text-red-400' : l.level === 'warning' ? 'text-yellow-400' : l.level === 'success' ? 'text-green-400' : 'text-slate-300';
        const time = l.time ? `<span class="text-slate-500">${formatTime(l.time)}</span> ` : '';
        return `<div class="py-0.5 ${cls}">${time}${escapeHtml(l.message)}</div>`;
    }).join('');
    
    if (wasAtBottom) win.scrollTop = win.scrollHeight;
}

function filterLogs() {
    const f = document.getElementById('filter').value.toLowerCase();
    renderLogs(f ? allLogs.filter(l => l.message.toLowerCase().includes(f)) : allLogs);
}

function downloadLogs() {
    const text = allLogs.map(l => `${l.time || ''} ${l.message}`).join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([text], {type: 'text/plain'}));
    a.download = `${currentType}_${new Date().toISOString().slice(0,10)}.log`;
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

function toggleAuto() {
    clearInterval(autoInterval);
    if (document.getElementById('auto').checked) {
        autoInterval = setInterval(() => loadLogs(currentType), 5000);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadLogs('system');
    document.getElementById('auto').addEventListener('change', toggleAuto);
    toggleAuto();
});
</script>
