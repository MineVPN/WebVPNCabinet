# MineVPN — Web Cabinet

Веб-панель управления VPN-сервером MineVPN. Этот репозиторий содержит сам интерфейс — PHP, JavaScript, CSS — а также скрипты обновления (`update.sh`) и Health Check daemon (`vpn-healthcheck.sh`).

> ⚠️ **Не клонируйте этот репозиторий вручную для установки.**
> Используйте [установщик из MineVPN/VPN](https://github.com/MineVPN/VPN) — он подготовит систему (Apache, PHP, WireGuard, OpenVPN, dnsmasq, iptables, shellinabox) и сам клонирует этот репо в `/var/www/html/`.

<p align="center">
  <img src="https://raw.githubusercontent.com/MineVPN/VPN/main/docs/img/banner.png" alt="MineVPN Banner" width="800">
</p>

---

## Содержание

- [Что это](#что-это)
- [Скриншоты](#скриншоты)
- [Возможности](#возможности)
- [Установка для пользователей](#установка-для-пользователей)
- [Структура репозитория](#структура-репозитория)
- [Архитектура](#архитектура)
- [Конвенции и паттерны](#конвенции-и-паттерны)
- [Безопасность](#безопасность)
- [Cache-busting](#cache-busting)
- [Миграции (update.sh)](#миграции-updatesh)
- [Health Check daemon](#health-check-daemon)
- [Разработка](#разработка)
- [Как добавить новую страницу](#как-добавить-новую-страницу)
- [Как добавить новый API endpoint](#как-добавить-новый-api-endpoint)
- [Тестирование](#тестирование)
- [Отладка](#отладка)
- [Лицензия](#лицензия)

---

## Что это

**MineVPN Web Cabinet** — современная веб-панель для управления VPN-роутером на Ubuntu. Загрузка нескольких конфигов WireGuard/OpenVPN, переключение между ними, автоматический failover при падении, Kill Switch, мониторинг в реальном времени, веб-терминал.

Стек: **PHP 8.x + ванильный JS + CSS-tokens** (без фреймворков, без сборки, без npm). Запускается как обычный Apache + mod_php сайт. Сборка не требуется — запушил в main, через cron auto-update попадает в production.

**Бэкенд-логика** распределена между:
- **PHP** — UI, валидация, права, чтение/запись конфигов
- **bash** (`vpn-healthcheck.sh`) — мониторинг VPN, failover, Kill Switch self-healing
- **systemd** — управление туннелями (`wg-quick@tun0`, `openvpn@tun0`)
- **iptables** — Kill Switch, NAT, rate-limit
- **dnsmasq** — DHCP/DNS для LAN

---

## Скриншоты

### Главная страница — Обзор / Dashboard

<!-- screenshot: dashboard.png — главная "Обзор" с метриками, активным VPN, последними событиями -->
<p align="center">
  <img src="https://raw.githubusercontent.com/MineVPN/VPN/main/docs/img/dashboard.png" alt="Dashboard" width="800">
</p>

CPU / RAM / Disk / Network в реальном времени. Активный VPN, текущий внешний IP, последние события (подключения, отключения, failover). Polling каждые 2 секунды.

---

### VPN Manager

<!-- screenshot: vpn-manager.png — VPN Manager с несколькими конфигами в drag-drop списке -->
<p align="center">
  <img src="https://raw.githubusercontent.com/MineVPN/VPN/main/docs/img/vpn-manager.png" alt="VPN Manager" width="800">
</p>

Несколько конфигов одновременно с приоритетами. Drag-and-drop сортировка, inline-переименование (двойной клик), массовое удаление, поддержка WireGuard и OpenVPN. Auto-rollback на предыдущий конфиг если новый не поднялся за 15 секунд.

---

### Настройки

<!-- screenshot: settings.png — страница Настройки с тогглами Kill Switch, Failover, Auto-restart -->
<p align="center">
  <img src="https://raw.githubusercontent.com/MineVPN/VPN/main/docs/img/settings.png" alt="Settings" width="800">
</p>

Toggle-cards для Kill Switch, Failover (мягкий/агрессивный), Auto-restart, VPN Checker. 2-колоночный layout с L-shape connectors между связанными настройками. Управление сервером (reboot/poweroff) с подтверждением.

---

### Сетевые настройки

<!-- screenshot: netsettings.png — страница netsettings.php с полями WAN/LAN, DHCP/Static IP -->
<p align="center">
  <img src="https://raw.githubusercontent.com/MineVPN/VPN/main/docs/img/netsettings.png" alt="Network Settings" width="800">
</p>

WAN: DHCP или Static IP с маской / шлюзом / DNS. LAN — фиксированный 10.10.1.1/20. Применение через `netplan try` с автоматическим rollback при потере связи (90 сек).

---

### Веб-терминал

<!-- screenshot: console.png — веб-терминал shellinabox с тёмной темой -->
<p align="center">
  <img src="https://raw.githubusercontent.com/MineVPN/VPN/main/docs/img/console.png" alt="Web Console" width="800">
</p>

Полноценный shell в браузере через **shellinabox** в iframe. Кастомная тёмная тема в фирменных цветах MineVPN. Доступен через `/shell/` (Apache mod_proxy → 127.0.0.1:4200, наружу не торчит).

---

### Ping-инструмент

<!-- screenshot: pinger.png — страница Ping с вводом адреса и результатами -->
<p align="center">
  <img src="https://raw.githubusercontent.com/MineVPN/VPN/main/docs/img/pinger.png" alt="Pinger" width="800">
</p>

Быстрая проверка доступности хоста. Можно пинговать через VPN-туннель или напрямую через WAN — для диагностики и сравнения маршрутов.

---

### Установщик

<!-- screenshot: installer1.png — терминал во время установки: выбор интерфейсов WAN/LAN -->
<p align="center">
  <img src="https://raw.githubusercontent.com/MineVPN/VPN/main/docs/img/installer1.png" alt="Installer Step 1" width="800">
</p>

<!-- screenshot: installer2.png — терминал, шаг конфигурации сети (DHCP/Static, IP/маска/шлюз) -->
<p align="center">
  <img src="https://raw.githubusercontent.com/MineVPN/VPN/main/docs/img/installer2.png" alt="Installer Step 2" width="800">
</p>

ASCII-логотип, цветной вывод, интерактивные prompt'ы, автоматическая обработка ошибок APT, полный лог в `/var/log/minevpn/install.log`.

---

## Возможности

### 🎯 VPN Manager
Несколько конфигов одновременно с приоритетами. Поддержка **WireGuard** и **OpenVPN** в одной панели. Drag-and-drop сортировка, inline-переименование, bulk-удаление. Auto-rollback на предыдущий конфиг если новый не поднялся за 15 секунд.

### 🔄 Failover
Автоматическое переключение на резервный конфиг при падении основного. Два режима — **мягкий** (сначала рестарт активного, потом switch) и **агрессивный** (сразу switch при первом сбое). Возврат на primary при восстановлении.

### 🛡 Kill Switch
LAN-трафик блокируется при падении VPN через `iptables FORWARD REJECT`. Реальный IP не утечёт. Self-healing — Health Check daemon восстанавливает правила если их кто-то сбросил.

### ❤️ Health Check Daemon
Долгоживущий процесс (`Type=simple`) с реакцией на падения за ~5 секунд. ISP-down detection (не пытается перезапускать VPN если упал провайдер). Warmup phase 120 секунд после reboot для избежания false-positive.

### 💻 Веб-терминал
Полноценный shell в браузере через shellinabox. Тёмная тема в фирменных цветах. Не нужен отдельный SSH-клиент.

### 🌐 Сетевые настройки
WAN/LAN через UI без правки netplan. DHCP/Static, DNS, шлюз. Локальный DHCP/DNS через dnsmasq. VOIP-оптимизации (conntrack tuning, SIP ALG отключён, DHCP lease 72 часа).

### 📊 Дашборд и события
CPU/RAM/Disk/Network в реальном времени. График пропускной способности. Журнал всех событий VPN — подключения, отключения, failover, восстановления. История сбоев интернета.

### 🔒 Безопасность
- Авторизация по root-паролю через PAM
- Rate-limit HTTP (60/мин) и SSH (10/мин) через iptables — **только для WAN**, LAN whitelisted
- Сессии привязаны к IP, авто-выход через 30 минут неактивности
- Sudoers ограничен только нужными командами
- Brute-force защита логина (5 попыток / 5 минут lockout)

### ♻️ Авто-обновление с миграциями
Каждый день в 04:00 — `git pull` + `update.sh` с инкрементальными миграциями (v0→v1→v2→v3→v4→v5). Sanity-check блоки самовосстанавливают типовые проблемы.

### 📈 Масштабирование
Дефолты на ~100 устройств. При установке автоматически тюнятся для **400+**: dnsmasq лимиты (dns-forward-max=8192, cache-size=50000), conntrack max=524288, soft-PAM faillock.

---

## Установка для пользователей

См. [MineVPN/VPN](https://github.com/MineVPN/VPN) — там основной README с командой установки одной строкой и подробным описанием:

```bash
curl -O https://raw.githubusercontent.com/MineVPN/VPN/main/MineVPN-Installer.sh && sudo bash MineVPN-Installer.sh
```

Этот репозиторий — только код панели. Он клонируется установщиком в `/var/www/html/`.

---

## Структура репозитория

```
/
├── cabinet.php                  Главная оболочка панели (sidebar + контент)
├── index.php                    Точка входа (роутинг auth/login)
├── login.php                    Авторизация по root-паролю (PAM)
├── logout.php                   Выход + регенерация Session ID
├── .htaccess                    Apache rules (gzip, кеш, security headers)
├── version                      → /var/www/version (символьная ссылка)
│
├── api/                         JSON endpoints для AJAX
│   ├── vpn_action.php             Все действия над конфигами:
│   │                              activate, delete, rename, upload, reorder, set_role
│   ├── stats_api.php              Метрики (CPU/RAM/Disk/Network/события)
│   │                              Live-poll каждые 2 сек
│   ├── status_check.php           Состояние VPN (для polling в навбаре)
│   ├── ping.php                   Ping-инструмент
│   ├── system_action.php          reboot/poweroff сервера (через sudoers)
│   └── update_action.php          Запуск update.sh из веб-панели с прогрессом
│
├── pages/                       Содержимое страниц (включаются в cabinet.php)
│   ├── stats.php                  Обзор / дашборд (главная)
│   ├── vpn-manager.php            VPN Manager — список конфигов + загрузка
│   ├── vpn-manager.handler.php    PRG-обработчик загрузки .conf файлов
│   ├── settings.php               Настройки панели (toggle-cards)
│   ├── netsettings.php            Сетевые настройки (WAN/LAN, netplan)
│   ├── console.php                Веб-терминал (iframe → shellinabox)
│   ├── pinger.php                 Ping-инструмент (UI)
│   └── about.php                  О панели
│
├── includes/
│   ├── vpn_helpers.php            Shared library — все mv_* функции:
│   │                              loadConfigs, saveConfigs, readState, logEvent,
│   │                              getActiveConfig, isValidConfigId, ...
│   └── auth_check.php             Проверка авторизации для всех страниц
│
├── assets/
│   ├── css/
│   │   ├── tokens.css             CSS-переменные (цвета, размеры, тени)
│   │   ├── components.css         Кнопки, карточки, модалки, тогглы
│   │   ├── layout.css             Sidebar, header, основная сетка
│   │   ├── pages/                 Page-specific стили (vpn-manager.css, ...)
│   │   └── shellinabox-theme.css  Тёмная тема для веб-терминала
│   ├── js/
│   │   ├── app.js                 Главный JS с window.MineVPN namespace
│   │   ├── lib/
│   │   │   ├── toast.js           Toast-уведомления
│   │   │   ├── progress.js        Прогресс-бары для долгих операций
│   │   │   └── confirm.js         Модалки подтверждения
│   │   └── pages/                 Page-specific JS (vpn-manager.js, ...)
│   └── img/                       Логотипы, favicon, графика
│
├── vpn-healthcheck.sh           Health Check daemon (systemd Type=simple)
└── update.sh                    Скрипт миграций v0 → v5 (запускается через cron)
```

---

## Архитектура

### Стек

```
┌─────────────────────────────────────────────────────────────┐
│  Browser                                                    │
│  ├── HTML5 + ванильный JS (без фреймворков)                 │
│  ├── CSS-tokens + components (без preprocessor'ов)          │
│  └── Polling: 2 сек (live data) + 5 сек (events/history)    │
├─────────────────────────────────────────────────────────────┤
│  Apache 2 + mod_php (Ubuntu 22.04)                          │
│  ├── PHP 8.x — UI rendering, валидация, права               │
│  ├── mod_rewrite, mod_headers, mod_expires, mod_deflate     │
│  ├── mod_proxy → 127.0.0.1:4200 (shellinabox)               │
│  └── ServerTokens Prod (нет утечки версий)                  │
├─────────────────────────────────────────────────────────────┤
│  Bash + systemd                                             │
│  ├── vpn-healthcheck.sh (HC daemon, Type=simple)            │
│  ├── wg-quick@tun0 / openvpn@tun0 (туннели)                 │
│  └── /usr/local/bin/minevpn-update.sh (cron 04:00)          │
├─────────────────────────────────────────────────────────────┤
│  Storage                                                    │
│  ├── /var/www/vpn-configs/configs.json    Metadata          │
│  ├── /var/www/vpn-configs/*.conf          Конфиги (660)     │
│  ├── /var/www/minevpn-state               Runtime state     │
│  ├── /var/www/settings                    Настройки панели  │
│  ├── /etc/minevpn.conf                    WAN/LAN/VERSION   │
│  └── /var/log/minevpn/{vpn,events,update,install}.log       │
└─────────────────────────────────────────────────────────────┘
```

### State machine

| Файл | Назначение | Формат | Кто пишет |
|---|---|---|---|
| `/var/www/minevpn-state` | Runtime state | `key=value` | PHP + HC daemon |
| `/var/www/vpn-configs/configs.json` | Metadata конфигов | JSON | PHP только (через flock) |
| `/var/www/vpn-configs/*.conf` | Файлы конфигов | WG/OVPN | PHP при upload |
| `/var/www/settings` | Настройки панели | `key=value` | PHP + update.sh |
| `/etc/minevpn.conf` | Статика (WAN, LAN, VERSION) | `KEY=VALUE` | Installer + update.sh |
| `/var/log/minevpn/events.log` | Журнал событий | `TIME|TYPE|F1|F2|F3` | HC daemon + PHP |
| `/var/log/minevpn/vpn.log` | Live-лог HC daemon | Текст | HC daemon |

### Ключи в `minevpn-state`

```
STATE=active|inactive|failover|warmup|stopped
ACTIVE_ID=vpn_<16hex>      # текущий активный конфиг
PRIMARY_ID=vpn_<16hex>     # primary (приоритет 1)
ACTIVATED_BY=auto|user|failover|recovery
LAST_CHECK_TS=<unix>
LAST_FAILOVER_TS=<unix>
```

### Ключи в `settings`

```
vpnchecker=true|false      # Включён ли HC daemon мониторинг
autoupvpn=true|false       # Авто-рестарт при падении (без failover)
failover=true|false        # Переключение на резервный конфиг
failover_first=true|false  # Агрессивный режим (true) или мягкий (false)
```

### Поток данных при активации конфига

```
User clicks "Activate"
   ↓
JS: api/vpn_action.php?action=activate&id=vpn_xxx
   ↓
PHP:
   1. Validate ID through mv_isValidConfigId()
   2. flock configs.json
   3. Update state: ACTIVE_ID=vpn_xxx, ACTIVATED_BY=user
   4. shell_exec("sudo systemctl restart wg-quick@tun0")
   5. Wait up to 15s for tun0 with IP
   6. mv_logEvent("activate", "vpn_xxx")
   7. JSON response: { ok: true }
   ↓
HC daemon (parallel):
   - Detects ACTIVATED_BY=user → respects, doesn't override
   - Sees STATE=active → starts monitoring with 5s ping
```

---

## Конвенции и паттерны

### PRG (Post-Redirect-Get)

Формы с `multipart/form-data` (загрузка `.conf` файлов) идут через handler:

```
POST → vpn-manager.handler.php → 302 redirect → GET → vpn-manager.php
```

Это бьёт дублирование при F5/Ctrl+R на странице после загрузки файла.

### AJAX без перезагрузки

Все остальные действия (activate, rename, delete, reorder, toggle role, settings) идут через `api/vpn_action.php` или `api/stats_api.php` без перезагрузки. Polling:
- 2 секунды — live данные (CPU/RAM, текущий VPN)
- 5 секунд — события и история

### Префикс `mv_` для всех shared-функций

Все функции в `includes/vpn_helpers.php` называются `mv_<name>` — это позволяет искать их по всему проекту через `grep -r "mv_"` без false-positive с PHP/JS встроенными функциями:

```php
mv_loadConfigs()           // Прочитать configs.json
mv_saveConfigs($configs)   // Записать с flock
mv_readState()             // Прочитать /var/www/minevpn-state
mv_writeState($state)
mv_getActiveConfig()       // Текущий активный конфиг
mv_isValidConfigId($id)    // Валидация vpn_[a-f0-9]{16}
mv_logEvent($type, $f1, $f2, $f3)
mv_pollVpnUp($timeout)     // Ждать поднятия tun0
mv_getCurrentVpnConfig()   // Какой конфиг сейчас активен
```

### `window.MineVPN` namespace

В `app.js` все JS-функции и состояние живут под одним глобальным namespace:

```javascript
window.MineVPN = {
    confirm: (...) => {...},   // из confirm.js
    toast: { success, error, info, warn },
    progress: { start, end },
    fetchJSON: (url, opts) => {...},
    pollState: () => {...},
    ...
}
```

Расширение через `Object.assign(window.MineVPN, { ... })` — **не пишем `window.MineVPN.confirm = ...`** напрямую, чтобы избежать race conditions при параллельной загрузке JS.

### Атомарные записи через flock

Все state-changing операции на `configs.json`, `minevpn-state`, `settings`:

```php
$fp = fopen($file, 'c+');
if (flock($fp, LOCK_EX)) {
    // ... read, modify, write
    flock($fp, LOCK_UN);
}
fclose($fp);
```

Для `events.log` — отдельный `.lock` файл (чтобы не блокировать читающие операции на сам log).

### Hierarchical settings

Связанные настройки в UI визуально привязаны через L-shape connectors:

```
[ ] VPN Checker          (enables monitoring)
    ├─ [ ] Auto-restart  (требует VPN Checker)
    └─ [ ] Failover      (требует VPN Checker)
        └─ [ ] Aggressive (требует Failover)
```

Если родитель выключен — дети disabled и не учитываются в логике HC daemon.

---

## Безопасность

### Path traversal

Все ID конфигов валидируются регуляркой:

```php
function mv_isValidConfigId($id) {
    return is_string($id) && preg_match('/^vpn_[a-f0-9]{16}$/', $id) === 1;
}
```

ID никогда не приходит напрямую из юзер-инпута — он генерируется на сервере через `bin2hex(random_bytes(8))`. Юзер может только передать ID существующего конфига.

### Shell escape

Везде где есть `shell_exec`:

```php
$id = mv_isValidConfigId($_POST['id']) ? $_POST['id'] : null;
$cmd = "sudo systemctl restart wg-quick@" . escapeshellarg($id);
shell_exec($cmd);
```

### CSRF

Все state-changing запросы проверяют:

```php
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    exit('Forbidden');
}
```

Это блокирует cross-site form-submit атаки (атакующий не может выставить `X-Requested-With` без CORS).

### Brute-force на login

```php
$counter_file = "/tmp/login_attempts_" . md5($_SERVER['REMOTE_ADDR']);
// flock + read + check < 5 попыток за 5 минут + write
```

После 5 неудачных попыток с одного IP — блокировка на 5 минут. Counter в `/tmp/` (сбрасывается при reboot, что приемлемо для VPN-роутера).

### Sudoers — точечно

```
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop wg-quick@tun0, ...
www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan try, /usr/sbin/netplan apply
www-data ALL=(ALL) NOPASSWD: /bin/systemctl reboot, /bin/systemctl poweroff
```

**Никакого** `ALL=(ALL) NOPASSWD: ALL`. Каждое правило — конкретная команда.

### Авторизация через PAM

Не своя БД с паролями — `pam_unix` через `pam_authenticate()`. Логин = пароль root системы. Если меняете root-пароль через `passwd` — он сразу работает в панели без перезапуска.

---

## Cache-busting

Каждая страница имеет свой `$<page>AssetsVer`:

```php
// pages/stats.php
$statsAssetsVer = '5.5.15';
```

При изменении `assets/css/pages/stats.css` или `assets/js/pages/stats.js` поднимаем номер. В HTML:

```html
<link rel="stylesheet" href="/assets/css/pages/stats.css?v=<?= $statsAssetsVer ?>">
```

Это **per-page** cache-busting — не нужно ломать кеш всем юзерам когда меняется одна страница.

Глобальный `$cssVer` в `cabinet.php` для shared assets (header, sidebar, components):

```php
$cssVer = '5.6.5';
```

### Текущие версии (для синхронизации при PR)

| Файл | Переменная | Текущий |
|---|---|---|
| cabinet.php | `$cssVer` | 5.6.5 |
| pages/vpn-manager.php | `$vpnAssetsVer` | 5.5.8 |
| pages/stats.php | `$statsAssetsVer` | 5.5.15 |
| pages/console.php | `$consoleAssetsVer` | 5.5.5 |
| pages/pinger.php | `$pingerAssetsVer` | 5.5.2 |
| pages/netsettings.php | `$netsettingsAssetsVer` | 5.5.4 |

---

## Миграции (update.sh)

`update.sh` — главный скрипт обновления. Запускается через cron `0 4 * * *` или вручную через `sudo /usr/local/bin/minevpn-update.sh`.

### Архитектура

```bash
CURRENT_VERSION=$(cat /var/www/version)
TARGET_VERSION=5

# Версионные блоки (выполняются один раз на каждой версии)
if [ "$CURRENT_VERSION" -lt 1 ]; then
    # ... v1 миграции
fi

if [ "$CURRENT_VERSION" -lt 2 ]; then
    # ... v2 миграции
fi

# ... и так далее до v5

# Sanity-check блоки (выполняются КАЖДЫЙ раз)
# - проверка прав
# - валидация /etc/minevpn.conf
# - восстановление Kill Switch
# - sync HC daemon при изменении md5
```

### Что делают v5 миграции

Полный список изменений v4 → v5 (см. [CHANGELOG.md](CHANGELOG.md)):

- Sudoers cleanup (`/bin/bash` удалён, добавлены reboot/poweroff)
- Создание директории `/var/www/vpn-configs/` с правами 770 root:www-data
- Миграция legacy конфигов в новый формат с `configs.json`
- Kill Switch FORWARD chain + NAT MASQUERADE
- HC daemon Type=simple (вместо oneshot из v4)
- Apache модули (headers, expires, rewrite, deflate, proxy, proxy_http)
- AllowOverride All, ServerTokens Prod
- Установка shellinabox + `/shell/` proxy
- Создание `/etc/minevpn.conf` (WAN, LAN, VERSION)
- VOIP-оптимизации: conntrack max=524288, UDP timeouts, SIP ALG отключён
- DHCP lease 12h → 72h
- dnsmasq лимиты для 400+ устройств: dns-forward-max=8192, dhcp-lease-max=2000, cache-size=50000
- INPUT chain rate-limit (HTTP 60/мин, SSH 10/мин) + LAN whitelist
- PAM faillock soft-режим (deny=30, unlock=60s)
- PHP `mbstring` extension
- Cron launcher: `run-update.sh` → `minevpn-update.sh` с `SKIP_GIT=1`

### Sanity-check блоки

Запускаются при каждом запуске `update.sh`, гарантируют корректность системы:

| Блок | Что проверяет / чинит |
|---|---|
| Permissions | `/var/www/settings` 666, `vpn-healthcheck.sh` 755 root:root |
| `/etc/minevpn.conf` validation | Если WAN/LAN не существуют — перегенерация через `ip` команды |
| Kill Switch revalidation | Если правил нет в iptables — восстановление |
| HC daemon sync | Если md5 `vpn-healthcheck.sh` изменился — `systemctl restart` |
| Sudoers | `visudo -c` валидация, добавление недостающих правил |
| PHP extensions | Установка недостающих (yaml, mbstring) |
| Cron launcher | Замена legacy `run-update.sh` на `minevpn-update.sh` |
| Legacy files cleanup | Удаление залегших файлов из v3/v4 |

### FD 3/4 tee logging

Все скрипты (`update.sh`, `MineVPN-Installer.sh`) используют **FD 3/4 архитектуру** для разделения юзер-сообщений и шума:

```
FD 1 (stdout) → log file              (apt/git/systemctl шум)
FD 2 (stderr) → log file              (errors)
FD 7          → original stdout (terminal с цветами)
FD 8          → original stderr (terminal)
FD 3 → tee → FD 7 (terminal w/ ANSI) + sed strip ANSI → log file
FD 4 → tee → FD 8 (terminal stderr)  + sed strip ANSI → log file
```

`log_info`, `log_warn`, `log_step` пишут в FD 3 — попадают и в терминал (с цветами) и в лог (без ANSI). Шум от `apt`/`git`/`systemctl` — только в лог.

---

## Health Check daemon

`vpn-healthcheck.sh` — это **бесконечный цикл с `sleep 5`**, запущенный через systemd `Type=simple` (с `Restart=always`). Это не cron-таймер, а постоянно живущий процесс.

### Главный цикл

```bash
while true; do
    # 1. Проверить наличие tun0 и IP на нём
    # 2. Прочитать settings и state
    # 3. ping через tun0 → ok | timeout
    # 4. Если ping не прошёл:
    #    a. Проверить ISP — может это провайдер упал?
    #    b. Если ISP жив → VPN упал → restart/failover
    #    c. Если ISP мёртв → ждать пока поднимется
    # 5. Проверить iptables Kill Switch правила
    # 6. Записать состояние в /var/log/minevpn/vpn.log
    # 7. sleep 5
done
```

### Состояния (STATE)

```
warmup       → 0-120 секунд после старта daemon (после reboot)
active       → VPN работает, ping ОК
inactive     → VPN не работает, попытки восстановить
failover     → переключились на резервный конфиг
stopped      → юзер выключил VPN явно
```

### События (events.log)

Формат: `TIMESTAMP|TYPE|FIELD1|FIELD2|FIELD3`

```
2026-04-27 10:23:15|auto_start|vpn_3b730ecb79252377
2026-04-27 11:45:33|vpn_down|vpn_3b730ecb79252377|ping timeout
2026-04-27 11:45:38|recovery_attempt|vpn_3b730ecb79252377
2026-04-27 11:45:55|recovery_succeeded|vpn_3b730ecb79252377
2026-04-27 14:12:01|isp_down|gateway_unreachable
2026-04-27 14:14:55|isp_restored
2026-04-27 18:30:22|failover|vpn_3b730ecb→vpn_0e0a2b70
2026-04-27 19:15:44|failover_back|vpn_0e0a2b70→vpn_3b730ecb
```

Каждое событие имеет **закрывающее**:

| Открывающее | Закрывающее |
|---|---|
| `vpn_down` | `recovery_succeeded` или `failover` или `firewall_restored` |
| `isp_down` | `isp_restored` |
| `recovery_attempt` | `recovery_succeeded` или `recovery_failed` |
| `failover` | `failover_back` (когда primary восстанавливается) |

Это позволяет в UI рисовать корректные timeline'ы и считать downtime.

### Hash-based reload

При запуске daemon вычисляет md5 от собственного скрипта. Каждые ~60 секунд — пересчитывает. Если хеш изменился (update.sh подложил новый скрипт) — daemon **корректно завершается**, systemd его сразу перезапускает с новым кодом.

Это позволяет обновлять HC daemon без `systemctl restart` извне.

### BOOTED_RECENTLY

```bash
if [ "$(uptime_seconds)" -lt 90 ]; then
    BOOTED_RECENTLY=1
fi
```

Если сервер только что загрузился — daemon **всегда** сам стартует VPN (`auto_start` event), даже если в state стоит `ACTIVATED_BY=user`. Это потому что после reboot мы не знаем, хочет ли юзер VPN или нет — стартуем по умолчанию.

Если же daemon перезапустился после live-update (uptime > 90s) — наоборот, **уважает** `ACTIVATED_BY` и не лезет.

---

## Разработка

### Окружение

Локальная разработка не требуется — панель работает только на установленном MineVPN-сервере (нужны WireGuard, OpenVPN, dnsmasq, iptables и т.д., которые не сэмулируешь в Docker без headache).

### Workflow

1. Установить v5 на тестовый сервер через [MineVPN/VPN установщик](https://github.com/MineVPN/VPN)
2. SSH или веб-терминал → `cd /var/www/html`
3. Это **и есть** git working copy этого репозитория
4. Делать изменения, тестировать в браузере
5. Коммитить через `git add . && git commit -m '...'`
6. Push в свой fork или feature-ветку
7. PR в `main`

После merge в `main` все production-серверы подтянут изменения через cron в 04:00 (или вручную через `sudo /usr/local/bin/minevpn-update.sh`).

### Ветки

- `main` — стабильная версия, на которую тянет cron auto-update
- `dev` — разработка следующей версии
- `feature/*` — отдельные фичи в работе

### Настройка git на тестовом сервере

```bash
cd /var/www/html
git config user.name "Your Name"
git config user.email "you@example.com"
# SSH-ключ для push в GitHub:
ssh-keygen -t ed25519 -C "you@example.com"
cat ~/.ssh/id_ed25519.pub  # → добавить в GitHub Settings → SSH keys
git remote set-url origin git@github.com:MineVPN/WebVPNCabinet.git
```

---

## Как добавить новую страницу

Допустим хотим добавить страницу `Backups` для бэкапа конфигов.

### 1. Создать `pages/backups.php`

```php
<?php
require_once __DIR__ . '/../includes/auth_check.php';
$backupsAssetsVer = '5.0.0';
?>
<div class="page-backups">
    <h1>Резервное копирование</h1>
    <!-- ... контент страницы ... -->
</div>

<link rel="stylesheet" href="/assets/css/pages/backups.css?v=<?= $backupsAssetsVer ?>">
<script src="/assets/js/pages/backups.js?v=<?= $backupsAssetsVer ?>" defer></script>
```

### 2. Создать стили `assets/css/pages/backups.css`

```css
.page-backups {
    /* Используй CSS-tokens из tokens.css */
    color: var(--text-primary);
    background: var(--bg-card);
    /* ... */
}
```

### 3. Создать JS `assets/js/pages/backups.js`

```javascript
(function() {
    'use strict';
    // Используй window.MineVPN namespace
    const { fetchJSON, toast } = window.MineVPN;

    document.addEventListener('DOMContentLoaded', function() {
        // ... инициализация страницы
    });
})();
```

### 4. Добавить ссылку в sidebar (`cabinet.php`)

Найти блок навигации, добавить:

```php
<a href="?page=backups" class="<?= $page === 'backups' ? 'active' : '' ?>">
    <span class="icon">💾</span>
    Резервы
</a>
```

### 5. Добавить page-роутинг в `cabinet.php`

```php
$allowed_pages = ['stats', 'vpn-manager', 'settings', 'netsettings',
                  'console', 'pinger', 'about', 'backups'];
```

### 6. (Опционально) API endpoint в `api/backups_action.php`

```php
<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/vpn_helpers.php';

header('Content-Type: application/json');

// CSRF check
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

$action = $_POST['action'] ?? null;
switch ($action) {
    case 'create':
        // ... создание бэкапа
        echo json_encode(['ok' => true, 'backup_id' => '...']);
        break;
    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
```

### 7. Push, обновить version в `cabinet.php` если нужно

Если страница ломает совместимость с предыдущей версией панели — поднимай `$cssVer` в cabinet.php.

---

## Как добавить новый API endpoint

Все API живут в `api/*.php` с общим паттерном:

```php
<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/vpn_helpers.php';

header('Content-Type: application/json; charset=utf-8');

// 1. CSRF (для state-changing операций)
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
    }
}

// 2. Получить action
$action = $_REQUEST['action'] ?? null;

// 3. Switch
try {
    switch ($action) {
        case 'list':
            $data = mv_loadConfigs();
            echo json_encode(['ok' => true, 'data' => $data]);
            break;

        case 'do_something':
            // Валидация
            $id = $_POST['id'] ?? null;
            if (!mv_isValidConfigId($id)) {
                throw new InvalidArgumentException('Invalid ID');
            }
            // Действие
            // ...
            mv_logEvent('did_something', $id);
            echo json_encode(['ok' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log("API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal error']);
}
```

### Вызов из JS

```javascript
const result = await window.MineVPN.fetchJSON('/api/your_action.php', {
    method: 'POST',
    body: new URLSearchParams({ action: 'do_something', id: 'vpn_xxx' })
});

if (result.ok) {
    window.MineVPN.toast.success('Готово!');
} else {
    window.MineVPN.toast.error(result.error || 'Ошибка');
}
```

---

## Тестирование

В репозитории есть два диагностический скрипт ([MineVPN/VPN](https://github.com/MineVPN/VPN/tree/main/docs/diagnostic.md)):

```bash
# Общая диагностика v5 (~80 проверок)
curl -O https://raw.githubusercontent.com/MineVPN/VPN/main/diagnostic.sh && sudo bash diagnostic.sh
```

Перед PR в `main` проверь:

1. **Синтаксис PHP** — `php -l <file>` для всех изменённых файлов
2. **Синтаксис bash** — `bash -n update.sh && bash -n vpn-healthcheck.sh`
3. **Диагностика** — `sudo bash diagnostic.sh` показывает 0 failures
4. **Live test** — тестируй конкретные сценарии: загрузка конфига, активация, failover, kill switch

### Чеклист сценариев для ручного тестирования

- [ ] Установка с нуля → панель открывается на `http://10.10.1.1/`
- [ ] Загрузка WG конфига → activate → tun0 поднимается → ping проходит
- [ ] Загрузка OVPN конфига → activate → tun0 поднимается через openvpn
- [ ] Failover: убить primary → автопереключение на backup в ≤15 сек
- [ ] Recovery: вернуть primary → обратное переключение
- [ ] Kill Switch: остановить VPN → LAN-клиенты не могут в интернет
- [ ] Reboot сервера → VPN автоподнимается, события `auto_start` в events.log
- [ ] Веб-терминал `/shell/` работает с авторизацией
- [ ] Изменение настроек сети → netplan try → если ошибка, rollback за 90 сек
- [ ] Удаление активного конфига → блокируется (нельзя)
- [ ] Удаление неактивного → ОК
- [ ] Brute-force login → бан на 5 минут после 5 попыток

---

## Отладка

### Логи

| Файл | Содержит |
|---|---|
| `/var/log/minevpn/vpn.log` | HC daemon — текущее состояние, ping, restarts |
| `/var/log/minevpn/events.log` | Журнал событий (vpn_down, failover, recovery) |
| `/var/log/minevpn/update.log` | Вывод update.sh (cron + ручные запуски) |
| `/var/log/minevpn/install.log` | Установщик (включая reinstall) |
| `/var/log/apache2/error.log` | PHP errors, Apache errors |
| `/var/log/apache2/access.log` | HTTP requests |
| `journalctl -u vpn-healthcheck` | Системный журнал HC daemon |
| `journalctl -u dnsmasq` | DHCP/DNS события |

### PHP errors

В `cabinet.php` и других PHP-файлах — НЕ выводим errors в браузер на production. Чтобы увидеть:

```bash
sudo tail -f /var/log/apache2/error.log
```

Для дебага временно можно:

```php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
```

(не коммитить!)

### JS отладка

```javascript
// В DevTools Console:
window.MineVPN              // namespace со всеми функциями
window.MineVPN.fetchJSON('/api/vpn_action.php?action=list')
    .then(console.log)
```

### Проверить что HC daemon видит

```bash
# Текущее состояние
cat /var/www/minevpn-state

# Текущая конфиг-метадата
sudo cat /var/www/vpn-configs/configs.json | jq

# Что говорит daemon прямо сейчас
sudo tail -f /var/log/minevpn/vpn.log

# События
sudo tail -f /var/log/minevpn/events.log
```

### Полная диагностика

```bash
# Общая диагностика v5 (~80 проверок)
curl -O https://raw.githubusercontent.com/MineVPN/VPN/main/diagnostic.sh && sudo bash diagnostic.sh
```

См. [MineVPN/VPN/docs/diagnostic.md](https://github.com/MineVPN/VPN/docs/diagnostic.md) для подробностей.

---

## Связанные репозитории

| Репозиторий | Что |
|---|---|
| [MineVPN/VPN](https://github.com/MineVPN/VPN) | **Установщик** — bash-скрипт, ставит ОС-пакеты и клонирует этот репо |
| [MineVPN/WebVPNCabinet](https://github.com/MineVPN/WebVPNCabinet) | **Этот репо** — код панели |
| [MineVPN/VPNServerPanel-v4-old](https://github.com/MineVPN/VPNServerPanel-v4-old) | Архив v4 (не поддерживается) |

---

## Поддержка и контакты

- 🌐 [minevpn.net](https://minevpn.net) — сайт проекта
- 🤖 [@MineVpn_Bot](https://t.me/MineVpn_Bot) — Telegram-бот (купить VPN-конфиг для тестирования)
- 👤 [@minevpn_support](https://t.me/minevpn_support) — поддержка
- 🐛 Issues — в этом репозитории на GitHub

---

## Лицензия

**Proprietary**. Copyright © 2026 MineVPN Systems. All Rights Reserved.

Использование, модификация и распространение — по согласованию с правообладателем.
