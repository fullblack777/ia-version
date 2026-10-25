<?php
declare(strict_types=1);

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/Sao_Paulo');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_set_cookie_params([
        'lifetime' => 86400, 'path' => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_name('HIPCTL');
    session_start();
}

if (empty($_SESSION['_init'])) {
    $_SESSION['_init'] = time();
    session_regenerate_id(true);
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-XSS-Protection: 1; mode=block');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header("Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' https: data:; img-src 'self' data: https:; connect-src 'self' https:;");
header_remove('X-Powered-By');

// ============ CONFIG ============
define('BRAND', '@hipolitoofc');
define('SITE_NAME', 'HIPOLITO CLT');
define('DB_DIR', __DIR__ . '/data');
define('DB_FILE', DB_DIR . '/hipolito.db');
define('API_DIR', __DIR__ . '/api');
define('LOGINS_DIR', __DIR__ . '/logins');
define('LIVE_COST', 1.00);
define('DIE_COST', 0.20);
define('DEP_MIN', 10.00);
define('DEP_MAX', 200.00);
define('PIX_COOLDOWN', 600);
define('ADMIN_USER', 'hipolito');
define('ADMIN_PASS', 'hipolitoofcadm');
define('BOT_TOKEN', '8952767112:AAGsG1yxraZXY0IkZ3QmIGu5_jfTXhhhGas');
define('ADMIN_TG', 8617449427);
define('ELITE_ID', 'ep_421737e484563dee4971477ca0c87575');
define('ELITE_SECRET', 'eps_43f74326495af4ef95c43bf9b501ef122e4410dc6a8eb48d9f19f972509e2945');
define('ELITE_WEBHOOK_SECRET', 'eps_43f74326495af4ef95c43bf9b501ef122e4410dc6a8eb48d9f19f972509e2945');
define('ELITE_URL', 'https://api.elitepaybr.com');
define('BASE_URL', getenv('BASE_URL') ?: 'https://hipolito-ctl.onrender.com');
define('AFFILIATE_PCT', 0.10); // 10% do depósito pro indicador
define('2FA_TTL', 300);          // 5 minutos

foreach ([DB_DIR, API_DIR, LOGINS_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
    @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    @file_put_contents($d . '/index.html', '');
}

// ============ DB ============
function db(): SQLite3 {
    static $db = null;
    if ($db !== null) return $db;
    $db = new SQLite3(DB_FILE);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');
    $db->exec('PRAGMA foreign_keys = ON');

    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        ip TEXT,
        balance REAL DEFAULT 0,
        total_checks INTEGER DEFAULT 0,
        total_lives INTEGER DEFAULT 0,
        total_dies INTEGER DEFAULT 0,
        total_deposited REAL DEFAULT 0,
        ref_code TEXT UNIQUE,
        ref_by INTEGER,
        ref_earnings REAL DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        last_login TEXT, last_seen TEXT
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS sessions (token TEXT PRIMARY KEY, user_id INTEGER NOT NULL, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, expires_at TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS deposits (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, transaction_id TEXT UNIQUE, amount REAL NOT NULL, net_amount REAL, status TEXT DEFAULT "pending", qrcode_url TEXT, copy_paste TEXT, payer_name TEXT, payer_document TEXT, ip TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, paid_at TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS checks (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, gate TEXT NOT NULL, gate_type TEXT NOT NULL, card TEXT NOT NULL, bin TEXT, result TEXT NOT NULL, cost REAL NOT NULL, response TEXT, ip TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('CREATE TABLE IF NOT EXISTS audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, username TEXT, action TEXT NOT NULL, details TEXT, ip TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('CREATE TABLE IF NOT EXISTS rate_limit (k TEXT PRIMARY KEY, attempts INTEGER DEFAULT 1, first_attempt INTEGER)');
    // NOVAS TABELAS
    $db->exec('CREATE TABLE IF NOT EXISTS user_apis (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, gate TEXT NOT NULL, api_key TEXT NOT NULL, api_secret TEXT, api_extra TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, gate))');
    $db->exec('CREATE TABLE IF NOT EXISTS twofa (user_key TEXT PRIMARY KEY, code TEXT NOT NULL, expires INTEGER NOT NULL, attempts INTEGER DEFAULT 0)');
    $db->exec('CREATE TABLE IF NOT EXISTS coupons (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE NOT NULL, amount REAL NOT NULL, max_uses INTEGER DEFAULT 1, uses INTEGER DEFAULT 0, expires_at TEXT, active INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('CREATE TABLE IF NOT EXISTS coupon_uses (id INTEGER PRIMARY KEY AUTOINCREMENT, coupon_id INTEGER NOT NULL, user_id INTEGER NOT NULL, amount REAL NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    return $db;
}

// ============ HELPERS ============
function clientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrfOk(?string $t): bool {
    return !empty($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'], $t);
}
function jsonOut(array $d, int $c = 200): void {
    http_response_code($c);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fmtMoney(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

function tg(string $msg, ?int $chatId = null): void {
    try {
        $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chatId ?? ADMIN_TG, 'text' => $msg, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        @curl_exec($ch);
        @curl_close($ch);
    } catch (Throwable $e) {}
}

// ============ USER ============
function getSessionUser(): ?array {
    if (!empty($_SESSION['user_id'])) {
        $u = userById((int)$_SESSION['user_id']);
        if ($u) return $u;
    }
    if (!empty($_COOKIE['hipctl_token'])) {
        $u = userBySession($_COOKIE['hipctl_token']);
        if ($u) { $_SESSION['user_id'] = (int)$u['id']; return $u; }
    }
    return null;
}
function userById(int $id): ?array {
    $s = db()->prepare('SELECT * FROM users WHERE id = :i');
    $s->bindValue(':i', $id, SQLITE3_INTEGER);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}
function userByUsername(string $u): ?array {
    $s = db()->prepare('SELECT * FROM users WHERE username = :u');
    $s->bindValue(':u', $u, SQLITE3_TEXT);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}
function userByRefCode(string $c): ?array {
    $s = db()->prepare('SELECT * FROM users WHERE ref_code = :c');
    $s->bindValue(':c', $c, SQLITE3_TEXT);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}
function userBySession(string $t): ?array {
    $s = db()->prepare('SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = :t AND s.expires_at > CURRENT_TIMESTAMP');
    $s->bindValue(':t', $t, SQLITE3_TEXT);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}
function genRefCode(): string {
    do {
        $c = strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
        $exists = userByRefCode($c);
    } while ($exists);
    return $c;
}
function userCreate(string $username, string $password, string $ip, ?string $refBy = null): array {
    $db = db();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ref = genRefCode();
    $refByUserId = null;
    if ($refBy) {
        $indicador = userByRefCode(strtoupper($refBy));
        if ($indicador) $refByUserId = (int)$indicador['id'];
    }
    $s = $db->prepare('INSERT INTO users (username, password_hash, ip, ref_code, ref_by, last_seen) VALUES (:u,:p,:ip,:rc,:rb,CURRENT_TIMESTAMP)');
    $s->bindValue(':u', $username); $s->bindValue(':p', $hash);
    $s->bindValue(':ip', $ip); $s->bindValue(':rc', $ref);
    if ($refByUserId) $s->bindValue(':rb', $refByUserId, SQLITE3_INTEGER);
    else $s->bindValue(':rb', null, SQLITE3_NULL);
    if (!$s->execute()) return ['ok' => false, 'error' => 'Erro ao criar conta'];
    return ['ok' => true, 'id' => $db->lastInsertRowID()];
}
function userTouch(int $id): void {
    $s = db()->prepare('UPDATE users SET last_seen = CURRENT_TIMESTAMP, last_login = CURRENT_TIMESTAMP WHERE id = :i');
    $s->bindValue(':i', $id, SQLITE3_INTEGER); $s->execute();
}
function userAddBalance(int $id, float $a): float {
    $s = db()->prepare('UPDATE users SET balance = balance + :a, total_deposited = total_deposited + :a WHERE id = :i');
    $s->bindValue(':a', $a, SQLITE3_FLOAT); $s->bindValue(':i', $id, SQLITE3_INTEGER);
    $s->execute();
    $u = userById($id);
    return (float)$u['balance'];
}
function userAddBalanceOnly(int $id, float $a): float {
    $s = db()->prepare('UPDATE users SET balance = balance + :a WHERE id = :i');
    $s->bindValue(':a', $a, SQLITE3_FLOAT); $s->bindValue(':i', $id, SQLITE3_INTEGER);
    $s->execute();
    $u = userById($id);
    return (float)$u['balance'];
}
function userDeductBalance(int $id, float $a): bool {
    $u = userById($id);
    if (!$u || $u['balance'] < $a) return false;
    $s = db()->prepare('UPDATE users SET balance = balance - :a WHERE id = :i');
    $s->bindValue(':a', $a, SQLITE3_FLOAT); $s->bindValue(':i', $id, SQLITE3_INTEGER);
    return $s->execute() !== false;
}
function userSetBalance(int $id, float $a): void {
    $s = db()->prepare('UPDATE users SET balance = :a WHERE id = :i');
    $s->bindValue(':a', max(0, $a), SQLITE3_FLOAT); $s->bindValue(':i', $id, SQLITE3_INTEGER);
    $s->execute();
}

function sessionCreate(int $uid, string $ip, string $ua): string {
    $t = bin2hex(random_bytes(32));
    $s = db()->prepare('INSERT INTO sessions (token, user_id, ip, user_agent, expires_at) VALUES (:t,:u,:ip,:ua,datetime("now","+86400 seconds"))');
    $s->bindValue(':t', $t); $s->bindValue(':u', $uid);
    $s->bindValue(':ip', $ip); $s->bindValue(':ua', $ua);
    $s->execute();
    return $t;
}
function sessionDestroy(string $t): void {
    $s = db()->prepare('DELETE FROM sessions WHERE token = :t');
    $s->bindValue(':t', $t); $s->execute();
}

function auditLog(?int $uid, ?string $un, string $action, string $details, string $ip): void {
    try {
        $s = db()->prepare('INSERT INTO audit_log (user_id, username, action, details, ip) VALUES (:u,:un,:a,:d,:ip)');
        $s->bindValue(':u', $uid, SQLITE3_INTEGER);
        $s->bindValue(':un', $un, SQLITE3_TEXT);
        $s->bindValue(':a', $action, SQLITE3_TEXT);
        $s->bindValue(':d', $details, SQLITE3_TEXT);
        $s->bindValue(':ip', $ip, SQLITE3_TEXT);
        $s->execute();
    } catch (Throwable $e) {}
}

function rateCheck(string $action, string $ip, int $max, int $window): bool {
    $db = db();
    $key = $action . ':' . $ip;
    $s = $db->prepare('SELECT attempts, first_attempt FROM rate_limit WHERE k = :k');
    $s->bindValue(':k', $key, SQLITE3_TEXT);
    $row = $s->execute()->fetchArray(SQLITE3_ASSOC);
    $now = time();
    if (!$row) {
        $s = $db->prepare('INSERT INTO rate_limit (k, attempts, first_attempt) VALUES (:k,1,:t)');
        $s->bindValue(':k', $key); $s->bindValue(':t', $now, SQLITE3_INTEGER); $s->execute();
        return true;
    }
    if ($now - $row['first_attempt'] > $window) {
        $s = $db->prepare('UPDATE rate_limit SET attempts=1, first_attempt=:t WHERE k=:k');
        $s->bindValue(':t', $now, SQLITE3_INTEGER); $s->bindValue(':k', $key); $s->execute();
        return true;
    }
    if ($row['attempts'] >= $max) return false;
    $s = $db->prepare('UPDATE rate_limit SET attempts=attempts+1 WHERE k=:k');
    $s->bindValue(':k', $key); $s->execute();
    return true;
}

// ============ 2FA ============
function twofaSend(string $userKey): string {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $exp = time() + 2FA_TTL;
    $s = db()->prepare('INSERT OR REPLACE INTO twofa (user_key, code, expires, attempts) VALUES (:k,:c,:e,0)');
    $s->bindValue(':k', $userKey); $s->bindValue(':c', $code); $s->bindValue(':e', $exp, SQLITE3_INTEGER);
    $s->execute();
    tg("🔐 <b>CÓDIGO 2FA ADMIN</b>\n\n<b>{$code}</b>\n\nExpira em 5 minutos.\nIP: <code>" . clientIp() . "</code>\n⚡ " . BRAND);
    return $code;
}
function twofaVerify(string $userKey, string $code): bool {
    $s = db()->prepare('SELECT * FROM twofa WHERE user_key = :k');
    $s->bindValue(':k', $userKey);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$r) return false;
    if ($r['expires'] < time()) return false;
    if ($r['attempts'] >= 5) return false;
    if (!hash_equals($r['code'], $code)) {
        $s = db()->prepare('UPDATE twofa SET attempts = attempts + 1 WHERE user_key = :k');
        $s->bindValue(':k', $userKey); $s->execute();
        return false;
    }
    $s = db()->prepare('DELETE FROM twofa WHERE user_key = :k');
    $s->bindValue(':k', $userKey); $s->execute();
    return true;
}

// ============ DEPOSITS ============
function eliteDeposit(float $amount): array {
    $ch = curl_init(ELITE_URL . '/api/v1/deposit');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'amount' => round($amount, 2),
            'description' => 'Deposito HIPOLITO CLT',
            'projectWebhook' => BASE_URL . '/index.php?action=webhook',
        ]),
        CURLOPT_HTTPHEADER => [
            'x-client-id: ' . ELITE_ID,
            'x-client-secret: ' . ELITE_SECRET,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code < 200 || $code >= 300) return ['ok' => false, 'error' => 'Falha ElitePay (HTTP ' . $code . ')'];
    $d = json_decode($resp, true);
    if (empty($d['transactionId'])) return ['ok' => false, 'error' => 'Resposta sem TXID'];
    return ['ok' => true, 'txid' => $d['transactionId'], 'qr' => $d['qrcodeUrl'] ?? '', 'copy' => $d['copyPaste'] ?? ''];
}
function eliteVerifyWebhookSignature(string $ts, string $body, string $sig): bool {
    $expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, ELITE_WEBHOOK_SECRET);
    return hash_equals($expected, $sig);
}
function depositByTxid(string $txid): ?array {
    $s = db()->prepare('SELECT * FROM deposits WHERE transaction_id = :t');
    $s->bindValue(':t', $txid); $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}
function depositConfirm(string $txid, ?float $net = null, ?string $pn = null, ?string $pd = null): bool {
    $db = db();
    $d = depositByTxid($txid);
    if (!$d || $d['status'] === 'paid') return false;
    $db->exec('BEGIN IMMEDIATE');
    try {
        $s = $db->prepare('UPDATE deposits SET status="paid", paid_at=CURRENT_TIMESTAMP, net_amount=:n, payer_name=:pn, payer_document=:pd WHERE transaction_id=:t');
        $s->bindValue(':n', $net ?? $d['amount'], SQLITE3_FLOAT);
        $s->bindValue(':pn', $pn); $s->bindValue(':pd', $pd);
        $s->bindValue(':t', $txid); $s->execute();
        userAddBalance((int)$d['user_id'], (float)($net ?? $d['amount']));

        // ===== AFILIADO: 10% pro indicador =====
        $u = userById((int)$d['user_id']);
        if ($u && !empty($u['ref_by'])) {
            $comissao = round((float)($net ?? $d['amount']) * AFFILIATE_PCT, 2);
            if ($comissao > 0) {
                userAddBalanceOnly((int)$u['ref_by'], $comissao);
                $s = $db->prepare('UPDATE users SET ref_earnings = ref_earnings + :c WHERE id = :i');
                $s->bindValue(':c', $comissao, SQLITE3_FLOAT);
                $s->bindValue(':i', (int)$u['ref_by'], SQLITE3_INTEGER);
                $s->execute();
                $ind = userById((int)$u['ref_by']);
                tg("💸 <b>COMISSÃO AFILIADO</b>\n\n👤 Indicador: <b>" . ($ind['username'] ?? '?') . "</b>\n👥 Indicado: <b>" . ($u['username'] ?? '?') . "</b>\n💵 +" . fmtMoney($comissao) . "\n⚡ " . BRAND);
            }
        }
        $db->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        return false;
    }
}
function depositPendente(int $userId): ?array {
    $s = db()->prepare('SELECT * FROM deposits WHERE user_id = :u AND status = "pending" ORDER BY id DESC LIMIT 1');
    $s->bindValue(':u', $userId, SQLITE3_INTEGER);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}
function canCreatePix(int $userId): array {
    $s = db()->prepare('SELECT * FROM deposits WHERE user_id = :u ORDER BY id DESC LIMIT 1');
    $s->bindValue(':u', $userId, SQLITE3_INTEGER);
    $last = $s->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$last) return ['ok' => true];
    if ($last['status'] === 'pending') {
        $el = time() - strtotime($last['created_at']);
        if ($el < PIX_COOLDOWN) return ['ok' => false, 'reason' => 'pending', 'wait' => PIX_COOLDOWN - $el];
        $s = db()->prepare('UPDATE deposits SET status="expired" WHERE transaction_id=:t');
        $s->bindValue(':t', $last['transaction_id']); $s->execute();
        return ['ok' => true];
    }
    if ($last['status'] === 'paid') {
        $el = time() - strtotime($last['paid_at'] ?? $last['created_at']);
        if ($el < PIX_COOLDOWN && $el > 0) return ['ok' => false, 'reason' => 'recently_paid', 'wait' => PIX_COOLDOWN - $el];
    }
    return ['ok' => true];
}

// ============ USER APIs ============
function userApiGet(int $uid, string $gate): ?array {
    $s = db()->prepare('SELECT * FROM user_apis WHERE user_id = :u AND gate = :g');
    $s->bindValue(':u', $uid, SQLITE3_INTEGER);
    $s->bindValue(':g', $gate);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}
function userApiSet(int $uid, string $gate, string $key, ?string $secret, ?string $extra): void {
    $s = db()->prepare('INSERT INTO user_apis (user_id, gate, api_key, api_secret, api_extra) VALUES (:u,:g,:k,:s,:e)
        ON CONFLICT(user_id, gate) DO UPDATE SET api_key=excluded.api_key, api_secret=excluded.api_secret, api_extra=excluded.api_extra');
    $s->bindValue(':u', $uid, SQLITE3_INTEGER);
    $s->bindValue(':g', $gate);
    $s->bindValue(':k', $key);
    $s->bindValue(':s', $secret);
    $s->bindValue(':e', $extra);
    $s->execute();
}

// ============ LIST ============
function listGates(): array {
    $g = ['gate' => [], 'login' => []];
    foreach (glob(API_DIR . '/*.{php,py}', GLOB_BRACE) ?: [] as $f) {
        $n = basename($f);
        if ($n === 'index.php') continue;
        $g['gate'][] = $n;
    }
    foreach (glob(LOGINS_DIR . '/*.{php,py}', GLOB_BRACE) ?: [] as $f) {
        $n = basename($f);
        if ($n === 'index.php') continue;
        $g['login'][] = $n;
    }
    sort($g['gate']); sort($g['login']);
    return $g;
}

// ============ STATS / LISTS ============
function statsGlobal(): array {
    $db = db(); $r = [];
    $r['users'] = (int)$db->querySingle('SELECT COUNT(*) FROM users');
    $r['deposits_total'] = (float)$db->querySingle('SELECT COALESCE(SUM(net_amount),0) FROM deposits WHERE status="paid"');
    $r['deposits_count'] = (int)$db->querySingle('SELECT COUNT(*) FROM deposits WHERE status="paid"');
    $r['checks_total'] = (int)$db->querySingle('SELECT COUNT(*) FROM checks');
    $r['lives_total'] = (int)$db->querySingle('SELECT COUNT(*) FROM checks WHERE result="live"');
    $r['dies_total'] = (int)$db->querySingle('SELECT COUNT(*) FROM checks WHERE result="die"');
    $r['deposits_pending'] = (int)$db->querySingle('SELECT COUNT(*) FROM deposits WHERE status="pending"');
    return $r;
}
function listAllUsers(): array {
    $r = db()->query('SELECT * FROM users ORDER BY id DESC');
    $o = []; while ($row = $r->fetchArray(SQLITE3_ASSOC)) $o[] = $row; return $o;
}
function listRecentDeposits(int $limit = 50): array {
    $s = db()->prepare('SELECT d.*, u.username FROM deposits d LEFT JOIN users u ON u.id=d.user_id ORDER BY d.id DESC LIMIT :l');
    $s->bindValue(':l', $limit, SQLITE3_INTEGER);
    $r = $s->execute(); $o = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $o[] = $row; return $o;
}
function listRecentChecks(int $limit = 100): array {
    $s = db()->prepare('SELECT c.*, u.username FROM checks c LEFT JOIN users u ON u.id=c.user_id ORDER BY c.id DESC LIMIT :l');
    $s->bindValue(':l', $limit, SQLITE3_INTEGER);
    $r = $s->execute(); $o = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $o[] = $row; return $o;
}
function listAudit(int $limit = 100): array {
    $s = db()->prepare('SELECT * FROM audit_log ORDER BY id DESC LIMIT :l');
    $s->bindValue(':l', $limit, SQLITE3_INTEGER);
    $r = $s->execute(); $o = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $o[] = $row; return $o;
}

// filtros admin
function listChecksFiltered(?string $user, ?string $gate, ?string $date, ?string $status, int $limit = 500): array {
    $sql = 'SELECT c.*, u.username FROM checks c LEFT JOIN users u ON u.id=c.user_id WHERE 1=1';
    $p = [];
    if ($user) { $sql .= ' AND u.username LIKE :u'; $p[':u'] = '%' . $user . '%'; }
    if ($gate) { $sql .= ' AND c.gate = :g'; $p[':g'] = $gate; }
    if ($date) { $sql .= ' AND date(c.created_at) = :d'; $p[':d'] = $date; }
    if ($status === 'live') { $sql .= ' AND c.result = "live"'; }
    elseif ($status === 'die') { $sql .= ' AND c.result = "die"'; }
    $sql .= ' ORDER BY c.id DESC LIMIT :l';
    $s = db()->prepare($sql);
    foreach ($p as $k => $v) $s->bindValue($k, $v);
    $s->bindValue(':l', $limit, SQLITE3_INTEGER);
    $r = $s->execute(); $o = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $o[] = $row;
    return $o;
}
function listDepositsFiltered(?string $user, ?string $date, ?string $status, int $limit = 500): array {
    $sql = 'SELECT d.*, u.username FROM deposits d LEFT JOIN users u ON u.id=d.user_id WHERE 1=1';
    $p = [];
    if ($user) { $sql .= ' AND u.username LIKE :u'; $p[':u'] = '%' . $user . '%'; }
    if ($date) { $sql .= ' AND date(d.created_at) = :d'; $p[':d'] = $date; }
    if ($status) { $sql .= ' AND d.status = :s'; $p[':s'] = $status; }
    $sql .= ' ORDER BY d.id DESC LIMIT :l';
    $s = db()->prepare($sql);
    foreach ($p as $k => $v) $s->bindValue($k, $v);
    $s->bindValue(':l', $limit, SQLITE3_INTEGER);
    $r = $s->execute(); $o = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $o[] = $row;
    return $o;
}
function checksByDay(int $days = 14): array {
    $out = [];
    $db = db();
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $s = $db->prepare('SELECT
            SUM(CASE WHEN result="live" THEN 1 ELSE 0 END) as l,
            SUM(CASE WHEN result="die" THEN 1 ELSE 0 END) as d
            FROM checks WHERE date(created_at) = :d');
        $s->bindValue(':d', $d);
        $row = $s->execute()->fetchArray(SQLITE3_ASSOC);
        $out[] = ['date' => $d, 'lives' => (int)($row['l'] ?? 0), 'dies' => (int)($row['d'] ?? 0)];
    }
    return $out;
}

// ============ GATE EXEC ============
function runGatePhp(string $path, string $lista, string $gateName, ?array $userApi = null): string {
    // Se o servidor tem proc_open, usa isolado; senão include com buffer
    $canProc = function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);

    if ($canProc) {
        $phpBin = PHP_BINARY ?: 'php';
        $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
        $env = $_ENV;
        $env['GATE_LISTA'] = $lista;
        $env['GATE_NAME'] = $gateName;
        $env['QUERY_STRING'] = http_build_query(['lista' => $lista, 'gate' => $gateName]);
        // passa API do usuário como env
        if ($userApi) {
            $env['USER_API_KEY'] = $userApi['api_key'] ?? '';
            $env['USER_API_SECRET'] = $userApi['api_secret'] ?? '';
            $env['USER_API_EXTRA'] = $userApi['api_extra'] ?? '';
        }
        $cmd = escapeshellarg($phpBin) . ' -d display_errors=0 -d log_errors=0 -d error_reporting=0 '
             . escapeshellarg($path) . ' ' . escapeshellarg($lista);
        $proc = @proc_open($cmd, $descriptors, $pipes, dirname($path), $env);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $stdout = ''; $stderr = ''; $start = time();
            while (true) {
                $status = proc_get_status($proc);
                $read = [$pipes[1], $pipes[2]]; $write = null; $except = null;
                if (@stream_select($read, $write, $except, 0, 200000) > 0) {
                    foreach ($read as $s) {
                        $chunk = fread($s, 8192);
                        if ($chunk === false) continue;
                        if ($s === $pipes[1]) $stdout .= $chunk;
                        else $stderr .= $chunk;
                    }
                }
                if (!$status['running']) {
                    $stdout .= stream_get_contents($pipes[1]) ?: '';
                    $stderr .= stream_get_contents($pipes[2]) ?: '';
                    break;
                }
                if (time() - $start > 60) {
                    @proc_terminate($proc, 9);
                    $stdout .= "\n⏱ Timeout 60s";
                    break;
                }
            }
            @fclose($pipes[1]); @fclose($pipes[2]);
            @proc_close($proc);
            $out = trim($stdout);
            if ($out === '' && $stderr !== '') $out = '❌ Erro: ' . trim(substr($stderr, 0, 500));
            if ($out === '') $out = '❌ Sem resposta';
            return $out;
        }
    }

    // Fallback: include com buffer (para Render free que bloqueia proc_open)
    $_GET['lista'] = $lista;
    $_GET['gate'] = $gateName;
    if ($userApi) {
        putenv('USER_API_KEY=' . ($userApi['api_key'] ?? ''));
        putenv('USER_API_SECRET=' . ($userApi['api_secret'] ?? ''));
        putenv('USER_API_EXTRA=' . ($userApi['api_extra'] ?? ''));
        $_ENV['USER_API_KEY'] = $userApi['api_key'] ?? '';
        $_ENV['USER_API_SECRET'] = $userApi['api_secret'] ?? '';
        $_ENV['USER_API_EXTRA'] = $userApi['api_extra'] ?? '';
    }
    ob_start();
    try {
        include $path;
        $out = ob_get_clean();
    } catch (Throwable $e) {
        while (ob_get_level() > 0) @ob_end_clean();
        $out = '❌ Erro: ' . $e->getMessage();
    }
    $out = trim((string)$out);
    if ($out === '') $out = '❌ Sem resposta';
    return $out;
}
function runGatePython(string $path, string $lista): string {
    $canShell = function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);
    if (!$canShell) return '❌ Python não disponível neste servidor';
    $cmd = 'python3 ' . escapeshellarg($path) . ' ' . escapeshellarg($lista) . ' 2>&1';
    $out = @shell_exec($cmd);
    if ($out === null) $out = '❌ Sem resposta';
    $out = trim((string)$out);
    return $out === '' ? '❌ Sem resposta' : $out;
}

// ============ AÇÕES ============
$ip = clientIp();

if (isset($_GET['action']) && $_GET['action'] === 'ping') {
    header('Content-Type: text/plain'); echo 'OK:' . time(); exit;
}

// CSV export (admin)
if (isset($_GET['action']) && $_GET['action'] === 'export' && !empty($_SESSION['admin'])) {
    $type = $_GET['type'] ?? 'checks';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $type . '_' . date('Ymd_His') . '.csv"');
    $fp = fopen('php://output', 'w');
    if ($type === 'deposits') {
        fputcsv($fp, ['ID','Usuario','Valor','Liquido','Status','Payer','IP','TXID','Criado','Pago']);
        foreach (listRecentDeposits(5000) as $d) {
            fputcsv($fp, [$d['id'],$d['username']??'', $d['amount'],$d['net_amount']??'', $d['status'],$d['payer_name']??'', $d['ip']??'', $d['transaction_id']??'', $d['created_at'],$d['paid_at']??'']);
        }
    } else {
        fputcsv($fp, ['ID','Usuario','Gate','Tipo','Cartao','BIN','Resultado','Custo','IP','Criado']);
        foreach (listRecentChecks(5000) as $c) {
            fputcsv($fp, [$c['id'],$c['username']??'', $c['gate'],$c['gate_type'],$c['card'],$c['bin']??'', $c['result'],$c['cost'],$c['ip']??'', $c['created_at']]);
        }
    }
    fclose($fp);
    exit;
}

// webhook ElitePay
if (isset($_GET['action']) && $_GET['action'] === 'webhook') {
    $raw = file_get_contents('php://input');
    $ts = $_SERVER['HTTP_X_ELITE_TIMESTAMP'] ?? '';
    $sig = $_SERVER['HTTP_X_ELITE_SIGNATURE'] ?? '';
    if (!$raw || !$ts || !$sig) { http_response_code(400); echo 'bad'; exit; }
    if (!eliteVerifyWebhookSignature($ts, $raw, $sig)) { http_response_code(401); echo 'invalid sig'; exit; }
    $payload = json_decode($raw, true);
    if (!is_array($payload)) { http_response_code(400); echo 'invalid json'; exit; }
    $txid = $payload['transactionId'] ?? $payload['acquirerTransactionId'] ?? '';
    $state = $payload['transactionState'] ?? '';
    $status = $payload['status'] ?? '';
    $netValue = (float)($payload['netValue'] ?? $payload['value'] ?? 0);
    $pn = $payload['payer']['name'] ?? ($payload['payerName'] ?? '');
    $pd = $payload['payer']['document'] ?? ($payload['payerDocument'] ?? '');
    if (!$txid) { http_response_code(200); echo 'no txid'; exit; }
    $d = depositByTxid($txid);
    if (!$d) { auditLog(null, null, 'webhook_orphan', "txid=$txid state=$state", $ip); http_response_code(200); echo 'ok'; exit; }
    if ($d['status'] === 'paid') { http_response_code(200); echo 'already paid'; exit; }
    $isComplete = in_array($state, ['COMPLETO'], true) || in_array($status, ['aprovado','concluido'], true);
    $isFailed = in_array($state, ['FALHA','RECUSADO','CANCELADO','DEVOLVIDO','REEMBOLSADO','ESTORNO'], true);
    if ($isComplete) {
        $net = $netValue > 0 ? $netValue : (float)$d['amount'];
        if (depositConfirm($txid, $net, $pn, $pd)) {
            $u = userById((int)$d['user_id']);
            tg("💰 <b>DEPÓSITO CONFIRMADO</b>\n\n👤 <b>" . ($u['username'] ?? '?') . "</b>\n💵 " . fmtMoney($net) . "\n⚡ " . BRAND);
            auditLog((int)$d['user_id'], $u['username'] ?? null, 'deposit_paid', "amount=$net txid=$txid", $ip);
        }
    } elseif ($isFailed) {
        $s = db()->prepare('UPDATE deposits SET status=:s WHERE transaction_id=:t');
        $s->bindValue(':s', strtolower($state)); $s->bindValue(':t', $txid); $s->execute();
    }
    http_response_code(200); echo 'ok'; exit;
}

if (isset($_GET['logout'])) {
    if (!empty($_COOKIE['hipctl_token'])) sessionDestroy($_COOKIE['hipctl_token']);
    setcookie('hipctl_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
    $_SESSION = []; session_destroy();
    header('Location: index.php'); exit;
}

// ============ POST ============
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        if (strlen($raw) > 2 * 1048576) jsonOut(['ok' => false, 'error' => 'Payload grande'], 413);
        $json = json_decode($raw, true);
        if (!is_array($json)) $json = $_POST;
        $action = $json['action'] ?? '';
        if ($action === '') jsonOut(['ok' => false, 'error' => 'Ação vazia'], 400);

        // ===== ADMIN LOGIN (com 2FA) =====
        if ($action === 'admin_login') {
            if (!rateCheck('admin_login', $ip, 10, 900)) jsonOut(['ok' => false, 'error' => 'Muitas tentativas'], 429);
            $u = trim($json['username'] ?? ''); $p = $json['password'] ?? '';
            if ($u === ADMIN_USER && hash_equals(ADMIN_PASS, $p)) {
                $key = 'admin:' . $ip;
                twofaSend($key);
                auditLog(null, 'admin', 'admin_2fa_sent', "ip=$ip", $ip);
                jsonOut(['ok' => true, 'need2fa' => true, 'msg' => 'Código enviado no Telegram']);
            }
            auditLog(null, $u, 'admin_login_fail', "ip=$ip", $ip);
            jsonOut(['ok' => false, 'error' => 'Credenciais inválidas']);
        }
        if ($action === 'admin_2fa_verify') {
            if (!rateCheck('admin_2fa', $ip, 10, 900)) jsonOut(['ok' => false, 'error' => 'Muitas tentativas'], 429);
            $code = trim($json['code'] ?? '');
            if (!preg_match('/^\d{6}$/', $code)) jsonOut(['ok' => false, 'error' => 'Código inválido']);
            if (!twofaVerify('admin:' . $ip, $code)) jsonOut(['ok' => false, 'error' => 'Código errado/expirado']);
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            auditLog(null, 'admin', 'admin_login_ok', "ip=$ip", $ip);
            jsonOut(['ok' => true, 'redirect' => 'index.php?admin=1']);
        }

        if ($action === 'admin_action') {
            if (empty($_SESSION['admin'])) jsonOut(['ok' => false, 'error' => 'Não autorizado'], 401);
            if (!csrfOk($json['csrf'] ?? '')) jsonOut(['ok' => false, 'error' => 'CSRF inválido'], 403);
            $sub = $json['sub'] ?? '';
            if ($sub === 'add_balance') {
                $uid = (int)($json['user_id'] ?? 0); $amt = (float)($json['amount'] ?? 0);
                if ($uid <= 0 || $amt <= 0) jsonOut(['ok' => false, 'error' => 'Dados inválidos']);
                $u = userById($uid); if (!$u) jsonOut(['ok' => false, 'error' => 'User não existe']);
                $nb = userAddBalanceOnly($uid, $amt);
                auditLog($uid, $u['username'], 'admin_add_balance', "amt=$amt new=$nb", $ip);
                tg("💰 <b>ADMIN +SALDO</b>\n👤 {$u['username']}\n💵 +" . fmtMoney($amt) . "\n💳 Novo: " . fmtMoney($nb) . "\n⚡ " . BRAND);
                jsonOut(['ok' => true, 'balance' => $nb]);
            }
            if ($sub === 'rem_balance') {
                $uid = (int)($json['user_id'] ?? 0); $amt = (float)($json['amount'] ?? 0);
                if ($uid <= 0 || $amt <= 0) jsonOut(['ok' => false, 'error' => 'Dados inválidos']);
                $u = userById($uid); if (!$u) jsonOut(['ok' => false, 'error' => 'User não existe']);
                $nb = max(0, (float)$u['balance'] - $amt);
                userSetBalance($uid, $nb);
                auditLog($uid, $u['username'], 'admin_rem_balance', "amt=$amt new=$nb", $ip);
                jsonOut(['ok' => true, 'balance' => $nb]);
            }
            if ($sub === 'delete_user') {
                $uid = (int)($json['user_id'] ?? 0);
                if ($uid <= 0) jsonOut(['ok' => false, 'error' => 'ID inválido']);
                $u = userById($uid); if (!$u) jsonOut(['ok' => false, 'error' => 'User não existe']);
                $s = db()->prepare('DELETE FROM users WHERE id=:i'); $s->bindValue(':i', $uid, SQLITE3_INTEGER); $s->execute();
                auditLog(null, 'admin', 'admin_delete_user', "user={$u['username']} id=$uid", $ip);
                jsonOut(['ok' => true]);
            }
            if ($sub === 'create_coupon') {
                $code = strtoupper(trim($json['code'] ?? ''));
                $amount = (float)($json['amount'] ?? 0);
                $maxUses = (int)($json['max_uses'] ?? 1);
                $expires = trim($json['expires_at'] ?? '');
                if (!preg_match('/^[A-Z0-9]{3,20}$/', $code)) jsonOut(['ok' => false, 'error' => 'Código inválido (3-20 A-Z/0-9)']);
                if ($amount <= 0) jsonOut(['ok' => false, 'error' => 'Valor inválido']);
                if ($maxUses <= 0) $maxUses = 1;
                $s = db()->prepare('INSERT INTO coupons (code, amount, max_uses, expires_at) VALUES (:c,:a,:m,:e)');
                $s->bindValue(':c', $code); $s->bindValue(':a', $amount, SQLITE3_FLOAT);
                $s->bindValue(':m', $maxUses, SQLITE3_INTEGER);
                $s->bindValue(':e', $expires ?: null, $expires ? SQLITE3_TEXT : SQLITE3_NULL);
                if (!$s->execute()) jsonOut(['ok' => false, 'error' => 'Erro (código duplicado?)']);
                auditLog(null, 'admin', 'coupon_create', "code=$code amt=$amount", $ip);
                jsonOut(['ok' => true]);
            }
            jsonOut(['ok' => false, 'error' => 'Sub desconhecida'], 400);
        }

        if (!csrfOk($json['csrf'] ?? '')) jsonOut(['ok' => false, 'error' => 'Sessão expirada'], 403);

        // ===== REGISTER =====
        if ($action === 'register') {
            if (!empty($json['honeypot'])) jsonOut(['ok' => false, 'error' => 'Bloqueado'], 400);
            if (!rateCheck('register', $ip, 5, 3600)) jsonOut(['ok' => false, 'error' => 'Muitas tentativas']);
            $u = trim($json['username'] ?? ''); $p = $json['password'] ?? ''; $c = $json['confirm'] ?? '';
            $ref = trim($json['ref'] ?? '');
            if (strlen($u) < 3 || strlen($u) > 20) jsonOut(['ok' => false, 'error' => 'Usuário 3-20 chars']);
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $u)) jsonOut(['ok' => false, 'error' => 'Só letras/números/_']);
            if (strlen($p) < 8) jsonOut(['ok' => false, 'error' => 'Senha mín. 8']);
            if (preg_match('/^\d+$/', $p)) jsonOut(['ok' => false, 'error' => 'Senha não pode ser só números']);
            if ($p !== $c) jsonOut(['ok' => false, 'error' => 'Senhas não coincidem']);
            if (userByUsername($u)) jsonOut(['ok' => false, 'error' => 'Usuário já existe']);
            $r = userCreate($u, $p, $ip, $ref ?: null);
            if (!$r['ok']) jsonOut($r);
            session_regenerate_id(true);
            $t = sessionCreate((int)$r['id'], $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');
            setcookie('hipctl_token', $t, time() + 86400, '/', '', isset($_SERVER['HTTPS']), true);
            $_SESSION['user_id'] = (int)$r['id'];
            auditLog((int)$r['id'], $u, 'register', "ip=$ip ref=$ref", $ip);
            $novoUser = userById((int)$r['id']);
            tg("👤 <b>NOVO USUÁRIO</b>\n\n📛 <b>$u</b>\n📱 <code>$ip</code>\n" . ($ref ? "🔗 Indicado por: <b>$ref</b>\n" : '') . "🔑 Código afiliado: <code>{$novoUser['ref_code']}</code>\n⚡ " . BRAND);
            jsonOut(['ok' => true, 'redirect' => 'index.php']);
        }

        if ($action === 'login') {
            if (!empty($json['honeypot'])) jsonOut(['ok' => false, 'error' => 'Bloqueado'], 400);
            if (!rateCheck('login', $ip, 10, 900)) jsonOut(['ok' => false, 'error' => 'Muitas tentativas. Aguarde 15min']);
            $u = trim($json['username'] ?? ''); $p = $json['password'] ?? '';
            $user = userByUsername($u);
            if (!$user || !password_verify($p, $user['password_hash'])) {
                auditLog(null, $u, 'login_fail', "ip=$ip", $ip);
                jsonOut(['ok' => false, 'error' => 'Usuário ou senha inválidos']);
            }
            session_regenerate_id(true);
            $t = sessionCreate((int)$user['id'], $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');
            setcookie('hipctl_token', $t, time() + 86400, '/', '', isset($_SERVER['HTTPS']), true);
            $_SESSION['user_id'] = (int)$user['id'];
            userTouch((int)$user['id']);
            auditLog((int)$user['id'], $user['username'], 'login', "ip=$ip", $ip);
            jsonOut(['ok' => true, 'redirect' => 'index.php']);
        }

        // ===== USER ACTIONS =====
        if (in_array($action, ['deposit','check','balance','status','pix_allowed','my_checks','save_api','redeem_coupon','my_apis'], true)) {
            $user = getSessionUser();
            if (!$user) jsonOut(['ok' => false, 'error' => 'Faça login'], 401);
            userTouch((int)$user['id']);

            if ($action === 'pix_allowed') {
                $r = canCreatePix((int)$user['id']);
                $pend = depositPendente((int)$user['id']);
                $extra = [];
                if ($pend) $extra = ['txid' => $pend['transaction_id'], 'qr' => $pend['qrcode_url'], 'copy' => $pend['copy_paste'], 'amount' => (float)$pend['amount'], 'created_at' => $pend['created_at']];
                jsonOut(['ok' => true, 'allowed' => $r['ok'], 'reason' => $r['reason'] ?? null, 'wait' => $r['wait'] ?? 0, 'pending' => $extra]);
            }
            if ($action === 'deposit') {
                $c = canCreatePix((int)$user['id']);
                if (!$c['ok']) {
                    $msg = $c['reason'] === 'pending' ? 'Aguarde PIX pendente expirar/pagar (' . ceil($c['wait']/60) . 'min)' : 'Aguarde ' . ceil($c['wait']/60) . 'min';
                    jsonOut(['ok' => false, 'error' => $msg, 'wait' => $c['wait']]);
                }
                if (!rateCheck('dep', $ip, 5, 600)) jsonOut(['ok' => false, 'error' => 'Muitas tentativas']);
                $amt = (float)($json['amount'] ?? 0);
                if ($amt < DEP_MIN || $amt > DEP_MAX) jsonOut(['ok' => false, 'error' => 'Entre ' . fmtMoney(DEP_MIN) . ' e ' . fmtMoney(DEP_MAX)]);
                $r = eliteDeposit($amt);
                if (!$r['ok']) jsonOut(['ok' => false, 'error' => $r['error']]);
                $s = db()->prepare('INSERT INTO deposits (user_id, transaction_id, amount, status, qrcode_url, copy_paste, ip) VALUES (:u,:t,:a,"pending",:q,:c,:ip)');
                $s->bindValue(':u', (int)$user['id'], SQLITE3_INTEGER);
                $s->bindValue(':t', $r['txid']); $s->bindValue(':a', $amt, SQLITE3_FLOAT);
                $s->bindValue(':q', $r['qr']); $s->bindValue(':c', $r['copy']); $s->bindValue(':ip', $ip);
                $s->execute();
                auditLog((int)$user['id'], $user['username'], 'deposit_create', "amt=$amt txid={$r['txid']}", $ip);
                tg("🎫 <b>PIX GERADO</b>\n👤 {$user['username']}\n💵 " . fmtMoney($amt) . "\n⚡ " . BRAND);
                jsonOut(['ok' => true, 'txid' => $r['txid'], 'qr' => $r['qr'], 'copy' => $r['copy'], 'amount' => $amt]);
            }
            if ($action === 'status') {
                $txid = trim($json['txid'] ?? '');
                $d = depositByTxid($txid);
                if (!$d || (int)$d['user_id'] !== (int)$user['id']) jsonOut(['ok' => false, 'error' => 'Não encontrado']);
                jsonOut(['ok' => true, 'status' => $d['status'], 'amount' => (float)$d['amount']]);
            }
            if ($action === 'balance') {
                $fresh = userById((int)$user['id']);
                jsonOut(['ok' => true, 'balance' => (float)$fresh['balance']]);
            }
            if ($action === 'my_checks') {
                $s = db()->prepare('SELECT id, gate, gate_type, card, result, cost, created_at FROM checks WHERE user_id=:u ORDER BY id DESC LIMIT 100');
                $s->bindValue(':u', (int)$user['id'], SQLITE3_INTEGER);
                $r = $s->execute(); $o = [];
                while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
                    $row['card'] = substr($row['card'], 0, 6) . '***' . substr($row['card'], -4);
                    $o[] = $row;
                }
                jsonOut(['ok' => true, 'checks' => $o]);
            }
            if ($action === 'my_apis') {
                $s = db()->prepare('SELECT gate, api_key, api_secret, api_extra FROM user_apis WHERE user_id=:u');
                $s->bindValue(':u', (int)$user['id'], SQLITE3_INTEGER);
                $r = $s->execute(); $o = [];
                while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
                    $o[$row['gate']] = [
                        'api_key' => $row['api_key'],
                        'api_secret' => $row['api_secret'] ? substr($row['api_secret'], 0, 4) . '***' : '',
                        'has_secret' => !empty($row['api_secret']),
                        'api_extra' => $row['api_extra'],
                    ];
                }
                jsonOut(['ok' => true, 'apis' => $o]);
            }
            if ($action === 'save_api') {
                $gate = basename(trim($json['gate'] ?? ''));
                $key = trim($json['api_key'] ?? '');
                $secret = trim($json['api_secret'] ?? '');
                $extra = trim($json['api_extra'] ?? '');
                if (!$gate || !$key) jsonOut(['ok' => false, 'error' => 'Gate e API key obrigatórios']);
                $files = array_map('basename', glob(API_DIR . '/*.{php,py}', GLOB_BRACE) ?: []);
                if (!in_array($gate, $files, true)) jsonOut(['ok' => false, 'error' => 'Gate não existe']);
                userApiSet((int)$user['id'], $gate, $key, $secret ?: null, $extra ?: null);
                auditLog((int)$user['id'], $user['username'], 'save_api', "gate=$gate", $ip);
                jsonOut(['ok' => true]);
            }
            if ($action === 'redeem_coupon') {
                $code = strtoupper(trim($json['code'] ?? ''));
                if (!preg_match('/^[A-Z0-9]{3,20}$/', $code)) jsonOut(['ok' => false, 'error' => 'Código inválido']);
                if (!rateCheck('coupon', $ip, 10, 3600)) jsonOut(['ok' => false, 'error' => 'Muitas tentativas']);
                $db = db();
                $s = $db->prepare('SELECT * FROM coupons WHERE code=:c AND active=1');
                $s->bindValue(':c', $code);
                $cup = $s->execute()->fetchArray(SQLITE3_ASSOC);
                if (!$cup) jsonOut(['ok' => false, 'error' => 'Cupom não existe']);
                if ($cup['expires_at'] && strtotime($cup['expires_at']) < time()) jsonOut(['ok' => false, 'error' => 'Cupom expirado']);
                if ($cup['uses'] >= $cup['max_uses']) jsonOut(['ok' => false, 'error' => 'Cupom esgotado']);
                $s = $db->prepare('SELECT id FROM coupon_uses WHERE coupon_id=:c AND user_id=:u');
                $s->bindValue(':c', (int)$cup['id'], SQLITE3_INTEGER);
                $s->bindValue(':u', (int)$user['id'], SQLITE3_INTEGER);
                if ($s->execute()->fetchArray()) jsonOut(['ok' => false, 'error' => 'Você já usou este cupom']);
                $db->exec('BEGIN IMMEDIATE');
                try {
                    $s = $db->prepare('UPDATE coupons SET uses=uses+1 WHERE id=:i');
                    $s->bindValue(':i', (int)$cup['id'], SQLITE3_INTEGER); $s->execute();
                    $s = $db->prepare('INSERT INTO coupon_uses (coupon_id, user_id, amount) VALUES (:c,:u,:a)');
                    $s->bindValue(':c', (int)$cup['id'], SQLITE3_INTEGER);
                    $s->bindValue(':u', (int)$user['id'], SQLITE3_INTEGER);
                    $s->bindValue(':a', (float)$cup['amount'], SQLITE3_FLOAT);
                    $s->execute();
                    $nb = userAddBalanceOnly((int)$user['id'], (float)$cup['amount']);
                    $db->exec('COMMIT');
                    auditLog((int)$user['id'], $user['username'], 'coupon_use', "code=$code amt={$cup['amount']}", $ip);
                    tg("🎟️ <b>CUPOM RESGATADO</b>\n👤 {$user['username']}\n💵 +" . fmtMoney((float)$cup['amount']) . "\n🔑 <code>$code</code>\n⚡ " . BRAND);
                    jsonOut(['ok' => true, 'amount' => (float)$cup['amount'], 'balance' => $nb]);
                } catch (Throwable $e) {
                    $db->exec('ROLLBACK');
                    jsonOut(['ok' => false, 'error' => 'Erro ao resgatar']);
                }
            }
            if ($action === 'check') {
                if (!rateCheck('check', $ip, 60, 60)) jsonOut(['ok' => false, 'error' => 'Rate limit']);
                $gate = basename(trim($json['gate'] ?? ''));
                $type = $json['type'] ?? 'gate';
                $lista = trim($json['lista'] ?? '');
                if (!in_array($type, ['gate','login'], true)) jsonOut(['ok' => false, 'error' => 'Tipo inválido']);
                if (!$gate || !$lista) jsonOut(['ok' => false, 'error' => 'Dados vazios']);
                $dir = $type === 'gate' ? API_DIR : LOGINS_DIR;
                $files = array_map('basename', glob($dir . '/*.{php,py}', GLOB_BRACE) ?: []);
                if (!in_array($gate, $files, true)) jsonOut(['ok' => false, 'error' => 'Gate não existe']);
                $path = $dir . '/' . $gate;
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ((float)$user['balance'] < DIE_COST) jsonOut(['ok' => false, 'error' => 'Saldo insuficiente']);

                // pega API própria do user pra esse gate
                $userApi = $type === 'gate' ? userApiGet((int)$user['id'], $gate) : null;

                $tries = 0; $response = '';
                while ($tries < 2) {
                    if ($ext === 'php') $response = runGatePhp($path, $lista, $gate, $userApi);
                    elseif ($ext === 'py') $response = runGatePython($path, $lista);
                    else jsonOut(['ok' => false, 'error' => 'Extensão não suportada']);
                    if (strpos($response, '❌ Erro') !== 0 && strpos($response, '❌ Sem resposta') !== 0) break;
                    $tries++;
                    if ($tries < 2) usleep(300000);
                }

                $rl = strtolower($response);
                $liveWords = ['✅','aprovada','approved','success','live','autorizado','valid','aprovado','apvd','ativa','active','cvv','saldo','insuficiente'];
                $isLive = false;
                foreach ($liveWords as $w) { if (strpos($rl, $w) !== false) { $isLive = true; break; } }
                if (strpos($response, '❌') === 0) $isLive = false;

                $cost = $isLive ? LIVE_COST : DIE_COST;
                if (!userDeductBalance((int)$user['id'], $cost)) jsonOut(['ok' => false, 'error' => 'Saldo insuficiente']);
                $card = $type === 'gate' ? preg_replace('/[^0-9]/', '', explode('|', $lista)[0]) : $lista;
                $bin = $type === 'gate' ? substr($card, 0, 6) : '';
                $s = db()->prepare('INSERT INTO checks (user_id, gate, gate_type, card, bin, result, cost, response, ip) VALUES (:u,:g,:t,:c,:b,:r,:co,:re,:ip)');
                $s->bindValue(':u', (int)$user['id'], SQLITE3_INTEGER);
                $s->bindValue(':g', $gate); $s->bindValue(':t', $type);
                $s->bindValue(':c', $card); $s->bindValue(':b', $bin);
                $s->bindValue(':r', $isLive ? 'live' : 'die');
                $s->bindValue(':co', $cost, SQLITE3_FLOAT);
                $s->bindValue(':re', $response); $s->bindValue(':ip', $ip);
                $s->execute();
                $col = $isLive ? 'total_lives' : 'total_dies';
                $s = db()->prepare("UPDATE users SET total_checks = total_checks + 1, $col = $col + 1 WHERE id = :u");
                $s->bindValue(':u', (int)$user['id'], SQLITE3_INTEGER); $s->execute();

                // Notifica LIVE no Telegram
                if ($isLive) {
                    tg("🔥 <b>LIVE ENCONTRADA!</b>\n\n👤 <b>{$user['username']}</b>\n🎯 Gate: <code>$gate</code>\n💳 <code>" . substr($card, 0, 6) . "****" . substr($card, -4) . "</code>\n📱 <code>$ip</code>\n⚡ " . BRAND);
                }
                $fresh = userById((int)$user['id']);
                jsonOut(['ok' => true, 'live' => $isLive, 'response' => $response, 'cost' => $cost, 'balance' => (float)$fresh['balance']]);
            }
        }

        jsonOut(['ok' => false, 'error' => 'Ação desconhecida: ' . $action], 400);
    }
} catch (Throwable $e) {
    error_log('[HIPCTL] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    jsonOut(['ok' => false, 'error' => 'Erro servidor'], 500);
}

// ============ RENDER ============
$adminMode = isset($_GET['admin']) && !empty($_SESSION['admin']);
$user = getSessionUser();
$csrf = csrf();
$gates = listGates();
$pixData = null;
if ($user) {
    $pend = depositPendente((int)$user['id']);
    if ($pend) $pixData = ['txid' => $pend['transaction_id'], 'qr' => $pend['qrcode_url'], 'copy' => $pend['copy_paste'], 'amount' => (float)$pend['amount'], 'created_at' => $pend['created_at']];
}

$stats = $adminMode ? statsGlobal() : [];
$users = $adminMode ? listAllUsers() : [];
$chartData = $adminMode ? checksByDay(14) : [];

// filtros admin
$filterUser = $_GET['f_user'] ?? '';
$filterGate = $_GET['f_gate'] ?? '';
$filterDate = $_GET['f_date'] ?? '';
$filterStatus = $_GET['f_status'] ?? '';
$checks = $adminMode ? listChecksFiltered($filterUser ?: null, $filterGate ?: null, $filterDate ?: null, $filterStatus ?: null, 500) : [];
$deposits = $adminMode ? listDepositsFiltered($filterUser ?: null, $filterDate ?: null, null, 500) : [];
$audit = $adminMode ? listAudit(100) : [];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= SITE_NAME ?> <?= $adminMode ? '— Admin' : '— Acesso' ?></title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
/* (mantenha EXATAMENTE o CSS da versão anterior — só adicione as classes abaixo) */
:root{--bg:#090b0a;--line:rgba(255,255,255,.10);--muted:#8e9990;--text:#f3f7f2;--lime:#c8ff52;--red:#ff5f5f;--cyan:#00d4ff;--yellow:#fbbf24;--purple:#a78bfa}
*{box-sizing:border-box}
html{min-height:100%;background:var(--bg)}
body{margin:0;min-height:100vh;color:var(--text);font-family:Inter,system-ui,-apple-system,sans-serif;background:radial-gradient(ellipse at 13% 12%,rgba(137,197,55,.12),transparent 34%),radial-gradient(ellipse at 89% 86%,rgba(78,126,61,.12),transparent 30%),var(--bg)}
.brand{display:flex;align-items:center;gap:12px;font-weight:850;letter-spacing:-.04em;font-size:17px}
.brand-mark{width:38px;height:38px;border-radius:12px;background:var(--lime);color:#10150a;display:grid;place-items:center;font-size:18px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border:none;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;font-family:inherit;text-transform:uppercase;letter-spacing:.5px;transition:.2s}
.btn-primary{background:var(--lime);color:#10150a}
.btn-primary:hover{background:#d6ff7a}
.btn-ghost{background:#ffffff08;border:1px solid var(--line);color:var(--text)}
.btn-ghost:hover{border-color:var(--lime);color:var(--lime)}
.btn-danger{background:#ff5f5f20;border:1px solid #ff5f5f60;color:#ffb0a9}
.btn:disabled{opacity:.4;cursor:not-allowed}
.card{background:linear-gradient(180deg,rgba(20,25,22,.85),rgba(10,14,12,.9));border:1px solid var(--line);border-radius:18px;padding:22px;margin-bottom:18px}
.card-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:var(--lime);margin-bottom:14px}
.dash-nav{position:sticky;top:0;z-index:50;backdrop-filter:blur(30px);background:rgba(9,11,10,.9);border-bottom:1px solid var(--line);padding:14px 5.5%;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.dash-nav .right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.bal-pill{background:#c8ff5210;border:1px solid #c8ff5240;padding:9px 16px;border-radius:11px;display:flex;gap:8px;align-items:center}
.bal-pill .lbl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;font-weight:700}
.bal-pill .val{font-size:15px;font-weight:800;color:var(--lime);font-family:'Courier New',monospace}
.user-pill{display:flex;align-items:center;gap:8px;background:#ffffff06;padding:7px 12px;border-radius:11px;border:1px solid var(--line)}
.user-pill .av{width:30px;height:30px;background:var(--lime);border-radius:8px;display:grid;place-items:center;font-weight:800;color:#10150a;font-size:13px}
.user-pill .nm{font-size:12px;font-weight:600}
.dash-wrap{max-width:1100px;margin:0 auto;padding:24px 5.5%}
.type-tabs{display:flex;gap:6px;margin-bottom:14px;background:#080a09;border-radius:12px;padding:5px;border:1px solid var(--line)}
.type-tab{flex:1;padding:10px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;cursor:pointer;border-radius:9px;color:var(--muted);background:none;border:none;font-family:inherit}
.type-tab.active{background:var(--lime);color:#10150a}
.gates-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}
.gate-card{background:#080a09;border:1.5px solid var(--line);border-radius:12px;padding:14px;cursor:pointer;transition:.25s;position:relative}
.gate-card:hover{border-color:#c8ff5250;transform:translateY(-2px)}
.gate-card.sel{border-color:var(--lime);background:#c8ff520a}
.gate-card .top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px}
.gate-card .ext{font-size:9px;font-weight:800;padding:2px 7px;border-radius:6px;background:#c8ff5220;color:var(--lime);text-transform:uppercase}
.gate-card .nm{font-size:12px;font-weight:700;word-break:break-all;font-family:'Courier New',monospace}
.gate-card .tp{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px}
textarea.lista{width:100%;min-height:110px;padding:14px;background:#080a09;border:1px solid var(--line);border-radius:12px;color:var(--text);font-family:'Courier New',monospace;font-size:12px;outline:none;resize:vertical}
.results{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:20px}
.res-box{background:#080a09;border:1.5px solid var(--line);border-radius:14px;padding:14px;max-height:400px;overflow-y:auto}
.res-box.live{border-color:#c8ff5240}
.res-box.die{border-color:#ff5f5f40}
.res-box h3{font-size:11px;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:10px}
.res-item{background:#0006;border-left:3px solid;border-radius:8px;padding:10px 12px;margin-bottom:8px;font-family:'Courier New',monospace;font-size:11px;line-height:1.5}
.res-item.live{border-color:var(--lime)}
.res-item.die{border-color:var(--red)}
.res-item .hd{font-weight:700;margin-bottom:5px;word-break:break-all}
.res-item.live .hd{color:var(--lime)}
.res-item.die .hd{color:#ffb0a9}
.res-item .body{color:#ffffffc0;white-space:pre-wrap;word-break:break-word;max-height:150px;overflow-y:auto}
.notice{padding:11px 13px;border-radius:10px;font-size:11px;line-height:1.6;margin-bottom:17px;display:none}
.notice.show{display:block}
.notice.error{background:#ff5f5f12;border:1px solid #ff77772c;color:#ffb0a9}
.notice.ok{background:#c8ff5210;border:1px solid #c8ff5233;color:#d9fca3}
.notice.warn{background:#fbbf2412;border:1px solid #fbbf2430;color:#fde68a}
.admin-wrap{max-width:1400px;margin:0 auto;padding:24px}
.admin-top{display:flex;align-items:center;justify-content:space-between;padding:16px 24px;margin-bottom:24px;background:rgba(20,25,22,.85);border:1px solid var(--line);border-radius:16px;flex-wrap:wrap;gap:12px}
.admin-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px}
.admin-stat{background:rgba(20,25,22,.85);border:1px solid var(--line);border-radius:14px;padding:18px}
.admin-stat .lbl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;font-weight:600}
.admin-stat .val{font-size:22px;font-weight:800;font-family:'Courier New',monospace}
.admin-tabs{display:flex;gap:6px;margin-bottom:20px;background:#080a09;border-radius:12px;padding:5px;border:1px solid var(--line);flex-wrap:wrap}
.admin-tab{padding:9px 16px;background:none;border:none;color:var(--muted);font-size:12px;font-weight:700;cursor:pointer;border-radius:9px;transition:.2s;text-transform:uppercase;letter-spacing:.8px;font-family:inherit}
.admin-tab.active{background:var(--lime);color:#000}
.admin-content{display:none}
.admin-content.active{display:block}
.table-wrap{overflow-x:auto;border-radius:12px;border:1px solid rgba(255,255,255,.05);background:rgba(20,25,22,.7)}
table.tbl{width:100%;border-collapse:collapse;font-size:12px}
table.tbl th{padding:11px 12px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);background:rgba(0,0,0,.3);border-bottom:1px solid var(--line);font-weight:700;white-space:nowrap}
table.tbl td{padding:11px 12px;border-bottom:1px solid rgba(255,255,255,.04)}
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase}
.b-green{background:rgba(200,255,82,.12);color:var(--lime);border:1px solid rgba(200,255,82,.25)}
.b-red{background:rgba(255,95,95,.12);color:#ffb0a9;border:1px solid rgba(255,95,95,.25)}
.b-yellow{background:rgba(251,191,36,.12);color:var(--yellow);border:1px solid rgba(251,191,36,.25)}
.b-cyan{background:rgba(0,212,255,.12);color:var(--cyan);border:1px solid rgba(0,212,255,.25)}
.filter-bar{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap}
.filter-bar input,.filter-bar select{padding:9px 12px;background:#080a09;border:1px solid var(--line);border-radius:9px;color:var(--text);font-size:12px;outline:none;font-family:inherit}
.empty{text-align:center;padding:40px;color:var(--muted);font-size:13px}
code{font-family:'Courier New',monospace;background:rgba(0,0,0,.4);padding:2px 7px;border-radius:5px;font-size:11px}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:11px;font-weight:700;color:#d4dbd4;margin-bottom:6px}
.form-group input,.form-group select{width:100%;padding:11px 14px;background:#080a09;border:1px solid var(--line);border-radius:9px;color:var(--text);font-size:13px;outline:none;font-family:inherit}
.form-group input:focus{border-color:var(--lime)}
@media(max-width:900px){.results{grid-template-columns:1fr}.dash-wrap{padding:16px 20px}.admin-wrap{padding:16px}}
</style>
</head>
<body>

<?php if ($adminMode): ?>
<!-- ============ ADMIN ============ -->
<div class="admin-wrap">
    <div class="admin-top">
        <div class="brand"><div class="brand-mark">⚡</div><div><b style="font-size:15px">ADMIN PANEL</b><div style="font-size:10px;color:var(--muted);letter-spacing:1px;text-transform:uppercase"><?= SITE_NAME ?></div></div></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="?action=export&type=checks" class="btn btn-ghost">📥 CSV Checks</a>
            <a href="?action=export&type=deposits" class="btn btn-ghost">📥 CSV Depósitos</a>
            <a href="index.php" class="btn btn-ghost">↩ Voltar</a>
            <a href="?logout" class="btn btn-danger">Sair</a>
        </div>
    </div>

    <div class="admin-stats">
        <div class="admin-stat"><div class="lbl">Usuários</div><div class="val" style="color:var(--lime)"><?= $stats['users'] ?></div></div>
        <div class="admin-stat"><div class="lbl">Depositado</div><div class="val" style="color:var(--yellow);font-size:16px"><?= fmtMoney($stats['deposits_total']) ?></div></div>
        <div class="admin-stat"><div class="lbl">Depósitos</div><div class="val" style="color:var(--cyan)"><?= $stats['deposits_count'] ?></div></div>
        <div class="admin-stat"><div class="lbl">Pendentes</div><div class="val" style="color:var(--yellow)"><?= $stats['deposits_pending'] ?></div></div>
        <div class="admin-stat"><div class="lbl">Checks</div><div class="val" style="color:var(--purple)"><?= $stats['checks_total'] ?></div></div>
        <div class="admin-stat"><div class="lbl">Lives</div><div class="val" style="color:var(--lime)"><?= $stats['lives_total'] ?></div></div>
        <div class="admin-stat"><div class="lbl">Dies</div><div class="val" style="color:#ffb0a9"><?= $stats['dies_total'] ?></div></div>
    </div>

    <div class="card">
        <div class="card-title">📊 Lives/Dies por dia (14 dias)</div>
        <canvas id="chartLivesDies" style="max-height:280px"></canvas>
    </div>

    <div class="admin-tabs">
        <button class="admin-tab active" onclick="showAdminTab('users',this)">👥 Usuários</button>
        <button class="admin-tab" onclick="showAdminTab('deposits',this)">💰 Depósitos</button>
        <button class="admin-tab" onclick="showAdminTab('checks',this)">🔍 Checks</button>
        <button class="admin-tab" onclick="showAdminTab('coupons',this)">🎟️ Cupons</button>
        <button class="admin-tab" onclick="showAdminTab('audit',this)">📋 Logs</button>
    </div>

    <div class="admin-content active" id="ac-users">
        <div class="table-wrap"><table class="tbl">
            <thead><tr><th>ID</th><th>Username</th><th>Saldo</th><th>Depositou</th><th>Checks</th><th>Lives</th><th>Ref Code</th><th>Indicou</th><th>Criado</th><th>Ações</th></tr></thead>
            <tbody>
            <?php if (empty($users)): ?><tr><td colspan="10" class="empty">Nenhum</td></tr>
            <?php else: foreach ($users as $u): ?>
            <tr>
                <td><code>#<?= (int)$u['id'] ?></code></td>
                <td><strong><?= esc($u['username']) ?></strong></td>
                <td style="color:var(--lime);font-weight:700"><?= fmtMoney((float)$u['balance']) ?></td>
                <td style="color:var(--yellow)"><?= fmtMoney((float)$u['total_deposited']) ?></td>
                <td><?= (int)$u['total_checks'] ?></td>
                <td><span class="badge b-green"><?= (int)$u['total_lives'] ?></span></td>
                <td><code><?= esc($u['ref_code'] ?? '') ?></code></td>
                <td><span style="color:var(--lime)"><?= fmtMoney((float)($u['ref_earnings'] ?? 0)) ?></span></td>
                <td style="font-size:11px;color:var(--muted)"><?= date('d/m/y H:i', strtotime($u['created_at'])) ?></td>
                <td>
                    <div style="display:flex;gap:4px;flex-wrap:wrap">
                        <input type="number" step="0.01" placeholder="R$" id="add-<?= (int)$u['id'] ?>" style="width:70px;padding:6px;background:#080a09;border:1px solid var(--line);border-radius:6px;color:#fff;font-size:11px">
                        <button class="btn btn-primary" style="padding:6px 9px;font-size:10px" onclick="adminBalance('add',<?= (int)$u['id'] ?>)">+</button>
                        <button class="btn btn-danger" style="padding:6px 9px;font-size:10px" onclick="adminBalance('rem',<?= (int)$u['id'] ?>)">-</button>
                        <button class="btn btn-danger" style="padding:6px 9px;font-size:10px" onclick="adminDelUser(<?= (int)$u['id'] ?>,'<?= esc($u['username']) ?>')">🗑</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="admin-content" id="ac-deposits">
        <form method="get" class="filter-bar">
            <input type="hidden" name="admin" value="1">
            <input type="text" name="f_user" placeholder="Usuário" value="<?= esc($filterUser) ?>">
            <input type="date" name="f_date" value="<?= esc($filterDate) ?>">
            <button class="btn btn-primary" type="submit">Filtrar</button>
            <a href="?admin=1" class="btn btn-ghost">Limpar</a>
        </form>
        <div class="table-wrap"><table class="tbl">
            <thead><tr><th>ID</th><th>User</th><th>Valor</th><th>Líquido</th><th>Status</th><th>IP</th><th>TXID</th><th>Data</th></tr></thead>
            <tbody>
            <?php if (empty($deposits)): ?><tr><td colspan="8" class="empty">Nenhum</td></tr>
            <?php else: foreach ($deposits as $d): $bc = $d['status']==='paid'?'b-green':($d['status']==='pending'?'b-yellow':'b-red'); ?>
            <tr>
                <td><code>#<?= (int)$d['id'] ?></code></td>
                <td><strong><?= esc($d['username'] ?? '?') ?></strong></td>
                <td style="color:var(--yellow)"><?= fmtMoney((float)$d['amount']) ?></td>
                <td style="color:var(--lime)"><?= $d['net_amount'] ? fmtMoney((float)$d['net_amount']) : '—' ?></td>
                <td><span class="badge <?= $bc ?>"><?= esc($d['status']) ?></span></td>
                <td><code style="font-size:10px"><?= esc($d['ip'] ?? '') ?></code></td>
                <td><code style="font-size:10px"><?= esc(substr($d['transaction_id'] ?? '',0,12)) ?>...</code></td>
                <td style="font-size:11px;color:var(--muted)"><?= date('d/m/y H:i', strtotime($d['created_at'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="admin-content" id="ac-checks">
        <form method="get" class="filter-bar">
            <input type="hidden" name="admin" value="1">
            <input type="text" name="f_user" placeholder="Usuário" value="<?= esc($filterUser) ?>">
            <input type="text" name="f_gate" placeholder="Gate" value="<?= esc($filterGate) ?>">
            <input type="date" name="f_date" value="<?= esc($filterDate) ?>">
            <select name="f_status">
                <option value="">Todos</option>
                <option value="live" <?= $filterStatus==='live'?'selected':'' ?>>Live</option>
                <option value="die" <?= $filterStatus==='die'?'selected':'' ?>>Die</option>
            </select>
            <button class="btn btn-primary" type="submit">Filtrar</button>
            <a href="?admin=1" class="btn btn-ghost">Limpar</a>
        </form>
        <div class="table-wrap"><table class="tbl">
            <thead><tr><th>ID</th><th>User</th><th>Gate</th><th>Tipo</th><th>Cartão</th><th>Result</th><th>Custo</th><th>IP</th><th>Data</th></tr></thead>
            <tbody>
            <?php if (empty($checks)): ?><tr><td colspan="9" class="empty">Nenhum</td></tr>
            <?php else: foreach ($checks as $c): ?>
            <tr>
                <td><code>#<?= (int)$c['id'] ?></code></td>
                <td><strong><?= esc($c['username'] ?? '?') ?></strong></td>
                <td><code><?= esc($c['gate']) ?></code></td>
                <td><span class="badge <?= $c['gate_type']==='gate'?'b-cyan':'b-green' ?>"><?= esc($c['gate_type']) ?></span></td>
                <td><code style="font-size:10px"><?= esc(substr($c['card']??'',0,6)) ?>***<?= esc(substr($c['card']??'',-4)) ?></code></td>
                <td><span class="badge <?= $c['result']==='live'?'b-green':'b-red' ?>"><?= esc($c['result']) ?></span></td>
                <td style="color:var(--yellow);font-weight:700"><?= fmtMoney((float)$c['cost']) ?></td>
                <td><code style="font-size:10px"><?= esc($c['ip'] ?? '') ?></code></td>
                <td style="font-size:11px;color:var(--muted)"><?= date('d/m/y H:i', strtotime($c['created_at'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="admin-content" id="ac-coupons">
        <div class="card">
            <div class="card-title">🎟️ Criar Cupom</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
                <div class="form-group"><label>Código (A-Z 0-9)</label><input id="c_code" placeholder="BONUS50" style="text-transform:uppercase"></div>
                <div class="form-group"><label>Valor R$</label><input id="c_amount" type="number" step="0.01" placeholder="10.00"></div>
                <div class="form-group"><label>Usos máx</label><input id="c_uses" type="number" value="1" min="1"></div>
                <div class="form-group"><label>Expira (opcional)</label><input id="c_exp" type="date"></div>
            </div>
            <button class="btn btn-primary" onclick="criarCupom()">Criar Cupom</button>
        </div>
    </div>

    <div class="admin-content" id="ac-audit">
        <div class="table-wrap"><table class="tbl">
            <thead><tr><th>ID</th><th>User</th><th>Ação</th><th>Detalhes</th><th>IP</th><th>Data</th></tr></thead>
            <tbody>
            <?php if (empty($audit)): ?><tr><td colspan="6" class="empty">Nenhum</td></tr>
            <?php else: foreach ($audit as $a): ?>
            <tr>
                <td><code>#<?= (int)$a['id'] ?></code></td>
                <td><strong><?= esc($a['username'] ?? 'sistema') ?></strong></td>
                <td><code style="font-size:10px;color:var(--cyan)"><?= esc($a['action']) ?></code></td>
                <td style="font-size:11px;color:var(--muted);max-width:300px;word-break:break-word"><?= esc($a['details'] ?? '') ?></td>
                <td><code style="font-size:10px"><?= esc($a['ip'] ?? '') ?></code></td>
                <td style="font-size:11px;color:var(--muted)"><?= date('d/m/y H:i:s', strtotime($a['created_at'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const CHART_DATA = <?= json_encode($chartData) ?>;
function showAdminTab(id,el){document.querySelectorAll('.admin-tab').forEach(t=>t.classList.remove('active'));document.querySelectorAll('.admin-content').forEach(c=>c.classList.remove('active'));document.getElementById('ac-'+id).classList.add('active');el.classList.add('active')}
async function adminPost(p){const r=await fetch('index.php',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({...p,csrf:CSRF})});return r.json()}
async function adminBalance(mode,uid){const v=parseFloat(document.getElementById('add-'+uid).value);if(!v||v<=0)return alert('Valor inválido');if(!confirm((mode==='add'?'Adicionar':'Remover')+' R$ '+v.toFixed(2)+'?'))return;const j=await adminPost({action:'admin_action',sub:mode==='add'?'add_balance':'rem_balance',user_id:uid,amount:v});if(j.ok){alert('✅ Novo: R$ '+j.balance.toFixed(2));location.reload()}else alert('❌ '+j.error)}
async function adminDelUser(uid,n){if(!confirm('DELETAR "'+n+'"?'))return;if(!confirm('TEM CERTEZA?'))return;const j=await adminPost({action:'admin_action',sub:'delete_user',user_id:uid});if(j.ok){alert('✅');location.reload()}else alert('❌ '+j.error)}
async function criarCupom(){const c=document.getElementById('c_code').value.trim().toUpperCase();const a=parseFloat(document.getElementById('c_amount').value);const m=parseInt(document.getElementById('c_uses').value);const e=document.getElementById('c_exp').value;if(!c||!a)return alert('Preencha código e valor');const j=await adminPost({action:'admin_action',sub:'create_coupon',code:c,amount:a,max_uses:m,expires_at:e});if(j.ok){alert('✅ Cupom criado');location.reload()}else alert('❌ '+j.error)}
// gráfico
if(typeof Chart!=='undefined'){const ctx=document.getElementById('chartLivesDies');if(ctx){new Chart(ctx,{type:'line',data:{labels:CHART_DATA.map(d=>d.date.slice(5)),datasets:[{label:'Lives',data:CHART_DATA.map(d=>d.lives),borderColor:'#c8ff52',backgroundColor:'#c8ff5220',tension:.35,fill:true},{label:'Dies',data:CHART_DATA.map(d=>d.dies),borderColor:'#ff5f5f',backgroundColor:'#ff5f5f20',tension:.35,fill:true}]},options:{responsive:true,plugins:{legend:{labels:{color:'#f3f7f2'}}},scales:{x:{ticks:{color:'#8e9990'},grid:{color:'#ffffff08'}},y:{ticks:{color:'#8e9990'},grid:{color:'#ffffff08'}}}}})}}
</script>

<?php elseif ($user): ?>
<!-- ============ DASHBOARD USER ============ -->
<nav class="dash-nav">
    <div class="brand"><div class="brand-mark">⚡</div><b>HIPÓLITO <span style="color:var(--lime)">CLT</span></b></div>
    <div class="right">
        <div class="bal-pill"><span class="lbl">Saldo</span><span class="val" id="bal"><?= fmtMoney((float)$user['balance']) ?></span></div>
        <div class="user-pill"><div class="av"><?= strtoupper(substr($user['username'],0,1)) ?></div><span class="nm"><?= esc($user['username']) ?></span></div>
        <a href="?logout" class="btn btn-danger">Sair</a>
    </div>
</nav>

<div class="dash-wrap">
    <div class="card">
        <div class="card-title">💳 Adicionar Saldo via PIX</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <input type="number" id="dep-amount" min="<?= DEP_MIN ?>" max="<?= DEP_MAX ?>" step="0.01" value="20" style="flex:1;min-width:140px;padding:13px 16px;background:#080a09;border:1px solid var(--line);border-radius:11px;color:#fff;font-size:15px;font-family:monospace;font-weight:700;outline:none">
            <button class="btn btn-primary" id="btn-pix" onclick="criarPix()">💰 Gerar PIX</button>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:8px">Mín <?= fmtMoney(DEP_MIN) ?> · Máx <?= fmtMoney(DEP_MAX) ?></div>
        <div class="notice" id="pix-msg" style="margin-top:12px"></div>
    </div>

    <div class="card">
        <div class="card-title">🎟️ Resgatar Cupom</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <input type="text" id="coupon-code" placeholder="BONUS50" style="flex:1;min-width:140px;padding:13px 16px;background:#080a09;border:1px solid var(--line);border-radius:11px;color:#fff;font-size:15px;font-family:monospace;font-weight:700;outline:none;text-transform:uppercase">
            <button class="btn btn-primary" onclick="resgatarCupom()">🎟️ Resgatar</button>
        </div>
        <div class="notice" id="cupom-msg" style="margin-top:12px"></div>
    </div>

    <div class="card">
        <div class="card-title">🔗 Seu link de afiliado (<?= AFFILIATE_PCT*100 ?>% por depósito)</div>
        <div style="background:#080a09;padding:13px;border-radius:11px;border:1px solid var(--line);font-family:monospace;font-size:12px;word-break:break-all;cursor:pointer" onclick="copiarRef()" id="ref-link"><?= esc(BASE_URL) ?>/?ref=<?= esc($user['ref_code'] ?? '') ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:8px">Código: <code><?= esc($user['ref_code'] ?? '') ?></code> · Ganhos: <span style="color:var(--lime);font-weight:700"><?= fmtMoney((float)($user['ref_earnings'] ?? 0)) ?></span></div>
    </div>

    <div class="card">
        <div class="card-title">🎯 Escolher Checker</div>
        <div class="type-tabs">
            <button class="type-tab active" onclick="setType('gate')">🎴 Gates</button>
            <button class="type-tab" onclick="setType('login')">🔐 Logins</button>
        </div>
        <div class="gates-grid" id="gates"></div>
    </div>

    <div class="card">
        <div class="card-title">📝 Dados</div>
        <label id="label-lista" style="display:block;font-size:11px;font-weight:700;color:#d4dbd4;margin-bottom:6px">Cartões</label>
        <textarea class="lista" id="lista" placeholder="4532015112830366|12|2027|123"></textarea>
        <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
            <button class="btn btn-primary" id="btn-run" onclick="runCheck()" disabled>▶ Iniciar</button>
            <button class="btn btn-ghost" onclick="stopCheck()">⏹ Parar</button>
            <button class="btn btn-ghost" onclick="clearAll()">🗑 Limpar</button>
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:11px;color:var(--muted);margin-top:12px">
            <span>DIE: <strong><?= fmtMoney(DIE_COST) ?></strong></span>
            <span>LIVE: <strong><?= fmtMoney(LIVE_COST) ?></strong></span>
            <span>Proc: <strong id="st-proc">0</strong></span>
            <span>✅ <strong id="st-live" style="color:var(--lime)">0</strong></span>
            <span>❌ <strong id="st-die" style="color:#ffb0a9">0</strong></span>
        </div>
    </div>

    <div class="card">
        <div class="card-title">🔑 Suas APIs (opcional)</div>
        <div style="font-size:11px;color:var(--muted);margin-bottom:12px">Se você tem keys próprias do gate, cadastre aqui. O sistema vai passar automaticamente para o checker.</div>
        <div id="api-list"></div>
    </div>

    <div class="results">
        <div class="res-box live"><h3 style="color:var(--lime)">✅ Aprovados</h3><div id="res-live"></div></div>
        <div class="res-box die"><h3 style="color:#ffb0a9">❌ Reprovados</h3><div id="res-die"></div></div>
    </div>

    <div class="card">
        <div class="card-title">📜 Seu histórico (últimos 100)</div>
        <div id="hist-checks"><div class="empty">Carregando...</div></div>
    </div>
</div>

<div id="pix-modal" style="position:fixed;inset:0;background:#000e;backdrop-filter:blur(8px);z-index:200;display:none;align-items:center;justify-content:center;padding:20px">
    <div style="background:linear-gradient(180deg,#0d100e,#080a09);border:1px solid #c8ff5240;border-radius:22px;padding:28px;max-width:440px;width:100%;max-height:90vh;overflow-y:auto;position:relative">
        <button onclick="closePix()" style="position:absolute;top:14px;right:14px;width:34px;height:34px;background:#ffffff08;border:none;border-radius:10px;color:var(--muted);font-size:18px;cursor:pointer">✕</button>
        <h2 style="text-align:center;color:var(--lime);margin:0 0 6px">💰 PIX</h2>
        <div style="text-align:center;font-size:12px;color:var(--muted);margin-bottom:20px" id="pix-sub">Aguardando...</div>
        <div style="background:#fff;padding:16px;border-radius:14px;margin-bottom:16px;text-align:center"><img id="pix-img" style="max-width:220px;width:100%;border-radius:8px" src="" alt="QR"></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;margin-bottom:6px;font-weight:700">Copia e Cola</div>
        <div onclick="copyPix()" id="pix-copy" style="background:#0008;border:1.5px solid #c8ff5240;border-radius:11px;padding:14px;font-family:monospace;font-size:10px;word-break:break-all;color:#c8ff52;cursor:pointer;margin-bottom:14px">—</div>
        <div style="text-align:center;font-size:12px;color:var(--muted)">⏳ Aguardando confirmação...</div>
        <div id="pix-timer" style="text-align:center;font-size:11px;color:var(--muted);font-family:monospace;margin-top:10px"></div>
    </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const GATES = {gate:<?= json_encode($gates['gate']) ?>, login:<?= json_encode($gates['login']) ?>};
const USER_APIS = <?= json_encode((function() use ($user){
    $s = db()->prepare('SELECT gate, api_key, api_extra FROM user_apis WHERE user_id=:u');
    $s->bindValue(':u', (int)$user['id'], SQLITE3_INTEGER);
    $r = $s->execute(); $o = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $o[$row['gate']] = ['key' => $row['api_key'], 'extra' => $row['api_extra']];
    return $o;
})()) ?>;
const PIX_COOLDOWN = <?= PIX_COOLDOWN ?>;
const PIX_DATA = <?= json_encode($pixData) ?>;
let curType='gate', curGate='', running=false;
let pixInterval = null;

async function api(p){
    const r = await fetch('index.php',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({...p,csrf:CSRF}),credentials:'same-origin'});
    const t = await r.text();
    try { return JSON.parse(t); } catch(e) { console.error(t); return {ok:false,error:'Erro servidor'}; }
}
function setType(t){
    curType=t; curGate='';
    document.querySelectorAll('.type-tab').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.type-tab')[t==='gate'?0:1].classList.add('active');
    document.getElementById('label-lista').textContent = t==='gate'?'Cartões (NÚMERO|MÊS|ANO|CVV)':'Logins (USUÁRIO:SENHA)';
    document.getElementById('lista').placeholder = t==='gate'?'4532015112830366|12|2027|123':'usuario:senha';
    renderGates(); document.getElementById('btn-run').disabled = true;
}
function renderGates(){
    const box = document.getElementById('gates'), arr = GATES[curType] || [];
    box.innerHTML = '';
    if (!arr.length) { box.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:24px;color:var(--muted);font-size:12px">Nenhum checker</div>'; return; }
    arr.forEach(f => {
        const name = f.replace(/\.(php|py)$/i,'');
        const ext = f.split('.').pop();
        const d = document.createElement('div');
        d.className = 'gate-card';
        d.innerHTML = `<div class="top"><div class="nm">${name}</div><div class="ext">${ext}</div></div><div class="tp">${curType==='gate'?'Cartão':'Login'}</div>`;
        d.onclick = () => { curGate=f; document.querySelectorAll('.gate-card').forEach(x=>x.classList.remove('sel')); d.classList.add('sel'); document.getElementById('btn-run').disabled=false; renderApiForGate(f); };
        box.appendChild(d);
    });
    renderApiForGate('');
}
function renderApiForGate(gate){
    const box = document.getElementById('api-list');
    if (!gate) { box.innerHTML = '<div style="font-size:11px;color:var(--muted)">Escolha um gate acima</div>'; return; }
    const cur = USER_APIS[gate] || {};
    box.innerHTML = `
        <div style="font-size:11px;color:var(--lime);font-weight:700;margin-bottom:8px">Gate: <code>${gate}</code></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
            <div><label style="display:block;font-size:10px;color:var(--muted);margin-bottom:4px">API KEY</label><input id="api-key" value="${cur.key||''}" placeholder="pk_live_..." style="width:100%;padding:9px;background:#080a09;border:1px solid var(--line);border-radius:8px;color:#fff;font-size:11px;font-family:monospace"></div>
            <div><label style="display:block;font-size:10px;color:var(--muted);margin-bottom:4px">SECRET (opcional)</label><input id="api-secret" placeholder="sk_live_..." style="width:100%;padding:9px;background:#080a09;border:1px solid var(--line);border-radius:8px;color:#fff;font-size:11px;font-family:monospace"></div>
            <div><label style="display:block;font-size:10px;color:var(--muted);margin-bottom:4px">EXTRA (opcional)</label><input id="api-extra" value="${cur.extra||''}" placeholder="extra..." style="width:100%;padding:9px;background:#080a09;border:1px solid var(--line);border-radius:8px;color:#fff;font-size:11px;font-family:monospace"></div>
        </div>
        <button class="btn btn-primary" style="margin-top:10px" onclick="salvarApi()">💾 Salvar API</button>
        <div class="notice" id="api-msg" style="margin-top:10px"></div>
    `;
}
async function salvarApi(){
    const gate = curGate;
    const key = document.getElementById('api-key').value.trim();
    const sec = document.getElementById('api-secret').value.trim();
    const ext = document.getElementById('api-extra').value.trim();
    if (!gate) return;
    if (!key) return alert('API key obrigatória');
    const j = await api({action:'save_api', gate, api_key:key, api_secret:sec, api_extra:ext});
    const m = document.getElementById('api-msg');
    if (j.ok) { m.className='notice ok show'; m.textContent='✅ Salvo!'; }
    else { m.className='notice error show'; m.textContent='❌ '+j.error; }
}
function showPixMsg(t,type){const m=document.getElementById('pix-msg');m.textContent=t;m.className='notice '+(type||'error')+' show'}
async function checarPixPermitido(){
    try{
        const j = await api({action:'pix_allowed'});
        const btn = document.getElementById('btn-pix');
        if (j.ok && !j.allowed) { btn.disabled=true; const min=Math.ceil((j.wait||0)/60); showPixMsg('⏳ Aguarde '+min+'min','warn'); }
        else { btn.disabled=false; }
    }catch(e){}
}
async function criarPix(){
    const amt = parseFloat(document.getElementById('dep-amount').value);
    if (!amt || amt<<?= DEP_MIN ?> || amt><?= DEP_MAX ?>) return showPixMsg('Valor entre <?= fmtMoney(DEP_MIN) ?> e <?= fmtMoney(DEP_MAX) ?>','error');
    const j = await api({action:'deposit',amount:amt});
    if (!j.ok) return showPixMsg(j.error||'Erro','error');
    document.getElementById('pix-img').src = j.qr;
    document.getElementById('pix-copy').textContent = j.copy;
    document.getElementById('pix-sub').textContent = 'Valor: R$ '+j.amount.toFixed(2).replace('.',',');
    document.getElementById('pix-modal').style.display = 'flex';
    monitorPix(j.txid);
}
function monitorPix(txid){
    const start = Date.now();
    if (pixInterval) clearInterval(pixInterval);
    pixInterval = setInterval(async ()=>{
        const el = document.getElementById('pix-timer');
        if (el) {
            const elp = Math.floor((Date.now()-start)/1000);
            const left = Math.max(0, PIX_COOLDOWN - elp);
            const m = Math.floor(left/60), s = left%60;
            el.textContent = left>0 ? 'Expira em '+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0') : 'Expirado';
        }
        if (document.getElementById('pix-modal').style.display !== 'flex') { clearInterval(pixInterval); return; }
        const j = await api({action:'status',txid});
        if (j.ok && j.status==='paid') { clearInterval(pixInterval); document.getElementById('pix-sub').textContent='✅ Pago!'; setTimeout(()=>location.reload(),1500); }
    }, 4000);
}
function closePix(){ document.getElementById('pix-modal').style.display='none'; if(pixInterval) clearInterval(pixInterval); checarPixPermitido(); }
function copyPix(){ navigator.clipboard.writeText(document.getElementById('pix-copy').textContent); }
function copiarRef(){ navigator.clipboard.writeText(document.getElementById('ref-link').textContent); alert('Link copiado!'); }
async function resgatarCupom(){
    const code = document.getElementById('coupon-code').value.trim().toUpperCase();
    if (!code) return;
    const m = document.getElementById('cupom-msg');
    const j = await api({action:'redeem_coupon',code});
    if (j.ok) { m.className='notice ok show'; m.textContent='✅ +R$ '+j.amount.toFixed(2)+'! Novo saldo: R$ '+j.balance.toFixed(2); document.getElementById('bal').textContent='R$ '+j.balance.toFixed(2).replace('.',','); }
    else { m.className='notice error show'; m.textContent='❌ '+j.error; }
}
async function runCheck(){
    if (!curGate) return alert('Escolha gate');
    const lista = document.getElementById('lista').value.trim();
    if (!lista) return alert('Cole dados');
    const items = lista.split('\n').filter(l=>l.trim());
    if (!items.length) return;
    running = true; let idx = 0, live = 0, die = 0;
    clearAll();
    // Paralelismo: 3 por vez
    const PARALLEL = 3;
    let active = 0;
    const queue = [...items];
    const processNext = async () => {
        if (!running || !queue.length) return;
        const it = queue.shift();
        active++;
        try {
            const j = await api({action:'check',gate:curGate,type:curType,lista:it});
            if (!j.ok) { addRes(it,'❌ '+(j.error||'Erro'),false); die++; }
            else {
                addRes(it, j.response, j.live);
                if (j.live) live++; else die++;
                document.getElementById('bal').textContent = 'R$ '+j.balance.toFixed(2).replace('.',',');
            }
        } catch(e) { addRes(it,'❌ Erro conexão',false); die++; }
        idx++;
        document.getElementById('st-proc').textContent=idx;
        document.getElementById('st-live').textContent=live;
        document.getElementById('st-die').textContent=die;
        active--;
        if (running && queue.length) await new Promise(r=>setTimeout(r,1500));
        if (running) processNext();
    };
    for (let i=0;i<Math.min(PARALLEL, items.length);i++) processNext();
    // aguarda tudo acabar
    const checkDone = setInterval(()=>{ if (!running || (queue.length===0 && active===0)) { clearInterval(checkDone); running=false; } }, 500);
}
function stopCheck(){ running=false; }
function clearAll(){ document.getElementById('res-live').innerHTML=''; document.getElementById('res-die').innerHTML=''; document.getElementById('st-proc').textContent='0'; document.getElementById('st-live').textContent='0'; document.getElementById('st-die').textContent='0'; }
function addRes(input,resp,isLive){
    const box = document.getElementById(isLive?'res-live':'res-die');
    const d = document.createElement('div');
    d.className = 'res-item '+(isLive?'live':'die');
    d.innerHTML = `<div class="hd">${String(input).substring(0,60)}</div><div class="body">${String(resp).replace(/</g,'&lt;')}</div>`;
    box.insertBefore(d, box.firstChild);
}
async function loadHistorico(){
    const j = await api({action:'my_checks'});
    const box = document.getElementById('hist-checks');
    if (!j.ok || !j.checks.length) { box.innerHTML = '<div class="empty">Sem histórico</div>'; return; }
    let html = '<div class="table-wrap"><table class="tbl"><thead><tr><th>Data</th><th>Gate</th><th>Cartão</th><th>Result</th><th>Custo</th></tr></thead><tbody>';
    j.checks.forEach(c => {
        const badge = c.result === 'live' ? 'b-green' : 'b-red';
        html += `<tr><td style="font-size:11px;color:var(--muted)">${new Date(c.created_at).toLocaleString('pt-BR')}</td><td><code>${c.gate}</code></td><td><code>${c.card}</code></td><td><span class="badge ${badge}">${c.result}</span></td><td style="color:var(--yellow)">R$ ${parseFloat(c.cost).toFixed(2)}</td></tr>`;
    });
    html += '</tbody></table></div>';
    box.innerHTML = html;
}
document.addEventListener('DOMContentLoaded', ()=>{
    renderGates();
    checarPixPermitido();
    loadHistorico();
    if (PIX_DATA) showPixMsg('⏳ Você tem PIX pendente: R$ '+PIX_DATA.amount.toFixed(2).replace('.',','),'warn');
});
</script>

<?php else: ?>
<!-- ============ LOGIN ============ -->
<main style="display:grid;place-items:center;min-height:100vh;padding:24px">
    <div style="width:100%;max-width:430px;padding:32px;background:linear-gradient(145deg,rgba(25,30,26,.94),rgba(13,16,14,.96));border:1px solid rgba(255,255,255,.11);border-radius:25px">
        <div class="brand" style="justify-content:center;margin-bottom:24px"><div class="brand-mark">⚡</div><b>HIPÓLITO <span style="color:var(--lime)">CLT</span></b></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;padding:4px;border:1px solid var(--line);background:#080a09;border-radius:12px;margin-bottom:20px">
            <button class="tab active" id="loginTab" onclick="setMode('login')" style="border:0;border-radius:9px;padding:11px;background:transparent;color:var(--muted);font:600 12px inherit;cursor:pointer">Entrar</button>
            <button class="tab" id="registerTab" onclick="setMode('register')" style="border:0;border-radius:9px;padding:11px;background:transparent;color:var(--muted);font:600 12px inherit;cursor:pointer">Criar conta</button>
        </div>
        <div class="notice" id="msg"></div>
        <form id="authForm" onsubmit="return false">
            <input type="text" id="hp_field" style="position:absolute;left:-9999px" tabindex="-1">
            <div class="form-group"><label>Usuário</label><input id="username" placeholder="usuário" autocomplete="username"></div>
            <div class="form-group"><label>Senha</label><input id="password" type="password" placeholder="senha" autocomplete="current-password"></div>
            <div class="form-group" id="confirmGroup" style="display:none"><label>Confirmar senha</label><input id="confirm_password" type="password"></div>
            <div class="form-group" id="refGroup" style="display:none"><label>Código de indicação (opcional)</label><input id="ref_code" placeholder="ABC1234" style="text-transform:uppercase"></div>
            <button class="btn btn-primary" type="submit" id="submitBtn" style="width:100%;height:51px;justify-content:center">ACESSAR</button>
        </form>
        <div style="text-align:center;margin-top:14px;font-size:11px"><a href="#" onclick="adminMode();return false" style="color:var(--muted);text-decoration:none">Admin</a></div>
    </div>
</main>

<script>
const CSRF = <?= json_encode($csrf) ?>;
let currentMode = 'login';
const params = new URLSearchParams(location.search);
if (params.get('ref')) { setMode('register'); document.getElementById('ref_code').value = params.get('ref').toUpperCase(); }
function setMode(m){
    currentMode = m;
    document.getElementById('loginTab').style.background = m==='login' ? '#20281a' : 'transparent';
    document.getElementById('loginTab').style.color = m==='login' ? '#c8ff52' : '#8e9990';
    document.getElementById('registerTab').style.background = m==='register' ? '#20281a' : 'transparent';
    document.getElementById('registerTab').style.color = m==='register' ? '#c8ff52' : '#8e9990';
    document.getElementById('confirmGroup').style.display = m==='register' ? 'block' : 'none';
    document.getElementById('refGroup').style.display = m==='register' ? 'block' : 'none';
    document.getElementById('submitBtn').textContent = m==='register' ? 'CRIAR CONTA' : 'ACESSAR';
    hideMsg();
}
function adminMode(){ setMode('login'); currentMode='admin'; document.getElementById('submitBtn').textContent='ADMIN LOGIN'; }
function showMsg(t,type){ const m=document.getElementById('msg'); m.textContent=t; m.className='notice '+(type||'error')+' show'; }
function hideMsg(){ document.getElementById('msg').className='notice'; }
async function apiCall(p){
    const r = await fetch('index.php',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({...p,csrf:CSRF,honeypot:document.getElementById('hp_field').value})});
    const t = await r.text(); try { return JSON.parse(t); } catch(e) { return {ok:false,error:'Erro'}; }
}
document.getElementById('authForm').onsubmit = async (e) => {
    e.preventDefault(); hideMsg();
    const u = document.getElementById('username').value.trim();
    const p = document.getElementById('password').value;
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    try {
        if (currentMode === 'admin') {
            const j = await apiCall({action:'admin_login', username:u, password:p});
            if (!j.ok) { showMsg(j.error); btn.disabled=false; return; }
            // pede código 2FA
            const code = prompt('Digite o código de 6 dígitos que foi enviado no Telegram:');
            if (!code) { btn.disabled=false; return; }
            const v = await apiCall({action:'admin_2fa_verify', code});
            if (v.ok) { showMsg('✅ OK', 'ok'); setTimeout(()=>location.href=v.redirect, 400); }
            else { showMsg(v.error); btn.disabled=false; }
            return;
        }
        if (currentMode === 'register') {
            const c = document.getElementById('confirm_password').value;
            const ref = document.getElementById('ref_code').value.trim();
            if (p !== c) { showMsg('Senhas não coincidem'); btn.disabled=false; return; }
            const j = await apiCall({action:'register', username:u, password:p, confirm:c, ref});
            if (j.ok) { showMsg('✅ OK', 'ok'); setTimeout(()=>location.href='index.php', 400); }
            else { showMsg(j.error); btn.disabled=false; }
        } else {
            const j = await apiCall({action:'login', username:u, password:p});
            if (j.ok) { showMsg('✅ OK', 'ok'); setTimeout(()=>location.href='index.php', 400); }
            else { showMsg(j.error); btn.disabled=false; }
        }
    } catch(err) { showMsg('Erro conexão'); btn.disabled=false; }
};
</script>
<?php endif; ?>

</body>
</html>
