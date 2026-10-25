<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/elitepay.php';

$rawBody = file_get_contents('php://input');
$ts = $_SERVER['HTTP_X_ELITE_TIMESTAMP'] ?? '';
$sig = $_SERVER['HTTP_X_ELITE_SIGNATURE'] ?? '';

if (!$rawBody || !$ts || !$sig) {
    http_response_code(400);
    echo 'bad request';
    exit;
}

if (!eliteVerifyWebhookSignature($ts, $rawBody, $sig)) {
    http_response_code(401);
    echo 'invalid signature';
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo 'invalid json';
    exit;
}

$txid = $payload['transactionId'] ?? $payload['acquirerTransactionId'] ?? '';
$state = $payload['transactionState'] ?? '';
$status = $payload['status'] ?? '';
$netValue = (float)($payload['netValue'] ?? $payload['value'] ?? 0);
$payerName = $payload['payer']['name'] ?? ($payload['payerName'] ?? '');
$payerDoc = $payload['payer']['document'] ?? ($payload['payerDocument'] ?? '');

if (!$txid) {
    http_response_code(200);
    echo 'no txid';
    exit;
}

$d = depositByTxid($txid);
if (!$d) {
    auditLog(null, null, 'webhook_orphan', "txid=$txid state=$state", clientIp());
    http_response_code(200);
    echo 'ok';
    exit;
}

if ($d['status'] === 'paid') {
    http_response_code(200);
    echo 'already paid';
    exit;
}

$isComplete = ($state === 'COMPLETO') || ($status === 'aprovado') || ($status === 'concluido');
$isFailed = in_array($state, ['FALHA', 'RECUSADO', 'CANCELADO', 'DEVOLVIDO', 'REEMBOLSADO', 'ESTORNO'], true);

if ($isComplete) {
    $net = $netValue > 0 ? $netValue : (float)$d['amount'];
    $ok = depositConfirm($txid, $net, $payerName, $payerDoc);
    if ($ok) {
        $u = userById((int)$d['user_id']);
        $msg = "💰 <b>DEPÓSITO CONFIRMADO</b>\n\n"
             . "👤 User: <b>" . ($u['username'] ?? 'N/A') . "</b>\n"
             . "🆔 ID: <code>" . $d['user_id'] . "</code>\n"
             . "💵 Valor: <b>" . fmtMoney($net) . "</b>\n"
             . "📱 IP: <code>" . ($d['ip'] ?? 'N/A') . "</code>\n"
             . "🔑 TXID: <code>" . substr($txid, 0, 20) . "...</code>\n"
             . "💳 Novo saldo: <b>" . fmtMoney((float)($u['balance'] ?? 0)) . "</b>\n"
             . "⚡ " . BRAND;
        pingBot($msg);
        auditLog((int)$d['user_id'], $u['username'] ?? null, 'deposit_paid', "amount=$net txid=$txid", clientIp());
    }
} elseif ($isFailed) {
    $db = db();
    $stmt = $db->prepare('UPDATE deposits SET status = :s WHERE transaction_id = :t');
    $stmt->bindValue(':s', strtolower($state), SQLITE3_TEXT);
    $stmt->bindValue(':t', $txid, SQLITE3_TEXT);
    $stmt->execute();
    auditLog((int)$d['user_id'], null, 'deposit_failed', "state=$state txid=$txid", clientIp());
}

http_response_code(200);
echo 'ok';