<?php
require_once __DIR__ . '/config.php';

function db(): SQLite3 {
    static $db = null;
    if ($db !== null) return $db;
    $db = new SQLite3(DB_PATH);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');
    dbInit($db);
    return $db;
}

function dbInit(SQLite3 $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE,
            password_hash TEXT NOT NULL,
            ip TEXT,
            balance REAL DEFAULT 0,
            total_checks INTEGER DEFAULT 0,
            total_lives INTEGER DEFAULT 0,
            total_dies INTEGER DEFAULT 0,
            total_deposited REAL DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            last_login TEXT,
            last_seen TEXT
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS sessions (
            token TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            ip TEXT,
            user_agent TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS deposits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            transaction_id TEXT UNIQUE,
            amount REAL NOT NULL,
            net_amount REAL,
            status TEXT DEFAULT 'pending',
            qrcode_url TEXT,
            copy_paste TEXT,
            payer_name TEXT,
            payer_document TEXT,
            ip TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            paid_at TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS checks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            gate TEXT NOT NULL,
            gate_type TEXT NOT NULL,
            card TEXT NOT NULL,
            bin TEXT,
            result TEXT NOT NULL,
            cost REAL NOT NULL,
            response TEXT,
            ip TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            username TEXT,
            action TEXT NOT NULL,
            details TEXT,
            ip TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_checks_user ON checks(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_checks_created ON checks(created_at DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_deposits_user ON deposits(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_deposits_status ON deposits(status)");
}

function userCreate(string $username, string $password, string $ip): array {
    $db = db();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (username, password_hash, ip, created_at, last_seen) VALUES (:u, :p, :ip, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
    $stmt->bindValue(':u', $username, SQLITE3_TEXT);
    $stmt->bindValue(':p', $hash, SQLITE3_TEXT);
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    if (!$stmt->execute()) return ['success' => false, 'error' => 'Erro ao criar conta'];
    return ['success' => true, 'id' => $db->lastInsertRowID()];
}

function userByUsername(string $username): ?array {
    $db = db();
    $stmt = $db->prepare('SELECT * FROM users WHERE username = :u');
    $stmt->bindValue(':u', $username, SQLITE3_TEXT);
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

function userBySession(string $token): ?array {
    $db = db();
    $stmt = $db->prepare('SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = :t AND s.expires_at > CURRENT_TIMESTAMP');
    $stmt->bindValue(':t', $token, SQLITE3_TEXT);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

function userUpdateLastSeen(int $id): void {
    $db = db();
    $stmt = $db->prepare('UPDATE users SET last_seen = CURRENT_TIMESTAMP, last_login = CURRENT_TIMESTAMP WHERE id = :i');
    $stmt->bindValue(':i', $id, SQLITE3_INTEGER);
    $stmt->execute();
}

function userAddBalance(int $id, float $amount): float {
    $db = db();
    $stmt = $db->prepare('UPDATE users SET balance = balance + :a, total_deposited = total_deposited + :a2 WHERE id = :i');
    $stmt->bindValue(':a', $amount, SQLITE3_FLOAT);
    $stmt->bindValue(':a2', $amount, SQLITE3_FLOAT);
    $stmt->bindValue(':i', $id, SQLITE3_INTEGER);
    $stmt->execute();
    $u = userById($id);
    return (float)$u['balance'];
}

function userDeductBalance(int $id, float $amount): bool {
    $db = db();
    $u = userById($id);
    if (!$u || $u['balance'] < $amount) return false;
    $stmt = $db->prepare('UPDATE users SET balance = balance - :a WHERE id = :i');
    $stmt->bindValue(':a', $amount, SQLITE3_FLOAT);
    $stmt->bindValue(':i', $id, SQLITE3_INTEGER);
    return $stmt->execute() !== false;
}

function userSetBalance(int $id, float $amount): void {
    $db = db();
    $stmt = $db->prepare('UPDATE users SET balance = :a WHERE id = :i');
    $stmt->bindValue(':a', max(0, $amount), SQLITE3_FLOAT);
    $stmt->bindValue(':i', $id, SQLITE3_INTEGER);
    $stmt->execute();
}

function sessionCreate(int $userId, string $ip, string $ua): string {
    $db = db();
    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare('INSERT INTO sessions (token, user_id, ip, user_agent, expires_at) VALUES (:t, :u, :ip, :ua, datetime("now", "+' . SESSION_LIFETIME . ' seconds"))');
    $stmt->bindValue(':t', $token, SQLITE3_TEXT);
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->bindValue(':ua', $ua, SQLITE3_TEXT);
    $stmt->execute();
    return $token;
}

function sessionDestroy(string $token): void {
    $db = db();
    $stmt = $db->prepare('DELETE FROM sessions WHERE token = :t');
    $stmt->bindValue(':t', $token, SQLITE3_TEXT);
    $stmt->execute();
}

function depositCreate(int $userId, string $txid, float $amount, string $qrcode, string $copyPaste, string $ip): void {
    $db = db();
    $stmt = $db->prepare('INSERT INTO deposits (user_id, transaction_id, amount, status, qrcode_url, copy_paste, ip) VALUES (:u, :t, :a, "pending", :q, :c, :ip)');
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':t', $txid, SQLITE3_TEXT);
    $stmt->bindValue(':a', $amount, SQLITE3_FLOAT);
    $stmt->bindValue(':q', $qrcode, SQLITE3_TEXT);
    $stmt->bindValue(':c', $copyPaste, SQLITE3_TEXT);
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->execute();
}

function depositByTxid(string $txid): ?array {
    $db = db();
    $stmt = $db->prepare('SELECT * FROM deposits WHERE transaction_id = :t');
    $stmt->bindValue(':t', $txid, SQLITE3_TEXT);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

function depositConfirm(string $txid, ?float $net = null, ?string $payerName = null, ?string $payerDoc = null): bool {
    $db = db();
    $d = depositByTxid($txid);
    if (!$d || $d['status'] === 'paid') return false;

    $db->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $db->prepare('UPDATE deposits SET status = "paid", paid_at = CURRENT_TIMESTAMP, net_amount = :n, payer_name = :pn, payer_document = :pd WHERE transaction_id = :t');
        $stmt->bindValue(':n', $net ?? $d['amount'], SQLITE3_FLOAT);
        $stmt->bindValue(':pn', $payerName, SQLITE3_TEXT);
        $stmt->bindValue(':pd', $payerDoc, SQLITE3_TEXT);
        $stmt->bindValue(':t', $txid, SQLITE3_TEXT);
        $stmt->execute();

        userAddBalance((int)$d['user_id'], (float)($net ?? $d['amount']));
        $db->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        return false;
    }
}

function checkLog(int $userId, string $gate, string $type, string $card, string $bin, string $result, float $cost, string $response, string $ip): void {
    $db = db();
    $stmt = $db->prepare('INSERT INTO checks (user_id, gate, gate_type, card, bin, result, cost, response, ip) VALUES (:u, :g, :t, :c, :b, :r, :co, :re, :ip)');
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':g', $gate, SQLITE3_TEXT);
    $stmt->bindValue(':t', $type, SQLITE3_TEXT);
    $stmt->bindValue(':c', $card, SQLITE3_TEXT);
    $stmt->bindValue(':b', $bin, SQLITE3_TEXT);
    $stmt->bindValue(':r', $result, SQLITE3_TEXT);
    $stmt->bindValue(':co', $cost, SQLITE3_FLOAT);
    $stmt->bindValue(':re', $response, SQLITE3_TEXT);
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->execute();

    $col = $result === 'live' ? 'total_lives' : 'total_dies';
    $stmt = $db->prepare("UPDATE users SET total_checks = total_checks + 1, $col = $col + 1 WHERE id = :u");
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}

function auditLog(?int $userId, ?string $username, string $action, string $details, string $ip): void {
    $db = db();
    $stmt = $db->prepare('INSERT INTO audit_log (user_id, username, action, details, ip) VALUES (:u, :un, :a, :d, :ip)');
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':un', $username, SQLITE3_TEXT);
    $stmt->bindValue(':a', $action, SQLITE3_TEXT);
    $stmt->bindValue(':d', $details, SQLITE3_TEXT);
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->execute();
}

function statsGlobal(): array {
    $db = db();
    $r = [];
    $r['users'] = (int)$db->querySingle('SELECT COUNT(*) FROM users');
    $r['deposits_total'] = (float)$db->querySingle('SELECT COALESCE(SUM(net_amount), 0) FROM deposits WHERE status = "paid"');
    $r['deposits_count'] = (int)$db->querySingle('SELECT COUNT(*) FROM deposits WHERE status = "paid"');
    $r['checks_total'] = (int)$db->querySingle('SELECT COUNT(*) FROM checks');
    $r['lives_total'] = (int)$db->querySingle('SELECT COUNT(*) FROM checks WHERE result = "live"');
    $r['dies_total'] = (int)$db->querySingle('SELECT COUNT(*) FROM checks WHERE result = "die"');
    $r['deposits_pending'] = (int)$db->querySingle('SELECT COUNT(*) FROM deposits WHERE status = "pending"');
    return $r;
}

function listRecentDeposits(int $limit = 50): array {
    $db = db();
    $stmt = $db->prepare('SELECT d.*, u.username FROM deposits d LEFT JOIN users u ON u.id = d.user_id ORDER BY d.id DESC LIMIT :l');
    $stmt->bindValue(':l', $limit, SQLITE3_INTEGER);
    $r = $stmt->execute();
    $out = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $out[] = $row;
    return $out;
}

function listRecentChecks(int $limit = 100): array {
    $db = db();
    $stmt = $db->prepare('SELECT c.*, u.username FROM checks c LEFT JOIN users u ON u.id = c.user_id ORDER BY c.id DESC LIMIT :l');
    $stmt->bindValue(':l', $limit, SQLITE3_INTEGER);
    $r = $stmt->execute();
    $out = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $out[] = $row;
    return $out;
}

function listAllUsers(): array {
    $db = db();
    $r = $db->query('SELECT * FROM users ORDER BY id DESC');
    $out = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $out[] = $row;
    return $out;
}

function listAudit(int $limit = 200): array {
    $db = db();
    $stmt = $db->prepare('SELECT * FROM audit_log ORDER BY id DESC LIMIT :l');
    $stmt->bindValue(':l', $limit, SQLITE3_INTEGER);
    $r = $stmt->execute();
    $out = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $out[] = $row;
    return $out;
}