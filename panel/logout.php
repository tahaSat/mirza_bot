<?php
require_once __DIR__ . '/inc/config.php';

panel_logout();
header('Location: login.php');
exit;
