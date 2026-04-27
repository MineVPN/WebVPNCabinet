<?php
/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ==================================================================
 *      V P N   M A N A G E R   H A N D L E R   F I L E
 * ==================================================================
 * * @category    VPN Subsystem
 * * @package     MineVPN\Server
 * * @version     5.0.0
 * * [WARNING]
 * This source code is strictly proprietary and confidential.
 * Unauthorized reproduction, distribution, or decompilation
 * is strictly prohibited and heavily monitored.
 * * @copyright   2026 MineVPN Systems. All rights reserved.
 *
 * MineVPN Server — VPN Manager POST handler / PRG pattern исполнитель
 *
 * Обрабатывает формы POST со страницы vpn-manager.php (no-JS fallback) И multipart-upload
 * новых конфигов. Логика вынесена в отдельный файл чтобы выполняться ДО вывода HTML в cabinet.php —
 * это позволяет делать header('Location:...') redirect по PRG паттерну.
 *
 * Почему PRG (Post-Redirect-Get):
 *   • Без redirect F5/Ctrl+R в браузере повторяет form submission
 *   • Это вызывало дублирование загруженных конфигов (один файл → несколько записей в configs.json)
 *   • С PRG браузер видит в history GET-запрос — reload безопасен
 *
 * Actions (только без-JS обработчики форм, остальные — через api/vpn_action.php):
 *   • Upload файла конфига (multipart $_FILES['config_file']):
 *       — валидация UPLOAD_ERR_*, расширение (.ovpn/.conf), размер (≤1 KB — 512 KB)
 *       — проверка лимита MINEVPN_MAX_CONFIGS (30 конфигов)
 *       — определение типа (mv_detectConfigType) + извлечение server/port/proto
 *       — генерация vpn_[hex16] ID, запись в configs.json (atomic via flock)
 *   • Delete (из hidden field в form): если JS отключён и юзер нажимает резервную
 *     кнопку для удаления — стоп сервиса если это активный, разлинкование файла,
 *     удаление из configs.json + log_event
 *
 * Flash messaging:
 *   1. Handler записывает $_SESSION['mv_flash'] = ['message' => '...', 'type' => 'success'|'error']
 *   2. Делает redirect на cabinet.php?menu=vpn
 *   3. vpn-manager.php (rendered после redirect через GET) читает flash и unset
 *   4. JS получает window.__flashMessage и показывает через Toast.show()
 *
 * Взаимодействует с:
 *   • cabinet.php           — вызывает этот handler при REQUEST_METHOD=POST и ?menu=vpn
 *   • vpn-manager.php       — источник форм и рендерер flash-сообщений (после redirect)
 *   • includes/vpn_helpers.php — все mv_* вызовы (loadConfigs, saveConfigs, generateConfigId,
 *                              detectConfigType, extractConfigInfo, isValidConfigId, logEvent,
 *                              stopAllServices, disableAllServices, cleanActiveConfigFiles)
 *   • api/vpn_action.php    — AJAX альтернатива для всех других actions
 *                              (rename/move/reorder/toggle_role/activate/stop/restart/bulk_delete)
 *
 * Читает:
 *   • $_FILES['config_file'] — multipart upload
 *   • $_POST['delete_config'] — hidden field с конфиг-ID
 *   • $_POST['config_name']  — custom name (иначе падает на originalName из filename)
 *   • /var/www/vpn-configs/configs.json + .lock
 *   • /var/www/minevpn-state
 *
 * Пишет:
 *   • /var/www/vpn-configs/<configId>.{ovpn|conf} — загруженный конфиг-файл (move_uploaded_file)
 *   • /var/www/vpn-configs/configs.json — обновлённый список
 *   • /var/www/minevpn-state — если удалён активный конфиг
 *   • /var/log/minevpn/events.log — события config_added, config_deleted (с снапшотом имени/server)
 *   • $_SESSION['mv_flash'] — результат POST для vpn-manager.php
 */

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/../includes/vpn_helpers.php';

$message = '';
$messageType = '';

// ── Upload (multipart) ───────────────────────────────────────────────────────
if (isset($_FILES["config_file"]) && !empty($_FILES["config_file"]["name"])) {
    $configs = mv_loadConfigs();
    $uploadedFile = $_FILES["config_file"];
    $uploadErr    = $uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($uploadErr !== UPLOAD_ERR_OK) {
        $phpUploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'Файл превышает upload_max_filesize в php.ini',
            UPLOAD_ERR_FORM_SIZE  => 'Файл превышает MAX_FILE_SIZE в форме',
            UPLOAD_ERR_PARTIAL    => 'Файл загружен только частично',
            UPLOAD_ERR_NO_FILE    => 'Файл не выбран',
            UPLOAD_ERR_NO_TMP_DIR => 'Нет временной директории (проверьте /tmp)',
            UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск',
            UPLOAD_ERR_EXTENSION  => 'Upload заблокирован PHP-расширением',
        ];
        $message = 'Ошибка PHP upload: ' . ($phpUploadErrors[$uploadErr] ?? "код $uploadErr");
        $messageType = 'error';
    } else {
        $originalName = pathinfo($uploadedFile["name"], PATHINFO_FILENAME);
        $extension    = strtolower(pathinfo($uploadedFile["name"], PATHINFO_EXTENSION));
        $maxSize      = 512 * 1024;

        if (count($configs) >= MINEVPN_MAX_CONFIGS) {
            $message = "Достигнут лимит " . MINEVPN_MAX_CONFIGS . " конфигураций. Удалите старые перед добавлением новых";
            $messageType = "error";
        } elseif (!in_array($extension, ['ovpn', 'conf'], true)) {
            $message = "Разрешены только файлы .ovpn и .conf (ваш: .$extension)";
            $messageType = "error";
        } elseif ($uploadedFile['size'] > $maxSize) {
            $message = "Файл слишком большой (" . round($uploadedFile['size']/1024, 1) . " KB, максимум 512 KB)";
            $messageType = "error";
        } elseif ($uploadedFile['size'] === 0) {
            $message = "Файл пустой";
            $messageType = "error";
        } elseif (!is_dir(MINEVPN_CONFIG_PATH)) {
            $message = "Директория " . MINEVPN_CONFIG_PATH . " не существует. Запустите sudo bash update.sh";
            $messageType = "error";
        } elseif (!is_writable(MINEVPN_CONFIG_PATH)) {
            $owner = function_exists('posix_getpwuid') ? posix_getpwuid(fileowner(MINEVPN_CONFIG_PATH))['name'] : fileowner(MINEVPN_CONFIG_PATH);
            $group = function_exists('posix_getgrgid') ? posix_getgrgid(filegroup(MINEVPN_CONFIG_PATH))['name'] : filegroup(MINEVPN_CONFIG_PATH);
            $perms = substr(sprintf('%o', fileperms(MINEVPN_CONFIG_PATH)), -4);
            $message = "Директория " . MINEVPN_CONFIG_PATH . " не доступна для записи (владелец=$owner:$group, права=$perms). Выполните: sudo chown root:www-data " . MINEVPN_CONFIG_PATH . " && sudo chmod 770 " . MINEVPN_CONFIG_PATH;
            $messageType = "error";
        } else {
            $configId     = mv_generateConfigId();
            $savedFile    = $configId . '.' . $extension;
            $savedPath    = MINEVPN_CONFIG_PATH . '/' . $savedFile;

            if (!move_uploaded_file($uploadedFile["tmp_name"], $savedPath)) {
                $lastErr = error_get_last();
                $message = "move_uploaded_file не удалось: " . ($lastErr['message'] ?? 'неизвестная причина');
                $messageType = "error";
            } else {
                $configType = mv_detectConfigType($savedPath);
                $configInfo = mv_extractConfigInfo($savedPath, $configType);
                $rawName    = isset($_POST['config_name']) && !empty(trim($_POST['config_name']))
                    ? trim($_POST['config_name']) : $originalName;
                $customName = mv_safeSubstr($rawName, 0, MINEVPN_MAX_CONFIG_NAME);

                $maxPriority = 0;
                foreach ($configs as $c) {
                    if (isset($c['priority']) && $c['priority'] > $maxPriority) $maxPriority = $c['priority'];
                }

                $configs[$configId] = [
                    'id'                => $configId,
                    'name'              => $customName,
                    'filename'          => $savedFile,
                    'original_filename' => $uploadedFile["name"],
                    'type'              => $configType,
                    'server'            => $configInfo['server'],
                    'port'              => $configInfo['port'],
                    'protocol'          => $configInfo['protocol'],
                    'priority'          => $maxPriority + 1,
                    'role'              => 'backup',
                    'created_at'        => date('Y-m-d H:i:s'),
                    'last_used'         => null,
                ];

                mv_saveConfigs($configs);
                mv_logEvent('config_added', $configId);
                $message = "Конфигурация '{$customName}' успешно добавлена";
                $messageType = "success";
            }
        }
    }

    $_SESSION['mv_flash'] = ['message' => $message, 'type' => $messageType];
    header('Location: cabinet.php?menu=vpn');
    exit();
}

// ── Delete (no-JS fallback с hidden field) ──────────────────────────────────
if (isset($_POST['delete_config'])) {
    $configId = (string)$_POST['delete_config'];
    if (!mv_isValidConfigId($configId)) {
        $message = 'Неверный ID конфига'; $messageType = 'error';
    } else {
        $configs = mv_loadConfigs();
        if (!isset($configs[$configId])) {
            $message = 'Конфиг не найден'; $messageType = 'error';
        } else {
            $vpnState = mv_readState();
            if ($vpnState['ACTIVE_ID'] === $configId) {
                mv_stopAllServices(); mv_disableAllServices(); mv_cleanActiveConfigFiles();
                $newPrimary = ($vpnState['PRIMARY_ID'] === $configId) ? '' : $vpnState['PRIMARY_ID'];
                mv_saveState('stopped', '', $newPrimary, '');
            } elseif ($vpnState['PRIMARY_ID'] === $configId) {
                mv_saveState($vpnState['STATE'], $vpnState['ACTIVE_ID'], '', $vpnState['ACTIVATED_BY']);
            }
            $filePath = MINEVPN_CONFIG_PATH . '/' . $configs[$configId]['filename'];
            $nameSnap = $configs[$configId]['name']   ?? '?';
            $serverSnap = $configs[$configId]['server'] ?? '';
            if (file_exists($filePath)) unlink($filePath);
            unset($configs[$configId]);
            mv_saveConfigs($configs);
            mv_logEvent('config_deleted', $configId, $nameSnap, $serverSnap);
            $message = "Конфигурация удалена"; $messageType = "success";
        }
    }

    $_SESSION['mv_flash'] = ['message' => $message, 'type' => $messageType];
    header('Location: cabinet.php?menu=vpn');
    exit();
}

// Если ни upload ни delete — handler ничего не делает, cabinet.php продолжает
// рендер vpn-manager.php нормально.
