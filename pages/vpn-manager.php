<?php
/**
 * ══════════════════════════════════════════════════════════════════
 *             V P N   M A N A G E R   P A G E   F I L E
 * ══════════════════════════════════════════════════════════════════
 * * @category    VPN Subsystem
 * * @package     VPN\Server
 * * @version     5.0.0
 * * [WARNING] 
 * This source code is strictly proprietary and confidential. 
 * Unauthorized reproduction, distribution, or decompilation 
 * is strictly prohibited and heavily monitored.
 * * @copyright   2026 VPN Systems. All rights reserved.
 *
 * VPN Server — VPN Manager / Страница управления VPN-конфигами
 *
 * Главная рабочая страница панели. Отображает список конфигов, роли (primary/backup),
 * статус VPN, drag-drop сортировку по приоритету, upload форму. Рендерит HTML и подключает
 * vpn.js — реальные действия над конфигами идут через AJAX в api/vpn_action.php.
 *
 * Архитектура actions:
 *   • No-JS form POST       — идёт в vpn-manager.handler.php (PRG redirect)
 *                            для Upload и Delete (fallback без JS)
 *   • AJAX (все остальные) — vpn.js → api/vpn_action.php
 *                            (activate, delete, rename, move, reorder, toggle_role,
 *                             stop, restart, bulk_delete, status)
 *
 * Что рендерит:
 *   • Status card — соединение, имя конфига, тип, пинг, autorestart/failover баджи
 *   • Configs list — список с drag-drop sortable, роли, кнопки действий
 *   • Promo banner   — если 0 конфигов (CTA на  + Telegram bot)
 *   • Single-config warning — если ровно 1 конфиг и активен (CTA на бекап)
 *   • Upload form    — multipart форма для новых конфигов + "Купить" линк
 *
 * Lazy migration (выполняется при первом рендере новой версии):
 *   • Подхват priority/role для старых конфигов (без этих полей)
 *   • Чистка legacy 'failover' булевых полей (их заменило role='backup')
 *   • Fallback опознание активного конфига по md5 (если state-файл повреждён)
 *
 * Три источника истины для «активного конфига» (приоритет сверху):
 *   1. /var/www/minevpn-state ACTIVE_ID — основной источник (пишет HC daemon и vpn_action)
 *   2. systemctl is-active wg-quick@/openvpn@tun0 — реальный статус сервиса
 *   3. md5(/etc/wireguard/tun0.conf vs /var/www/vpn-configs/<id>.conf) — последний резерв
 *
 * Взаимодействует с:
 *   • cabinet.php — включает этот файл как pages/vpn-manager.php при ?menu=vpn (GET)
 *   • vpn-manager.handler.php — PRG handler, выполняется раньше при POST
 *   • includes/vpn_helpers.php — все mv_* вызовы (loadConfigs, saveConfigs, getActiveConfig,
 *                              checkVPNStatus, readState, saveState)
 *   • api/vpn_action.php — AJAX endpoint для всех действий (вызывается из vpn.js)
 *   • api/status_check.php — polling state (live update sidebar/badges)
 *   • api/ping.php — измерение пинга до активного сервера (в status card)
 *
 * Frontend assets:
 *   • assets/css/pages/vpn.css       — стили страницы (configs list, status card, promo banner)
 *   • assets/js/pages/vpn.js        — AJAX-клиент для api/vpn_action.php, поллинг status_check,
 *                                     детект failover (markFailoverPending), drag-drop sortable
 *
 * Режимы отображения:
 *   • 0 конфигов           → promo banner вместо списка
 *   • 1 конфиг активен  → single-config warning (рекомендация добавить бекап)
 *   • N конфигов           → полный UI с sortable списком
 *   • Активирован резерв    → badge рядом с active config ("запасной режим")
 */

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/../includes/vpn_helpers.php';

// ──────────────────────────────────────────────────────────────────────
// POST handlers — вынесены в pages/vpn-manager.handler.php (выполняется в cabinet.php
// ДО вывода HTML — для PRG redirect, который бьёт дублирование конфигов).
// После POST браузер получит redirect и GET-запрос сюда. Результат POST передаётся
// через $_SESSION['mv_flash'] — и подхватывается ниже.
// ──────────────────────────────────────────────────────────────────────

$message = '';
$messageType = '';

if (!empty($_SESSION['mv_flash'])) {
    $message = $_SESSION['mv_flash']['message'] ?? '';
    $messageType = $_SESSION['mv_flash']['type'] ?? '';
    unset($_SESSION['mv_flash']);
}

// ──────────────────────────────────────────────────────────────────────
// Data loading
// ──────────────────────────────────────────────────────────────────────

$configs      = mv_loadConfigs();
$isConnected  = mv_checkVPNStatus();
$activeConfig = mv_getActiveConfig();
$vpnState     = mv_readState();

$activeConfigId       = null;
$primaryConfigId      = null;
$activatedByFailover  = false;

if (!empty($vpnState['ACTIVE_ID']) && isset($configs[$vpnState['ACTIVE_ID']])) {
    $activeConfigId      = $vpnState['ACTIVE_ID'];
    $activatedByFailover = ($vpnState['ACTIVATED_BY'] === 'failover');
}
if (!empty($vpnState['PRIMARY_ID']) && isset($configs[$vpnState['PRIMARY_ID']])) {
    $primaryConfigId = $vpnState['PRIMARY_ID'];
}

// Fallback: state-файл отсутствует — опознаем по md5
if (!$activeConfigId && $activeConfig) {
    $activeContent = file_get_contents($activeConfig['file']);
    foreach ($configs as $id => $config) {
        $configPath = MINEVPN_CONFIG_PATH . '/' . $config['filename'];
        if (file_exists($configPath) && md5($activeContent) === md5(file_get_contents($configPath))) {
            $activeConfigId = $id;
            $primaryConfigId = $id;
            mv_saveState('running', $id, $id, 'manual');
            break;
        }
    }
}

// Settings (autorestart, failover)
$settingsFile = '/var/www/settings';
$autoRestartEnabled = false;
$failoverEnabled    = false;
if (file_exists($settingsFile)) {
    $c = file_get_contents($settingsFile);
    $autoRestartEnabled = (strpos($c, 'autoupvpn=true') !== false);
    $failoverEnabled    = (strpos($c, 'failover=true') !== false);
}

// Миграция: priority + role для старых конфигов
$needsSave = false;
$p = 1;
foreach ($configs as $id => &$cfg) {
    if (!isset($cfg['priority'])) { $cfg['priority'] = $p++; $needsSave = true; }
    if (!isset($cfg['role'])) {
        $cfg['role'] = ($id === $activeConfigId) ? 'primary' : 'backup';
        $needsSave = true;
    }
    if (isset($cfg['failover'])) {
        if ($cfg['failover'] && ($cfg['role'] ?? '') !== 'primary') $cfg['role'] = 'backup';
        unset($cfg['failover'], $cfg['_migrated_role']);
        $needsSave = true;
    }
    if (isset($cfg['_migrated_role'])) { unset($cfg['_migrated_role']); $needsSave = true; }
}
unset($cfg);
if ($needsSave) mv_saveConfigs($configs);

// Сортировка по приоритету
uasort($configs, fn($a, $b) => ($a['priority'] ?? 99) - ($b['priority'] ?? 99));

// Подсчёт backup-конфигов
$backupCount = 0;
foreach ($configs as $c) {
    if (($c['role'] ?? 'none') === 'backup') $backupCount++;
}
$primaryDown = ($activatedByFailover && $primaryConfigId && $primaryConfigId !== $activeConfigId);

// Cache-buster для assets
// 5.5.7 → 5.5.8: vpn.css — компактні .config-item (padding 4/5→3/4, gap 4→3, item-gap 3→2),
//                .config-type-icon 52→42, svg 24→20, .config-priority 44/40→36/32,
//                .action-menu-btn 44→36, .config-search input 44→38, header margin менше.
//                Шрифти БЕЗ ЗМІН.
$vpnAssetsVer = '5.5.8';
?>

<!-- Page-specific CSS + state data для polling -->
<link rel="stylesheet" href="assets/css/pages/vpn.css?v=<?php echo $vpnAssetsVer; ?>">
<div id="vpn-state-data"
     data-active-id="<?php echo htmlspecialchars($activeConfigId ?? ''); ?>"
     data-state="<?php echo htmlspecialchars($vpnState['STATE'] ?? 'stopped'); ?>"
     hidden></div>

<?php if (!empty($message)): ?>
<!-- Flash message: подхватывается app.js и показывается через Toast -->
<script>
window.__flashMessage = {
    text: <?php echo json_encode($message, JSON_UNESCAPED_UNICODE); ?>,
    type: <?php echo json_encode($messageType); ?>
};
</script>
<?php endif; ?>

<div class="vpn-layout">

    <!-- ══════════════════════════ LEFT column ══════════════════════════ -->
    <div class="vpn-left">

        <!-- ── Status card ─────────────────────────────────────── -->
        <div class="card status-card">
            <h2 class="card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                Статус VPN
            </h2>

            <div class="status-list">
                <div class="status-row">
                    <span class="status-label">Соединение:</span>
                    <span id="connection-status" class="<?php echo $isConnected ? 'status-pill status-pill--ok' : 'status-pill status-pill--err'; ?>">
                        <?php if ($isConnected): ?>
                            <span class="status-dot status-dot--ok status-dot--pulse"></span>Подключено
                        <?php else: ?>
                            Отключено
                        <?php endif; ?>
                    </span>
                </div>

                <div class="status-row">
                    <span class="status-label">Конфиг:</span>
                    <span class="status-value">
                        <?php
                        if ($activeConfigId && isset($configs[$activeConfigId])) {
                            echo htmlspecialchars($configs[$activeConfigId]['name']);
                        } elseif ($activeConfig) {
                            echo $activeConfig['type'] === 'wireguard' ? 'WireGuard' : 'OpenVPN';
                        } else {
                            echo '<span class="text-muted">Не установлен</span>';
                        }
                        ?>
                    </span>
                </div>

                <?php if ($activeConfig): ?>
                <div class="status-row">
                    <span class="status-label">Тип:</span>
                    <span class="status-value <?php echo $activeConfig['type'] === 'wireguard' ? 'text-violet' : 'text-amber'; ?>">
                        <?php echo $activeConfig['type'] === 'wireguard' ? 'WireGuard' : 'OpenVPN'; ?>
                    </span>
                </div>
                <?php endif; ?>

                <div class="status-row">
                    <span class="status-label">Пинг:</span>
                    <span id="ping-display" class="status-value mono text-muted">—</span>
                </div>

                <div class="status-row">
                    <span class="status-label">Авто-восстановление:</span>
                    <?php if ($autoRestartEnabled): ?>
                        <span class="badge badge--emerald">Вкл</span>
                    <?php else: ?>
                        <span class="badge badge--slate">Выкл</span>
                    <?php endif; ?>
                </div>

                <div class="status-row">
                    <span class="status-label">Резервирование:</span>
                    <?php if ($failoverEnabled && $backupCount >= 1): ?>
                        <span class="badge badge--cyan"><?php echo $backupCount; ?> резервн.</span>
                    <?php elseif ($failoverEnabled): ?>
                        <span class="badge badge--amber">Нет резервных</span>
                    <?php else: ?>
                        <span class="badge badge--slate">Выкл</span>
                    <?php endif; ?>
                </div>

                <?php if ($primaryDown && $primaryConfigId && isset($configs[$primaryConfigId])): ?>
                <div class="failover-notice">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.072 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                    <div class="failover-notice-text">
                        Работает резервный конфиг
                        <div class="failover-notice-sub">Основной «<?php echo htmlspecialchars($configs[$primaryConfigId]['name']); ?>» недоступен</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Control buttons -->
            <?php if ($activeConfigId && $vpnState['STATE'] !== 'stopped'): ?>
                <div class="status-actions">
                    <button type="button" class="btn btn--warning" data-action="restart">Перезапустить</button>
                    <button type="button" class="btn btn--danger" data-action="stop">Остановить</button>
                </div>
            <?php elseif ($activeConfigId): ?>
                <button type="button" class="btn btn--primary btn--block"
                        data-action="activate" data-config-id="<?php echo htmlspecialchars($activeConfigId); ?>">
                    Подключить заново
                </button>
            <?php else: ?>
                <div class="text-sm text-muted" style="text-align:center;">Выберите конфиг из списка для подключения</div>
            <?php endif; ?>
        </div>

        <!-- Single config warning: если всего 1 конфиг — резервироваться нечем. Предлагаем купить запас.
             Реальная полезная подсказка — без резерва локалка останется без Инета если основной упадёт. -->
        <?php if (count($configs) === 1 && $activeConfigId): ?>
            <div class="vpn-single-config-warning">
                <div class="vpn-single-config-warning-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <div class="vpn-single-config-warning-body">
                    <div class="vpn-single-config-warning-title">Резервирования нет</div>
                    <div class="vpn-single-config-warning-text">
                        У вас только один конфиг. Если он перестанет работать, локальная сеть
                        останется без интернета. Стоит добавить запасной.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Upload card -->
        <div class="card upload-card">
            <h2 class="card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" style="width:22px;height:22px;color:var(--emerald);">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Добавить конфиг
            </h2>

            <form method="post" enctype="multipart/form-data" class="upload-form" data-vpn-action="upload">
                <input type="text" name="config_name" class="input" placeholder="Название (опционально)" maxlength="64">

                <label id="upload-zone" class="upload-zone" for="config-file-input">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    <div id="upload-zone-text" class="upload-zone-text">.ovpn или .conf</div>
                    <div class="upload-zone-hint">Клик или перетащите файл сюда</div>
                    <input type="file" id="config-file-input" name="config_file" accept=".ovpn,.conf">
                </label>

                <button type="submit" class="btn btn--primary btn--block">Загрузить</button>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════ RIGHT column ══════════════════════════ -->
    <div class="vpn-right">
        <div class="card">
            <div class="config-list-header">
                <div class="config-list-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" style="width:22px;height:22px;color:var(--cyan);">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                    Мои конфигурации
                    <span class="config-list-count" id="config-count"><?php echo count($configs); ?> шт.</span>
                </div>

                <?php if (!empty($configs)): ?>
                <div class="config-list-toolbar">
                    <div class="config-search">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <circle cx="11" cy="11" r="8"/>
                            <path stroke-linecap="round" d="M21 21l-4.35-4.35"/>
                        </svg>
                        <input type="search" id="config-search-input" class="input" placeholder="Поиск..." autocomplete="off">
                    </div>
                    <button type="button" class="btn btn--ghost btn--icon" data-action="bulk-toggle" title="Множественный выбор" aria-label="Множественный выбор">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" width="18" height="18">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- Bulk toolbar -->
            <div class="bulk-toolbar">
                <div class="bulk-count">
                    Выделено: <span id="bulk-count" class="bulk-count-number">0</span>
                </div>
                <div class="bulk-actions">
                    <button type="button" class="btn btn--danger btn--sm" data-action="bulk-delete">
                        Удалить выделенные
                    </button>
                    <button type="button" class="btn btn--ghost btn--sm" data-action="bulk-toggle">
                        Отмена
                    </button>
                </div>
            </div>

            <?php if (empty($configs)): ?>
                <div id="config-empty-state" class="empty-state">
                    <svg class="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                    </svg>
                    <div class="empty-state-title">Нет сохранённых конфигураций</div>
                    <div class="empty-state-text">Загрузите .ovpn или .conf файл слева</div>
                </div>

                <!-- Promo banner: брендовая подсказка где взять конфиги. Показывается только
                     когда список пуст — в этот момент реклама = полезная информация, не раздражает. -->
                <div class="vpn-promo-banner">
                    <div class="vpn-promo-banner-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    </div>
                </div>
            <?php else: ?>
                <div class="config-items" id="config-items">
                    <?php foreach ($configs as $id => $config):
                        $isActive = ($id === $activeConfigId) && ($vpnState['STATE'] !== 'stopped');
                        $isPrimary = (($config['role'] ?? 'none') === 'primary');
                        $isBackup  = (($config['role'] ?? 'none') === 'backup');
                        $typeClass = $config['type'] === 'wireguard' ? 'wireguard' : 'openvpn';
                        $typeLabel = $config['type'] === 'wireguard' ? 'WireGuard' : 'OpenVPN';

                        $itemClass = 'config-item';
                        if ($isActive && $isConnected)         $itemClass .= ' is-active-up';
                        elseif ($isActive && !$isConnected)    $itemClass .= ' is-active-down';
                    ?>
                    <div class="<?php echo $itemClass; ?>"
                         data-config-id="<?php echo htmlspecialchars($id); ?>"
                         data-config-type="<?php echo $typeClass; ?>">

                        <!-- Drag handle / bulk checkbox / click-стрелки для мобильных и touch-устройств -->
                        <div class="reorder-controls">
                            <button type="button" class="reorder-btn reorder-btn--up"
                                    data-action="move-up"
                                    data-config-id="<?php echo htmlspecialchars($id); ?>"
                                    title="Вверх" aria-label="Переместить вверх">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/>
                                </svg>
                            </button>
                            <div class="drag-handle" aria-label="Перетащить" title="Перетащите для изменения порядка">
                                <svg viewBox="0 0 24 24" fill="currentColor">
                                    <circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/>
                                    <circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/>
                                    <circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/>
                                </svg>
                            </div>
                            <button type="button" class="reorder-btn reorder-btn--down"
                                    data-action="move-down"
                                    data-config-id="<?php echo htmlspecialchars($id); ?>"
                                    title="Вниз" aria-label="Переместить вниз">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                        </div>
                        <input type="checkbox" class="bulk-check" aria-label="Выбрать">

                        <!-- Priority + Type icon column -->
                        <div class="priority-type-col">
                            <span class="config-priority"><?php echo $config['priority'] ?? '?'; ?></span>
                            <div class="config-type-icon config-type-icon--<?php echo $typeClass; ?>">
                                <?php if ($config['type'] === 'wireguard'): ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                    </svg>
                                <?php else: ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                    </svg>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Info -->
                        <div class="config-info">
                            <div class="config-name-row">
                                <span class="config-name" title="Двойной клик для переименования">
                                    <?php echo htmlspecialchars($config['name']); ?>
                                </span>
                                <?php if ($isPrimary): ?>
                                    <span class="badge badge--violet">Основной</span>
                                <?php elseif ($isBackup): ?>
                                    <span class="badge badge--cyan">Резерв</span>
                                <?php endif; ?>
                                <?php if ($isActive && $isConnected && $activatedByFailover): ?>
                                    <span class="badge badge--amber">Активен (авто)</span>
                                <?php elseif ($isActive && $isConnected): ?>
                                    <span class="badge badge--emerald">Активен</span>
                                <?php elseif ($isActive && !$isConnected): ?>
                                    <span class="badge badge--rose">Нет связи</span>
                                <?php endif; ?>
                            </div>
                            <div class="config-meta">
                                <span class="config-meta-type--<?php echo $typeClass; ?>"><?php echo $typeLabel; ?></span>
                                <span class="config-meta-dot"></span>
                                <span class="config-meta-server"><?php echo htmlspecialchars($config['server']); ?></span>
                                <?php if (!empty($config['port'])): ?>
                                    <span class="config-meta-dot"></span>
                                    <span class="config-meta-server">:<?php echo htmlspecialchars($config['port']); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($config['last_used'])): ?>
                                <div class="config-lastused">Посл. подключение: <?php echo htmlspecialchars($config['last_used']); ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Actions -->
                        <div class="config-actions">
                            <?php if (!$isActive): ?>
                                <button type="button" class="btn btn--primary btn--sm"
                                        data-action="activate"
                                        data-config-id="<?php echo htmlspecialchars($id); ?>"
                                        data-config-name="<?php echo htmlspecialchars($config['name'], ENT_QUOTES); ?>">
                                    Подключить
                                </button>
                            <?php endif; ?>

                            <div class="action-menu">
                                <button type="button" class="action-menu-btn" aria-label="Действия">
                                    <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                                        <circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/>
                                    </svg>
                                </button>
                                <div class="action-menu-dropdown">
                                    <button type="button" class="action-menu-item"
                                            data-action="rename" data-config-id="<?php echo htmlspecialchars($id); ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                        </svg>
                                        Переименовать
                                    </button>
                                    <?php if (!$isActive && !$isPrimary): ?>
                                    <button type="button" class="action-menu-item"
                                            data-action="toggle-role" data-config-id="<?php echo htmlspecialchars($id); ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                                        </svg>
                                        <?php echo $isBackup ? 'Убрать из резерва' : 'В резерв'; ?>
                                    </button>
                                    <?php endif; ?>
                                    <div class="action-menu-divider"></div>
                                    <button type="button" class="action-menu-item action-menu-item--danger"
                                            data-action="delete"
                                            data-config-id="<?php echo htmlspecialchars($id); ?>"
                                            data-config-name="<?php echo htmlspecialchars($config['name'], ENT_QUOTES); ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                        Удалить
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="assets/js/pages/vpn.js?v=<?php echo $vpnAssetsVer; ?>"></script>
