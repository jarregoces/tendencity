<?php
declare(strict_types=1);

/**
 * Admin → Configuración: Píxeles y analítica, Mastershop, Usuarios y Productos de prueba.
 * Lo incluye public/admin/index.php.
 */

/** Versión del panel: se muestra abajo en el menú lateral para confirmar qué archivos están en el servidor. */
const ADMIN_VERSION = '2026.11.13-29';

const PIXEL_FIELDS = [
    'meta_pixel_id'            => ['Meta Pixel (Facebook / Instagram)', 'Solo el número, ej. 1234567890123456', '/^\d{10,20}$/'],
    'meta_domain_verification' => ['Verificación de dominio de Meta', 'El valor de content de la etiqueta meta que te da Business Manager', '/^[a-z0-9]{10,64}$/i'],
    'ga4_id'                   => ['Google Analytics 4', 'ID de medición, ej. G-ABC123XYZ', '/^G-[A-Z0-9]{4,15}$/i'],
    'gtm_id'                   => ['Google Tag Manager', 'ID del contenedor, ej. GTM-ABC1234 (opcional)', '/^GTM-[A-Z0-9]{4,10}$/i'],
    'google_site_verification' => ['Google Search Console', 'El valor de content de la etiqueta HTML de verificación', '/^[A-Za-z0-9_\-]{20,100}$/'],
    'tiktok_pixel_id'          => ['TikTok Pixel', 'ID del píxel, ej. C1A2B3C4D5E6F7 (opcional)', '/^[A-Z0-9]{10,30}$/i'],
];

function page_pixeles(): void
{ ?>
    <h1>Píxeles y analítica</h1>
    <p class="muted">Pega solo el ID; el código se instala solo en todas las páginas de la tienda. Deja vacío lo que no uses.</p>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="save_pixels">
      <fieldset><legend>Publicidad</legend>
        <?php foreach (['meta_pixel_id', 'meta_domain_verification', 'tiktok_pixel_id'] as $k) { pixel_input($k); } ?>
      </fieldset>
      <fieldset><legend>Google</legend>
        <?php foreach (['ga4_id', 'gtm_id', 'google_site_verification'] as $k) { pixel_input($k); } ?>
        <p class="muted">Si usas Tag Manager y ya configuraste ahí Google Analytics, deja vacío el campo de GA4 para no contar doble.</p>
      </fieldset>
      <button class="big">Guardar</button>
    </form>
    <?php $tok = (string) setting('meta_capi_token', ''); $last = json_decode((string) setting('meta_capi_last', ''), true); ?>
    <form method="post" class="form" style="margin-top:1rem"><?= csrf_field() ?><input type="hidden" name="action" value="save_capi">
      <fieldset><legend>API de Conversiones de Meta <?= MetaCapi::ready() ? '<span class="pill-ok">Activa</span>' : '' ?></legend>
        <p class="muted">Envía cada compra desde tu servidor, además del píxel. Recupera las compras que el navegador pierde (iPhone, bloqueadores) y mejora la optimización de tus campañas.
          Usa el mismo ID de píxel de arriba.</p>
        <label>Token de acceso <?= $tok ? '<small>(guardado: termina en ' . e(substr($tok, -6)) . '; deja vacío para conservarlo)</small>' : '' ?>
          <input name="meta_capi_token" type="password" autocomplete="off" placeholder="<?= $tok ? '••••••••••••' : 'Pega aquí el token' ?>">
          <small>Administrador de eventos → tu píxel → Configuración → API de Conversiones → «Generar token de acceso».</small></label>
        <label>Código de prueba <small>(opcional, solo mientras pruebas)</small><input name="meta_test_code" value="<?= e(setting('meta_test_code', '')) ?>" placeholder="TEST12345">
          <small>Administrador de eventos → Probar eventos. Con código, los eventos aparecen ahí y no cuentan en tus campañas: bórralo cuando termines.</small></label>
        <div class="bar" style="justify-content:flex-start;margin:0">
          <button>Guardar</button>
          <?php if ($tok): ?><button name="action" value="capi_remove" class="ghost" onclick="return confirm('¿Quitar el token?')">Quitar token</button><?php endif; ?>
        </div>
      </fieldset>
    </form>
    <?php if (MetaCapi::ready()): ?>
    <form method="post" class="box" style="max-width:860px;margin-top:1rem"><?= csrf_field() ?><input type="hidden" name="action" value="capi_test">
      <h2>Probar la conexión</h2>
      <p class="muted">Envía un evento de prueba (PageView). Para verlo en Meta, pon antes un código de prueba.</p>
      <button class="ghost">Enviar evento de prueba</button>
      <?php if ($last): ?><p style="margin-top:.8rem">Último envío (<?= e($last['at']) ?>, <?= e($last['event']) ?> <?= e($last['id']) ?>):
        <?= $last['ok'] ? '<span class="pill-ok">Recibido por Meta</span>' : '<span class="bad">Falló (HTTP ' . (int) $last['status'] . ')</span> <small class="muted">' . e($last['body']) . '</small>' ?></p><?php endif; ?>
    </form>
    <?php endif; ?>
    <section class="box" style="max-width:860px;margin-top:1rem">
      <h2>Eventos que se envían</h2>
      <table class="t t--plain"><thead><tr><th>Momento</th><th>Meta / TikTok</th><th>Google Analytics</th></tr></thead><tbody>
        <tr><td>Cualquier página</td><td>PageView</td><td>page_view</td></tr>
        <tr><td>Ficha de producto</td><td>ViewContent</td><td>view_item</td></tr>
        <tr><td>Abre el formulario de pedido</td><td>InitiateCheckout</td><td>begin_checkout</td></tr>
        <tr><td>Pedido confirmado</td><td>Purchase desde el navegador <?= MetaCapi::ready() ? '<strong>y desde el servidor (API de Conversiones)</strong>' : '' ?>, con el número de pedido para no contar doble</td><td>purchase</td></tr>
      </tbody></table>
      <p class="muted">Purchase se envía en la página de confirmación, con el valor y la moneda (COP). Es el evento que debes optimizar en tus campañas de Meta.</p>
    </section>
<?php }

function pixel_input(string $k): void
{
    [$label, $help] = PIXEL_FIELDS[$k];
    $v = (string) setting($k, ''); ?>
    <label><?= e($label) ?> <?= $v ? '<span class="pill-ok">Activo</span>' : '' ?>
      <input name="<?= e($k) ?>" value="<?= e($v) ?>" placeholder="<?= e($help) ?>" autocomplete="off"><small><?= e($help) ?></small></label>
<?php }

/** Claves del .env que faltan o quedaron con el texto de ejemplo. */
function env_problems(): array
{
    $out = [];
    foreach (['WEBHOOK_TOKEN' => 'sin él no se pueden registrar los webhooks', 'APP_SECRET' => 'firma los enlaces de confirmación de pedido; sin él, otros podrían ver pedidos ajenos'] as $k => $why) {
        $v = (string) env($k, '');
        if (strlen($v) < 32 || !preg_match('/^[a-f0-9]+$/i', $v)) {
            $out[] = "Falta {$k} en el archivo .env ({$why}). Debe ser una línea así: {$k}= seguido de 64 letras y números, sin espacios ni comentarios.";
        }
    }
    foreach (['SITE_URL', 'WEBHOOK_URL'] as $k) {
        $v = (string) env($k, '');
        if ($v !== '' && !preg_match('#^https?://[^\s]+$#', $v)) {
            $out[] = "{$k} en el .env no es una dirección válida: debe quedar como https://tudominio.co" . ($k === 'WEBHOOK_URL' ? '/webhook.php' : '');
        }
    }
    return $out;
}

/** Revisa el formato de la API key sin mostrarla completa. */
function api_key_problems(string $raw): array
{
    $p = [];
    if ($raw === '' || str_contains($raw, 'PEGA_AQUI')) {
        return ['No hay API key en el archivo .env (sigue el texto de ejemplo PEGA_AQUI).'];
    }
    if (preg_match('/\s/', $raw)) { $p[] = 'Tiene espacios o saltos de línea en medio: cópiala de nuevo con el botón de copiar de Mastershop.'; }
    if (preg_match('/["\'`<>]/', $raw)) { $p[] = 'Tiene comillas o signos < >: en el .env va sin comillas, así: MASTERSHOP_API_KEY=tullave'; }
    if (strlen($raw) < 20) { $p[] = 'Es muy corta (' . strlen($raw) . ' caracteres): puede que se haya copiado incompleta.'; }
    if (preg_match('/^[•*.\-]+$/u', $raw) || str_contains($raw, '•')) { $p[] = 'Parece copiada con los puntos que oculta Mastershop: usa el botón de copiar, no el texto visible.'; }
    return $p;
}

/** Mensaje claro según la respuesta de Mastershop. */
function ms_error_text(MastershopException $e): string
{
    $body = $e->body ? ($e->body['error'] ?? $e->body['message'] ?? null) : null;
    $detail = ' (Mastershop respondió HTTP ' . $e->status() . ($body ? ': ' . (is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE)) : '') . ')';
    $key = (string) env('MASTERSHOP_API_KEY', '');
    $fmt = api_key_problems($key);
    return match (true) {
        $e->status() === 403 => 'La API key es válida, pero no tiene permiso para esta operación' . $detail
            . '. Según la documentación de Mastershop, el 403 es un problema de permisos de la llave o de tu cuenta, no de la llave en sí. '
            . 'Usa «Probar permisos» en Configuración → Mastershop para ver qué operaciones tienes habilitadas.',
        $e->status() === 401 => 'Mastershop rechazó la API key' . $detail . '. '
            . ($fmt ? 'Problema encontrado: ' . implode(' ', $fmt)
                    : 'El formato se ve bien (termina en ' . substr($key, -4) . ', ' . strlen($key) . ' caracteres): verifica en Mastershop que esa llave esté activa, o crea una nueva con «Nueva Api Key» y reemplázala en el .env.'),
        $e->status() === 429 => 'Mastershop pidió esperar (demasiadas peticiones). Intenta de nuevo en unos minutos.',
        $e->status() === 0 => 'No se pudo conectar con Mastershop: ' . $e->getMessage(),
        default => 'Mastershop respondió con error' . $detail . '.',
    };
}

function page_mastershop(PDO $db): void
{
    $key = (string) env('MASTERSHOP_API_KEY', '');
    $keyOk = $key !== '' && !str_contains($key, 'PEGA_AQUI');
    $keyProblems = api_key_problems($key);
    $lastSync = $db->query('SELECT MAX(synced_at) FROM products WHERE ms_product_id <= ' . DemoSeeder::ID_FROM)->fetchColumn();
    $nProducts = (int) $db->query('SELECT COUNT(*) FROM products WHERE ms_product_id <= ' . DemoSeeder::ID_FROM)->fetchColumn();
    $subs = $db->query('SELECT * FROM webhook_subscriptions ORDER BY id_webhook')->fetchAll();
    $events = $db->query('SELECT id, event_name, ms_order_id, received_at, processed_at, error FROM webhook_events ORDER BY id DESC LIMIT 15')->fetchAll();
    $errors = (int) $db->query("SELECT COUNT(*) FROM orders WHERE sync_status = 'error'")->fetchColumn();
    $hookUrl = WebhookRegistrar::url();
    ?>
    <h1>Conexión con Mastershop</h1>
    <?php foreach (env_problems() as $msg): ?><p class="flash flash--bad"><?= e($msg) ?></p><?php endforeach; ?>
    <div class="kpis">
      <div><span>API key</span><strong class="<?= $keyOk ? '' : 'bad' ?>"><?= $keyOk ? 'Configurada' : 'Falta' ?></strong>
        <small><?= $keyOk ? 'Termina en ' . e(substr($key, -4)) . ' · ' . strlen($key) . ' caracteres' : 'Agrégala en el archivo .env' ?></small></div>
      <div><span>Productos sincronizados</span><strong><?= num($nProducts) ?></strong><small><?= $lastSync ? 'Última vez: ' . e(date('d/m/Y H:i', strtotime($lastSync))) : 'Nunca' ?></small></div>
      <div><span>Webhooks</span><strong><?= count(array_filter($subs, fn($s) => $s['is_enabled'])) ?> / <?= count(WebhookRegistrar::EVENTS) ?></strong><small>eventos registrados</small></div>
      <div><span>Pedidos con error</span><strong class="<?= $errors ? 'bad' : '' ?>"><?= num($errors) ?></strong><small><a href="?p=pedidos&f=error">Ver pedidos</a></small></div>
    </div>

    <div class="cols">
      <section class="box"><h2>Conexión</h2>
        <?php if ($keyOk && $keyProblems): ?><div class="flash flash--bad"><?= e(implode(' ', $keyProblems)) ?></div><?php endif; ?>
        <p class="muted">La API key vive en el archivo <code>.env</code> del servidor (no en la base de datos) para que nadie la vea desde el panel.
          Se saca en Mastershop → Api Key → copiar.</p>
        <div class="bar" style="justify-content:flex-start">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ms_test"><button <?= $keyOk ? '' : 'disabled' ?>>Probar conexión</button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ms_perms"><button class="ghost" <?= $keyOk ? '' : 'disabled' ?>>Probar permisos</button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><input type="hidden" name="back" value="mastershop"><button class="ghost" <?= $keyOk ? '' : 'disabled' ?>>Sincronizar productos ahora</button></form>
        </div>
        <p class="muted">La sincronización automática corre con el cron cada 30 minutos (ver README).</p>
      </section>
      <section class="box"><h2>Webhooks (estados de envío)</h2>
        <p class="muted">Mastershop avisa a la tienda cada vez que un pedido cambia de estado o se genera la guía.</p>
        <?php if (!$hookUrl): ?><p class="bad">Falta WEBHOOK_TOKEN en el .env.</p>
        <?php else: ?>
          <p class="muted">Para configurarlos a mano en Mastershop usa esta URL en todos los eventos (es secreta: no la compartas):</p>
          <div class="utm__out"><input readonly value="<?= e($hookUrl) ?>" id="hook-url"><button type="button" class="ghost" onclick="navigator.clipboard.writeText(document.getElementById('hook-url').value);this.textContent='Copiada'">Copiar URL</button></div>
          <details style="margin:.6rem 0"><summary>Eventos que debes suscribir</summary>
            <ul><?php foreach (WebhookRegistrar::EVENTS as $id => $n): ?><li><?= e($n) ?> <small class="muted">(id <?= (int) $id ?>)</small></li><?php endforeach; ?></ul>
            <p class="muted">En el nombre del webhook usa solo letras y espacios, por ejemplo «Tendencity pedidos».</p></details>
          <p class="muted">O deja que la tienda los registre sola:</p>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ms_webhooks"><button <?= $keyOk ? '' : 'disabled' ?>>Registrar webhooks</button></form>
        <?php endif; ?>
        <?php if ($subs): ?>
          <table class="t t--plain" style="margin-top:.8rem"><tbody>
          <?php foreach ($subs as $s): ?><tr><td><?= e(WebhookRegistrar::EVENTS[(int) $s['id_webhook']] ?? 'Evento ' . $s['id_webhook']) ?></td><td><?= $s['is_enabled'] ? '<span class="pill-ok">Activo</span>' : 'Inactivo' ?></td></tr><?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
      </section>
    </div>

    <?php if (!empty($_SESSION['ms_perms'])): $perm = $_SESSION['ms_perms']; ?>
    <section class="box"><h2>Permisos de tu API key</h2>
      <p class="muted">Probado el <?= e($perm['at']) ?>. Solo se prueban operaciones de lectura: no se crea ni se cambia nada en tu cuenta.<?= !empty($perm['ip']) ? ' IP de salida de tu servidor: <strong>' . e($perm['ip']) . '</strong>' : '' ?></p>
      <table class="t"><thead><tr><th>Operación</th><th>Para qué la usa la tienda</th><th>Resultado</th></tr></thead><tbody>
      <?php foreach ($perm['rows'] as $r): ?>
        <tr><td><strong><?= e($r['name']) ?></strong><br><small class="muted"><?= e($r['endpoint']) ?></small></td><td><?= e($r['use']) ?></td>
          <td><?= $r['ok'] === null ? '<span class="muted">No se prueba (crearía datos reales)</span>' : ($r['ok'] ? '<span class="pill-ok">Permitido</span>' : '<span class="bad">' . e($r['status'] === 403 ? 'Sin permiso (403)' : ($r['status'] === 401 ? 'Llave rechazada (401)' : 'Error ' . $r['status'])) . '</span>') ?>
            <?php if ($r['ok'] === false && $r['msg']): ?><br><small class="muted"><?= e(mb_substr($r['msg'], 0, 120)) ?></small><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
      <?php $denied = array_filter($perm['rows'], fn($r) => $r['ok'] === false); if ($denied): ?>
        <div class="help" style="margin-top:1rem"><p><strong>Mensaje para soporte de Mastershop</strong> (cópialo y envíalo por su chat o a soporte@mastershop.com):</p>
        <pre id="ms-msg">Hola, estoy conectando mi tienda web a la API pública de Mastershop con una API Key de mi negocio (termina en <?= e(substr((string) env('MASTERSHOP_API_KEY'), -4)) ?>).
La llave funciona desde mi computador (GET /api/products responde 200), pero desde mi servidor web todas las operaciones responden 403 Forbidden<?= !empty($perm['ip']) ? ' (IP del servidor: ' . e($perm['ip']) . ')' : '' ?>:
<?php foreach ($denied as $r): ?>- <?= e($r['name']) ?>: <?= e($r['endpoint']) ?>

<?php endforeach; ?>¿Me pueden habilitar el acceso desde la IP de mi servidor o indicarme qué está bloqueando la petición? Gracias.</pre>
        <button type="button" class="ghost" onclick="navigator.clipboard.writeText(document.getElementById('ms-msg').textContent);this.textContent='Copiado'">Copiar mensaje</button></div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="box"><h2>Diagnóstico del servidor</h2>
      <table class="t t--plain"><tbody>
      <?php foreach (server_checks() as [$name, $ok, $help]): ?>
        <tr><td><?= $ok ? '<span class="pill-ok">OK</span>' : '<span class="bad">✕ Falta</span>' ?></td><td><?= e($name) ?></td><td class="muted"><?= e($help) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </section>

    <section class="box"><h2>Últimas notificaciones recibidas</h2>
      <table class="t"><thead><tr><th>Recibida</th><th>Evento</th><th>Pedido Mastershop</th><th>Estado</th></tr></thead><tbody>
      <?php foreach ($events as $ev): ?>
        <tr><td><?= e(date('d/m H:i', strtotime($ev['received_at']))) ?></td><td><?= e($ev['event_name']) ?></td><td><?= $ev['ms_order_id'] ? '#' . (int) $ev['ms_order_id'] : '—' ?></td>
          <td><?= $ev['error'] ? '<span class="bad">' . e(mb_substr($ev['error'], 0, 90)) . '</span>' : ($ev['processed_at'] ? 'Procesada' : 'Pendiente') ?></td></tr>
      <?php endforeach; if (!$events): ?><tr><td colspan="4">Todavía no ha llegado ninguna notificación.</td></tr><?php endif; ?>
      </tbody></table>
    </section>
<?php }

function page_usuarios(PDO $db): void
{
    $me = (string) $_SESSION['admin'];
    $edit = isset($_GET['id']) ? (int) $_GET['id'] : null;
    $users = $db->query('SELECT id, email, name, created_at, last_login_at FROM admins ORDER BY id')->fetchAll();
    if ($edit !== null) {
        $u = ['id' => 0, 'email' => '', 'name' => ''];
        foreach ($users as $x) { if ((int) $x['id'] === $edit) { $u = $x; } }
        $isMe = $u['email'] === $me; ?>
        <h1><?= $u['id'] ? 'Editar usuario' : 'Nuevo usuario' ?></h1>
        <form method="post" class="form box" style="max-width:520px" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="save_user"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <label>Nombre <input name="name" value="<?= e((string) $u['name']) ?>" maxlength="80"></label>
          <label>Correo <input name="email" type="email" value="<?= e($u['email']) ?>" required></label>
          <?php if ($u['id'] && $isMe): ?>
            <label>Contraseña actual <small>(solo si vas a cambiar tu contraseña)</small><input name="current" type="password" autocomplete="current-password"></label>
          <?php endif; ?>
          <label><?= $u['id'] ? 'Nueva contraseña' : 'Contraseña' ?> <small><?= $u['id'] ? '(vacío = no cambia)' : '' ?> mínimo 10 caracteres</small>
            <input name="password" type="password" minlength="10" autocomplete="new-password" <?= $u['id'] ? '' : 'required' ?>></label>
          <label>Repite la contraseña <input name="password2" type="password" minlength="10" autocomplete="new-password" <?= $u['id'] ? '' : 'required' ?>></label>
          <div class="bar" style="justify-content:flex-start"><button class="big">Guardar</button><a href="?p=usuarios">Cancelar</a></div>
        </form>
        <?php if ($u['id'] && !$isMe && count($users) > 1): ?>
          <form method="post" onsubmit="return confirm('¿Eliminar a <?= e($u['email']) ?>? Ya no podrá entrar al panel.')"><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="ghost danger">Eliminar usuario</button></form>
        <?php endif;
        return;
    } ?>
    <h1>Usuarios</h1>
    <p><a class="btnlink" href="?p=usuarios&id=0">Crear usuario</a></p>
    <table class="t"><thead><tr><th>Nombre</th><th>Correo</th><th>Creado</th><th>Último ingreso</th><th></th></tr></thead><tbody>
    <?php foreach ($users as $u): ?>
      <tr><td><?= e($u['name'] ?: '—') ?><?= $u['email'] === $me ? ' <span class="pill-ok">Tú</span>' : '' ?></td><td><?= e($u['email']) ?></td>
        <td><?= e(date('d/m/Y', strtotime($u['created_at']))) ?></td><td><?= $u['last_login_at'] ? e(date('d/m/Y H:i', strtotime($u['last_login_at']))) : 'Nunca' ?></td>
        <td><a href="?p=usuarios&id=<?= (int) $u['id'] ?>"><?= $u['email'] === $me ? 'Editar / cambiar contraseña' : 'Editar' ?></a></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <p class="muted">Todos los usuarios tienen acceso completo al panel.</p>
<?php }

function page_pruebas(PDO $db): void
{
    $demo = (new DemoSeeder($db))->count(); ?>
    <h1>Productos de prueba</h1>
    <section class="box" style="max-width:860px">
      <p>5 productos con fotos y contenido completo para ver cómo luce la tienda antes de cargar los tuyos.
        Los pedidos que hagas con ellos se guardan, pero <strong>nunca se envían a Mastershop</strong>.</p>
      <p class="<?= $demo ? 'warn' : 'muted' ?>"><?= $demo ? "Ahora hay {$demo} productos de prueba publicados. Bórralos antes de lanzar la tienda." : 'No hay productos de prueba cargados.' ?></p>
      <div class="bar" style="justify-content:flex-start">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="demo_seed"><button><?= $demo ? 'Volver a cargar' : 'Cargar productos de prueba' ?></button></form>
        <?php if ($demo): ?><form method="post" onsubmit="return confirm('¿Borrar los productos de prueba?')"><?= csrf_field() ?><input type="hidden" name="action" value="demo_remove"><button class="ghost">Borrar productos de prueba</button></form><?php endif; ?>
      </div>
    </section>
<?php }

/* ---------------- Acciones ---------------- */

function handle_config_post(PDO $db, string $action): void
{
    switch ($action) {
        case 'save_pixels':
            $st = $db->prepare('REPLACE INTO settings (k, v) VALUES (?, ?)');
            $bad = [];
            foreach (PIXEL_FIELDS as $k => [$label, , $re]) {
                $v = trim((string) ($_POST[$k] ?? ''));
                if (preg_match('/content="([^"]+)"/', $v, $m)) { $v = $m[1]; } // pegaron la etiqueta completa
                if ($v !== '' && !preg_match($re, $v)) { $bad[] = $label; continue; }
                $st->execute([$k, $v]);
            }
            flash($bad ? 'No se guardó (formato inválido): ' . implode(', ', $bad) . '. El resto quedó guardado.' : 'Píxeles guardados.', $bad ? 'bad' : 'ok');
            back('?p=pixeles');

        case 'save_capi':
            $st = $db->prepare('REPLACE INTO settings (k, v) VALUES (?, ?)');
            $tok = trim((string) ($_POST['meta_capi_token'] ?? ''));
            if ($tok !== '') {
                if (!preg_match('/^[A-Za-z0-9]{40,}$/', $tok)) { flash('El token no tiene el formato de Meta: cópialo completo, sin espacios.', 'bad'); back('?p=pixeles'); }
                $st->execute(['meta_capi_token', $tok]);
            }
            $st->execute(['meta_test_code', preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['meta_test_code'] ?? ''))]);
            flash(MetaCapi::ready() ? 'API de Conversiones guardada. Usa «Enviar evento de prueba» para verificarla.' : 'Guardado. Falta el ID del píxel o el token para activarla.');
            back('?p=pixeles');
        case 'capi_remove':
            $db->exec("UPDATE settings SET v = '' WHERE k IN ('meta_capi_token', 'meta_capi_last')");
            flash('Token eliminado: la API de Conversiones quedó apagada.');
            back('?p=pixeles');
        case 'capi_test':
            $r = MetaCapi::send('PageView', 'prueba-' . time(), MetaCapi::userData([], []), [], site_url('/'));
            flash($r['ok'] ? 'Meta recibió el evento de prueba.' . (setting('meta_test_code', '') ? ' Míralo en Administrador de eventos → Probar eventos.' : '')
                           : 'Meta rechazó el evento (HTTP ' . $r['status'] . '): ' . mb_substr($r['body'], 0, 220), $r['ok'] ? 'ok' : 'bad');
            back('?p=pixeles');
        case 'ms_test':
            try {
                $r = MastershopClient::unwrap(mastershop()->listProducts(1, 1));
                flash('Conexión correcta con Mastershop.' . (is_array($r) && isset($r['results']) ? ' La API respondió con tu catálogo.' : ''));
            } catch (MastershopException $e) {
                flash(ms_error_text($e), 'bad');
            } catch (Throwable $e) {
                flash('No se pudo conectar: ' . $e->getMessage(), 'bad');
            }
            back('?p=mastershop');

        case 'ms_perms':
            $ms = mastershop();
            $checks = [
                ['Productos de tu negocio', 'GET /api/products', 'Sincronizar tu catálogo', fn() => $ms->listProducts(1, 1)],
                ['Marketplace', 'POST /api/products/market', 'Explorar productos de otros proveedores', fn() => $ms->searchMarket([], 0, 1)],
                ['Pedidos (consulta)', 'POST /api/orders/list', 'Seguimiento de pedidos', fn() => $ms->listOrders(date('Y-m-d', strtotime('-7 days')), [], 1, 1)],
                ['Billetera', 'POST /api/wallets/movements/list', 'Ninguno por ahora (solo informativo)', fn() => $ms->listWalletMovements([], 1, 1)],
            ];
            $rows = [];
            foreach ($checks as [$name, $endpoint, $use, $call]) {
                try {
                    $call();
                    $rows[] = compact('name', 'endpoint', 'use') + ['ok' => true, 'status' => 200, 'msg' => ''];
                } catch (MastershopException $e) {
                    $body = $e->body['error'] ?? $e->body['message'] ?? '';
                    $rows[] = compact('name', 'endpoint', 'use') + ['ok' => false, 'status' => $e->status(), 'msg' => is_string($body) ? $body : json_encode($body)];
                } catch (Throwable $e) {
                    $rows[] = compact('name', 'endpoint', 'use') + ['ok' => false, 'status' => 0, 'msg' => $e->getMessage()];
                }
            }
            $rows[] = ['name' => 'Crear pedidos y webhooks', 'endpoint' => 'POST /api/orders · POST /api/webhooks', 'use' => 'Enviar los pedidos de la tienda y recibir estados',
                       'ok' => true, 'status' => 0, 'msg' => ''];
            $rows[count($rows) - 1]['ok'] = null; // no se prueba: crearía datos reales
            $ip = null;
            if (function_exists('curl_init')) {
                $ch = curl_init('https://api.ipify.org');
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4]);
                $r = curl_exec($ch);
                curl_close($ch);
                $ip = is_string($r) && filter_var(trim($r), FILTER_VALIDATE_IP) ? trim($r) : null;
            }
            $_SESSION['ms_perms'] = ['at' => date('d/m/Y H:i'), 'rows' => $rows, 'ip' => $ip];
            back('?p=mastershop');

        case 'ms_webhooks':
            try {
                $res = (new WebhookRegistrar($db, mastershop()))->register();
                $errs = array_filter($res, fn($r) => str_starts_with($r, 'error'));
                flash($errs ? 'Algunos webhooks fallaron: ' . implode(' · ', array_map(fn($id, $r) => WebhookRegistrar::EVENTS[$id] . " ({$r})", array_keys($errs), $errs)) : 'Webhooks registrados.', $errs ? 'bad' : 'ok');
            } catch (Throwable $e) {
                flash($e->getMessage(), 'bad');
            }
            back('?p=mastershop');

        case 'save_user':
            $id = (int) $_POST['id'];
            $email = filter_var(trim((string) $_POST['email']), FILTER_VALIDATE_EMAIL);
            $name = trim((string) $_POST['name']) ?: null;
            $pass = (string) $_POST['password'];
            $to = '?p=usuarios&id=' . $id;
            if (!$email) { flash('Escribe un correo válido.', 'bad'); back($to); }
            if ($pass !== '' || !$id) {
                if (strlen($pass) < 10) { flash('La contraseña debe tener al menos 10 caracteres.', 'bad'); back($to); }
                if ($pass !== (string) $_POST['password2']) { flash('Las contraseñas no coinciden.', 'bad'); back($to); }
            }
            $st = $db->prepare('SELECT * FROM admins WHERE email = ? AND id <> ?');
            $st->execute([$email, $id]);
            if ($st->fetch()) { flash('Ya existe un usuario con ese correo.', 'bad'); back($to); }

            if (!$id) {
                $db->prepare('INSERT INTO admins (email, name, password_hash) VALUES (?, ?, ?)')->execute([$email, $name, password_hash($pass, PASSWORD_DEFAULT)]);
                flash("Usuario {$email} creado. Compártele la contraseña por un canal seguro.");
                back('?p=usuarios');
            }
            $st = $db->prepare('SELECT * FROM admins WHERE id = ?');
            $st->execute([$id]);
            $u = $st->fetch() ?: back('?p=usuarios');
            $isMe = $u['email'] === $_SESSION['admin'];
            if ($pass !== '' && $isMe && !password_verify((string) ($_POST['current'] ?? ''), $u['password_hash'])) {
                flash('Tu contraseña actual no es correcta.', 'bad');
                back($to);
            }
            $db->prepare('UPDATE admins SET email = ?, name = ? WHERE id = ?')->execute([$email, $name, $id]);
            if ($pass !== '') {
                $db->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            }
            if ($isMe) { $_SESSION['admin'] = $email; }
            flash($pass !== '' ? 'Usuario y contraseña actualizados.' : 'Usuario actualizado.');
            back('?p=usuarios');

        case 'delete_user':
            $id = (int) $_POST['id'];
            $st = $db->prepare('SELECT email FROM admins WHERE id = ?');
            $st->execute([$id]);
            $email = $st->fetchColumn();
            if ($email === $_SESSION['admin']) { flash('No puedes eliminar tu propio usuario.', 'bad'); back('?p=usuarios'); }
            if ((int) $db->query('SELECT COUNT(*) FROM admins')->fetchColumn() <= 1) { flash('No se puede eliminar el último administrador.', 'bad'); back('?p=usuarios'); }
            $db->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
            flash("Usuario {$email} eliminado.");
            back('?p=usuarios');
    }
}


/**
 * Sincronización desde el panel, por tandas de ~15 s para no chocar con el límite
 * de tiempo del hosting. Muestra el avance y continúa sola hasta terminar.
 */
function run_sync_step(PDO $db, bool $start): never
{
    $backTo = (($_POST['back'] ?? $_SESSION['sync']['back'] ?? '') === 'mastershop') ? '?p=mastershop' : '?p=productos';
    if ($start || empty($_SESSION['sync'])) {
        $_SESSION['sync'] = ['page' => 1, 'offset' => 0, 'run_start' => date('Y-m-d H:i:s'), 'back' => $_POST['back'] ?? '',
                             'stats' => ['vistos' => 0, 'nuevos' => 0, 'actualizados' => 0, 'errores' => 0]];
    }
    @set_time_limit(60);
    $limit = (int) ini_get('max_execution_time');
    $budget = $limit > 0 ? max(5, min(15, $limit - 12)) : 15;

    try {
        $sync = new ProductSync($db, mastershop());
        $state = $sync->batch($_SESSION['sync'], $budget);
    } catch (MastershopException $e) {
        unset($_SESSION['sync']);
        flash(ms_error_text($e), 'bad');
        back($backTo);
    } catch (Throwable $e) {
        unset($_SESSION['sync']);
        flash('La sincronización falló: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'bad');
        back($backTo);
    }

    $_SESSION['sync'] = array_merge($_SESSION['sync'], $state);
    $s = $state['stats'];

    if ($state['done']) {
        $off = $sync->finalize($_SESSION['sync']['run_start'], $s);
        unset($_SESSION['sync']);
        $txt = "Sincronización lista: {$s['vistos']} productos revisados, {$s['nuevos']} nuevos, {$s['actualizados']} actualizados";
        $txt .= !empty($s['vinculados']) ? " ({$s['vinculados']} productos de la tienda se vincularon por SKU)" : '';
        $txt .= $s['errores'] ? ", {$s['errores']} con error (último: " . ($s['ultimo_error'] ?? '') . ')' : '';
        $txt .= $off ? ". {$off} ya no están en Mastershop y se ocultaron." : '.';
        flash($txt, $s['errores'] ? 'bad' : 'ok');
        back($backTo);
    }

    // Aún falta: página de progreso que continúa sola
    admin_head('Sincronizando', 'mastershop'); ?>
    <h1>Sincronizando con Mastershop…</h1>
    <section class="box" style="max-width:620px">
      <p><strong><?= num($s['vistos']) ?></strong> productos revisados · <?= num($s['nuevos']) ?> nuevos · <?= num($s['actualizados']) ?> actualizados<?= $s['errores'] ? ' · <span class="bad">' . num($s['errores']) . ' con error</span>' : '' ?></p>
      <div class="fun__bar" style="height:10px"><i class="sync-bar"></i></div>
      <p class="muted">No cierres esta página. Va por tandas para no chocar con el límite de tiempo del servidor.</p>
      <form method="post" id="sync-next"><?= csrf_field() ?><input type="hidden" name="action" value="sync_continue"><button class="ghost">Continuar</button></form>
    </section>
    <style>.sync-bar{width:40%;animation:sb 1.2s ease-in-out infinite alternate}@keyframes sb{from{margin-left:0}to{margin-left:60%}}</style>
    <script>setTimeout(()=>document.getElementById('sync-next').submit(),400)</script>
    <?php admin_foot();
    exit;
}

/** Diagnóstico del servidor para la página de Mastershop. */
function server_checks(): array
{
    $up = dirname(__DIR__) . '/public/uploads';
    return [
        ['PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>='), 'Se necesita PHP 8.1 o superior'],
        ['Extensión curl', extension_loaded('curl'), 'Necesaria para hablar con Mastershop'],
        ['Extensión pdo_mysql', extension_loaded('pdo_mysql'), 'Necesaria para la base de datos'],
        ['Extensión mbstring', extension_loaded('mbstring'), 'Necesaria para textos con tildes'],
        ['Extensión zip', class_exists('ZipArchive'), 'Para subir Excel en la carga masiva'],
        ['Extensión gd con WebP', function_exists('imagewebp'), 'Para optimizar las fotos que subes'],
        ['Tiempo máximo: ' . ((int) ini_get('max_execution_time') ?: 'sin límite') . ' s', true, 'La sincronización se adapta a este límite'],
        ['Carpeta public/uploads con permiso de escritura', is_dir($up) ? is_writable($up) : is_writable(dirname($up)), 'Para guardar fotos y banners'],
    ];
}


/* ---------------- Calculadora de precios ---------------- */

function page_calculadora(PDO $db): void
{
    $ready = Pricing::calculatorReady();
    $rows = $db->query('SELECT id, COALESCE(title, name) nombre, brand, cost, price, price_auto, with_ads, is_active
                        FROM products WHERE cost IS NOT NULL AND cost > 0 ORDER BY is_active DESC, nombre')->fetchAll();
    $ex = $ready ? Pricing::calculate(50000, false) : null;
    $exAds = $ready ? Pricing::calculate(50000, true) : null; ?>
    <h1>Calculadora de precios</h1>
    <form method="post" class="form box" style="max-width:860px"><?= csrf_field() ?><input type="hidden" name="action" value="save_calc">
      <p class="muted">Precio de venta = <strong>costo + flete + publicidad</strong> (solo si el producto lleva) <strong>+ utilidad</strong>, cerrado en …900.
        La utilidad es el mayor entre el % sobre el costo y la utilidad mínima.</p>
      <div class="row">
        <label>Flete por pedido ($) <input name="calc_flete" inputmode="numeric" value="<?= e(setting('calc_flete', '')) ?>" required placeholder="Ej. lo que te cobra Mastershop"></label>
        <label>Publicidad por venta ($) <input name="calc_publicidad" inputmode="numeric" value="<?= e(setting('calc_publicidad', '')) ?>" placeholder="Tu costo por compra en Meta"></label>
        <label>Utilidad (% sobre el costo) <input name="calc_utilidad_pct" inputmode="decimal" value="<?= e(setting('calc_utilidad_pct', '')) ?>"></label>
        <label>Utilidad mínima ($) <input name="calc_utilidad_min" inputmode="numeric" value="<?= e(setting('calc_utilidad_min', '')) ?>"></label>
      </div>
      <?php if ($ex): ?><p class="muted">Ejemplo con un costo de $50.000: <strong><?= money($ex['precio']) ?></strong> sin publicidad · <strong><?= money($exAds['precio']) ?></strong> con publicidad.</p><?php endif; ?>
      <p class="muted">Recuerda: como el envío va incluido, el flete sale de tu precio. Si también hay devoluciones, súmalas al flete promedio o a la utilidad.</p>
      <button class="big">Guardar valores</button>
    </form>

    <?php if (!$ready): ?><p class="flash flash--bad">Configura al menos el flete y la utilidad para ver los precios calculados.</p><?php return; endif; ?>
    <form method="post" class="box"><?= csrf_field() ?><input type="hidden" name="action" value="apply_calc">
      <h2>Productos con costo</h2>
      <p class="muted">Marca «Publicidad» en los productos que vas a pautar. Los que tienen «Aplicar» marcado toman el precio calculado.
        Por defecto vienen marcados los que ya usan la calculadora; los de precio manual quedan sin marcar.</p>
      <table class="t"><thead><tr><th>Producto</th><th>Costo</th><th>Publicidad</th><th>Precio actual</th><th>Precio calculado</th><th>Aplicar</th></tr></thead><tbody>
      <?php foreach ($rows as $r): $c = Pricing::calculate((int) $r['cost'], (bool) $r['with_ads']); $diff = $c['precio'] - (int) $r['price']; ?>
        <tr><td><a href="?p=producto&id=<?= (int) $r['id'] ?>"><?= e($r['nombre']) ?></a><?= $r['brand'] ? ' <small class="muted">· ' . e($r['brand']) . '</small>' : '' ?><?= $r['is_active'] ? '' : ' <small class="muted">(oculto)</small>' ?></td>
          <td><?= money($r['cost']) ?></td>
          <td><input type="checkbox" name="ads[<?= (int) $r['id'] ?>]" value="1" <?= $r['with_ads'] ? 'checked' : '' ?> style="width:auto"></td>
          <td><?= money($r['price']) ?></td>
          <td><strong><?= money($c['precio']) ?></strong> <?= $diff ? '<small class="' . ($diff > 0 ? 'warn' : 'muted') . '">(' . ($diff > 0 ? '+' : '') . money($diff) . ')</small>' : '' ?>
            <br><small class="muted">flete <?= money($c['flete']) ?> · pub. <?= money($c['publicidad']) ?> · utilidad <?= money($c['utilidad']) ?></small></td>
          <td><input type="checkbox" name="apply[<?= (int) $r['id'] ?>]" value="1" <?= $r['price_auto'] ? 'checked' : '' ?> style="width:auto"></td></tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="6">Ningún producto tiene costo todavía.</td></tr><?php endif; ?>
      </tbody></table>
      <button class="big" style="margin-top:1rem">Guardar publicidad y aplicar precios</button>
    </form>
<?php }

function handle_calc_post(PDO $db, string $action): void
{
    if ($action === 'save_calc') {
        $st = $db->prepare('REPLACE INTO settings (k, v) VALUES (?, ?)');
        foreach (['calc_flete', 'calc_publicidad', 'calc_utilidad_min'] as $k) {
            $st->execute([$k, preg_replace('/\D/', '', (string) ($_POST[$k] ?? ''))]);
        }
        $st->execute(['calc_utilidad_pct', preg_replace('/[^\d.,]/', '', (string) ($_POST['calc_utilidad_pct'] ?? ''))]);
        flash('Valores guardados. Revisa la tabla y aplica los precios.');
        back('?p=calculadora');
    }
    if ($action === 'apply_calc') {
        $ads = array_map('intval', array_keys((array) ($_POST['ads'] ?? [])));
        $apply = array_map('intval', array_keys((array) ($_POST['apply'] ?? [])));
        $rows = $db->query('SELECT id, cost FROM products WHERE cost > 0')->fetchAll();
        $upAds = $db->prepare('UPDATE products SET with_ads = ? WHERE id = ?');
        $upPrice = $db->prepare('UPDATE products SET price = ?, price_auto = 1, price_locked = 1 WHERE id = ?');
        $n = 0;
        foreach ($rows as $r) {
            $withAds = in_array((int) $r['id'], $ads, true);
            $upAds->execute([$withAds ? 1 : 0, $r['id']]);
            if (in_array((int) $r['id'], $apply, true) && ($c = Pricing::calculate((int) $r['cost'], $withAds))) {
                $upPrice->execute([$c['precio'], $r['id']]);
                $n++;
            }
        }
        flash("Precios actualizados en {$n} productos.");
        back('?p=calculadora');
    }
}

/* ---------------- Portada: secciones destacadas ---------------- */

function page_portada(PDO $db): void
{
    $catalog = new Catalog($db);
    $slots = new HomeSlots($db, $catalog);
    $cfg = HomeSlots::config();
    $all = $db->query('SELECT id, COALESCE(title, name) t, price, price_before, offer_until, offer_from, images, custom_images, stock FROM products WHERE is_active = 1 ORDER BY t')->fetchAll();
    $js = array_map(fn($p) => ['id' => (int) $p['id'], 't' => $p['t'], 'p' => money($p['price']), 'img' => product_image($p) ?: '',
        'off' => Pricing::offerActive($p), 'until' => (bool) $p['offer_until'], 'stock' => (int) $p['stock']], $all);
    // Disposición del mapa, como se ve la portada
    $layout = [['hero'], ['envio'], ['exclusiva', 'dia'], ['populares'], ['ofertas']];
    ?>
    <h1>Portada</h1>
    <p class="muted">Elige qué productos salen en cada sección destacada de la página de inicio. <strong>Automático</strong>: el sistema escoge con la regla indicada.
      <strong>Manual</strong>: salen los que agregues, en ese orden. <a href="/" target="_blank">Ver la portada</a></p>
    <form method="post" id="portada"><?= csrf_field() ?><input type="hidden" name="action" value="save_portada">
      <div class="map">
      <?php foreach ($layout as $row): ?>
        <div class="map__row map__row--<?= count($row) ?>">
        <?php foreach ($row as $k): [$name, $max, $rule] = HomeSlots::SLOTS[$k]; $manual = $cfg[$k]['mode'] === 'manual';
              // misma lógica que la tienda: la oferta exclusiva nunca repite la oferta del día
              $dia = $slots->products('dia')[0] ?? null;
              $now = $slots->products($k, ($k === 'exclusiva' && $dia) ? [(int) $dia['id']] : []);
              if ($k === 'exclusiva' && $dia) { $now = array_values(array_filter($now, fn($x) => (int) $x['id'] !== (int) $dia['id'])); } ?>
          <section class="slot" data-slot="<?= e($k) ?>" data-max="<?= (int) $max ?>">
            <header><h2><?= e($name) ?></h2><small class="muted"><?= $max === 1 ? '1 producto' : "Hasta {$max} productos" ?></small></header>
            <div class="slot__mode">
              <label><input type="radio" name="mode[<?= e($k) ?>]" value="auto" <?= $manual ? '' : 'checked' ?>> Automático</label>
              <label><input type="radio" name="mode[<?= e($k) ?>]" value="manual" <?= $manual ? 'checked' : '' ?>> Manual</label>
            </div>
            <p class="slot__rule muted">Regla automática: <?= e($rule) ?>.</p>
            <ol class="slot__list"></ol>
            <div class="slot__add"><input list="prods" placeholder="Escribe para buscar y agregar un producto…" autocomplete="off"><button type="button" class="ghost">Agregar</button></div>
            <input type="hidden" name="ids[<?= e($k) ?>]" value="<?= e(implode(',', $cfg[$k]['ids'])) ?>">
            <details class="slot__now"><summary>Ahora se ve: <?= $now ? e(implode(' · ', array_map(fn($p) => product_title($p), array_slice($now, 0, 3)))) . (count($now) > 3 ? ' y ' . (count($now) - 3) . ' más' : '') : 'nada (sección oculta)' ?></summary>
              <?php if (in_array($k, ['hero', 'envio', 'exclusiva', 'dia'], true)): ?><p class="muted">Esta sección solo muestra productos con foto.</p><?php endif; ?>
              <?php if ($k === 'dia'): ?><p class="muted">El contador aparece si el producto tiene «oferta hasta» con fecha.</p><?php endif; ?>
            </details>
          </section>
        <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      </div>
      <datalist id="prods"><?php foreach ($all as $p): ?><option value="<?= e($p['t']) ?> · #<?= (int) $p['id'] ?>"><?php endforeach; ?></datalist>
      <button class="big" style="margin-top:1rem">Guardar portada</button>
    </form>
    <script>
    (() => {
      const P = new Map(<?= json_encode(array_map(fn($p) => [$p['id'], $p], $js), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>);
      document.querySelectorAll('.slot').forEach(slot => {
        const max = +slot.dataset.max, key = slot.dataset.slot;
        const hidden = slot.querySelector('input[type=hidden]'), list = slot.querySelector('.slot__list');
        const input = slot.querySelector('.slot__add input'), add = slot.querySelector('.slot__add button');
        const radios = slot.querySelectorAll('.slot__mode input');
        let ids = hidden.value ? hidden.value.split(',').map(Number).filter(i => P.has(i)) : [];
        const sync = () => {
          hidden.value = ids.join(',');
          const manual = slot.querySelector('.slot__mode input[value=manual]').checked;
          slot.classList.toggle('is-manual', manual);
          list.innerHTML = '';
          ids.forEach((id, i) => {
            const p = P.get(id), li = document.createElement('li');
            const warn = (!p.img && ['hero','envio','exclusiva','dia'].includes(key)) ? '<em class="bad">sin foto: no se mostrará</em>'
              : (key === 'dia' && !p.until) ? '<em class="warn">sin fecha de oferta: sin contador</em>' : (p.stock <= 0 ? '<em class="warn">agotado</em>' : '');
            li.innerHTML = (p.img ? `<img src="${p.img}" alt="">` : '<span class="noimg"></span>') +
              `<span class="slot__t">${p.t}<small>${p.p} ${warn}</small></span>` +
              `<span class="slot__btns"><button type="button" data-a="up" ${i ? '' : 'disabled'} title="Subir">↑</button><button type="button" data-a="down" ${i < ids.length - 1 ? '' : 'disabled'} title="Bajar">↓</button><button type="button" data-a="rm" title="Quitar">×</button></span>`;
            li.querySelectorAll('button').forEach(b => b.onclick = () => {
              if (b.dataset.a === 'rm') ids.splice(i, 1);
              if (b.dataset.a === 'up') [ids[i - 1], ids[i]] = [ids[i], ids[i - 1]];
              if (b.dataset.a === 'down') [ids[i + 1], ids[i]] = [ids[i], ids[i + 1]];
              sync();
            });
            list.appendChild(li);
          });
          slot.querySelector('.slot__add').hidden = ids.length >= max && max > 1;
        };
        const doAdd = () => {
          const m = input.value.match(/#(\d+)\s*$/); if (!m || !P.has(+m[1])) { input.focus(); return; }
          const id = +m[1]; ids = ids.filter(i => i !== id);
          max === 1 ? ids = [id] : (ids.length < max && ids.push(id));
          slot.querySelector('.slot__mode input[value=manual]').checked = true;
          input.value = ''; sync();
        };
        add.onclick = doAdd; input.addEventListener('change', doAdd);
        input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); doAdd(); } });
        radios.forEach(r => r.addEventListener('change', sync));
        sync();
      });
    })();
    </script>
<?php }

function handle_portada_post(PDO $db, string $action): void
{
    if ($action === 'save_portada') {
        $cfg = [];
        foreach (HomeSlots::SLOTS as $k => [, $max]) {
            $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'][$k] ?? '')))))), 0, $max);
            $mode = ($_POST['mode'][$k] ?? 'auto') === 'manual' ? 'manual' : 'auto';
            $cfg[$k] = ['mode' => $mode, 'ids' => $ids];
        }
        HomeSlots::save($db, $cfg);
        flash('Portada guardada.');
        back('?p=portada');
    }
    if ($action === 'toggle_slot') {
        $slot = (string) $_POST['slot'];
        if (isset(HomeSlots::SLOTS[$slot])) {
            $id = (int) $_POST['id'];
            $on = !in_array($slot, HomeSlots::slotsOf($id), true);
            HomeSlots::toggle($db, $slot, $id, $on);
            flash($on ? 'Agregado a «' . HomeSlots::SLOTS[$slot][0] . '».' : 'Quitado de «' . HomeSlots::SLOTS[$slot][0] . '».');
        }
        back((string) ($_POST['back'] ?? '?p=productos'));
    }
}
