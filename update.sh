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

# Определяем правило, которое нужно проверить/добавить
IPTABLES_RULE="-A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu"

# Проверяем, существует ли уже это правило в цепочке FORWARD
if ! sudo iptables -C $IPTABLES_RULE &> /dev/null; then
    # Если команда проверки завершилась с ошибкой (код не 0), значит, правила нет.
    echo "Правило MSS clamp не найдено. Добавляю..."
    sudo iptables $IPTABLES_RULE
    
    # После добавления правила, сохраняем текущую конфигурацию iptables,
    # чтобы правило пережило перезагрузку.
    echo "Сохраняю правила iptables..."
    sudo iptables-save | sudo tee /etc/iptables/rules.v4
else
    # Если команда проверки завершилась успешно (код 0), значит, правило уже есть.
    echo "Правило MSS clamp уже существует. Ничего не делаю."
fi
