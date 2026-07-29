<?php
// includes/db.php — JSON-based storage (replaces MongoDB)

define('DATA_DIR', __DIR__ . '/../data');

// ─────────────────────────────────────────────
//  Low-level JSON helpers
// ─────────────────────────────────────────────

function json_read(string $file): array {
    $path = DATA_DIR . '/' . $file;
    if (!is_file($path)) return [];
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function json_write(string $file, array $data): void {
    $path = DATA_DIR . '/' . $file;
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0777, true);
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function gen_id(): string {
    return bin2hex(random_bytes(12));
}

// ─────────────────────────────────────────────
//  mongo_* compatibility layer (used in views.php)
// ─────────────────────────────────────────────

function mongo_find_one(string $collection, array $filter): ?array {
    $rows = json_read($collection . '.json');
    foreach ($rows as $row) {
        $match = true;
        foreach ($filter as $k => $v) {
            if (($row[$k] ?? null) != $v) { $match = false; break; }
        }
        if ($match) return $row;
    }
    return null;
}

function mongo_insert(string $collection, array $doc): void {
    $rows = json_read($collection . '.json');
    if (!isset($doc['_id'])) $doc['_id'] = gen_id();
    $rows[] = $doc;
    json_write($collection . '.json', $rows);
}

function mongo_update(string $collection, array $filter, array $update, bool $upsert = false): void {
    $rows  = json_read($collection . '.json');
    $found = false;

    foreach ($rows as &$row) {
        $match = true;
        foreach ($filter as $k => $v) {
            if (($row[$k] ?? null) != $v) { $match = false; break; }
        }
        if (!$match) continue;
        $found = true;
        // Handle $inc
        if (isset($update['$inc'])) {
            foreach ($update['$inc'] as $k => $v) {
                $row[$k] = (int)($row[$k] ?? 0) + (int)$v;
            }
        }
        // Handle $set
        if (isset($update['$set'])) {
            foreach ($update['$set'] as $k => $v) {
                $row[$k] = $v;
            }
        }
        // Plain field update (no operator)
        if (!isset($update['$inc']) && !isset($update['$set'])) {
            foreach ($update as $k => $v) {
                $row[$k] = $v;
            }
        }
    }
    unset($row);

    if (!$found && $upsert) {
        $doc = $filter;
        if (!isset($doc['_id'])) $doc['_id'] = gen_id();
        if (isset($update['$inc'])) {
            foreach ($update['$inc'] as $k => $v) {
                $doc[$k] = (int)$v;
            }
        }
        if (isset($update['$set'])) {
            foreach ($update['$set'] as $k => $v) {
                $doc[$k] = $v;
            }
        }
        $rows[] = $doc;
    }

    json_write($collection . '.json', $rows);
}

// ─────────────────────────────────────────────
//  Auth helpers
// ─────────────────────────────────────────────

function auth_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function auth_login_session(array $user): void {
    // منع Session Fixation: نجدد session ID عند كل تسجيل دخول ناجح
    sec_session_rotate();
    $_SESSION['user'] = [
        'id'       => $user['id'],
        'username' => $user['username'],
        'email'    => $user['email'],
        'avatar'   => $user['avatar'] ?? '',
    ];
}

function auth_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
    session_destroy();
}

// ─────────────────────────────────────────────
//  Users
// ─────────────────────────────────────────────

function db_find_user_by_email_or_username(string $value): ?array {
    $users = json_read('users.json');
    $value_lower = strtolower($value);
    foreach ($users as $u) {
        if (strtolower($u['email'] ?? '') === $value_lower) return $u;
        if (strtolower($u['username'] ?? '') === $value_lower) return $u;
    }
    return null;
}

function db_username_exists(string $username): bool {
    $users = json_read('users.json');
    $lower = strtolower($username);
    foreach ($users as $u) {
        if (strtolower($u['username'] ?? '') === $lower) return true;
    }
    return false;
}

function db_email_exists(string $email): bool {
    $users = json_read('users.json');
    $lower = strtolower($email);
    foreach ($users as $u) {
        if (strtolower($u['email'] ?? '') === $lower) return true;
    }
    return false;
}

function db_create_user(string $username, string $email, string $password): array {
    $users = json_read('users.json');
    $user  = [
        'id'         => gen_id(),
        'username'   => $username,
        'email'      => $email,
        'password'   => password_hash($password, PASSWORD_DEFAULT),
        'avatar'     => '',
        'created_at' => time(),
    ];
    $users[] = $user;
    json_write('users.json', $users);
    return $user;
}

function db_get_user(string $id): ?array {
    $users = json_read('users.json');
    foreach ($users as $u) {
        if (($u['id'] ?? '') === $id) return $u;
    }
    return null;
}

function db_update_user_avatar(string $id, string $avatar_path): void {
    $users = json_read('users.json');
    foreach ($users as &$u) {
        if (($u['id'] ?? '') === $id) {
            $u['avatar'] = $avatar_path;
            break;
        }
    }
    unset($u);
    json_write('users.json', $users);
}

// ─────────────────────────────────────────────
//  Comments
// ─────────────────────────────────────────────

function db_get_comments(int $tmdb_id, string $type): array {
    $all = json_read('comments.json');
    $out = [];
    foreach ($all as $c) {
        if ((int)($c['tmdb_id'] ?? 0) === $tmdb_id && ($c['type'] ?? '') === $type) {
            $out[] = $c;
        }
    }
    usort($out, fn($a, $b) => (int)($b['created_at'] ?? 0) - (int)($a['created_at'] ?? 0));
    return $out;
}

function db_add_comment(string $user_id, string $username, int $tmdb_id, string $type, string $body, int $rating): array {
    $all = json_read('comments.json');
    $comment = [
        'id'         => gen_id(),
        'user_id'    => $user_id,
        'username'   => $username,
        'tmdb_id'    => $tmdb_id,
        'type'       => $type,
        'body'       => $body,
        'rating'     => $rating,
        'created_at' => time(),
    ];
    $all[] = $comment;
    json_write('comments.json', $all);
    return $comment;
}

function db_delete_comment(string $comment_id, string $user_id): bool {
    $all     = json_read('comments.json');
    $new     = [];
    $deleted = false;
    foreach ($all as $c) {
        if (($c['id'] ?? '') === $comment_id && ($c['user_id'] ?? '') === $user_id) {
            $deleted = true;
            continue;
        }
        $new[] = $c;
    }
    if ($deleted) json_write('comments.json', $new);
    return $deleted;
}

function db_avg_rating(int $tmdb_id, string $type): ?float {
    $all    = json_read('comments.json');
    $total  = 0;
    $count  = 0;
    foreach ($all as $c) {
        if ((int)($c['tmdb_id'] ?? 0) === $tmdb_id && ($c['type'] ?? '') === $type) {
            $total += (int)($c['rating'] ?? 0);
            $count++;
        }
    }
    if (!$count) return null;
    return round($total / $count, 1);
}

function db_get_comments_by_user(string $user_id): array {
    $all = json_read('comments.json');
    $out = [];
    foreach ($all as $c) {
        if (($c['user_id'] ?? '') === $user_id) $out[] = $c;
    }
    usort($out, fn($a, $b) => (int)($b['created_at'] ?? 0) - (int)($a['created_at'] ?? 0));
    return $out;
}

// ─────────────────────────────────────────────
//  Views
// ─────────────────────────────────────────────

function db_get_views(int $tmdb_id, string $type): int {
    $doc = mongo_find_one('views', ['tmdb_id' => $tmdb_id, 'type' => $type]);
    return (int)($doc['count'] ?? 0);
}

function db_increment_view(int $tmdb_id, string $type): void {
    $sess_key = 'vw_inc_' . $tmdb_id . '_' . $type;
    if (!empty($_SESSION[$sess_key])) return;

    $user       = auth_user();
    $viewer_key = $user ? ('u_' . $user['id']) : ('s_' . session_id());

    $already = mongo_find_one('view_logs', [
        'viewer'  => $viewer_key,
        'tmdb_id' => $tmdb_id,
        'type'    => $type,
    ]);

    if (!$already) {
        mongo_insert('view_logs', [
            'viewer'    => $viewer_key,
            'tmdb_id'   => $tmdb_id,
            'type'      => $type,
            'viewed_at' => time(),
        ]);
        mongo_update(
            'views',
            ['tmdb_id' => $tmdb_id, 'type' => $type],
            ['$inc' => ['count' => 1]],
            true
        );
    }

    $_SESSION[$sess_key] = time();
}

// ─────────────────────────────────────────────
//  Saved list
// ─────────────────────────────────────────────

function db_is_saved(string $user_id, int $tmdb_id, string $type): bool {
    $all = json_read('saved.json');
    foreach ($all as $s) {
        if (($s['user_id'] ?? '') === $user_id && (int)($s['tmdb_id'] ?? 0) === $tmdb_id && ($s['type'] ?? '') === $type) {
            return true;
        }
    }
    return false;
}

function db_toggle_save(string $user_id, int $tmdb_id, string $type, string $title, string $poster): bool {
    $all = json_read('saved.json');
    $new = [];
    $found = false;
    foreach ($all as $s) {
        if (($s['user_id'] ?? '') === $user_id && (int)($s['tmdb_id'] ?? 0) === $tmdb_id && ($s['type'] ?? '') === $type) {
            $found = true;
            continue;
        }
        $new[] = $s;
    }
    if ($found) {
        json_write('saved.json', $new);
        return false;
    }
    $new[] = [
        'id'         => gen_id(),
        'user_id'    => $user_id,
        'tmdb_id'    => $tmdb_id,
        'type'       => $type,
        'title'      => $title,
        'poster'     => $poster,
        'saved_at'   => time(),
    ];
    json_write('saved.json', $new);
    return true;
}

function db_get_saved(string $user_id): array {
    $all = json_read('saved.json');
    $out = [];
    foreach ($all as $s) {
        if (($s['user_id'] ?? '') === $user_id) $out[] = $s;
    }
    usort($out, fn($a, $b) => (int)($b['saved_at'] ?? 0) - (int)($a['saved_at'] ?? 0));
    return $out;
}
