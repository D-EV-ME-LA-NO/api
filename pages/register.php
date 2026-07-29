<?php
$page_title = 'إنشاء حساب';
$active     = '';

if (auth_user()) { header('Location: /'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sec_csrf_verify()) {
        $error = 'طلب غير صالح. يرجى إعادة تحميل الصفحة والمحاولة مجدداً.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $pass     = $_POST['password'] ?? '';
        $pass2    = $_POST['password2'] ?? '';

        if (!$username || !$email || !$pass || !$pass2) {
            $error = 'يرجى تعبئة جميع الحقول';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'البريد الإلكتروني غير صالح';
        } elseif (strlen($pass) < 6) {
            $error = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل';
        } elseif ($pass !== $pass2) {
            $error = 'كلمتا المرور غير متطابقتين';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-\.]{3,30}$/', $username)) {
            $error = 'اسم المستخدم يجب أن يكون 3-30 حرفاً (أرقام وحروف إنجليزية فقط)';
        } else {
            try {
                if (db_username_exists($username)) {
                    $error = 'اسم المستخدم مستخدم بالفعل';
                } elseif (db_email_exists($email)) {
                    $error = 'البريد الإلكتروني مستخدم بالفعل';
                } else {
                    $user = db_create_user($username, $email, $pass);
                    auth_login_session($user);
                    // إعادة توليد CSRF بعد التسجيل
                    unset($_SESSION['_csrf']);
                    header('Location: /');
                    exit;
                }
            } catch (\Exception $e) {
                $error = 'خطأ في الخادم. يرجى المحاولة لاحقاً.';
            }
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
    <h1 class="auth-title">إنشاء حساب جديد</h1>

    <?php if ($error): ?>
      <div class="auth-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="auth-form">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(sec_csrf_token()) ?>">
      <div class="auth-field">
        <label>اسم المستخدم</label>
        <input type="text" name="username" required autocomplete="username" placeholder="username123"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" />
      </div>
      <div class="auth-field">
        <label>البريد الإلكتروني</label>
        <input type="email" name="email" required autocomplete="email" placeholder="example@mail.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" />
      </div>
      <div class="auth-field">
        <label>كلمة المرور</label>
        <input type="password" name="password" required autocomplete="new-password" placeholder="••••••••" />
      </div>
      <div class="auth-field">
        <label>تأكيد كلمة المرور</label>
        <input type="password" name="password2" required autocomplete="new-password" placeholder="••••••••" />
      </div>
      <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center;">
        <i class="fa-solid fa-user-plus"></i> إنشاء حساب
      </button>
    </form>
    <p class="auth-switch">لديك حساب بالفعل؟ <a href="/login">تسجيل الدخول</a></p>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
