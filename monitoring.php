<?php
// ==============================================================================
// MINE SERVER - Мониторинг системы
// ==============================================================================

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}
?>

<!-- Карточки статистики -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="glassmorphism rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-slate-400 text-sm">CPU</span>
            <span class="text-xs" id="cpu-temp">--</span>
        </div>
        <div class="text-3xl font-bold text-white mb-2" id="cpu-usage">--%</div>
        <div class="w-full bg-slate-700 rounded-full h-2">
            <div class="bg-violet-500 h-2 rounded-full transition-all" id="cpu-bar" style="width: 0%"></div>
        </div>
    </div>
    
    <div class="glassmorphism rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-slate-400 text-sm">RAM</span>
            <span class="text-xs text-slate-500" id="ram-detail">--</span>
        </div>
        <div class="text-3xl font-bold text-white mb-2" id="ram-usage">--%</div>
        <div class="w-full bg-slate-700 rounded-full h-2">
            <div class="bg-green-500 h-2 rounded-full transition-all" id="ram-bar" style="width: 0%"></div>
        </div>
    </div>
    
    <div class="glassmorphism rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-slate-400 text-sm">Диск</span>
            <span class="text-xs text-slate-500" id="disk-detail">--</span>
        </div>
        <div class="text-3xl font-bold text-white mb-2" id="disk-usage">--%</div>
        <div class="w-full bg-slate-700 rounded-full h-2">
            <div class="bg-blue-500 h-2 rounded-full transition-all" id="disk-bar" style="width: 0%"></div>
        </div>
    </div>
    
    <div class="glassmorphism rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-slate-400 text-sm">Uptime</span>
            <span class="text-xs text-slate-500" id="load-avg">--</span>
        </div>
        <div class="text-3xl font-bold text-white" id="uptime">--</div>
    </div>
</div>

<!-- VPN Ping -->
<div class="glassmorphism rounded-2xl p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-bold text-white">VPN Ping</h3>
        <div class="flex items-center gap-2">
            <div class="w-2 h-2 rounded-full" id="ping-dot"></div>
            <span class="text-xs text-slate-500" id="ping-time">--</span>
        </div>
    </div>
    
    <div class="grid grid-cols-4 gap-4 text-center mb-4">
        <div>
            <div class="text-2xl font-bold" id="ping-current">--</div>
            <div class="text-slate-500 text-xs">Текущий</div>
        </div>
        <div>
            <div class="text-2xl font-bold text-green-400" id="ping-min">--</div>
            <div class="text-slate-500 text-xs">Мин</div>
        </div>
        <div>
            <div class="text-2xl font-bold text-blue-400" id="ping-avg">--</div>
            <div class="text-slate-500 text-xs">Сред</div>
        </div>
        <div>
            <div class="text-2xl font-bold text-red-400" id="ping-max">--</div>
            <div class="text-slate-500 text-xs">Макс</div>
        </div>
    </div>
    
    <div class="relative h-24 bg-slate-800/50 rounded-lg overflow-hidden">
        <canvas id="ping-chart"></canvas>
        <div class="absolute bottom-1 left-2 text-xs text-slate-500">Потери: <span id="ping-loss">0%</span></div>
        <div class="absolute bottom-1 right-2 text-xs text-slate-500">60 сек</div>
    </div>
</div>

<!-- Службы и интерфейсы -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="glassmorphism rounded-2xl p-6">
        <h3 class="text-lg font-bold text-white mb-4">Службы</h3>
        <div class="space-y-2" id="services-list">
            <div class="text-slate-400 text-sm">Загрузка...</div>
        </div>
    </div>
    
    <div class="glassmorphism rounded-2xl p-6">
        <h3 class="text-lg font-bold text-white mb-4">Интерфейсы</h3>
        <div class="space-y-2" id="interfaces-list">
            <div class="text-slate-400 text-sm">Загрузка...</div>
        </div>
    </div>
</div>

<script>
let pingData = [];
const MAX_POINTS = 60;
let canvas, ctx;

// Инициализация canvas
function initCanvas() {
    canvas = document.getElementById('ping-chart');
    if (!canvas) return;
    ctx = canvas.getContext('2d');
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);
}

function resizeCanvas() {
    if (!canvas) return;
    const rect = canvas.parentElement.getBoundingClientRect();
    canvas.width = rect.width;
    canvas.height = rect.height;
    drawChart();
}

// Отрисовка графика
function drawChart() {
    if (!ctx || !canvas) return;
    
    const w = canvas.width;
    const h = canvas.height;
    ctx.clearRect(0, 0, w, h);
    
    // Фильтруем только валидные значения для расчёта масштаба
    const valid = pingData.filter(p => p !== null && p > 0);
    if (valid.length < 2) {
        ctx.fillStyle = '#64748b';
        ctx.font = '12px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Ожидание данных...', w / 2, h / 2);
        return;
    }
    
    const minVal = Math.max(0, Math.min(...valid) * 0.8);
    const maxVal = Math.max(...valid) * 1.2;
    const range = maxVal - minVal || 1;
    
    const stepX = w / (MAX_POINTS - 1);
    const padding = 5;
    
    // Рисуем заливку
    ctx.beginPath();
    let firstValid = true;
    let lastX = 0;
    
    for (let i = 0; i < pingData.length; i++) {
        const x = i * stepX;
        if (pingData[i] !== null) {
            const y = h - padding - ((pingData[i] - minVal) / range) * (h - padding * 2);
            if (firstValid) {
                ctx.moveTo(x, h);
                ctx.lineTo(x, y);
                firstValid = false;
            } else {
                ctx.lineTo(x, y);
            }
            lastX = x;
        }
    }
    
    ctx.lineTo(lastX, h);
    ctx.closePath();
    
    const gradient = ctx.createLinearGradient(0, 0, 0, h);
    gradient.addColorStop(0, 'rgba(139, 92, 246, 0.4)');
    gradient.addColorStop(1, 'rgba(139, 92, 246, 0.05)');
    ctx.fillStyle = gradient;
    ctx.fill();
    
    // Рисуем линию
    ctx.beginPath();
    firstValid = true;
    for (let i = 0; i < pingData.length; i++) {
        const x = i * stepX;
        if (pingData[i] !== null) {
            const y = h - padding - ((pingData[i] - minVal) / range) * (h - padding * 2);
            if (firstValid) {
                ctx.moveTo(x, y);
                firstValid = false;
            } else {
                ctx.lineTo(x, y);
            }
        }
    }
    ctx.strokeStyle = '#8b5cf6';
    ctx.lineWidth = 2;
    ctx.stroke();
    
    // Точки для потерь (красные)
    for (let i = 0; i < pingData.length; i++) {
        if (pingData[i] === null) {
            const x = i * stepX;
            ctx.beginPath();
            ctx.arc(x, h / 2, 3, 0, Math.PI * 2);
            ctx.fillStyle = '#ef4444';
            ctx.fill();
        }
    }
}

// Обновление пинга
async function updatePing() {
    try {
        const r = await fetch('ping.php?host=8.8.8.8&interface=tun0');
        const data = await r.text();
        
        const dot = document.getElementById('ping-dot');
        const current = document.getElementById('ping-current');
        
        if (data.indexOf('NO PING') === -1 && !isNaN(parseFloat(data))) {
            const ping = Math.round(parseFloat(data));
            pingData.push(ping);
            
            current.textContent = ping + 'мс';
            current.className = 'text-2xl font-bold ' + (ping < 50 ? 'text-green-400' : ping < 100 ? 'text-yellow-400' : 'text-red-400');
            dot.className = 'w-2 h-2 rounded-full bg-green-500 animate-pulse';
        } else {
            pingData.push(null);
            current.textContent = '✕';
            current.className = 'text-2xl font-bold text-red-400';
            dot.className = 'w-2 h-2 rounded-full bg-red-500';
        }
        
        if (pingData.length > MAX_POINTS) pingData.shift();
        
        // Статистика
        const valid = pingData.filter(p => p !== null);
        if (valid.length > 0) {
            document.getElementById('ping-min').textContent = Math.min(...valid) + 'мс';
            document.getElementById('ping-avg').textContent = Math.round(valid.reduce((a, b) => a + b, 0) / valid.length) + 'мс';
            document.getElementById('ping-max').textContent = Math.max(...valid) + 'мс';
        }
        document.getElementById('ping-loss').textContent = Math.round((pingData.length - valid.length) / pingData.length * 100) + '%';
        document.getElementById('ping-time').textContent = new Date().toLocaleTimeString();
        
        drawChart();
    } catch (e) {
        pingData.push(null);
        if (pingData.length > MAX_POINTS) pingData.shift();
        drawChart();
    }
}

// Обновление системных метрик
async function updateStats() {
    try {
        const r = await fetch('api/system_stats.php');
        const d = await r.json();
        
        // CPU
        document.getElementById('cpu-usage').textContent = d.cpu.usage + '%';
        document.getElementById('cpu-bar').style.width = d.cpu.usage + '%';
        document.getElementById('cpu-bar').className = 'h-2 rounded-full transition-all ' + 
            (d.cpu.usage > 80 ? 'bg-red-500' : d.cpu.usage > 50 ? 'bg-yellow-500' : 'bg-violet-500');
        
        if (d.cpu.temperature) {
            document.getElementById('cpu-temp').textContent = d.cpu.temperature + '°C';
            document.getElementById('cpu-temp').className = 'text-xs ' + 
                (d.cpu.temperature > 70 ? 'text-red-400' : d.cpu.temperature > 50 ? 'text-yellow-400' : 'text-slate-500');
        }
        
        // RAM
        document.getElementById('ram-usage').textContent = d.memory.percent + '%';
        document.getElementById('ram-bar').style.width = d.memory.percent + '%';
        document.getElementById('ram-detail').textContent = d.memory.used + '/' + d.memory.total + ' MB';
        
        // Disk
        if (d.disk && d.disk[0]) {
            document.getElementById('disk-usage').textContent = d.disk[0].percent + '%';
            document.getElementById('disk-bar').style.width = d.disk[0].percent + '%';
            document.getElementById('disk-detail').textContent = d.disk[0].used + '/' + d.disk[0].total + ' GB';
        }
        
        // Uptime
        document.getElementById('uptime').textContent = d.uptime.formatted;
        document.getElementById('load-avg').textContent = 'Load: ' + d.load.load1;
    } catch (e) {}
}

// Обновление служб
async function updateServices() {
    try {
        const r = await fetch('api/server.php?action=services_status');
        const services = await r.json();
        
        const container = document.getElementById('services-list');
        container.innerHTML = services.map(s => `
            <div class="flex items-center justify-between p-2 bg-slate-800/50 rounded-lg">
                <span class="text-slate-300 text-sm">${s.name}</span>
                <span class="text-xs px-2 py-0.5 rounded ${s.active ? 'bg-green-500/20 text-green-400' : 'bg-slate-600/50 text-slate-400'}">
                    ${s.active ? 'ON' : 'OFF'}
                </span>
            </div>
        `).join('');
    } catch (e) {}
}

// Обновление интерфейсов
async function updateInterfaces() {
    try {
        const r = await fetch('api/network.php?action=interfaces');
        const interfaces = await r.json();
        
        const container = document.getElementById('interfaces-list');
        container.innerHTML = interfaces.map(i => `
            <div class="flex items-center justify-between p-2 bg-slate-800/50 rounded-lg">
                <div class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full ${i.status === 'up' ? 'bg-green-500' : 'bg-red-500'}"></div>
                    <span class="text-white font-mono text-sm">${i.name}</span>
                </div>
                <div class="text-right">
                    <div class="text-slate-300 text-sm font-mono">${i.ipv4 || '-'}</div>
                    <div class="text-slate-500 text-xs">↓${i.rx_formatted} ↑${i.tx_formatted}</div>
                </div>
            </div>
        `).join('');
    } catch (e) {}
}

// Запуск
document.addEventListener('DOMContentLoaded', () => {
    initCanvas();
    updateStats();
    updatePing();
    updateServices();
    updateInterfaces();
    
    setInterval(updateStats, 2000);
    setInterval(updatePing, 1000);
    setInterval(updateServices, 5000);
    setInterval(updateInterfaces, 5000);
});
</script>
