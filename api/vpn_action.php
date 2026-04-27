<?php
/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ══════════════════════════════════════════════════════════════════
 *           A P I   V P N   A C T I O N   F I L E
 * ══════════════════════════════════════════════════════════════════
 * * @category    VPN Subsystem
 * * @package     MineVPN\Server
 * * @version     5.0.0
 * * [WARNING] 
 * This source code is strictly proprietary and confidential. 
 * Unauthorized reproduction, distribution, or decompilation 
 * is strictly prohibited and heavily monitored.
 * * @copyright   2026 MineVPN Systems. All rights reserved.
 *
 * MineVPN — VPN Action API (JSON endpoint)
 *
 * AJAX-эндпоинт для действий над VPN-конфигами. Замена для POST→reload flow
 * в vpn-manager.php. Позволяет robust UX с toast-уведомлениями вместо
 * reload-ов и анимациями без мерцания.
 *
 * Request:  POST  application/json  или  application/x-www-form-urlencoded
 *   { "action": "activate", "config_id": "vpn_..." }
 *
 * Response: application/json
 *   { "ok": true,  "message": "...", "data": { ... } }
 *   { "ok": false, "error":   "..." }
 *
 * Actions:
 *   activate, delete, rename, move, reorder, toggle_role,
 *   stop, restart, bulk_delete, status
 *
 * Взаимодействует с:
 *   • assets/js/pages/vpn.js — AJAX-клиент этого endpoint, добавляет toast-уведомления
 *   • includes/vpn_helpers.php — константы и хелперы (mv_loadConfigs, mv_saveConfigs,
 *                                mv_isValidConfigId, mv_dedupConfigs, MINEVPN_CONFIG_PATH)
 *
 * Читает:
 *   • /var/www/vpn-configs/configs.json — список конфигов (лок-файл configs.json.lock)
 *   • /var/www/vpn-configs/*.conf      — сами файлы конфигов
 *   • /var/www/minevpn-state            — текущий STATE для rollback-логики
 *
 * Пишет:
 *   • /var/www/vpn-configs/configs.json — при delete/rename/move/reorder/toggle_role/activate
 *   • /var/www/minevpn-state            — при activate/stop/restart (STATE, ACTIVE_ID, PRIMARY_ID)
 *   • /etc/wireguard/tun0.conf          — копия активного WG конфига при activate
 *   • /etc/openvpn/tun0.conf            — копия активного OVPN конфига при activate
 *   • /var/log/minevpn/events.log       — события manual_activate, deletion, rename и др.
 *
 * Вызывает:
 *   • sudo systemctl start/stop/restart wg-quick@tun0
 *   • sudo systemctl start/stop/restart openvpn@tun0
 *   • sudo systemctl enable/disable {wg-quick,openvpn}@tun0 (через sudoers NOPASSWD)
 */

session_start();

// ── Auth ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/vpn_helpers.php';

// ── Parse body (JSON или form) ───────────────────────────────────────
$raw = file_get_contents('php://input');
$body = [];
if (!empty($raw)) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $body = $decoded;
}
if (empty($body)) $body = $_POST;

$action    = (string)($body['action'] ?? '');
$configId  = (string)($body['config_id'] ?? '');

/**
 * Helper для JSON response
 */
function respond(bool $ok, string $message = '', array $data = []): void {
    $out = ['ok' => $ok];
    if ($ok) {
        if ($message) $out['message'] = $message;
        if ($data)    $out['data']    = $data;
    } else {
        $out['error'] = $message;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Dispatch ─────────────────────────────────────────────────────────
switch ($action) {

// ─────────────────────────────────────────────────────────────────
// ACTIVATE — активация с rollback-логикой при падении
// ─────────────────────────────────────────────────────────────────
case 'activate':
    if (!mv_isValidConfigId($configId)) respond(false, 'Неверный ID конфига');
    $configs = mv_loadConfigs();
    if (!isset($configs[$configId])) respond(false, 'Конфиг не найден');

    $config     = $configs[$configId];
    $sourceFile = MINEVPN_CONFIG_PATH . '/' . $config['filename'];
    if (!file_exists($sourceFile)) respond(false, 'Файл конфига отсутствует');

    // Сохраняем предыдущее состояние для rollback
    $prevState    = mv_readState();
    $prevActiveId = $prevState['ACTIVE_ID'];
    $prevConfig   = ($prevActiveId && isset($configs[$prevActiveId])) ? $configs[$prevActiveId] : null;
    $prevSource   = $prevConfig ? (MINEVPN_CONFIG_PATH . '/' . $prevConfig['filename']) : null;
    $canRollback  = $prevActiveId && $prevActiveId !== $configId && $prevSource && file_exists($prevSource);

    // HANDSHAKE: STATE=restarting — HC daemon ждёт
    mv_saveState('restarting', $prevActiveId, $prevState['PRIMARY_ID'], $prevState['ACTIVATED_BY']);

    // Stop + cleanup
    mv_stopAllServices();
    sleep(1);
    mv_cleanActiveConfigFiles();

    // Start новый
    $copyOk = mv_activateServiceFromFile($sourceFile, $config['type']);
    if (!$copyOk) {
        mv_saveState('stopped', '', $prevState['PRIMARY_ID'], '');
        respond(false, 'Ошибка копирования конфига');
    }

    if (mv_pollVpnUp(15)) {
        // УСПЕХ — обновляем приоритеты и роли
        uasort($configs, fn($a, $b) => ($a['priority'] ?? 99) - ($b['priority'] ?? 99));
        $newPriority = 2;
        foreach ($configs as $cid => &$c) {
            if ($cid === $configId) {
                $c['role']         = 'primary';
                $c['priority']     = 1;
                $c['last_used']    = date('Y-m-d H:i:s');
                $c['activated_by'] = 'manual';
            } else {
                if (($c['role'] ?? '') === 'primary')          $c['role'] = 'backup';
                if (($c['activated_by'] ?? '') === 'failover') $c['activated_by'] = '';
                $c['priority'] = $newPriority++;
            }
        }
        unset($c);
        mv_saveConfigs($configs);
        mv_saveState('running', $configId, $configId, 'manual');
        mv_logEvent('manual_activate', $configId);
        respond(true, "Конфиг '{$config['name']}' активирован");
    }

    // Не поднялся — rollback
    if ($canRollback) {
        mv_stopAllServices();
        sleep(1);
        mv_cleanActiveConfigFiles();
        $rollbackOk = mv_activateServiceFromFile($prevSource, $prevConfig['type']);
        $prevRose = $rollbackOk ? mv_pollVpnUp(10) : false;

        if ($prevRose) {
            mv_saveState('running', $prevActiveId, $prevState['PRIMARY_ID'] ?: $prevActiveId, 'manual');
            mv_logEvent('rollback', $configId, $prevActiveId);
            respond(false, "Конфиг '{$config['name']}' не работает — вернулись на '{$prevConfig['name']}'");
        }
        mv_saveState('stopped', '', $prevState['PRIMARY_ID'], '');
        respond(false, 'Не удалось активировать конфиг, откат тоже не удался — VPN остановлен');
    }

    mv_saveState('stopped', '', $prevState['PRIMARY_ID'], '');
    respond(false, "Конфиг '{$config['name']}' не поднялся за 15 секунд — VPN остановлен");

// ─────────────────────────────────────────────────────────────────
// DELETE
// ─────────────────────────────────────────────────────────────────
case 'delete':
    if (!mv_isValidConfigId($configId)) respond(false, 'Неверный ID конфига');
    $configs = mv_loadConfigs();
    if (!isset($configs[$configId])) respond(false, 'Конфиг не найден');

    $vpnState = mv_readState();
    if ($vpnState['ACTIVE_ID'] === $configId) {
        mv_stopAllServices();
        mv_disableAllServices();
        mv_cleanActiveConfigFiles();
        $newPrimary = ($vpnState['PRIMARY_ID'] === $configId) ? '' : $vpnState['PRIMARY_ID'];
        mv_saveState('stopped', '', $newPrimary, '');
    } elseif ($vpnState['PRIMARY_ID'] === $configId) {
        mv_saveState($vpnState['STATE'], $vpnState['ACTIVE_ID'], '', $vpnState['ACTIVATED_BY']);
    }

    $filePath   = MINEVPN_CONFIG_PATH . '/' . $configs[$configId]['filename'];
    $nameSnap   = $configs[$configId]['name']   ?? '?';
    $serverSnap = $configs[$configId]['server'] ?? '';
    if (file_exists($filePath)) unlink($filePath);
    unset($configs[$configId]);
    mv_saveConfigs($configs);
    mv_logEvent('config_deleted', $configId, $nameSnap, $serverSnap);

    respond(true, 'Конфигурация удалена');

// ─────────────────────────────────────────────────────────────────
// RENAME
// ─────────────────────────────────────────────────────────────────
case 'rename':
    if (!mv_isValidConfigId($configId)) respond(false, 'Неверный ID конфига');
    $newName = mv_safeSubstr(trim((string)($body['new_name'] ?? '')), 0, MINEVPN_MAX_CONFIG_NAME);
    if (empty($newName)) respond(false, 'Название не может быть пустым');

    $configs = mv_loadConfigs();
    if (!isset($configs[$configId])) respond(false, 'Конфиг не найден');

    $oldName = $configs[$configId]['name'] ?? '?';
    $configs[$configId]['name'] = $newName;
    mv_saveConfigs($configs);
    mv_logEvent('config_renamed', $configId, $oldName, $newName);

    respond(true, 'Конфигурация переименована', ['new_name' => $newName]);

// ─────────────────────────────────────────────────────────────────
// MOVE — изменение приоритета (up/down на 1 позицию)
// ─────────────────────────────────────────────────────────────────
case 'move':
    if (!mv_isValidConfigId($configId)) respond(false, 'Неверный ID конфига');
    $direction = ($body['direction'] ?? '');
    if (!in_array($direction, ['up', 'down'], true)) respond(false, 'Неверное направление');

    $configs = mv_loadConfigs();
    if (!isset($configs[$configId])) respond(false, 'Конфиг не найден');

    uasort($configs, fn($a, $b) => ($a['priority'] ?? 99) - ($b['priority'] ?? 99));
    $ids = array_keys($configs);
    $currentIndex = array_search($configId, $ids);

    if ($direction === 'up' && $currentIndex > 0) {
        $swapId = $ids[$currentIndex - 1];
        [$configs[$configId]['priority'], $configs[$swapId]['priority']] =
        [$configs[$swapId]['priority'],   $configs[$configId]['priority']];
        mv_saveConfigs($configs);
    } elseif ($direction === 'down' && $currentIndex < count($ids) - 1) {
        $swapId = $ids[$currentIndex + 1];
        [$configs[$configId]['priority'], $configs[$swapId]['priority']] =
        [$configs[$swapId]['priority'],   $configs[$configId]['priority']];
        mv_saveConfigs($configs);
    }

    respond(true);

// ─────────────────────────────────────────────────────────────────
// REORDER — bulk переупорядочение после drag-and-drop
// Принимает массив id в новом порядке: ["vpn_a", "vpn_b", "vpn_c"]
// ─────────────────────────────────────────────────────────────────
case 'reorder':
    $order = $body['order'] ?? [];
    if (!is_array($order) || empty($order)) respond(false, 'Пустой порядок');

    // Все id должны быть валидными
    foreach ($order as $id) {
        if (!mv_isValidConfigId((string)$id)) respond(false, 'Неверный ID в порядке');
    }

    $configs = mv_loadConfigs();
    $priority = 1;
    foreach ($order as $id) {
        if (isset($configs[$id])) {
            $configs[$id]['priority'] = $priority++;
        }
    }
    mv_saveConfigs($configs);
    respond(true);

// ─────────────────────────────────────────────────────────────────
// TOGGLE_ROLE — backup ↔ none
// ─────────────────────────────────────────────────────────────────
case 'toggle_role':
    if (!mv_isValidConfigId($configId)) respond(false, 'Неверный ID конфига');
    $configs  = mv_loadConfigs();
    $vpnState = mv_readState();
    if (!isset($configs[$configId])) respond(false, 'Конфиг не найден');

    $currentRole = $configs[$configId]['role'] ?? 'none';
    if ($currentRole === 'primary' || $configId === $vpnState['ACTIVE_ID']) {
        respond(false, 'Нельзя изменить роль активного конфига');
    }
    $newRole = ($currentRole === 'backup') ? 'none' : 'backup';
    $configs[$configId]['role'] = $newRole;
    mv_saveConfigs($configs);
    mv_logEvent('role_changed', $configId, $newRole);

    $roleName = $newRole === 'backup' ? 'резервный' : 'не участвует';
    respond(true, "'{$configs[$configId]['name']}' — {$roleName}", ['role' => $newRole]);

// ─────────────────────────────────────────────────────────────────
// STOP — остановка VPN
// ─────────────────────────────────────────────────────────────────
case 'stop':
    $vpnState  = mv_readState();
    $stoppedId = $vpnState['ACTIVE_ID'];
    mv_saveState('stopped', $vpnState['ACTIVE_ID'], $vpnState['PRIMARY_ID'], $vpnState['ACTIVATED_BY']);

    mv_stopAllServices();
    mv_disableAllServices();
    mv_cleanActiveConfigFiles();
    sleep(2);
    if ($stoppedId) mv_logEvent('vpn_stopped', $stoppedId);
    respond(true, 'VPN остановлен');

// ─────────────────────────────────────────────────────────────────
// RESTART — перезапуск текущего VPN
// ─────────────────────────────────────────────────────────────────
case 'restart':
    $activeConfig = mv_getActiveConfig();
    if (!$activeConfig) respond(false, 'Нет активного конфига для перезапуска');

    $vpnState = mv_readState();
    mv_saveState('restarting', $vpnState['ACTIVE_ID'], $vpnState['PRIMARY_ID'], $vpnState['ACTIVATED_BY']);

    if ($activeConfig['type'] === 'wireguard') {
        shell_exec('sudo systemctl restart wg-quick@tun0');
    } else {
        shell_exec('sudo systemctl restart openvpn@tun0');
    }
    sleep(3);

    if (mv_checkVPNStatus()) {
        mv_saveState('running', $vpnState['ACTIVE_ID'], $vpnState['PRIMARY_ID'], $vpnState['ACTIVATED_BY']);
        if (!empty($vpnState['ACTIVE_ID'])) mv_logEvent('vpn_restarted', $vpnState['ACTIVE_ID']);
        respond(true, 'VPN перезапущен');
    }
    mv_saveState('recovering', $vpnState['ACTIVE_ID'], $vpnState['PRIMARY_ID'], $vpnState['ACTIVATED_BY']);
    respond(false, 'VPN не поднялся, автовосстановление...');

// ─────────────────────────────────────────────────────────────────
// BULK_DELETE — множественное удаление
// ─────────────────────────────────────────────────────────────────
case 'bulk_delete':
    $ids = $body['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) respond(false, 'Пустой список для удаления');
    foreach ($ids as $id) {
        if (!mv_isValidConfigId((string)$id)) respond(false, 'Неверный ID в списке');
    }

    $configs  = mv_loadConfigs();
    $vpnState = mv_readState();
    $activeId = $vpnState['ACTIVE_ID'];
    $deleted  = [];
    $skipped  = [];

    foreach ($ids as $id) {
        if (!isset($configs[$id])) { $skipped[] = $id; continue; }
        if ($id === $activeId) {
            // Активный конфиг удалять в bulk mode не даём — нужно явно подтвердить
            $skipped[] = $id;
            continue;
        }
        $filePath = MINEVPN_CONFIG_PATH . '/' . $configs[$id]['filename'];
        $nameSnap   = $configs[$id]['name']   ?? '?';
        $serverSnap = $configs[$id]['server'] ?? '';
        if (file_exists($filePath)) unlink($filePath);
        if ($vpnState['PRIMARY_ID'] === $id) {
            mv_saveState($vpnState['STATE'], $vpnState['ACTIVE_ID'], '', $vpnState['ACTIVATED_BY']);
            $vpnState['PRIMARY_ID'] = '';
        }
        unset($configs[$id]);
        mv_logEvent('config_deleted', $id, $nameSnap, $serverSnap);
        $deleted[] = $id;
    }
    mv_saveConfigs($configs);

    $msg = 'Удалено: ' . count($deleted);
    if (!empty($skipped)) $msg .= ' (пропущено активный: ' . count($skipped) . ')';
    respond(true, $msg, ['deleted' => $deleted, 'skipped' => $skipped]);

// ─────────────────────────────────────────────────────────────────
// STATUS — текущее состояние VPN + active_id (для refresh после действий)
// ─────────────────────────────────────────────────────────────────
case 'status':
    $state    = mv_readState();
    $isUp     = mv_checkVPNStatus();
    respond(true, '', [
        'state'        => $state['STATE'],
        'active_id'    => $state['ACTIVE_ID'],
        'primary_id'   => $state['PRIMARY_ID'],
        'activated_by' => $state['ACTIVATED_BY'],
        'connected'    => $isUp,
    ]);

// ─────────────────────────────────────────────────────────────────
default:
    respond(false, 'Неизвестное действие: ' . $action);
}
