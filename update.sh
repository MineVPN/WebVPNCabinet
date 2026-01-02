#!/bin/bash

# ==============================================================================
# MINE SERVER - Скрипт обновления
# Версия: 5.1 (исправления)
# ==============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

VERSION_FILE="/var/www/version"
SETTINGS_FILE="/var/www/settings"
LOG_FILE="/var/log/mineserver-update.log"

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

get_version() {
    if [[ -f "$VERSION_FILE" ]]; then
        cat "$VERSION_FILE"
    else
        echo "1"
    fi
}

set_version() {
    echo "$1" > "$VERSION_FILE"
    chmod 644 "$VERSION_FILE"
    log INFO "Версия обновлена до $1"
}

# ==============================================================================
# МИГРАЦИИ
# ==============================================================================

migrate_v1_to_v2() {
    log INFO "Миграция v1 → v2: Настройка прав и базовых правил"
    
    chmod 666 /etc/netplan/*.yaml 2>/dev/null || true
    
    if ! dpkg -l | grep -q php-yaml; then
        apt-get update -qq
        apt-get install -y php-yaml >/dev/null 2>&1
    fi
    
    # MSS clamping
    if ! iptables -t mangle -C FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null; then
        iptables -t mangle -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu
    fi
    
    set_version "2"
}

migrate_v2_to_v3() {
    log INFO "Миграция v2 → v3: Установка VPN healthcheck"
    
    # Настройки по умолчанию
    if [[ ! -f "$SETTINGS_FILE" ]]; then
        echo "vpnchecker=true" > "$SETTINGS_FILE"
        echo "autoupvpn=true" >> "$SETTINGS_FILE"
    fi
    chmod 666 "$SETTINGS_FILE"
    
    set_version "3"
}

migrate_v3_to_v4() {
    log INFO "Миграция v3 → v4: Улучшение healthcheck"
    set_version "4"
}

migrate_v4_to_v5() {
    log INFO "Миграция v4 → v5: Kill Switch + безопасность"
    
    # --- ОПРЕДЕЛЕНИЕ ИНТЕРФЕЙСОВ ---
    local WAN_IF=""
    local LAN_IF=""
    
    if [[ -f /etc/netplan/01-mineserver.yaml ]]; then
        WAN_IF=$(grep -A5 "ethernets:" /etc/netplan/01-mineserver.yaml | grep -E "^\s+\w+:" | head -1 | tr -d ' :')
        LAN_IF=$(grep -A5 "ethernets:" /etc/netplan/01-mineserver.yaml | grep -E "^\s+\w+:" | tail -1 | tr -d ' :')
    fi
    
    if [[ -z "$WAN_IF" ]]; then
        WAN_IF=$(ip route | grep default | awk '{print $5}' | head -1)
    fi
    
    if [[ -z "$LAN_IF" ]]; then
        for iface in $(ls /sys/class/net/ | grep -E '^(eth|enp|ens)'); do
            if [[ "$iface" != "$WAN_IF" ]]; then
                LAN_IF="$iface"
                break
            fi
        done
    fi
    
    log INFO "WAN: $WAN_IF, LAN: $LAN_IF"
    
    # --- KILL SWITCH ---
    iptables-save > /tmp/iptables-backup-v5.rules 2>/dev/null || true
    iptables -F FORWARD 2>/dev/null || true
    iptables -P FORWARD DROP
    
    if [[ -n "$LAN_IF" ]]; then
        iptables -A FORWARD -i "$LAN_IF" -o tun0 -j ACCEPT
        iptables -A FORWARD -i tun0 -o "$LAN_IF" -m state --state RELATED,ESTABLISHED -j ACCEPT
    fi
    
    if [[ "$LAN_IF" == "$WAN_IF" ]] || [[ -z "$LAN_IF" ]]; then
        iptables -A FORWARD -o tun0 -j ACCEPT
        iptables -A FORWARD -i tun0 -m state --state RELATED,ESTABLISHED -j ACCEPT
    fi
    
    iptables -t nat -C POSTROUTING -o tun0 -j MASQUERADE 2>/dev/null || \
        iptables -t nat -A POSTROUTING -o tun0 -j MASQUERADE
    
    if command -v netfilter-persistent &>/dev/null; then
        netfilter-persistent save
    else
        mkdir -p /etc/iptables
        iptables-save > /etc/iptables/rules.v4
    fi
    
    set_version "5"
}

# Миграция v5 -> v5.1 (исправления)
migrate_v5_to_v51() {
    log INFO "Миграция v5 → v5.1: Исправления healthcheck, логов, прав"
    
    # --- ДОБАВЛЯЕМ www-data В ГРУППУ systemd-journal ДЛЯ ЛОГОВ ---
    log INFO "Настройка прав для просмотра логов..."
    usermod -aG systemd-journal www-data 2>/dev/null || true
    usermod -aG adm www-data 2>/dev/null || true
    
    # --- ОБНОВЛЯЕМ SUDOERS С JOURNALCTL ---
    log INFO "Обновление sudoers..."
    cat > /etc/sudoers.d/mineserver << 'SUDOERS'
# MineServer sudoers rules - v5.1

# VPN управление
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl enable wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl disable wg-quick@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl enable openvpn@tun0
www-data ALL=(ALL) NOPASSWD: /bin/systemctl disable openvpn@tun0

# Сетевые настройки
www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan try
www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan apply

# Системные
www-data ALL=(ALL) NOPASSWD: /bin/systemctl daemon-reload
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart dnsmasq
www-data ALL=(ALL) NOPASSWD: /bin/systemctl reload dnsmasq
www-data ALL=(ALL) NOPASSWD: /sbin/reboot

# Логи - ВАЖНО для просмотра журналов
www-data ALL=(ALL) NOPASSWD: /bin/journalctl *
www-data ALL=(ALL) NOPASSWD: /usr/bin/journalctl *

# Диагностика
www-data ALL=(ALL) NOPASSWD: /bin/systemctl is-active *
www-data ALL=(ALL) NOPASSWD: /bin/systemctl is-enabled *
www-data ALL=(ALL) NOPASSWD: /bin/systemctl status *
SUDOERS
    chmod 440 /etc/sudoers.d/mineserver
    
    # --- ИСПРАВЛЕННЫЙ HEALTHCHECK ---
    log INFO "Установка исправленного VPN healthcheck..."
    
    cat > /usr/local/bin/vpn-healthcheck.sh << 'HEALTHCHECK'
#!/bin/bash
# VPN Health Check v5.1 - исправленная версия

SETTINGS_FILE="/var/www/settings"
LOG_FILE="/var/log/vpn-healthcheck.log"
LOCK_FILE="/tmp/vpn-healthcheck.lock"
MAX_LOG_SIZE=1048576  # 1MB

# Функция логирования
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S'): $1" >> "$LOG_FILE"
    
    # Ротация лога если больше 1MB
    if [[ -f "$LOG_FILE" ]] && [[ $(stat -f%z "$LOG_FILE" 2>/dev/null || stat -c%s "$LOG_FILE" 2>/dev/null) -gt $MAX_LOG_SIZE ]]; then
        tail -n 1000 "$LOG_FILE" > "$LOG_FILE.tmp"
        mv "$LOG_FILE.tmp" "$LOG_FILE"
    fi
}

# Проверка на уже запущенный процесс
if [[ -f "$LOCK_FILE" ]]; then
    pid=$(cat "$LOCK_FILE")
    if kill -0 "$pid" 2>/dev/null; then
        exit 0
    fi
fi
echo $$ > "$LOCK_FILE"
trap "rm -f $LOCK_FILE" EXIT

# Читаем настройки
vpnchecker="true"
autoupvpn="true"

if [[ -f "$SETTINGS_FILE" ]]; then
    if grep -q "vpnchecker=false" "$SETTINGS_FILE"; then
        vpnchecker="false"
    fi
    if grep -q "autoupvpn=false" "$SETTINGS_FILE"; then
        autoupvpn="false"
    fi
fi

# Если мониторинг выключен - выходим
if [[ "$vpnchecker" == "false" ]]; then
    exit 0
fi

# Определяем тип VPN
VPN_TYPE=""
VPN_SERVICE=""

if [[ -f /etc/wireguard/tun0.conf ]]; then
    VPN_TYPE="wireguard"
    VPN_SERVICE="wg-quick@tun0"
elif [[ -f /etc/openvpn/tun0.conf ]]; then
    VPN_TYPE="openvpn"
    VPN_SERVICE="openvpn@tun0"
else
    log "WARN: Конфигурация VPN не найдена"
    exit 0
fi

# Функция перезапуска VPN
restart_vpn() {
    if [[ "$autoupvpn" == "true" ]]; then
        log "ACTION: Перезапуск $VPN_SERVICE..."
        
        # Останавливаем
        systemctl stop "$VPN_SERVICE" 2>/dev/null
        sleep 2
        
        # Для WireGuard дополнительно очищаем интерфейс
        if [[ "$VPN_TYPE" == "wireguard" ]]; then
            ip link delete tun0 2>/dev/null || true
        fi
        
        # Запускаем
        systemctl start "$VPN_SERVICE" 2>/dev/null
        
        # Ждём поднятия
        sleep 5
        
        # Проверяем результат
        if ip link show tun0 &>/dev/null; then
            log "SUCCESS: VPN перезапущен успешно"
            return 0
        else
            log "ERROR: VPN не поднялся после перезапуска"
            return 1
        fi
    else
        log "WARN: Автоперезапуск отключен в настройках"
        return 1
    fi
}

# ПРОВЕРКА 1: Существует ли интерфейс tun0?
if ! ip link show tun0 &>/dev/null; then
    log "ERROR: Интерфейс tun0 не существует"
    restart_vpn
    exit 1
fi

# ПРОВЕРКА 2: Интерфейс UP?
if ! ip link show tun0 | grep -q "state UP\|state UNKNOWN"; then
    log "ERROR: Интерфейс tun0 не в состоянии UP"
    restart_vpn
    exit 1
fi

# ПРОВЕРКА 3: Есть ли IP на интерфейсе?
if ! ip addr show tun0 | grep -q "inet "; then
    log "ERROR: На tun0 нет IP адреса"
    restart_vpn
    exit 1
fi

# ПРОВЕРКА 4: Ping через VPN
PING_HOSTS="8.8.8.8 1.1.1.1 208.67.222.222"
PING_SUCCESS=false

for host in $PING_HOSTS; do
    if ping -c 1 -W 3 -I tun0 "$host" &>/dev/null; then
        PING_SUCCESS=true
        break
    fi
done

if [[ "$PING_SUCCESS" == "false" ]]; then
    log "ERROR: Ping через tun0 не проходит ни к одному хосту"
    restart_vpn
    exit 1
fi

# Всё ОК
exit 0
HEALTHCHECK
    chmod +x /usr/local/bin/vpn-healthcheck.sh
    
    # --- SYSTEMD SERVICE ---
    cat > /etc/systemd/system/vpn-healthcheck.service << 'SERVICE'
[Unit]
Description=VPN Health Check
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/vpn-healthcheck.sh
TimeoutSec=60
SERVICE
    
    # --- SYSTEMD TIMER (каждые 15 секунд для быстрой реакции) ---
    cat > /etc/systemd/system/vpn-healthcheck.timer << 'TIMER'
[Unit]
Description=Run VPN Health Check frequently

[Timer]
OnBootSec=30
OnUnitActiveSec=15
AccuracySec=5

[Install]
WantedBy=timers.target
TIMER
    
    # --- ОБНОВЛЕНИЕ LAUNCHER ---
    cat > /usr/local/bin/mineserver-update.sh << 'LAUNCHER'
#!/bin/bash
set -e

cd /var/www/html || exit 1

# Сохраняем локальные изменения
git stash 2>/dev/null || true

# Обновляем
git fetch origin 2>/dev/null
git reset --hard origin/main 2>/dev/null || git reset --hard origin/master 2>/dev/null
git clean -df 2>/dev/null

# Удаляем .git чтобы не было проблем
rm -rf .git 2>/dev/null || true

# Запускаем update.sh если есть
if [[ -f update.sh ]]; then
    chmod +x update.sh
    ./update.sh
fi

# Перезагружаем Apache для применения изменений PHP
systemctl reload apache2 2>/dev/null || true
LAUNCHER
    chmod +x /usr/local/bin/mineserver-update.sh
    
    # --- СОЗДАНИЕ ДИРЕКТОРИЙ ---
    mkdir -p /var/www/data
    chown www-data:www-data /var/www/data
    chmod 755 /var/www/data
    
    mkdir -p /var/log
    touch /var/log/vpn-healthcheck.log
    chmod 666 /var/log/vpn-healthcheck.log
    
    # --- ПРИМЕНЯЕМ ИЗМЕНЕНИЯ ---
    systemctl daemon-reload
    systemctl enable vpn-healthcheck.timer
    systemctl restart vpn-healthcheck.timer
    
    # Перезапуск Apache чтобы применились группы www-data
    systemctl restart apache2 2>/dev/null || true
    
    # Обновляем cron
    (crontab -l 2>/dev/null | grep -v "run-update.sh\|mineserver-update.sh\|update.sh"; \
     echo "0 4 * * * /usr/local/bin/mineserver-update.sh >> /var/log/mineserver-update.log 2>&1") | crontab -
    
    log INFO "Миграция v5.1 завершена!"
    set_version "5.1"
}

# ==============================================================================
# ОСНОВНАЯ ЛОГИКА
# ==============================================================================

main() {
    log INFO "========== Запуск обновления =========="
    
    current_version=$(get_version)
    log INFO "Текущая версия: $current_version"
    
    # Конвертируем версию в число для сравнения
    current_num=$(echo "$current_version" | tr -d '.')
    
    if [[ "$current_version" == "1" ]] || [[ "$current_num" -lt 2 ]]; then
        migrate_v1_to_v2
    fi
    
    current_version=$(get_version)
    current_num=$(echo "$current_version" | tr -d '.')
    
    if [[ "$current_num" -lt 3 ]]; then
        migrate_v2_to_v3
    fi
    
    current_version=$(get_version)
    current_num=$(echo "$current_version" | tr -d '.')
    
    if [[ "$current_num" -lt 4 ]]; then
        migrate_v3_to_v4
    fi
    
    current_version=$(get_version)
    current_num=$(echo "$current_version" | tr -d '.')
    
    if [[ "$current_num" -lt 5 ]]; then
        migrate_v4_to_v5
    fi
    
    current_version=$(get_version)
    
    # v5.1 миграция (всегда применяем если версия 5 или меньше 5.1)
    if [[ "$current_version" == "5" ]] || [[ "$current_version" < "5.1" ]]; then
        migrate_v5_to_v51
    fi
    
    final_version=$(get_version)
    log INFO "Финальная версия: $final_version"
    log INFO "========== Обновление завершено =========="
}

main "$@"
