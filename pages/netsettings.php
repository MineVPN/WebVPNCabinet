<?php
/**
 * ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
 * ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
 * ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
 * ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
 * ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
 * ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
 * ══════════════════════════════════════════════════════════════════
 *           N E T   S E T T I N G S   P A G E   F I L E
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
 * MineVPN Server — Network Settings / Страница настроек сети
 *
 * Редактирует netplan YAML для настройки WAN/LAN интерфейсов. POST handler встроен прямо
 * в этот файл (не выделяется в отдельный handler) — простые формы без multipart, double-submit не
 * критичен (юзер вручную жмёт Apply при осознанных изменениях).
 *
 * Логика выбора netplan-файла:
 *   1. Сначала ищет *.yaml/.yml в /etc/netplan/ с 'minevpn' в имени (custom config)
 *   2. Если не найден — берёт первый явленный yaml в директории
 *   3. Если вообще нет явленных yaml — рендерит error card и выходит
 *
 * Концепция WAN vs LAN в netplan:
 *   • WAN (input_interface)  — optional=false. Главное подключение к интернету.
 *                              Блокирует boot до появления линка (иначе сервер не имеет сети).
 *   • LAN (output_interface) — optional=true. Отложенный — если lan-кабель отсутствует
 *                              при boot, это НЕ ломает systemd-networkd-wait-online.
 *                              Статический IP 10.10.1.1/20, DNS на себя.
 *
 * Apply флоу:
 *   1. Читаем текущий yaml (только если валидный — yaml_parse_file)
 *   2. Удаляем старые ethernets[input_interface] и ethernets[output_interface]
 *   3. Добавляем новые определения интерфейсов (DHCP или static с IP/mask/gw/DNS)
 *   4. Записываем yaml (yaml_emit без разделителей ---/...)
 *   5. Выполняем `sudo netplan apply` без --dry-run
 *      (netplan try имеет 120с блокирующий timeout — положит PHP)
 *   6. sleep(2) чтобы NetworkManager успел применить
 *
 * Безопасность (Command Injection и валидация):
 *   • Имена интерфейсов — regex [a-zA-Z0-9_:.-]{1,20}
 *   • IP/gateway       — filter_var() FILTER_VALIDATE_IP + FLAG_IPV4
 *   • subnet mask      — диапазон 1-32 (CIDR notation)
 *   • DNS              — каждый валидируется как IPv4
 *   • connection_type  — белый список ['dhcp', 'static']
 *   • escapeshellarg() в getInterfaceLiveInfo для shell_exec
 *   • input != output  — защита от self-loop конфига
 *
 * Взаимодействует с:
 *   • cabinet.php — include этого файла при ?menu=netsettings
 *   • system — sudo netplan apply (через sudoers NOPASSWD)
 *   • system — ip -o -4 addr show, ip route show default, ip link show (для live info)
 *
 * Читает:
 *   • /etc/netplan/*.yaml + .yml — исходные настройки интерфейсов
 *   • /sys/class/net/<iface>/speed       — скорость сетевого адаптера в Mbps
 *   • /sys/class/net/<iface>/statistics/{rx,tx}_bytes — счётчики трафика
 *   • /etc/resolv.conf — текущие DNS-серверы
 *
 * Пишет:
 *   • /etc/netplan/*.yaml — обновлённые настройки (вызывает file_put_contents без атомарности —
 *                          вызов редкий, race condition практически не случается)
 *
 * Frontend assets:
 *   • assets/css/pages/netsettings.css — стили форм (custom selects, IP inputs, live info блоки)
 *   • Отдельный JS-файл НЕ используется — логика инлайн в HTML (toggle dhcp/static блоков)
 */

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

// 5.5.3 → 5.5.4: netsettings.css — компактні net-stat (padding 4→3, icon 36→32),
//                .net-metric padding 4/5→3/4, .net-metric-value text-2xl→text-xl (єдиний зменшений шрифт — найбільший на сторінці),
//                form gaps 5/4→4/3, page-header margin 6→4.
$netsettingsAssetsVer = '5.5.4';

// ── Поиск файла netplan ──────────────────────────────────────────────
$netplanDir   = '/etc/netplan/';
$yamlFilePath = null;

if (is_dir($netplanDir)) {
    $files = glob($netplanDir . '*.yaml') ?: glob($netplanDir . '*.yml') ?: [];
    if (!empty($files)) {
        foreach ($files as $file) {
            if (strpos($file, 'minevpn') !== false) { $yamlFilePath = $file; break; }
        }
        if (!$yamlFilePath) $yamlFilePath = $files[0];
    }
}

if (!$yamlFilePath || !file_exists($yamlFilePath)) {
    ?>
    <link rel="stylesheet" href="assets/css/pages/netsettings.css?v=<?php echo $netsettingsAssetsVer; ?>">
    <div class="card card--accent-rose">
        <div class="empty-state">
            <div class="empty-state-title text-rose">Файл конфигурации сети не найден</div>
            <div class="empty-state-text">
                Не удалось найти netplan-файл в директории
                <code class="mono">/etc/netplan/</code>
            </div>
        </div>
    </div>
    <?php
    return;
}

// ── Функции ──────────────────────────────────────────────────────────

function readYamlFile(string $filePath): ?array {
    if (!file_exists($filePath)) return null;
    return yaml_parse_file($filePath) ?: null;
}

function writeYamlFile(string $filePath, array $data): bool {
    $yaml = yaml_emit($data, YAML_UTF8_ENCODING);
    $yaml = preg_replace('/^---\n/', '', $yaml);
    $yaml = preg_replace('/\n\.\.\.\n?$/', '', $yaml);
    return file_put_contents($filePath, $yaml) !== false;
}

function getInterfaceLiveInfo(string $iface): array {
    $info = [
        'status' => 'down', 'ip' => '—', 'mask' => '—', 'gateway' => '—',
        'dns' => [], 'mac' => '—', 'speed' => '—',
        'rx_bytes' => 0, 'tx_bytes' => 0,
    ];
    if (empty($iface)) return $info;

    $safeIface  = escapeshellarg($iface);
    $cleanIface = preg_replace('/[^a-z0-9_.-]/i', '', $iface);

    $out = shell_exec("ip -o -4 addr show $safeIface 2>/dev/null");
    if ($out && preg_match('/inet ([\d.]+)\/(\d+)/', $out, $m)) {
        $info['ip']   = $m[1];
        $info['mask'] = $m[2];
    }

    $link = shell_exec("ip link show $safeIface 2>/dev/null");
    if ($link && preg_match('/state (\w+)/', $link, $m)) $info['status'] = strtolower($m[1]);
    if ($link && preg_match('/link\/ether ([\da-f:]+)/i', $link, $m)) $info['mac'] = $m[1];

    $speedFile = "/sys/class/net/{$cleanIface}/speed";
    $speed = @file_get_contents($speedFile);
    if ($speed !== false && is_numeric(trim($speed)) && (int)trim($speed) > 0) {
        $info['speed'] = trim($speed) . ' Mbps';
    }

    $route = shell_exec("ip route show default 2>/dev/null");
    if ($route && preg_match('/default via ([\d.]+) dev ' . preg_quote($iface, '/') . '/', $route, $m)) {
        $info['gateway'] = $m[1];
    }

    $resolv = @file_get_contents('/etc/resolv.conf');
    if ($resolv) {
        preg_match_all('/^nameserver\s+([\d.]+)/m', $resolv, $m);
        $info['dns'] = $m[1] ?? [];
    }

    $info['rx_bytes'] = (int)trim(@file_get_contents("/sys/class/net/{$cleanIface}/statistics/rx_bytes") ?: '0');
    $info['tx_bytes'] = (int)trim(@file_get_contents("/sys/class/net/{$cleanIface}/statistics/tx_bytes") ?: '0');
    return $info;
}

function formatBytes(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

// ── POST handler ──────────────────────────────
$flashMessage = '';
$flashType    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_settings'])) {
    $data = readYamlFile($yamlFilePath);

    if ($data === null) {
        $flashMessage = 'Ошибка чтения файла конфигурации';
        $flashType    = 'error';
    } else {
        $newType         = $_POST['connection_type']  ?? '';
        $inputInterface  = trim($_POST['input_interface']  ?? '');
        $outputInterface = trim($_POST['output_interface'] ?? '');

        if (!in_array($newType, ['dhcp', 'static'], true)) {
            $flashMessage = 'Недопустимый тип подключения'; $flashType = 'error';
        } elseif (!preg_match('/^[a-zA-Z0-9_:.-]{1,20}$/', $inputInterface) ||
                  !preg_match('/^[a-zA-Z0-9_:.-]{1,20}$/', $outputInterface) ||
                  $inputInterface === $outputInterface) {
            $flashMessage = 'Недопустимые интерфейсы'; $flashType = 'error';
        } elseif (!isset($data['network']['ethernets'][$inputInterface])) {
            $flashMessage = "Интерфейс {$inputInterface} не найден в конфигурации"; $flashType = 'error';
        } else {
            unset($data['network']['ethernets'][$inputInterface]);

            if ($newType === 'static') {
                $address    = trim($_POST['address']);
                $subnetMask = (int)trim($_POST['subnet_mask']);
                $gateway    = trim($_POST['gateway']);
                $dns        = array_filter(array_map('trim', explode(',', $_POST['dns'])));

                $isValidIp   = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
                $isValidGw   = filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
                $isValidMask = $subnetMask >= 1 && $subnetMask <= 32;
                $dnsValid    = true;
                foreach ($dns as $d) {
                    if (!filter_var($d, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { $dnsValid = false; break; }
                }

                if (empty($address) || empty($gateway))   { $flashMessage = 'Все поля для статической конфигурации обязательны'; $flashType = 'error'; }
                elseif (!$isValidIp)                       { $flashMessage = "Некорректный IP-адрес: $address";                   $flashType = 'error'; }
                elseif (!$isValidGw)                       { $flashMessage = "Некорректный шлюз: $gateway";                       $flashType = 'error'; }
                elseif (!$isValidMask)                     { $flashMessage = "Некорректная маска подсети (1-32): $subnetMask";    $flashType = 'error'; }
                elseif (!$dnsValid)                        { $flashMessage = 'Некорректный DNS-адрес';                             $flashType = 'error'; }
                else {
                    $data['network']['ethernets'][$inputInterface] = [
                        'dhcp4'       => false,
                        'addresses'   => ["$address/$subnetMask"],
                        'routes'      => [['to' => 'default', 'via' => $gateway]],
                        'nameservers' => ['addresses' => $dns ?: ['8.8.8.8', '8.8.4.4']],
                    ];
                }
            } else {
                $data['network']['ethernets'][$inputInterface] = ['dhcp4' => true];
            }

            if (empty($flashMessage)) {
                if (!isset($data['network']['ethernets'][$outputInterface])) {
                    $data['network']['ethernets'][$outputInterface] = [
                        'dhcp4'       => false,
                        'addresses'   => ['10.10.1.1/20'],
                        'nameservers' => ['addresses' => ['10.10.1.1']],
                        'optional'    => true,
                    ];
                }

                if (writeYamlFile($yamlFilePath, $data)) {
                    exec('sudo netplan apply 2>&1', $applyOutput, $applyReturn);
                    if ($applyReturn === 0) {
                        sleep(2);
                        $flashMessage = 'Настройки сети успешно применены';
                        $flashType    = 'success';
                    } else {
                        $flashMessage = 'Ошибка применения: ' . implode(' ', $applyOutput);
                        $flashType    = 'error';
                    }
                } else {
                    $flashMessage = 'Ошибка записи файла конфигурации';
                    $flashType    = 'error';
                }
            }
        }
    }
}

// ── Чтение текущих данных ───────────────────────────────────────────
$data = readYamlFile($yamlFilePath);
$inputInterface  = '';
$outputInterface = '';
$inputConfig     = [];
$outputConfig    = [];

if ($data && isset($data['network']['ethernets'])) {
    foreach ($data['network']['ethernets'] as $iface => $config) {
        if (!empty($config['optional']) && $config['optional'] === true) {
            $outputInterface = $iface;
            $outputConfig    = $config;
        } else {
            $inputInterface = $iface;
            $inputConfig    = $config;
        }
    }
}
if (empty($inputInterface))  $inputInterface  = 'eth0';
if (empty($outputInterface)) $outputInterface = 'eth1';

$inputType = (isset($inputConfig['dhcp4']) && $inputConfig['dhcp4']) ? 'dhcp' : 'static';
$inputAddress = '';
$inputSubnetMask = '';
$inputGateway = '';
$inputDNS = '';

if ($inputType === 'static' && !empty($inputConfig)) {
    if (isset($inputConfig['addresses'][0])) {
        $parts = explode('/', $inputConfig['addresses'][0]);
        $inputAddress    = $parts[0];
        $inputSubnetMask = $parts[1] ?? '24';
    }
    if (isset($inputConfig['routes'])) {
        foreach ($inputConfig['routes'] as $route) {
            if (isset($route['to']) && $route['to'] === 'default' && isset($route['via'])) {
                $inputGateway = $route['via'];
                break;
            }
        }
    } elseif (isset($inputConfig['gateway4'])) {
        $inputGateway = $inputConfig['gateway4'];
    }
    $inputDNS = isset($inputConfig['nameservers']['addresses'])
        ? implode(', ', $inputConfig['nameservers']['addresses'])
        : '';
}

$outputAddress    = '';
$outputSubnetMask = '';
if (isset($outputConfig['addresses'][0])) {
    $parts = explode('/', $outputConfig['addresses'][0]);
    $outputAddress    = $parts[0];
    $outputSubnetMask = $parts[1] ?? '20';
}

$wanLive = getInterfaceLiveInfo($inputInterface);
?>

<link rel="stylesheet" href="assets/css/pages/netsettings.css?v=<?php echo $netsettingsAssetsVer; ?>">

<?php if ($flashMessage): ?>
<script>
window.__flashMessage = {
    text: <?php echo json_encode($flashMessage, JSON_UNESCAPED_UNICODE); ?>,
    type: <?php echo json_encode($flashType); ?>
};
</script>
<?php endif; ?>

<div class="net-page-header">
    <h1>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/>
        </svg>
        Настройки сети
    </h1>
</div>

<!-- ════════════════════════ Current state ════════════════════════ -->
<div class="card" style="margin-bottom: var(--space-5);">
    <div class="card-header">
        <div class="card-title">
            <span class="icon-badge icon-badge--cyan">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/></svg>
            </span>
            Текущее состояние
        </div>
        <?php $okStatus = ($wanLive['status'] === 'up'); ?>
        <span class="status-pill <?php echo $okStatus ? 'status-pill--ok' : 'status-pill--err'; ?>">
            <span class="status-dot <?php echo $okStatus ? 'status-dot--ok status-dot--pulse' : 'status-dot--err'; ?>"></span>
            <?php echo $okStatus ? 'Подключено' : 'Не подключено'; ?>
        </span>
    </div>

    <!-- ─── Сетевые параметры (5 карточек) ─── -->
    <div class="net-stats-grid">
        <div class="net-stat">
            <div class="net-stat-icon net-stat-icon--cyan">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 100 4 2 2 0 000-4zm14 0a2 2 0 110 4 2 2 0 010-4zm-7-9v18"/></svg>
            </div>
            <span class="net-stat-label">Интерфейс</span>
            <span class="net-stat-value mono"><?php echo htmlspecialchars($inputInterface); ?></span>
        </div>

        <div class="net-stat">
            <div class="net-stat-icon net-stat-icon--emerald">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 002 2 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <span class="net-stat-label">IP-адрес</span>
            <span class="net-stat-value mono"><?php echo htmlspecialchars($wanLive['ip']); ?><?php echo $wanLive['mask'] !== '—' ? '<span class="net-stat-suffix">/' . htmlspecialchars($wanLive['mask']) . '</span>' : ''; ?></span>
        </div>

        <div class="net-stat">
            <div class="net-stat-icon net-stat-icon--violet">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            </div>
            <span class="net-stat-label">Шлюз</span>
            <span class="net-stat-value mono"><?php echo htmlspecialchars($wanLive['gateway']); ?></span>
        </div>

        <div class="net-stat">
            <div class="net-stat-icon net-stat-icon--amber">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 002 2 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <span class="net-stat-label">DNS</span>
            <span class="net-stat-value mono"><?php echo !empty($wanLive['dns']) ? htmlspecialchars(implode(', ', $wanLive['dns'])) : '—'; ?></span>
        </div>

        <div class="net-stat">
            <div class="net-stat-icon net-stat-icon--slate">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </div>
            <span class="net-stat-label">MAC-адрес</span>
            <span class="net-stat-value mono"><?php echo htmlspecialchars($wanLive['mac']); ?></span>
        </div>
    </div>

    <!-- ─── Метрики (швидкість + трафік) ─── -->
    <div class="net-metrics-grid">
        <div class="net-metric">
            <div class="net-metric-head">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span>Скорость</span>
            </div>
            <span class="net-metric-value"><?php echo htmlspecialchars($wanLive['speed']); ?></span>
        </div>

        <div class="net-metric net-metric--rx">
            <div class="net-metric-head">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                <span>Получено</span>
            </div>
            <span class="net-metric-value"><?php echo formatBytes($wanLive['rx_bytes']); ?></span>
        </div>

        <div class="net-metric net-metric--tx">
            <div class="net-metric-head">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
                <span>Отправлено</span>
            </div>
            <span class="net-metric-value"><?php echo formatBytes($wanLive['tx_bytes']); ?></span>
        </div>
    </div>

    <div class="net-mode-note">
        <span class="text-xs text-muted">
            Режим: <span class="text-secondary"><?php echo $inputType === 'dhcp' ? 'DHCP (автоматически)' : 'Статический IP'; ?></span>
        </span>
    </div>
</div>

<!-- ════════════════════════ WAN form ════════════════════════ -->
<form method="post" class="net-form">

    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span class="icon-badge icon-badge--violet">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/></svg>
                </span>
                Входящее подключение (WAN)
            </div>
            <span class="net-iface-pill"><?php echo htmlspecialchars($inputInterface); ?></span>
        </div>

        <input type="hidden" name="input_interface"  value="<?php echo htmlspecialchars($inputInterface); ?>">
        <input type="hidden" name="output_interface" value="<?php echo htmlspecialchars($outputInterface); ?>">

        <div class="net-form-fields">

            <div class="net-row">
                <label for="connection_type" class="net-row-label">Тип подключения</label>
                <select name="connection_type" id="connection_type" class="select" onchange="toggleStaticFields()">
                    <option value="dhcp"   <?php echo $inputType === 'dhcp'   ? 'selected' : ''; ?>>DHCP (автоматически)</option>
                    <option value="static" <?php echo $inputType === 'static' ? 'selected' : ''; ?>>Статический IP</option>
                </select>
            </div>

            <div id="staticFields" class="net-static-fields" style="display: <?php echo $inputType === 'static' ? 'flex' : 'none'; ?>;">

                <div class="net-row">
                    <label for="address" class="net-row-label">IP-адрес</label>
                    <input type="text" id="address" name="address" class="input mono"
                           value="<?php echo htmlspecialchars($inputAddress); ?>"
                           placeholder="192.168.1.100">
                </div>

                <div class="net-row">
                    <label for="subnet_mask" class="net-row-label">Маска подсети</label>
                    <input type="number" min="1" max="32" id="subnet_mask" name="subnet_mask" class="input mono"
                           value="<?php echo htmlspecialchars($inputSubnetMask); ?>" placeholder="24">
                </div>

                <div class="net-row">
                    <label for="gateway" class="net-row-label">Шлюз</label>
                    <input type="text" id="gateway" name="gateway" class="input mono"
                           value="<?php echo htmlspecialchars($inputGateway); ?>"
                           placeholder="192.168.1.1">
                </div>

                <div class="net-row">
                    <label for="dns" class="net-row-label">DNS (через запятую)</label>
                    <input type="text" id="dns" name="dns" class="input mono"
                           value="<?php echo htmlspecialchars($inputDNS); ?>"
                           placeholder="8.8.8.8, 8.8.4.4">
                </div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════ LAN info ════════════════════════ -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span class="icon-badge icon-badge--emerald">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </span>
                Локальная сеть (LAN)
            </div>
            <span class="net-iface-pill"><?php echo htmlspecialchars($outputInterface); ?></span>
        </div>

        <div class="net-form-fields">
            <div class="net-row">
                <span class="net-row-label">IP-адрес шлюза</span>
                <span class="net-readonly-value"><?php echo htmlspecialchars($outputAddress ?: '10.10.1.1'); ?></span>
            </div>
            <div class="net-row">
                <span class="net-row-label">Маска подсети</span>
                <span class="net-readonly-value">/<?php echo htmlspecialchars($outputSubnetMask ?: '20'); ?> (255.255.240.0)</span>
            </div>
            <div class="net-row">
                <span class="net-row-label">Диапазон DHCP</span>
                <span class="net-readonly-value">10.10.1.2 — 10.10.15.254</span>
            </div>
        </div>
    </div>

    <div class="net-submit-row">
        <button type="submit" name="apply_settings" value="1" class="btn btn--primary btn--lg">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" width="16" height="16">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
            Применить настройки
        </button>
    </div>
</form>

<script>
function toggleStaticFields() {
    const type = document.getElementById('connection_type').value;
    const fields = document.getElementById('staticFields');
    fields.style.display = (type === 'static') ? 'flex' : 'none';
    fields.querySelectorAll('input').forEach(i => { i.required = (type === 'static'); });
}
document.addEventListener('DOMContentLoaded', toggleStaticFields);
</script>