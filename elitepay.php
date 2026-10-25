<?php
require_once __DIR__ . '/config.php';

function elitepayHeaders(): array {
    return [
        'x-client-id: ' . ELITEPAY_CLIENT_ID,
        'x-client-secret: ' . ELITEPAY_CLIENT_SECRET,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
}

function elitepayRequest(string $method, string $path, ?array $body = null): array {
    $url = ELITEPAY_BASE_URL . $path;
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => elitepayHeaders(),
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'code' => 0, 'error' => $err, 'data' => null];
    }
    $data = json_decode($resp, true);
    return [
        'ok' => $code >= 200 && $code < 300,
        'code' => $code,
        'error' => $code >= 400 ? ($data['message'] ?? 'HTTP ' . $code) : null,
        'data' => $data,
    ];
}

function eliteGetBalance(): array {
    $r = elitepayRequest('GET', '/api/v1/balance');
    return [
        'ok' => $r['ok'],
        'balance' => (float)($r['data']['balance'] ?? 0),
        'error' => $r['error'],
    ];
}

function eliteCreateDeposit(float $amount, string $description, string $payerName, string $payerDoc, string $webhookUrl): array {
    $body = [
        'amount' => round($amount, 2),
        'description' => $description,
    ];
    if ($payerName) $body['payerName'] = $payerName;
    if ($payerDoc) $body['payerDocument'] = preg_replace('/\D/', '', $payerDoc);
    if ($webhookUrl) $body['projectWebhook'] = $webhookUrl;

    $r = elitepayRequest('POST', '/api/v1/deposit', $body);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'] ?? 'Falha ao criar PIX'];
    $d = $r['data'];
    if (empty($d['transactionId'])) return ['ok' => false, 'error' => 'Resposta inválida do gateway'];
    return [
        'ok' => true,
        'transactionId' => $d['transactionId'],
        'qrcodeUrl' => $d['qrcodeUrl'] ?? '',
        'copyPaste' => $d['copyPaste'] ?? '',
        'amount' => (float)($d['valorOriginal'] ?? $amount),
        'netAmount' => (float)($d['valorLiquido'] ?? $amount * 0.98),
    ];
}

function eliteCheckTransaction(string $txid): array {
    $r = elitepayRequest('POST', '/api/v1/check', ['transactionId' => $txid]);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'state' => null];
    $t = $r['data']['transaction'] ?? [];
    return [
        'ok' => true,
        'state' => $t['transactionState'] ?? null,
        'statusMessage' => $t['statusMessage'] ?? null,
        'value' => (float)($t['value'] ?? 0),
        'netValue' => (float)($t['netValue'] ?? 0),
    ];
}

function eliteVerifyWebhookSignature(string $timestamp, string $rawBody, string $signature): bool {
    $base = $timestamp . '.' . $rawBody;
    $expected = 'sha256=' . hash_hmac('sha256', $base, ELITEPAY_WEBHOOK_SECRET);
    return hash_equals($expected, $signature);
}