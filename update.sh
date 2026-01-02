#!/bin/bash

# ==============================================================================
# MINE SERVER - Скрипт обновления
# Версия: 5.0
# ==============================================================================

set -e

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# Файлы
VERSION_FILE="/var/www/version"
SETTINGS_FILE="/var/www/settings"
LOG_FILE="/var/log/mineserver-update.log"

# Логирование
log() {
    local level=$1
    shift
    local message="$@"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo -e "${timestamp} [${level}] ${message}" >> "$LOG_FILE"
    
    case $level in
        INFO)  echo -e "${GREEN}[*]${NC} ${message}" ;;
        WARN)  echo -e "${YELLOW}[!]${NC} ${message}" ;;
        ERROR) echo -e "${RED}[X]${NC} ${message}" ;;
        *)     echo -e "${CYAN}[-]${NC} ${message}" ;;
    esac
}

# Получение текущей версии
get_version() {
    if [[ -f "$VERSION_FILE" ]]; then
        cat "$VERSION_FILE"
    else
        echo "1"
    fi
}

# Установка версии
set_version() {
    echo "$1" > "$VERSION_FILE"
    chmod 644 "$VERSION_FILE"
    log INFO "Версия обновлена до $1"
}

# ==============================================================================
# МИГРАЦИИ
# ==============================================================================

# Миграция v1 -> v2
migrate_v1_to_v2() {
    log INFO "Миграция v1 → v2: Настройка прав и базовых правил"
    
    # Права на netplan
    chmod 666 /etc/netplan/*.yaml 2>/dev/null || true
    
    # Установка php-yaml если нет
    if ! dpkg -l | grep -q php-yaml; then
        apt-get update -qq
        apt-get install -y php-yaml >/dev/null 2>&1
    fi
    
    # Базовый sudoers
    cat > /etc/sudoers.d/mineserver << 'SUDOERS'
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan try
www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan apply
SUDOERS
    chmod 440 /etc/sudoers.d/mineserver
    
    # MSS clamping
    if ! iptables -t mangle -C FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null; then
        iptables -t mangle -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu
    fi
    
    set_version "2"
}

# Миграция v2 -> v3
migrate_v2_to_v3() {
    log INFO "Миграция v2 → v3: Установка VPN healthcheck"
    
    # Создание скрипта healthcheck
    cat > /usr/local/bin/vpn-healthcheck.sh << 'HEALTHCHECK'
#!/bin/bash
SETTINGS_FILE="/var/www/settings"

# Проверяем включен ли мониторинг
if [[ -f "$SETTINGS_FILE" ]]; then
    if ! grep -q "vpnchecker=true" "$SETTINGS_FILE"; then
        exit 0
    fi
fi

# Проверяем наличие tun0
if ! ip link show tun0 &>/dev/null; then
    echo "$(date): tun0 не найден" >> /var/log/vpn-healthcheck.log
    
    if grep -q "autoupvpn=true" "$SETTINGS_FILE" 2>/dev/null; then
        echo "$(date): Перезапуск VPN..." >> /var/log/vpn-healthcheck.log
        
        if [[ -f /etc/wireguard/tun0.conf ]]; then
            systemctl restart wg-quick@tun0
        elif [[ -f /etc/openvpn/tun0.conf ]]; then
            systemctl restart openvpn@tun0
        fi
    fi
    exit 1
fi

# Проверяем ping через VPN
if ! ping -c 1 -W 5 -I tun0 8.8.8.8 &>/dev/null; then
    echo "$(date): Ping через tun0 не проходит" >> /var/log/vpn-healthcheck.log
    
    if grep -q "autoupvpn=true" "$SETTINGS_FILE" 2>/dev/null; then
        echo "$(date): Перезапуск VPN..." >> /var/log/vpn-healthcheck.log
        
        if [[ -f /etc/wireguard/tun0.conf ]]; then
            systemctl restart wg-quick@tun0
        elif [[ -f /etc/openvpn/tun0.conf ]]; then
            systemctl restart openvpn@tun0
        fi
    fi
    exit 1
fi

exit 0
HEALTHCHECK
    chmod +x /usr/local/bin/vpn-healthcheck.sh
    
    # Systemd service
    cat > /etc/systemd/system/vpn-healthcheck.service << 'SERVICE'
[Unit]
Description=VPN Health Check
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/vpn-healthcheck.sh
SERVICE
    
    # Systemd timer
    cat > /etc/systemd/system/vpn-healthcheck.timer << 'TIMER'
[Unit]
Description=Run VPN Health Check every 30 seconds

[Timer]
OnBootSec=60
OnUnitActiveSec=30

[Install]
WantedBy=timers.target
TIMER
    
    # Настройки по умолчанию
    if [[ ! -f "$SETTINGS_FILE" ]]; then
        echo "vpnchecker=true" > "$SETTINGS_FILE"
        echo "autoupvpn=true" >> "$SETTINGS_FILE"
    fi
    
    systemctl daemon-reload
    systemctl enable vpn-healthcheck.timer
    systemctl start vpn-healthcheck.timer
    
    set_version "3"
}

# Миграция v3 -> v4
migrate_v3_to_v4() {
    log INFO "Миграция v3 → v4: Улучшение healthcheck"
    
    # Обновление скрипта с улучшенной обработкой ошибок
    cat > /usr/local/bin/vpn-healthcheck.sh << 'HEALTHCHECK'
#!/bin/bash
set -o pipefail

SETTINGS_FILE="/var/www/settings"
LOG_FILE="/var/log/vpn-healthcheck.log"

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S'): $1" >> "$LOG_FILE"
}

# Проверяем включен ли мониторинг
if [[ -f "$SETTINGS_FILE" ]]; then
    if ! grep -q "vpnchecker=true" "$SETTINGS_FILE"; then
        exit 0
    fi
fi

# Проверяем наличие tun0
if ! ip link show tun0 &>/dev/null; then
    log "ERROR: tun0 не найден"
    
    if grep -q "autoupvpn=true" "$SETTINGS_FILE" 2>/dev/null; then
        log "Автоматический перезапуск VPN..."
        
        if [[ -f /etc/wireguard/tun0.conf ]]; then
            systemctl restart wg-quick@tun0 2>&1 | tee -a "$LOG_FILE"
        elif [[ -f /etc/openvpn/tun0.conf ]]; then
            systemctl restart openvpn@tun0 2>&1 | tee -a "$LOG_FILE"
        fi
    fi
    exit 1
fi

# Проверяем ping через VPN
if ! ping -c 1 -W 5 -I tun0 8.8.8.8 &>/dev/null; then
    log "ERROR: Ping через tun0 не проходит"
    
    if grep -q "autoupvpn=true" "$SETTINGS_FILE" 2>/dev/null; then
        log "Автоматический перезапуск VPN..."
        
        if [[ -f /etc/wireguard/tun0.conf ]]; then
            systemctl restart wg-quick@tun0 2>&1 | tee -a "$LOG_FILE"
        elif [[ -f /etc/openvpn/tun0.conf ]]; then
            systemctl restart openvpn@tun0 2>&1 | tee -a "$LOG_FILE"
        fi
    fi
    exit 1
fi

exit 0
HEALTHCHECK
    chmod +x /usr/local/bin/vpn-healthcheck.sh
    
    systemctl daemon-reload
    systemctl restart vpn-healthcheck.timer
    
    set_version "4"
}

# Миграция v4 -> v5
migrate_v4_to_v5() {
    log INFO "Миграция v4 → v5: Kill Switch + улучшения безопасности"
    
    # --- ОПРЕДЕЛЕНИЕ ИНТЕРФЕЙСОВ ---
    local WAN_IF=""
    local LAN_IF=""
    
    # Получаем интерфейсы из netplan
    if [[ -f /etc/netplan/01-mineserver.yaml ]]; then
        WAN_IF=$(grep -A5 "ethernets:" /etc/netplan/01-mineserver.yaml | grep -E "^\s+\w+:" | head -1 | tr -d ' :')
        LAN_IF=$(grep -A5 "ethernets:" /etc/netplan/01-mineserver.yaml | grep -E "^\s+\w+:" | tail -1 | tr -d ' :')
    fi
    
    # Fallback: определение по маршрутам
    if [[ -z "$WAN_IF" ]]; then
        WAN_IF=$(ip route | grep default | awk '{print $5}' | head -1)
    fi
    
    # Fallback: все ethernet интерфейсы
    if [[ -z "$LAN_IF" ]]; then
        for iface in $(ls /sys/class/net/ | grep -E '^(eth|enp|ens)'); do
            if [[ "$iface" != "$WAN_IF" ]]; then
                LAN_IF="$iface"
                break
            fi
        done
    fi
    
    log INFO "WAN интерфейс: $WAN_IF"
    log INFO "LAN интерфейс: $LAN_IF"
    
    # --- KILL SWITCH ---
    log INFO "Настройка Kill Switch..."
    
    # Сохраняем текущие правила
    iptables-save > /tmp/iptables-backup-v5.rules
    
    # Очищаем FORWARD chain
    iptables -F FORWARD
    
    # Kill Switch: политика DROP по умолчанию для FORWARD
    iptables -P FORWARD DROP
    
    # Разрешаем трафик ТОЛЬКО через VPN туннель
    if [[ -n "$LAN_IF" ]]; then
        # LAN -> VPN (исходящий)
        iptables -A FORWARD -i "$LAN_IF" -o tun0 -j ACCEPT
        # VPN -> LAN (входящий, установленные соединения)
        iptables -A FORWARD -i tun0 -o "$LAN_IF" -m state --state RELATED,ESTABLISHED -j ACCEPT
    fi
    
    # Для случая когда LAN_IF = WAN_IF (один интерфейс)
    if [[ "$LAN_IF" == "$WAN_IF" ]] || [[ -z "$LAN_IF" ]]; then
        iptables -A FORWARD -o tun0 -j ACCEPT
        iptables -A FORWARD -i tun0 -m state --state RELATED,ESTABLISHED -j ACCEPT
    fi
    
    # NAT через VPN
    iptables -t nat -C POSTROUTING -o tun0 -j MASQUERADE 2>/dev/null || \
        iptables -t nat -A POSTROUTING -o tun0 -j MASQUERADE
    
    # Сохраняем правила
    if command -v netfilter-persistent &>/dev/null; then
        netfilter-persistent save
    else
        iptables-save > /etc/iptables/rules.v4 2>/dev/null || \
        iptables-save > /etc/iptables.rules
    fi
    
    # --- ОБНОВЛЕНИЕ SUDOERS ---
    log INFO "Обновление sudoers..."
    
    cat > /etc/sudoers.d/mineserver << 'SUDOERS'
# MineServer sudoers rules - v5
# Только необходимые команды

# VPN управление
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl enable wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl disable wg-quick@tun0

# Сетевые настройки
www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan try
www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan apply

# Системные
www-data ALL=(ALL) NOPASSWD: /bin/systemctl daemon-reload
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart dnsmasq
www-data ALL=(ALL) NOPASSWD: /sbin/reboot
www-data ALL=(root) NOPASSWD: /usr/bin/id
SUDOERS
    chmod 440 /etc/sudoers.d/mineserver
    
    # --- ОБНОВЛЕНИЕ СКРИПТА ОБНОВЛЕНИЯ ---
    log INFO "Обновление launcher..."
    
    cat > /usr/local/bin/mineserver-update.sh << 'LAUNCHER'
#!/bin/bash
cd /var/www/html || exit 1
git fetch origin 2>/dev/null
git reset --hard origin/main 2>/dev/null || git reset --hard origin/master 2>/dev/null
git clean -df 2>/dev/null
if [[ -f update.sh ]]; then
    chmod +x update.sh
    ./update.sh
fi
LAUNCHER
    chmod +x /usr/local/bin/mineserver-update.sh
    
    # Обновляем cron
    (crontab -l 2>/dev/null | grep -v "run-update.sh\|mineserver-update.sh\|update.sh"; \
     echo "0 4 * * * /usr/local/bin/mineserver-update.sh >> /var/log/mineserver-update.log 2>&1") | crontab -
    
    # --- ОБНОВЛЕНИЕ HEALTHCHECK ТАЙМЕРА ---
    cat > /etc/systemd/system/vpn-healthcheck.timer << 'TIMER'
[Unit]
Description=Run VPN Health Check every 30 seconds

[Timer]
OnBootSec=60
OnUnitActiveSec=30

[Install]
WantedBy=timers.target
TIMER
    
    systemctl daemon-reload
    systemctl restart vpn-healthcheck.timer
    
    # --- СОЗДАНИЕ ДИРЕКТОРИИ ДЛЯ ДАННЫХ ---
    mkdir -p /var/www/data
    chown www-data:www-data /var/www/data
    chmod 755 /var/www/data
    
    log INFO "Kill Switch активирован!"
    set_version "5"
}

# ==============================================================================
# ОСНОВНАЯ ЛОГИКА
# ==============================================================================

main() {
    log INFO "========== Запуск обновления =========="
    
    current_version=$(get_version)
    log INFO "Текущая версия: $current_version"
    
    # Последовательное применение миграций
    if [[ "$current_version" -lt 2 ]]; then
        migrate_v1_to_v2
    fi
    
    if [[ "$current_version" -lt 3 ]]; then
        migrate_v2_to_v3
    fi
    
    if [[ "$current_version" -lt 4 ]]; then
        migrate_v3_to_v4
    fi
    
    if [[ "$current_version" -lt 5 ]]; then
        migrate_v4_to_v5
    fi
    
    final_version=$(get_version)
    log INFO "Финальная версия: $final_version"
    log INFO "========== Обновление завершено =========="
}

# Запуск
main "$@"
