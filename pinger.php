<?php
session_start(); // Начало сессии

// Проверяем, установлена ли сессия
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    // Сессия не установлена или пользователь не аутентифицирован, перенаправляем на страницу входа
    header("Location: login.php");
    exit(); // Важно вызвать exit() после перенаправления, чтобы предотвратить дальнейшее выполнение кода
}

// Весь ваш код для страницы кабинета может быть добавлен здесь
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ping Checker</title>
    <style>
        #logWindow {
            height: 600px; /* Высота окошка с логом */
            overflow-y: auto; /* Включаем вертикальную прокрутку */
            border: 1px solid #ccc; /* Добавляем рамку */
            padding: 5px; /* Добавляем отступы внутри окошка */
        }
        #logWindow p {
            margin: 0; /* Убираем внешние отступы у элементов <p> */
        }
    </style>
</head>
<body>
    <h2>Ping Checker</h2>
    <div>
        <label for="targetAddress">Адрес для проверки:</label>
        <input type="text" id="targetAddress" placeholder="Введите адрес" value="8.8.8.8">
        <button id="startButton">Старт</button>
        <button id="stopButton">Стоп</button>
    </div>
    <div>Всего : <span id="allCount">0</span>, Успешных: <span id="successCount">0</span>, Неуспешных: <span id="failCount">0</span>, Процент потерь: <span id="lossPercent">0%</span></div>
    <div>Минимальный: <span id="minPing">-</span> мс, Максимальный: <span id="maxPing">-</span> мс, Средний: <span id="avgPing">-</span> мс</div>
    <div id="logWindow"></div> <!-- Окошко с логом -->

    <script>
        var intervalId; // Идентификатор интервала измерения пинга
        var allCount = 0;
        var successCount = 0;
        var failCount = 0;
        var minPing = Infinity;
        var maxPing = -Infinity;
        var totalPing = 0;

        document.getElementById("startButton").addEventListener("click", function() {
            var targetAddress = document.getElementById("targetAddress").value;
            if (targetAddress.trim() === "") {
                alert("Введите адрес для проверки пинга!");
                return;
            }
            // Очищаем лог перед началом нового измерения
            document.getElementById("logWindow").innerHTML = "";
            // Если уже запущен интервал измерения, останавливаем его
            if (intervalId) {
                clearInterval(intervalId);
            }
            // Сбрасываем счетчики перед новым измерением
            allCount = 0;
            successCount = 0;
            failCount = 0;
            minPing = Infinity;
            maxPing = -Infinity;
            totalPing = 0;
            // Запускаем интервал измерения пинга
            intervalId = setInterval(function() {
                measurePing(targetAddress);
            }, 1000);
            // Запускаем измерение пинга сразу после нажатия кнопки
            measurePing(targetAddress);
        });

        document.getElementById("stopButton").addEventListener("click", function() {
            // Очищаем интервал измерения пинга
            clearInterval(intervalId);
        });

        function measurePing(targetAddress) {
            var xhr = new XMLHttpRequest();
            xhr.onreadystatechange = function() {
                if (xhr.readyState == 4) {
                    // Получаем текущую дату и время
                    var now = new Date().toLocaleString();
                    allCount++;
                    if (xhr.status == 200 && xhr.responseText.indexOf("NO PING") == -1) {
                        successCount++;
                        var ping = parseFloat(xhr.responseText); // Получаем значение пинга как число
                        // Обновляем минимальный и максимальный пинг
                        minPing = Math.min(minPing, ping);
                        maxPing = Math.max(maxPing, ping);
                        // Обновляем сумму пингов для подсчета среднего значения
                        totalPing += ping;
                        // Создаем новую строку с результатом замера
                        var logEntry = document.createElement('p');
                        logEntry.textContent = now + ': ' + ping + ' мс';
                        // Добавляем строку в окошко с логом
                        document.getElementById("logWindow").appendChild(logEntry);
                    } else {
                        failCount++;
                        // Создаем новую строку с сообщением об ошибке
                        var logEntry = document.createElement('p');
                        logEntry.textContent = now + ': NO PING';
                        // Добавляем строку в окошко с логом
                        document.getElementById("logWindow").appendChild(logEntry);
                    }
                    // Обновляем счетчики на странице
                    document.getElementById("allCount").textContent = allCount;
                    document.getElementById("successCount").textContent = successCount;
                    document.getElementById("failCount").textContent = failCount;
                    // Подсчитываем процент потерь и обновляем на странице
                    var total = successCount + failCount;
                    var lossPercent = (failCount / total * 100).toFixed(2);
                    document.getElementById("lossPercent").textContent = lossPercent + "%";
                    // Обновляем минимальный, максимальный и средний пинг на странице
                    document.getElementById("minPing").textContent = (minPing == Infinity) ? "-" : minPing.toFixed(2);
                    document.getElementById("maxPing").textContent = (maxPing == -Infinity) ? "-" : maxPing.toFixed(2);
                    var avgPing = totalPing / successCount;
                    document.getElementById("avgPing").textContent = isNaN(avgPing) ? "-" : avgPing.toFixed(2);
                    // Прокручиваем окошко с логом до самого низа (новые записи)
                    document.getElementById("logWindow").scrollTop = document.getElementById("logWindow").scrollHeight;
                }
            };
            xhr.open("GET", "ping.php?host=" + encodeURIComponent(targetAddress), true);
            xhr.send();
        }
    </script>
</body>
</html>
