<?php
require_once __DIR__ . '/lib.php';
app_session();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = isset($_POST['password']) ? $_POST['password'] : '';
    if (hash_equals(WEB_PASSWORD, $pw)) {
        session_regenerate_id(true);
        $_SESSION['authed'] = true;
        $_SESSION['last'] = time();
        header('Location: index.php');
        exit;
    }
    $error = 'Incorrect password.';
}
if (is_logged_in()) { header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in - Ordinal File Manager</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">
<form class="login-card" method="post" autocomplete="off">
  <h1>Ordinal File Manager</h1>
  <p class="muted">Sign in to manage your connected AIs.</p>
  <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <input type="password" name="password" placeholder="Password" autofocus required>
  <button type="submit">Sign in</button>
</form>
</body>
</html>
