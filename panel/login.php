<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';

panel_session_start();

$pdo = panel_ensure_pdo();

if (!empty($_SESSION['admin_user'])) {
  header('Location: index.php');
  exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check_post();

  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  $remember = !empty($_POST['remember']);
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

  if ($username === '' || $password === '') {
    $error = 'نام کاربری و رمز عبور را وارد کنید.';
  } elseif (!check_login_rate($ip)) {

    $error = 'تعداد تلاش‌های ناموفق بیش از حد. لطفاً ۱۵ دقیقه صبر کنید.';
    error_log("Login rate limit hit for IP: $ip username: $username");
  } else {

    $admin = select("admin", "*", "username", $username, "select");

    $dummyHash = '$2y$10$dummy.hash.for.timing.attack.prevention.xxxxxxxxxxxxxxxx';
    $storedHash = $admin ? $admin['password'] : $dummyHash;

    $isCorrect = false;
    if (password_verify($password, $storedHash)) {
      $isCorrect = true;
    } elseif ($admin && !password_needs_rehash($storedHash, PASSWORD_BCRYPT)) {

      if ($password === $storedHash) {
        $isCorrect = true;
      }
    } elseif ($admin) {

      if ($password === $admin['password']) {
        $isCorrect = true;
      }
    }

    if ($isCorrect && $admin) {

      if (!str_starts_with($admin['password'], '$2')) {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        update("admin", "password", $hash, "username", $username);
      }
      clear_login_rate($ip);
      session_regenerate_id(true);
      $_SESSION['admin_user'] = $admin['username'];
      $_SESSION['login_time'] = time();
      $_SESSION['csrf'] = bin2hex(random_bytes(32));

      if ($remember) {
        panel_enable_remember();
      } else {
        panel_clear_remember();
        // Session cookie only (cleared when browser closes)
        setcookie(session_name(), session_id(), panel_cookie_options(0));
      }

      flash('success', 'خوش آمدید، ' . $admin['username']);
      header('Location: index.php');
      exit;
    } else {
      $error = 'نام کاربری یا رمز عبور اشتباه است.';
      error_log("Failed login for username: $username from IP: $ip");
    }
  }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
  <meta name="theme-color" content="#07070a" id="mtc">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="manifest" href="/panel/manifest.webmanifest">
  <link rel="apple-touch-icon" href="/panel/icons/apple-touch-icon.png">
  <title>ورود — پیچا</title>
  <meta name="apple-mobile-web-app-title" content="پیچا">
  <link rel="icon" href="/panel/icons/icon-192.png" type="image/png">
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/login.css">
  <script>document.documentElement.setAttribute('data-theme', 'slate');</script>
</head>

<body>
  <div class="login-page">
    <div class="login-shell">
      <header class="login-brand">
        <img class="login-logo" src="/panel/icons/logo.png" width="96" height="96" alt="پیچا">
        <h1 class="login-name">پیچا</h1>
        <p class="login-name-en">picha</p>
        <p class="login-tagline">ورود به پنل مدیریت ربات</p>
      </header>

      <div class="login-form-wrap">
        <?php if ($error): ?>
          <div class="notice notice-no"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form class="login-form auth-form" method="POST" autocomplete="on">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <div class="login-field">
            <label for="username">نام کاربری</label>
            <input type="text" id="username" name="username" class="input" placeholder="admin"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autocomplete="username" required autofocus
              maxlength="100">
          </div>
          <div class="login-field">
            <label for="password">رمز عبور</label>
            <input type="password" id="password" name="password" class="input" placeholder="••••••••"
              autocomplete="current-password" required maxlength="200">
          </div>
          <label class="login-check" for="remember">
            <input type="checkbox" id="remember" name="remember" value="1"
              <?= (!isset($_POST['username']) || !empty($_POST['remember'])) ? 'checked' : '' ?>>
            <span>مرا به خاطر بسپار (۳۰ روز)</span>
          </label>
          <button type="submit" class="login-submit" id="loginBtn">
            <span id="loginText">ورود</span>
            <span class="login-spin" id="loginSpin"></span>
          </button>
        </form>
      </div>

      <p class="login-foot">© <?= date('Y') ?> · پیچا</p>
    </div>
  </div>
  <script src="js/login.js"></script>
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', function () {
        navigator.serviceWorker.register('/panel/sw.js', { scope: '/panel/' })
          .catch(function (error) {
            console.warn('Panel service worker registration failed:', error);
          });
      });
    }
  </script>
</body>

</html>
