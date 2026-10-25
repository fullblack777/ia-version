<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';

secHeaders();

$ip = clientIp();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if (!csrfCheck($_POST['csrf'] ?? '')) jsonOut(['ok' => false, 'error' => 'CSRF inválido'], 403);

    if ($action === 'login') {
        if (!rateCheck('admin_login', $ip, 10, 900)) jsonOut(['ok' => false, 'error' => 'Muitas tentativas'], 429);
        $u = trim($_POST['username'] ?? '');
        $p = $_POST['password'] ?? '';
        if ($u === ADMIN_USER && hash_equals(ADMIN_PASS, $p)) {
            $_SESSION['admin'] = true;
            auditLog(null, 'admin', 'admin_login', "ip=$ip", $ip);
            jsonOut(['ok' => true]);
        }
        auditLog(null, $u, 'admin_login_fail', "ip=$ip", $ip);
        jsonOut(['ok' => false, 'error' => 'Credenciais inválidas']);
    }

    if (empty($_SESSION['admin'])) jsonOut(['ok' => false, 'error' => 'Não autorizado'], 401);

    if ($action === 'add_balance') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        if ($uid <= 0 || $amount <= 0) jsonOut(['ok' => false, 'error' => 'Dados inválidos']);
        $u = userById($uid);
        if (!$u) jsonOut(['ok' => false, 'error' => 'Usuário não encontrado']);
        $newBal = userAddBalance($uid, $amount);
        auditLog($uid, $u['username'], 'admin_add_balance', "amount=$amount new=$newBal", $ip);
        pingBot("💰 <b>ADMIN ADICIONOU SALDO</b>\n\n👤 User: <b>" . $u['username'] . "</b>\n💵 +" . fmtMoney($amount) . "\n💳 Novo: " . fmtMoney($newBal) . "\n⚡ " . BRAND);
        jsonOut(['ok' => true, 'balance' => $newBal]);
    }

    if ($action === 'rem_balance') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        if ($uid <= 0 || $amount <= 0) jsonOut(['ok' => false, 'error' => 'Dados inválidos']);
        $u = userById($uid);
        if (!$u) jsonOut(['ok' => false, 'error' => 'Usuário não encontrado']);
        $newBal = max(0, (float)$u['balance'] - $amount);
        userSetBalance($uid, $newBal);
        auditLog($uid, $u['username'], 'admin_rem_balance', "amount=$amount new=$newBal", $ip);
        jsonOut(['ok' => true, 'balance' => $newBal]);
    }

    if ($action === 'delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid <= 0) jsonOut(['ok' => false, 'error' => 'ID inválido']);
        $u = userById($uid);
        if (!$u) jsonOut(['ok' => false, 'error' => 'Usuário não encontrado']);
        $db = db();
        $stmt = $db->prepare('DELETE FROM users WHERE id = :i');
        $stmt->bindValue(':i', $uid, SQLITE3_INTEGER);
        $stmt->execute();
        auditLog(null, 'admin', 'admin_delete_user', "user=" . $u['username'] . " id=$uid", $ip);
        jsonOut(['ok' => true]);
    }

    if ($action === 'refresh') {
        jsonOut(['ok' => true, 'stats' => statsGlobal(), 'users' => listAllUsers(), 'deposits' => listRecentDeposits(50), 'checks' => listRecentChecks(100), 'audit' => listAudit(100)]);
    }

    jsonOut(['ok' => false, 'error' => 'Ação desconhecida'], 400);
}

if (isset($_GET['logout'])) {
    $_SESSION['admin'] = false;
    session_destroy();
    redirect('central.php');
}

if (empty($_SESSION['admin'])) {
    $csrf = csrfToken();
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ADMIN — <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:#050810;color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at 50% 50%,rgba(0,255,136,.05),transparent 60%);pointer-events:none}
.box{position:relative;max-width:400px;width:100%;background:rgba(10,15,24,.95);border:1px solid rgba(0,255,136,.2);border-radius:20px;padding:40px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.ico{width:60px;height:60px;margin:0 auto 16px;background:linear-gradient(135deg,#00ff88,#00d4ff);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:28px;box-shadow:0 8px 24px rgba(0,255,136,.3)}
h1{text-align:center;font-size:20px;font-weight:800;background:linear-gradient(135deg,#00ff88,#00d4ff);-webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:1px;margin-bottom:4px}
.sub{text-align:center;font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:2px;margin-bottom:28px}
.f{margin-bottom:16px}
label{display:block;font-size:10px;font-weight:700;color:#00d4ff;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:8px}
input{width:100%;padding:14px 16px;background:rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.1);border-radius:11px;color:#fff;font-size:14px;outline:none;transition:.2s;font-family:inherit}
input:focus{border-color:#00ff88;box-shadow:0 0 0 3px rgba(0,255,136,.1)}
.btn{width:100%;padding:14px;background:linear-gradient(135deg,#00ff88,#00d4ff);border:none;border-radius:11px;color:#000;font-size:13px;font-weight:800;cursor:pointer;text-transform:uppercase;letter-spacing:1px;transition:.2s;margin-top:8px;font-family:inherit}
.btn:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,255,136,.3)}
.alert{padding:12px 16px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#f87171;border-radius:10px;font-size:12px;margin-bottom:16px;display:none}
.alert.show{display:block}
</style>
</head>
<body>
<div class="box">
    <div class="ico">⚡</div>
    <h1>ADMIN PANEL</h1>
    <p class="sub"><?= SITE_NAME ?></p>
    <div class="alert" id="alert"></div>
    <form onsubmit="doLogin(event)">
        <div class="f"><label>Username</label><input type="text" name="username" required autocomplete="username"></div>
        <div class="f"><label>Senha</label><input type="password" name="password" required autocomplete="current-password"></div>
        <button type="submit" class="btn">Entrar</button>
    </form>
</div>
<script>
const CSRF='<?= $csrf ?>';
async function doLogin(e){e.preventDefault();const f=e.target;const a=document.getElementById('alert');a.classList.remove('show');
try{const r=await fetch('central.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'login',username:f.username.value,password:f.password.value,csrf:CSRF})});const j=await r.json();if(j.ok)location.reload();else{a.textContent=j.error;a.classList.add('show')}}catch(err){a.textContent='Erro de conexão';a.classList.add('show')}}
</script>
</body>
</html>
    <?php
    exit;
}

$csrf = csrfToken();
$stats = statsGlobal();
$users = listAllUsers();
$deposits = listRecentDeposits(50);
$checks = listRecentChecks(100);
$audit = listAudit(100);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ADMIN — <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--green:#00ff88;--cyan:#00d4ff;--yellow:#fbbf24;--red:#ef4444;--purple:#a78bfa;--bg:#050810;--surface:#0a0f18;--surface-2:#0d1520;--border:rgba(0,255,136,.15);--border-2:rgba(0,255,136,.3);--text:#e2e8f0;--muted:#64748b}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at 20% 10%,rgba(0,255,136,.05),transparent 50%),radial-gradient(ellipse at 80% 90%,rgba(0,212,255,.04),transparent 50%);pointer-events:none;z-index:0}
.container{position:relative;z-index:1;max-width:1400px;margin:0 auto;padding:24px}
.top{display:flex;align-items:center;justify-content:space-between;padding:16px 24px;margin-bottom:24px;background:rgba(10,15,24,.85);backdrop-filter:blur(20px);border:1px solid var(--border);border-radius:16px;flex-wrap:wrap;gap:12px}
.brand{display:flex;align-items:center;gap:12px}
.logo{width:44px;height:44px;background:linear-gradient(135deg,var(--green),var(--cyan));border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:0 4px 20px rgba(0,255,136,.3)}
.brand h1{font-size:18px;font-weight:800;background:linear-gradient(135deg,var(--green),var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.brand p{font-size:10px;color:var(--muted);letter-spacing:2px;text-transform:uppercase}
.btn-out{padding:10px 16px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:10px;color:#f87171;font-size:12px;font-weight:700;text-decoration:none}
.btn-out:hover{background:rgba(239,68,68,.2)}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:24px}
.stat{background:rgba(10,15,24,.85);border:1px solid var(--border);border-radius:14px;padding:20px;position:relative;overflow:hidden;transition:.2s}
.stat:hover{border-color:var(--border-2);transform:translateY(-2px)}
.stat .lbl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:6px;font-weight:600}
.stat .val{font-size:26px;font-weight:800;font-family:'JetBrains Mono',monospace}
.stat .ico{position:absolute;top:16px;right:16px;font-size:22px;opacity:.5}
.card{background:rgba(10,15,24,.85);backdrop-filter:blur(20px);border:1px solid var(--border);border-radius:16px;padding:24px;margin-bottom:20px}
.card h2{font-size:14px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:10px}
.tabs{display:flex;gap:6px;margin-bottom:20px;background:rgba(0,0,0,.4);border-radius:12px;padding:4px;flex-wrap:wrap}
.tab{padding:9px 16px;background:none;border:none;color:var(--muted);font-size:12px;font-weight:700;cursor:pointer;border-radius:9px;transition:.2s;text-transform:uppercase;letter-spacing:.8px;font-family:inherit}
.tab.active{background:linear-gradient(135deg,var(--green),var(--cyan));color:#000}
.tab-content{display:none}
.tab-content.active{display:block;animation:fade .3s}
@keyframes fade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
.table-wrap{overflow-x:auto;border-radius:12px;border:1px solid rgba(255,255,255,.05)}
table{width:100%;border-collapse:collapse;font-size:12px}
thead th{padding:12px 14px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);background:rgba(0,0,0,.3);border-bottom:1px solid var(--border);font-weight:700;white-space:nowrap}
tbody td{padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover td{background:rgba(0,255,136,.02)}
code{font-family:'JetBrains Mono',monospace;background:rgba(0,0,0,.4);padding:3px 8px;border-radius:6px;font-size:11px}
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.b-green{background:rgba(0,255,136,.12);color:var(--green);border:1px solid rgba(0,255,136,.25)}
.b-red{background:rgba(239,68,68,.12);color:#f87171;border:1px solid rgba(239,68,68,.25)}
.b-yellow{background:rgba(251,191,36,.12);color:var(--yellow);border:1px solid rgba(251,191,36,.25)}
.b-cyan{background:rgba(0,212,255,.12);color:var(--cyan);border:1px solid rgba(0,212,255,.25)}
.b-purple{background:rgba(167,139,250,.12);color:var(--purple);border:1px solid rgba(167,139,250,.25)}
.act-form{display:flex;gap:4px;align-items:center;flex-wrap:wrap}
.act-form input{width:70px;padding:6px 8px;background:rgba(0,0,0,.5);border:1px solid var(--border);border-radius:7px;color:var(--text);font-size:11px;outline:none;font-family:inherit}
.btn-sm{padding:6px 10px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);border-radius:7px;color:var(--text);font-size:11px;font-weight:700;cursor:pointer;transition:.2s;font-family:inherit}
.btn-sm:hover{border-color:var(--green);color:var(--green)}
.btn-sm.green{color:var(--green);border-color:rgba(0,255,136,.3)}
.btn-sm.red{color:#f87171;border-color:rgba(239,68,68,.3)}
.btn-sm.red:hover{background:rgba(239,68,68,.15)}
.empty{text-align:center;padding:40px;color:var(--muted);font-size:13px}
@media(max-width:768px){.container{padding:16px}.card{padding:16px}.stat .val{font-size:20px}}
</style>
</head>
<body>
<div class="container">
    <div class="top">
        <div class="brand">
            <div class="logo">⚡</div>
            <div><h1>ADMIN PANEL</h1><p><?= SITE_NAME ?></p></div>
        </div>
        <a href="?logout" class="btn-out">Sair</a>
    </div>

    <div class="stats">
        <div class="stat"><div class="lbl">Usuários</div><div class="val" style="color:var(--green)"><?= $stats['users'] ?></div><div class="ico">👥</div></div>
        <div class="stat"><div class="lbl">Total Depositado</div><div class="val" style="color:var(--yellow);font-size:18px"><?= fmtMoney($stats['deposits_total']) ?></div><div class="ico">💰</div></div>
        <div class="stat"><div class="lbl">Depósitos</div><div class="val" style="color:var(--cyan)"><?= $stats['deposits_count'] ?></div><div class="ico">🎫</div></div>
        <div class="stat"><div class="lbl">Pendentes</div><div class="val" style="color:var(--yellow)"><?= $stats['deposits_pending'] ?></div><div class="ico">⏳</div></div>
        <div class="stat"><div class="lbl">Checks</div><div class="val" style="color:var(--purple)"><?= $stats['checks_total'] ?></div><div class="ico">🔍</div></div>
        <div class="stat"><div class="lbl">Lives</div><div class="val" style="color:var(--green)"><?= $stats['lives_total'] ?></div><div class="ico">✅</div></div>
        <div class="stat"><div class="lbl">Dies</div><div class="val" style="color:#f87171"><?= $stats['dies_total'] ?></div><div class="ico">❌</div></div>
    </div>

    <div class="tabs">
        <button class="tab active" onclick="showTab('users')">👥 Usuários</button>
        <button class="tab" onclick="showTab('deposits')">💰 Depósitos</button>
        <button class="tab" onclick="showTab('checks')">🔍 Checks</button>
        <button class="tab" onclick="showTab('audit')">📋 Logs</button>
    </div>

    <div class="tab-content active" id="tab-users">
        <div class="card">
            <h2>👥 Usuários (<?= count($users) ?>)</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>ID</th><th>Username</th><th>Saldo</th><th>Depositou</th><th>Checks</th><th>Lives</th><th>IP</th><th>Criado</th><th>Ações</th></tr></thead>
                    <tbody>
                    <?php if (empty($users)): ?>
                    <tr><td colspan="9" class="empty">Nenhum usuário</td></tr>
                    <?php else: foreach ($users as $u): ?>
                    <tr>
                        <td><code>#<?= $u['id'] ?></code></td>
                        <td><strong><?= esc($u['username']) ?></strong></td>
                        <td><span style="color:var(--green);font-weight:700"><?= fmtMoney((float)$u['balance']) ?></span></td>
                        <td><span style="color:var(--yellow)"><?= fmtMoney((float)$u['total_deposited']) ?></span></td>
                        <td><?= (int)$u['total_checks'] ?></td>
                        <td><span class="badge b-green"><?= (int)$u['total_lives'] ?></span></td>
                        <td><code style="font-size:10px"><?= esc($u['ip'] ?? 'N/A') ?></code></td>
                        <td style="font-size:11px;color:var(--muted)"><?= date('d/m/y H:i', strtotime($u['created_at'])) ?></td>
                        <td>
                            <div class="act-form">
                                <input type="number" step="0.01" placeholder="R$" id="add-<?= $u['id'] ?>">
                                <button class="btn-sm green" onclick="addBalance(<?= $u['id'] ?>)">+</button>
                                <button class="btn-sm red" onclick="remBalance(<?= $u['id'] ?>)">-</button>
                                <button class="btn-sm red" onclick="delUser(<?= $u['id'] ?>,'<?= esc($u['username']) ?>')">🗑</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-content" id="tab-deposits">
        <div class="card">
            <h2>💰 Últimos Depósitos (<?= count($deposits) ?>)</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>ID</th><th>User</th><th>Valor</th><th>Líquido</th><th>Status</th><th>Payer</th><th>IP</th><th>TXID</th><th>Data</th></tr></thead>
                    <tbody>
                    <?php if (empty($deposits)): ?>
                    <tr><td colspan="9" class="empty">Nenhum depósito</td></tr>
                    <?php else: foreach ($deposits as $d): ?>
                    <tr>
                        <td><code>#<?= $d['id'] ?></code></td>
                        <td><strong><?= esc($d['username'] ?? 'N/A') ?></strong></td>
                        <td><span style="color:var(--yellow)"><?= fmtMoney((float)$d['amount']) ?></span></td>
                        <td><span style="color:var(--green)"><?= $d['net_amount'] ? fmtMoney((float)$d['net_amount']) : '—' ?></span></td>
                        <td><?php $st = $d['status']; $bc = $st==='paid'?'b-green':($st==='pending'?'b-yellow':'b-red'); ?>
                            <span class="badge <?= $bc ?>"><?= $st ?></span></td>
                        <td style="font-size:11px"><?= esc($d['payer_name'] ?? '—') ?></td>
                        <td><code style="font-size:10px"><?= esc($d['ip'] ?? 'N/A') ?></code></td>
                        <td><code style="font-size:10px"><?= esc(substr($d['transaction_id'] ?? '', 0, 12)) ?>...</code></td>
                        <td style="font-size:11px;color:var(--muted)"><?= date('d/m/y H:i', strtotime($d['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-content" id="tab-checks">
        <div class="card">
            <h2>🔍 Últimos Checks (<?= count($checks) ?>)</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>ID</th><th>User</th><th>Gate</th><th>Tipo</th><th>Cartão</th><th>Result</th><th>Custo</th><th>IP</th><th>Data</th></tr></thead>
                    <tbody>
                    <?php if (empty($checks)): ?>
                    <tr><td colspan="9" class="empty">Nenhum check</td></tr>
                    <?php else: foreach ($checks as $c): ?>
                    <tr>
                        <td><code>#<?= $c['id'] ?></code></td>
                        <td><strong><?= esc($c['username'] ?? 'N/A') ?></strong></td>
                        <td><code><?= esc($c['gate']) ?></code></td>
                        <td><span class="badge <?= $c['gate_type']==='gate'?'b-cyan':'b-purple' ?>"><?= $c['gate_type'] ?></span></td>
                        <td><code style="font-size:10px"><?= esc(substr($c['card'] ?? '', 0, 6)) ?>***<?= esc(substr($c['card'] ?? '', -4)) ?></code></td>
                        <td><span class="badge <?= $c['result']==='live'?'b-green':'b-red' ?>"><?= $c['result'] ?></span></td>
                        <td><span style="color:var(--yellow);font-weight:700"><?= fmtMoney((float)$c['cost']) ?></span></td>
                        <td><code style="font-size:10px"><?= esc($c['ip'] ?? 'N/A') ?></code></td>
                        <td style="font-size:11px;color:var(--muted)"><?= date('d/m/y H:i', strtotime($c['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-content" id="tab-audit">
        <div class="card">
            <h2>📋 Log de Auditoria (<?= count($audit) ?>)</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>ID</th><th>User</th><th>Ação</th><th>Detalhes</th><th>IP</th><th>Data</th></tr></thead>
                    <tbody>
                    <?php if (empty($audit)): ?>
                    <tr><td colspan="6" class="empty">Nenhum log</td></tr>
                    <?php else: foreach ($audit as $a): ?>
                    <tr>
                        <td><code>#<?= $a['id'] ?></code></td>
                        <td><strong><?= esc($a['username'] ?? 'sistema') ?></strong></td>
                        <td><code style="font-size:10px;color:var(--cyan)"><?= esc($a['action']) ?></code></td>
                        <td style="font-size:11px;color:var(--muted);max-width:300px;word-break:break-word"><?= esc($a['details'] ?? '') ?></td>
                        <td><code style="font-size:10px"><?= esc($a['ip'] ?? 'N/A') ?></code></td>
                        <td style="font-size:11px;color:var(--muted)"><?= date('d/m/y H:i:s', strtotime($a['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF='<?= $csrf ?>';
function showTab(id){document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));document.querySelectorAll('.tab-content').forEach(c=>c.classList.remove('active'));document.getElementById('tab-'+id).classList.add('active');event.target.classList.add('active')}

async function post(action,data){const r=await fetch('central.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action,...data,csrf:CSRF})});return await r.json()}

async function addBalance(uid){
    const inp=document.getElementById('add-'+uid);
    const v=parseFloat(inp.value);
    if(!v||v<=0)return alert('Digite um valor');
    if(!confirm('Adicionar R$ '+v.toFixed(2)+' ao usuário #'+uid+'?'))return;
    const j=await post('add_balance',{user_id:uid,amount:v});
    if(j.ok){alert('✅ Adicionado! Novo saldo: R$ '+j.balance.toFixed(2));inp.value='';location.reload()}
    else alert('❌ '+j.error);
}

async function remBalance(uid){
    const inp=document.getElementById('add-'+uid);
    const v=parseFloat(inp.value);
    if(!v||v<=0)return alert('Digite um valor');
    if(!confirm('Remover R$ '+v.toFixed(2)+' do usuário #'+uid+'?'))return;
    const j=await post('rem_balance',{user_id:uid,amount:v});
    if(j.ok){alert('✅ Removido! Novo saldo: R$ '+j.balance.toFixed(2));inp.value='';location.reload()}
    else alert('❌ '+j.error);
}

async function delUser(uid,username){
    if(!confirm('DELETAR usuário "'+username+'" (# '+uid+')? ISSO É PERMANENTE!'))return;
    if(!confirm('TEM CERTEZA? Todos os dados serão perdidos!'))return;
    const j=await post('delete_user',{user_id:uid});
    if(j.ok){alert('✅ Deletado!');location.reload()}
    else alert('❌ '+j.error);
}
</script>
</body>
</html>