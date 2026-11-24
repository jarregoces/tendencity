<?php
declare(strict_types=1);

/**
 * API privada para despachar pedidos fuera de Mastershop (Dropi vía Claude).
 * Autenticación: header "Authorization: Bearer <API_TOKEN>" o "X-Api-Key: <API_TOKEN>" (API_TOKEN en el .env).
 *
 *   GET  /api/ping                                  → prueba de conexión
 *   GET  /api/pedidos                               → cola: pedidos listos para crear en Dropi
 *   GET  /api/pedidos?estado=enviados&desde=AAAA-MM-DD → ya creados en Dropi (para seguir su estado)
 *   GET  /api/pedidos/{ref}                         → un pedido
 *   POST /api/pedidos/{ref}/despachado  {"dropi_order_id":"123","carrier":"…","tracking":"…"}
 *   POST /api/pedidos/{ref}/envio       {"status":"EN TRANSITO","carrier":"…","tracking":"…","tracking_url":"…"}
 *   POST /api/pedidos/{ref}/error       {"error":"Sin cobertura en el municipio"}
 *   POST /api/productos/importar        archivo .xlsx/.csv (campo "archivo") con el formato de la carga masiva
 *   GET  /api/ajustes                   ajustes editables
 *   POST /api/testimonios  {"sku":"4664+28315","items":[{"name":"Ana P.","city":"","rating":5,"text":"…"}]}
 *   POST /api/ajustes   {"whatsapp":"573182165374","pia_global":"0"}  cambia ajustes (solo los permitidos)
 *
 * Productos compuestos: un SKU "4664+28315" son dos productos de Dropi que se envían juntos
 * (items[].dropi_components). El precio se reparte entre ellos en partes iguales.
 *
 * Convención de productos: el SKU del producto en el panel es el ID del producto en Dropi.
 * Si el producto tiene variantes, el SKU de cada variante es "IDPRODUCTO-IDVARIACION" de Dropi.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$out = function (mixed $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$token = (string) env('API_TOKEN', '');
if (strlen($token) < 32) {
    $out(['ok' => false, 'error' => 'API desactivada: falta API_TOKEN (mínimo 32 caracteres) en el .env'], 503);
}
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$sent = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
if (!hash_equals($token, trim($sent))) {
    usleep(300000);
    $out(['ok' => false, 'error' => 'No autorizado'], 401);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$seg = array_values(array_filter(explode('/', trim($path, '/')), 'strlen')); // ['api', 'pedidos', ...]
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$db = db();
$service = new OrderService($db, null);

/** Pedido en el formato que necesita Claude para crearlo en Dropi. */
$present = function (array $o) use ($db): array {
    $c = json_decode($o['customer'], true) ?: [];
    $a = json_decode($o['shipping_address'], true) ?: [];
    $st = $db->prepare('SELECT i.name, i.sku, i.quantity, i.unit_price, i.product_id, i.variant_id,
                               p.sku AS product_sku, v.sku AS variant_sku
                        FROM order_items i
                        LEFT JOIN products p ON p.id = i.product_id
                        LEFT JOIN product_variants v ON v.id = i.variant_id
                        WHERE i.order_id = ? ORDER BY i.id');
    $st->execute([$o['id']]);
    $items = array_map(function (array $i): array {
        // Variante "123-456" → producto 123, variación 456; si no, el SKU del producto es el ID en Dropi
        $vsku = (string) ($i['variant_sku'] ?? '');
        $psku = (string) ($i['product_sku'] ?? $i['sku'] ?? '');
        $dropiProduct = null; $dropiVariation = null;
        $components = null;
        if (preg_match('/^(\d+)-(\d+)$/', $vsku, $m)) { $dropiProduct = (int) $m[1]; $dropiVariation = (int) $m[2]; }
        elseif (ctype_digit($psku)) { $dropiProduct = (int) $psku; }
        elseif (preg_match('/^\d+(?:-\d+)?(?:\+\d+(?:-\d+)?)+$/', $psku)) {
            // Varios productos de Dropi por cada unidad vendida: el precio se reparte (el resto va al primero)
            $parts = explode('+', $psku);
            $n = count($parts);
            $unit = (int) $i['unit_price'];
            $components = [];
            foreach ($parts as $k => $part) {
                [$pid, $var] = array_pad(explode('-', $part), 2, null);
                $components[] = ['dropi_product_id' => (int) $pid, 'dropi_variation_id' => $var !== null ? (int) $var : null,
                                 'unit_price' => intdiv($unit, $n) + ($k === 0 ? $unit % $n : 0)];
            }
        }
        return [
            'name'               => $i['name'],
            'quantity'           => (int) $i['quantity'],
            'unit_price'         => (int) $i['unit_price'],
            'sku'                => $vsku ?: $psku,
            'dropi_product_id'   => $dropiProduct,
            'dropi_variation_id' => $dropiVariation,
            'dropi_components'   => $components,
        ];
    }, $st->fetchAll());

    return [
        'ref'             => $o['external_id'],
        'created_at'      => $o['created_at'],
        'country'         => Country::code(),
        'currency'        => $o['currency'],
        'payment_method'  => $o['payment_method'],          // cod = contra entrega, pia = anticipado
        'payment_status'  => $o['payment_status'],
        'rate_type'       => $o['payment_method'] === 'cod' ? 'CON RECAUDO' : 'SIN RECAUDO',
        'collect_amount'  => $o['payment_method'] === 'cod' ? (int) $o['total'] : 0,  // lo que cobra el mensajero
        'subtotal'        => (int) $o['subtotal'],
        'shipping_charge' => (int) $o['shipping_charge'],
        'total'           => (int) $o['total'],
        'customer'        => [
            'first_name' => $c['first_name'] ?? '',
            'last_name'  => $c['last_name'] ?? '',
            'phone'      => $c['phone'] ?? '',
            'email'      => ($c['email'] ?? null) === env('FALLBACK_EMAIL') ? null : ($c['email'] ?? null), // el de respaldo no es del cliente
            'document'   => $c['documentNumber'] ?? null,
        ],
        'address' => [
            'state'    => $a['state'] ?? '',
            'city'     => $a['city'] ?? '',
            'address1' => $a['address1'] ?? '',
            'address2' => $a['address2'] ?? null,
            'district' => $a['district'] ?? null,
        ],
        'notes'           => $o['notes'],
        'items'           => $items,
        'sync_status'     => $o['sync_status'],
        'confirm_status'  => $o['confirm_status'] ?? 'confirmado',   // por_confirmar | confirmado | cancelado
        'dropi_order_id'  => $o['fulfillment_ref'] ?? null,
        'carrier'         => $o['carrier_name'],
        'carrier_status'  => $o['carrier_status'],
        'tracking'        => $o['tracking_code'],
        'error'           => $o['sync_error'],
    ];
};

$find = function (string $ref) use ($db): ?array {
    $st = $db->prepare('SELECT * FROM orders WHERE external_id = ?');
    $st->execute([strtoupper($ref)]);
    return $st->fetch() ?: null;
};

if (($seg[1] ?? '') === 'ping') {
    $out(['ok' => true, 'store' => site_url('/'), 'country' => Country::code(), 'fulfillment' => fulfillment(), 'time' => date('c')]);
}

// POST /api/productos/importar — misma carga masiva del panel, para subir catálogos desde Claude
if (($seg[1] ?? '') === 'productos' && ($seg[2] ?? '') === 'importar' && $method === 'POST') {
    $f = $_FILES['archivo'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $out(['ok' => false, 'error' => 'Envía el archivo en el campo "archivo" (multipart/form-data)'], 422);
    }
    $name = (string) ($f['name'] ?? 'productos.xlsx');
    if (!preg_match('/\.(xlsx|csv)$/i', $name)) {
        $out(['ok' => false, 'error' => 'Solo .xlsx o .csv'], 422);
    }
    $rep = (new Importer($db))->import($f['tmp_name'], $name);
    $out(['ok' => !$rep['errors'], 'imported' => $rep['ok'], 'errors' => $rep['errors'], 'warnings' => $rep['warnings']]);
}

// Ajustes de la tienda editables desde la API (lista cerrada: nada de claves ni datos sensibles)
const API_SETTINGS = ['whatsapp', 'announcement', 'shipping_flat', 'shipping_free_from', 'pia_global', 'pia_global_discount',
                      'pia_instructions', 'confirm_sender', 'def_guarantee', 'def_qty_offers', 'def_urgency', 'def_faq', 'ship_prep_days',
                      'ship_transit_days', 'ship_cutoff_hour', 'ship_saturday', 'ship_note'];
if (($seg[1] ?? '') === 'ajustes') {
    if ($method === 'POST') {
        $st = $db->prepare('REPLACE INTO settings (k, v) VALUES (?, ?)');
        $changed = [];
        foreach ($body as $k => $v) {
            if (in_array($k, API_SETTINGS, true) && is_scalar($v)) {
                $st->execute([$k, mb_substr(trim((string) $v), 0, 5000)]);
                $changed[] = $k;
            }
        }
        $ignored = array_values(array_diff(array_keys($body), $changed));
        $out(['ok' => (bool) $changed, 'changed' => $changed, 'ignored' => $ignored]);
    }
    $in = implode(',', array_fill(0, count(API_SETTINGS), '?'));
    $st = $db->prepare("SELECT k, v FROM settings WHERE k IN ($in)");
    $st->execute(API_SETTINGS);
    $out(['ok' => true, 'settings' => $st->fetchAll(PDO::FETCH_KEY_PAIR)]);
}

// GET /api/avisos → diagnóstico de notificaciones push | POST /api/avisos → enviar aviso de prueba
if (($seg[1] ?? '') === 'avisos') {
    $subs = [];
    try {
        $subs = $db->query('SELECT id, admin_email, user_agent, fails, last_ok, created_at, endpoint FROM push_subscriptions ORDER BY id')->fetchAll();
    } catch (Throwable $e) {
        $out(['ok' => false, 'error' => 'Tabla de avisos no creada todavía: ' . $e->getMessage()], 500);
    }
    $list = array_map(fn($s) => [
        'id' => (int) $s['id'], 'usuario' => $s['admin_email'], 'servicio' => parse_url($s['endpoint'], PHP_URL_HOST),
        'celular' => mb_substr((string) $s['user_agent'], 0, 140), 'fallos' => (int) $s['fails'],
        'ultimo_ok' => $s['last_ok'], 'creado' => $s['created_at'],
    ], $subs);
    if ($method === 'POST') {
        $res = WebPush::notifyAll($db);
        $codes = [];
        foreach ($subs as $s) { $codes[parse_url($s['endpoint'], PHP_URL_HOST) . '#' . $s['id']] = null; }
        $out(['ok' => true, 'enviados' => $res, 'suscripciones' => $list]);
    }
    $vapid = null;
    try { $vapid = strlen(WebPush::publicKey($db)) > 40; } catch (Throwable $e) { $vapid = 'ERROR: ' . $e->getMessage(); }
    $out(['ok' => true, 'claves_vapid' => $vapid, 'curl' => function_exists('curl_init'), 'suscripciones' => $list]);
}

// POST /api/testimonios — testimonios reales de clientes para un producto (no duplica)
if (($seg[1] ?? '') === 'testimonios' && $method === 'POST') {
    $st = $db->prepare('SELECT id FROM products WHERE sku = ? OR slug = ? LIMIT 1');
    $st->execute([(string) ($body['sku'] ?? ''), (string) ($body['slug'] ?? '')]);
    $pid = (int) $st->fetchColumn();
    if (!$pid) {
        $out(['ok' => false, 'error' => 'Producto no encontrado (envía sku o slug)'], 404);
    }
    $dup = $db->prepare('SELECT 1 FROM testimonials WHERE product_id = ? AND name = ? AND text = ?');
    $ins = $db->prepare('INSERT INTO testimonials (product_id, name, city, rating, text, sort) VALUES (?, ?, ?, ?, ?, ?)');
    $added = 0; $skipped = 0;
    foreach ((array) ($body['items'] ?? []) as $k => $t) {
        $name = mb_substr(trim((string) ($t['name'] ?? '')), 0, 80);
        $text = mb_substr(trim((string) ($t['text'] ?? '')), 0, 600);
        if ($name === '' || $text === '') { $skipped++; continue; }
        $dup->execute([$pid, $name, $text]);
        if ($dup->fetchColumn()) { $skipped++; continue; }
        $ins->execute([$pid, $name, trim((string) ($t['city'] ?? '')) ?: null, max(1, min(5, (int) ($t['rating'] ?? 5))), $text, (int) $k]);
        $added++;
    }
    $out(['ok' => true, 'product_id' => $pid, 'added' => $added, 'skipped' => $skipped]);
}

if (($seg[1] ?? '') !== 'pedidos') {
    $out(['ok' => false, 'error' => 'Ruta no encontrada'], 404);
}

// GET /api/pedidos
if ($method === 'GET' && count($seg) === 2) {
    $limit = (int) ($_GET['limit'] ?? 50);
    if (($_GET['estado'] ?? '') === 'enviados') {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['desde'] ?? '')) ? $_GET['desde'] : date('Y-m-d', strtotime('-30 days'));
        $st = $db->prepare("SELECT * FROM orders WHERE sync_status = 'enviado' AND fulfillment_ref IS NOT NULL AND created_at >= ?
                            ORDER BY id DESC LIMIT " . max(1, min(200, $limit)));
        $st->execute([$from]);
        $rows = $st->fetchAll();
    } else {
        $rows = $service->dispatchQueue($limit);
    }
    $out(['ok' => true, 'count' => count($rows), 'orders' => array_map($present, $rows)]);
}

$order = isset($seg[2]) ? $find($seg[2]) : null;
if (!$order) {
    $out(['ok' => false, 'error' => 'Pedido no encontrado'], 404);
}

// GET /api/pedidos/{ref}
if ($method === 'GET' && count($seg) === 3) {
    $out(['ok' => true, 'order' => $present($order)]);
}

if ($method !== 'POST' || count($seg) !== 4) {
    $out(['ok' => false, 'error' => 'Método o ruta no válidos'], 405);
}

$str = fn(string $k, int $max = 120) => ($v = trim((string) ($body[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);

switch ($seg[3]) {
    case 'despachado':
        $ref = $str('dropi_order_id', 60) ?? $out(['ok' => false, 'error' => 'Falta dropi_order_id'], 422);
        if ($order['sync_status'] === 'enviado') {
            $out(['ok' => true, 'already' => true, 'order' => $present($order)]);
        }
        if ($order['payment_method'] === 'pia' && $order['payment_status'] !== 'pagado') {
            $out(['ok' => false, 'error' => 'Pago anticipado sin confirmar: no se despacha'], 409);
        }
        if (($order['confirm_status'] ?? 'confirmado') !== 'confirmado') {
            $out(['ok' => false, 'error' => 'El cliente todavía no confirmó el pedido (o se canceló): no se despacha'], 409);
        }
        $service->markDispatched((int) $order['id'], $ref, $str('carrier', 80), $str('tracking', 60));
        break;
    case 'envio':
        $url = $str('tracking_url', 500);
        if ($url !== null && !preg_match('#^https://#', $url)) { $url = null; }
        $service->updateShipment((int) $order['id'], $str('status'), $str('carrier', 80), $str('tracking', 60), $url);
        break;
    case 'error':
        $service->markDispatchError((int) $order['id'], $str('error', 2000) ?? 'Error sin detalle');
        break;
    default:
        $out(['ok' => false, 'error' => 'Acción no válida'], 404);
}

$out(['ok' => true, 'order' => $present($find((string) $order['external_id']))]);
