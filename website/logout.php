<?php
require_once __DIR__ . '/lib.php';
app_session();
session_unset();
session_destroy();
header('Location: login.php');
exit;
