<?php
declare(strict_types=1);

/**
 * Panel admin: pedidos, productos (editor AIDA), carga masiva, categorías, reseñas, guías y ajustes.
 * Crear el primer usuario: php cli/create_admin.php correo@dominio.com
 */
require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/helpers.php';
require dirname(__DIR__, 2) . '/src/admin_extra.php';
require dirname(__DIR__, 2) . '/src/admin_config.php';
require dirname(__DIR__, 2) . '/src/admin_product.php';

const ADMIN_MENU = [
    'Métricas y ventas' => [
        'inicio'  => ['Dashboard', 'M3 13h7V3H3zM14 21h7V11h-7zM3 21h7v-5H3zM14 3v5h7V3z'],
        'fuentes' => ['Fuentes de tráfico', 'M4 19V9M10 19V5M16 19v-7M22 19H2'],
        'pedidos' => ['Pedidos', 'M6 2h12l2 6H4zM4 8h16v13H4zM9 12h6'],
        'ventas'  => ['App de ventas (celular)', 'M7 2h10a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zM11 18h2'],
    ],
    'Tienda' => [
        'productos'  => ['Productos', 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8'],
        'importar'   => ['Carga masiva', 'M12 3v12M7 10l5 5 5-5M4 21h16'],
        'categorias' => ['Categorías', 'M3 4h7v7H3zM14 4h7v7h-7zM3 15h7v6H3zM14 15h7v6h-7z'],
        'portada'    => ['Portada', 'M3 10l9-7 9 7v10a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z'],
        'calculadora'=> ['Calculadora de precios', 'M5 3h14v18H5zM8 7h8M8 11h2M12 11h2M16 11h0M8 15h2M12 15h2M8 18h8'],
        'promocion'  => ['Promoción y ofertas', 'M20 12l-8 8-9-9V3h8zM7.5 7.5h.01'],
        'banners'    => ['Banners', 'M3 5h18v14H3zM3 15l5-5 4 4 3-3 6 6'],
        'resenas'    => ['Reseñas', 'M12 3l2.7 5.6 6.3.9-4.5 4.4 1 6.1L12 17l-5.5 3 1-6.1L3 9.5l6.3-.9z'],
        'guias'      => ['Guías', 'M4 4h11a3 3 0 0 1 3 3v13H7a3 3 0 0 1-3-3zM18 20h2V7'],
        'paginas'    => ['Páginas', 'M6 2h9l5 5v15H6zM14 2v6h6M9 13h8M9 17h8'],
    ],
    'Configuración' => [
        'ajustes'    => ['General', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.8 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7 19.4l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 3 13.8H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 10.2 3V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.8 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.8h.1a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.6 1.1z'],
        'pixeles'    => ['Píxeles y analítica', 'M12 20a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM12 12h.01'],
        'mastershop' => ['Mastershop', 'M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1'],
        'usuarios'   => ['Usuarios', 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8'],
        'pruebas'    => ['Productos de prueba', 'M9 3h6M10 3v6L4 19a1 1 0 0 0 .9 1.5h14.2A1 1 0 0 0 20 19l-6-10V3'],
    ],
];

csrf_token(); // inicia sesión

/*
 * En el admin, un error no se queda en "500": se muestra el mensaje real para poder resolverlo.
 * Al público nunca se le muestran detalles técnicos (eso pasa solo dentro de /admin/ con sesión).
 */
function admin_fatal(string $msg, string $where = ''): void
{
    error_log("[admin] {$msg} {$where}");
    while (ob_get_level()) { ob_end_clean(); }
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
    $detail = !empty($_SESSION['admin']) ? '<pre style="white-space:pre-wrap;background:#fbe9e8;color:#7a1712;padding:1rem;border-radius:8px">'
        . htmlspecialchars($msg . ($where ? "\n{$where}" : '')) . '</pre>' : '';
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><body style="font:15px system-ui;max-width:760px;margin:10vh auto;padding:1rem">'
       . '<h1 style="font-size:1.3rem">Algo falló en el panel</h1>' . $detail
       . '<p>Copia este mensaje si necesitas ayuda. <a href="?p=inicio">Volver al panel</a></p></body>';
}
set_exception_handler(function (Throwable $e) {
    admin_fatal(get_class($e) . ': ' . $e->getMessage(), basename($e->getFile()) . ':' . $e->getLine());
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $msg = $err['message'];
        if (str_contains($msg, 'Maximum execution time')) {
            $msg = 'El servidor cortó el proceso por tiempo (' . ini_get('max_execution_time') . ' s). ' . $msg;
        }
        admin_fatal($msg, basename($err['file']) . ':' . $err['line']);
    }
});
ob_start();
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');

const SETTINGS = [
    'whatsapp'            => ['WhatsApp de soporte (con indicativo, sin +; ej. 573182165374 en Colombia o 50255551234 en Guatemala)', 'text'],
    'announcement'        => ['Barra superior', 'text'],
    'shipping_flat'       => ['Costo de envío que cobras (0 = gratis)', 'text'],
    'shipping_free_from'  => ['Envío gratis desde (0 = no aplica)', 'text'],
    'pia_global'          => ['Pago anticipado en TODOS los productos (1 = sí, 0 = solo en los que lo tengan activado)', 'text'],
    'pia_global_discount' => ['% de descuento por pago anticipado para todos (los productos con su propio % lo conservan)', 'text'],
    'pia_instructions'    => ['Instrucciones de pago anticipado', 'textarea'],
    'black_friday_active' => ['Black Friday activo (1 = sí)', 'text'],
    'black_friday_text'   => ['Texto de la landing Black Friday', 'textarea'],
    'def_guarantee'       => ['Garantía por defecto (la usan los productos que no tienen una propia)', 'text'],
    'def_qty_offers'      => ['Oferta por cantidad general para productos nuevos (ej. 2:10:Más vendido | 3:15; vacío = ninguna)', 'text'],
    'def_urgency'         => ['Motivo para decidir hoy, por defecto (opcional)', 'text'],
    'def_faq'             => ['Preguntas frecuentes generales: se agregan a todos los productos (Pregunta :: Respuesta, una por línea)', 'textarea'],
    'ship_prep_days'      => ['Días hábiles para despachar (alistamiento), ej. 0-1', 'text'],
    'ship_transit_days'   => ['Días hábiles de la transportadora hasta la entrega, ej. 2-3', 'text'],
    'ship_cutoff_hour'    => ['Hora de corte (0-23): pedidos después de esta hora cuentan desde el día hábil siguiente', 'text'],
    'ship_saturday'       => ['¿El sábado cuenta como día hábil de entrega? (1 = sí, 0 = no)', 'text'],
    'ship_note'           => ['Nota bajo la fecha estimada', 'textarea'],
    'return_freight_factor' => ['Fletes extra por cada devolución (para la ganancia estimada; 1 = un flete de regreso)', 'text'],
];

$page = preg_replace('/[^a-z_]/', '', (string) ($_GET['p'] ?? 'inicio'));
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$db = db();

/* ---------- Primer administrador (solo existe mientras no haya ninguno) ---------- */
if (empty($_SESSION['admin']) && (int) $db->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0) {
    $error = '';
    if ($isPost && ($_POST['action'] ?? '') === 'setup') {
        csrf_check();
        $email = filter_var(trim((string) $_POST['email']), FILTER_VALIDATE_EMAIL);
        $pass = (string) $_POST['password'];
        if (!$email) {
            $error = 'Escribe un correo válido.';
        } elseif (strlen($pass) < 10) {
            $error = 'La contraseña debe tener al menos 10 caracteres.';
        } elseif ($pass !== (string) $_POST['password2']) {
            $error = 'Las contraseñas no coinciden.';
        } else {
            // INSERT condicionado: si dos personas lo intentan a la vez, solo gana la primera
            $st = $db->prepare('INSERT INTO admins (email, password_hash) SELECT ?, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM admins)');
            $st->execute([$email, password_hash($pass, PASSWORD_DEFAULT)]);
            if ($st->rowCount()) {
                $db->prepare('UPDATE admins SET last_login_at = NOW() WHERE email = ?')->execute([$email]);
                session_regenerate_id(true);
                $_SESSION['admin'] = $email;
                header('Location: ?p=inicio');
                exit;
            }
            header('Location: ?p=login');
            exit;
        }
    }
    admin_head('Crear administrador'); ?>
    <form class="box login" method="post" action="?p=login">
      <img class="login__logo" src="/assets/img/logo.png" alt="Tendencity" width="200" height="41">
      <h1>Crear el administrador</h1>
      <p class="muted">Esta pantalla aparece una sola vez: cuando creas el primer usuario, desaparece.</p>
      <?php if ($error): ?><p class="bad"><?= e($error) ?></p><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="setup">
      <label>Tu correo <input name="email" type="email" required autofocus value="<?= e((string) ($_POST['email'] ?? '')) ?>"></label>
      <label>Contraseña (mínimo 10 caracteres) <input name="password" type="password" required minlength="10" autocomplete="new-password"></label>
      <label>Repite la contraseña <input name="password2" type="password" required minlength="10" autocomplete="new-password"></label>
      <button>Crear y entrar</button>
    </form>
    <?php admin_foot(); exit;
}

/* ---------- Login ---------- */
if ($page === 'salir') { session_destroy(); SalesApp::forget(); header('Location: ?p=login'); exit; }
if ($page === 'ventas') { header('Location: /admin/ventas.php'); exit; }
if (empty($_SESSION['admin'])) {
    $error = '';
    if ($isPost && $page === 'login') {
        csrf_check();
        $st = $db->prepare('SELECT * FROM admins WHERE email = ?');
        $st->execute([trim((string) $_POST['email'])]);
        $a = $st->fetch();
        usleep(300_000);
        if ($a && password_verify((string) $_POST['password'], $a['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = $a['email'];
            $db->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')->execute([$a['id']]);
            $next = $_SESSION['after_login'] ?? '';
            unset($_SESSION['after_login']);
            header('Location: ' . ($next === '/admin/ventas.php' ? $next : '?p=inicio'));   // solo destinos conocidos
            exit;
        }
        $error = 'Correo o contraseña incorrectos.';
    }
    admin_head('Ingresar'); ?>
    <form class="box login" method="post" action="?p=login">
      <img class="login__logo" src="/assets/img/logo.png" alt="Tendencity" width="200" height="41">
      <h1>Ingresar al panel</h1>
      <?php if ($error): ?><p class="bad"><?= e($error) ?></p><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label>Correo <input name="email" type="email" required autofocus></label>
      <label>Contraseña <input name="password" type="password" required></label>
      <button>Ingresar</button>
    </form>
    <?php admin_foot(); exit;
}
// Si eliminaron al usuario mientras tenía la sesión abierta, se le cierra
$st = $db->prepare('SELECT name FROM admins WHERE email = ?');
$st->execute([$_SESSION['admin']]);
$me = $st->fetch();
if (!$me) { session_destroy(); header('Location: ?p=login'); exit; }
$_SESSION['admin_name'] = $me['name'] ?: $_SESSION['admin'];

if ($isPost) { csrf_check(); }

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'] = [$type, $msg]; }
function back(string $to): never { header('Location: ' . $to); exit; }

/* ======================================================================
   Acciones POST
   ====================================================================== */
if ($isPost) {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['save_portada', 'toggle_slot'], true)) {
        handle_portada_post($db, $action);
    }
    if (in_array($action, ['save_calc', 'apply_calc'], true)) {
        handle_calc_post($db, $action);
    }
    if (in_array($action, ['save_pixels', 'save_capi', 'capi_remove', 'capi_test', 'ms_test', 'ms_perms', 'ms_webhooks', 'save_user', 'delete_user'], true)) {
        handle_config_post($db, $action);
    }
    if (in_array($action, ['upload_landing', 'landing_move', 'landing_delete', 'add_testimonial', 'toggle_testimonial', 'delete_testimonial'], true)) {
        handle_extra_post($db, $action);
    }
    if (in_array($action, ['upload_images', 'delete_image', 'cover_image', 'toggle_featured', 'save_badge', 'save_hero', 'save_page'], true)) {
        handle_extra_post($db, $action);
    }
    $orders = new OrderService($db, mastershop_or_null());

    if ($action === 'mark_paid') {
        $ok = $orders->markPaidAndPush((int) $_POST['id'], 'manual', trim((string) $_POST['ref']) ?: 'admin');
        if (!uses_mastershop()) {
            flash('Pago registrado. El pedido quedó en «Por despachar» para crearlo en Dropi.');
        } else {
            flash($ok ? 'Pago registrado y pedido enviado a Mastershop.' : 'Pago registrado, pero Mastershop falló. Revisa el error y reintenta.', $ok ? 'ok' : 'bad');
        }
        back('?p=pedidos');
    }
    if ($action === 'mark_dispatched') {
        $ref = trim((string) ($_POST['ref'] ?? ''));
        if ($ref === '') { flash('Escribe el número del pedido en Dropi.', 'bad'); back('?p=pedidos&f=cola'); }
        $orders->markDispatched((int) $_POST['id'], $ref, trim((string) ($_POST['carrier'] ?? '')) ?: null, trim((string) ($_POST['tracking'] ?? '')) ?: null);
        flash('Pedido marcado como creado en Dropi.');
        back('?p=pedidos&f=cola');
    }
    if ($action === 'retry' && !uses_mastershop()) {
        // Sin Mastershop, "Reintentar" devuelve el pedido a la cola de despacho
        $db->prepare("UPDATE orders SET sync_status = 'pendiente_envio', sync_error = NULL WHERE id = ? AND sync_status = 'error'")->execute([(int) $_POST['id']]);
        flash('El pedido volvió a «Por despachar».');
        back('?p=pedidos&f=cola');
    }
    if ($action === 'retry') {
        $ok = $orders->pushToMastershop((int) $_POST['id']);
        flash($ok ? 'Pedido enviado a Mastershop.' : 'Sigue fallando. Mira el detalle del error.', $ok ? 'ok' : 'bad');
        back('?p=pedidos');
    }
    if ($action === 'toggle_product') {
        $db->prepare('UPDATE products SET is_active = 1 - is_active WHERE id = ? AND (is_active = 1 OR category_id IS NOT NULL)')->execute([(int) $_POST['id']]);
        back('?p=productos&q=' . urlencode((string) ($_POST['q'] ?? '')));
    }
    if ($action === 'create_product') {
        $row = ['titulo' => trim((string) $_POST['titulo']), 'categoria' => (string) $_POST['categoria'], 'precio' => (string) $_POST['precio'],
                'costo' => (string) ($_POST['costo'] ?? ''), 'stock' => (string) ($_POST['stock'] ?? ''), 'sku' => trim((string) ($_POST['sku'] ?? '')),
                'peso' => (string) ($_POST['peso'] ?? ''), 'activo' => 'no', 'contraentrega' => 'si'];
        $rep = (new Importer($db))->importOne($row);
        if ($rep['errors']) { flash('No se creó: ' . implode(' ', $rep['errors']), 'bad'); back('?p=productos&nuevo=1'); }
        $id = (int) $db->query('SELECT MAX(id) FROM products')->fetchColumn();
        flash('Producto creado. Completa el contenido, sube las fotos y márcalo como publicado.');
        back("?p=producto&id={$id}");
    }
    if (in_array($action, ['body_upload', 'body_alt', 'body_move', 'body_delete'], true)) {
        handle_body_post($db, $action);
    }
    if ($action === 'save_product') {
        $section = (string) ($_POST['_section'] ?? 'avanzado');
        $row = product_form_to_row($_POST);
        // Cada paso guarda solo sus campos (guardar uno no borra los demás)
        $basicVenta = array_merge(EDITOR_SECTIONS['basico'], EDITOR_SECTIONS['venta']);
        $keep = $section === 'avanzado' ? array_diff(array_keys($row), $basicVenta) : array_merge(['_id'], EDITOR_SECTIONS[$section] ?? []);
        $row = array_intersect_key($row, array_flip($keep));
        if ($section === 'avanzado') {
            $row['ofertas_cantidad'] = isset($_POST['oferta_general']) ? 'BORRAR' : (trim((string) ($_POST['ofertas_cantidad'] ?? '')) === '' ? 'ninguna' : $row['ofertas_cantidad']);
        }
        if ($section === 'basico' && empty($_POST['price_auto'])) {
            $db->prepare('UPDATE products SET price_auto = 0 WHERE id = ?')->execute([(int) $_POST['id']]);
        }
        if ($section === 'basico' && !empty($_POST['price_auto']) && Pricing::calculatorReady()) {
            $db->prepare('UPDATE products SET price_auto = 1 WHERE id = ?')->execute([(int) $_POST['id']]);
            $row['precio'] = '';           // el importador calcula el precio con el costo
            $row['costo'] = $row['costo'] !== '' ? $row['costo'] : (string) (int) $db->query('SELECT cost FROM products WHERE id = ' . (int) $_POST['id'])->fetchColumn();
        }
        $rep = (new Importer($db))->importOne($row);
        if (!$rep['errors'] && isset($_POST['portada_form'])) {
            $want = array_keys((array) ($_POST['portada'] ?? []));
            $have = HomeSlots::slotsOf((int) $_POST['id']);
            foreach (array_keys(HomeSlots::SLOTS) as $slot) {
                $w = in_array($slot, $want, true);
                if ($w !== in_array($slot, $have, true)) { HomeSlots::toggle($db, $slot, (int) $_POST['id'], $w); }
            }
        }
        if (!$rep['errors'] && $section === 'avanzado') {
            $from = trim((string) ($_POST['oferta_desde'] ?? ''));
            $db->prepare('UPDATE products SET badge = ?, is_featured = ?, offer_from = ? WHERE id = ?')->execute([
                trim((string) ($_POST['etiqueta'] ?? '')) ?: null, isset($_POST['destacado']) ? 1 : 0,
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? "{$from} 00:00:00" : null, (int) $_POST['id']]);
        }
        flash($rep['errors'] ? 'No se guardó: ' . implode(' ', $rep['errors']) : 'Producto guardado.' . ($rep['warnings'] ? ' Ojo: ' . implode(' ', $rep['warnings']) : ''), $rep['errors'] ? 'bad' : 'ok');
        $anchor = ['basico' => '#paso1', 'venta' => '#paso2'][$section] ?? '&av=1#avanzado';
        back('?p=producto&id=' . (int) $_POST['id'] . $anchor);
    }
    if ($action === 'import' && !empty($_FILES['file']['tmp_name'])) {
        $_SESSION['import_report'] = (new Importer($db))->import($_FILES['file']['tmp_name'], $_FILES['file']['name']);
        back('?p=importar');
    }
    if ($action === 'sync' || $action === 'sync_continue') {
        run_sync_step($db, $action === 'sync');
    }
    if ($action === 'save_entity') {
        save_entity($db, (string) $_POST['entity'], $_POST);
    }
    if ($action === 'demo_seed') {
        $n = (new DemoSeeder($db))->seed();
        flash("Se cargaron {$n} productos de prueba. Sus pedidos no se envían a Mastershop.");
        back('?p=pruebas');
    }
    if ($action === 'demo_remove') {
        $n = (new DemoSeeder($db))->remove();
        flash("Se borraron {$n} productos de prueba.");
        back('?p=pruebas');
    }
    if ($action === 'save_settings') {
        $st = $db->prepare('REPLACE INTO settings (k, v) VALUES (?, ?)');
        foreach (SETTINGS as $k => $_) { $st->execute([$k, trim((string) ($_POST[$k] ?? ''))]); }
        flash('Ajustes guardados.');
        back('?p=ajustes');
    }
}

/* ======================================================================
   Vistas
   ====================================================================== */
admin_head(ucfirst($page), $page);
// El dominio de SITE_URL debe ser el mismo donde está la tienda: de él salen el sitemap, las URL canónicas y los enlaces
$cfgHost = preg_replace('/^www\./', '', (string) parse_url((string) env('SITE_URL', ''), PHP_URL_HOST));
$reqHost = preg_replace('/^www\./', '', strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]));
if ($cfgHost && $reqHost && $cfgHost !== $reqHost && !in_array($reqHost, ['localhost', '127.0.0.1'], true)): ?>
  <p class="flash flash--bad"><strong>El dominio no coincide.</strong> En el archivo .env, SITE_URL dice <strong><?= e($cfgHost) ?></strong>, pero la tienda está en <strong><?= e($reqHost) ?></strong>.
    Google recibe el sitemap y las URL canónicas con el dominio equivocado. Cambia en el .env: <code>SITE_URL=https://<?= e($reqHost) ?></code> y <code>WEBHOOK_URL=https://<?= e($reqHost) ?>/webhook.php</code></p>
<?php endif;
if ($flash): ?><p class="flash flash--<?= e($flash[0]) ?>"><?= e($flash[1]) ?></p><?php endif;

switch ($page) {
case 'inicio':
    page_inicio($db);
    break;
case 'pixeles':
    page_pixeles();
    break;
case 'calculadora':
    page_calculadora($db);
    break;
case 'portada':
    page_portada($db);
    break;
case 'mastershop':
    page_mastershop($db);
    break;
case 'usuarios':
    page_usuarios($db);
    break;
case 'pruebas':
    page_pruebas($db);
    break;
case 'fuentes':
    page_fuentes($db);
    break;
case 'promocion':
    page_promocion($db);
    break;
case 'paginas':
    page_paginas($db);
    break;
case 'pedidos':
    $f = $_GET['f'] ?? '';
    $where = match ($f) {
        'error'     => "WHERE sync_status = 'error' AND COALESCE(sync_error, '') NOT LIKE 'Despacho manual%'",
        'manual'    => "WHERE sync_status = 'error' AND sync_error LIKE 'Despacho manual%'",
        'por_pagar' => "WHERE payment_method = 'pia' AND payment_status = 'pendiente'",
        'cola'      => "WHERE sync_status = 'pendiente_envio' AND (payment_method = 'cod' OR payment_status = 'pagado')",
        'novedad'   => 'WHERE novelty IS NOT NULL AND ms_status_id = 6',
        default     => '',
    };
    $total = (int) $db->query("SELECT COUNT(*) FROM orders {$where}")->fetchColumn();
    [$pg, $pages, $off] = admin_page($total, 50);
    $rows = $db->query("SELECT * FROM orders {$where} ORDER BY id DESC LIMIT 50 OFFSET {$off}")->fetchAll(); ?>
    <h1>Pedidos</h1>
    <nav class="tabs">
      <?php $tabs = uses_mastershop()
          ? ['' => 'Todos', 'manual' => 'Despacho manual', 'error' => 'Con error', 'por_pagar' => 'Pago anticipado pendiente', 'novedad' => 'Con novedad']
          : ['' => 'Todos', 'cola' => 'Por despachar (' . ucfirst(fulfillment()) . ')', 'error' => 'Con error', 'por_pagar' => 'Pago anticipado pendiente'];
      foreach ($tabs as $k => $l): ?>
        <a href="?p=pedidos&f=<?= e($k) ?>" class="<?= $f === $k ? 'on' : '' ?>"><?= e($l) ?></a>
      <?php endforeach; ?>
    </nav>
    <table class="t">
      <thead><tr><th>Pedido</th><th>Cliente</th><th>Total</th><th>Origen</th><th>Pago</th><th><?= uses_mastershop() ? 'Mastershop' : e(ucfirst(fulfillment())) ?></th><th>Envío</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $o): $c = json_decode($o['customer'], true); $a = json_decode($o['shipping_address'], true); ?>
        <tr>
          <td><strong><?= e($o['external_id']) ?></strong><br><small><?= e(date('d/m H:i', strtotime($o['created_at']))) ?></small></td>
          <td><?= e($c['full_name'] ?? '') ?><br><small><?= e(($a['city'] ?? '') . ', ' . ($a['state'] ?? '')) ?></small><br>
            <a href="<?= e(wa_customer((string) ($c['phone'] ?? ''))) ?>" target="_blank" rel="noopener"><?= e($c['phone'] ?? '') ?></a></td>
          <td><?= money($o['total']) ?></td>
          <td><?= e(Stats::CHANNELS[$o['channel'] ?? 'directo'] ?? (string) $o['channel']) ?><?php if ($o['source'] || $o['campaign']): ?><br><small><?= e(trim(($o['source'] ?? '') . ' · ' . ($o['campaign'] ?? ''), ' ·')) ?></small><?php endif; ?></td>
          <td><?= $o['payment_method'] === 'cod' ? 'Contraentrega' : 'Anticipado' ?><br><small><?= e($o['payment_status']) ?></small></td>
          <td><?= e($o['sync_status']) ?><?= $o['ms_order_id'] ? '<br><small>#' . (int) $o['ms_order_id'] . '</small>' : '' ?><?= !empty($o['fulfillment_ref']) ? '<br><small>#' . e($o['fulfillment_ref']) . '</small>' : '' ?>
            <?php if ($o['sync_error']): ?><details><summary class="bad">Ver error</summary><small><?= e($o['sync_error']) ?></small></details><?php endif; ?></td>
          <td><?= e(WebhookProcessor::STATUS[(int) $o['ms_status_id']] ?? '—') ?>
            <?php if ($o['carrier_status']): ?><br><small><?= e($o['carrier_status']) ?></small><?php endif; ?>
            <?php if ($o['tracking_url']): ?><br><a href="<?= e($o['tracking_url']) ?>" target="_blank" rel="noopener"><?= e($o['carrier_name'] . ' ' . $o['tracking_code']) ?></a><?php endif; ?></td>
          <td class="actions">
            <?php if ($o['payment_method'] === 'pia' && $o['payment_status'] === 'pendiente'): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                <input name="ref" placeholder="Ref. del pago" size="10"><button>Marcar pagado</button></form>
            <?php elseif (!uses_mastershop() && $o['sync_status'] === 'pendiente_envio'): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mark_dispatched"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                <input name="ref" placeholder="N.º en Dropi" size="9" required><button>Ya está en Dropi</button></form>
            <?php elseif ($o['sync_status'] !== 'enviado'): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><button>Reintentar</button></form>
            <?php endif; ?>
            <?php if ($o['label_url']): ?><a href="<?= e($o['label_url']) ?>" target="_blank" rel="noopener">Guía PDF</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="8">No hay pedidos en esta vista.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php admin_pager($pg, $pages, $total, 'pedidos'); break;

case 'productos':
    $q = trim((string) ($_GET['q'] ?? ''));
    $extraWhere = match ($_GET['f'] ?? '') {
        'sin_stock'   => ' AND p.is_active = 1 AND p.stock <= 0',
        'incompletos' => ' AND (p.aida_hook IS NULL OR p.aida_benefits IS NULL OR p.category_id IS NULL OR p.price <= 0)',
        'ocultos'     => ' AND p.is_active = 0',
        'sin_vincular'=> ' AND p.ms_product_id IS NULL',
        default       => '',
    };
    $wh = "WHERE (? = '' OR p.name LIKE ? OR p.title LIKE ? OR p.sku LIKE ? OR p.ms_product_id = ?){$extraWhere}";
    $args = [$q, "%{$q}%", "%{$q}%", "%{$q}%", (int) $q];
    $cnt = $db->prepare("SELECT COUNT(*) FROM products p {$wh}"); $cnt->execute($args);
    $total = (int) $cnt->fetchColumn();
    [$pg, $pages, $off] = admin_page($total, 50);
    $st = $db->prepare("SELECT p.*, c.name AS cat FROM products p LEFT JOIN categories c ON c.id = p.category_id
        {$wh} ORDER BY p.is_active DESC, p.is_featured DESC, p.updated_at DESC LIMIT 50 OFFSET {$off}");
    $st->execute($args);
    $rows = $st->fetchAll(); ?>
    <h1>Productos</h1>
    <?php if (!empty($_GET['nuevo'])): [$cats] = category_options($db); ?>
    <form method="post" class="form box" style="margin-bottom:1rem"><?= csrf_field() ?><input type="hidden" name="action" value="create_product">
      <h2>Nuevo producto</h2>
      <p class="muted">Se crea en la tienda sin depender de Mastershop. Si usas el mismo SKU que en Mastershop, se vincula solo al sincronizar.</p>
      <label>Nombre <input name="titulo" required maxlength="160"></label>
      <div class="row">
        <label>Categoría <select name="categoria" required><option value="">Elige…</option><?php foreach ($cats as $path => $label): ?><option value="<?= e($path) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>SKU <input name="sku" placeholder="TD-HOG-001" maxlength="120"></label>
      </div>
      <div class="row">
        <label>Precio de venta <input name="precio" inputmode="numeric" required></label>
        <label>Costo <input name="costo" inputmode="numeric"></label>
        <label>Stock <input name="stock" inputmode="numeric" value="0"></label>
        <label>Peso (kg) <input name="peso" inputmode="decimal" placeholder="0.5"></label>
      </div>
      <div class="bar" style="justify-content:flex-start"><button class="big">Crear y completar</button><a href="?p=productos">Cancelar</a></div>
    </form>
    <?php endif; ?>
    <nav class="tabs">
      <?php foreach (['' => 'Todos', 'incompletos' => 'Contenido incompleto', 'sin_stock' => 'Publicados sin stock', 'ocultos' => 'Ocultos', 'sin_vincular' => 'Sin vincular a Mastershop'] as $k => $l): ?>
        <a href="?p=productos&f=<?= e($k) ?>" class="<?= ($_GET['f'] ?? '') === $k ? 'on' : '' ?>"><?= e($l) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="bar">
      <form><input type="hidden" name="p" value="productos"><input name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre o ID"><button>Buscar</button></form>
      <div class="bar" style="margin:0">
        <a class="btnlink" href="?p=productos&nuevo=1">+ Nuevo producto</a>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><button class="ghost">Sincronizar con Mastershop</button></form>
      </div>
    </div>
    <table class="t">
      <thead><tr><th></th><th>Producto</th><th>Categoría</th><th>Precio</th><th>Costo</th><th>Stock</th><th>Estado</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $p): $img = product_image($p); ?>
        <tr>
          <td><?php if ($img): ?><img src="<?= e($img) ?>" width="44" height="44" alt=""><?php endif; ?></td>
          <td><?php $inPop = in_array('populares', HomeSlots::slotsOf((int) $p['id']), true); ?>
            <form method="post" class="star"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_slot"><input type="hidden" name="slot" value="populares"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="back" value="<?= e('?p=productos&f=' . urlencode((string) ($_GET['f'] ?? '')) . '&q=' . urlencode($q)) ?>">
              <button class="star__b<?= $inPop ? ' on' : '' ?>" title="<?= $inPop ? 'Quitar de Destacados en la portada' : 'Destacar en la portada' ?>" <?= $p['is_active'] ? '' : 'disabled' ?>><?= $inPop ? '★' : '☆' ?></button></form>
            <a href="?p=producto&id=<?= (int) $p['id'] ?>"><strong><?= e(product_title($p)) ?></strong></a><br><small><?= $p['ms_product_id'] ? 'MS ' . (int) $p['ms_product_id'] : '<span class="pill-wait">Sin vincular</span>' ?><?= $p['sku'] ? ' · ' . e($p['sku']) : '' ?><?= $p['brand'] ? ' · ' . e($p['brand']) : '' ?><?= $p['badge'] ? ' · ' . e($p['badge']) : '' ?></small></td>
          <td><?= e($p['cat'] ?? '—') ?></td>
          <td><?= money($p['price']) ?></td>
          <td><?= $p['cost'] ? money($p['cost']) . '<br><small>Margen ' . money($p['price'] - $p['cost']) . '</small>' : '—' ?></td>
          <td><?= (int) $p['stock'] ?></td>
          <td><?= product_status_badge($p) ?></td>
          <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_product"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
            <button class="<?= $p['is_active'] ? 'on' : 'ghost' ?>" <?= (!$p['is_active'] && !$p['category_id']) ? 'disabled title="Asigna una categoría primero"' : '' ?>><?= $p['is_active'] ? 'Publicado' : 'Oculto' ?></button></form>
            <?php if ($p['is_active']): ?><a href="/p/<?= e($p['slug']) ?>/" target="_blank">Ver</a><?php endif; ?></td>
        </tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="8">Sin productos en esta vista.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php admin_pager($pg, $pages, $total, 'productos'); break;

case 'producto':
    page_producto($db);
    break;

case 'importar':
    $rep = $_SESSION['import_report'] ?? null; unset($_SESSION['import_report']); ?>
    <h1>Carga masiva</h1>
    <div class="box">
      <ol>
        <li>Pulsa «Sincronizar con Mastershop» en Productos para traer el catálogo.</li>
        <li>Descarga la <a href="/admin/plantilla-basica.xlsx"><strong>plantilla básica</strong></a> (12 columnas, lo necesario para publicar) o la <a href="/admin/plantilla-productos.xlsx">plantilla completa</a> (todas las opciones). Una fila por producto.</li>
        <li>Súbela aquí. Celda vacía = no cambia; escribe BORRAR para vaciar un dato.</li>
      </ol>
      <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="import">
        <input type="file" name="file" accept=".xlsx,.csv" required> <button>Importar</button></form>
    </div>
    <?php if ($rep): ?>
      <div class="box"><h2><?= (int) $rep['ok'] ?> productos actualizados</h2>
        <?php foreach ($rep['errors'] as $er): ?><p class="bad"><?= e($er) ?></p><?php endforeach; ?>
        <?php foreach ($rep['warnings'] as $w): ?><p class="warn"><?= e($w) ?></p><?php endforeach; ?>
      </div>
    <?php endif;
    break;

case 'categorias': case 'resenas': case 'guias': case 'banners':
    entity_page($db, $page);
    break;

case 'ajustes':
    ?><h1>Configuración general</h1><form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="save_settings">
    <?php foreach (SETTINGS as $k => [$label, $type]): ?>
      <label><?= e($label) ?><?php if ($type === 'textarea'): ?><textarea name="<?= e($k) ?>" rows="3"><?= e(setting($k)) ?></textarea><?php else: ?><input name="<?= e($k) ?>" value="<?= e(setting($k)) ?>"><?php endif; ?></label>
    <?php endforeach; ?><button class="big">Guardar</button></form>
    <?php
    break;
}
admin_foot();

/* ======================================================================
   Funciones del admin
   ====================================================================== */

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }

/** Convierte el formulario del editor al formato de la carga masiva (misma validación). */
function product_form_to_row(array $in): array
{
    $join = fn(string $k, string $sep) => implode($sep, lines((string) ($in[$k] ?? '')));
    $row = [
        '_id'           => (string) (int) ($in['id'] ?? 0),
        'ms_product_id' => trim((string) ($in['ms_link'] ?? '')),
        'sku'           => trim((string) ($in['sku'] ?? '')),
        'costo'         => trim((string) ($in['costo'] ?? '')),
        'peso'          => trim((string) ($in['peso'] ?? '')),
        'stock'         => trim((string) ($in['stock'] ?? '')),
        'variantes'     => isset($in['variantes_form'])
            ? (implode(' | ', array_filter(array_map(fn($n, $f) => trim((string) $n) === '' ? '' : trim((string) $n) . (trim((string) $f) !== '' ? ' = ' . trim((string) $f) : ''),
                (array) ($in['variante_nombre'] ?? []), (array) ($in['variante_foto'] ?? [])))) ?: 'ninguna')
            : '',
        'contenido_caja'=> $join('contenido_caja', ' | '),
        'medidas'       => $join('medidas', ' | '),
        'caracteristicas_foto' => $join('caracteristicas_foto', ' || '),
        'formas_uso'    => $join('formas_uso', ' || '),
        'comparacion_fotos' => trim((string) ($in['comparacion_fotos'] ?? '')),
        'comparacion'   => $join('comparacion', ' || '),
        'marca'         => trim((string) ($in['marca'] ?? '')),
        'con_publicidad'=> isset($in['con_publicidad']) ? 'si' : 'no',
        'activo'        => isset($in['activo']) ? 'si' : 'no',
        'enfocado'      => isset($in['enfocado']) ? 'si' : 'no',
        'contraentrega' => isset($in['contraentrega']) ? 'si' : 'no',
        'anticipado'    => isset($in['anticipado']) ? 'si' : 'no',
        'categoria'     => (string) ($in['categoria'] ?? ''),
        'precio'        => (string) ($in['precio'] ?? ''),
        'slug'          => (string) ($in['slug'] ?? ''),
        'descuento_anticipado' => (string) (int) ($in['descuento_anticipado'] ?? 0),
        'interes_beneficios' => $join('interes_beneficios', ' | '),
        'deseo_para_quien'   => $join('deseo_para_quien', ' | '),
        'caracteristicas'    => $join('caracteristicas', ' | '),
        'faq'                => $join('faq', ' || '),
        'ofertas_cantidad'   => $join('ofertas_cantidad', ' | '),
    ];
    foreach (['titulo', 'meta_title', 'meta_description', 'atencion_titular', 'atencion_gancho', 'deseo_texto',
              'accion_garantia', 'accion_urgencia', 'precio_antes', 'oferta_hasta'] as $k) {
        $row[$k] = trim((string) ($in[$k] ?? ''));
    }
    // En el editor, un campo vaciado significa "borrar"
    foreach ($row as $k => $v) {
        if ($v === '' && !in_array($k, ['categoria', 'precio', 'slug', '_id', 'ms_product_id', 'sku', 'stock', 'variantes', 'costo'], true)) {
            $row[$k] = 'BORRAR';
        }
    }
    return $row;
}

/** @return array{0: array<string,string>, 1: array<int,string>} [ruta => etiqueta], [id => ruta] */
/** [página actual, total de páginas, offset] según ?pg= */
function admin_page(int $total, int $per): array
{
    $pages = max(1, (int) ceil($total / $per));
    $pg = min($pages, max(1, (int) ($_GET['pg'] ?? 1)));
    return [$pg, $pages, ($pg - 1) * $per];
}

/** Botones de paginación del admin; conserva filtros y búsqueda. */
function admin_pager(int $pg, int $pages, int $total, string $label): void
{
    if ($pages <= 1) { echo '<p class="muted pager__info">' . $total . ' ' . $label . '</p>'; return; }
    $link = fn(int $n) => '?' . http_build_query(array_merge($_GET, ['pg' => $n]));
    $nums = array_unique(array_filter([1, $pg - 2, $pg - 1, $pg, $pg + 1, $pg + 2, $pages], fn($n) => $n >= 1 && $n <= $pages));
    sort($nums);
    echo '<nav class="pager" aria-label="Páginas">';
    echo $pg > 1 ? '<a href="' . e($link($pg - 1)) . '">‹ Anterior</a>' : '<span class="off">‹ Anterior</span>';
    $prev = 0;
    foreach ($nums as $n) {
        if ($n - $prev > 1) echo '<span class="gap">…</span>';
        echo $n === $pg ? '<b>' . $n . '</b>' : '<a href="' . e($link($n)) . '">' . $n . '</a>';
        $prev = $n;
    }
    echo $pg < $pages ? '<a href="' . e($link($pg + 1)) . '">Siguiente ›</a>' : '<span class="off">Siguiente ›</span>';
    echo '<span class="pager__info">Página ' . $pg . ' de ' . $pages . ' · ' . $total . ' ' . $label . '</span></nav>';
}

function category_options(PDO $db): array
{
    $opts = [];
    $ids = [];
    // Todas las categorías, también las ocultas (marcadas), para poder asignarlas recién creadas
    $rows = $db->query('SELECT id, parent_id, slug, name, is_active FROM categories ORDER BY sort, name')->fetchAll();
    $pillars = array_filter($rows, fn($r) => $r['parent_id'] === null);
    foreach ($pillars as $pl) {
        $opts[$pl['slug']] = $pl['name'] . ($pl['is_active'] ? '' : ' (oculta)');
        $ids[(int) $pl['id']] = $pl['slug'];
        foreach ($rows as $c) {
            if ((int) $c['parent_id'] !== (int) $pl['id']) continue;
            $path = "{$pl['slug']}/{$c['slug']}";
            $opts[$path] = "{$pl['name']} › {$c['name']}" . ($c['is_active'] && $pl['is_active'] ? '' : ' (oculta)');
            $ids[(int) $c['id']] = $path;
        }
    }
    return [$opts, $ids];
}

/** Definición de los CRUD simples. */
function entities(): array
{
    return [
        'categorias' => ['table' => 'categories', 'title' => 'Categorías', 'list' => ['name' => 'Nombre', 'slug' => 'URL', 'is_active' => 'Activa'],
            'fields' => [
                'name' => ['Nombre', 'text', true], 'slug' => ['URL (slug)', 'slug', true], 'parent_id' => ['Pilar (vacío = es un pilar)', 'pillar', false],
                'h1' => ['H1', 'text', false], 'meta_title' => ['Meta title', 'text60', false], 'meta_description' => ['Meta description', 'text155', false],
                'intro' => ['Intro (arriba del listado)', 'textarea', false], 'seo_text' => ['Texto SEO (abajo, 300-500 palabras)', 'markdown', false],
                'faq' => ['Preguntas (Pregunta :: Respuesta por línea)', 'faq', false], 'sort' => ['Orden', 'int', false], 'is_active' => ['Activa', 'bool', false]]],
        'resenas' => ['table' => 'reviews', 'title' => 'Reseñas', 'list' => ['title' => 'Título', 'rating' => 'Nota', 'is_published' => 'Publicada'],
            'fields' => [
                'title' => ['Título', 'text', true], 'slug' => ['URL (slug)', 'slug', true], 'category_id' => ['Pilar', 'pillar', true],
                'product_id' => ['ID local del producto reseñado', 'int', false], 'meta_title' => ['Meta title', 'text60', false],
                'meta_description' => ['Meta description', 'text155', false], 'rating' => ['Calificación 1-5', 'rating', false],
                'verdict' => ['Veredicto', 'textarea', false], 'pros' => ['Pros (uno por línea)', 'textarea', false],
                'cons' => ['Contras reales (uno por línea)', 'textarea', false], 'for_whom' => ['Para quién (uno por línea)', 'textarea', false],
                'body' => ['Texto', 'markdown', false], 'is_published' => ['Publicada', 'bool', false]]],
        'banners' => ['table' => 'banners', 'title' => 'Banners de la portada', 'list' => ['title' => 'Título', 'slot' => 'Ubicación', 'is_active' => 'Activo'],
            'fields' => [
                'slot' => ['Ubicación', 'slot', true], 'eyebrow' => ['Texto pequeño arriba', 'text', false],
                'title' => ['Título', 'text', true], 'highlight' => ['Parte del título en degradado', 'text', false],
                'text' => ['Texto', 'textarea', false], 'button_text' => ['Texto del botón', 'text', false],
                'button_url' => ['Enlace del botón (/ofertas/, /tecnologia/…)', 'text', false],
                'button2_text' => ['Texto del segundo botón (solo mayorista)', 'text', false],
                'button2_url' => ['Enlace del segundo botón (escribe whatsapp para abrir el chat)', 'text', false],
                'image' => ['Imagen (opcional; sin imagen se muestran productos)', 'image', false],
                'until' => ['Contador hasta (vacío = sin contador). Al pasar la fecha el banner se oculta', 'datetime', false],
                'sort' => ['Orden', 'int', false], 'is_active' => ['Activo', 'bool', false]]],
        'guias' => ['table' => 'guides', 'title' => 'Guías', 'list' => ['title' => 'Título', 'is_published' => 'Publicada'],
            'fields' => [
                'title' => ['Título', 'text', true], 'slug' => ['URL (slug)', 'slug', true], 'category_id' => ['Categoría puente', 'category', false],
                'meta_title' => ['Meta title', 'text60', false], 'meta_description' => ['Meta description', 'text155', false],
                'excerpt' => ['Resumen', 'textarea', false], 'body' => ['Texto', 'markdown', false],
                'product_ids' => ['IDs locales de productos recomendados (coma)', 'text', false], 'is_published' => ['Publicada', 'bool', false]]],
    ];
}

function entity_page(PDO $db, string $key): void
{
    $def = entities()[$key];
    $id = isset($_GET['id']) ? (int) $_GET['id'] : null;
    if ($id === null) {
        $rows = $db->query("SELECT * FROM {$def['table']} ORDER BY id DESC")->fetchAll(); ?>
        <h1><?= e($def['title']) ?></h1>
        <p><a class="btnlink" href="?p=<?= e($key) ?>&id=0">Crear nueva</a></p>
        <table class="t"><thead><tr><?php foreach ($def['list'] as $l): ?><th><?= e($l) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr><?php $first = true; foreach ($def['list'] as $col => $_): ?>
          <td><?php if ($first): ?><a href="?p=<?= e($key) ?>&id=<?= (int) $r['id'] ?>"><?= e((string) $r[$col]) ?></a><?php else: ?><?= e(is_null($r[$col]) ? '—' : (in_array($col, ['is_published', 'is_active'], true) ? ($r[$col] ? 'Sí' : 'No (oculta en la tienda)') : (string) $r[$col])) ?><?php endif; $first = false; ?></td>
        <?php endforeach; ?></tr><?php endforeach; ?>
        </tbody></table><?php
        return;
    }
    $row = [];
    if ($id) { $st = $db->prepare("SELECT * FROM {$def['table']} WHERE id = ?"); $st->execute([$id]); $row = $st->fetch() ?: []; }
    $pillars = (new Catalog($db))->tree();
    [$cats, $catIds] = category_options($db); ?>
    <h1><?= $id ? 'Editar' : 'Crear' ?> · <?= e($def['title']) ?></h1>
    <form method="post" class="form" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="save_entity"><input type="hidden" name="entity" value="<?= e($key) ?>"><input type="hidden" name="id" value="<?= (int) $id ?>">
    <?php foreach ($def['fields'] as $f => [$label, $type, $req]): $v = $row[$f] ?? ''; ?>
      <?php if ($type === 'slot'): ?>
        <label><?= e($label) ?><select name="<?= e($f) ?>"><?php foreach (['principal' => 'Banner principal (arriba)', 'mayorista' => 'Banner de envío incluido (al lado de las categorías)', 'intermedio' => 'Banner intermedio (se muestran 3)'] as $sk => $sl): ?><option value="<?= $sk ?>" <?= $v === $sk ? 'selected' : '' ?>><?= $sl ?></option><?php endforeach; ?></select></label>
      <?php elseif ($type === 'image'): ?>
        <label><?= e($label) ?><?php if ($v): ?><img src="<?= e((string) $v) ?>" alt="" width="160" style="border-radius:8px;margin:.3rem 0"><label class="check"><input type="checkbox" name="<?= e($f) ?>_borrar" value="1"> Quitar imagen</label><?php endif; ?>
          <input type="file" name="<?= e($f) ?>" accept="image/jpeg,image/png,image/webp"></label>
      <?php elseif ($type === 'datetime'): ?>
        <label><?= e($label) ?><input type="datetime-local" name="<?= e($f) ?>" value="<?= $v ? e(date('Y-m-d\TH:i', strtotime((string) $v))) : '' ?>"></label>
      <?php elseif ($type === 'bool'): ?>
        <label class="check"><input type="checkbox" name="<?= e($f) ?>" value="1" <?= ($v || (!$id && $f === 'is_active')) ? 'checked' : '' ?>> <?= e($label) ?></label>
      <?php elseif ($type === 'pillar'): ?>
        <label><?= e($label) ?><select name="<?= e($f) ?>" <?= $req ? 'required' : '' ?>><option value="">—</option>
          <?php foreach ($pillars as $pl): if ($key === 'categorias' && $pl['id'] == ($row['id'] ?? 0)) continue; ?><option value="<?= (int) $pl['id'] ?>" <?= $v == $pl['id'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option><?php endforeach; ?></select></label>
      <?php elseif ($type === 'category'): ?>
        <label><?= e($label) ?><select name="<?= e($f) ?>"><option value="">—</option>
          <?php foreach ($catIds as $cid => $path): ?><option value="<?= (int) $cid ?>" <?= $v == $cid ? 'selected' : '' ?>><?= e($cats[$path]) ?></option><?php endforeach; ?></select></label>
      <?php elseif (in_array($type, ['textarea', 'markdown', 'faq'], true)):
            if ($type === 'faq') { $v = implode("\n", array_map(fn($x) => "{$x['q']} :: {$x['a']}", faq_list($v))); } ?>
        <label><?= e($label) ?><textarea name="<?= e($f) ?>" rows="<?= $type === 'markdown' ? 14 : 4 ?>"><?= e((string) $v) ?></textarea></label>
      <?php else: ?>
        <label><?= e($label) ?><input name="<?= e($f) ?>" value="<?= e((string) $v) ?>" <?= $req ? 'required' : '' ?> <?= $type === 'text60' ? 'data-count="60"' : ($type === 'text155' ? 'data-count="155"' : '') ?>></label>
      <?php endif; ?>
    <?php endforeach; ?>
    <button class="big">Guardar</button></form><?php
}

/** Imagen de un CRUD: conserva la actual, la reemplaza si suben otra o la quita si marcan "Quitar". */
function entity_image(PDO $db, string $table, int $id, string $field): ?string
{
    $current = null;
    if ($id) {
        $st = $db->prepare("SELECT {$field} FROM {$table} WHERE id = ?");
        $st->execute([$id]);
        $current = $st->fetchColumn() ?: null;
    }
    if (!empty($_POST["{$field}_borrar"])) {
        return null;
    }
    if (!empty($_FILES[$field]['tmp_name'])) {
        try {
            return ImageUpload::store($_FILES[$field], 0, $table);
        } catch (RuntimeException $e) {
            flash('La imagen no se guardó: ' . $e->getMessage(), 'bad');
        }
    }
    return $current;
}

function save_entity(PDO $db, string $key, array $in): never
{
    $def = entities()[$key] ?? back('?p=pedidos');
    $id = (int) ($in['id'] ?? 0);
    $data = [];
    foreach ($def['fields'] as $f => [$label, $type, $req]) {
        $v = $in[$f] ?? null;
        $data[$f] = match ($type) {
            'bool'   => isset($in[$f]) ? 1 : 0,
            'int', 'pillar', 'category' => ($v === '' || $v === null) ? null : (int) $v,
            'rating' => ($v === '' || $v === null) ? null : max(1, min(5, (float) str_replace(',', '.', $v))),
            'slug'   => slugify((string) $v),
            'slot'   => in_array($v, ['principal', 'mayorista', 'intermedio'], true) ? $v : 'intermedio',
            'datetime' => ($v && strtotime((string) $v)) ? date('Y-m-d H:i:s', strtotime((string) $v)) : null,
            'image'  => entity_image($db, $def['table'], $id, $f),
            'faq'    => json_encode(array_values(array_filter(array_map(function ($l) {
                            [$q, $a] = array_pad(array_map('trim', explode('::', $l, 2)), 2, '');
                            return $q && $a ? ['q' => $q, 'a' => $a] : null;
                        }, lines((string) $v)))), JSON_UNESCAPED_UNICODE),
            default  => trim((string) $v) === '' ? null : trim((string) $v),
        };
        if ($req && ($data[$f] === null || $data[$f] === '')) {
            flash("Falta: {$label}.", 'bad');
            back("?p={$key}&id={$id}");
        }
    }
    if ($key === 'resenas' && $data['is_published'] && !$data['cons']) {
        flash('Una reseña publicada necesita al menos un contra real.', 'bad');
        back("?p={$key}&id={$id}");
    }
    if (in_array($key, ['resenas', 'guias'], true) && $data['is_published']) {
        $data['published_at'] = $id ? null : date('Y-m-d H:i:s'); // se completa abajo si estaba vacío
    }
    try {
        if ($id) {
            unset($data['published_at']);
            $cols = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
            $extra = in_array($key, ['resenas', 'guias'], true) ? ', published_at = COALESCE(published_at, IF(?, NOW(), NULL))' : '';
            $args = [...array_values($data)];
            if ($extra) { $args[] = $data['is_published']; }
            $args[] = $id;
            $db->prepare("UPDATE {$def['table']} SET {$cols}{$extra} WHERE id = ?")->execute($args);
        } else {
            $data = array_filter($data, fn($v) => $v !== null);
            $cols = implode(', ', array_keys($data));
            $db->prepare("INSERT INTO {$def['table']} ({$cols}) VALUES (" . implode(', ', array_fill(0, count($data), '?')) . ')')->execute(array_values($data));
            $id = (int) $db->lastInsertId();
        }
        flash('Guardado.');
    } catch (PDOException $e) {
        flash(str_contains($e->getMessage(), 'Duplicate') ? 'Esa URL ya existe, usa otra.' : 'No se pudo guardar: ' . $e->getMessage(), 'bad');
    }
    back("?p={$key}&id={$id}");
}


function admin_head(string $title, string $page = ''): void
{
    $active = ['producto' => 'productos'][$page] ?? $page;
    $label = $title;
    foreach (ADMIN_MENU as $items) { if (isset($items[$active])) { $label = $items[$active][0]; } }
    $badges = !empty($_SESSION['admin']) ? Stats::alerts(db()) : [];
    $badgeFor = ['pedidos' => ($badges['error'][0] ?? 0) + ($badges['manual'][0] ?? 0) + ($badges['por_pagar'][0] ?? 0) + ($badges['novedad'][0] ?? 0),
                 'productos' => ($badges['sin_stock'][0] ?? 0) + ($badges['sin_aida'][0] ?? 0)];
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($label) ?> · Tendencity admin</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" href="/assets/img/favicon.png"><link rel="stylesheet" href="/admin/admin.css?v=<?= (int) @filemtime(__DIR__ . '/admin.css') ?>"></head>
<body class="<?= empty($_SESSION['admin']) ? 'is-guest' : 'has-side' ?>">
<?php if (!empty($_SESSION['admin'])): ?>
<input type="checkbox" id="side-toggle" class="side-toggle" aria-hidden="true">
<header class="mbar"><label for="side-toggle" class="mbar__btn" aria-label="Abrir menú"><span></span><span></span><span></span></label>
  <img src="/assets/img/logo-white.png" alt="Tendencity" width="120"><span class="mbar__t"><?= e($label) ?></span></header>
<aside class="side" aria-label="Menú del panel">
  <a class="side__logo" href="?p=inicio"><img src="/assets/img/logo-white.png" alt="Tendencity" width="150"></a>
  <nav>
  <?php foreach (ADMIN_MENU as $group => $items): ?>
    <p class="side__group"><?= e($group) ?></p>
    <?php foreach ($items as $k => [$name, $icon]): ?>
      <a href="?p=<?= $k ?>" class="side__a<?= $active === $k ? ' on' : '' ?>"<?= $active === $k ? ' aria-current="page"' : '' ?>>
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="<?= $icon ?>" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span><?= e($name) ?></span><?php if (!empty($badgeFor[$k])): ?><b class="side__badge"><?= (int) $badgeFor[$k] ?></b><?php endif; ?></a>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </nav>
  <div class="side__foot">
    <a href="/" target="_blank" class="side__a"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M14 3h7v7M10 14L21 3M21 14v7H3V3h7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg><span>Ver tienda</span></a>
    <div class="side__me"><span><?= e((string) ($_SESSION['admin_name'] ?? $_SESSION['admin'])) ?></span><a href="?p=salir">Salir</a></div>
    <p class="side__ver">Versión <?= defined('ADMIN_VERSION') ? e(ADMIN_VERSION) : 'anterior: falta subir src/admin_config.php' ?></p>
  </div>
</aside>
<label for="side-toggle" class="side-veil" aria-hidden="true"></label>
<?php endif; ?><main class="am"><?php }

function admin_foot(): void
{ ?></main><script>
document.querySelectorAll('[data-count]').forEach(el=>{const max=+el.dataset.count,s=document.createElement('small');el.after(s);
const u=()=>{s.textContent=el.value.length+' / '+max;s.className=el.value.length>max?'bad':'muted'};el.addEventListener('input',u);u();});
</script></body></html><?php }
