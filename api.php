<?php
/* ℤ — api.php  (PHP 8.0+ / pdo_sqlite / GD / cURL) */

const DB_PATH     = __DIR__ . '/data/z.sqlite';
const UPLOAD_DIR  = __DIR__ . '/uploads';
const MAX_POST    = 500;
const MAX_MEDIA        = 4;                     // 1ポストの画像上限(動画は1本のみ)
const MAX_IMG_BYTES    = 10 * 1024 * 1024;      // 画像 10MB
const MAX_VIDEO_BYTES  = 50 * 1024 * 1024;      // 動画 50MB (php.ini の upload_max_filesize / post_max_size も要調整)
const MAX_EMOJI_BYTES  = 2 * 1024 * 1024;       // カスタム絵文字 元ファイル 2MB
const MAX_EMOJI_PER_USER = 50;
const MAX_DECO_BYTES   = 3 * 1024 * 1024;       // アイコンデコレーション画像 3MB
const MAX_BADGES       = 5;                     // 1人が付けられるバッジ(絵文字)の数
const SIGNUP_OPEN = true;
const SITE_URL    = 'https://shinte.tech';       // OGP の絶対URL用(末尾スラッシュなし)
const OGP_DEFAULT = 'ogp.png';                   // 画像もアイコンも無いポスト用の既定画像
const RX_SET      = ['👍', '😂', '😮', '😢', '🔥', '🎉', '🙏', '👀'];
const RESERVED    = ['admin', 'administrator', 'root', 'system', 'support', 'api', 'z', 'null', 'undefined',
                     'login', 'signup', 'home', 'search', 'notifications', 'messages', 'bookmarks', 'profile'];

mb_internal_encoding('UTF-8');
session_name('zsid');
session_set_cookie_params([
    'lifetime' => 86400 * 60, 'path' => '/', 'httponly' => true,
    'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']),
]);
ini_set('session.gc_maxlifetime', (string)(86400 * 60));
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

/* ---------- 出力 ---------- */
function out($d, int $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function fail(string $m, int $code = 400)
{
    out(['error' => $m], $code);
}

/* ---------- DB ---------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;');
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 1) {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      handle TEXT NOT NULL UNIQUE COLLATE NOCASE,
      name TEXT NOT NULL, pass TEXT NOT NULL,
      bio TEXT NOT NULL DEFAULT '', avatar TEXT, banner TEXT,
      no_view INTEGER NOT NULL DEFAULT 0,
      notif_read INTEGER NOT NULL DEFAULT 0,
      created_at INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS posts(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL, parent_id INTEGER,
      body TEXT NOT NULL, created_at INTEGER NOT NULL,
      deleted INTEGER NOT NULL DEFAULT 0);
    CREATE INDEX IF NOT EXISTS posts_ts ON posts(created_at DESC);
    CREATE INDEX IF NOT EXISTS posts_user ON posts(user_id, created_at DESC);
    CREATE INDEX IF NOT EXISTS posts_parent ON posts(parent_id);
    CREATE TABLE IF NOT EXISTS tags(
      post_id INTEGER NOT NULL, tag TEXT NOT NULL, created_at INTEGER NOT NULL,
      PRIMARY KEY(post_id, tag));
    CREATE INDEX IF NOT EXISTS tags_tag ON tags(tag, created_at DESC);
    CREATE TABLE IF NOT EXISTS likes(
      user_id INTEGER NOT NULL, post_id INTEGER NOT NULL, created_at INTEGER NOT NULL,
      PRIMARY KEY(user_id, post_id));
    CREATE INDEX IF NOT EXISTS likes_post ON likes(post_id);
    CREATE TABLE IF NOT EXISTS reactions(
      user_id INTEGER NOT NULL, post_id INTEGER NOT NULL, emoji TEXT NOT NULL, created_at INTEGER NOT NULL,
      PRIMARY KEY(user_id, post_id, emoji));
    CREATE INDEX IF NOT EXISTS reactions_post ON reactions(post_id);
    CREATE TABLE IF NOT EXISTS reposts(
      user_id INTEGER NOT NULL, post_id INTEGER NOT NULL, created_at INTEGER NOT NULL,
      PRIMARY KEY(user_id, post_id));
    CREATE INDEX IF NOT EXISTS reposts_post ON reposts(post_id);
    CREATE INDEX IF NOT EXISTS reposts_ts ON reposts(created_at DESC);
    CREATE TABLE IF NOT EXISTS bookmarks(
      user_id INTEGER NOT NULL, post_id INTEGER NOT NULL, created_at INTEGER NOT NULL,
      PRIMARY KEY(user_id, post_id));
    CREATE TABLE IF NOT EXISTS follows(
      follower_id INTEGER NOT NULL, followee_id INTEGER NOT NULL, created_at INTEGER NOT NULL,
      PRIMARY KEY(follower_id, followee_id));
    CREATE INDEX IF NOT EXISTS follows_followee ON follows(followee_id);
    CREATE TABLE IF NOT EXISTS dms(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      from_id INTEGER NOT NULL, to_id INTEGER NOT NULL,
      body TEXT NOT NULL, created_at INTEGER NOT NULL);
    CREATE INDEX IF NOT EXISTS dms_from ON dms(from_id, to_id);
    CREATE INDEX IF NOT EXISTS dms_to ON dms(to_id, from_id);
    CREATE TABLE IF NOT EXISTS notifs(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL, actor_id INTEGER NOT NULL, type TEXT NOT NULL,
      post_id INTEGER, emoji TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL);
    CREATE INDEX IF NOT EXISTS notifs_user ON notifs(user_id, id DESC);
    CREATE TABLE IF NOT EXISTS views(
      post_id INTEGER NOT NULL, user_id INTEGER NOT NULL, ts INTEGER NOT NULL,
      PRIMARY KEY(post_id, user_id));
    CREATE TABLE IF NOT EXISTS throttle(k TEXT NOT NULL, ts INTEGER NOT NULL);
    CREATE INDEX IF NOT EXISTS throttle_k ON throttle(k, ts);
    CREATE TABLE IF NOT EXISTS ogp_cache(url TEXT PRIMARY KEY, json TEXT NOT NULL, ts INTEGER NOT NULL);
    ");
    $pdo->exec('PRAGMA user_version=1');
    }
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 2) {
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS media(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          user_id INTEGER NOT NULL, post_id INTEGER, pos INTEGER NOT NULL DEFAULT 0,
          kind TEXT NOT NULL, path TEXT NOT NULL,
          w INTEGER NOT NULL DEFAULT 0, h INTEGER NOT NULL DEFAULT 0,
          created_at INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS media_post ON media(post_id);
        CREATE INDEX IF NOT EXISTS media_user ON media(user_id, post_id);
        CREATE TABLE IF NOT EXISTS emojis(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          name TEXT NOT NULL UNIQUE COLLATE NOCASE,
          user_id INTEGER NOT NULL, path TEXT NOT NULL, created_at INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS emojis_user ON emojis(user_id);
        ");
        $pdo->exec('PRAGMA user_version=2');
    }
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 3) {
        try { $pdo->exec('ALTER TABLE posts ADD COLUMN quote_id INTEGER'); } catch (PDOException $e) { /* 追加済み */ }
        $pdo->exec('CREATE INDEX IF NOT EXISTS posts_quote ON posts(quote_id)');
        $pdo->exec('PRAGMA user_version=3');
    }
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 4) {
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS terms(
          post_id INTEGER NOT NULL, term TEXT NOT NULL, kind TEXT NOT NULL,
          user_id INTEGER NOT NULL, created_at INTEGER NOT NULL,
          PRIMARY KEY(post_id, term));
        CREATE INDEX IF NOT EXISTS terms_ts ON terms(created_at);
        CREATE INDEX IF NOT EXISTS terms_term ON terms(term, created_at);
        CREATE TABLE IF NOT EXISTS trend_cache(k TEXT PRIMARY KEY, json TEXT NOT NULL, ts INTEGER NOT NULL);
        ");
        // 既存ポスト(直近8日)から語を作り直す
        $pdo->beginTransaction();
        try {
            foreach ($pdo->query('SELECT id, user_id, body, created_at FROM posts WHERE deleted=0 AND created_at>' . (time() - 8 * 86400))->fetchAll() as $r) {
                index_terms((int)$r['id'], (int)$r['user_id'], (string)$r['body'], (int)$r['created_at']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[Z] terms backfill failed: ' . $e->getMessage());
        }
        $pdo->exec('PRAGMA user_version=4');
    }
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 5) {
        foreach (['ALTER TABLE users ADD COLUMN deco TEXT', "ALTER TABLE users ADD COLUMN badges TEXT NOT NULL DEFAULT ''"] as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) { /* 追加済み */ }
        }
        $pdo->exec('PRAGMA user_version=5');
    }
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement
{
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s;
}
function ins(array $a): string
{
    return implode(',', array_fill(0, count($a), '?'));
}

/* ---------- レート制限 ---------- */
function ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0';
}
function throttle_check(string $k, int $max, int $win): void
{
    if (mt_rand(1, 50) === 1) q('DELETE FROM throttle WHERE ts < ?', [time() - 86400]);
    $n = (int)q('SELECT COUNT(*) FROM throttle WHERE k=? AND ts>?', [$k, time() - $win])->fetchColumn();
    if ($n >= $max) fail('操作が多すぎます。しばらくしてからやり直してください', 429);
}
function throttle_hit(string $k): void
{
    q('INSERT INTO throttle(k, ts) VALUES(?, ?)', [$k, time()]);
}
function throttle(string $k, int $max, int $win): void
{
    throttle_check($k, $max, $win);
    throttle_hit($k);
}

/* ---------- ユーザー ---------- */
function cur(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $r = q('SELECT * FROM users WHERE id=?', [$_SESSION['uid']])->fetch();
            if ($r) $u = $r; else unset($_SESSION['uid']);
        }
    }
    return $u;
}
function need(): array
{
    $u = cur();
    if (!$u) fail('ログインが必要です', 401);
    return $u;
}
function uj(array $r): array
{
    return ['handle' => $r['handle'], 'name' => $r['name'], 'bio' => $r['bio'],
            'avatar' => $r['avatar'] ?: null, 'banner' => $r['banner'] ?: null,
            'deco' => ($r['deco'] ?? null) ?: null, 'badges' => badges_out($r['badges'] ?? null)];
}
function user_by_handle($h): ?array
{
    $h = is_string($h) ? trim(ltrim($h, '@')) : '';
    if (!preg_match('/^[A-Za-z0-9_]{3,15}$/', $h)) return null;
    $r = q('SELECT * FROM users WHERE handle=?', [$h])->fetch();
    return $r ?: null;
}
function user_rows(array $rows, int $me): array
{
    $f = [];
    if ($rows) {
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $st = q('SELECT followee_id FROM follows WHERE follower_id=? AND followee_id IN (' . ins($ids) . ')', array_merge([$me], $ids));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $x) $f[(int)$x] = 1;
    }
    return array_map(fn($r) => uj($r) + ['following' => isset($f[(int)$r['id']]), 'mine' => (int)$r['id'] === $me], $rows);
}
function str(array $b, string $k): string
{
    return isset($b[$k]) && is_string($b[$k]) ? trim($b[$k]) : '';
}
function clean_name(string $s): string
{
    return trim(preg_replace('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', $s) ?? '');
}
function clean_text(string $s): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    return trim(preg_replace('/[^\P{Cc}\n\t]+/u', '', $s) ?? '');
}

/* ---------- ゲスト閲覧 ---------- */
/** ログイン任意の読み取り専用エンドポイント用。未ログインなら IP 単位で回数制限する */
function viewer(?string $limitKey = null, int $max = 60): int
{
    $u = cur();
    if (!$u && $limitKey !== null) throttle('gv:' . $limitKey . ':' . ip(), $max, 60);
    return $u ? (int)$u['id'] : 0;
}

/* ---------- 通知 ---------- */
function notify(int $to, int $actor, string $type, ?int $post, string $emoji = ''): void
{
    if ($to === $actor) return;
    q('INSERT INTO notifs(user_id, actor_id, type, post_id, emoji, created_at) VALUES(?,?,?,?,?,?)', [$to, $actor, $type, $post, $emoji, time()]);
}
function unnotify(int $to, int $actor, string $type, ?int $post, string $emoji = ''): void
{
    q('DELETE FROM notifs WHERE user_id=? AND actor_id=? AND type=? AND post_id IS ? AND emoji=?', [$to, $actor, $type, $post, $emoji]);
}

/* ---------- ポスト ---------- */
function live_post(int $id): array
{
    $p = q('SELECT * FROM posts WHERE id=? AND deleted=0', [$id])->fetch();
    if (!$p) fail('ポストが見つかりません', 404);
    return $p;
}
function decorate(array $ids, ?int $me): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $rows = q('SELECT p.*, u.handle, u.name, u.bio, u.avatar, u.banner, u.deco, u.badges FROM posts p JOIN users u ON u.id=p.user_id WHERE p.deleted=0 AND p.id IN (' . ins($ids) . ')', $ids)->fetchAll();
    $by = [];
    foreach ($rows as $r) $by[(int)$r['id']] = $r;
    $ok = array_keys($by);
    if (!$ok) return [];
    $in = ins($ok);
    $cnt = function (string $sql) use ($ok) {
        $m = [];
        foreach (q($sql, $ok)->fetchAll() as $r) $m[(int)$r['k']] = (int)$r['n'];
        return $m;
    };
    $rep = $cnt("SELECT parent_id k, COUNT(*) n FROM posts WHERE deleted=0 AND parent_id IN ($in) GROUP BY parent_id");
    $rt  = $cnt("SELECT post_id k, COUNT(*) n FROM reposts WHERE post_id IN ($in) GROUP BY post_id");
    $qt  = $cnt("SELECT quote_id k, COUNT(*) n FROM posts WHERE deleted=0 AND quote_id IN ($in) GROUP BY quote_id");
    $lk  = $cnt("SELECT post_id k, COUNT(*) n FROM likes WHERE post_id IN ($in) GROUP BY post_id");
    $rx = [];
    foreach (q("SELECT post_id, emoji, COUNT(*) n FROM reactions WHERE post_id IN ($in) GROUP BY post_id, emoji ORDER BY n DESC", $ok)->fetchAll() as $r) {
        $rx[(int)$r['post_id']][$r['emoji']] = (int)$r['n'];
    }
    $mediaBy = [];
    foreach (q("SELECT id, post_id, kind, path, w, h FROM media WHERE post_id IN ($in) ORDER BY pos, id", $ok)->fetchAll() as $r) {
        $mediaBy[(int)$r['post_id']][] = ['id' => (int)$r['id'], 'kind' => $r['kind'], 'url' => $r['path'], 'w' => (int)$r['w'], 'h' => (int)$r['h']];
    }
    // 引用元ポスト(1段のみ展開)
    $qBy = [];
    $qMedia = [];
    $qids = array_values(array_unique(array_filter(array_map(fn($r) => (int)($r['quote_id'] ?? 0), $by))));
    if ($qids) {
        $qin = ins($qids);
        foreach (q("SELECT p.id, p.body, p.created_at, p.deleted, u.handle, u.name, u.avatar FROM posts p JOIN users u ON u.id=p.user_id WHERE p.id IN ($qin)", $qids)->fetchAll() as $r) $qBy[(int)$r['id']] = $r;
        foreach (q("SELECT post_id, kind, path, w, h FROM media WHERE post_id IN ($qin) ORDER BY pos, id", $qids)->fetchAll() as $r) {
            if (isset($qMedia[(int)$r['post_id']])) continue;
            $qMedia[(int)$r['post_id']] = [['kind' => $r['kind'], 'url' => $r['path'], 'w' => (int)$r['w'], 'h' => (int)$r['h']]];
        }
    }
    // 本文中の :name: とリアクションで使われているカスタム絵文字を解決
    $per = [];
    $names = [];
    foreach ($ok as $id) {
        $set = [];
        if (preg_match_all('/:([A-Za-z0-9_]{2,32}):/', $by[$id]['body'], $mm)) foreach ($mm[1] as $n) $set[strtolower($n)] = 1;
        foreach (array_keys($rx[$id] ?? []) as $e) if (preg_match('/^:([a-z0-9_]{2,32}):$/', (string)$e, $mm)) $set[$mm[1]] = 1;
        $per[$id] = array_map('strval', array_keys($set));
        foreach ($per[$id] as $n) $names[$n] = 1;
    }
    $qper = [];
    foreach ($qBy as $qid => $qr) {
        if ((int)$qr['deleted'] === 0 && preg_match_all('/:([A-Za-z0-9_]{2,32}):/', $qr['body'], $mm)) {
            foreach ($mm[1] as $n) { $qper[$qid][strtolower($n)] = 1; $names[strtolower($n)] = 1; }
        }
    }
    $emap = emoji_map(array_map('strval', array_keys($names)));
    $quoteOf = function (array $r) use ($qBy, $qMedia, $qper, $emap) {
        $qid = (int)($r['quote_id'] ?? 0);
        if (!$qid) return null;
        $qr = $qBy[$qid] ?? null;
        if (!$qr || (int)$qr['deleted']) return ['id' => $qid, 'deleted' => true];
        $em = [];
        foreach (array_map('strval', array_keys($qper[$qid] ?? [])) as $n) if (isset($emap[$n])) $em[$n] = $emap[$n];
        return ['id' => $qid, 'deleted' => false, 'handle' => $qr['handle'], 'name' => $qr['name'], 'avatar' => $qr['avatar'] ?: null,
                'body' => mb_substr($qr['body'], 0, 300), 'ts' => (int)$qr['created_at'], 'media' => $qMedia[$qid] ?? [], 'emojis' => $em];
    };
    $mine = ['like' => [], 'rt' => [], 'bm' => [], 'rx' => []];
    if ($me) {
        $a = array_merge([$me], $ok);
        foreach (q("SELECT post_id FROM likes WHERE user_id=? AND post_id IN ($in)", $a)->fetchAll(PDO::FETCH_COLUMN) as $x) $mine['like'][(int)$x] = 1;
        foreach (q("SELECT post_id FROM reposts WHERE user_id=? AND post_id IN ($in)", $a)->fetchAll(PDO::FETCH_COLUMN) as $x) $mine['rt'][(int)$x] = 1;
        foreach (q("SELECT post_id FROM bookmarks WHERE user_id=? AND post_id IN ($in)", $a)->fetchAll(PDO::FETCH_COLUMN) as $x) $mine['bm'][(int)$x] = 1;
        foreach (q("SELECT post_id, emoji FROM reactions WHERE user_id=? AND post_id IN ($in)", $a)->fetchAll() as $r) $mine['rx'][(int)$r['post_id']][] = $r['emoji'];
    }
    $out = [];
    foreach ($ids as $id) {
        if (!isset($by[$id])) continue;
        $r = $by[$id];
        $out[] = uj($r) + [
            'id' => $id, 'body' => $r['body'], 'ts' => (int)$r['created_at'],
            'parent_id' => $r['parent_id'] ? (int)$r['parent_id'] : null,
            'rep' => $rep[$id] ?? 0, 'rt' => $rt[$id] ?? 0, 'likes' => $lk[$id] ?? 0,
            'rx' => $rx[$id] ?? [], 'myrx' => $mine['rx'][$id] ?? [],
            'qt' => $qt[$id] ?? 0, 'quote' => $quoteOf($r),
            'media' => $mediaBy[$id] ?? [],
            'emojis' => (function () use ($per, $emap, $id) { $o = []; foreach ($per[$id] as $n) if (isset($emap[$n])) $o[$n] = $emap[$n]; return $o; })(),
            'liked' => isset($mine['like'][$id]), 'reposted' => isset($mine['rt'][$id]), 'bookmarked' => isset($mine['bm'][$id]),
            'mine' => $me !== null && (int)$r['user_id'] === $me,
        ];
    }
    return $out;
}

/* ---------- カスタム絵文字 ---------- */
function emoji_map(array $names): array
{
    $names = array_values(array_unique($names));
    if (!$names) return [];
    $m = [];
    foreach (q('SELECT name, path FROM emojis WHERE name IN (' . ins($names) . ')', $names)->fetchAll() as $r) $m[(string)$r['name']] = $r['path'];
    return $m;
}

/* ---------- バッジ(好きな絵文字を最大 MAX_BADGES 個) ---------- */
const RX_EMOJI = '\x{00A9}\x{00AE}\x{203C}\x{2049}\x{2122}\x{2139}\x{2194}-\x{21AA}\x{231A}-\x{23FF}\x{24C2}\x{25AA}-\x{25FE}\x{2600}-\x{27BF}\x{2934}\x{2935}\x{2B05}-\x{2B55}\x{3030}\x{303D}\x{3297}\x{3299}\x{1F000}-\x{1FAFF}';

/** 標準の絵文字1つ(肌色・ZWJ・国旗・キーキャップ含む)かどうか */
function valid_emoji(string $s): bool
{
    if ($s === '' || !mb_check_encoding($s, 'UTF-8') || mb_strlen($s) > 14) return false;
    if (!preg_match('/^[' . RX_EMOJI . '\x{200D}\x{FE0F}\x{20E3}\x{E0020}-\x{E007F}#*0-9]+$/u', $s)) return false;
    if (!preg_match('/[' . RX_EMOJI . ']/u', $s) && !preg_match('/^[#*0-9]\x{FE0F}?\x{20E3}$/u', $s)) return false;
    $n = function_exists('grapheme_strlen') ? grapheme_strlen($s) : preg_match_all('/\X/u', $s);
    return $n === 1;
}
function emoji_url(string $name): ?string
{
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (q('SELECT name, path FROM emojis')->fetchAll() as $r) $all[strtolower((string)$r['name'])] = $r['path'];
    }
    return $all[strtolower($name)] ?? null;
}
/** 保存済み JSON → 表示用 [{e, url?}]。削除されたカスタム絵文字は除く */
function badges_out($json): array
{
    $a = is_string($json) && $json !== '' ? json_decode($json, true) : null;
    if (!is_array($a)) return [];
    $o = [];
    foreach (array_slice($a, 0, MAX_BADGES) as $e) {
        if (!is_string($e)) continue;
        if (preg_match('/^:([a-z0-9_]{2,32}):$/', $e, $mm)) {
            $url = emoji_url($mm[1]);
            if ($url) $o[] = ['e' => $e, 'url' => $url];
        } else {
            $o[] = ['e' => $e];
        }
    }
    return $o;
}
/** 入力を検証して保存用の文字列配列にする(不正なら fail) */
function parse_badges($in): array
{
    if (!is_array($in)) fail('バッジの指定が不正です');
    if (count($in) > MAX_BADGES) fail('バッジは' . MAX_BADGES . '個までです');
    $out = [];
    foreach ($in as $e) {
        if (!is_string($e)) fail('バッジの指定が不正です');
        $e = trim($e);
        if (preg_match('/^:([a-z0-9_]{2,32}):$/', $e, $mm)) {
            if (!q('SELECT 1 FROM emojis WHERE name=?', [$mm[1]])->fetch()) fail('カスタム絵文字 ' . $e . ' は存在しません');
        } elseif (!valid_emoji($e)) {
            fail('バッジには絵文字を1つずつ指定してください');
        }
        if (!in_array($e, $out, true)) $out[] = $e;
    }
    return $out;
}

/* ---------- アップロード共通 ---------- */
function ini_bytes(string $v): int
{
    $v = trim($v);
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return $n;
}
function upload_file(): array
{
    $f = $_FILES['file'] ?? null;
    if (!$f) {
        $lim = ini_bytes((string)ini_get('post_max_size'));
        if ($lim > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $lim) fail('ファイルがサーバーの上限(' . ini_get('post_max_size') . ')を超えています', 413);
        fail('ファイルを選んでください');
    }
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) fail('ファイルがサーバーの上限(' . ini_get('upload_max_filesize') . ')を超えています', 413);
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) fail('アップロードできませんでした');
    return $f;
}
function ensure_dir(string $sub = ''): string
{
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
        file_put_contents(UPLOAD_DIR . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar)$\">\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n</FilesMatch>\n");
    }
    $d = $sub === '' ? UPLOAD_DIR : UPLOAD_DIR . '/' . $sub;
    if (!is_dir($d)) {
        mkdir($d, 0755, true);
        file_put_contents($d . '/.htaccess', "<IfModule mod_headers.c>\nHeader set X-Content-Type-Options \"nosniff\"\n</IfModule>\n");
    }
    return $d;
}
function rm_file(?string $path): void
{
    if ($path && preg_match('#^uploads/(m|e|d)/[A-Za-z0-9_]+\.(jpg|png|gif|mp4|webm)$#', $path)) @unlink(__DIR__ . '/' . $path);
}
function has_alpha($im): bool
{
    if (!imageistruecolor($im)) return imagecolortransparent($im) >= 0;
    $w = imagesx($im);
    $h = imagesy($im);
    $sx = max(1, intdiv($w, 48));
    $sy = max(1, intdiv($h, 48));
    for ($y = 0; $y < $h; $y += $sy) for ($x = 0; $x < $w; $x += $sx) if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) > 0) return true;
    return false;
}
function gif_animated(string $raw): bool
{
    return preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $raw) > 1;
}
/** 画像を再エンコードして保存(EXIF/位置情報の除去・向き補正・縮小)。[相対パス, w, h] を返す */
function store_image(string $tmp, array $info, string $sub, string $prefix, int $maxSide, bool $forcePng = false): array
{
    if (!function_exists('imagecreatefromstring')) fail('サーバーにGD拡張が必要です', 500);
    $im = @imagecreatefromstring((string)file_get_contents($tmp));
    if (!$im) fail('この画像は読み込めません');
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data($tmp);
        $deg = [3 => 180, 6 => -90, 8 => 90][(int)($ex['Orientation'] ?? 1)] ?? 0;
        if ($deg) { $r = @imagerotate($im, $deg, 0); if ($r) $im = $r; }
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $s = min(1, $maxSide / max($w, $h));
    $nw = max(1, (int)round($w * $s));
    $nh = max(1, (int)round($h * $s));
    $alpha = $forcePng || has_alpha($im);
    $dst = imagecreatetruecolor($nw, $nh);
    if ($alpha) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    } else {
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    }
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $dir = ensure_dir($sub);
    $name = $prefix . bin2hex(random_bytes(10)) . ($alpha ? '.png' : '.jpg');
    $ok = $alpha ? imagepng($dst, $dir . '/' . $name, 6) : imagejpeg($dst, $dir . '/' . $name, 85);
    if (!$ok) fail('画像を保存できませんでした', 500);
    return ['uploads/' . $sub . '/' . $name, $nw, $nh];
}
/** 動画コンテナを先頭バイトで判定(mp4/mov → mp4, webm → webm) */
function video_ext(string $tmp): ?string
{
    $fh = @fopen($tmp, 'rb');
    if (!$fh) return null;
    $h = (string)fread($fh, 64);
    fclose($fh);
    if (strlen($h) >= 12 && substr($h, 4, 4) === 'ftyp') return 'mp4';
    if (substr($h, 0, 4) === "\x1A\x45\xDF\xA3" && strpos($h, 'webm') !== false) return 'webm';
    return null;
}
function cleanup_media(): void
{
    foreach (q('SELECT id, path FROM media WHERE post_id IS NULL AND created_at < ?', [time() - 7200])->fetchAll() as $r) {
        rm_file($r['path']);
        q('DELETE FROM media WHERE id=?', [$r['id']]);
    }
}

/* ---------- 画像 ---------- */
function rm_img(?string $path): void
{
    if ($path && preg_match('#^uploads/(avatar|banner)_\d+_[0-9a-f]+\.jpg$#', $path)) @unlink(__DIR__ . '/' . $path);
}
function save_image(string $tmp, int $w, int $h, string $name): void
{
    $im = @imagecreatefromstring((string)file_get_contents($tmp));
    if (!$im) fail('この画像は読み込めません');
    $sw = imagesx($im);
    $sh = imagesy($im);
    $s = max($w / $sw, $h / $sh);
    $cw = (int)round($w / $s);
    $ch = (int)round($h / $s);
    $sx = (int)floor(($sw - $cw) / 2);
    $sy = (int)floor(($sh - $ch) / 2);
    $dst = imagecreatetruecolor($w, $h);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $im, 0, 0, $sx, $sy, $w, $h, $cw, $ch);
    ensure_dir();
    if (!imagejpeg($dst, UPLOAD_DIR . '/' . $name, 88)) fail('画像を保存できませんでした', 500);
}

/* ---------- リンクプレビュー(OGP) ---------- */
function abs_url(string $base, string $rel): string
{
    if (preg_match('#^https?://#i', $rel)) return $rel;
    $p = parse_url($base);
    $o = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($rel, '//')) return $p['scheme'] . ':' . $rel;
    if (str_starts_with($rel, '/')) return $o . $rel;
    return $o . preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/') . $rel;
}
function http_get_safe(string $url): ?array
{
    $maxBytes = 524288;
    for ($i = 0; $i < 4; $i++) {
        $p = parse_url($url);
        if (!$p || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return null;
        $scheme = strtolower($p['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) return null;
        $host = $p['host'];
        $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
        $ips = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host] : (@gethostbynamel($host) ?: []);
        if (!$ips) return null;
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return null;
        }
        $body = '';
        $ctype = '';
        $loc = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]], // 検証したIPに固定(DNSリバインディング対策)
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ZLinkPreview/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: ja,en;q=0.8'],
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$loc, &$ctype) {
                if (stripos($h, 'location:') === 0) $loc = trim(substr($h, 9));
                elseif (stripos($h, 'content-type:') === 0) $ctype = trim(substr($h, 13));
                return strlen($h);
            },
            CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$body, $maxBytes) {
                $body .= $d;
                return strlen($body) > $maxBytes ? 0 : strlen($d);
            },
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code >= 300 && $code < 400 && $loc !== '') {
            $url = abs_url($url, $loc);
            continue;
        }
        if ($code !== 200) return null;
        return ['body' => $body, 'ctype' => $ctype, 'url' => $url];
    }
    return null;
}
function parse_ogp(string $html, string $base): ?array
{
    $enc = mb_detect_encoding($html, ['UTF-8', 'SJIS-win', 'EUC-JP', 'ISO-8859-1'], true) ?: 'UTF-8';
    if ($enc !== 'UTF-8') $html = mb_convert_encoding($html, 'UTF-8', $enc);
    $html = mb_scrub($html);
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    $m = [];
    foreach ($d->getElementsByTagName('meta') as $t) {
        $k = strtolower($t->getAttribute('property') ?: $t->getAttribute('name'));
        $v = trim($t->getAttribute('content'));
        if ($k !== '' && $v !== '' && !isset($m[$k])) $m[$k] = $v;
    }
    $title = $m['og:title'] ?? ($m['twitter:title'] ?? '');
    if ($title === '') {
        $tt = $d->getElementsByTagName('title')->item(0);
        $title = $tt ? trim($tt->textContent) : '';
    }
    if ($title === '') return null;
    $img = $m['og:image'] ?? ($m['twitter:image'] ?? '');
    if ($img !== '') {
        $img = abs_url($base, $img);
        if (!preg_match('#^https?://#i', $img)) $img = '';
    }
    $p = parse_url($base);
    return [
        'title' => mb_substr($title, 0, 120),
        'desc' => mb_substr($m['og:description'] ?? ($m['description'] ?? ''), 0, 200),
        'image' => $img !== '' ? $img : null,
        'site' => mb_substr($m['og:site_name'] ?? ($p['host'] ?? ''), 0, 60),
    ];
}
function ogp(string $url, string $uid): ?array
{
    if (!preg_match('#^https?://\S{1,480}$#i', $url)) return null;
    $c = q('SELECT json, ts FROM ogp_cache WHERE url=?', [$url])->fetch();
    if ($c && time() - (int)$c['ts'] < ($c['json'] === 'null' ? 3600 : 604800)) return json_decode($c['json'], true);
    throttle('og:' . $uid, $uid[0] === 'g' ? 15 : 40, 60);
    $r = http_get_safe($url);
    $res = ($r && stripos($r['ctype'], 'html') !== false) ? parse_ogp($r['body'], $r['url']) : null;
    q('INSERT OR REPLACE INTO ogp_cache(url, json, ts) VALUES(?,?,?)', [$url, json_encode($res, JSON_UNESCAPED_UNICODE), time()]);
    return $res;
}

/* ---------- トレンド(ハッシュタグ + 一般語の急上昇検出) ---------- */
const TREND_LIMIT     = 5;
const TREND_HALF_LIFE = 5400;          // 現在の勢いの半減期: 90分(短いほど新鮮さ重視)
const TREND_RECENT    = 28800;         // 「いま」とみなす範囲: 8時間(以降は重み<2%)
const TREND_BASELINE  = 7 * 86400;     // 平常時の頻度を測る期間
const TREND_CACHE_TTL = 60;

const RX_HAN = '\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}々〆';
const RX_KAT = '\x{30A1}-\x{30FA}\x{30FC}';

function term_stop(): array
{
    static $s = null;
    if ($s === null) $s = array_flip(preg_split('/\s+/u', trim('
        今日 明日 昨日 今年 去年 来年 今回 今度 最近 自分 時間 毎日 毎回 本当 大丈夫 一番 結構 全然 普通 感じ 気持ち 問題 場合 必要
        部分 以上 以下 以外 以前 今後 現在 最初 最後 午前 午後 今夜 今朝 先週 来週 今週 先月 来月 今月 仕事 学校 みんな 一緒 最高
        ありがとう よろしく お願い 無理 面白 可能 予定 気分 言葉 状態 一応 完全 絶対 以降 付近 方法 理由 意味 内容 結果 感想 発言
        the and for with this that have from you are was not but all can will just its out get got has had how why who what when
        too very more some any now new one two like http https www com amp lol
    ')));
    return $s;
}

/** 本文から [語 => 種別] を抽出。種別: t=ハッシュタグ, w=一般語 */
function extract_terms(string $body): array
{
    $out = [];
    // ハッシュタグ(post時のtags登録と同じ規則: 正規化せず小文字化のみ)
    $rest = $body;
    if (preg_match_all('/(?:^|\s)#([\p{L}\p{N}_]{1,50})/u', $body, $mm)) {
        foreach (array_unique(array_map('mb_strtolower', $mm[1])) as $t) $out[(string)$t] = 't';
        $rest = preg_replace('/(?:^|\s)#[\p{L}\p{N}_]{1,50}/u', ' ', $body) ?? $body;
    }
    $s = mb_strtolower(mb_convert_kana($rest, 'KVa'));          // 半角カナ→全角、全角英数→半角
    $s = preg_replace('#https?://\S+#u', ' ', $s) ?? $s;
    $s = preg_replace('/(?:^|\s)@[a-z0-9_]{3,15}/u', ' ', $s) ?? $s;
    $s = preg_replace('/:[a-z0-9_]{2,32}:/u', ' ', $s) ?? $s;

    $ok = '/^(?:[' . RX_HAN . ']{2,10}|[' . RX_KAT . ']{2,20}|[a-z][a-z0-9_]{1,29})$/u';
    $isJa = fn(string $t) => (bool)preg_match('/^[' . RX_HAN . RX_KAT . ']+$/u', $t);
    $suffix = ['院', '都', '県', '市', '区', '町', '村', '号', '者', '性', '化', '会', '部', '系', '中', '氏', '社', '党', '戦', '祭', '駅'];
    $toks = [];
    if (class_exists('IntlBreakIterator') && !defined('TERM_NO_ICU')) {
        $it = IntlBreakIterator::createWordInstance('ja_JP');
        $it->setText($s);
        foreach ($it->getPartsIterator() as $p) $toks[] = (string)$p;
    } else {
        // intl が無い環境: 文字種ごとの連続(漢字列・カタカナ列・英単語)
        preg_match_all('/[' . RX_HAN . ']+|[' . RX_KAT . ']+|[a-z][a-z0-9_]*|[0-9_]+|[^' . RX_HAN . RX_KAT . 'a-z0-9_]+/u', $s, $m);
        $toks = $m[0];
    }
    $stop = term_stop();
    $cand = [];
    $prev = null;
    foreach ($toks as $tk) {
        if (preg_match($ok, $tk) && !isset($stop[$tk])) $cand[$tk] = 'w';
        // 隣接する日本語トークンを結合(例: 花火+大会 → 花火大会 / 衆議+院+選挙 は2語ずつ)
        if ($prev !== null && mb_strlen($prev) >= 2 && $isJa($prev) && $isJa($tk)
            && (mb_strlen($tk) >= 2 || in_array($tk, $suffix, true))) {
            $j = $prev . $tk;
            if (mb_strlen($j) <= 10 && !isset($stop[$j])) $cand[$j] = 'w';
        }
        $prev = $tk;
    }
    foreach ($cand as $k => $v) if (!isset($out[(string)$k])) $out[(string)$k] = $v;
    return array_slice($out, 0, 40, true);
}

function index_terms(int $postId, int $userId, string $body, int $ts): void
{
    foreach (extract_terms($body) as $t => $kind) {
        q('INSERT OR IGNORE INTO terms(post_id, term, kind, user_id, created_at) VALUES(?,?,?,?,?)', [$postId, (string)$t, $kind, $userId, $ts]);
    }
}

/**
 * 急上昇スコア。
 *   r = 直近の勢い(指数減衰 半減期90分、同一ユーザーの連投は 1, 0.4, 0.16… と逓減)
 *   e = 過去7日の平常頻度から期待される r(ユーザー×時間 のユニーク数で数える)
 *   score = (r - e) / sqrt(e + 1)  × ユーザー数係数 × 反応係数 × タグ係数
 * いつも出る語は e が大きく自然に沈み、初出の語・急増した語は e≈0 で浮上する。
 */
function compute_trends(int $limit = TREND_LIMIT): array
{
    $now = time();
    $rows = q('SELECT t.term, t.kind, t.post_id, t.user_id, t.created_at ts FROM terms t
               JOIN posts p ON p.id=t.post_id AND p.deleted=0
               WHERE t.created_at > ? AND t.term IN (SELECT term FROM terms WHERE created_at > ?)',
              [$now - TREND_BASELINE, $now - TREND_RECENT])->fetchAll();
    $cut = $now - TREND_RECENT;
    $T = [];
    foreach ($rows as $r) {
        $k = (string)$r['term'];
        $T[$k] ??= ['tag' => false, 'users' => [], 'base' => [], 'posts' => [], 'first' => PHP_INT_MAX, 'n24' => []];
        $x = &$T[$k];
        if ($r['kind'] === 't') $x['tag'] = true;
        $ts = (int)$r['ts'];
        $x['first'] = min($x['first'], $ts);
        if ($ts >= $cut) {
            $x['users'][(int)$r['user_id']][] = 0.5 ** (($now - $ts) / TREND_HALF_LIFE);
            $x['posts'][(int)$r['post_id']] = 1;
        } else {
            $x['base'][(int)$r['user_id'] . ':' . intdiv($ts, 3600)] = 1;
        }
        if ($ts > $now - 86400) $x['n24'][(int)$r['post_id']] = 1;
        unset($x);
    }
    $scored = [];
    $baseSpan = TREND_BASELINE - TREND_RECENT;
    foreach ($T as $k => $x) {
        if (!$x['posts']) continue;
        $r = 0.0;
        foreach ($x['users'] as $ws) {
            rsort($ws);
            foreach ($ws as $i => $w) $r += $w * (0.4 ** $i);
        }
        $e = 0.2 + count($x['base']) / $baseSpan * (TREND_HALF_LIFE / M_LN2);
        $burst = ($r - $e) / sqrt($e + 1);
        $u = count($x['users']);
        $uf = $u >= 3 ? 1.0 : ($u === 2 ? 0.7 : 0.3);     // 1人だけの語は基本的に載せない
        $scored[$k] = ['score' => $burst * $uf * ($x['tag'] ? 1.25 : 1.0), 'burst' => $burst, 'u' => $u, 'x' => $x];
    }
    $len = fn($k) => mb_strlen((string)$k);
    uasort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    $scored = array_slice($scored, 0, 40, true);

    // 反応(いいね・リアクション・リポスト・返信)で加点
    $pids = [];
    foreach ($scored as $s) foreach (array_keys($s['x']['posts']) as $p) $pids[$p] = 1;
    $eng = [];
    if ($pids) {
        $ids = array_keys($pids);
        $in = ins($ids);
        foreach (q("SELECT k, COUNT(*) n FROM (
                SELECT post_id k FROM likes WHERE post_id IN ($in) UNION ALL
                SELECT post_id FROM reposts WHERE post_id IN ($in) UNION ALL
                SELECT post_id FROM reactions WHERE post_id IN ($in) UNION ALL
                SELECT parent_id FROM posts WHERE deleted=0 AND parent_id IN ($in)) GROUP BY k", array_merge($ids, $ids, $ids, $ids))->fetchAll() as $r) $eng[(int)$r['k']] = (int)$r['n'];
    }
    foreach ($scored as $k => &$s) {
        $sum = 0;
        foreach (array_keys($s['x']['posts']) as $p) $sum += $eng[$p] ?? 0;
        $s['eng'] = $sum;
        $s['score'] *= min(1.8, 1 + 0.25 * log(1 + $sum));
    }
    unset($s);
    uasort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

    // 重複排除: 直近ポスト集合がほぼ同じ語(花火/大会/花火大会…)は1つにまとめる。同点なら長い語を残す
    $keys = array_keys($scored);
    usort($keys, fn($a, $b) => [round($scored[$b]['score'], 3), $len($b)] <=> [round($scored[$a]['score'], 3), $len($a)]);
    $pick = [];
    foreach ($keys as $k) {
        $s = $scored[$k];
        if ($s['score'] < 0.5) break;
        $dup = false;
        foreach ($pick as $pk => $ps) {
            $a = array_keys($s['x']['posts']); $b = array_keys($ps['x']['posts']);
            $inter = count(array_intersect($a, $b));
            if ($inter / max(1, count($a) + count($b) - $inter) >= 0.8) { $dup = true; break; }
        }
        if ($dup) continue;
        $pick[$k] = $s;
        if (count($pick) >= $limit) break;
    }
    $out = [];
    foreach ($pick as $k => $s) {
        $k = (string)$k;
        $fresh = $s['x']['first'] >= $now - 6 * 3600;
        $out[] = [
            'tag'   => $s['x']['tag'] ? '#' . $k : $k,
            'q'     => $s['x']['tag'] ? '#' . $k : $k,
            'count' => count($s['x']['n24']),
            'users' => $s['u'],
            'label' => $fresh ? '新着' : ($s['burst'] >= 4 ? '急上昇' : 'トレンド'),
            'score' => round($s['score'], 2),
        ];
    }
    return $out;
}

function trends_cached(): array
{
    $c = q('SELECT json, ts FROM trend_cache WHERE k=?', ['main'])->fetch();
    if ($c && time() - (int)$c['ts'] < TREND_CACHE_TTL) return json_decode($c['json'], true) ?: [];
    $t = compute_trends();
    q('INSERT OR REPLACE INTO trend_cache(k, json, ts) VALUES(?,?,?)', ['main', json_encode($t, JSON_UNESCAPED_UNICODE), time()]);
    return $t;
}

/* ---------- 共有用ページ (SNS クローラー向け OGP) ---------- */
function share_page(int $id): void
{
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $r = $id > 0 ? q('SELECT p.id, p.body, p.created_at, u.handle, u.name, u.avatar FROM posts p JOIN users u ON u.id=p.user_id WHERE p.id=? AND p.deleted=0', [$id])->fetch() : false;

    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if (!$r) {
        http_response_code(404);
        header('Cache-Control: no-store');
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
           . '<title>ポストが見つかりません</title></head><body><script>location.replace("/");</script>'
           . '<p><a href="/">トップへ戻る</a></p></body></html>';
        return;
    }

    // 説明文: :emoji: を除き、空白をまとめて 140 字で切る
    $text = trim(preg_replace('/\s+/u', ' ', preg_replace('/:[A-Za-z0-9_]{2,32}:/', '', (string)$r['body'])));
    $media = q('SELECT kind, path, w, h FROM media WHERE post_id=? ORDER BY pos, id', [$id])->fetchAll();
    $img = null;
    foreach ($media as $m) if ($m['kind'] === 'image') { $img = $m; break; }
    if ($text === '') $text = $media ? ($img ? '画像のポスト' : '動画のポスト') : 'ポスト';
    if (mb_strlen($text) > 140) $text = mb_substr($text, 0, 140) . '…';

    $title = $r['name'] . ' (@' . $r['handle'] . ') さんのポスト';
    $url   = SITE_URL . '/p/' . $id;
    $dest  = SITE_URL . '/#/post/' . $id;
    $abs   = fn(string $p) => SITE_URL . '/' . ltrim($p, '/');

    if ($img) {
        $image = $abs($img['path']); $card = 'summary_large_image';
        $iw = (int)$img['w']; $ih = (int)$img['h'];
    } elseif ($r['avatar']) {
        $image = $abs($r['avatar']); $card = 'summary'; $iw = $ih = 400;
    } else {
        $image = $abs(OGP_DEFAULT); $card = 'summary_large_image'; $iw = $ih = 0;
    }

    header_remove('Set-Cookie');
    header('Cache-Control: public, max-age=300');
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8">'
       . '<title>' . $h($title) . '</title>'
       . '<meta name="description" content="' . $h($text) . '">'
       . '<link rel="canonical" href="' . $h($url) . '">'
       . '<meta property="og:type" content="article">'
       . '<meta property="og:site_name" content="Z">'
       . '<meta property="og:title" content="' . $h($title) . '">'
       . '<meta property="og:description" content="' . $h($text) . '">'
       . '<meta property="og:url" content="' . $h($url) . '">'
       . '<meta property="og:image" content="' . $h($image) . '">'
       . ($iw && $ih ? '<meta property="og:image:width" content="' . $iw . '"><meta property="og:image:height" content="' . $ih . '">' : '')
       . '<meta property="article:published_time" content="' . gmdate('c', (int)$r['created_at']) . '">'
       . '<meta name="twitter:card" content="' . $card . '">'
       . '<meta name="twitter:title" content="' . $h($title) . '">'
       . '<meta name="twitter:description" content="' . $h($text) . '">'
       . '<meta name="twitter:image" content="' . $h($image) . '">'
       . '</head><body>'
       // クローラーは JS を実行しないので上の meta が読まれ、人間はアプリ本体へ移動する
       . '<script>location.replace(' . json_encode($dest, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ');</script>'
       . '<noscript><p><a href="' . $h($dest) . '">ポストを見る</a></p></noscript>'
       . '</body></html>';
}

/* ---------- ルーター ---------- */
try {
    $a = (string)($_GET['a'] ?? '');
    $MUT = ['signup', 'login', 'logout', 'post', 'delete_post', 'like', 'react', 'repost', 'bookmark',
            'profile', 'follow', 'dm_send', 'upload_image', 'remove_image', 'noview', 'view_seen',
            'upload_media', 'delete_media', 'emoji_add', 'emoji_delete', 'upload_deco', 'remove_deco'];
    $isPost = in_array($a, $MUT, true);
    if ($isPost) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('POSTで送信してください', 405);
        if (!hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) fail('セッションが切れました。ページを再読み込みしてください', 403);
        $b = in_array($a, ['upload_image', 'upload_media', 'emoji_add', 'upload_deco'], true) ? $_POST : json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($b)) $b = [];
    } else {
        $b = $_GET;
    }

    switch ($a) {
        case 'share':
            share_page((int)($b['id'] ?? 0));
            exit;

        case 'me':
            $u = cur();
            $unread = $u ? (int)q('SELECT COUNT(*) FROM notifs WHERE user_id=? AND id>?', [$u['id'], $u['notif_read']])->fetchColumn() : 0;
            out(['csrf' => $_SESSION['csrf'], 'user' => $u ? uj($u) : null, 'unread' => $unread]);

        case 'signup': {
            if (!SIGNUP_OPEN) fail('現在、新規登録は受け付けていません', 403);
            throttle_check('su:' . ip(), 5, 3600);
            $h = str($b, 'handle');
            $n = clean_name(str($b, 'name'));
            $pw = isset($b['password']) && is_string($b['password']) ? $b['password'] : '';
            if (!preg_match('/^[A-Za-z0-9_]{3,15}$/', $h)) fail('ユーザー名は半角英数字と _ の3〜15文字にしてください');
            if (in_array(strtolower($h), RESERVED, true)) fail('このユーザー名は使えません');
            if ($n === '') $n = $h;
            if (mb_strlen($n) > 30) fail('表示名は30文字以内にしてください');
            if (strlen($pw) < 8 || strlen($pw) > 200) fail('パスワードは8文字以上にしてください');
            if (q('SELECT 1 FROM users WHERE handle=?', [$h])->fetch()) fail('このユーザー名は既に使われています', 409);
            q('INSERT INTO users(handle, name, pass, created_at) VALUES(?,?,?,?)', [$h, $n, password_hash($pw, PASSWORD_DEFAULT), time()]);
            $id = (int)db()->lastInsertId();
            throttle_hit('su:' . ip());
            session_regenerate_id(true);
            $_SESSION['uid'] = $id;
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
        }

        case 'login': {
            $k = 'li:' . ip();
            throttle_check($k, 10, 600);
            $pw = isset($b['password']) && is_string($b['password']) ? $b['password'] : '';
            $u = user_by_handle($b['handle'] ?? '');
            if (!$u || !password_verify($pw, $u['pass'])) {
                throttle_hit($k);
                fail('ユーザー名またはパスワードが違います', 401);
            }
            if (password_needs_rehash($u['pass'], PASSWORD_DEFAULT)) q('UPDATE users SET pass=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
        }

        case 'logout':
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            out(['ok' => true]);

        case 'timeline': {
            $m = viewer('tl', 90);
            $fa = $fb = '';
            if ($m && ($b['scope'] ?? '') === 'following') {
                $set = "(SELECT followee_id FROM follows WHERE follower_id=$m UNION SELECT $m)";
                $fa = " AND p.user_id IN $set";
                $fb = " AND r.user_id IN $set";
            }
            $rows = q("SELECT * FROM (
                SELECT p.id pid, p.created_at ts, 0 by_id FROM posts p WHERE p.deleted=0 AND p.parent_id IS NULL$fa
                UNION ALL
                SELECT r.post_id pid, r.created_at ts, r.user_id by_id FROM reposts r JOIN posts p ON p.id=r.post_id WHERE p.deleted=0$fb
            ) ORDER BY ts DESC LIMIT 200")->fetchAll();
            $seen = [];
            foreach ($rows as $r) if (!isset($seen[$r['pid']])) $seen[$r['pid']] = $r;
            $seen = array_slice($seen, 0, 100, true);
            $byIds = array_values(array_unique(array_filter(array_map(fn($r) => (int)$r['by_id'], $seen))));
            $byU = [];
            if ($byIds) foreach (q('SELECT * FROM users WHERE id IN (' . ins($byIds) . ')', $byIds)->fetchAll() as $r) $byU[(int)$r['id']] = $r;
            $posts = [];
            foreach (decorate(array_keys($seen), $m) as $p) {
                $s = $seen[$p['id']];
                $bu = $byU[(int)$s['by_id']] ?? null;
                $posts[] = $p + ['rtBy' => $bu ? uj($bu) + ['mine' => (int)$bu['id'] === $m] : null, '_s' => (int)$s['ts']];
            }
            out(['posts' => $posts]);
        }

        case 'thread': {
            $u = cur();
            $m = viewer('th', 90);
            $id = (int)($b['id'] ?? 0);
            $post = decorate([$id], $m)[0] ?? null;
            if (!$post) fail('ポストが見つかりません', 404);
            $rs = q('SELECT id FROM posts WHERE parent_id=? AND deleted=0 ORDER BY created_at ASC LIMIT 200', [$id])->fetchAll(PDO::FETCH_COLUMN);
            $author = (int)q('SELECT user_id FROM posts WHERE id=?', [$id])->fetchColumn();
            if ($u && $author !== $m && !$u['no_view']) q('INSERT OR REPLACE INTO views(post_id, user_id, ts) VALUES(?,?,?)', [$id, $m, time()]);
            // 閲覧者の一覧は、ログインしている人にだけ見せる
            $vs = $u ? q('SELECT u.* FROM views v JOIN users u ON u.id=v.user_id WHERE v.post_id=? AND v.user_id<>? ORDER BY v.ts DESC LIMIT 200', [$id, $author])->fetchAll() : [];
            out(['post' => $post, 'replies' => decorate($rs, $m), 'viewers' => array_map('uj', $vs), 'canRecord' => (bool)$u, 'noview' => $u ? (bool)$u['no_view'] : false]);
        }

        case 'view_seen': {
            // 画面に表示されたポスト(開かなくても)を閲覧履歴に入れる。「閲覧を記録しない」設定の人・自分のポストは対象外
            $u = need();
            $m = (int)$u['id'];
            if ($u['no_view']) out(['n' => 0]);
            throttle('vs:' . $m, 60, 60);
            $ids = is_array($b['ids'] ?? null) ? $b['ids'] : [];
            $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 50);
            if (!$ids) out(['n' => 0]);
            $rows = q('SELECT id FROM posts WHERE deleted=0 AND user_id<>? AND id IN (' . ins($ids) . ')', array_merge([$m], $ids))->fetchAll(PDO::FETCH_COLUMN);
            $now = time();
            foreach ($rows as $pid) q('INSERT OR REPLACE INTO views(post_id, user_id, ts) VALUES(?,?,?)', [(int)$pid, $m, $now]);
            out(['n' => count($rows)]);
        }

        case 'noview': {
            $u = need();
            $nv = $u['no_view'] ? 0 : 1;
            q('UPDATE users SET no_view=? WHERE id=?', [$nv, $u['id']]);
            if ($nv) q('DELETE FROM views WHERE user_id=?', [$u['id']]);
            out(['noview' => (bool)$nv]);
        }

        case 'post': {
            $u = need();
            $m = (int)$u['id'];
            $body = clean_text(isset($b['body']) && is_string($b['body']) ? $b['body'] : '');
            $mids = [];
            if (isset($b['media'])) {
                if (!is_array($b['media'])) fail('メディアの指定が不正です');
                $mids = array_values(array_unique(array_filter(array_map('intval', $b['media']))));
            }
            if ($body === '' && !$mids) fail('本文を入力してください');
            if (mb_strlen($body) > MAX_POST) fail('本文は' . MAX_POST . '文字以内にしてください');
            if ($mids) {
                if (count($mids) > MAX_MEDIA) fail('添付できるのは画像' . MAX_MEDIA . '枚までです');
                $mrows = q('SELECT id, kind FROM media WHERE user_id=? AND post_id IS NULL AND id IN (' . ins($mids) . ')', array_merge([$m], $mids))->fetchAll();
                if (count($mrows) !== count($mids)) fail('添付メディアが見つかりません。もう一度アップロードしてください');
                if (in_array('video', array_column($mrows, 'kind'), true) && count($mids) > 1) fail('動画は1本のみ添付できます');
            }
            $parent = null;
            if (!empty($b['parent_id'])) {
                $parent = q('SELECT id, user_id FROM posts WHERE id=? AND deleted=0', [(int)$b['parent_id']])->fetch();
                if (!$parent) fail('返信先のポストが見つかりません', 404);
            }
            $quote = null;
            if (!empty($b['quote_id'])) {
                if ($parent) fail('返信と引用は同時にできません');
                $quote = q('SELECT id, user_id FROM posts WHERE id=? AND deleted=0', [(int)$b['quote_id']])->fetch();
                if (!$quote) fail('引用元のポストが見つかりません', 404);
            }
            throttle('po:' . $m, 30, 60);
            $now = time();
            q('INSERT INTO posts(user_id, parent_id, quote_id, body, created_at) VALUES(?,?,?,?,?)', [$m, $parent ? $parent['id'] : null, $quote ? $quote['id'] : null, $body, $now]);
            $id = (int)db()->lastInsertId();
            foreach ($mids as $i => $mid) q('UPDATE media SET post_id=?, pos=? WHERE id=? AND user_id=? AND post_id IS NULL', [$id, $i, $mid, $m]);
            if (preg_match_all('/(?:^|\s)#([\p{L}\p{N}_]{1,50})/u', $body, $mm)) {
                foreach (array_unique(array_map('mb_strtolower', $mm[1])) as $t) q('INSERT OR IGNORE INTO tags(post_id, tag, created_at) VALUES(?,?,?)', [$id, $t, $now]);
            }
            index_terms($id, $m, $body, $now);
            q('DELETE FROM trend_cache');
            if (mt_rand(1, 50) === 1) q('DELETE FROM terms WHERE created_at < ?', [$now - 8 * 86400]);
            $done = [$m];
            if ($parent) {
                notify((int)$parent['user_id'], $m, 'reply', $id);
                $done[] = (int)$parent['user_id'];
            }
            if ($quote) {
                notify((int)$quote['user_id'], $m, 'quote', $id);
                $done[] = (int)$quote['user_id'];
            }
            if (preg_match_all('/(?:^|\s)@([A-Za-z0-9_]{3,15})/', $body, $mm)) {
                foreach (array_slice(array_unique(array_map('strtolower', $mm[1])), 0, 10) as $h) {
                    $t = user_by_handle($h);
                    if ($t && !in_array((int)$t['id'], $done, true)) {
                        notify((int)$t['id'], $m, 'mention', $id);
                        $done[] = (int)$t['id'];
                    }
                }
            }
            out(['id' => $id]);
        }

        case 'delete_post': {
            $u = need();
            $id = (int)($b['id'] ?? 0);
            if (q('UPDATE posts SET deleted=1 WHERE id=? AND user_id=? AND deleted=0', [$id, $u['id']])->rowCount()) {
                q('DELETE FROM tags WHERE post_id=?', [$id]);
                q('DELETE FROM terms WHERE post_id=?', [$id]);
                q('DELETE FROM trend_cache');
                q('DELETE FROM notifs WHERE post_id=?', [$id]);
                q('DELETE FROM bookmarks WHERE post_id=?', [$id]);
                foreach (q('SELECT id, path FROM media WHERE post_id=?', [$id])->fetchAll() as $mr) {
                    rm_file($mr['path']);
                    q('DELETE FROM media WHERE id=?', [$mr['id']]);
                }
            }
            out(['ok' => true]);
        }

        case 'like': {
            $u = need();
            $m = (int)$u['id'];
            $id = (int)($b['id'] ?? 0);
            $p = live_post($id);
            if (q('SELECT 1 FROM likes WHERE user_id=? AND post_id=?', [$m, $id])->fetch()) {
                q('DELETE FROM likes WHERE user_id=? AND post_id=?', [$m, $id]);
                unnotify((int)$p['user_id'], $m, 'like', $id);
                out(['on' => false]);
            }
            throttle('lk:' . $m, 120, 60);
            q('INSERT OR IGNORE INTO likes(user_id, post_id, created_at) VALUES(?,?,?)', [$m, $id, time()]);
            notify((int)$p['user_id'], $m, 'like', $id);
            out(['on' => true]);
        }

        case 'react': {
            $u = need();
            $m = (int)$u['id'];
            $id = (int)($b['id'] ?? 0);
            $e = str($b, 'emoji');
            if (!in_array($e, RX_SET, true)) {
                if (!preg_match('/^:([a-z0-9_]{2,32}):$/', $e, $em) || !q('SELECT 1 FROM emojis WHERE name=?', [$em[1]])->fetch()) fail('このリアクションは使えません');
            }
            $p = live_post($id);
            if (q('SELECT 1 FROM reactions WHERE user_id=? AND post_id=? AND emoji=?', [$m, $id, $e])->fetch()) {
                q('DELETE FROM reactions WHERE user_id=? AND post_id=? AND emoji=?', [$m, $id, $e]);
                unnotify((int)$p['user_id'], $m, 'react', $id, $e);
                out(['on' => false]);
            }
            throttle('lk:' . $m, 120, 60);
            q('INSERT OR IGNORE INTO reactions(user_id, post_id, emoji, created_at) VALUES(?,?,?,?)', [$m, $id, $e, time()]);
            notify((int)$p['user_id'], $m, 'react', $id, $e);
            out(['on' => true]);
        }

        case 'repost': {
            $u = need();
            $m = (int)$u['id'];
            $id = (int)($b['id'] ?? 0);
            $p = live_post($id);
            if (q('SELECT 1 FROM reposts WHERE user_id=? AND post_id=?', [$m, $id])->fetch()) {
                q('DELETE FROM reposts WHERE user_id=? AND post_id=?', [$m, $id]);
                unnotify((int)$p['user_id'], $m, 'repost', $id);
                out(['on' => false]);
            }
            throttle('lk:' . $m, 120, 60);
            q('INSERT OR IGNORE INTO reposts(user_id, post_id, created_at) VALUES(?,?,?)', [$m, $id, time()]);
            notify((int)$p['user_id'], $m, 'repost', $id);
            out(['on' => true]);
        }

        case 'bookmark': {
            $u = need();
            $id = (int)($b['id'] ?? 0);
            live_post($id);
            if (q('SELECT 1 FROM bookmarks WHERE user_id=? AND post_id=?', [$u['id'], $id])->fetch()) {
                q('DELETE FROM bookmarks WHERE user_id=? AND post_id=?', [$u['id'], $id]);
                out(['on' => false]);
            }
            q('INSERT OR IGNORE INTO bookmarks(user_id, post_id, created_at) VALUES(?,?,?)', [$u['id'], $id, time()]);
            out(['on' => true]);
        }

        case 'bookmarks': {
            $u = need();
            $ids = q('SELECT post_id FROM bookmarks WHERE user_id=? ORDER BY created_at DESC LIMIT 50', [$u['id']])->fetchAll(PDO::FETCH_COLUMN);
            out(['posts' => decorate($ids, (int)$u['id'])]);
        }

        case 'user': {
            $m = viewer('us', 90);
            $t = user_by_handle($b['handle'] ?? '');
            if (!$t) fail('ユーザーが見つかりません', 404);
            $tid = (int)$t['id'];
            $tab = (string)($b['tab'] ?? 'posts');
            if ($tab === 'replies') $ids = q('SELECT id FROM posts WHERE user_id=? AND parent_id IS NOT NULL AND deleted=0 ORDER BY created_at DESC LIMIT 100', [$tid])->fetchAll(PDO::FETCH_COLUMN);
            elseif ($tab === 'likes') $ids = $tid === $m ? q('SELECT post_id FROM likes WHERE user_id=? ORDER BY created_at DESC LIMIT 100', [$tid])->fetchAll(PDO::FETCH_COLUMN) : [];
            else $ids = q('SELECT id FROM posts WHERE user_id=? AND parent_id IS NULL AND deleted=0 ORDER BY created_at DESC LIMIT 100', [$tid])->fetchAll(PDO::FETCH_COLUMN);
            $one = fn(string $sql) => (int)q($sql, [$tid])->fetchColumn();
            out([
                'user' => uj($t) + [
                    'joined' => (int)$t['created_at'],
                    'followers' => $one('SELECT COUNT(*) FROM follows WHERE followee_id=?'),
                    'following' => $one('SELECT COUNT(*) FROM follows WHERE follower_id=?'),
                    'posts' => $one('SELECT COUNT(*) FROM posts WHERE user_id=? AND parent_id IS NULL AND deleted=0'),
                    'is_following' => (bool)q('SELECT 1 FROM follows WHERE follower_id=? AND followee_id=?', [$m, $tid])->fetch(),
                    'mine' => $tid === $m,
                ],
                'posts' => decorate($ids, $m),
            ]);
        }

        case 'profile': {
            $u = need();
            $n = clean_name(str($b, 'name'));
            $bio = preg_replace('/[^\P{Cc}\n]+/u', '', str_replace(["\r\n", "\r"], "\n", str($b, 'bio'))) ?? '';
            $bio = trim($bio);
            if ($n === '') fail('名前を入力してください');
            if (mb_strlen($n) > 30) fail('名前は30文字以内にしてください');
            if (mb_strlen($bio) > 160) fail('自己紹介は160文字以内にしてください');
            $badges = array_key_exists('badges', $b) ? parse_badges($b['badges']) : null;
            q('UPDATE users SET name=?, bio=? WHERE id=?', [$n, $bio, $u['id']]);
            if ($badges !== null) q('UPDATE users SET badges=? WHERE id=?', [json_encode($badges, JSON_UNESCAPED_UNICODE), $u['id']]);
            out(uj(q('SELECT * FROM users WHERE id=?', [$u['id']])->fetch()));
        }

        case 'upload_image': {
            $u = need();
            $kind = (string)($b['kind'] ?? '');
            if (!in_array($kind, ['avatar', 'banner'], true)) fail('不正な種類です');
            if (!function_exists('imagecreatefromstring')) fail('サーバーにGD拡張が必要です', 500);
            $f = $_FILES['file'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) fail('画像をアップロードできませんでした');
            if ($f['size'] > 3 * 1024 * 1024) fail('画像は3MB以下にしてください');
            throttle('up:' . $u['id'], 20, 3600);
            $info = @getimagesize($f['tmp_name']);
            if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) fail('JPEG / PNG / WebP / GIF の画像を選んでください');
            if ($info[0] * $info[1] > 25000000) fail('画像のサイズが大きすぎます');
            [$w, $h] = $kind === 'avatar' ? [400, 400] : [1500, 500];
            $name = $kind . '_' . $u['id'] . '_' . bin2hex(random_bytes(6)) . '.jpg';
            save_image($f['tmp_name'], $w, $h, $name);
            rm_img($u[$kind]);
            q("UPDATE users SET $kind=? WHERE id=?", ['uploads/' . $name, $u['id']]);
            out([$kind => 'uploads/' . $name]);
        }

        case 'remove_image': {
            $u = need();
            $kind = (string)($b['kind'] ?? '');
            if (!in_array($kind, ['avatar', 'banner'], true)) fail('不正な種類です');
            rm_img($u[$kind]);
            q("UPDATE users SET $kind=NULL WHERE id=?", [$u['id']]);
            out(['ok' => true]);
        }

        case 'upload_deco': {
            $u = need();
            $m = (int)$u['id'];
            @ini_set('memory_limit', '256M');
            if (!function_exists('imagecreatefromstring')) fail('サーバーにGD拡張が必要です', 500);
            $f = upload_file();
            if ($f['size'] > MAX_DECO_BYTES) fail('画像は' . (MAX_DECO_BYTES >> 20) . 'MB以下にしてください');
            throttle('dc:' . $m, 20, 3600);
            $info = @getimagesize($f['tmp_name']);
            if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) fail('透過に対応した PNG / WebP / GIF の画像を選んでください');
            if ($info[0] * $info[1] > 16000000) fail('画像のサイズが大きすぎます');
            $raw = (string)file_get_contents($f['tmp_name']);
            if ($info[2] === IMAGETYPE_GIF && gif_animated($raw)) {
                // アニメGIFは再エンコードせずそのまま保存
                if ($info[0] > 512 || $info[1] > 512 || $f['size'] > 1024 * 1024) fail('アニメーションGIFは512px以下・1MB以下にしてください');
                $dir = ensure_dir('d');
                $fn = 'd' . $m . '_' . bin2hex(random_bytes(10)) . '.gif';
                if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $fn)) fail('保存できませんでした', 500);
                $path = 'uploads/d/' . $fn;
            } else {
                $im = @imagecreatefromstring($raw);
                if (!$im) fail('この画像は読み込めません');
                if (!has_alpha($im)) fail('透過部分のある画像を選んでください(枠や飾りの周りが透明な画像)');
                unset($im, $raw);
                [$path] = store_image($f['tmp_name'], $info, 'd', 'd' . $m . '_', 256, true);   // 256px以内・透過PNGに再エンコード
            }
            rm_file($u['deco'] ?? null);
            q('UPDATE users SET deco=? WHERE id=?', [$path, $m]);
            out(['deco' => $path]);
        }

        case 'remove_deco': {
            $u = need();
            rm_file($u['deco'] ?? null);
            q('UPDATE users SET deco=NULL WHERE id=?', [$u['id']]);
            out(['ok' => true]);
        }

        case 'follow': {
            $u = need();
            $m = (int)$u['id'];
            $t = user_by_handle($b['handle'] ?? '');
            if (!$t || (int)$t['id'] === $m) fail('フォローできません');
            $tid = (int)$t['id'];
            if (q('SELECT 1 FROM follows WHERE follower_id=? AND followee_id=?', [$m, $tid])->fetch()) {
                q('DELETE FROM follows WHERE follower_id=? AND followee_id=?', [$m, $tid]);
                unnotify($tid, $m, 'follow', null);
                out(['on' => false]);
            }
            throttle('fo:' . $m, 60, 60);
            q('INSERT OR IGNORE INTO follows(follower_id, followee_id, created_at) VALUES(?,?,?)', [$m, $tid, time()]);
            notify($tid, $m, 'follow', null);
            out(['on' => true]);
        }

        case 'follows': {
            $m = viewer('fw', 60);
            $t = user_by_handle($b['handle'] ?? '');
            if (!$t) fail('ユーザーが見つかりません', 404);
            $sql = ($b['kind'] ?? '') === 'following'
                ? 'SELECT u.* FROM follows f JOIN users u ON u.id=f.followee_id WHERE f.follower_id=? ORDER BY f.created_at DESC LIMIT 100'
                : 'SELECT u.* FROM follows f JOIN users u ON u.id=f.follower_id WHERE f.followee_id=? ORDER BY f.created_at DESC LIMIT 100';
            out(['user' => ['handle' => $t['handle'], 'name' => $t['name']], 'users' => user_rows(q($sql, [$t['id']])->fetchAll(), $m)]);
        }

        case 'search': {
            $m = viewer('se', 30);
            $s = trim((string)($b['q'] ?? ''));
            if ($s === '') out(['users' => [], 'posts' => []]);
            if ($s[0] === '#') {
                $ids = q('SELECT t.post_id FROM tags t JOIN posts p ON p.id=t.post_id WHERE t.tag=? AND p.deleted=0 ORDER BY t.created_at DESC LIMIT 60', [mb_strtolower(mb_substr($s, 1))])->fetchAll(PDO::FETCH_COLUMN);
                out(['users' => [], 'posts' => decorate($ids, $m)]);
            }
            $s = ltrim($s, '@');
            $like = '%' . addcslashes($s, '%_\\') . '%';
            $us = q("SELECT * FROM users WHERE handle LIKE ? ESCAPE '\\' OR name LIKE ? ESCAPE '\\' ORDER BY (handle=?) DESC, created_at DESC LIMIT 10", [$like, $like, $s])->fetchAll();
            $ids = q("SELECT id FROM posts WHERE deleted=0 AND body LIKE ? ESCAPE '\\' ORDER BY created_at DESC LIMIT 50", [$like])->fetchAll(PDO::FETCH_COLUMN);
            out(['users' => user_rows($us, $m), 'posts' => decorate($ids, $m)]);
        }

        case 'trends': {
            out(['trends' => trends_cached(), 'users' => []]);
        }

        case 'notifs': {
            $u = need();
            $read = (int)$u['notif_read'];
            $rows = q('SELECT n.*, a.handle, a.name, a.avatar, a.deco, p.body, (SELECT COUNT(*) FROM media WHERE post_id=p.id) mc FROM notifs n JOIN users a ON a.id=n.actor_id LEFT JOIN posts p ON p.id=n.post_id AND p.deleted=0 WHERE n.user_id=? ORDER BY n.id DESC LIMIT 50', [$u['id']])->fetchAll();
            $enames = [];
            foreach ($rows as $r) if ($r['type'] === 'react' && preg_match('/^:([a-z0-9_]{2,32}):$/', (string)$r['emoji'], $mm)) $enames[] = $mm[1];
            $emap = emoji_map($enames);
            $items = array_map(fn($r) => [
                'type' => $r['type'], 'post_id' => $r['post_id'] ? (int)$r['post_id'] : null,
                'is_read' => (int)$r['id'] <= $read ? 1 : 0, 'ts' => (int)$r['created_at'],
                'handle' => $r['handle'], 'name' => $r['name'], 'avatar' => $r['avatar'] ?: null, 'deco' => $r['deco'] ?: null,
                'emoji' => $r['emoji'],
                'emojis' => (preg_match('/^:([a-z0-9_]{2,32}):$/', (string)$r['emoji'], $mm) && isset($emap[$mm[1]])) ? [$mm[1] => $emap[$mm[1]]] : [],
                'excerpt' => $r['body'] === null ? '' : ($r['body'] === '' && (int)$r['mc'] > 0 ? '（画像・動画）' : mb_substr($r['body'], 0, 140)),
            ], $rows);
            q('UPDATE users SET notif_read=(SELECT COALESCE(MAX(id),0) FROM notifs WHERE user_id=?) WHERE id=?', [$u['id'], $u['id']]);
            out(['items' => $items]);
        }

        case 'convos': {
            $u = need();
            $m = (int)$u['id'];
            $rows = q("SELECT d.body, d.created_at ts, d.from_id, x.handle, x.name, x.avatar, x.deco FROM dms d
                JOIN (SELECT MAX(id) mid FROM dms WHERE from_id=$m OR to_id=$m GROUP BY CASE WHEN from_id=$m THEN to_id ELSE from_id END) l ON l.mid=d.id
                JOIN users x ON x.id = CASE WHEN d.from_id=$m THEN d.to_id ELSE d.from_id END
                ORDER BY d.id DESC LIMIT 100")->fetchAll();
            out(['items' => array_map(fn($r) => [
                'handle' => $r['handle'], 'name' => $r['name'], 'avatar' => $r['avatar'] ?: null, 'deco' => $r['deco'] ?: null,
                'body' => $r['body'], 'ts' => (int)$r['ts'], 'mine' => (int)$r['from_id'] === $m,
            ], $rows)]);
        }

        case 'dm': {
            $u = need();
            $m = (int)$u['id'];
            $t = user_by_handle($b['handle'] ?? '');
            if (!$t) fail('ユーザーが見つかりません', 404);
            $tid = (int)$t['id'];
            $rows = q('SELECT * FROM (SELECT * FROM dms WHERE (from_id=? AND to_id=?) OR (from_id=? AND to_id=?) ORDER BY id DESC LIMIT 200) ORDER BY id ASC', [$m, $tid, $tid, $m])->fetchAll();
            out(['user' => ['handle' => $t['handle'], 'name' => $t['name'], 'avatar' => $t['avatar'] ?: null],
                 'msgs' => array_map(fn($r) => ['body' => $r['body'], 'ts' => (int)$r['created_at'], 'mine' => (int)$r['from_id'] === $m], $rows)]);
        }

        case 'dm_send': {
            $u = need();
            $m = (int)$u['id'];
            $t = user_by_handle($b['handle'] ?? '');
            if (!$t || (int)$t['id'] === $m) fail('送信できません');
            $body = clean_text(isset($b['body']) && is_string($b['body']) ? $b['body'] : '');
            if ($body === '') fail('メッセージを入力してください');
            if (mb_strlen($body) > 1000) fail('メッセージは1000文字以内にしてください');
            throttle('dm:' . $m, 60, 60);
            q('INSERT INTO dms(from_id, to_id, body, created_at) VALUES(?,?,?,?)', [$m, $t['id'], $body, time()]);
            out(['ok' => true]);
        }

        case 'upload_media': {
            $u = need();
            $m = (int)$u['id'];
            @ini_set('memory_limit', '256M');
            $f = upload_file();
            throttle('um:' . $m, 40, 3600);
            if (mt_rand(1, 20) === 1) cleanup_media();
            if ((int)q('SELECT COUNT(*) FROM media WHERE user_id=? AND post_id IS NULL', [$m])->fetchColumn() >= 8) fail('未投稿のメディアが多すぎます。先に投稿するか削除してください');
            $info = @getimagesize($f['tmp_name']);
            $dir = ensure_dir('m');
            if ($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
                if ($f['size'] > MAX_IMG_BYTES) fail('画像は' . (MAX_IMG_BYTES >> 20) . 'MB以下にしてください');
                if ($info[0] * $info[1] > 20000000) fail('画像のサイズが大きすぎます');
                $kind = 'image';
                if ($info[2] === IMAGETYPE_GIF && gif_animated((string)file_get_contents($f['tmp_name']))) {
                    $name = 'm' . $m . '_' . bin2hex(random_bytes(10)) . '.gif';   // アニメGIFは再エンコードせず保持
                    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) fail('保存できませんでした', 500);
                    [$path, $w, $h] = ['uploads/m/' . $name, (int)$info[0], (int)$info[1]];
                } else {
                    [$path, $w, $h] = store_image($f['tmp_name'], $info, 'm', 'm' . $m . '_', 2048);
                }
            } else {
                $ext = video_ext($f['tmp_name']);
                if (!$ext) fail('対応していない形式です(画像: JPEG/PNG/WebP/GIF、動画: MP4/WebM/MOV)');
                if ($f['size'] > MAX_VIDEO_BYTES) fail('動画は' . (MAX_VIDEO_BYTES >> 20) . 'MB以下にしてください');
                $kind = 'video';
                $name = 'v' . $m . '_' . bin2hex(random_bytes(10)) . '.' . $ext;
                if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) fail('保存できませんでした', 500);
                $path = 'uploads/m/' . $name;
                $w = max(0, min(8000, (int)($b['w'] ?? 0)));
                $h = max(0, min(8000, (int)($b['h'] ?? 0)));
            }
            q('INSERT INTO media(user_id, kind, path, w, h, created_at) VALUES(?,?,?,?,?,?)', [$m, $kind, $path, $w, $h, time()]);
            out(['id' => (int)db()->lastInsertId(), 'kind' => $kind, 'url' => $path, 'w' => $w, 'h' => $h]);
        }

        case 'delete_media': {
            $u = need();
            $r = q('SELECT id, path FROM media WHERE id=? AND user_id=? AND post_id IS NULL', [(int)($b['id'] ?? 0), $u['id']])->fetch();
            if ($r) {
                rm_file($r['path']);
                q('DELETE FROM media WHERE id=?', [$r['id']]);
            }
            out(['ok' => true]);
        }

        case 'emoji_list': {
            $u = cur();
            $rows = q('SELECT e.name, e.path, e.user_id, u.handle FROM emojis e JOIN users u ON u.id=e.user_id ORDER BY e.name LIMIT 1000')->fetchAll();
            out(['emojis' => array_map(fn($r) => ['name' => $r['name'], 'url' => $r['path'], 'by' => $r['handle'], 'mine' => $u && (int)$r['user_id'] === (int)$u['id']], $rows)]);
        }

        case 'emoji_add': {
            $u = need();
            $m = (int)$u['id'];
            @ini_set('memory_limit', '256M');
            $name = strtolower(trim((string)($b['name'] ?? '')));
            if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) fail('名前は半角英数字と _ の2〜32文字にしてください');
            $f = upload_file();
            if ($f['size'] > MAX_EMOJI_BYTES) fail('画像は' . (MAX_EMOJI_BYTES >> 20) . 'MB以下にしてください');
            throttle('em:' . $m, 20, 3600);
            if (q('SELECT 1 FROM emojis WHERE name=?', [$name])->fetch()) fail('この名前は既に使われています', 409);
            if ((int)q('SELECT COUNT(*) FROM emojis WHERE user_id=?', [$m])->fetchColumn() >= MAX_EMOJI_PER_USER) fail('登録できる絵文字は1人' . MAX_EMOJI_PER_USER . '個までです');
            $info = @getimagesize($f['tmp_name']);
            if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) fail('JPEG / PNG / WebP / GIF の画像を選んでください');
            if ($info[0] * $info[1] > 20000000) fail('画像のサイズが大きすぎます');
            if ($info[2] === IMAGETYPE_GIF && gif_animated((string)file_get_contents($f['tmp_name']))) {
                if ($info[0] > 256 || $info[1] > 256 || $f['size'] > 512 * 1024) fail('アニメーションGIFは256px以下・512KB以下にしてください');
                $dir = ensure_dir('e');
                $fn = 'e' . $m . '_' . bin2hex(random_bytes(10)) . '.gif';
                if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $fn)) fail('保存できませんでした', 500);
                $path = 'uploads/e/' . $fn;
            } else {
                [$path] = store_image($f['tmp_name'], $info, 'e', 'e' . $m . '_', 128, true);
            }
            try {
                q('INSERT INTO emojis(name, user_id, path, created_at) VALUES(?,?,?,?)', [$name, $m, $path, time()]);
            } catch (PDOException $ex) {
                rm_file($path);
                fail('この名前は既に使われています', 409);
            }
            out(['name' => $name, 'url' => $path]);
        }

        case 'emoji_delete': {
            $u = need();
            $name = strtolower(str($b, 'name'));
            $r = q('SELECT id, path FROM emojis WHERE name=? AND user_id=?', [$name, $u['id']])->fetch();
            if (!$r) fail('絵文字が見つかりません', 404);
            q('DELETE FROM emojis WHERE id=?', [$r['id']]);
            q('DELETE FROM reactions WHERE emoji=?', [':' . $name . ':']);
            q('DELETE FROM notifs WHERE type=? AND emoji=?', ['react', ':' . $name . ':']);
            foreach (q('SELECT id, badges FROM users WHERE badges LIKE ?', ['%:' . $name . ':%'])->fetchAll() as $ur) {
                $a = json_decode((string)$ur['badges'], true);
                if (!is_array($a)) continue;
                $keep = array_values(array_filter($a, fn($x) => $x !== ':' . $name . ':'));
                if (count($keep) !== count($a)) q('UPDATE users SET badges=? WHERE id=?', [json_encode($keep, JSON_UNESCAPED_UNICODE), $ur['id']]);
            }
            rm_file($r['path']);
            out(['ok' => true]);
        }

        case 'ogp': {
            $u = cur();
            out(['ogp' => ogp((string)($b['url'] ?? ''), $u ? (string)(int)$u['id'] : 'g' . ip())]);
        }
    }
    fail('未対応の操作です', 404);
} catch (Throwable $e) {
    error_log('[Z] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail('サーバーエラーが発生しました', 500);
}
