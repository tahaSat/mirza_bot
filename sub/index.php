<?php
ini_set('error_log', 'error_log');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';

header('Content-Type: text/plain; charset=utf-8');

$url = $_SERVER['REQUEST_URI'] ?? '';
$parts = explode('/sub/', $url, 2);
$link = $parts[1] ?? '';
$token = preg_replace('/[^A-Za-z0-9_-]/', '', explode('?', $link)[0]);
if ($token === '') {
    echo 'ERROR!';
    exit;
}

$cacheDir = __DIR__ . '/../storage/cache';
$cacheFile = $cacheDir . '/sub_' . hash('sha256', $token) . '.txt';
$ttl = 90;

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    echo file_get_contents($cacheFile);
    exit;
}

$ManagePanel = new ManagePanel();
try {
    if (!isset($token)) {
        echo 'ERROR!';
        exit;
    }
    $nameloc = select('invoice', '*', 'id_invoice', $token, 'select');
    if (!$nameloc || empty($nameloc['username'])) {
        echo 'ERROR!';
        exit;
    }
    $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $nameloc['username']);
    if (!is_array($DataUserOut) || empty($DataUserOut['links']) || !is_array($DataUserOut['links'])) {
        if (is_file($cacheFile)) {
            echo file_get_contents($cacheFile);
            exit;
        }
        echo 'ERROR!';
        exit;
    }
    $config = '';
    foreach ($DataUserOut['links'] as $Links) {
        $config .= $Links . "\r\r";
    }
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    @file_put_contents($cacheFile, $config, LOCK_EX);
    echo $config;
} catch (Exception $e) {
    if (is_file($cacheFile)) {
        echo file_get_contents($cacheFile);
        exit;
    }
    echo 'Error!';
}
