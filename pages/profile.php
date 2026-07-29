<?php
$page_title = 'حسابي';
$active     = 'profile';

$user = auth_user();
if (!$user) { header('Location: /login'); exit; }

// Reload fresh avatar from DB into session if needed
$db_user = db_get_user($user['id']);
if ($db_user && !empty($db_user['avatar']) && ($user['avatar'] ?? '') !== $db_user['avatar']) {
    $_SESSION['user']['avatar'] = $db_user['avatar'];
    $user = auth_user();
}

$saved       = db_get_saved($user['id']);
$my_comments = db_get_comments_by_user($user['id']);
$avatar_path = $user['avatar'] ?? '';
$has_avatar  = $avatar_path && file_exists(__DIR__ . '/..' . $avatar_path);

include __DIR__ . '/../includes/header.php';
?>
<div class="browse">

  <!-- ===== Profile Header ===== -->
  <div class="profile-header">
    <div class="profile-avatar-wrap">
      <div class="profile-avatar" id="profileAvatarWrap">
        <?php if ($has_avatar): ?>
          <img id="avatarImg" src="<?= htmlspecialchars($avatar_path) ?>?v=<?= filemtime(__DIR__ . '/..' . $avatar_path) ?>" alt="avatar" />
        <?php else: ?>
          <span id="avatarLetter"><?= mb_strtoupper(mb_substr($user['username'], 0, 1)) ?></span>
        <?php endif; ?>
        <label class="avatar-edit-btn" for="avatarInput" title="تغيير الصورة">
          <i class="fa-solid fa-camera"></i>
        </label>
      </div>
      <input type="file" id="avatarInput" accept="image/jpeg,image/png,image/webp,image/gif" hidden />
      <div id="avatarMsg" class="avatar-msg" hidden></div>
    </div>

    <div class="profile-info">
      <h1 class="profile-name"><?= htmlspecialchars($user['username']) ?></h1>
      <p class="profile-email"><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($user['email']) ?></p>
    </div>

    <a href="/logout" class="btn btn-ghost" style="margin-top:auto;">
      <i class="fa-solid fa-right-from-bracket"></i> تسجيل الخروج
    </a>
  </div>

  <!-- ===== Stats ===== -->
  <div class="profile-stats">
    <div class="stat-card">
      <span class="stat-num"><?= count($saved) ?></span>
      <span class="stat-lbl">المحفوظات</span>
    </div>
    <div class="stat-card">
      <span class="stat-num"><?= count($my_comments) ?></span>
      <span class="stat-lbl">التعليقات</span>
    </div>
  </div>

  <!-- ===== Saved ===== -->
  <?php if ($saved): ?>
    <h2 class="section-title"><i class="fa-solid fa-bookmark" style="color:var(--primary)"></i> قائمتي المحفوظة</h2>
    <div class="grid" style="margin-bottom:40px;">
      <?php foreach ($saved as $it):
        $href = '/' . ($it['type'] === 'tv' ? 'tv-show' : 'movie') . '/' . $it['tmdb_id'] . '-' . slugify($it['title']);
      ?>
        <a class="card card-grid" href="<?= $href ?>">
          <div class="card-poster">
            <img loading="lazy" src="<?= htmlspecialchars($it['poster'] ?? '') ?>" alt="<?= htmlspecialchars($it['title']) ?>" />
            <div class="card-overlay"><div class="play-circle"><i class="fa-solid fa-play"></i></div></div>
            <span class="card-type"><?= $it['type'] === 'tv' ? 'TV' : 'Movie' ?></span>
          </div>
          <div class="card-meta"><h3><?= htmlspecialchars($it['title']) ?></h3></div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty-state" style="padding:60px 20px;">
      <i class="fa-solid fa-bookmark"></i>
      <h3>لا توجد محفوظات بعد</h3>
      <p>أضف أفلامك ومسلسلاتك المفضلة لتجدها هنا</p>
      <a href="/" class="btn btn-primary">تصفح الآن</a>
    </div>
  <?php endif; ?>

  <!-- ===== Comments ===== -->
  <?php if ($my_comments): ?>
    <h2 class="section-title"><i class="fa-solid fa-comments" style="color:var(--primary)"></i> تعليقاتي</h2>
    <div class="comments-list" style="margin-bottom:60px;">
      <?php foreach ($my_comments as $c): ?>
        <div class="comment-card">
          <div class="comment-top">
            <span class="comment-user"><i class="fa-solid fa-circle-user"></i> <?= htmlspecialchars($c['username']) ?></span>
            <span class="comment-rating"><?= str_repeat('★', (int)$c['rating']) ?><?= str_repeat('☆', 5-(int)$c['rating']) ?></span>
            <span class="comment-date"><?= date('Y/m/d', (int)$c['created_at']) ?></span>
          </div>
          <p class="comment-body"><?= nl2br(htmlspecialchars($c['body'])) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  const input = document.getElementById('avatarInput');
  const msg   = document.getElementById('avatarMsg');
  const wrap  = document.getElementById('profileAvatarWrap');

  if (!input) return;

  input.addEventListener('change', async () => {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 3 * 1024 * 1024) { showMsg('حجم الصورة يجب أن يكون أقل من 3 MB', 'err'); return; }

    showMsg('جارٍ الرفع...', 'info');
    const fd = new FormData();
    fd.append('avatar', file);

    try {
      const r = await fetch('/api/avatar', { method: 'POST', body: fd });
      const j = await r.json();
      if (j.ok) {
        showMsg('تم تغيير الصورة بنجاح ✓', 'ok');
        // Update all avatars on page
        const url = j.avatar;
        let img = document.getElementById('avatarImg');
        if (!img) {
          img = document.createElement('img');
          img.id = 'avatarImg';
          const letter = document.getElementById('avatarLetter');
          if (letter) letter.replaceWith(img);
          else wrap.querySelector('.profile-avatar').prepend(img);
        }
        img.src = url;
        // Update nav avatar too
        const navAvatar = document.querySelector('.nav-avatar-img');
        if (navAvatar) navAvatar.src = url;
        else {
          const navLetter = document.querySelector('.nav-avatar:not(img)');
          if (navLetter) {
            const ni = document.createElement('img');
            ni.className = 'nav-avatar nav-avatar-img';
            ni.src = url;
            ni.alt = 'avatar';
            navLetter.replaceWith(ni);
          }
        }
      } else {
        showMsg(j.error || 'حدث خطأ', 'err');
      }
    } catch(e) {
      showMsg('فشل الاتصال بالخادم', 'err');
    }
    input.value = '';
  });

  function showMsg(text, type) {
    msg.hidden = false;
    msg.textContent = text;
    msg.className = 'avatar-msg ' + type;
    if (type === 'ok') setTimeout(() => { msg.hidden = true; }, 3000);
  }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
