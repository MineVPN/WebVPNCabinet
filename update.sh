cd /var/www/html/
sudo git pull origin main

#test comment

# Проверяем, существует ли уже нужная строка в файле sudoers
if ! grep -q "www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop openvpn\*, /bin/systemctl start openvpn\*" /etc/sudoers; then
    # Если строки нет, добавляем ее
    echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop openvpn*, /bin/systemctl start openvpn*" >> /etc/sudoers
fi

