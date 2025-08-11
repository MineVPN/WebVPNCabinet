cd /var/www/html/
sudo git pull origin main
cd
sudo chmod 666 /etc/netplan/01-network-manager-all.yaml
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y php-yaml

# Проверяем, существует ли уже нужная строка в файле sudoers
if ! grep -q "www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop openvpn\*, /bin/systemctl start openvpn\*" /etc/sudoers; then
    # Если строки нет, добавляем ее
    echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop openvpn*, /bin/systemctl start openvpn*" >> /etc/sudoers
fi


# Проверяем, существует ли уже нужная строка в файле sudoers
if ! grep -q "www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop wg-quick\*, /bin/systemctl start wg-quick\*" /etc/sudoers; then
    # Если строки нет, добавляем ее
    echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop wg-quick*, /bin/systemctl start wg-quick*" >> /etc/sudoers
fi


# Проверяем, существует ли уже нужная строка в файле sudoers
if ! grep -q "www-data ALL=(ALL) NOPASSWD: /bin/systemctl enable wg-quick\*, /bin/systemctl disable wg-quick\*" /etc/sudoers; then
    # Если строки нет, добавляем ее
    echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl enable wg-quick*, /bin/systemctl disable wg-quick*" >> /etc/sudoers
fi


# Проверяем, существует ли уже нужная строка в файле sudoers
if ! grep -q "www-data ALL=(root) NOPASSWD: /usr/bin/id" /etc/sudoers; then
    # Если строки нет, добавляем ее
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/id" >> /etc/sudoers
fi

if ! grep -q "www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan try, /usr/sbin/netplan apply" /etc/sudoers; then
    # Если строки нет, добавляем ее
    echo "www-data ALL=(ALL) NOPASSWD: /usr/sbin/netplan try, /usr/sbin/netplan apply" >> /etc/sudoers
fi

# Определяем только параметры правила, БЕЗ флага -A (добавить)
RULE_PARAMS="FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu"

# Проверяем наличие правила, используя только его параметры
if ! sudo iptables -C $RULE_PARAMS &> /dev/null; then
    # Правила нет, поэтому теперь мы используем флаг -A для его ДОБАВЛЕНИЯ
    echo "Правило MSS clamp не найдено. Добавляю..."
    sudo iptables -A $RULE_PARAMS
    
    # Сохраняем конфигурацию, чтобы правило пережило перезагрузку
    echo "Сохраняю правила iptables..."
    sudo iptables-save | sudo tee /etc/iptables/rules.v4 > /dev/null
else
    # Правило уже есть, ничего не делаем
    echo "Правило MSS clamp уже существует. Ничего не делаю."
fi
