<?php
declare(strict_types=1);

/**
 * App de ventas para el celular: total del día y sonido de caja registradora en cada pedido nuevo.
 *   /admin/ventas.php            → la app (instalable en la pantalla de inicio)
 *   /admin/ventas.php?data=1     → JSON con las ventas de hoy (&since=ID para saber cuáles son nuevas)
 *   /admin/ventas.php?salir=1    → olvida este celular
 */
require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/helpers.php';

csrf_token(); // inicia la sesión del panel
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$db = db();

if (isset($_GET['salir'])) {
    SalesApp::forget();
    header('Location: /admin/?p=salir');
    exit;
}

$email = SalesApp::auth($db);
if (isset($_GET['data'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$email) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'login']);
        exit;
    }
    echo json_encode(SalesApp::today($db, (int) ($_GET['since'] ?? 0)), JSON_UNESCAPED_UNICODE);
    exit;
}
if (!$email) {
    $_SESSION['after_login'] = '/admin/ventas.php';
    header('Location: /admin/?p=login');
    exit;
}
SalesApp::remember($email);   // renueva los 30 días cada vez que se abre
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0e0b1a">
<meta name="robots" content="noindex">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Ventas">
<title>Ventas Tendencity</title>
<link rel="manifest" href="/admin/ventas.webmanifest">
<link rel="icon" href="/admin/ventas-192.png">
<link rel="apple-touch-icon" href="/assets/img/apple-touch.png">
<style>
  :root { --bg: #0e0b1a; --card: #1a1530; --line: #2c2547; --txt: #f4f1ff; --muted: #a39dbd; --ok: #4ade80; --bad: #f87171;
          --grad: linear-gradient(100deg, #3bb4f2 0%, #8e62f0 55%, #e05ff0 100%); }
  * { box-sizing: border-box; }
  html, body { margin: 0; background: var(--bg); color: var(--txt); font: 16px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif; }
  body { padding: max(16px, env(safe-area-inset-top)) 16px max(24px, env(safe-area-inset-bottom)); max-width: 560px; margin: 0 auto; }
  header { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
  header h1 { font-size: 1.05rem; margin: 0; font-weight: 600; }
  .live { display: inline-flex; align-items: center; gap: .4rem; font-size: .8rem; color: var(--muted); }
  .dot { width: 9px; height: 9px; border-radius: 50%; background: var(--muted); }
  .live.on .dot { background: var(--ok); box-shadow: 0 0 0 0 rgba(74,222,128,.6); animation: pulse 2s infinite; }
  .live.off .dot { background: var(--bad); }
  @keyframes pulse { 70% { box-shadow: 0 0 0 9px rgba(74,222,128,0); } 100% { box-shadow: 0 0 0 0 rgba(74,222,128,0); } }
  .hero { margin-top: 1rem; padding: 1.3rem 1.2rem; border-radius: 20px; background: var(--grad); color: #fff; }
  .hero small { opacity: .9; font-size: .85rem; }
  .hero .big { font-size: 2.6rem; font-weight: 800; letter-spacing: -.02em; line-height: 1.05; margin: .2rem 0; font-variant-numeric: tabular-nums; }
  .hero .cmp { font-size: .85rem; opacity: .95; }
  .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: .6rem; margin-top: .8rem; }
  .stat { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: .8rem; }
  .stat b { display: block; font-size: 1.35rem; font-variant-numeric: tabular-nums; }
  .stat span { color: var(--muted); font-size: .78rem; }
  h2 { font-size: .95rem; color: var(--muted); font-weight: 600; margin: 1.4rem 0 .6rem; }
  .order { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: .85rem .95rem; margin-bottom: .6rem; }
  .order.new { animation: flash 2.4s ease-out; border-color: #8e62f0; }
  @keyframes flash { 0% { background: #3b2a74; transform: scale(1.02); } 100% { background: var(--card); transform: none; } }
  .order .row { display: flex; justify-content: space-between; gap: .6rem; align-items: baseline; }
  .order .tot { font-weight: 700; font-size: 1.1rem; font-variant-numeric: tabular-nums; }
  .order .who { font-weight: 600; }
  .order .meta, .order .items { color: var(--muted); font-size: .85rem; margin-top: .2rem; }
  .empty { text-align: center; color: var(--muted); padding: 2rem 1rem; border: 1px dashed var(--line); border-radius: 14px; }
  .tools { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
  .tools button, .tools a { flex: 1 1 auto; font: inherit; font-size: .85rem; color: var(--txt); background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: .6rem .7rem; text-align: center; text-decoration: none; cursor: pointer; }
  .tools button.on { border-color: var(--ok); color: var(--ok); }
  .gate { position: fixed; inset: 0; z-index: 10; display: grid; place-items: center; padding: 24px; background: rgba(14,11,26,.94); }
  .gate[hidden] { display: none; }
  .gate div { max-width: 360px; text-align: center; }
  .gate .bell { font-size: 3.2rem; }
  .gate h3 { margin: .4rem 0; font-size: 1.3rem; }
  .gate p { color: var(--muted); font-size: .92rem; }
  .gate button { width: 100%; margin-top: 1rem; padding: 1rem; border: 0; border-radius: 14px; background: var(--grad); color: #fff; font: 700 1.05rem system-ui, sans-serif; cursor: pointer; }
  .note { color: var(--muted); font-size: .78rem; margin-top: 1rem; }
</style>
</head>
<body>
<header>
  <h1>Ventas · Tendencity <?= e(Country::name()) ?></h1>
  <span class="live" id="live"><span class="dot"></span><span id="liveTxt">Conectando…</span></span>
</header>

<section class="hero" aria-live="polite">
  <small>Vendido hoy</small>
  <div class="big" id="total">—</div>
  <div class="cmp" id="cmp">&nbsp;</div>
</section>

<div class="stats">
  <div class="stat"><b id="count">—</b><span>pedidos</span></div>
  <div class="stat"><b id="units">—</b><span>unidades</span></div>
  <div class="stat"><b id="avg">—</b><span>ticket prom.</span></div>
</div>

<h2>Pedidos de hoy</h2>
<div id="list"><div class="empty">Cargando…</div></div>

<div class="tools">
  <button type="button" id="test">🔔 Probar sonido</button>
  <button type="button" id="wake">📱 Pantalla siempre encendida</button>
  <a href="/admin/?p=pedidos">Ver en el panel</a>
</div>
<p class="note">Deja esta app abierta para escuchar cada venta. El celular revisa cada 15 segundos. Si la cierras o se bloquea la pantalla, el sistema la pausa: al volver, se pone al día sola.</p>

<div class="gate" id="gate">
  <div>
    <div class="bell" aria-hidden="true">🛎️</div>
    <h3>Activa el sonido de ventas</h3>
    <p>Tu celular solo deja sonar una página después de que la tocas. Pulsa el botón una vez cada vez que abras la app.</p>
    <button type="button" id="start">Activar sonido y avisos</button>
  </div>
</div>

<?= country_js() ?>
<script>
(() => {
  const TC = window.TC || { sym: 'Q', loc: 'es-GT' };
  const money = (n) => TC.sym + Math.round(n).toLocaleString(TC.loc);
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  let lastId = 0, ctx = null, wakeLock = null, timer = null, today = null;

  /* ---------- Sonido de caja registradora (sintetizado, sin archivos) ---------- */
  function kaching() {
    if (!ctx) return;
    const t = ctx.currentTime + 0.02;
    // 1) "Clac" del cajón: ráfaga corta de ruido filtrado
    const len = Math.floor(ctx.sampleRate * 0.09), buf = ctx.createBuffer(1, len, ctx.sampleRate), d = buf.getChannelData(0);
    for (let i = 0; i < len; i++) d[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / len, 3);
    const noise = ctx.createBufferSource(); noise.buffer = buf;
    const bp = ctx.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = 1800; bp.Q.value = 0.8;
    const ng = ctx.createGain(); ng.gain.value = 0.9;
    noise.connect(bp).connect(ng).connect(ctx.destination); noise.start(t);
    // 2) "¡Ching!": campana con armónicos, dos golpes
    const bell = (start, base, vol) => {
      [[1, 1], [2.76, 0.45], [5.4, 0.22], [8.93, 0.1]].forEach(([mult, amp]) => {
        const o = ctx.createOscillator(), g = ctx.createGain();
        o.type = 'sine'; o.frequency.value = base * mult;
        g.gain.setValueAtTime(0.0001, start);
        g.gain.exponentialRampToValueAtTime(vol * amp, start + 0.008);
        g.gain.exponentialRampToValueAtTime(0.0001, start + 1.6 / Math.sqrt(mult));
        o.connect(g).connect(ctx.destination); o.start(start); o.stop(start + 1.8);
      });
    };
    bell(t + 0.10, 1318.5, 0.5);
    bell(t + 0.24, 1760, 0.42);
  }

  /* ---------- Datos ---------- */
  function render(data, fresh) {
    today = data.date;
    $('total').textContent = money(data.total);
    $('count').textContent = data.count;
    $('units').textContent = data.units;
    $('avg').textContent = data.count ? money(data.avg) : '—';
    const y = data.yesterday;
    const diff = data.total - y.total;
    $('cmp').textContent = y.count || data.count
      ? `Ayer a esta hora: ${money(y.total)} (${y.count} ped.) · ${diff >= 0 ? '▲' : '▼'} ${money(Math.abs(diff))}`
      : 'Todavía no hay ventas hoy ni ayer a esta hora';
    $('list').innerHTML = data.orders.length ? data.orders.map((o) => `
      <article class="order${fresh.has(o.id) ? ' new' : ''}">
        <div class="row"><span class="who">${esc(o.name)}</span><span class="tot">${money(o.total)}</span></div>
        <div class="items">${esc(o.items)}</div>
        <div class="meta">${esc(o.time)} · ${esc(o.place)} · ${esc(o.method)} · ${esc(o.ref)}</div>
      </article>`).join('') : '<div class="empty">Aún no hay ventas hoy. Cuando entre la primera, sonará la caja. 🛎️</div>';
    document.title = (data.count ? `(${data.count}) ` : '') + 'Ventas Tendencity';
  }

  function live(state, text) {
    $('live').className = 'live ' + state;
    $('liveTxt').textContent = text;
  }

  async function poll() {
    clearTimeout(timer);
    try {
      const res = await fetch(`ventas.php?data=1&since=${lastId}`, { cache: 'no-store', credentials: 'same-origin' });
      if (res.status === 401) { location.href = '/admin/ventas.php'; return; }
      const data = await res.json();
      const isNewDay = today && data.date !== today;
      const fresh = new Set(lastId && !isNewDay ? data.new.map((o) => o.id) : []);
      if (fresh.size) announce(data.new);
      lastId = Math.max(lastId, data.last_id);
      render(data, fresh);
      live('on', `En vivo · ${data.now}`);
    } catch (e) {
      live('off', 'Sin conexión, reintentando…');
    }
    timer = setTimeout(poll, 15000);
  }

  function announce(orders) {
    kaching();
    navigator.vibrate?.([180, 80, 180]);
    if ('Notification' in window && Notification.permission === 'granted') {
      const total = orders.reduce((s, o) => s + o.total, 0);
      const title = orders.length > 1 ? `¡${orders.length} ventas nuevas! ${money(total)}` : `¡Nueva venta! ${money(total)}`;
      const body = orders.map((o) => `${o.name} · ${o.items}`).join('\n');
      navigator.serviceWorker?.getRegistration().then((r) => r
        ? r.showNotification(title, { body, icon: '/admin/ventas-192.png', badge: '/admin/ventas-192.png', tag: 'venta-' + orders[0].id })
        : new Notification(title, { body }));
    }
  }

  /* ---------- Pantalla encendida (opcional) ---------- */
  async function setWake(on) {
    try {
      if (on && 'wakeLock' in navigator) { wakeLock = await navigator.wakeLock.request('screen'); }
      else if (wakeLock) { await wakeLock.release(); wakeLock = null; }
    } catch (e) { wakeLock = null; }
    $('wake').classList.toggle('on', !!wakeLock);
    $('wake').textContent = wakeLock ? '📱 Pantalla encendida: sí' : '📱 Pantalla siempre encendida';
  }

  /* ---------- Arranque ---------- */
  $('start').addEventListener('click', async () => {
    ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
    await ctx.resume();
    kaching();
    if ('Notification' in window && Notification.permission === 'default') { try { await Notification.requestPermission(); } catch (e) {} }
    $('gate').hidden = true;
  });
  $('test').addEventListener('click', async () => { if (!ctx) { ctx = new (window.AudioContext || window.webkitAudioContext)(); } await ctx.resume(); kaching(); });
  $('wake').addEventListener('click', () => setWake(!wakeLock));
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      poll();                                   // al volver a la app, se pone al día
      if ($('wake').classList.contains('on') || wakeLock) setWake(true);
      ctx?.resume();
    }
  });
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/admin/ventas-sw.js', { scope: '/admin/' }).catch(() => {});
  poll();
})();
</script>
</body>
</html>
