<?php
// Этот скрипт проверяет только наличие интерфейса tun0
$status_output = shell_exec("ifconfig tun0 2>&1");

if (strpos($status_output, 'Device not found') === false) {
    echo 'connected';
} else {
    echo 'disconnected';
}
?>