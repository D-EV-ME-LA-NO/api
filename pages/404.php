<?php
$page_title = 'Not Found';
$active     = '';
include __DIR__ . '/../includes/header.php';
?>
<section class="empty-state">
  <i class="fa-solid fa-circle-exclamation"></i>
  <h1>404</h1>
  <p>The page you’re looking for doesn’t exist.</p>
  <a class="btn btn-primary" href="/">Go Home</a>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
