<?php
declare(strict_types=1);

/**
 * Receptor de webhooks de Mastershop.
 * URL registrada: https://tutienda.com/webhook.php?t=<WEBHOOK_TOKEN>
 *
 * Responde 200 rápido y procesa después, para no depender de un timeout
 * que Mastershop no documenta.
 */
require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json');

$token = (string) env('WEBHOOK_TOKEN', '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || strlen($token) < 32 || !hash_equals($token, (string) ($_GET['t'] ?? ''))) {
    http_response_code(404);
    echo '{}';
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, 1_000_000) ?: '';

try {
    $processor = new WebhookProcessor(db(), mastershop());
    $eventId = $processor->store($raw);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo '{"ok":false}';
    exit;
} catch (Throwable $e) {
    error_log('[mastershop-webhook] ' . $e->getMessage());
    http_response_code(500); // que Mastershop reintente, si es que reintenta
    echo '{"ok":false}';
    exit;
}

http_response_code(200);
echo '{"ok":true}';

// Cerrar la respuesta y seguir procesando en segundo plano.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

if ($eventId !== null) {
    $processor->process($eventId);
}
// Los eventos que fallen quedan con error y los reprocesa cli/retry_orders.php
