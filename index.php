<?php
date_default_timezone_set('America/Sao_Paulo');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

define('BOT_TOKEN', '8952767112:AAGsG1yxraZXY0IkZ3QmIGu5_jfTXhhhGas');
define('ADMIN_ID', 8617449427);
define('DB_FILE', __DIR__ . '/data/hipolito.db');
define('BRAND', '@hipolitoofc');
define('DEFAULT_SITE', getenv('BASE_URL') ?: 'https://hipolito-ctl.onrender.com');

$GLOBALS['last_update'] = 0;
$GLOBALS['link_url'] = null;
$GLOBALS['link_active'] = false;
$GLOBALS['link_last'] = 0;

function log_msg($m) { echo '[' . date('H:i:s') . '] ' . $m . "\n"; @flush(); }

function tg_api($method, $params = []) {
    $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $r = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($r === false) return ['ok' => false, 'error' => $err];
    return json_decode($r, true) ?: ['ok' => false, 'error' => 'parse'];
}

function send($chat, $text) {
    return tg_api('sendMessage', ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
}

function fmt($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }

function db() {
    static $db = null;
    if ($db !== null) return $db;
    if (!file_exists(DB_FILE)) throw new Exception('DB não encontrado: ' . DB_FILE);
    $db = new SQLite3(DB_FILE);
    $db->busyTimeout(5000);
    return $db;
}

function dbQuery($sql, $params = []) {
    try {
        $conn = db(); $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v, is_int($v) ? SQLITE3_INTEGER : (is_float($v) ? SQLITE3_FLOAT : SQLITE3_TEXT));
        $res = $stmt->execute(); $rows = [];
        if ($res) while ($row = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
        return $rows;
    } catch (Throwable $e) { log_msg('DB erro: ' . $e->getMessage()); return []; }
}

function dbExec($sql, $params = []) {
    try {
        $conn = db(); $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v, is_int($v) ? SQLITE3_INTEGER : (is_float($v) ? SQLITE3_FLOAT : SQLITE3_TEXT));
        return $stmt->execute() !== false;
    } catch (Throwable $e) { log_msg('DB exec erro: ' . $e->getMessage()); return false; }
}

function cmd_start($chat) {
    send($chat, "<b>⚡ HIPOLITO BOT</b>\n\n<b>Comandos:</b>\n/wh — webhook log + saldo\n/set — stats do site\n/users — lista usuários\n/stats — estatísticas\n/addsaldo &lt;user&gt; &lt;valor&gt;\n/remsaldo &lt;user&gt; &lt;valor&gt;\n/link &lt;url&gt; — keep-alive\n/unlink — parar\n\n⚡ " . BRAND);
}

function cmd_wh($chat) {
    $deps = dbQuery("SELECT d.*, u.username FROM deposits d LEFT JOIN users u ON u.id=d.user_id WHERE d.status='paid' ORDER BY d.id DESC LIMIT 20");
    $total = dbQuery("SELECT COALESCE(SUM(net_amount),0) as t FROM deposits WHERE status='paid'");
    $pend = dbQuery("SELECT COUNT(*) as c FROM deposits WHERE status='pending'");
    $txt = "<b>💰 WEBHOOK LOG</b>\n\n💵 Total: <b>" . fmt($total[0]['t'] ?? 0) . "</b>\n⏳ Pendentes: <b>" . ($pend[0]['c'] ?? 0) . "</b>\n\n";
    if (!$deps) $txt .= "<i>Nenhum depósito</i>";
    else foreach (array_slice($deps, 0, 15) as $d) $txt .= "👤 <b>" . ($d['username'] ?? 'N/A') . "</b> · " . fmt($d['net_amount'] ?? 0) . "\n   <code>" . ($d['ip'] ?? 'N/A') . "</code> · " . substr($d['paid_at'] ?? $d['created_at'] ?? '', 0, 16) . "\n";
    send($chat, $txt);
}

function cmd_set($chat) {
    $s = dbQuery("SELECT (SELECT COUNT(*) FROM users) as u, (SELECT COUNT(*) FROM checks) as c, (SELECT COUNT(*) FROM checks WHERE result='live') as l, (SELECT COUNT(*) FROM deposits WHERE status='paid') as d");
    $r = $s[0] ?? [];
    $txt = "<b>📊 STATS</b>\n\n👥 Users: <b>" . ($r['u'] ?? 0) . "</b>\n🔍 Checks: <b>" . ($r['c'] ?? 0) . "</b>\n✅ Lives: <b>" . ($r['l'] ?? 0) . "</b>\n💰 Dep: <b>" . ($r['d'] ?? 0) . "</b>\n\n<b>👤 Últimos:</b>\n";
    foreach (dbQuery("SELECT username, ip, created_at FROM users ORDER BY id DESC LIMIT 10") as $u) $txt .= "• <b>{$u['username']}</b> · <code>" . ($u['ip'] ?? 'N/A') . "</code>\n";
    send($chat, $txt);
}

function cmd_users($chat) {
    $users = dbQuery("SELECT username, balance, ip, total_checks, total_lives FROM users ORDER BY id DESC LIMIT 30");
    if (!$users) return send($chat, "<i>Nenhum usuário</i>");
    $txt = "<b>👥 USERS (" . count($users) . ")</b>\n\n";
    foreach ($users as $u) $txt .= "• <b>{$u['username']}</b> · " . fmt($u['balance'] ?? 0) . "\n   <code>" . ($u['ip'] ?? 'N/A') . "</code> · ✅{$u['total_lives']} ❌" . ((int)($u['total_checks'] ?? 0) - (int)($u['total_lives'] ?? 0)) . "\n";
    send($chat, $txt);
}

function cmd_stats($chat) {
    $s = dbQuery("SELECT (SELECT COUNT(*) FROM users) as u, (SELECT COUNT(*) FROM checks) as c, (SELECT COUNT(*) FROM checks WHERE result='live') as l, (SELECT COUNT(*) FROM checks WHERE result='die') as d, (SELECT COALESCE(SUM(net_amount),0) FROM deposits WHERE status='paid') as dep");
    $r = $s[0] ?? [];
    send($chat, "<b>📊 ESTATÍSTICAS</b>\n\n👥 " . ($r['u'] ?? 0) . "\n🔍 " . ($r['c'] ?? 0) . "\n✅ " . ($r['l'] ?? 0) . "\n❌ " . ($r['d'] ?? 0) . "\n💰 " . fmt($r['dep'] ?? 0));
}

function cmd_addsaldo($chat, $args) {
    if (count($args) < 2) return send($chat, "Uso: <code>/addsaldo user 10.50</code>");
    $user = $args[0]; $val = (float)str_replace(',', '.', $args[1]);
    if ($val <= 0) return send($chat, "❌ Valor inválido");
    $u = dbQuery("SELECT id, username, balance FROM users WHERE username = :u", [':u' => $user]);
    if (!$u) return send($chat, "❌ User não encontrado");
    $uid = (int)$u[0]['id']; $new = (float)$u[0]['balance'] + $val;
    dbExec("UPDATE users SET balance = :b, total_deposited = total_deposited + :v WHERE id = :i", [':b' => $new, ':v' => $val, ':i' => $uid]);
    send($chat, "✅ +" . fmt($val) . " em <b>{$user}</b>\n💳 Novo: <b>" . fmt($new) . "</b>");
}

function cmd_remsaldo($chat, $args) {
    if (count($args) < 2) return send($chat, "Uso: <code>/remsaldo user 10.50</code>");
    $user = $args[0]; $val = (float)str_replace(',', '.', $args[1]);
    if ($val <= 0) return send($chat, "❌ Valor inválido");
    $u = dbQuery("SELECT id, username, balance FROM users WHERE username = :u", [':u' => $user]);
    if (!$u) return send($chat, "❌ User não encontrado");
    $uid = (int)$u[0]['id']; $new = max(0, (float)$u[0]['balance'] - $val);
    dbExec("UPDATE users SET balance = :b WHERE id = :i", [':b' => $new, ':i' => $uid]);
    send($chat, "✅ -" . fmt($val) . " de <b>{$user}</b>\n💳 Novo: <b>" . fmt($new) . "</b>");
}

function cmd_link($chat, $args) {
    if (!$args) return send($chat, "Uso: <code>/link https://site.com</code>");
    $url = $args[0];
    if (!preg_match('#^https?://#', $url)) return send($chat, "❌ URL inválida");
    $GLOBALS['link_url'] = $url; $GLOBALS['link_active'] = true; $GLOBALS['link_last'] = 0;
    send($chat, "🔗 Keep-alive ATIVADO\n🌐 <code>{$url}</code>\n⏱ 10min");
}
function cmd_unlink($chat) { $GLOBALS['link_active'] = false; send($chat, "🔗 Keep-alive DESATIVADO"); }

function handle_msg($msg) {
    if (!isset($msg['chat']['id'], $msg['from']['id'])) return;
    $chat = $msg['chat']['id']; $uid = $msg['from']['id'];
    $text = trim($msg['text'] ?? '');
    if ($text === '' || $text[0] !== '/') return;
    if ($uid !== ADMIN_ID) { send($chat, "⛔ Acesso negado"); return; }
    $parts = preg_split('/\s+/', $text);
    $cmd = strtolower(explode('@', $parts[0])[0]);
    $args = array_slice($parts, 1);
    log_msg("CMD: {$cmd} " . implode(' ', $args));
    try {
        switch ($cmd) {
            case '/start': case '/help': cmd_start($chat); break;
            case '/wh': cmd_wh($chat); break;
            case '/set': cmd_set($chat); break;
            case '/users': cmd_users($chat); break;
            case '/stats': cmd_stats($chat); break;
            case '/addsaldo': cmd_addsaldo($chat, $args); break;
            case '/remsaldo': cmd_remsaldo($chat, $args); break;
            case '/link': cmd_link($chat, $args); break;
            case '/unlink': cmd_unlink($chat); break;
        }
    } catch (Throwable $e) { send($chat, "❌ Erro: " . $e->getMessage()); }
}

function keepalive_tick() {
    if (!$GLOBALS['link_active'] || !$GLOBALS['link_url']) return;
    $now = time();
    if ($now - $GLOBALS['link_last'] < 600) return;
    $GLOBALS['link_last'] = $now;
    try {
        $ch = curl_init($GLOBALS['link_url']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false]);
        curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        log_msg("KEEPALIVE {$GLOBALS['link_url']} => {$code}");
    } catch (Throwable $e) { log_msg("KEEPALIVE erro: " . $e->getMessage()); }
}

log_msg("⚡ HIPOLITO BOT iniciado");
log_msg("Admin: " . ADMIN_ID);
log_msg("DB: " . DB_FILE);

while (true) {
    try {
        $r = tg_api('getUpdates', ['offset' => $GLOBALS['last_update'] + 1, 'timeout' => 30]);
        if (!empty($r['ok']) && isset($r['result']) && is_array($r['result'])) {
            foreach ($r['result'] as $upd) {
                if (isset($upd['update_id'])) $GLOBALS['last_update'] = (int)$upd['update_id'];
                if (isset($upd['message'])) handle_msg($upd['message']);
            }
        } elseif (!empty($r['error'])) { log_msg("TG erro: " . $r['error']); sleep(3); }
        keepalive_tick();
    } catch (Throwable $e) { log_msg("LOOP erro: " . $e->getMessage()); sleep(3); }
}
