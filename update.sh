#!/bin/bash
#
# ███╗   ███╗██╗███╗   ██╗███████╗██╗   ██╗██████╗ ███╗   ██╗
# ████╗ ████║██║████╗  ██║██╔════╝██║   ██║██╔══██╗████╗  ██║
# ██╔████╔██║██║██╔██╗ ██║█████╗  ██║   ██║██████╔╝██╔██╗ ██║
# ██║╚██╔╝██║██║██║╚██╗██║██╔══╝  ╚██╗ ██╔╝██╔═══╝ ██║╚██╗██║
# ██║ ╚═╝ ██║██║██║ ╚████║███████╗ ╚████╔╝ ██║     ██║ ╚████║
# ╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝  ╚═══╝  ╚═╝     ╚═╝  ╚═══╝
# ══════════════════════════════════════════════════════════════════
#            U P D A T E   S C R I P T   F I L E
# ══════════════════════════════════════════════════════════════════
#
# @category    VPN Subsystem
# @package     MineVPN\Server
# @version     5.0.0
# [WARNING]
# This source code is strictly proprietary and confidential.
# Unauthorized reproduction, distribution, or decompilation
# is strictly prohibited and heavily monitored.
# @copyright   2026 MineVPN Systems. All rights reserved.
# ══════════════════════════════════════════════════════════════════
#
# MineVPN Server — Update / Скрипт последовательных миграций версий
#
# Выполняет миграции v0→v5 (каждая — свой блок if). При достижении целевой версии
# продолжает выполнять sanity-check блоки (права файлов, sync HC, установка PHP-расширений).
#
# Что делает по блокам:
#   • v0→v1  — базовая настройка (php-yaml, netplan permissions, sudoers, iptables MSS clamp)
#   • v1→v2  — система обновлений (cron ежедневно в 4 утра → git pull + этот скрипт)
#   • v2→v3  — базовый VPN Health Check (systemd timer)
#   • v3→v4  — bug-fix релиз (перекрыт миграцией v5)
#   • v4→v5  — VPN Manager (несколько конфигов + failover), Kill Switch, shellinabox,
#             HC daemon, gzip + кеш assets, редизайн UI, /etc/minevpn.conf, VOIP оптимизации
#
# Взаимодействует с:
#   • Installer.sh (корень репо) — вызывает этот скрипт при опции 2 (deploy)
#   • cron /usr/local/bin/minevpn-update.sh — вызывает ежедневно в 04:00
#   • vpn-healthcheck.sh — синхронизирует через md5 hash, перезапускает daemon
#     при изменении скрипта
#
# Изменяет файлы/директории:
#   • /var/www/version                            — номер текущей версии
#   • /var/www/settings                           — key=value настройки панели
#   • /var/www/vpn-configs/configs.json           — список VPN конфигов
#   • /var/www/minevpn-state                      — состояние VPN (STATE, ACTIVE_ID)
#   • /var/log/minevpn/events.log                 — журнал событий для UI
#   • /etc/sudoers.d/minevpn-www-data             — точечные sudo-разрешения
#   • /etc/systemd/system/vpn-healthcheck.service — systemd unit для HC daemon
#   • /etc/iptables/rules.v4                      — Kill Switch правила
#   • /etc/default/shellinabox                    — конфиг веб-терминала
#   • /etc/apache2/conf-available/minevpn-shell.conf — HTTP proxy /shell/ → :4200
#   • /etc/apache2/apache2.conf                   — AllowOverride All (для .htaccess)
#   • /etc/default/openvpn                        — AUTOSTART=none
#   • /etc/minevpn.conf                           — VERSION/DATE/WAN/LAN (для HC daemon)
#   • /etc/sysctl.conf                            — conntrack tuning для VOIP
#   • /etc/modprobe.d/no-sip-alg.conf             — отключение SIP ALG
#   • /etc/dnsmasq.conf                           — DHCP lease 12h → 72h
#
# Устанавливает пакеты: php-yaml, php-mbstring, shellinabox (apt-get install).
# ==================================================================

SCRIPT_VERSION=5
VERSION_FILE="/var/www/version"
SETTINGS_FILE="/var/www/settings"
WEB_DIR="/var/www/html"
VPN_CONFIGS_DIR="/var/www/vpn-configs"

# Цвета
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# ==============================================================================
# ЛОГ-ФАЙЛ І ПЕРЕНАПРАВЛЕННЯ STDOUT/STDERR (симетрично з Installer.sh)
# ==============================================================================
# Стратегія: ПОВНИЙ ЛОГ — в /var/log/minevpn/update.log записується АБСОЛЮТНО ВСЕ:
#   • Шум від apt-get, git, systemctl, sed, chmod — через stdout/stderr (FD 1/2)
#   • Заготовлені повідомлення (банери, log_*) — через FD 3/4
#
# Скрипт викликається з трьох місць:
#   • cron (щоночі 04:00) → minevpn-update.sh → SKIP_GIT=1 ./update.sh
#   • Installer.sh опція "2) Обновить" → SKIP_GIT=1 ./update.sh
#   • вручну → ./update.sh
#
# Критичний нюанс — пошук термінала через /dev/tty (а не FD 1):
# При виклику з Installer'а ми успадковуємо його FD 1 (=/var/log/minevpn/install.log).
# `exec 7>&1` взяв би цей файл як "термінал" — вивід update'у йшов би в install.log замість термінала.
# /dev/tty — це завжди реальний термінал якщо він є.
#
# В cron /dev/tty відсутній — пропускаємо tee і пишемо відразу в лог через sed strip ANSI
# (без цього був би дубль від cron-redirect'а в crontab).

LOG_FILE="/var/log/minevpn/update.log"
mkdir -p /var/log/minevpn 2>/dev/null
touch "$LOG_FILE" 2>/dev/null
chmod 644 "$LOG_FILE" 2>/dev/null

# Header сесії — розділяє запуски в лозі
UPDATE_PID=$BASHPID
{
    echo ""
    echo "============================================"
    echo "MineVPN Update v$SCRIPT_VERSION"
    echo "Запущено: $(date '+%Y-%m-%d %H:%M:%S')"
    echo "PID: $UPDATE_PID"
    if [ "${SKIP_GIT:-0}" = "1" ]; then
        echo "Викликано: з Installer.sh або cron-launcher (SKIP_GIT=1)"
    else
        echo "Викликано: вручну"
    fi
    echo "============================================"
} >> "$LOG_FILE"

# FD 7/8 = термінал. Шукаємо через /dev/tty (не через &1) — див. коментар вище.
if [ -e /dev/tty ] && [ -w /dev/tty ]; then
    exec 7>/dev/tty
    exec 8>/dev/tty
    # FD 3/4 = tee на термінал + лог (зі strip ANSI для логу)
    exec 3> >(stdbuf -oL tee >(stdbuf -oL sed -E 's/\x1b\[[0-9;]*[a-zA-Z]//g' >> "$LOG_FILE") >&7)
    exec 4> >(stdbuf -oL tee >(stdbuf -oL sed -E 's/\x1b\[[0-9;]*[a-zA-Z]//g' >> "$LOG_FILE") >&8)
else
    # Cron-режим: термінала немає, пишемо тільки в лог
    exec 3> >(stdbuf -oL sed -E 's/\x1b\[[0-9;]*[a-zA-Z]//g' >> "$LOG_FILE")
    exec 4> >(stdbuf -oL sed -E 's/\x1b\[[0-9;]*[a-zA-Z]//g' >> "$LOG_FILE")
fi

# stdout/stderr скрипту — в лог напряму (apt/git/systemctl/sed/chmod шум).
exec 1>>"$LOG_FILE" 2>&1

# Закриття FD 3/4 при виході — щоб фонові sed/tee встигли flush останні рядки.
trap 'exec 3>&- 4>&- 2>/dev/null; sleep 0.2' EXIT

# log_* пишуть через FD 3 — tee/sed дублюють у термінал (якщо є) і в лог.
log_info() { echo -e "${GREEN}[✓]${NC} $1" >&3; }
log_warn() { echo -e "${YELLOW}[!]${NC} $1" >&3; }
log_step() { echo -e "${CYAN}[*]${NC} $1" >&3; }

{
    echo ""
    echo -e "${CYAN}╔══════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║       MineVPN Server Update v$SCRIPT_VERSION           ║${NC}"
    echo -e "${CYAN}╚══════════════════════════════════════════╝${NC}"
    echo ""
} >&3

# Обновление репозитория (пропускаем если уже сделали git в Installer.sh)
if [ "${SKIP_GIT:-0}" = "1" ]; then
    log_info "Git уже выполнен — пропускаем pull"
else
    log_step "Обновление репозитория..."
    cd "$WEB_DIR" || exit 1
    git pull origin main 2>/dev/null || true
    log_info "Репозиторий обновлён"
fi

# Проверка версии
CURRENT_VERSION=0
if [ -f "$VERSION_FILE" ] && [ -s "$VERSION_FILE" ]; then
    CURRENT_VERSION=$(cat "$VERSION_FILE")
fi

echo "" >&3
log_step "Текущая версия: $CURRENT_VERSION"
log_step "Целевая версия: $SCRIPT_VERSION"
echo "" >&3

if [ "$CURRENT_VERSION" -ge "$SCRIPT_VERSION" ]; then
    log_info "Система уже обновлена до v$CURRENT_VERSION — выполняю проверки конфигурации"
    # НЕ выходим — ниже есть sanity-check блоки (права на файлы, sync HC, миграция events.log)
    # которые должны выполняться всегда, даже для уже обновлённой системы.
else
    log_warn "Применяю обновление..."
fi
echo "" >&3

# ==============================================================================
# МИГРАЦИЯ v0 → v1: Базовая настройка
# ==============================================================================
if [ "$CURRENT_VERSION" -lt 1 ]; then
    log_step "Миграция v1: Базовая настройка..."
    
    # php-yaml
    if ! php -m 2>/dev/null | grep -q yaml; then
        apt-get install -y -qq php-yaml 2>/dev/null || true
    fi
    
    # Права на netplan: 660 (root:www-data) — не 666!
    for np in /etc/netplan/*.yaml /etc/netplan/*.yml; do
        [ -f "$np" ] || continue
        chown root:www-data "$np" 2>/dev/null || true
        chmod 660 "$np" 2>/dev/null || true
    done
    
    # Sudoers (базовый)
    SUDOERS_FILE="/etc/sudoers.d/minevpn-www-data"
    if [ ! -f "$SUDOERS_FILE" ]; then
        cat > "$SUDOERS_FILE" << 'EOF'
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop openvpn*, /bin/systemctl start openvpn*, /bin/systemctl restart openvpn*
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop wg-quick*, /bin/systemctl start wg-quick*, /bin/systemctl restart wg-quick*
www-data ALL=(ALL) NOPASSWD: /bin/systemctl enable wg-quick*, /bin/systemctl disable wg-quick*
www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan try, /usr/sbin/netplan apply
www-data ALL=(root) NOPASSWD: /usr/bin/id
EOF
        chmod 440 "$SUDOERS_FILE"
    fi
    
    # MSS clamp
    if ! iptables -C FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null; then
        iptables -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu
        iptables-save > /etc/iptables/rules.v4 2>/dev/null || true
    fi
    
    log_info "Миграция v1 завершена"
fi

# ==============================================================================
# МИГРАЦИЯ v1 → v2: Система обновлений
# ==============================================================================
if [ "$CURRENT_VERSION" -lt 2 ]; then
    log_step "Миграция v2: Система обновлений..."
    
    mkdir -p /var/log/minevpn
    
    # Cron launcher — ім'я синхронне з Installer.sh::configure_auto_update.
    # SKIP_GIT=1 — сообщаем update.sh что git уже сделан выше.
    LAUNCHER="/usr/local/bin/minevpn-update.sh"
    cat > "$LAUNCHER" << 'EOF'
#!/bin/bash
cd /var/www/html/ || exit 1
echo "[$(date)] Обновление MineVPN..."
git fetch origin && git reset --hard origin/main && git clean -df
[ -f "update.sh" ] && chmod +x update.sh && SKIP_GIT=1 ./update.sh
echo "[$(date)] Готово"
EOF
    chmod +x "$LAUNCHER"
    
    (crontab -l 2>/dev/null | grep -vE "(run-update|minevpn-update|update\.sh)"; echo "0 4 * * * /bin/bash $LAUNCHER >> /var/log/minevpn/update.log 2>&1") | crontab -
    
    log_info "Миграция v2 завершена"
fi

# ==============================================================================
# МИГРАЦИЯ v2 → v3: VPN Health Check (базовый)
# ==============================================================================
if [ "$CURRENT_VERSION" -lt 3 ]; then
    log_step "Миграция v3: VPN Health Check..."
    
    # Файл настроек
    if [ ! -f "$SETTINGS_FILE" ]; then
        echo -e "vpnchecker=true\nautoupvpn=true" > "$SETTINGS_FILE"
    fi
    chmod 666 "$SETTINGS_FILE"
    
    # Не перезаписываем HC daemon (Type=simple из миграции v5) простым HC v2
    HC_V3_SKIP=false
    if [ -f /etc/systemd/system/vpn-healthcheck.service ]; then
        grep -q "Type=simple" /etc/systemd/system/vpn-healthcheck.service 2>/dev/null && HC_V3_SKIP=true
    fi
    
    if [ "$HC_V3_SKIP" = true ]; then
        log_info "Healthcheck daemon уже установлен, пропускаем"
    else
    # Базовый health check (будет обновлён в v5)
    cat > /usr/local/bin/vpn-healthcheck.sh << 'SCRIPT'
#!/bin/bash
INTERFACE="tun0"
SETTINGS="/var/www/settings"

[ -f "$SETTINGS" ] && ! grep -q "^vpnchecker=true$" "$SETTINGS" && exit 0

if ! ip link show "$INTERFACE" > /dev/null 2>&1; then
    if [ -f "$SETTINGS" ] && grep -q "^autoupvpn=true$" "$SETTINGS"; then
        [ -f "/etc/wireguard/${INTERFACE}.conf" ] && systemctl restart "wg-quick@${INTERFACE}"
        [ -f "/etc/openvpn/${INTERFACE}.conf" ] && systemctl restart "openvpn@${INTERFACE}"
    fi
    exit 1
fi
exit 0
SCRIPT
    chmod +x /usr/local/bin/vpn-healthcheck.sh
    
    cat > /etc/systemd/system/vpn-healthcheck.service << 'EOF'
[Unit]
Description=VPN Health Check
After=network-online.target
[Service]
Type=oneshot
ExecStart=/usr/local/bin/vpn-healthcheck.sh
EOF
    
    cat > /etc/systemd/system/vpn-healthcheck.timer << 'EOF'
[Unit]
Description=VPN Health Check Timer
[Timer]
OnBootSec=1min
OnUnitActiveSec=30s
[Install]
WantedBy=timers.target
EOF
    
    systemctl daemon-reload
    systemctl enable --now vpn-healthcheck.timer 2>/dev/null || true
    fi # HC_V3_SKIP
    
    log_info "Миграция v3 завершена"
fi

# ==============================================================================
# МИГРАЦИЯ v3 → v4: Минорные фиксы
# ==============================================================================
if [ "$CURRENT_VERSION" -lt 4 ]; then
    log_step "Миграция v4: Минорные фиксы"
    # v4 была bug-fix релизом — стабилизация HC, правила exit кодов.
    # Все исправления уже перекрыты миграцией v5 (весь HC перезаписывается).
    log_info "Миграция v4 завершена"
fi

# ==============================================================================
# МИГРАЦИЯ v4 → v5: VPN Manager + Kill Switch + улучшенный мониторинг
# ==============================================================================
if [ "$CURRENT_VERSION" -lt 5 ]; then
    log_step "Миграция v5: VPN Manager + Kill Switch + улучшенный мониторинг..."
    
    # === 1. Чистим старые sudoers записи (v1-v4 писали напрямую в /etc/sudoers) ===
    sed -i '/www-data ALL=(ALL) NOPASSWD: ALL/d' /etc/sudoers 2>/dev/null || true
    sed -i '/www-data.*NOPASSWD.*systemctl.*openvpn/d' /etc/sudoers 2>/dev/null || true
    sed -i '/www-data.*NOPASSWD.*systemctl.*wg-quick/d' /etc/sudoers 2>/dev/null || true
    sed -i '/www-data.*NOPASSWD.*\/usr\/bin\/id/d' /etc/sudoers 2>/dev/null || true
    sed -i '/www-data.*NOPASSWD.*netplan/d' /etc/sudoers 2>/dev/null || true
    
    # === 2. Обновляем sudoers — удалён /bin/bash, добавлены enable/disable openvpn ===
    cat > /etc/sudoers.d/minevpn-www-data << 'EOF'
# MineVPN Web Panel Permissions v5 (updated)
# OpenVPN
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl enable openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl disable openvpn@tun0
# WireGuard
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl enable wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl disable wg-quick@tun0
# Сеть
www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan try
www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan apply
# Проверка пароля root
www-data ALL=(root) NOPASSWD: /usr/bin/id
EOF
    chmod 440 /etc/sudoers.d/minevpn-www-data
    visudo -c -f /etc/sudoers.d/minevpn-www-data 2>/dev/null || log_warn "Ошибка в sudoers!"
    log_info "Sudoers обновлён (/bin/bash удалён)"
    
    # === 3. Создаём директорию для VPN конфигов ===
    mkdir -p "$VPN_CONFIGS_DIR"
    # 770: root и group www-data имеют полный доступ (write необходим для upload через PHP)
    chown root:www-data "$VPN_CONFIGS_DIR"
    chmod 770 "$VPN_CONFIGS_DIR"
    log_info "Директория VPN конфигов создана (770 root:www-data)"
    
    # === 3.5. Создаём state файл ===
    if [ ! -f /var/www/minevpn-state ]; then
        touch /var/www/minevpn-state
        chmod 666 /var/www/minevpn-state
        log_info "State файл создан"
    fi
    
    # === 3.6. Миграция существующего VPN конфига в vpn-manager ===
    CONFIGS_JSON="$VPN_CONFIGS_DIR/configs.json"
    if [ ! -f "$CONFIGS_JSON" ] || [ ! -s "$CONFIGS_JSON" ] || [ "$(cat "$CONFIGS_JSON" 2>/dev/null)" = "{}" ]; then
        for tun_conf in /etc/wireguard/tun0.conf /etc/openvpn/tun0.conf; do
            [ -f "$tun_conf" ] || continue
            CONF_ID="vpn_$(md5sum "$tun_conf" | cut -c1-16)"
            CONF_EXT="conf"
            cp "$tun_conf" "$VPN_CONFIGS_DIR/${CONF_ID}.${CONF_EXT}"
            chown www-data:www-data "$VPN_CONFIGS_DIR/${CONF_ID}.${CONF_EXT}"
            
            # Определяем тип и сервер
            CONF_TYPE="openvpn"
            CONF_SERVER="unknown"
            if grep -qi "\[Interface\]" "$tun_conf" && grep -qi "PrivateKey" "$tun_conf"; then
                CONF_TYPE="wireguard"
                CONF_SERVER=$(grep -oP 'Endpoint\s*=\s*\K[^:]+' "$tun_conf" 2>/dev/null | head -1)
            else
                CONF_SERVER=$(grep -oP '^\s*remote\s+\K\S+' "$tun_conf" 2>/dev/null | head -1)
            fi
            [ -z "$CONF_SERVER" ] && CONF_SERVER="unknown"
            
            # Создаём configs.json
            cat > "$CONFIGS_JSON" << MIGEOF
{
    "${CONF_ID}": {
        "id": "${CONF_ID}",
        "name": "${CONF_SERVER}",
        "filename": "${CONF_ID}.${CONF_EXT}",
        "original_filename": "tun0.conf",
        "type": "${CONF_TYPE}",
        "server": "${CONF_SERVER}",
        "port": "",
        "protocol": "",
        "priority": 1,
        "role": "primary",
        "created_at": "$(date '+%Y-%m-%d %H:%M:%S')",
        "last_used": "$(date '+%Y-%m-%d %H:%M:%S')"
    }
}
MIGEOF
            chown www-data:www-data "$CONFIGS_JSON"
            
            # Создаём state
            cat > /var/www/minevpn-state << STEOF
STATE=running
ACTIVE_ID=${CONF_ID}
PRIMARY_ID=${CONF_ID}
ACTIVATED_BY=migration
STEOF
            chmod 666 /var/www/minevpn-state
            
            log_info "Мигрирован VPN конфиг: $CONF_SERVER ($CONF_TYPE)"
            break  # Только первый найденный
        done
    fi
    
    # === 3.7. Settings — повна синхронізація з Installer.sh::configure_settings ===
    # Гарантуємо ідентичний фінальний файл незалежно від шляху встановлення:
    #   vpnchecker=true       — автоперевірка VPN активна
    #   autoupvpn=true        — автоперезапуск VPN при падінні
    #   failover=true         — перемикання на резервний конфіг при падінні primary
    #   failover_first=false  — м'який режим (спочатку restart активного, потім failover)
    #
    # Якщо файла немає — створюємо повністю.
    # Якщо є — додаємо тільки відсутні ключі (зберігаємо ручні налаштування юзера).
    # Видаляємо застарілий try_primary_first (замінено на failover_first з інверсною семантикою).
    if [ ! -f "$SETTINGS_FILE" ]; then
        echo -e "vpnchecker=true\nautoupvpn=true\nfailover=true\nfailover_first=false" > "$SETTINGS_FILE"
        chmod 666 "$SETTINGS_FILE"
        log_info "Settings створено з дефолтними значеннями"
    else
        grep -q "^vpnchecker=" "$SETTINGS_FILE" || echo "vpnchecker=true" >> "$SETTINGS_FILE"
        grep -q "^autoupvpn=" "$SETTINGS_FILE" || echo "autoupvpn=true" >> "$SETTINGS_FILE"
        grep -q "^failover=" "$SETTINGS_FILE" || echo "failover=true" >> "$SETTINGS_FILE"
        grep -q "^failover_first=" "$SETTINGS_FILE" || echo "failover_first=false" >> "$SETTINGS_FILE"
        # Видаляємо застарілий ключ (легасі від v5-pre)
        sed -i '/^try_primary_first=/d' "$SETTINGS_FILE"
    fi
    
    # === 4. Kill Switch ===
    NETPLAN_FILE=$(find /etc/netplan -name "*.yaml" 2>/dev/null | head -1)
    if [ -f "$NETPLAN_FILE" ]; then
        # Ищем LAN интерфейс (с optional: true)
        LAN_IF=$(grep -B10 "optional: true" "$NETPLAN_FILE" 2>/dev/null | grep -oP '^\s{4}\K[a-z0-9]+(?=:)' | tail -1)
        # Ищем WAN интерфейс (первый что не LAN)
        WAN_IF=$(grep -oP '^\s{4}\K[a-z0-9]+(?=:)' "$NETPLAN_FILE" 2>/dev/null | grep -v "^${LAN_IF}$" | head -1)
        
        if [ -n "$LAN_IF" ] && [ -n "$WAN_IF" ] && [ "$LAN_IF" != "$WAN_IF" ]; then
            # Проверяем не настроен ли уже Kill Switch
            if ! iptables -C FORWARD -i "$LAN_IF" -o "$WAN_IF" -j REJECT 2>/dev/null; then
                log_step "Настройка Kill Switch (LAN=$LAN_IF, WAN=$WAN_IF)..."
                
                # Сохраняем существующие правила NAT
                iptables-save -t nat > /tmp/iptables-nat-backup.txt 2>/dev/null || true
                
                # Устанавливаем политику DROP
                iptables -P FORWARD DROP
                
                # Очищаем FORWARD
                iptables -F FORWARD
                
                # Kill Switch правила
                iptables -A FORWARD -i "$LAN_IF" -o tun0 -j ACCEPT
                iptables -A FORWARD -i tun0 -o "$LAN_IF" -m state --state RELATED,ESTABLISHED -j ACCEPT
                iptables -A FORWARD -i "$LAN_IF" -o "$LAN_IF" -j ACCEPT
                iptables -A FORWARD -i "$LAN_IF" -o "$WAN_IF" -j REJECT --reject-with icmp-net-unreachable
                iptables -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu
                
                # Восстанавливаем NAT
                iptables-restore -T nat < /tmp/iptables-nat-backup.txt 2>/dev/null || true
                rm -f /tmp/iptables-nat-backup.txt
                
                # Добавляем NAT для tun0 если нет
                if ! iptables -t nat -C POSTROUTING -o tun0 -j MASQUERADE 2>/dev/null; then
                    iptables -t nat -A POSTROUTING -o tun0 -s 10.10.1.0/20 -j MASQUERADE
                fi
                
                iptables-save > /etc/iptables/rules.v4
                log_info "Kill Switch активирован"
            else
                log_info "Kill Switch уже настроен"
            fi
        else
            log_warn "Не удалось определить интерфейсы для Kill Switch"
        fi
    fi
    
    # === 4.5. INPUT chain (rate limit + LAN сервіси) ===
    # Синхронізація з Installer.sh::configure_firewall — захист панелі і SSH від brute-force flood,
    # дозвіл DNS і DHCP для LAN-клієнтів. Перевіряємо за наявністю ключового правила (HTTP rate limit
    # в ланцюжку HTTP) — якщо є, нічого не робимо (ідемпотентно).
    if ! iptables -C INPUT -p tcp --dport 80 -m state --state NEW -m recent --set --name HTTP 2>/dev/null; then
        log_step "Налаштування INPUT chain (rate limit HTTP/SSH + LAN сервіси)..."
        
        # LAN інтерфейс — беремо спочатку змінну вище (визначена в блоці 4 Kill Switch),
        # фаллбек — за IP 10.10.1.1
        INPUT_LAN_IF="${LAN_IF:-}"
        [ -z "$INPUT_LAN_IF" ] && INPUT_LAN_IF=$(ip -4 addr show 2>/dev/null | grep "10\.10\.1\.1/" | awk '{print $NF}' | head -1)
        
        # loopback завжди дозволяємо (shellinabox 127.0.0.1:4200, інші локальні сервіси)
        iptables -C INPUT -i lo -j ACCEPT 2>/dev/null || iptables -A INPUT -i lo -j ACCEPT
        # Встановлені з'єднання
        iptables -C INPUT -m state --state ESTABLISHED,RELATED -j ACCEPT 2>/dev/null || \
            iptables -A INPUT -m state --state ESTABLISHED,RELATED -j ACCEPT
        # HTTP — rate limit 60/хв (захист від flood)
        iptables -A INPUT -p tcp --dport 80 -m state --state NEW -m recent --set --name HTTP
        iptables -A INPUT -p tcp --dport 80 -m state --state NEW -m recent --update --seconds 60 --hitcount 60 --name HTTP -j DROP
        iptables -A INPUT -p tcp --dport 80 -j ACCEPT
        # SSH — rate limit 10 підключень/хв (захист від brute force)
        iptables -A INPUT -p tcp --dport 22 -m state --state NEW -m recent --set --name SSH
        iptables -A INPUT -p tcp --dport 22 -m state --state NEW -m recent --update --seconds 60 --hitcount 10 --name SSH -j DROP
        iptables -A INPUT -p tcp --dport 22 -j ACCEPT
        # DNS і DHCP для LAN-клієнтів (тільки якщо знаємо LAN інтерфейс)
        if [ -n "$INPUT_LAN_IF" ]; then
            iptables -A INPUT -i "$INPUT_LAN_IF" -p udp --dport 53 -j ACCEPT
            iptables -A INPUT -i "$INPUT_LAN_IF" -p tcp --dport 53 -j ACCEPT
            iptables -A INPUT -i "$INPUT_LAN_IF" -p udp --dport 67 -j ACCEPT
            log_info "INPUT chain налаштовано (rate limit HTTP/SSH, DNS/DHCP для LAN=$INPUT_LAN_IF)"
        else
            log_warn "INPUT chain налаштовано (rate limit HTTP/SSH), але LAN інтерфейс не визначено — DNS/DHCP для LAN не додано"
        fi
        
        iptables-save > /etc/iptables/rules.v4
    fi
    
    # === 5. Сервис мониторинга VPN (daemon) ===
    mkdir -p /var/log/minevpn
    touch /var/log/minevpn/vpn.log
    chmod 644 /var/log/minevpn/vpn.log

    # Останавливаем старый timer-базовый HC (v3/v4) — переходим на daemon
    systemctl stop vpn-healthcheck.timer vpn-healthcheck.service 2>/dev/null || true
    systemctl disable vpn-healthcheck.timer vpn-healthcheck.service 2>/dev/null || true
    rm -f /etc/systemd/system/vpn-healthcheck.timer

    # HC скрипт выполняется напрямую из $WEB_DIR/vpn-healthcheck.sh.
    # Копия в /usr/local/bin/ не нужна — один файл, один источник правды.
    cat > /etc/systemd/system/vpn-healthcheck.service << EOF
[Unit]
Description=MineVPN Health Check Daemon
After=network-online.target
Wants=network-online.target
[Service]
Type=simple
ExecStart=$WEB_DIR/vpn-healthcheck.sh
Restart=always
RestartSec=5
StandardOutput=null
StandardError=journal
[Install]
WantedBy=multi-user.target
EOF

    # Права: root:root 755 — только root может модифицировать файл
    # (www-data через Apache не сможет перезаписать даже при RCE в PHP).
    # Все могут читать+выполнять, systemd запускает от root.
    if [ -f "$WEB_DIR/vpn-healthcheck.sh" ]; then
        chown root:root "$WEB_DIR/vpn-healthcheck.sh"
        chmod 755 "$WEB_DIR/vpn-healthcheck.sh"
        log_info "HC daemon настроен ($WEB_DIR/vpn-healthcheck.sh)"
    else
        log_warn "HC скрипт не найден в $WEB_DIR — HC не установлен (проверьте git pull)"
    fi

    # Миграция со старой схемы: удаляем копию в /usr/local/bin/ если осталась
    rm -f /usr/local/bin/vpn-healthcheck.sh

    systemctl daemon-reload
    systemctl enable --now vpn-healthcheck.service 2>/dev/null || true
    log_info "Мониторинг VPN обновлён (daemon)"
    
    # === 7.0. AUTOSTART="all" — отключаем, автостарт через systemctl enable точечно ===
    sed -i 's/^AUTOSTART=.*/AUTOSTART="none"/' /etc/default/openvpn 2>/dev/null || true
    log_info "AUTOSTART=none выставлен"

    # === 7. Права на файлы ===
    chown -R www-data:www-data "$WEB_DIR" 2>/dev/null || true
    chmod -R 755 "$WEB_DIR" 2>/dev/null || true
    # VPN директории: 770 + setgid
    chown root:www-data /etc/openvpn /etc/wireguard 2>/dev/null || true
    chmod 770 /etc/openvpn/ 2>/dev/null || true
    chmod 770 /etc/wireguard/ 2>/dev/null || true
    chmod g+s /etc/openvpn /etc/wireguard 2>/dev/null || true
    # Файли всередині /etc/wireguard /etc/openvpn — 660 для group www-data read access
    # (синхронно з Installer.sh::configure_vpn — без цього старі файли v4 з chmod 600 недоступні PHP)
    find /etc/wireguard /etc/openvpn -type f -exec chmod 660 {} \; 2>/dev/null || true

    # === 7.1. Apache модули и права .htaccess ===
    a2enmod headers expires rewrite deflate 2>/dev/null || true
    # AllowOverride All — без него .htaccess не работает
    if grep -q 'AllowOverride None' /etc/apache2/apache2.conf 2>/dev/null; then
        sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf
        log_info "AllowOverride All выставлен"
    fi
    # Скрываем версию Apache/PHP
    sed -i 's/ServerTokens OS/ServerTokens Prod/' /etc/apache2/conf-enabled/security.conf 2>/dev/null || true
    sed -i 's/ServerSignature On/ServerSignature Off/' /etc/apache2/conf-enabled/security.conf 2>/dev/null || true
    # Netplan: 660 (root:www-data) — исправляем если было 666
    for np in /etc/netplan/*.yaml /etc/netplan/*.yml; do
        [ -f "$np" ] || continue
        chown root:www-data "$np" 2>/dev/null || true
        chmod 660 "$np" 2>/dev/null || true
    done
    systemctl restart apache2 2>/dev/null || true
    log_info "Apache модули включены, apache2 перезапущен"

    # === 8. Веб-терминал: установка shellinabox ===
    # Убираем устаревший setup-shellinabox.sh (логика теперь inline)
    rm -f "$WEB_DIR/setup-shellinabox.sh"

    # Устанавливаем shellinabox inline (без отдельного setup-файла)
    if [ ! -f /var/www/shell-token ]; then
        log_step "Установка shellinabox..."
        apt-get install -y -qq shellinabox 2>/dev/null || log_warn "apt-get install shellinabox не удался"
        if dpkg -l shellinabox 2>/dev/null | grep -q "^ii"; then
            cat > /etc/default/shellinabox << 'EOF'
# MineVPN — shellinabox конфиг
SHELLINABOX_DAEMON_START=1
SHELLINABOX_PORT=4200
SHELLINABOX_ARGS="--no-beep --disable-ssl --localhost-only --user-css MineDark:+/etc/shellinabox/mine-theme.css"
EOF
            a2enmod proxy proxy_http 2>/dev/null || true
            cat > /etc/apache2/conf-available/minevpn-shell.conf << 'APACHEEOF'
# MineVPN — shellinabox HTTP proxy
<IfModule mod_proxy.c>
    ProxyRequests Off
    ProxyPass        /shell/ http://127.0.0.1:4200/
    ProxyPassReverse /shell/ http://127.0.0.1:4200/
</IfModule>
APACHEEOF
            a2enconf minevpn-shell 2>/dev/null || true
            echo "enabled" > /var/www/shell-token
            chmod 644 /var/www/shell-token
            systemctl enable shellinabox 2>/dev/null
            systemctl restart shellinabox
            systemctl reload apache2 2>/dev/null || systemctl restart apache2 2>/dev/null
            systemctl is-active --quiet shellinabox && log_info "shellinabox установлен" || log_warn "shellinabox не запустился"
        else
            log_warn "shellinabox не установлен"
        fi
    else
        log_info "shellinabox уже установлен"
    fi

    # === 9. /etc/minevpn.conf — критично для HC daemon WAN/LAN детекту ===
    # HC daemon (vpn-healthcheck.sh::load_interfaces) использует этот файл как primary source
    # для WAN/LAN. Без него fallback идёт на ip-команды — работает, но логирует WARN.
    # Создаём при миграции v4→v5 (Installer.sh::finalize пишет этот файл при чистой установке).
    if [ ! -f /etc/minevpn.conf ]; then
        DETECTED_WAN=""
        DETECTED_LAN=""

        # Пробуем netplan yaml — LAN это интерфейс с optional:true (хороший маркер в v3 и v5)
        NETPLAN_F=$(find /etc/netplan -name "*.yaml" 2>/dev/null | head -1)
        if [ -n "$NETPLAN_F" ] && [ -f "$NETPLAN_F" ]; then
            DETECTED_LAN=$(grep -B10 "optional: true" "$NETPLAN_F" 2>/dev/null | grep -oP '^\s{4}\K[a-z0-9]+(?=:)' | tail -1)
            # WAN = ПЕРВЫЙ интерфейс НЕ равный LAN (обходит v4 баг с порядком LAN-first)
            DETECTED_WAN=$(grep -oP '^\s{4}\K[a-z0-9]+(?=:)' "$NETPLAN_F" 2>/dev/null | grep -v "^${DETECTED_LAN}$" | head -1)
        fi

        # Fallback — live system inspection (если netplan parsing не сработал)
        [ -z "$DETECTED_LAN" ] && DETECTED_LAN=$(ip -4 addr show 2>/dev/null | grep "10\.10\.1\.1/" | awk '{print $NF}')
        [ -z "$DETECTED_WAN" ] && DETECTED_WAN=$(ip route show default 2>/dev/null | grep -v "dev tun\|dev wg" | grep -oP 'dev \K[^ ]+' | head -1)

        cat > /etc/minevpn.conf << EOF
VERSION=$SCRIPT_VERSION
DATE=$(date '+%Y-%m-%d %H:%M:%S')
WAN=${DETECTED_WAN:-unknown}
LAN=${DETECTED_LAN:-unknown}
EOF
        log_info "/etc/minevpn.conf создан (WAN=${DETECTED_WAN:-unknown}, LAN=${DETECTED_LAN:-unknown})"
    fi

    # === 10. VOIP оптимизации ===
    # Для пользователей с IP-телефонами в LAN: 3 фикса (Conntrack tuning, SIP ALG disable, DHCP lease).
    # Изменения не ломают обычный web/HTTP трафик, только улучшают поведение SIP/UDP.

    # 10.1. Conntrack sysctl — больше размер таблицы + короткие UDP timeouts
    grep -q "^net.netfilter.nf_conntrack_max" /etc/sysctl.conf || echo "net.netfilter.nf_conntrack_max=262144" >> /etc/sysctl.conf
    grep -q "^net.netfilter.nf_conntrack_udp_timeout=" /etc/sysctl.conf || echo "net.netfilter.nf_conntrack_udp_timeout=30" >> /etc/sysctl.conf
    grep -q "^net.netfilter.nf_conntrack_udp_timeout_stream" /etc/sysctl.conf || echo "net.netfilter.nf_conntrack_udp_timeout_stream=120" >> /etc/sysctl.conf
    sysctl -p >/dev/null 2>&1
    log_info "Conntrack sysctl настроены (max=262144, UDP timeout=30/120)"

    # 10.2. SIP ALG — ядерные модули nf_conntrack_sip + nf_nat_sip «помогают» SIP трафику
    # пройти через NAT, но часто ломают VOIP (фантомные звонки, 1-way audio, проблемы регистрации).
    if [ ! -f /etc/modprobe.d/no-sip-alg.conf ]; then
        modprobe -r nf_conntrack_sip 2>/dev/null || true
        modprobe -r nf_nat_sip 2>/dev/null || true
        cat > /etc/modprobe.d/no-sip-alg.conf << 'EOF'
# MineVPN — отключение SIP ALG для корректной работы VOIP за NAT
blacklist nf_conntrack_sip
blacklist nf_nat_sip
EOF
        log_info "SIP ALG отключён (модули nf_conntrack_sip + nf_nat_sip в blacklist)"
    fi

    # 10.3. DHCP lease 12h → 72h (3 дня) — IP-телефоны реже обновляют IP
    # Используем grep -F (fixed string) и без $-end-of-line — безопасно при любых конфигах
    if [ -f /etc/dnsmasq.conf ] && grep -qF ",12h" /etc/dnsmasq.conf; then
        sed -i 's/,12h/,72h/' /etc/dnsmasq.conf
        if systemctl is-active --quiet dnsmasq 2>/dev/null; then
            systemctl restart dnsmasq 2>/dev/null && log_info "DHCP lease обновлён 12h → 72h (dnsmasq перезапущен)" \
                || log_warn "DHCP lease обновлён, но dnsmasq не перезапустился"
        else
            log_info "DHCP lease обновлён 12h → 72h (dnsmasq неактивен, рестарт не нужен)"
        fi
    fi

    # 10.4. dnsmasq.conf — інкрементальне доповнення до конфігурації Installer'а
    # Додаємо тільки відсутні рядки (не зачіпаємо ручні налаштування юзера,
    # в т.ч. interface= і dhcp-range= які мають євою системну логіку).
    if [ -f /etc/dnsmasq.conf ]; then
        dnsmasq_changed=0
        grep -qF "dhcp-authoritative" /etc/dnsmasq.conf || { echo "dhcp-authoritative" >> /etc/dnsmasq.conf; dnsmasq_changed=1; }
        grep -qF "domain=minevpn.lan" /etc/dnsmasq.conf || { echo "domain=minevpn.lan" >> /etc/dnsmasq.conf; dnsmasq_changed=1; }
        grep -qF "bind-interfaces" /etc/dnsmasq.conf || { echo "bind-interfaces" >> /etc/dnsmasq.conf; dnsmasq_changed=1; }
        grep -qF "cache-size=10000" /etc/dnsmasq.conf || { echo "cache-size=10000" >> /etc/dnsmasq.conf; dnsmasq_changed=1; }
        grep -qF "server=1.1.1.1" /etc/dnsmasq.conf || { echo "server=1.1.1.1" >> /etc/dnsmasq.conf; dnsmasq_changed=1; }
        grep -qF "server=8.8.8.8" /etc/dnsmasq.conf || { echo "server=8.8.8.8" >> /etc/dnsmasq.conf; dnsmasq_changed=1; }
        if [ "$dnsmasq_changed" = "1" ]; then
            if systemctl is-active --quiet dnsmasq 2>/dev/null; then
                systemctl restart dnsmasq 2>/dev/null && log_info "dnsmasq.conf доповнено + перезапущено" \
                    || log_warn "dnsmasq.conf доповнено, але dnsmasq не перезапустився"
            else
                log_info "dnsmasq.conf доповнено (dnsmasq неактивний)"
            fi
        fi
    fi

    # 10.5. SSH PermitRootLogin yes (синхронно з Installer.sh::configure_ssh)
    # Installer ставить yes при свіжій установці — для зручності роот SSH-доступу адміну.
    # Синхронізуємо для апгрейду якщо v4 залишив системний default (prohibit-password).
    if [ -f /etc/ssh/sshd_config ]; then
        sshd_changed=0
        if grep -qE "^#?PermitRootLogin[[:space:]]+(prohibit-password|no)" /etc/ssh/sshd_config; then
            sed -i 's/^#PermitRootLogin.*/PermitRootLogin yes/' /etc/ssh/sshd_config
            sed -i 's/^PermitRootLogin prohibit-password/PermitRootLogin yes/' /etc/ssh/sshd_config
            sed -i 's/^PermitRootLogin no/PermitRootLogin yes/' /etc/ssh/sshd_config
            sshd_changed=1
        fi
        if [ "$sshd_changed" = "1" ]; then
            systemctl restart sshd 2>/dev/null || systemctl restart ssh 2>/dev/null || true
            log_info "SSH PermitRootLogin yes (виставлено)"
        fi
    fi

    log_info "Миграция v5 завершена"
fi

# ==============================================================================
# МИГРАЦИЯ: vpn_history.json → events.log
# ==============================================================================
# v5 рефакторинг: HC daemon писал в JSON через PHP inline (медленно + сложно).
# Новый HC daemon пишет простой лог (TIME|TYPE|DETAILS[|EXTRA]).
# Конвертируем старую историю чтобы не терять данные.
mkdir -p /var/log/minevpn
if [ -f /var/log/minevpn/vpn_history.json ] && [ ! -s /var/log/minevpn/events.log ]; then
    log_step "Миграция vpn_history.json → events.log..."
    php -r '
        $json = @file_get_contents("/var/log/minevpn/vpn_history.json");
        $d = $json ? json_decode($json, true) : null;
        if (!is_array($d)) exit;
        $out = [];
        foreach (($d["disconnections"] ?? []) as $ev) {
            $t = $ev["time"] ?? ""; $r = $ev["reason"] ?? "";
            if ($t && $r) $out[] = [strtotime($t), "$t|disconnect|$r"];
        }
        foreach (($d["config_changes"] ?? []) as $ev) {
            $t = $ev["time"] ?? ""; $c = $ev["config"] ?? ""; $by = $ev["type"] ?? "auto";
            if (!$t || !$c) continue;
            // Старый формат: config был либо ID, либо "failover to ID", либо "primary restored"
            if (preg_match("/^failover to (\S+)/", $c, $m)) { $out[] = [strtotime($t), "$t|config_change|".$m[1]."|failover"]; }
            elseif (preg_match("/^(vpn_[a-f0-9]+)/", $c, $m)) { $out[] = [strtotime($t), "$t|config_change|".$m[1]."|manual"]; }
            // primary restored и прочее — пропускаем (нет ID для маппинга)
        }
        usort($out, fn($a, $b) => $a[0] - $b[0]);
        $lines = array_column($out, 1);
        file_put_contents("/var/log/minevpn/events.log", implode("\n", $lines) . (empty($lines) ? "" : "\n"));
    ' 2>/dev/null
    mv /var/log/minevpn/vpn_history.json /var/log/minevpn/vpn_history.json.migrated 2>/dev/null
    chmod 666 /var/log/minevpn/events.log
    log_info "Миграция завершена ($(wc -l < /var/log/minevpn/events.log 2>/dev/null || echo 0) событий)"
fi
# Гарантируем что events.log существует с правильными правами
touch /var/log/minevpn/events.log
chmod 666 /var/log/minevpn/events.log

# ==============================================================================
# ПРОВЕРКА ПРАВ (выполняется всегда, даже на v5+)
# ==============================================================================
# Гарантируем правильные права на критичные директории/файлы, независимо от версии.
# Баги прав которые могли появиться в миграциях предыдущих версий — исправляются здесь.

# VPN configs: 770 root:www-data (750 было багом — www-data не мог upload файлы)
if [ -d "$VPN_CONFIGS_DIR" ]; then
    # Исправляем только если текущие права не 770 — чтобы избежать лишних операций ежедневно
    perms=$(stat -c '%a' "$VPN_CONFIGS_DIR" 2>/dev/null)
    if [ "$perms" != "770" ]; then
        chown root:www-data "$VPN_CONFIGS_DIR"
        chmod 770 "$VPN_CONFIGS_DIR"
        log_info "Исправлены права $VPN_CONFIGS_DIR: $perms → 770"
    fi
fi

# events.log и vpn.log с правильными правами
if [ -f /var/log/minevpn/events.log ]; then
    chmod 666 /var/log/minevpn/events.log 2>/dev/null || true
fi

# State и settings — www-data регулярно пишет в оба. HC daemon мог испортить права
# на minevpn-state через mv -f (переносит ownership tmp-файла от root 0644 поверх оригинала).
# Актуальный HC daemon уже вызывает chmod 666 после mv, но этот блок — защита от испорченных
# прав из прошлых версий.
for f in /var/www/minevpn-state /var/www/settings; do
    if [ -f "$f" ]; then
        perms=$(stat -c '%a' "$f" 2>/dev/null)
        if [ "$perms" != "666" ]; then
            chmod 666 "$f" 2>/dev/null && log_info "Исправлены права $f: $perms → 666"
        fi
    fi
done

# ==============================================================================
# PHP РАСШИРЕНИЯ (выполняется всегда)
# ==============================================================================
# vpn-manager.php использует mb_substr() — это из пакета php-mbstring.
# php-yaml нужен для netsettings.php (чтение netplan YAML).
# Без них страницы падают с PHP fatal error.

for ext in mbstring yaml; do
    if ! php -m 2>/dev/null | grep -qi "^${ext}$"; then
        log_step "Установка php-${ext}..."
        apt-get install -y -qq "php-${ext}" 2>/dev/null && {
            log_info "php-${ext} установлен"
            php_ext_installed=1
        } || log_warn "не удалось установить php-${ext}"
    fi
done
# Если хоть что-то установили — перезапускаем apache2 чтобы подхватил модули
if [ "${php_ext_installed:-0}" = "1" ]; then
    systemctl restart apache2 2>/dev/null && log_info "apache2 перезапущен для подключения PHP расширений" || true
fi

# ==============================================================================
# SHELLINABOX THEME (выполняется всегда)
# ==============================================================================
# Синхронизируем тему с git-репо: $WEB_DIR/assets/css/shellinabox-theme.css → /etc/shellinabox/mine-theme.css
# По умолчанию shellinabox рисует reverse-video как ярко-синий (login prompt
# → нечитаемая полоса). --user-css подключает нашу тёмную тему как активную по умолчанию.

if dpkg -l shellinabox 2>/dev/null | grep -q "^ii"; then
    mkdir -p /etc/shellinabox
    theme_changed=0

    # 1) Синхронизируем файл темы с репо (cmp -s — копируем только если отличается)
    if [ -f "$WEB_DIR/assets/css/shellinabox-theme.css" ]; then
        if ! cmp -s "$WEB_DIR/assets/css/shellinabox-theme.css" /etc/shellinabox/mine-theme.css 2>/dev/null; then
            cp "$WEB_DIR/assets/css/shellinabox-theme.css" /etc/shellinabox/mine-theme.css
            chmod 644 /etc/shellinabox/mine-theme.css
            theme_changed=1
            log_info "shellinabox theme обновлена из $WEB_DIR/assets/css/shellinabox-theme.css"
        fi
    fi

    # 2) Проверяем SHELLINABOX_ARGS — добавляем --user-css если его нет
    if ! grep -q 'user-css' /etc/default/shellinabox 2>/dev/null; then
        cat > /etc/default/shellinabox << 'EOF'
# MineVPN — shellinabox конфиг
SHELLINABOX_DAEMON_START=1
SHELLINABOX_PORT=4200
SHELLINABOX_ARGS="--no-beep --disable-ssl --localhost-only --user-css MineDark:+/etc/shellinabox/mine-theme.css"
EOF
        theme_changed=1
        log_info "SHELLINABOX_ARGS обновлён (добавлен --user-css)"
    fi

    # 3) Перезапускаем shellinabox ТОЛЬКО если что-то реально изменилось — не дёргаем зря
    if [ "$theme_changed" -eq 1 ]; then
        systemctl restart shellinabox 2>/dev/null && log_info "shellinabox перезапущен" || log_warn "не удалось перезапустить shellinabox"
    fi
fi

# ==============================================================================
# СИНХРОНИЗАЦИЯ HEALTH CHECK SCRIPT
# ==============================================================================
# HC скрипт выполняется напрямую из $WEB_DIR/vpn-healthcheck.sh. При каждом
# update.sh проверяем что:
#   1. systemd service ссылается на правильный путь (мигрируем старую схему)
#   2. Права файла корректны (root:root 755) — защита от recursive chown www-data
#   3. Старой копии в /usr/local/bin/ нет
#   4. Если скрипт изменился — перезапускаем daemon

# 1) Миграция systemd service со старой схемы /usr/local/bin/ → $WEB_DIR/
if [ -f /etc/systemd/system/vpn-healthcheck.service ]; then
    if grep -q '/usr/local/bin/vpn-healthcheck.sh' /etc/systemd/system/vpn-healthcheck.service 2>/dev/null; then
        log_step "Миграция HC service: /usr/local/bin/ → $WEB_DIR/"
        cat > /etc/systemd/system/vpn-healthcheck.service << EOF
[Unit]
Description=MineVPN Health Check Daemon
After=network-online.target
Wants=network-online.target
[Service]
Type=simple
ExecStart=$WEB_DIR/vpn-healthcheck.sh
Restart=always
RestartSec=5
StandardOutput=null
StandardError=journal
[Install]
WantedBy=multi-user.target
EOF
        systemctl daemon-reload
        log_info "HC service обновлён (ExecStart → $WEB_DIR/vpn-healthcheck.sh)"
        hc_service_changed=1
    fi
fi

# 2) Права HC скрипта — root:root 755 (recursive chown в миграции v5 мог сбросить)
if [ -f "$WEB_DIR/vpn-healthcheck.sh" ]; then
    owner=$(stat -c '%U:%G' "$WEB_DIR/vpn-healthcheck.sh" 2>/dev/null)
    perms=$(stat -c '%a' "$WEB_DIR/vpn-healthcheck.sh" 2>/dev/null)
    if [ "$owner" != "root:root" ] || [ "$perms" != "755" ]; then
        chown root:root "$WEB_DIR/vpn-healthcheck.sh"
        chmod 755 "$WEB_DIR/vpn-healthcheck.sh"
        log_info "HC скрипт: права исправлены ($owner $perms → root:root 755)"
    fi
fi

# 3) Удаляем устаревшую копию из /usr/local/bin/ если она осталась
if [ -f /usr/local/bin/vpn-healthcheck.sh ]; then
    rm -f /usr/local/bin/vpn-healthcheck.sh
    log_info "Удалена устаревшая копия /usr/local/bin/vpn-healthcheck.sh"
fi

# 4) Перезапуск HC daemon если скрипт изменился (сравниваем md5 с сохранённым)
HC_MD5_FILE="/var/lib/minevpn/hc.md5"
mkdir -p /var/lib/minevpn
if [ -f "$WEB_DIR/vpn-healthcheck.sh" ]; then
    current_md5=$(md5sum "$WEB_DIR/vpn-healthcheck.sh" 2>/dev/null | cut -d' ' -f1)
    saved_md5=$(cat "$HC_MD5_FILE" 2>/dev/null || echo "")
    if [ "$current_md5" != "$saved_md5" ] || [ "${hc_service_changed:-0}" = "1" ]; then
        echo "$current_md5" > "$HC_MD5_FILE"
        if systemctl is-active --quiet vpn-healthcheck.service 2>/dev/null; then
            systemctl restart vpn-healthcheck.service 2>/dev/null && \
                log_info "HC daemon перезапущен (скрипт обновлён)" || \
                log_warn "HC daemon не удалось перезапустить"
        fi
    fi
fi

# ==============================================================================
# CRON LAUNCHER SYNC (виконується завжди)
# ==============================================================================
# Гарантуємо що /usr/local/bin/minevpn-update.sh містить SKIP_GIT=1.
# Старі версії Installer'а створювали /usr/local/bin/run-update.sh без SKIP_GIT — в cron'і
# git pull робився двічі (раз в launcherі, раз всередині update.sh). Працювало, але зайва робота.
#
# Мігруємо до канонічного імені і вмісту: видаляємо legacy run-update.sh, створюємо/оновлюємо
# minevpn-update.sh, переключаємо crontab з run-update або прямої update.sh директиви.

CRON_LAUNCHER="/usr/local/bin/minevpn-update.sh"
LEGACY_LAUNCHER="/usr/local/bin/run-update.sh"

# Якщо нема або не містить SKIP_GIT=1 (ознака канонічного вмісту) — переписуємо
if [ ! -f "$CRON_LAUNCHER" ] || ! grep -qF "SKIP_GIT=1" "$CRON_LAUNCHER" 2>/dev/null; then
    cat > "$CRON_LAUNCHER" << 'LAUNCHER_EOF'
#!/bin/bash
cd /var/www/html/ || exit 1
echo "[$(date)] Обновление MineVPN..."
git fetch origin && git reset --hard origin/main && git clean -df
[ -f "update.sh" ] && chmod +x update.sh && SKIP_GIT=1 ./update.sh
echo "[$(date)] Готово"
LAUNCHER_EOF
    chmod +x "$CRON_LAUNCHER"
    log_info "Cron launcher оновлено: $CRON_LAUNCHER (додано SKIP_GIT=1)"
fi

# Видаляємо legacy run-update.sh якщо залишився
if [ -f "$LEGACY_LAUNCHER" ]; then
    rm -f "$LEGACY_LAUNCHER"
    log_info "Видалено legacy launcher: $LEGACY_LAUNCHER"
fi

# Crontab: якщо посилається на run-update.sh або на update.sh напряму — переключаємо
if crontab -l 2>/dev/null | grep -qE "(run-update\.sh|/var/www/html/update\.sh)" && \
   ! crontab -l 2>/dev/null | grep -qF "$CRON_LAUNCHER"; then
    (crontab -l 2>/dev/null | grep -vE "(run-update|minevpn-update|/var/www/html/update\.sh)"; \
     echo "0 4 * * * /bin/bash $CRON_LAUNCHER >> /var/log/minevpn/update.log 2>&1") | crontab -
    log_info "Crontab переключено на $CRON_LAUNCHER"
fi

# ==============================================================================
# SUDOERS SANITY CHECK — правила reboot/poweroff (выполняется всегда)
# ==============================================================================
# Страница Настройки → секция «Управление сервером» вызывает sudo systemctl reboot/poweroff
# через api/system_action.php. Без этих sudoers-правил кнопки не сработают —
# www-data не сможет выполнить системные команды. Правила добавляются инкрементально
# (grep -qF → выходим если уже есть) — не дублируем, не ломаем существующие строки.
#
# После любого изменения в sudoers вызывается visudo -c для проверки синтаксиса.
# Брокен-файл sudoers может заблокировать весь sudo, так что visudo-валидация обязательна.

SUDOERS_FILE="/etc/sudoers.d/minevpn-www-data"
if [ -f "$SUDOERS_FILE" ]; then
    sudoers_changed=0

    # Перезагрузка (graceful через systemctl)
    if ! grep -qF "/bin/systemctl reboot" "$SUDOERS_FILE"; then
        echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl reboot" >> "$SUDOERS_FILE"
        sudoers_changed=1
    fi

    # Выключение (graceful через systemctl)
    if ! grep -qF "/bin/systemctl poweroff" "$SUDOERS_FILE"; then
        echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl poweroff" >> "$SUDOERS_FILE"
        sudoers_changed=1
    fi

    if [ "$sudoers_changed" = "1" ]; then
        if visudo -c -f "$SUDOERS_FILE" >/dev/null 2>&1; then
            log_info "Sudoers: добавлены reboot/poweroff (для страницы Настройки → Управление сервером)"
        else
            log_warn "Sudoers: ошибка валидации после добавления reboot/poweroff! Проверьте $SUDOERS_FILE"
        fi
    fi
fi

# ==============================================================================
# СОХРАНЕНИЕ ВЕРСИИ
# ==============================================================================
echo "$SCRIPT_VERSION" > "$VERSION_FILE"

{
    echo ""
    echo -e "${GREEN}╔══════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║    Обновление завершено: v$CURRENT_VERSION → v$SCRIPT_VERSION         ║${NC}"
    echo -e "${GREEN}╚══════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "  ${CYAN}Что нового в v5:${NC}"
    echo "    • VPN Manager — несколько конфигов с приоритетами, drag-drop, failover"
    echo "    • Kill Switch — LAN трафик блокируется при падении VPN"
    echo "    • Веб-терминал на базе shellinabox (/shell/)"
    echo "    • Health Check daemon — пинг + автоперезапуск + failover"
    echo "    • Синий UI, увеличенные размеры, стартовая страница — Обзор"
    echo "    • gzip-сжатие ассетов (mod_deflate), кэш на 1 год"
    echo "    • VOIP оптимизации — conntrack tuning, SIP ALG disabled, DHCP lease 72h"
    echo ""
} >&3
