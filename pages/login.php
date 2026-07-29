<?php
$page_title = 'تسجيل الدخول';
$active     = '';

if (auth_user()) { header('Location: /'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sec_csrf_verify()) {
        $error = 'طلب غير صالح. يرجى إعادة تحميل الصفحة والمحاولة مجدداً.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        if ($email && $pass) {
            try {
                $user = db_find_user_by_email_or_username($email);
                if ($user && password_verify($pass, $user['password'])) {
                    auth_login_session($user);
                    // إعادة توليد CSRF بعد الدخول
                    unset($_SESSION['_csrf']);
                    $redirect = $_GET['redirect'] ?? '/';
                    // منع Open Redirect
                    if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
                        $redirect = '/';
                    }
                    header('Location: ' . $redirect);
                    exit;
                } else {
                    $error = 'البريد أو كلمة المرور غير صحيحة';
                    sec_log('LOGIN_FAIL', ['hint' => substr($email, 0, 3) . '***']);
                }
            } catch (\Exception $e) {
                $error = 'خطأ في الخادم. يرجى المحاولة لاحقاً.';
            }
        } else {
            $error = 'يرجى تعبئة جميع الحقول';
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="auth-page">
  <div class="auth-box">
    <a href="/" class="brand" style="justify-content:center;margin-bottom:28px;">
      <span class="brand-text">HZ<span>Flix</span></span>
    </a>
    <h1 class="auth-title">تسجيل الدخول</h1>

    <?php if ($error): ?>
      <div class="auth-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="auth-form">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(sec_csrf_token()) ?>">
      <div class="auth-field">
        <label>البريد الإلكتروني أو اسم المستخدم</label>
        <input type="text" name="email" required autocomplete="email" placeholder="example@mail.com" />
      </div>
      <div class="auth-field">
        <label>كلمة المرور</label>
        <input type="password" name="password" required autocomplete="current-password" placeholder="••••••••" />
      </div>
      <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center;">
        <i class="fa-solid fa-right-to-bracket"></i> دخول
      </button>
    </form>
    <p class="auth-switch">ليس لديك حساب؟ <a href="/register">إنشاء حساب</a></p>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
