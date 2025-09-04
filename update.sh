#!/bin/bash
# -----------------------------------------------------------------
# --- ГЛАВНАЯ ВЕРСИЯ СКРИПТА ---
SCRIPT_VERSION=3
# -----------------------------------------------------------------

echo "Обновляю репозиторий кода..."
cd /var/www/html/
sudo git pull origin main
cd
echo "Репозиторий обновлен."

chmod 777 /var/www/html/settings


# --- Путь к файлу, где хранится текущая версия на сервере ---
VERSION_FILE="/var/www/version"

# =================================================================
#  ЛОГИКА ПРОВЕРКИ И ПРИМЕНЕНИЯ ВЕРСИЙ
# =================================================================

# Читаем текущую установленную версию. Если файл не существует или пуст, считаем версию равной 0.
CURRENT_VERSION=0
if [ -f "$VERSION_FILE" ]; then
    if [ -s "$VERSION_FILE" ]; then
        CURRENT_VERSION=$(cat "$VERSION_FILE")
    fi
fi

# Проверяем, нужно ли вообще что-то делать
if [ "$CURRENT_VERSION" -lt "$SCRIPT_VERSION" ]; then
    echo "Текущая версия ($CURRENT_VERSION) устарела. Применяю обновления до версии ($SCRIPT_VERSION)..."

    # -----------------------------------------------------------------
    # --- ОБНОВЛЕНИЕ ДО ВЕРСИИ 1 ---
    # Базовая настройка для очень старых или новых серверов.
    # -----------------------------------------------------------------
    if [ "$CURRENT_VERSION" -lt 1 ]; then
        echo "-> Применяю обновления для версии 1 (базовая настройка)..."
        
        sudo chmod 666 /etc/netplan/01-network-manager-all.yaml
        sudo DEBIAN_FRONTEND=noninteractive apt-get install -y php-yaml

        if ! grep -q "www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop openvpn\*, /bin/systemctl start openvpn\*" /etc/sudoers; then
            echo "   Добавляю правило sudoers для openvpn..."
            echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop openvpn*, /bin/systemctl start openvpn*" >> /etc/sudoers
        fi
        if ! grep -q "www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop wg-quick\*, /bin/systemctl start wg-quick\*" /etc/sudoers; then
            echo "   Добавляю правило sudoers для wg start/stop..."
            echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop wg-quick*, /bin/systemctl start wg-quick*" >> /etc/sudoers
        fi
        if ! grep -q "www-data ALL=(ALL) NOPASSWD: /bin/systemctl enable wg-quick\*, /bin/systemctl disable wg-quick\*" /etc/sudoers; then
            echo "   Добавляю правило sudoers для wg enable/disable..."
            echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl enable wg-quick*, /bin/systemctl disable wg-quick*" >> /etc/sudoers
        fi
        if ! grep -q "www-data ALL=(root) NOPASSWD: /usr/bin/id" /etc/sudoers; then
            echo "   Добавляю правило sudoers для id..."
            echo "www-data ALL=(root) NOPASSWD: /usr/bin/id" >> /etc/sudoers
        fi
        if ! grep -q "www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan try, /usr/sbin/netplan apply" /etc/sudoers; then
            echo "   Добавляю правило sudoers для netplan..."
            echo "www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan try, /usr/sbin/netplan apply" >> /etc/sudoers
        fi

        RULE_PARAMS="FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu"
        if ! sudo iptables -C $RULE_PARAMS &> /dev/null; then
            echo "   Правило MSS clamp не найдено. Добавляю..."
            sudo iptables -A $RULE_PARAMS
            echo "   Сохраняю правила iptables..."
            sudo iptables-save | sudo tee /etc/iptables/rules.v4 > /dev/null
        else
            echo "   Правило MSS clamp уже существует."
        fi
    fi

    # -----------------------------------------------------------------
    # --- ОБНОВЛЕНИЕ ДО ВЕРСИИ 2 (ОДНОРАЗОВЫЙ ПЕРЕХОД) ---
    # Этот блок создаст загрузчик и изменит cron. Он выполнится один раз и больше никогда.
    # -----------------------------------------------------------------
    if [ "$CURRENT_VERSION" -lt 2 ]; then
        LAUNCHER_PATH="/usr/local/bin/run-update.sh"
        echo "-> Применяю обновления для версии 2: Переход на загрузчик в $LAUNCHER_PATH..."

        # 1. Создаем файл загрузчика
        echo "   Создаю $LAUNCHER_PATH..."
        sudo tee $LAUNCHER_PATH > /dev/null << 'EOF'
#!/bin/bash
cd /var/www/html/ || exit
echo "Обновляем ЛК..."
sudo git fetch origin
sudo git reset --hard origin/main
sudo git clean -df
sudo chmod +x /var/www/html/update.sh
echo "Запускаем скрипт обновления update.sh..."
/var/www/html/update.sh
EOF
        
        # 2. Делаем загрузчик исполняемым
        echo "   Делаю загрузчик исполняемым..."
        sudo chmod +x $LAUNCHER_PATH

        # 3. Меняем запись в crontab
        # Эта команда безопасно заменяет старую строку на новую
        OLD_CRON_COMMAND="/var/www/html/update.sh"
        NEW_CRON_COMMAND="$LAUNCHER_PATH"
        CRON_JOB="0 4 * * * $NEW_CRON_COMMAND"
        echo "   Обновляю crontab..."
        (crontab -l 2>/dev/null | grep -v "$OLD_CRON_COMMAND"; echo "$CRON_JOB") | crontab -
        echo "   Переход успешно выполнен!"
    fi

    # -----------------------------------------------------------------
    # --- ОБНОВЛЕНИЕ ДО ВЕРСИИ 3 ---
    # Базовая настройка для очень старых или новых серверов.
    # -----------------------------------------------------------------

    if [ "$CURRENT_VERSION" -lt 3 ]; then
        chmod 777 /var/www/html/settings
        # --- ШАГ 1: Создание скрипта проверки (установка) ---
    echo "⚙️  Создание универсального скрипта проверки в /usr/local/bin/vpn-healthcheck.sh..."
cat > /usr/local/bin/vpn-healthcheck.sh << 'EOF'
#!/bin/bash

# --- Конфигурация ---
INTERFACE="tun0"
SETTINGS_FILE="/var/www/html/settings"
IP_CHECK_SERVICE="ifconfig.me"

# --- Функции ---
log() {
    logger -t VPNCheck "$1"
    echo "$1"
}

# --- Основная логика ---

# 1. Проверяем, разрешена ли проверка в файле настроек.
if [ -f "$SETTINGS_FILE" ] && ! grep -q "^vpnchecker=true$" "$SETTINGS_FILE" 2>/dev/null; then
    exit 0 # Проверка выключена, тихо выходим
fi

# 2. Убедимся, что интерфейс tun0 вообще существует. Если нет, нет смысла продолжать.
if ! ip link show "$INTERFACE" > /dev/null 2>&1; then
    #log "Интерфейс ${INTERFACE} не активен. Проверка невозможна."
    # Можно добавить перезапуск, но лучше дождаться, когда служба поднимет его сама
    exit 1
fi

# 3. ДИНАМИЧЕСКАЯ ПРОВЕРКА МАРШРУТИЗАЦИИ (по вашей идее)
# Получаем публичный IP через маршрут по умолчанию
DEFAULT_ROUTE_IP=$(curl -s --max-time 5 "$IP_CHECK_SERVICE")

# Получаем публичный IP, принудительно используя интерфейс tun0
TUN0_ROUTE_IP=$(curl -s --interface "$INTERFACE" --max-time 5 "$IP_CHECK_SERVICE")

# 4. Анализ результатов
# Сначала проверяем, удалось ли вообще получить IP
if [[ -z "$DEFAULT_ROUTE_IP" || -z "$TUN0_ROUTE_IP" ]]; then
    #log "Не удалось получить один или оба IP-адреса для сравнения. Возможно, полное отсутствие интернета."
    # Определяем, какой сервис перезапускать
    if [ -f "/etc/wireguard/${INTERFACE}.conf" ]; then
        #log "Перезапускаем WireGuard (wg-quick@${INTERFACE})..."
        systemctl restart "wg-quick@${INTERFACE}"
    elif [ -f "/etc/openvpn/${INTERFACE}.conf" ]; then
        #log "Перезапускаем OpenVPN (openvpn@${INTERFACE})..."
        systemctl restart "openvpn@${INTERFACE}"
    fi
    exit 1
fi

# Теперь главная проверка: сравниваем IP
if [[ "$DEFAULT_ROUTE_IP" != "$TUN0_ROUTE_IP" ]]; then
    #log "ОБНАРУЖЕНА УТЕЧКА МАРШРУТА!"
    #log "   -> IP по умолчанию: $DEFAULT_ROUTE_IP (неправильный)"
    #log "   -> IP через tun0: $TUN0_ROUTE_IP (правильный)"
    
    # Определяем, какой сервис перезапускать
    if [ -f "/etc/wireguard/${INTERFACE}.conf" ]; then
        #log "Перезапускаем WireGuard для исправления маршрутизации..."
        systemctl restart "wg-quick@${INTERFACE}"
    elif [ -f "/etc/openvpn/${INTERFACE}.conf" ]; then
        #log "Перезапускаем OpenVPN для исправления маршрутизации..."
        systemctl restart "openvpn@${INTERFACE}"
    fi
    exit 1
else
    #log "Проверка пройдена. Маршрутизация в порядке (Публичный IP: $DEFAULT_ROUTE_IP)."
    exit 0
fi
EOF

    # --- Установка прав на выполнение скрипта ---
    chmod +x /usr/local/bin/vpn-healthcheck.sh
    echo "✅  Скрипт создан и сделан исполняемым."

    # --- Шаги 2, 3, 4: Установка службы и таймера ---
    echo "⚙️  Создание файла службы /etc/systemd/system/vpn-healthcheck.service..."
cat > /etc/systemd/system/vpn-healthcheck.service << 'EOF'
[Unit]
Description=VPN Health Check Service
After=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/vpn-healthcheck.sh
EOF
    echo "✅  Файл службы создан."

    echo "⚙️  Создание файла таймера /etc/systemd/system/vpn-healthcheck.timer..."
cat > /etc/systemd/system/vpn-healthcheck.timer << 'EOF'
[Unit]
Description=Run VPN Health Check Service periodically

[Timer]
OnBootSec=1min
OnUnitActiveSec=30s
Unit=vpn-healthcheck.service

[Install]
WantedBy=timers.target
EOF
    echo "✅  Файл таймера создан."

    echo "🚀  Перезагрузка systemd, включение и запуск таймера..."
    systemctl daemon-reload
    systemctl stop vpn-healthcheck.timer >/dev/null 2>&1
    systemctl enable --now vpn-healthcheck.timer

    # --- Финальное сообщение ---
    echo ""
    echo "🎉 Готово! Установка универсальной службы проверки VPN завершены."
    echo ""

    fi


    # --- ЗАВЕРШЕНИЕ ---
    # Обновляем файл версии до самой последней после применения всех обновлений
    echo "Все обновления применены. Обновляю файл версии с $CURRENT_VERSION до $SCRIPT_VERSION."
    echo "$SCRIPT_VERSION" | sudo tee "$VERSION_FILE" > /dev/null

else
    echo "Текущая версия ($CURRENT_VERSION) уже актуальна. Обновление не требуется."
fi

echo "Скрипт завершил работу."
