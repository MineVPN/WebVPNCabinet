#!/bin/bash
# -----------------------------------------------------------------
# --- ГЛАВНАЯ ВЕРСИЯ СКРИПТА ---
# Версия 2 - специальная, для выполнения одноразового перехода на новый загрузчик.
SCRIPT_VERSION=2
# -----------------------------------------------------------------

echo "Обновляю репозиторий кода..."
cd /var/www/html/
sudo git pull origin main
cd
echo "Репозиторий обновлен."


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
sudo git pull origin main
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


    # --- ЗАВЕРШЕНИЕ ---
    # Обновляем файл версии до самой последней после применения всех обновлений
    echo "Все обновления применены. Обновляю файл версии с $CURRENT_VERSION до $SCRIPT_VERSION."
    echo "$SCRIPT_VERSION" | sudo tee "$VERSION_FILE" > /dev/null

else
    echo "Текущая версия ($CURRENT_VERSION) уже актуальна. Обновление не требуется."
fi

echo "Скрипт завершил работу."
