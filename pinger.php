<?php
// Проверка сессии и аутентификации
session_start();

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Утилита Ping</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Стили для эффекта "glassmorphism" */
        .glassmorphism {
            background: rgba(30, 41, 59, 0.5); /* bg-slate-800 with 50% opacity */
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-200 min-h-screen p-4 sm:p-8">

    <div class="max-w-4xl mx-auto">

        <div class="flex flex-col gap-8">

            <div class="glassmorphism rounded-2xl p-4">
    <div class="flex flex-wrap items-center gap-3">

        <input type="text" id="targetAddress" placeholder="IP-адрес, например, 8.8.8.8" value="8.8.8.8" class="flex-grow min-w-[180px] w-full sm:w-auto bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white placeholder-slate-400 focus:ring-2 focus:ring-violet-500 focus:outline-none transition">

        <select id="networkInterface" class="flex-shrink-0 bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white focus:ring-2 focus:ring-violet-500 focus:outline-none transition">  
            <option value="">По умолчанию</option>
            <option value="detect_netplan">Белый входной</option>
            <option value="tun0">VPN туннель</option>
        </select>

        <div class="flex flex-grow sm:flex-grow-0 w-full sm:w-auto gap-3">
             <button id="startButton" class="w-1/2 sm:w-auto bg-green-600 text-white font-bold py-3 px-6 rounded-lg hover:bg-green-700 transition-all">Старт</button>
             <button id="stopButton" class="w-1/2 sm:w-auto bg-red-600 text-white font-bold py-3 px-6 rounded-lg hover:bg-red-700 transition-all">Стоп</button>
        </div>

    </div>
</div>

            <div class="glassmorphism rounded-2xl p-4">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">

        <div class="bg-slate-800/50 p-3 rounded-lg flex justify-between items-baseline">
            <span class="text-slate-400">Отправлено:</span>
            <span class="text-2xl font-semibold text-white" id="allCount">0</span>
        </div>
        <div class="bg-slate-800/50 p-3 rounded-lg flex justify-between items-baseline">
            <span class="text-slate-400">Успешно:</span>
            <span class="text-2xl font-semibold text-green-400" id="successCount">0</span>
        </div>
        <div class="bg-slate-800/50 p-3 rounded-lg flex justify-between items-baseline">
            <span class="text-slate-400">Потеряно:</span>
            <span class="text-2xl font-semibold text-red-400" id="failCount">0</span>
        </div>
        <div class="bg-slate-800/50 p-3 rounded-lg flex justify-between items-baseline">
            <span class="text-slate-400">Потери:</span>
            <span class="text-2xl font-semibold text-orange-400" id="lossPercent">0%</span>
        </div>

        <div class="bg-slate-800/50 p-3 rounded-lg flex justify-between items-baseline">
            <span class="text-slate-400">Мин:</span>
            <p class="font-semibold text-sky-400">
                <span class="text-2xl" id="minPing">-</span>
                <span>мс</span>
            </p>
        </div>
        <div class="bg-slate-800/50 p-3 rounded-lg flex justify-between items-baseline">
            <span class="text-slate-400">Сред:</span>
            <p class="font-semibold text-sky-400">
                <span class="text-2xl" id="avgPing">-</span>
                <span>мс</span>
            </p>
        </div>
        <div class="bg-slate-800/50 p-3 rounded-lg flex justify-between items-baseline">
            <span class="text-slate-400">Макс:</span>
            <p class="font-semibold text-sky-400">
                <span class="text-2xl" id="maxPing">-</span>
                <span>мс</span>
            </p>
        </div>
        <div class="bg-slate-800/50 p-3 rounded-lg flex justify-between items-baseline">
            <span class="text-slate-400">Последний:</span>
            <p class="font-semibold text-white">
                <span class="text-2xl" id="lastPing">-</span>
                <span>мс</span>
            </p>
        </div>

    </div>
</div>

            <div class="glassmorphism rounded-2xl p-2">
                <div id="logWindow" class="w-full h-96 bg-slate-900/70 rounded-lg p-4 font-mono text-sm overflow-y-auto">
                </div>
            </div>

        </div>

    </div> <script>
    var intervalId; 
    var allCount = 0, successCount = 0, failCount = 0;
    var minPing = Infinity, maxPing = -Infinity, totalPing = 0;

    // Предполагается, что у вас есть функция уведомлений Notice(),
    // если нет, можно заменить на alert()
    function Notice(message, type) {
        // Простая замена, если нет кастомной функции
        console.log(`Notice (${type}): ${message}`);
        alert(message);
    }

    document.getElementById("startButton").addEventListener("click", function() {
        var targetAddress = document.getElementById("targetAddress").value;
        var networkInterface = document.getElementById("networkInterface").value;

        if (targetAddress.trim() === "") {
            Notice("Введите адрес для проверки пинга!", "error");
            return;
        }

        // Сброс статистики и логов
        document.getElementById("logWindow").innerHTML = "";
        if (intervalId) { clearInterval(intervalId); }
        allCount = 0; successCount = 0; failCount = 0;
        minPing = Infinity; maxPing = -Infinity; totalPing = 0;
        
        // Обновляем все поля на значения по умолчанию
        document.getElementById("allCount").textContent = '0';
        document.getElementById("successCount").textContent = '0';
        document.getElementById("failCount").textContent = '0';
        document.getElementById("lossPercent").textContent = '0%';
        document.getElementById("minPing").textContent = '-';
        document.getElementById("avgPing").textContent = '-';
        document.getElementById("maxPing").textContent = '-';
        document.getElementById("lastPing").textContent = '-';

        // Запускаем пинг с интервалом в 1 секунду
        intervalId = setInterval(function() { measurePing(targetAddress, networkInterface); }, 1000);
        measurePing(targetAddress, networkInterface);
    });

    document.getElementById("stopButton").addEventListener("click", function() {
        clearInterval(intervalId);
    });

    function measurePing(targetAddress, networkInterface) {
        var xhr = new XMLHttpRequest();
        xhr.onreadystatechange = function() {
            if (xhr.readyState == 4) {
                var now = new Date().toLocaleString();
                allCount++;
                var ping;

                if (xhr.status == 200 && xhr.responseText.indexOf("NO PING") == -1) {
                    successCount++;
                    ping = parseFloat(xhr.responseText);
                    minPing = Math.min(minPing, ping);
                    maxPing = Math.max(maxPing, ping);
                    totalPing += ping;
                    var logEntry = document.createElement('p');
                    logEntry.textContent = now + ': ' + ping.toFixed(2) + ' мс';
                    var avgPing = totalPing / successCount;
                    if (!isNaN(avgPing) && ping > (avgPing + 20)) {
                        logEntry.style.color = '#f97316'; // orange-500
                    } else {
                        logEntry.style.color = '#22c55e'; // green-500
                    }
                    document.getElementById("logWindow").appendChild(logEntry);
                } else {
                    failCount++;
                    ping = NaN; // Устанавливаем в NaN при ошибке
                    var logEntry = document.createElement('p');
                    logEntry.textContent = now + ': NO PING';
                    logEntry.style.color = '#ef4444'; // red-500
                    document.getElementById("logWindow").appendChild(logEntry);
                }
                
                // Обновляем статистику
                document.getElementById("allCount").textContent = allCount;
                document.getElementById("successCount").textContent = successCount;
                document.getElementById("failCount").textContent = failCount;
                var lossPercent = (allCount === 0) ? 0 : (failCount / allCount * 100);
                document.getElementById("lossPercent").textContent = lossPercent.toFixed(1) + "%";
                document.getElementById("minPing").textContent = (minPing == Infinity) ? "-" : minPing.toFixed(2);
                document.getElementById("maxPing").textContent = (maxPing == -Infinity) ? "-" : maxPing.toFixed(2);
                var avgPing = totalPing / successCount;
                document.getElementById("avgPing").textContent = isNaN(avgPing) ? "-" : avgPing.toFixed(2);
                document.getElementById("lastPing").textContent = isNaN(ping) ? "-" : ping.toFixed(2);
                
                // Автоматическая прокрутка лога вниз
                var logWindow = document.getElementById("logWindow");
                logWindow.scrollTop = logWindow.scrollHeight;
            }
        };

        // Формируем URL с учетом выбранного хоста и интерфейса
        var url = "ping.php?host=" + encodeURIComponent(targetAddress) + "&interface=" + encodeURIComponent(networkInterface);
        
        xhr.open("GET", url, true);
        xhr.send();
    }
</script>

</body>
</html>
