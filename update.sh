#!/bin/bash

# ==============================================================================
# MINE SERVER - Скрипт обновления v5
# ==============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

VERSION_FILE="/var/www/version"
SETTINGS_FILE="/var/www/settings"
LOG_FILE="/var/log/mineserver-update.log"

log() {
    echo -e "$(date '+%Y-%m-%d %H:%M:%S') $1" >> "$LOG_FILE"
    echo -e "$1"
}

get_version() {
    [[ -f "$VERSION_FILE" ]] && cat "$VERSION_FILE" || echo "1"
}

set_version() {
    echo "$1" > "$VERSION_FILE"
    chmod 644 "$VERSION_FILE"
}

# ==============================================================================
# МИГРАЦИЯ v4 -> v5
# ==============================================================================

migrate_to_v5() {
    log "${GREEN}[*]${NC} Миграция на версию 5..."
    
    # --- УДАЛЯЕМ .git ИЗ /var/www/html ---
    if [[ -d "/var/www/html/.git" ]]; then
        log "${YELLOW}[!]${NC} Удаление .git директории..."
        rm -rf /var/www/html/.git
    fi
    
    # --- ДОБАВЛЯЕМ www-data В ГРУППЫ ДЛЯ ЛОГОВ ---
    usermod -aG systemd-journal www-data 2>/dev/null || true
    usermod -aG adm www-data 2>/dev/null || true
    
    # --- ОПРЕДЕЛЕНИЕ ИНТЕРФЕЙСОВ ---
    local WAN_IF=""
    local LAN_IF=""
    
    if [[ -f /etc/netplan/01-mineserver.yaml ]]; then
        WAN_IF=$(grep -A5 "ethernets:" /etc/netplan/01-mineserver.yaml 2>/dev/null | grep -E "^\s+\w+:" | head -1 | tr -d ' :')
        LAN_IF=$(grep -A5 "ethernets:" /etc/netplan/01-mineserver.yaml 2>/dev/null | grep -E "^\s+\w+:" | tail -1 | tr -d ' :')
    fi
    
    [[ -z "$WAN_IF" ]] && WAN_IF=$(ip route | grep default | awk '{print $5}' | head -1)
    
    # --- KILL SWITCH ---
    log "${GREEN}[*]${NC} Настройка Kill Switch..."
    
    iptables -F FORWARD 2>/dev/null || true
    iptables -P FORWARD DROP
    
    if [[ -n "$LAN_IF" ]]; then
        iptables -A FORWARD -i "$LAN_IF" -o tun0 -j ACCEPT
        iptables -A FORWARD -i tun0 -o "$LAN_IF" -m state --state RELATED,ESTABLISHED -j ACCEPT
    fi
    
    iptables -A FORWARD -o tun0 -j ACCEPT
    iptables -A FORWARD -i tun0 -m state --state RELATED,ESTABLISHED -j ACCEPT
    
    iptables -t nat -C POSTROUTING -o tun0 -j MASQUERADE 2>/dev/null || \
        iptables -t nat -A POSTROUTING -o tun0 -j MASQUERADE
    
    # Сохраняем правила
    mkdir -p /etc/iptables
    iptables-save > /etc/iptables/rules.v4 2>/dev/null || true
    netfilter-persistent save 2>/dev/null || true
    
    # --- SUDOERS ---
    log "${GREEN}[*]${NC} Обновление sudoers..."
    cat > /etc/sudoers.d/mineserver << 'SUDOERS'
# MineServer sudoers v5
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl enable openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl disable openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl enable wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl disable wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan try
www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan apply
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart dnsmasq
www-data ALL=(ALL) NOPASSWD: /bin/systemctl reload dnsmasq
www-data ALL=(ALL) NOPASSWD: /bin/systemctl daemon-reload
www-data ALL=(ALL) NOPASSWD: /sbin/reboot
www-data ALL=(ALL) NOPASSWD: /bin/journalctl *
www-data ALL=(ALL) NOPASSWD: /usr/bin/journalctl *
www-data ALL=(ALL) NOPASSWD: /bin/systemctl is-active *
www-data ALL=(ALL) NOPASSWD: /bin/systemctl is-enabled *
www-data ALL=(ALL) NOPASSWD: /bin/systemctl status *
SUDOERS
    chmod 440 /etc/sudoers.d/mineserver
    
    # --- HEALTHCHECK (НЕ ЗАПУСКАЕТ VPN ЕСЛИ НЕТ КОНФИГА) ---
    log "${GREEN}[*]${NC} Установка VPN healthcheck..."
    cat > /usr/local/bin/vpn-healthcheck.sh << 'HEALTHCHECK'
#!/bin/bash
# VPN Health Check v5 - НЕ запускает VPN если нет конфига

SETTINGS_FILE="/var/www/settings"
LOG_FILE="/var/log/vpn-healthcheck.log"

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S'): $1" >> "$LOG_FILE"
    # Ротация лога
    if [[ $(wc -l < "$LOG_FILE" 2>/dev/null || echo 0) -gt 1000 ]]; then
        tail -500 "$LOG_FILE" > "$LOG_FILE.tmp" && mv "$LOG_FILE.tmp" "$LOG_FILE"
    fi
}

# Проверяем настройки
vpnchecker="true"
autoupvpn="true"
[[ -f "$SETTINGS_FILE" ]] && {
    grep -q "vpnchecker=false" "$SETTINGS_FILE" && vpnchecker="false"
    grep -q "autoupvpn=false" "$SETTINGS_FILE" && autoupvpn="false"
}

[[ "$vpnchecker" == "false" ]] && exit 0

# ВАЖНО: Проверяем наличие конфига ПЕРЕД любыми действиями
VPN_CONFIG=""
VPN_SERVICE=""

if [[ -f /etc/wireguard/tun0.conf ]]; then
    VPN_CONFIG="/etc/wireguard/tun0.conf"
    VPN_SERVICE="wg-quick@tun0"
elif [[ -f /etc/openvpn/tun0.conf ]]; then
    VPN_CONFIG="/etc/openvpn/tun0.conf"
    VPN_SERVICE="openvpn@tun0"
fi

# Если конфига нет - выходим молча, НЕ пытаемся запустить
if [[ -z "$VPN_CONFIG" ]]; then
    exit 0
fi

# Проверяем статус интерфейса
if ! ip link show tun0 &>/dev/null; then
    log "ERROR: tun0 не найден"
    
    if [[ "$autoupvpn" == "true" ]]; then
        log "Перезапуск $VPN_SERVICE..."
        systemctl restart "$VPN_SERVICE" 2>/dev/null
        sleep 5
    fi
    exit 1
fi

# Проверяем ping
for host in 8.8.8.8 1.1.1.1; do
    if ping -c 1 -W 3 -I tun0 "$host" &>/dev/null; then
        exit 0
    fi
done

log "ERROR: Ping через tun0 не проходит"

if [[ "$autoupvpn" == "true" ]]; then
    log "Перезапуск $VPN_SERVICE..."
    systemctl restart "$VPN_SERVICE" 2>/dev/null
fi
exit 1
HEALTHCHECK
    chmod +x /usr/local/bin/vpn-healthcheck.sh
    
    # Systemd service и timer
    cat > /etc/systemd/system/vpn-healthcheck.service << 'EOF'
[Unit]
Description=VPN Health Check
After=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/vpn-healthcheck.sh
TimeoutSec=30
EOF

    cat > /etc/systemd/system/vpn-healthcheck.timer << 'EOF'
[Unit]
Description=VPN Health Check Timer

[Timer]
OnBootSec=60
OnUnitActiveSec=15

[Install]
WantedBy=timers.target
EOF
    
    # --- ОТКЛЮЧАЕМ VPN СЕРВИСЫ ЕСЛИ НЕТ КОНФИГА ---
    if [[ ! -f /etc/openvpn/tun0.conf ]]; then
        systemctl stop openvpn@tun0 2>/dev/null || true
        systemctl disable openvpn@tun0 2>/dev/null || true
    fi
    
    if [[ ! -f /etc/wireguard/tun0.conf ]]; then
        systemctl stop wg-quick@tun0 2>/dev/null || true
        systemctl disable wg-quick@tun0 2>/dev/null || true
    fi
    
    # --- УСТАНОВКА ДОПОЛНИТЕЛЬНЫХ ПАКЕТОВ ---
    log "${GREEN}[*]${NC} Установка speedtest-cli..."
    apt-get install -y speedtest-cli 2>/dev/null || pip3 install speedtest-cli 2>/dev/null || true
    
    # --- СОЗДАНИЕ ДИРЕКТОРИЙ ---
    mkdir -p /var/www/data
    mkdir -p /etc/dnsmasq.d
    chown -R www-data:www-data /var/www/data
    chmod 755 /var/www/data
    
    touch /var/log/vpn-healthcheck.log
    chmod 666 /var/log/vpn-healthcheck.log
    
    # Настройки по умолчанию
    [[ ! -f "$SETTINGS_FILE" ]] && {
        echo "vpnchecker=true" > "$SETTINGS_FILE"
        echo "autoupvpn=true" >> "$SETTINGS_FILE"
    }
    chmod 666 "$SETTINGS_FILE"
    
    # --- LAUNCHER ---
    cat > /usr/local/bin/mineserver-update.sh << 'LAUNCHER'
#!/bin/bash
cd /var/www/html || exit 1

# ВАЖНО: Удаляем .git если есть
rm -rf .git 2>/dev/null

# Скачиваем свежую версию
git clone https://github.com/MineVPN/WebVPNCabinet.git /tmp/webupdate 2>/dev/null && {
    rm -rf /tmp/webupdate/.git
    cp -rf /tmp/webupdate/* /var/www/html/
    rm -rf /tmp/webupdate
}

# Запускаем update.sh
[[ -f update.sh ]] && chmod +x update.sh && ./update.sh

systemctl reload apache2 2>/dev/null || true
LAUNCHER
    chmod +x /usr/local/bin/mineserver-update.sh
    
    # Cron
    (crontab -l 2>/dev/null | grep -v "mineserver-update"; \
     echo "0 4 * * * /usr/local/bin/mineserver-update.sh >> /var/log/mineserver-update.log 2>&1") | crontab -
    
    # Применяем
    systemctl daemon-reload
    systemctl enable vpn-healthcheck.timer
    systemctl restart vpn-healthcheck.timer
    systemctl restart apache2 2>/dev/null || true
    
    set_version "5"
    log "${GREEN}[*]${NC} Миграция на v5 завершена!"
}

# ==============================================================================
# MAIN
# ==============================================================================

main() {
    log "${GREEN}========== Обновление MineServer ==========${NC}"
    
    current=$(get_version)
    log "Текущая версия: $current"
    
    # Всегда удаляем .git
    rm -rf /var/www/html/.git 2>/dev/null
    
    if [[ "$current" -lt 5 ]]; then
        migrate_to_v5
    else
        # Уже v5, просто обновляем healthcheck и sudoers
        log "${GREEN}[*]${NC} Обновление компонентов v5..."
        
        # Обновляем healthcheck на случай если он старый
        cat > /usr/local/bin/vpn-healthcheck.sh << 'HEALTHCHECK'
#!/bin/bash
SETTINGS_FILE="/var/www/settings"
LOG_FILE="/var/log/vpn-healthcheck.log"

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S'): $1" >> "$LOG_FILE"
    [[ $(wc -l < "$LOG_FILE" 2>/dev/null || echo 0) -gt 1000 ]] && tail -500 "$LOG_FILE" > "$LOG_FILE.tmp" && mv "$LOG_FILE.tmp" "$LOG_FILE"
}

vpnchecker="true"
autoupvpn="true"
[[ -f "$SETTINGS_FILE" ]] && {
    grep -q "vpnchecker=false" "$SETTINGS_FILE" && vpnchecker="false"
    grep -q "autoupvpn=false" "$SETTINGS_FILE" && autoupvpn="false"
}
[[ "$vpnchecker" == "false" ]] && exit 0

VPN_CONFIG=""
VPN_SERVICE=""
[[ -f /etc/wireguard/tun0.conf ]] && VPN_CONFIG="/etc/wireguard/tun0.conf" && VPN_SERVICE="wg-quick@tun0"
[[ -f /etc/openvpn/tun0.conf ]] && VPN_CONFIG="/etc/openvpn/tun0.conf" && VPN_SERVICE="openvpn@tun0"
[[ -z "$VPN_CONFIG" ]] && exit 0

if ! ip link show tun0 &>/dev/null; then
    log "ERROR: tun0 не найден"
    [[ "$autoupvpn" == "true" ]] && { log "Перезапуск $VPN_SERVICE..."; systemctl restart "$VPN_SERVICE" 2>/dev/null; sleep 5; }
    exit 1
fi

for host in 8.8.8.8 1.1.1.1; do
    ping -c 1 -W 3 -I tun0 "$host" &>/dev/null && exit 0
done

log "ERROR: Ping через tun0 не проходит"
[[ "$autoupvpn" == "true" ]] && { log "Перезапуск $VPN_SERVICE..."; systemctl restart "$VPN_SERVICE" 2>/dev/null; }
exit 1
HEALTHCHECK
        chmod +x /usr/local/bin/vpn-healthcheck.sh
        
        # Отключаем VPN сервисы если нет конфига
        [[ ! -f /etc/openvpn/tun0.conf ]] && { systemctl stop openvpn@tun0 2>/dev/null; systemctl disable openvpn@tun0 2>/dev/null; } || true
        [[ ! -f /etc/wireguard/tun0.conf ]] && { systemctl stop wg-quick@tun0 2>/dev/null; systemctl disable wg-quick@tun0 2>/dev/null; } || true
    fi
    
    log "${GREEN}========== Обновление завершено ==========${NC}"
}

main "$@"
