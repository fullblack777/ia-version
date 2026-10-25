<?php
require_once __DIR__ . '/config.php';

function secHeaders(): void {
    if (headers_sent()) return;
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-XSS-Protection: 1; mode=block');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' https: data:; img-src 'self' data: https:; connect-src 'self' https:;");
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header_remove('X-Powered-By');
    header('Server: cloudflare');
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

function csrfToken(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrfCheck(?string $token): bool {
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function getSessionUser(): ?array {
    if (!empty($_SESSION['user_id'])) {
        $u = userById((int)$_SESSION['user_id']);
        if ($u) return $u;
    }
    if (!empty($_COOKIE['hipctl_token'])) {
        $u = userBySession($_COOKIE['hipctl_token']);
        if ($u) {
            $_SESSION['user_id'] = (int)$u['id'];
            return $u;
        }
    }
    return null;
}

function requireUser(): array {
    $u = getSessionUser();
    if (!$u) jsonOut(['ok' => false, 'error' => 'not_logged'], 401);
    userUpdateLastSeen((int)$u['id']);
    return $u;
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

function validatePassword(string $pwd): ?string {
    if (strlen($pwd) < PASSWORD_MIN_LEN) return 'Senha deve ter no mínimo ' . PASSWORD_MIN_LEN . ' caracteres';
    if (preg_match('/^\d+$/', $pwd)) return 'Senha não pode ser só números';
    return null;
}

function validateUsername(string $u): ?string {
    if (strlen($u) < 3 || strlen($u) > 20) return 'Username deve ter entre 3 e 20 caracteres';
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $u)) return 'Username só pode ter letras, números e _';
    return null;
}

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function fmtMoney(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}

function pingBot(string $message): void {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage';
    $data = [
        'chat_id' => ADMIN_TELEGRAM_ID,
        'text'    => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    @curl_exec($ch);
    @curl_close($ch);
}