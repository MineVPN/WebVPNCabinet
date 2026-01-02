<?php
// Простой ping
$host = $_GET['host'] ?? '8.8.8.8';
$interface = $_GET['interface'] ?? '';

// Валидация
if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[a-z0-9.-]+$/i', $host)) {
    die('NO PING');
}

$cmd = 'ping -c 1 -W 2 ';
if ($interface && preg_match('/^[a-z0-9]+$/i', $interface)) {
    $cmd .= "-I $interface ";
}
$cmd .= escapeshellarg($host) . ' 2>/dev/null';

$output = shell_exec($cmd);

if ($output && preg_match('/time[=<]([\d.]+)\s*ms/i', $output, $m)) {
    echo $m[1];
} else {
    echo 'NO PING';
}
