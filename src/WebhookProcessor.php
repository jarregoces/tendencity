<?php
declare(strict_types=1);

/**
 * Procesa notificaciones de Mastershop.
 *
 * Mastershop NO firma los webhooks, no garantiza entrega única ni orden.
 * Por eso:
 *  - La URL lleva un token secreto (public/webhook.php lo valida).
 *  - Cada evento se deduplica por hash del contenido relevante.
 *  - Solo se aplica si date_update_order es >= al último aplicado (orden).
 *  - El pedido debe existir en nuestra BD y se confirma contra GET /api/orders.
 */
final class WebhookProcessor
{
    public const STATUS = [
        1 => 'Por confirmar', 2 => 'Pendiente', 3 => 'Por alistar', 4 => 'Por recolectar',
        5 => 'Recolectada', 6 => 'En tránsito', 8 => 'Entregada', 9 => 'Cancelada',
        10 => 'Devuelta', 11 => 'Reclamaciones',
    ];

    public function __construct(private PDO $db, private MastershopClient $ms) {}

    /** Guarda el evento crudo. Devuelve el id del evento o null si es duplicado. */
    public function store(string $rawBody): ?int
    {
        $data = json_decode($rawBody, true);
        if (!is_array($data) || !isset($data['body']) || !is_array($data['body'])) {
            throw new InvalidArgumentException('Payload inválido');
        }
        $b = $data['body'];
        $msOrderId = isset($b['id_order']) && is_numeric($b['id_order']) ? (int) $b['id_order'] : null;

        $dedupe = hash('sha256', json_encode([
            $data['eventName'] ?? null,
            $msOrderId,
            $b['id_status'] ?? null,
            $b['carrier_status_info']['carrier_status'] ?? null,
            $b['carrier_status_info']['id_carrier_status_update'] ?? null,
            $b['carrier_novelty']['noveltycode'] ?? null,
            $b['date_update_order'] ?? null,
        ]));

        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO webhook_events (dedupe_key, event_name, ms_order_id, payload) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$dedupe, mb_substr((string) ($data['eventName'] ?? ''), 0, 120), $msOrderId, $rawBody]);
        return $stmt->rowCount() ? (int) $this->db->lastInsertId() : null;
    }

    public function process(int $eventId): void
    {
        $ev = $this->db->query('SELECT * FROM webhook_events WHERE id = ' . $eventId)->fetch();
        if (!$ev || $ev['processed_at']) {
            return;
        }
        try {
            $this->apply(json_decode($ev['payload'], true));
            $this->db->prepare('UPDATE webhook_events SET processed_at = NOW(), error = NULL WHERE id = ?')
                ->execute([$eventId]);
        } catch (Throwable $e) {
            $this->db->prepare('UPDATE webhook_events SET error = ? WHERE id = ?')
                ->execute([mb_substr($e->getMessage(), 0, 2000), $eventId]);
            error_log("[mastershop-webhook] evento {$eventId}: " . $e->getMessage());
        }
    }

    private function apply(array $data): void
    {
        $b = $data['body'];
        $msOrderId  = isset($b['id_order']) ? (int) $b['id_order'] : 0;
        $externalId = (string) ($b['external_order_id'] ?? '');

        // 1. El pedido tiene que ser nuestro.
        $order = $this->db->prepare('SELECT * FROM orders WHERE ms_order_id = ? OR external_id = ? LIMIT 1');
        $order->execute([$msOrderId, $externalId !== '' ? $externalId : '__none__']);
        $order = $order->fetch();
        if (!$order) {
            throw new DomainException("Pedido {$msOrderId}/{$externalId} no existe en la tienda");
        }

        // 2. Confirmar que existe en Mastershop (protección extra al no haber firma).
        $remote = MastershopClient::unwrap($this->ms->getOrder(['idOrder' => (string) $msOrderId]));
        if (!$remote) {
            throw new DomainException("Mastershop no reconoce el pedido {$msOrderId}");
        }

        // 3. Orden: ignorar eventos más viejos que el último aplicado.
        $eventDate = !empty($b['date_update_order']) ? date('Y-m-d H:i:s', strtotime($b['date_update_order'])) : null;
        if ($eventDate && $order['ms_status_updated'] && $eventDate < $order['ms_status_updated']) {
            return;
        }

        $set = [
            'ms_order_id'       => $msOrderId ?: $order['ms_order_id'],
            'ms_status_id'      => isset($b['id_status']) ? (int) $b['id_status'] : $order['ms_status_id'],
            'ms_status_updated' => $eventDate ?? $order['ms_status_updated'],
        ];

        // Guía generada (evento "Pedido Por Alistar", estado 3).
        if (!empty($b['order_logistics']) && is_array($b['order_logistics'])) {
            $l = $b['order_logistics'];
            $set['carrier_name']  = $l['carrier_name'] ?? $order['carrier_name'];
            $set['tracking_code'] = $l['carrier_tracking_code'] ?? $order['tracking_code'];
            $set['tracking_url']  = $l['url_tracking'] ?? $order['tracking_url'];
            $set['label_url']     = $l['shipping_label'] ?? $order['label_url'];
            $set['shipping_rate'] = isset($l['shipping_rate']) ? (int) round((float) $l['shipping_rate']) : $order['shipping_rate'];
        }

        // Eventos de transportadora: id_status siempre llega 6; lo que distingue es carrier_status.
        if (!empty($b['carrier_status_info']['carrier_status'])) {
            $set['carrier_status'] = mb_substr((string) $b['carrier_status_info']['carrier_status'], 0, 120);
        }
        if (!empty($b['carrier_novelty'])) {
            $set['novelty'] = json_encode($b['carrier_novelty'], JSON_UNESCAPED_UNICODE);
        }

        $cols = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($set)));
        $this->db->prepare("UPDATE orders SET {$cols} WHERE id = ?")
            ->execute([...array_values($set), $order['id']]);

        $this->notifyCustomer($order, $set, $data['eventName'] ?? '');
    }

    /**
     * Punto de enganche para avisarle al cliente (correo, WhatsApp...).
     * Lo mínimo útil: cuando llega la guía (estado 3) y cuando hay novedad.
     */
    private function notifyCustomer(array $order, array $changes, string $eventName): void
    {
        $guiaNueva = !empty($changes['tracking_code']) && $changes['tracking_code'] !== $order['tracking_code'];
        if ($guiaNueva) {
            // TODO: enviar correo/WhatsApp con $changes['carrier_name'] y $changes['tracking_url']
        }
        if (!empty($changes['novelty'])) {
            // TODO: avisarte a ti (admin) para gestionar la novedad con el cliente
        }
    }
}
