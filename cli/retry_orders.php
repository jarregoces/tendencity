<?php
declare(strict_types=1);
/**
 * Reintenta pedidos que no llegaron a Mastershop y webhooks que fallaron.
 * Cron sugerido: cada 5 min  (ver README para la línea de crontab)
 */
if (PHP_SAPI !== 'cli') { exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
if (!uses_mastershop()) { echo "Esta tienda no despacha con Mastershop (FULFILLMENT=" . fulfillment() . "): nada que reintentar.\n"; exit(0); }

$lock = fopen(sys_get_temp_dir() . '/ms_retry.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

$orders = new OrderService(db(), mastershop());
$ids = db()->query(
    "SELECT id FROM orders
     WHERE sync_status IN ('pendiente_envio','error') AND sync_attempts < 10
       AND (payment_method = 'cod' OR payment_status = 'pagado')
     ORDER BY id LIMIT 50"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($ids as $id) {
    echo "Pedido {$id}: ", $orders->pushToMastershop((int) $id) ? "enviado" : "falló", "\n";
}

$hooks = new WebhookProcessor(db(), mastershop());
$events = db()->query(
    "SELECT id FROM webhook_events WHERE processed_at IS NULL
       AND received_at > NOW() - INTERVAL 3 DAY ORDER BY id LIMIT 100"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($events as $id) {
    $hooks->process((int) $id);
}
