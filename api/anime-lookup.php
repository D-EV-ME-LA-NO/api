<?php
/**
 * api/anime-lookup.php — إعادة توجيه إلى صفحة المشاهدة الجديدة
 * مسار الأنمي الجديد: /watch/anime/aw-{aw_id}/{episode}
 */
$aw_id   = (int)($_GET['aw_id']  ?? 0);
$episode = max(1, (int)($_GET['episode'] ?? 1));

if ($aw_id) {
    header('Location: /watch/anime/aw-' . $aw_id . '/' . $episode, true, 302);
} else {
    header('Location: /anime');
}
exit;
