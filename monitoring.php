<?php
// ==============================================================================
// MINE SERVER - Мониторинг системы (исправленный)
// ==============================================================================

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}
?>

<!-- Статистика системы -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    
    <!-- CPU -->
    <div class="glassmorphism rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-slate-400 text-sm">CPU</span>
            <span class="text-xs text-slate-500" id="cpu-temp">--°C</span>
        </div>
        <div class="text-3xl font-bold text-white mb-2" id="cpu-usage">--%</div>
        <div class="w-full bg-slate-700 rounded-full h-2">
            <div class="bg-violet-500 h-2 rounded-full transition-all duration-300" id="cpu-bar" style="width: 0%"></div>
        </div>
    </div>
    
    <!-- RAM -->
    <div class="glassmorphism rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-slate-400 text-sm">RAM</span>
            <span class="text-xs text-slate-500" id="ram-detail">--/-- МБ</span>
        </div>
        <div class="text-3xl font-bold text-white mb-2" id="ram-usage">--%</div>
        <div class="w-full bg-slate-700 rounded-full h-2">
            <div class="bg-green-500 h-2 rounded-full transition-all duration-300" id="ram-bar" style="width: 0%"></div>
        </div>
    </div>
    
    <!-- Disk -->
    <div class="glassmorphism rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-slate-400 text-sm">Диск</span>
            <span class="text-xs text-slate-500" id="disk-detail">--/-- ГБ</span>
        </div>
        <div class="text-3xl font-bold text-white mb-2" id="disk-usage">--%</div>
        <div class="w-full bg-slate-700 rounded-full h-2">
            <div class="bg-blue-500 h-2 rounded-full transition-all duration-300" id="disk-bar" style="width: 0%"></div>
        </div>
    </div>
    
    <!-- Uptime -->
    <div class="glassmorphism rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-slate-400 text-sm">Uptime</span>
            <span class="text-xs text-slate-500" id="load-avg">Load: --</span>
        </div>
        <div class="text-3xl font-bold text-white mb-2" id="uptime">--</div>
        <div class="w-full bg-slate-700 rounded-full h-2">
            <div class="bg-orange-500 h-2 rounded-full" style="width: 100%"></div>
        </div>
    </div>
</div>

<!-- VPN статус и пинг -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    
    <!-- Текущий пинг -->
    <div class="glassmorphism rounded-2xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-white">VPN Ping</h3>
            <div class="flex items-center gap-2">
                <div class="w-2 h-2 rounded-full animate-pulse" id="ping-indicator"></div>
                <span class="text-xs text-slate-500" id="ping-time">--</span>
            </div>
        </div>
        
        <div class="grid grid-cols-4 gap-4 text-center">
            <div>
                <div class="text-3xl font-bold" id="ping-current">--</div>
                <div class="text-slate-500 text-xs">Текущий</div>
            </div>
            <div>
                <div class="text-3xl font-bold text-green-400" id="ping-min">--</div>
                <div class="text-slate-500 text-xs">Мин</div>
            </div>
            <div>
                <div class="text-3xl font-bold text-blue-400" id="ping-avg">--</div>
                <div class="text-slate-500 text-xs">Сред</div>
            </div>
            <div>
                <div class="text-3xl font-bold text-red-400" id="ping-max">--</div>
                <div class="text-slate-500 text-xs">Макс</div>
            </div>
        </div>
        
        <!-- График пинга -->
        <div class="mt-4 h-24 relative">
            <canvas id="ping-chart"></canvas>
        </div>
        
        <div class="flex justify-between mt-2 text-xs text-slate-500">
            <span>Потери: <span id="ping-loss" class="text-white">--%</span></span>
            <span>Последние 60 сек</span>
        </div>
    </div>
    
    <!-- Сетевые интерфейсы -->
    <div class="glassmorphism rounded-2xl p-6">
        <h3 class="text-lg font-bold text-white mb-4">Интерфейсы</h3>
        <div class="space-y-2" id="interfaces-list">
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
    
    <!-- Устройства -->
    <div class="glassmorphism rounded-2xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-white">Устройства в сети</h3>
            <span class="text-xs text-slate-500" id="devices-count">0</span>
        </div>
        <div class="space-y-2 max-h-48 overflow-y-auto" id="devices-list">
            <div class="text-slate-400 text-sm">Загрузка...</div>
        </div>
    </div>
</div>

<script>
// Данные пинга
let pingHistory = [];
const maxPingHistory = 60;

// Canvas для графика
const canvas = document.getElementById('ping-chart');
const ctx = canvas.getContext('2d');

function resizeCanvas() {
    const rect = canvas.parentElement.getBoundingClientRect();
    canvas.width = rect.width;
    canvas.height = rect.height;
}
resizeCanvas();
window.addEventListener('resize', resizeCanvas);

// Отрисовка графика пинга
function drawPingChart() {
    const width = canvas.width;
    const height = canvas.height;
    
    ctx.clearRect(0, 0, width, height);
    
    if (pingHistory.length < 2) {
        ctx.fillStyle = '#64748b';
        ctx.font = '12px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Сбор данных...', width / 2, height / 2);
        return;
    }
    
    const values = pingHistory.filter(p => p !== null);
    if (values.length === 0) return;
    
    const minVal = Math.max(0, Math.min(...values) - 20);
    const maxVal = Math.max(...values) + 20;
    
    // Заливка под графиком
    ctx.beginPath();
    ctx.moveTo(0, height);
    
    let firstPoint = true;
    for (let i = 0; i < pingHistory.length; i++) {
        const value = pingHistory[i];
        const x = (width / (maxPingHistory - 1)) * i;
        
        if (value === null) {
            continue;
        }
        
        const y = height - (height * (value - minVal) / (maxVal - minVal));
        
        if (firstPoint) {
            ctx.lineTo(x, y);
            firstPoint = false;
        } else {
            ctx.lineTo(x, y);
        }
    }
    
    ctx.lineTo(width, height);
    ctx.closePath();
    ctx.fillStyle = 'rgba(139, 92, 246, 0.1)';
    ctx.fill();
    
    // Линия графика
    ctx.beginPath();
    firstPoint = true;
    for (let i = 0; i < pingHistory.length; i++) {
        const value = pingHistory[i];
        if (value === null) continue;
        
        const x = (width / (maxPingHistory - 1)) * i;
        const y = height - (height * (value - minVal) / (maxVal - minVal));
        
        if (firstPoint) {
            ctx.moveTo(x, y);
            firstPoint = false;
        } else {
            ctx.lineTo(x, y);
        }
    }
    ctx.strokeStyle = '#8b5cf6';
    ctx.lineWidth = 2;
    ctx.stroke();
}

// Обновление системных метрик (каждую секунду)
async function updateSystemStats() {
    try {
        const response = await fetch('api/system_stats.php');
        const data = await response.json();
        
        // CPU
        const cpuUsage = data.cpu.usage || 0;
        document.getElementById('cpu-usage').textContent = cpuUsage + '%';
        document.getElementById('cpu-bar').style.width = cpuUsage + '%';
        document.getElementById('cpu-bar').className = 'h-2 rounded-full transition-all duration-300 ' + 
            (cpuUsage > 80 ? 'bg-red-500' : cpuUsage > 50 ? 'bg-yellow-500' : 'bg-violet-500');
        
        // Температура
        if (data.cpu.temperature !== null) {
            document.getElementById('cpu-temp').textContent = data.cpu.temperature + '°C';
            document.getElementById('cpu-temp').className = 'text-xs ' + 
                (data.cpu.temperature > 70 ? 'text-red-400' : data.cpu.temperature > 50 ? 'text-yellow-400' : 'text-slate-500');
        } else {
            document.getElementById('cpu-temp').textContent = 'N/A';
        }
        
        // RAM
        document.getElementById('ram-usage').textContent = data.memory.percent + '%';
        document.getElementById('ram-bar').style.width = data.memory.percent + '%';
        document.getElementById('ram-detail').textContent = data.memory.used + '/' + data.memory.total + ' МБ';
        
        // Disk
        if (data.disk && data.disk[0]) {
            document.getElementById('disk-usage').textContent = data.disk[0].percent + '%';
            document.getElementById('disk-bar').style.width = data.disk[0].percent + '%';
            document.getElementById('disk-detail').textContent = data.disk[0].used + '/' + data.disk[0].total + ' ГБ';
        }
        
        // Uptime
        document.getElementById('uptime').textContent = data.uptime.formatted;
        document.getElementById('load-avg').textContent = 'Load: ' + data.load.load1;
        
    } catch (error) {
        console.error('Stats error:', error);
    }
}

// Обновление пинга (каждую секунду)
async function updatePing() {
    try {
        const response = await fetch('api/ping_history.php?action=ping&host=8.8.8.8&interface=tun0');
        const data = await response.json();
        
        const indicator = document.getElementById('ping-indicator');
        const currentEl = document.getElementById('ping-current');
        
        pingHistory.push(data.success ? data.time : null);
        if (pingHistory.length > maxPingHistory) {
            pingHistory.shift();
        }
        
        if (data.success) {
            const ping = Math.round(data.time);
            currentEl.textContent = ping + 'мс';
            currentEl.className = 'text-3xl font-bold ' + 
                (ping < 50 ? 'text-green-400' : ping < 100 ? 'text-yellow-400' : 'text-red-400');
            indicator.className = 'w-2 h-2 rounded-full animate-pulse bg-green-500';
        } else {
            currentEl.textContent = 'X';
            currentEl.className = 'text-3xl font-bold text-red-400';
            indicator.className = 'w-2 h-2 rounded-full bg-red-500';
        }
        
        document.getElementById('ping-time').textContent = new Date().toLocaleTimeString();
        
        // Статистика
        const statsResponse = await fetch('api/ping_history.php?action=history&limit=60');
        const stats = await statsResponse.json();
        
        if (stats.stats) {
            document.getElementById('ping-min').textContent = stats.stats.min ? Math.round(stats.stats.min) + 'мс' : '--';
            document.getElementById('ping-avg').textContent = stats.stats.avg ? Math.round(stats.stats.avg) + 'мс' : '--';
            document.getElementById('ping-max').textContent = stats.stats.max ? Math.round(stats.stats.max) + 'мс' : '--';
            document.getElementById('ping-loss').textContent = stats.stats.loss_percent + '%';
        }
        
        drawPingChart();
        
    } catch (error) {
        console.error('Ping error:', error);
        pingHistory.push(null);
        if (pingHistory.length > maxPingHistory) pingHistory.shift();
        drawPingChart();
    }
}

// Обновление интерфейсов (каждые 5 секунд)
async function updateInterfaces() {
    try {
        const response = await fetch('api/network.php?action=interfaces');
        const interfaces = await response.json();
        
        const container = document.getElementById('interfaces-list');
        container.innerHTML = '';
        
        interfaces.forEach(iface => {
            const statusColor = iface.status === 'up' ? 'bg-green-500' : 'bg-red-500';
            
            container.innerHTML += `
                <div class="flex items-center justify-between p-2 bg-slate-800/50 rounded-lg">
                    <div class="flex items-center gap-3">
                        <div class="w-2 h-2 rounded-full ${statusColor}"></div>
                        <span class="text-white font-mono text-sm">${iface.name}</span>
                    </div>
                    <div class="text-right">
                        <div class="text-slate-300 text-sm font-mono">${iface.ipv4 || '-'}</div>
                        <div class="text-slate-500 text-xs">↓${iface.rx_formatted} ↑${iface.tx_formatted}</div>
                    </div>
                </div>
            `;
        });
        
    } catch (error) {
        console.error('Interfaces error:', error);
    }
}

// Обновление служб (каждые 5 секунд)
async function updateServices() {
    try {
        const response = await fetch('api/server.php?action=services_status');
        const services = await response.json();
        
        const container = document.getElementById('services-list');
        container.innerHTML = '';
        
        services.forEach(service => {
            const statusClass = service.active ? 'bg-green-500' : 'bg-red-500';
            const statusText = service.active ? 'ON' : 'OFF';
            
            container.innerHTML += `
                <div class="flex items-center justify-between p-2 bg-slate-800/50 rounded-lg">
                    <span class="text-slate-300 text-sm">${service.name}</span>
                    <span class="text-xs px-2 py-0.5 rounded ${statusClass}/20 text-${service.active ? 'green' : 'red'}-400">${statusText}</span>
                </div>
            `;
        });
        
    } catch (error) {
        console.error('Services error:', error);
    }
}

// Обновление устройств (каждые 10 секунд)
async function updateDevices() {
    try {
        const response = await fetch('api/network.php?action=devices');
        const devices = await response.json();
        
        const container = document.getElementById('devices-list');
        document.getElementById('devices-count').textContent = devices.length + ' устр.';
        
        if (devices.length === 0) {
            container.innerHTML = '<div class="text-slate-400 text-sm">Нет устройств</div>';
            return;
        }
        
        container.innerHTML = '';
        devices.slice(0, 10).forEach(device => {
            container.innerHTML += `
                <div class="flex items-center justify-between p-2 bg-slate-800/50 rounded-lg">
                    <div>
                        <div class="text-white text-sm font-mono">${device.ip}</div>
                        <div class="text-slate-500 text-xs">${device.hostname || device.mac}</div>
                    </div>
                </div>
            `;
        });
        
        if (devices.length > 10) {
            container.innerHTML += `<div class="text-slate-500 text-xs text-center">и ещё ${devices.length - 10}...</div>`;
        }
        
    } catch (error) {
        console.error('Devices error:', error);
    }
}

// Запуск обновлений с разными интервалами
document.addEventListener('DOMContentLoaded', () => {
    // Первичная загрузка
    updateSystemStats();
    updatePing();
    updateInterfaces();
    updateServices();
    updateDevices();
    
    // Интервалы обновления
    setInterval(updateSystemStats, 1000);  // Каждую секунду
    setInterval(updatePing, 1000);          // Каждую секунду
    setInterval(updateInterfaces, 5000);    // Каждые 5 секунд
    setInterval(updateServices, 5000);      // Каждые 5 секунд
    setInterval(updateDevices, 10000);      // Каждые 10 секунд
});
</script>
