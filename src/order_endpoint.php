<?php
declare(strict_types=1);

/**
 * POST /pedido  (JSON o form-data)
 * Recibe el formulario rápido de la ficha. Todo precio se recalcula aquí.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

$fail = function (string $msg, int $code = 422, ?string $field = null): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg, 'field' => $field], JSON_UNESCAPED_UNICODE);
    exit;
};

$in = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
    ? (json_decode(file_get_contents('php://input'), true) ?: [])
    : $_POST;

// Antispam: campo trampa + límite por IP (6 intentos cada 15 min)
if (!empty($in['website'])) {
    echo json_encode(['ok' => true]);
    exit;
}
$ip = inet_pton($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') ?: '';
$st = db()->prepare('SELECT COUNT(*) FROM order_attempts WHERE ip = ? AND created_at > NOW() - INTERVAL 15 MINUTE');
$st->execute([$ip]);
if ((int) $st->fetchColumn() >= 6) {
    $fail('Recibimos varios intentos seguidos. Espera unos minutos o escríbenos por WhatsApp.', 429);
}
db()->prepare('INSERT INTO order_attempts (ip) VALUES (?)')->execute([$ip]);

// Producto y variante
$st = db()->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1');
$st->execute([(int) ($in['product_id'] ?? 0)]);
$p = $st->fetch() ?: $fail('Este producto ya no está disponible.', 404);
$p = Pricing::withStoreDefaults($p);

$variants = (new Catalog(db()))->variants((int) $p['id']);
$variantId = (int) ($in['variant_id'] ?? 0);
if (!$variantId && count($variants) === 1) {
    $variantId = (int) $variants[0]['id'];
}
if (!in_array($variantId, array_map('intval', array_column($variants, 'id')), true)) {
    $fail('Elige una opción del producto.', 422, 'variant_id');
}

// Cantidad: solo las ofertas configuradas
$qty = (int) ($in['qty'] ?? 1);
if (!in_array($qty, array_column(Pricing::qtyOffers($p), 'qty'), true)) {
    $qty = 1;
}

// Método de pago permitido para este producto
$method = ($in['payment_method'] ?? 'cod') === 'pia' ? 'pia' : 'cod';
if (($method === 'cod' && !$p['allow_cod']) || ($method === 'pia' && !$p['allow_pia'])) {
    $method = $p['allow_cod'] ? 'cod' : 'pia';
}

$pct = Pricing::orderPct($p, $qty, $method);
$variantPrice = null;
foreach ($variants as $v) {
    if ((int) $v['id'] === $variantId) {
        $variantPrice = $v['price'];
    }
}
$unit = Pricing::discounted((int) ($variantPrice ?? $p['price']), $pct);
// Oferta de precio fijo (2 por Q289): el % que quede (pago anticipado) se aplica sobre el paquete
$fixedTotal = Pricing::offerTotal($p, $qty);
if ($fixedTotal !== null) {
    $fixedTotal = Pricing::discounted($fixedTotal, $pct);
}
$shipping = Pricing::shipping($fixedTotal ?? $unit * $qty);

// El nombre completo llega en un solo campo: se separa en nombre / apellido
$full = trim(preg_replace('/\s+/', ' ', (string) ($in['full_name'] ?? '')));
$parts = explode(' ', $full);
if (count($parts) < 2) {
    $fail('Escribe tu nombre y apellido.', 422, 'full_name');
}
$first = count($parts) >= 4 ? implode(' ', array_slice($parts, 0, 2)) : $parts[0];
$last  = trim(substr($full, strlen($first)));

try {
    $service = new OrderService(db(), mastershop_or_null());
    $orderId = $service->createLocalOrder(
        [['variant_id' => $variantId, 'qty' => $qty]],
        [
            'first_name'      => $first,
            'last_name'       => $last,
            'phone'           => $in['phone'] ?? '',
            'email'           => $in['email'] ?? '',
            'document_type'   => $in['document_type'] ?? null,
            'document_number' => $in['document_number'] ?? null,
        ],
        [
            'address1' => $in['address1'] ?? '',
            'address2' => $in['address2'] ?? '',
            'city'     => $in['city'] ?? '',
            'state'    => $in['state'] ?? '',
            'district' => $in['district'] ?? '',
        ],
        $method,
        $shipping,
        mb_substr(trim((string) ($in['notes'] ?? '')), 0, 300) ?: null,
        $fixedTotal !== null ? 0 : $pct,
        $fixedTotal,
    );
} catch (InvalidArgumentException | DomainException $e) {
    $fail($e->getMessage());
} catch (Throwable $e) {
    error_log('[pedido] ' . $e->getMessage());
    $fail('No pudimos registrar tu pedido. Intenta de nuevo o escríbenos por WhatsApp.', 500);
}

// Atribución: de qué canal / campaña vino este pedido
try {
    $a = (new Tracker(db()))->attribution();
    db()->prepare('UPDATE orders SET session_id = ?, channel = ?, source = ?, medium = ?, campaign = ?, content = ?, landing_path = ? WHERE id = ?')
        ->execute([$a['session_id'], $a['channel'], $a['source'], $a['medium'], $a['campaign'], $a['content'], $a['landing_path'], $orderId]);
    (new Tracker(db()))->event('order', (int) $p['id']);
} catch (Throwable $e) {
    error_log('[atribucion] ' . $e->getMessage());
}

$ref = db()->query('SELECT external_id, total FROM orders WHERE id = ' . (int) $orderId)->fetch();
echo json_encode([
    'ok'       => true,
    'ref'      => $ref['external_id'],
    'total'    => (int) $ref['total'],
    'redirect' => '/pedido-confirmado/' . strtolower($ref['external_id']) . '/?k=' . order_key($ref['external_id']),
], JSON_UNESCAPED_UNICODE);

// Después de responder (el cliente no espera): API de Conversiones de Meta y aviso de venta al celular
$isTest = (bool) db()->query("SELECT 1 FROM orders WHERE id = " . (int) $orderId . " AND COALESCE(sync_error, '') LIKE 'Pedido de prueba%'")->fetchColumn();
$capi = MetaCapi::ready();
$push = !$isTest && WebPush::hasSubscribers(db());
if ($capi || $push) {
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); } else { @ob_end_flush(); flush(); }
    if ($push) {
        try { WebPush::notifyAll(db()); } catch (Throwable $e) { error_log('[webpush] ' . $e->getMessage()); }
    }
    if ($capi) {
        try { MetaCapi::purchase((int) $orderId); } catch (Throwable $e) { error_log('[meta-capi] ' . $e->getMessage()); }
    }
}
