# MineVPN — Web Cabinet

Код веб-панели управления VPN-сервером MineVPN. Этот репозиторий содержит сам интерфейс — PHP, JavaScript, CSS, а также скрипты обновления и health check daemon.

> **Не клонируйте этот репозиторий вручную для установки.** Используйте [установщик из MineVPN/VPN](https://github.com/MineVPN/VPN) — он подготовит систему (Apache, PHP, WireGuard, OpenVPN, dnsmasq, iptables) и сам клонирует этот репо в `/var/www/html/`.

---

## Структура

```
cabinet.php                    Главная оболочка панели (sidebar + контент)
index.php                      Точка входа (роутинг auth/login)
login.php / logout.php         Авторизация по root-паролю
.htaccess                      Apache rules (gzip, кеш, security headers)

api/                           JSON endpoints для AJAX
  vpn_action.php                — все действия над конфигами (activate, delete, rename, ...)
  stats_api.php                 — метрики (CPU/RAM/Disk/Network/события)
  status_check.php              — состояние VPN для polling
  ping.php                      — ping-инструмент
  system_action.php             — reboot/poweroff сервера

pages/                         Содержимое страниц (включаются в cabinet.php)
  vpn-manager.php + handler     — VPN Manager (список конфигов)
  stats.php                     — Обзор / дашборд
  netsettings.php               — настройки сети (WAN/LAN через netplan)
  settings.php                  — настройки панели
  console.php                   — веб-терминал (iframe → shellinabox)
  pinger.php                    — Ping
  about.php                     — О панели

includes/
  vpn_helpers.php               Shared library — все mv_* функции
                                (loadConfigs, saveConfigs, readState, logEvent, ...)

assets/
  css/                          Tokens + components + page-specific стили
  js/                           app.js + lib (toast, progress, confirm) + page-specific
  img/                          Логотипы, favicon

vpn-healthcheck.sh              Health Check daemon (systemd Type=simple)
update.sh                       Скрипт миграций v0 → v5
```

---

## Архитектура

### State machine
- `/var/www/minevpn-state` — runtime state (`STATE`, `ACTIVE_ID`, `PRIMARY_ID`, `ACTIVATED_BY`)
- `/var/www/vpn-configs/configs.json` — metadata конфигов (id, имя, роль, приоритет, тип)
- `/var/www/vpn-configs/*.conf` — файлы конфигов (доступны только root + www-data, 660)
- `/var/log/minevpn/events.log` — журнал событий (TIME|TYPE|F1|F2|F3 формат)
- `/etc/minevpn.conf` — статика (VERSION, WAN, LAN) для HC daemon
- `/var/www/settings` — настройки панели (key=value)

### HC daemon
Бесконечный цикл с `sleep 5`. Проверяет:
1. Есть ли интерфейс `tun0` и есть ли у него IP
2. Проходит ли ping через VPN
3. Не сброшены ли iptables правила Kill Switch
4. Жив ли провайдерский интернет (если VPN лёг — может это ISP)

При падении — пишет событие, перезапускает активный (если включён `autoupvpn`), переключается на резервный (если включён `failover`). Все действия логируются в `events.log`.

### PRG pattern
Формы с `multipart/form-data` (загрузка конфигов) идут через `vpn-manager.handler.php` → POST → redirect → GET → `vpn-manager.php`. Это бьёт дублирование при F5/Ctrl+R.

### AJAX
Все остальные действия (activate, rename, delete, reorder через drag-drop, toggle role) идут через `api/vpn_action.php` без перезагрузки. Поллинг состояния — каждые 2 секунды (live данные) и 5 секунд (события и история).

### Безопасность
- `mv_isValidConfigId()` — белый список `vpn_[a-f0-9]{16}` против path traversal
- `escapeshellarg()` во всех `shell_exec`
- Atomic writes через `flock` (`configs.json.lock` отдельный файл)
- Brute-force защита логина (`flock` на counter-файл)
- Sudoers — точечные правила, без `NOPASSWD: ALL`

---

## Разработка

Локальная разработка не требуется — панель работает только на установленном MineVPN-сервере. Для изменений:

1. Установить v5 на тестовый сервер (через [MineVPN/VPN установщик](https://github.com/MineVPN/VPN))
2. `cd /var/www/html` — это и есть git working copy этого репозитория
3. Делать изменения, тестировать
4. Коммитить и пушить

После git push в `main` все production-серверы подтянут изменения через cron в 04:00 (или вручную через `sudo /usr/local/bin/MineVPN-Update.sh`).

---

## Cache-busting

Каждая страница имеет свой `$<page>AssetsVer` (например `$statsAssetsVer = '5.5.15'`) — при изменениях CSS/JS поднимаем номер, браузер тянет свежие файлы. Глобальный `$cssVer` в `cabinet.php` для shared assets.

---

## Миграции

`update.sh` содержит блоки `if [ "$CURRENT_VERSION" -lt N ]` для каждой версии (v1, v2, v3, v4, v5). Каждый блок выполняет необратимые изменения один раз — права файлов, новые sudoers, iptables правила, systemd units.

Дополнительные sanity-check блоки выполняются при каждом запуске независимо от версии — гарантируют корректность прав, sudoers reboot/poweroff, актуальность HC daemon.

---

## Установка для пользователей

См. [MineVPN/VPN](https://github.com/MineVPN/VPN) — там основной README с командой установки и описанием возможностей.

---

## Лицензия

Proprietary. Copyright © 2026 MineVPN Systems. All Rights Reserved.
