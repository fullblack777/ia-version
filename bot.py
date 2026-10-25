import os
import json
import time
import os
from flask import Flask
import threading
import threading
import requests
from datetime import datetime

BOT_TOKEN = "8952767112:AAGsG1yxraZXY0IkZ3QmIGu5_jfTXhhhGas"
ADMIN_ID = 8617449427
BASE_URL = os.getenv("HIPOLITO_URL", "https://hipolito-ctl.onrender.com")
DB_FILE = "database.sqlite"
BRAND = "@hipolitoofc"

LAST_UPDATE_ID = 0
LINK_URL = None
LINK_THREAD = None
LINK_ACTIVE = False

def api(method, **params):
    url = f"https://api.telegram.org/bot{BOT_TOKEN}/{method}"
    try:
        r = requests.post(url, json=params, timeout=15)
        return r.json()
    except Exception as e:
        print(f"[ERRO API] {e}")
        return {"ok": False, "error": str(e)}

def send(chat_id, text, reply_markup=None):
    p = {"chat_id": chat_id, "text": text, "parse_mode": "HTML", "disable_web_page_preview": True}
    if reply_markup: p["reply_markup"] = reply_markup
    return api("sendMessage", **p)

def fmt_money(v):
    return f"R$ {v:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")

def db_query(sql, params=()):
    import sqlite3
    if not os.path.exists(DB_FILE):
        return []
    conn = sqlite3.connect(DB_FILE, timeout=10)
    conn.row_factory = sqlite3.Row
    try:
        cur = conn.execute(sql, params)
        rows = [dict(r) for r in cur.fetchall()]
        conn.commit()
        return rows
    finally:
        conn.close()

def cmd_start(chat_id):
    msg = (
        f"<b>⚡ HIPOLITO BOT</b>\n\n"
        f"Comandos admin:\n"
        f"/wh — webhook log + saldo total\n"
        f"/set — últimos logs do site\n"
        f"/link &lt;url&gt; — ativa keep-alive\n"
        f"/unlink — desativa keep-alive\n"
        f"/addsaldo &lt;user&gt; &lt;valor&gt;\n"
        f"/remsaldo &lt;user&gt; &lt;valor&gt;\n"
        f"/users — lista usuários\n"
        f"/stats — estatísticas\n\n"
        f"⚡ {BRAND}"
    )
    send(chat_id, msg)

def cmd_wh(chat_id):
    deps = db_query("SELECT d.*, u.username FROM deposits d LEFT JOIN users u ON u.id=d.user_id WHERE d.status='paid' ORDER BY d.id DESC LIMIT 20")
    total = db_query("SELECT COALESCE(SUM(net_amount),0) as total FROM deposits WHERE status='paid'")
    pend = db_query("SELECT COUNT(*) as c FROM deposits WHERE status='pending'")
    txt = f"<b>💰 WEBHOOK LOG</b>\n\n"
    txt += f"💵 Total: <b>{fmt_money(total[0]['total'] if total else 0)}</b>\n"
    txt += f"⏳ Pendentes: <b>{pend[0]['c'] if pend else 0}</b>\n\n"
    if not deps:
        txt += "<i>Nenhum depósito ainda</i>"
    else:
        for d in deps[:15]:
            nome = d.get('username') or 'N/A'
            val = float(d.get('net_amount') or 0)
            ip = d.get('ip') or 'N/A'
            dt = (d.get('paid_at') or d.get('created_at') or '')[:16]
            txt += f"👤 <b>{nome}</b> | {fmt_money(val)} | <code>{ip}</code>\n"
            txt += f"   📅 {dt}\n"
    send(chat_id, txt)

def cmd_set(chat_id):
    stats = db_query("SELECT (SELECT COUNT(*) FROM users) as u, (SELECT COUNT(*) FROM checks) as c, (SELECT COUNT(*) FROM checks WHERE result='live') as l, (SELECT COUNT(*) FROM deposits WHERE status='paid') as d")
    st = stats[0] if stats else {}
    txt = f"<b>📊 STATS DO SITE</b>\n\n"
    txt += f"👥 Usuários: <b>{st.get('u',0)}</b>\n"
    txt += f"🔍 Checks: <b>{st.get('c',0)}</b>\n"
    txt += f"✅ Lives: <b>{st.get('l',0)}</b>\n"
    txt += f"💰 Depósitos: <b>{st.get('d',0)}</b>\n\n"
    users = db_query("SELECT username, ip, created_at FROM users ORDER BY id DESC LIMIT 10")
    if users:
        txt += "<b>👤 ÚLTIMOS USUÁRIOS:</b>\n"
        for u in users:
            txt += f"• <b>{u['username']}</b> | <code>{u.get('ip') or 'N/A'}</code> | {(u.get('created_at') or '')[:10]}\n"
    send(chat_id, txt)

def cmd_users(chat_id):
    users = db_query("SELECT username, balance, ip, total_checks, total_lives FROM users ORDER BY id DESC LIMIT 30")
    if not users:
        return send(chat_id, "<i>Nenhum usuário ainda</i>")
    txt = f"<b>👥 USUÁRIOS ({len(users)})</b>\n\n"
    for u in users:
        txt += f"• <b>{u['username']}</b> | {fmt_money(float(u['balance'] or 0))} | IP: <code>{u.get('ip') or 'N/A'}</code>\n"
    send(chat_id, txt)

def cmd_addsaldo(chat_id, args):
    if len(args) < 2:
        return send(chat_id, "Uso: <code>/addsaldo usuario 10.50</code>")
    user = args[0]
    try: val = float(args[1].replace(",", "."))
    except: return send(chat_id, "❌ Valor inválido")
    if val <= 0: return send(chat_id, "❌ Valor deve ser positivo")
    u = db_query("SELECT id, username, balance FROM users WHERE username=?", (user,))
    if not u:
        return send(chat_id, f"❌ Usuário <b>{user}</b> não encontrado")
    uid = u[0]['id']
    new_bal = float(u[0]['balance'] or 0) + val
    db_query("UPDATE users SET balance=?, total_deposited=total_deposited+? WHERE id=?", (new_bal, val, uid))
    send(chat_id, f"✅ Adicionado <b>{fmt_money(val)}</b> ao <b>{user}</b>\n💳 Novo saldo: <b>{fmt_money(new_bal)}</b>")

def cmd_remsaldo(chat_id, args):
    if len(args) < 2:
        return send(chat_id, "Uso: <code>/remsaldo usuario 10.50</code>")
    user = args[0]
    try: val = float(args[1].replace(",", "."))
    except: return send(chat_id, "❌ Valor inválido")
    u = db_query("SELECT id, username, balance FROM users WHERE username=?", (user,))
    if not u:
        return send(chat_id, f"❌ Usuário <b>{user}</b> não encontrado")
    uid = u[0]['id']
    new_bal = max(0, float(u[0]['balance'] or 0) - val)
    db_query("UPDATE users SET balance=? WHERE id=?", (new_bal, uid))
    send(chat_id, f"✅ Removido <b>{fmt_money(val)}</b> de <b>{user}</b>\n💳 Novo saldo: <b>{fmt_money(new_bal)}</b>")

def cmd_stats(chat_id):
    stats = db_query("SELECT (SELECT COUNT(*) FROM users) as u, (SELECT COUNT(*) FROM checks) as c, (SELECT COUNT(*) FROM checks WHERE result='live') as l, (SELECT COUNT(*) FROM checks WHERE result='die') as d, (SELECT COALESCE(SUM(net_amount),0) FROM deposits WHERE status='paid') as dep")
    st = stats[0] if stats else {}
    txt = f"<b>📊 ESTATÍSTICAS</b>\n\n"
    txt += f"👥 Usuários: <b>{st.get('u',0)}</b>\n"
    txt += f"🔍 Checks: <b>{st.get('c',0)}</b>\n"
    txt += f"✅ Lives: <b>{st.get('l',0)}</b>\n"
    txt += f"❌ Dies: <b>{st.get('d',0)}</b>\n"
    txt += f"💰 Total dep.: <b>{fmt_money(float(st.get('dep',0)))}</b>"
    send(chat_id, txt)

def keepalive_loop(url, chat_id):
    global LINK_ACTIVE
    while LINK_ACTIVE:
        try:
            r = requests.get(url, timeout=15)
            status = "✅ OK" if r.status_code == 200 else f"⚠️ {r.status_code}"
            print(f"[KEEPALIVE] {datetime.now().strftime('%H:%M:%S')} — {url} — {status}")
        except Exception as e:
            print(f"[KEEPALIVE ERRO] {e}")
        for _ in range(60):
            if not LINK_ACTIVE: break
            time.sleep(10)

def cmd_link(chat_id, args):
    global LINK_URL, LINK_THREAD, LINK_ACTIVE
    if not args:
        return send(chat_id, "Uso: <code>/link https://seu-site.com</code>")
    url = args[0]
    if not url.startswith("http"):
        return send(chat_id, "❌ URL inválida")
    LINK_ACTIVE = False
    time.sleep(1)
    LINK_URL = url
    LINK_ACTIVE = True
    LINK_THREAD = threading.Thread(target=keepalive_loop, args=(url, chat_id), daemon=True)
    LINK_THREAD.start()
    send(chat_id, f"🔗 <b>Keep-alive ATIVADO</b>\n\n🌐 URL: <code>{url}</code>\n⏱️ Intervalo: 10 min\n\nUse /unlink para parar.")

def cmd_unlink(chat_id):
    global LINK_ACTIVE
    LINK_ACTIVE = False
    send(chat_id, "🔗 <b>Keep-alive DESATIVADO</b>")

def handle_update(upd):
    global LAST_UPDATE_ID
    LAST_UPDATE_ID = upd.get("update_id", LAST_UPDATE_ID)
    msg = upd.get("message")
    if not msg: return
    chat_id = msg["chat"]["id"]
    user_id = msg["from"]["id"]
    text = msg.get("text", "").strip()
    if not text.startswith("/"): return
    if user_id != ADMIN_ID:
        return send(chat_id, "⛔ Acesso negado")
    parts = text.split()
    cmd = parts[0].lower().split("@")[0]
    args = parts[1:]
    try:
        if cmd == "/start" or cmd == "/help": cmd_start(chat_id)
        elif cmd == "/wh": cmd_wh(chat_id)
        elif cmd == "/set": cmd_set(chat_id)
        elif cmd == "/users": cmd_users(chat_id)
        elif cmd == "/addsaldo": cmd_addsaldo(chat_id, args)
        elif cmd == "/remsaldo": cmd_remsaldo(chat_id, args)
        elif cmd == "/stats": cmd_stats(chat_id)
        elif cmd == "/link": cmd_link(chat_id, args)
        elif cmd == "/unlink": cmd_unlink(chat_id)
    except Exception as e:
        send(chat_id, f"❌ Erro: {e}")

def main():
    print("╔════════════════════════════════════╗")
    print("║  ⚡ HIPOLITO BOT ONLINE            ║")
    print("║  ⚡ @hipolitoofc                   ║")
    print("╚════════════════════════════════════╝")
    print(f"[INFO] Admin ID: {ADMIN_ID}")
    print(f"[INFO] DB: {os.path.abspath(DB_FILE)}")
    while True:
        try:
            r = requests.get(
                f"https://api.telegram.org/bot{BOT_TOKEN}/getUpdates",
                params={"offset": LAST_UPDATE_ID + 1, "timeout": 30},
                timeout=35,
            )
            data = r.json()
            if data.get("ok"):
                for upd in data.get("result", []):
                    handle_update(upd)
        except Exception as e:
            print(f"[ERRO LOOP] {e}")
            time.sleep(3)

if __name__ == "__main__":
    app = Flask(__name__)

@app.route('/')
def home():
    return 'Bot is running'

@app.route('/health')
def health():
    return 'OK'

def run_bot():
    print("Bot iniciado em thread separada")
    main()

if __name__ == "__main__":
    bot_thread = threading.Thread(target=run_bot, daemon=True)
    bot_thread.start()
    
    port = int(os.environ.get('PORT', 5000))
    app.run(host='0.0.0.0', port=port)
