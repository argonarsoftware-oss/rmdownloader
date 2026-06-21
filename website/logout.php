<?php
require_once __DIR__ . '/lib.php';
session_unset();
session_destroy();
header('Location: login.php');
exit;
