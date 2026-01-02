<?php
// ==============================================================================
// MINE SERVER - Мониторинг системы
// ==============================================================================

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}
?>

<!-- Статистика системы -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    
    <!-- CPU -->
    <div class="glassmorphism rounded-xl p-4">
        <div class="flex items-center justify-between mb-3">
            <span class="text-slate-400 text-sm">Процессор</span>
            <svg class="w-5 h-5 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>
            </svg>
        </div>
        <div class="text-3xl font-bold text-white mb-2" id="cpu-usage">--%</div>
        <div class="w-full bg-slate-700 rounded-full h-2">
            <div class="bg-violet-500 h-2 rounded-full transition-all duration-500" id="cpu-bar" style="width: 0%"></div>
        </div>
        <div class="text-xs text-slate-500 mt-2" id="cpu-temp">Температура: --°C</div>
    </div>
    
    <!-- RAM -->
    <div class="glassmorphism rounded-xl p-4">
        <div class="flex items-center justify-between mb-3">
            <span class="text-slate-400 text-sm">Память</span>
            <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
            </svg>
        </div>
        <div class="text-3xl font-bold text-white mb-2" id="ram-usage">--%</div>
        <div class="w-full bg-slate-700 rounded-full h-2">
            <div class="bg-green-500 h-2 rounded-full transition-all duration-500" id="ram-bar" style="width: 0%"></div>
        </div>
        <div class="text-xs text-slate-500 mt-2" id="ram-detail">-- / -- МБ</div>
    </div>
    
    <!-- Disk -->
    <div class="glassmorphism rounded-xl p-4">
        <div class="flex items-center justify-between mb-3">
            <span class="text-slate-400 text-sm">Диск</span>
            <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
            </svg>
        </div>
        <div class="text-3xl font-bold text-white mb-2" id="disk-usage">--%</div>
        <div class="w-full bg-slate-700 rounded-full h-2">
            <div class="bg-blue-500 h-2 rounded-full transition-all duration-500" id="disk-bar" style="width: 0%"></div>
        </div>
        <div class="text-xs text-slate-500 mt-2" id="disk-detail">-- / -- ГБ</div>
    </div>
    
    <!-- Uptime -->
    <div class="glassmorphism rounded-xl p-4">
        <div class="flex items-center justify-between mb-3">
            <span class="text-slate-400 text-sm">Uptime</span>
            <svg class="w-5 h-5 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        </div>
        <div class="text-3xl font-bold text-white mb-2" id="uptime">--</div>
        <div class="w-full bg-slate-700 rounded-full h-2">
            <div class="bg-orange-500 h-2 rounded-full" style="width: 100%"></div>
        </div>
        <div class="text-xs text-slate-500 mt-2" id="load-avg">Load: --</div>
    </div>
</div>

<!-- Графики и подробная информация -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    
    <!-- График пинга -->
    <div class="glassmorphism rounded-2xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-white">История пинга (VPN)</h3>
            <div class="flex items-center gap-2">
                <span class="text-xs text-slate-500" id="ping-stats">Min: -- | Avg: -- | Max: --</span>
            </div>
        </div>
        <div class="h-48 relative">
            <canvas id="ping-chart"></canvas>
        </div>
        <div class="flex justify-between mt-4 text-sm">
            <div class="text-center">
                <div class="text-2xl font-bold text-green-400" id="ping-current">--</div>
                <div class="text-slate-500 text-xs">Текущий</div>
            </div>
            <div class="text-center">
                <div class="text-2xl font-bold text-blue-400" id="ping-avg">--</div>
                <div class="text-slate-500 text-xs">Средний</div>
            </div>
            <div class="text-center">
                <div class="text-2xl font-bold text-red-400" id="ping-loss">--%</div>
                <div class="text-slate-500 text-xs">Потери</div>
            </div>
        </div>
    </div>
    
    <!-- Сетевые интерфейсы -->
    <div class="glassmorphism rounded-2xl p-6">
        <h3 class="text-lg font-bold text-white mb-4">Сетевые интерфейсы</h3>
        <div class="space-y-3" id="interfaces-list">
            <div class="text-slate-400 text-sm">Загрузка...</div>
        </div>
    </div>
</div>

<!-- Службы и устройства -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    
    <!-- Статус служб -->
    <div class="glassmorphism rounded-2xl p-6">
        <h3 class="text-lg font-bold text-white mb-4">Службы</h3>
        <div class="space-y-2" id="services-list">
            <div class="text-slate-400 text-sm">Загрузка...</div>
        </div>
    </div>
    
    <!-- Подключённые устройства -->
    <div class="glassmorphism rounded-2xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-white">Устройства в сети</h3>
            <button onclick="refreshDevices()" class="text-xs bg-slate-700 hover:bg-slate-600 px-3 py-1 rounded-lg transition">
                Обновить
            </button>
        </div>
        <div class="space-y-2 max-h-64 overflow-y-auto" id="devices-list">
            <div class="text-slate-400 text-sm">Загрузка...</div>
        </div>
    </div>
</div>

<!-- Простой canvas-based график (без внешних библиотек) -->
<script>
// Данные для графика пинга
let pingHistory = [];
const maxPingHistory = 60;

// Инициализация графика
const canvas = document.getElementById('ping-chart');
const ctx = canvas.getContext('2d');

function resizeCanvas() {
    const rect = canvas.parentElement.getBoundingClientRect();
    canvas.width = rect.width;
    canvas.height = rect.height;
}
resizeCanvas();
window.addEventListener('resize', resizeCanvas);

// Отрисовка графика
function drawPingChart() {
    const width = canvas.width;
    const height = canvas.height;
    const padding = 10;
    
    // Очистка
    ctx.clearRect(0, 0, width, height);
    
    if (pingHistory.length < 2) {
        ctx.fillStyle = '#64748b';
        ctx.font = '14px Inter, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Сбор данных...', width / 2, height / 2);
        return;
    }
    
    // Находим min/max для масштабирования
    const values = pingHistory.filter(p => p !== null);
    if (values.length === 0) return;
    
    const minVal = Math.max(0, Math.min(...values) - 10);
    const maxVal = Math.max(...values) + 10;
    
    // Рисуем сетку
    ctx.strokeStyle = '#334155';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
        const y = padding + (height - padding * 2) * (i / 4);
        ctx.beginPath();
        ctx.moveTo(padding, y);
        ctx.lineTo(width - padding, y);
        ctx.stroke();
    }
    
    // Рисуем линию
    ctx.strokeStyle = '#8b5cf6';
    ctx.lineWidth = 2;
    ctx.beginPath();
    
    let firstPoint = true;
    for (let i = 0; i < pingHistory.length; i++) {
        const value = pingHistory[i];
        if (value === null) continue;
        
        const x = padding + (width - padding * 2) * (i / (maxPingHistory - 1));
        const y = height - padding - (height - padding * 2) * ((value - minVal) / (maxVal - minVal));
        
        if (firstPoint) {
            ctx.moveTo(x, y);
            firstPoint = false;
        } else {
            ctx.lineTo(x, y);
        }
    }
    ctx.stroke();
    
    // Заливка под графиком
    ctx.lineTo(width - padding, height - padding);
    ctx.lineTo(padding, height - padding);
    ctx.closePath();
    ctx.fillStyle = 'rgba(139, 92, 246, 0.1)';
    ctx.fill();
}

// Обновление системной статистики
async function updateSystemStats() {
    try {
        const response = await fetch('api/system_stats.php');
        const data = await response.json();
        
        // CPU
        document.getElementById('cpu-usage').textContent = data.cpu.usage + '%';
        document.getElementById('cpu-bar').style.width = data.cpu.usage + '%';
        document.getElementById('cpu-bar').className = 'h-2 rounded-full transition-all duration-500 ' + 
            (data.cpu.usage > 80 ? 'bg-red-500' : data.cpu.usage > 50 ? 'bg-yellow-500' : 'bg-violet-500');
        
        if (data.cpu.temperature) {
            document.getElementById('cpu-temp').textContent = 'Температура: ' + data.cpu.temperature + '°C';
        }
        
        // RAM
        document.getElementById('ram-usage').textContent = data.memory.percent + '%';
        document.getElementById('ram-bar').style.width = data.memory.percent + '%';
        document.getElementById('ram-detail').textContent = data.memory.used + ' / ' + data.memory.total + ' МБ';
        
        // Disk
        if (data.disk && data.disk[0]) {
            document.getElementById('disk-usage').textContent = data.disk[0].percent + '%';
            document.getElementById('disk-bar').style.width = data.disk[0].percent + '%';
            document.getElementById('disk-detail').textContent = data.disk[0].used + ' / ' + data.disk[0].total + ' ГБ';
        }
        
        // Uptime
        document.getElementById('uptime').textContent = data.uptime.formatted;
        document.getElementById('load-avg').textContent = 'Load: ' + data.load.load1 + ', ' + data.load.load5 + ', ' + data.load.load15;
        
    } catch (error) {
        console.error('Error fetching system stats:', error);
    }
}

// Обновление пинга
async function updatePing() {
    try {
        const response = await fetch('api/ping_history.php?action=ping&host=8.8.8.8&interface=tun0');
        const data = await response.json();
        
        // Добавляем в историю
        pingHistory.push(data.success ? data.time : null);
        if (pingHistory.length > maxPingHistory) {
            pingHistory.shift();
        }
        
        // Обновляем текущий пинг
        if (data.success) {
            document.getElementById('ping-current').textContent = Math.round(data.time) + 'мс';
        } else {
            document.getElementById('ping-current').textContent = 'X';
        }
        
        // Получаем статистику
        const statsResponse = await fetch('api/ping_history.php?action=history&limit=60');
        const statsData = await statsResponse.json();
        
        if (statsData.stats) {
            document.getElementById('ping-avg').textContent = statsData.stats.avg ? Math.round(statsData.stats.avg) + 'мс' : '--';
            document.getElementById('ping-loss').textContent = statsData.stats.loss_percent + '%';
            document.getElementById('ping-stats').textContent = 
                'Min: ' + (statsData.stats.min || '--') + ' | Avg: ' + (statsData.stats.avg ? Math.round(statsData.stats.avg) : '--') + ' | Max: ' + (statsData.stats.max || '--');
        }
        
        drawPingChart();
        
    } catch (error) {
        console.error('Error fetching ping:', error);
        pingHistory.push(null);
        if (pingHistory.length > maxPingHistory) {
            pingHistory.shift();
        }
        drawPingChart();
    }
}

// Обновление сетевых интерфейсов
async function updateInterfaces() {
    try {
        const response = await fetch('api/network.php?action=interfaces');
        const interfaces = await response.json();
        
        const container = document.getElementById('interfaces-list');
        container.innerHTML = '';
        
        interfaces.forEach(iface => {
            const statusColor = iface.status === 'up' ? 'bg-green-500' : 'bg-red-500';
            const typeIcon = getInterfaceIcon(iface.type);
            
            container.innerHTML += `
                <div class="flex items-center justify-between p-2 bg-slate-800/50 rounded-lg">
                    <div class="flex items-center gap-3">
                        <div class="w-2 h-2 rounded-full ${statusColor}"></div>
                        <span class="text-white font-mono text-sm">${iface.name}</span>
                        <span class="text-slate-500 text-xs">${iface.type}</span>
                    </div>
                    <div class="text-right">
                        <div class="text-slate-300 text-sm">${iface.ipv4 || 'No IP'}</div>
                        <div class="text-slate-500 text-xs">↓${iface.rx_formatted} ↑${iface.tx_formatted}</div>
                    </div>
                </div>
            `;
        });
        
    } catch (error) {
        console.error('Error fetching interfaces:', error);
    }
}

// Обновление статуса служб
async function updateServices() {
    try {
        const response = await fetch('api/server.php?action=services_status');
        const services = await response.json();
        
        const container = document.getElementById('services-list');
        container.innerHTML = '';
        
        services.forEach(service => {
            const statusClass = service.active ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400';
            const statusText = service.active ? 'Активна' : 'Остановлена';
            
            container.innerHTML += `
                <div class="flex items-center justify-between p-2 bg-slate-800/50 rounded-lg">
                    <span class="text-slate-300 text-sm">${service.name}</span>
                    <span class="text-xs px-2 py-1 rounded ${statusClass}">${statusText}</span>
                </div>
            `;
        });
        
    } catch (error) {
        console.error('Error fetching services:', error);
    }
}

// Обновление списка устройств
async function refreshDevices() {
    try {
        const container = document.getElementById('devices-list');
        container.innerHTML = '<div class="text-slate-400 text-sm">Сканирование...</div>';
        
        const response = await fetch('api/network.php?action=devices');
        const devices = await response.json();
        
        container.innerHTML = '';
        
        if (devices.length === 0) {
            container.innerHTML = '<div class="text-slate-400 text-sm">Устройства не найдены</div>';
            return;
        }
        
        devices.forEach(device => {
            container.innerHTML += `
                <div class="flex items-center justify-between p-2 bg-slate-800/50 rounded-lg">
                    <div>
                        <div class="text-slate-300 text-sm font-mono">${device.ip}</div>
                        <div class="text-slate-500 text-xs">${device.mac}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-slate-400 text-xs">${device.hostname || ''}</div>
                        <div class="text-slate-500 text-xs">${device.vendor || ''}</div>
                    </div>
                </div>
            `;
        });
        
    } catch (error) {
        console.error('Error fetching devices:', error);
    }
}

function getInterfaceIcon(type) {
    const icons = {
        'vpn': '🔒',
        'wireguard': '🔐',
        'ethernet': '🔌',
        'wifi': '📶',
        'bridge': '🌉',
        'docker': '🐳',
        'pppoe': '📡'
    };
    return icons[type] || '🔗';
}

// Запуск обновлений
document.addEventListener('DOMContentLoaded', () => {
    updateSystemStats();
    updateInterfaces();
    updateServices();
    refreshDevices();
    updatePing();
    
    // Периодические обновления
    setInterval(updateSystemStats, 3000);
    setInterval(updatePing, 2000);
    setInterval(updateInterfaces, 10000);
    setInterval(updateServices, 10000);
});
</script>
