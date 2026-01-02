<?php
// ==============================================================================
// MINE SERVER - Просмотр логов
// ==============================================================================

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}
?>

<div class="space-y-6">
    
    <!-- Выбор типа логов -->
    <div class="flex flex-wrap gap-2">
        <button onclick="loadLogs('system')" id="btn-system" class="log-btn active px-4 py-2 rounded-lg bg-violet-600 text-white font-medium transition">
            Все VPN
        </button>
        <button onclick="loadLogs('openvpn')" id="btn-openvpn" class="log-btn px-4 py-2 rounded-lg bg-slate-700 text-slate-300 font-medium transition hover:bg-slate-600">
            OpenVPN
        </button>
        <button onclick="loadLogs('wireguard')" id="btn-wireguard" class="log-btn px-4 py-2 rounded-lg bg-slate-700 text-slate-300 font-medium transition hover:bg-slate-600">
            WireGuard
        </button>
        <button onclick="loadLogs('healthcheck')" id="btn-healthcheck" class="log-btn px-4 py-2 rounded-lg bg-slate-700 text-slate-300 font-medium transition hover:bg-slate-600">
            Health Check
        </button>
        <button onclick="loadLogs('dnsmasq')" id="btn-dnsmasq" class="log-btn px-4 py-2 rounded-lg bg-slate-700 text-slate-300 font-medium transition hover:bg-slate-600">
            DNS/DHCP
        </button>
        <button onclick="loadLogs('auth')" id="btn-auth" class="log-btn px-4 py-2 rounded-lg bg-slate-700 text-slate-300 font-medium transition hover:bg-slate-600">
            Авторизация
        </button>
    </div>
    
    <!-- Панель управления -->
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
            <label class="flex items-center gap-2 text-sm text-slate-400">
                <span>Строк:</span>
                <select id="lines-select" onchange="loadLogs(currentLogType)" class="bg-slate-700 border border-slate-600 rounded px-2 py-1 text-white">
                    <option value="50">50</option>
                    <option value="100" selected>100</option>
                    <option value="200">200</option>
                    <option value="500">500</option>
                </select>
            </label>
            
            <label class="flex items-center gap-2 text-sm text-slate-400">
                <input type="checkbox" id="auto-refresh" class="rounded bg-slate-700 border-slate-600" checked>
                <span>Автообновление</span>
            </label>
        </div>
        
        <div class="flex items-center gap-2">
            <button onclick="loadLogs(currentLogType)" class="px-3 py-1 bg-slate-700 hover:bg-slate-600 rounded text-sm transition">
                Обновить
            </button>
            <button onclick="clearLogs()" class="px-3 py-1 bg-slate-700 hover:bg-slate-600 rounded text-sm transition">
                Очистить
            </button>
            <button onclick="downloadLogs()" class="px-3 py-1 bg-slate-700 hover:bg-slate-600 rounded text-sm transition">
                Скачать
            </button>
        </div>
    </div>
    
    <!-- Фильтр -->
    <div class="relative">
        <input type="text" id="log-filter" placeholder="Фильтр логов..." 
            class="w-full bg-slate-700/50 border border-slate-600 rounded-lg px-4 py-2 text-white placeholder-slate-400 focus:ring-2 focus:ring-violet-500 focus:outline-none"
            oninput="filterLogs()">
        <svg class="w-5 h-5 absolute right-3 top-2.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
        </svg>
    </div>
    
    <!-- Окно логов -->
    <div class="glassmorphism rounded-xl p-1">
        <div id="log-window" class="h-[500px] bg-slate-900/70 rounded-lg p-4 font-mono text-sm overflow-y-auto">
            <div class="text-slate-400">Загрузка логов...</div>
        </div>
    </div>
    
    <!-- Статистика -->
    <div class="flex justify-between text-xs text-slate-500">
        <span id="log-stats">Загружено: 0 записей</span>
        <span id="log-time">Последнее обновление: --</span>
    </div>
</div>

<script>
let currentLogType = 'system';
let allLogs = [];
let autoRefreshInterval = null;

// Загрузка логов
async function loadLogs(type) {
    currentLogType = type;
    
    // Обновляем активную кнопку
    document.querySelectorAll('.log-btn').forEach(btn => {
        btn.classList.remove('active', 'bg-violet-600');
        btn.classList.add('bg-slate-700');
    });
    const activeBtn = document.getElementById('btn-' + type);
    if (activeBtn) {
        activeBtn.classList.add('active', 'bg-violet-600');
        activeBtn.classList.remove('bg-slate-700');
    }
    
    const lines = document.getElementById('lines-select').value;
    const logWindow = document.getElementById('log-window');
    
    try {
        const response = await fetch(`api/logs.php?type=${type}&lines=${lines}`);
        const data = await response.json();
        
        allLogs = data.logs || [];
        renderLogs(allLogs);
        
        document.getElementById('log-stats').textContent = `Загружено: ${data.count} записей`;
        document.getElementById('log-time').textContent = `Последнее обновление: ${new Date().toLocaleTimeString()}`;
        
    } catch (error) {
        logWindow.innerHTML = `<div class="text-red-400">Ошибка загрузки логов: ${error.message}</div>`;
    }
}

// Отрисовка логов
function renderLogs(logs) {
    const logWindow = document.getElementById('log-window');
    
    if (logs.length === 0) {
        logWindow.innerHTML = '<div class="text-slate-400">Логи не найдены</div>';
        return;
    }
    
    let html = '';
    logs.forEach(log => {
        const levelClass = getLevelClass(log.level);
        const time = log.time ? `<span class="text-slate-500">${formatTime(log.time)}</span> ` : '';
        const message = escapeHtml(log.message);
        
        html += `<div class="log-line py-0.5 hover:bg-slate-800/50 ${levelClass}">${time}${message}</div>`;
    });
    
    logWindow.innerHTML = html;
    
    // Прокрутка вниз
    logWindow.scrollTop = logWindow.scrollHeight;
}

// Фильтрация логов
function filterLogs() {
    const filter = document.getElementById('log-filter').value.toLowerCase();
    
    if (!filter) {
        renderLogs(allLogs);
        return;
    }
    
    const filtered = allLogs.filter(log => 
        log.message.toLowerCase().includes(filter)
    );
    
    renderLogs(filtered);
}

// Очистка окна
function clearLogs() {
    document.getElementById('log-window').innerHTML = '<div class="text-slate-400">Логи очищены</div>';
    allLogs = [];
}

// Скачивание логов
function downloadLogs() {
    const text = allLogs.map(log => {
        const time = log.time || '';
        return `${time} ${log.message}`;
    }).join('\n');
    
    const blob = new Blob([text], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${currentLogType}_logs_${new Date().toISOString().slice(0,10)}.txt`;
    a.click();
    URL.revokeObjectURL(url);
}

// Получение класса по уровню лога
function getLevelClass(level) {
    switch (level) {
        case 'error': return 'text-red-400';
        case 'warning': return 'text-yellow-400';
        case 'success': return 'text-green-400';
        default: return 'text-slate-300';
    }
}

// Форматирование времени
function formatTime(timeStr) {
    if (!timeStr) return '';
    
    try {
        const date = new Date(timeStr);
        return date.toLocaleTimeString();
    } catch {
        return timeStr.split('T')[1]?.split('+')[0] || timeStr;
    }
}

// Экранирование HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Автообновление
function toggleAutoRefresh() {
    const checkbox = document.getElementById('auto-refresh');
    
    if (checkbox.checked) {
        autoRefreshInterval = setInterval(() => loadLogs(currentLogType), 5000);
    } else {
        clearInterval(autoRefreshInterval);
    }
}

// Инициализация
document.addEventListener('DOMContentLoaded', () => {
    loadLogs('system');
    
    document.getElementById('auto-refresh').addEventListener('change', toggleAutoRefresh);
    toggleAutoRefresh();
});
</script>

<style>
.log-line {
    white-space: pre-wrap;
    word-break: break-all;
}
</style>
