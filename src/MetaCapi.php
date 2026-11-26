<?php
declare(strict_types=1);

/**
 * API de Conversiones de Meta: envía la compra desde el servidor.
 * Complementa al píxel del navegador (que pierde eventos con bloqueadores e iPhone).
 * Se usa el mismo event_id que el píxel (número de pedido) para que Meta no cuente doble.
 */
final class MetaCapi
{
    public static function ready(): bool
    {
        return preg_match('/^\d{10,20}$/', (string) setting('meta_pixel_id', '')) === 1 && strlen((string) setting('meta_capi_token', '')) > 40;
    }

    private static function h(?string $v): ?string
    {
        $v = trim(mb_strtolower((string) $v));
        return $v === '' ? null : hash('sha256', $v);
    }

    /** Datos del cliente en el formato que pide Meta (normalizados y en SHA-256). */
    public static function userData(array $customer, array $address, ?string $visitorId = null): array
    {
        $phone = preg_replace('/\D/', '', (string) ($customer['phone'] ?? ''));
        $phone = Country::intlPhone($phone);
        $norm = fn($s) => preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower((string) $s)) ?: '');
        $email = $customer['email'] ?? '';
        if ($email === fallback_email()) { $email = ''; } // el correo de respaldo no es del cliente
        return array_filter([
            'ph' => $phone ? [hash('sha256', $phone)] : null,
            'em' => $email ? [self::h($email)] : null,
            'fn' => ($v = self::h($norm($customer['first_name'] ?? ''))) ? [$v] : null,
            'ln' => ($v = self::h($norm($customer['last_name'] ?? ''))) ? [$v] : null,
            'ct' => ($v = self::h($norm($address['city'] ?? ''))) ? [$v] : null,
            'st' => ($v = self::h($norm($address['state'] ?? ''))) ? [$v] : null,
            'country' => [hash('sha256', strtolower(Country::code()))],
            'external_id' => $visitorId ? [hash('sha256', $visitorId)] : null,
            'client_ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'fbp' => $_COOKIE['_fbp'] ?? null,
            'fbc' => $_COOKIE['_fbc'] ?? null,
        ]);
    }

    /** @return array{ok:bool, status:int, body:string} */
    public static function send(string $event, string $eventId, array $userData, array $customData, string $sourceUrl, ?string $testCode = null): array
    {
        if (!self::ready()) {
            return ['ok' => false, 'status' => 0, 'body' => 'API de Conversiones no configurada'];
        }
        $payload = ['data' => [[
            'event_name' => $event, 'event_time' => time(), 'event_id' => $eventId, 'action_source' => 'website',
            'event_source_url' => $sourceUrl, 'user_data' => $userData, 'custom_data' => $customData,
        ]]];
        $testCode = $testCode ?? (setting('meta_test_code', '') ?: null);
        if ($testCode) { $payload['test_event_code'] = $testCode; }

        $url = rtrim((string) env('META_GRAPH_URL', 'https://graph.facebook.com'), '/') . '/' . env('META_GRAPH_VERSION', 'v25.0')
             . '/' . setting('meta_pixel_id') . '/events?access_token=' . rawurlencode((string) setting('meta_capi_token'));
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $ok = $status === 200 && str_contains($body, 'events_received');
        if (!$ok) {
            error_log("[meta-capi] {$event} {$eventId}: HTTP {$status} " . mb_substr($body, 0, 300));
        }
        db()->prepare("REPLACE INTO settings (k, v) VALUES ('meta_capi_last', ?)")
            ->execute([json_encode(['at' => date('Y-m-d H:i'), 'event' => $event, 'id' => $eventId, 'ok' => $ok, 'status' => $status, 'body' => mb_substr($body, 0, 300)], JSON_UNESCAPED_UNICODE)]);
        return ['ok' => $ok, 'status' => $status, 'body' => $body];
    }

    /** Purchase de un pedido recién creado. */
    public static function purchase(int $orderId): void
    {
        if (!self::ready()) return;
        $o = db()->query('SELECT * FROM orders WHERE id = ' . $orderId)->fetch();
        if (!$o) return;
        $items = db()->query('SELECT i.quantity, i.unit_price, COALESCE(p.sku, i.ms_product_id, i.product_id) cid
                              FROM order_items i LEFT JOIN products p ON p.id = i.product_id WHERE i.order_id = ' . $orderId)->fetchAll();
        $visitor = preg_match('/^[a-f0-9]{16}$/', (string) ($_COOKIE['td_v'] ?? '')) ? $_COOKIE['td_v'] : null;
        self::send('Purchase', $o['external_id'],
            self::userData(json_decode($o['customer'], true) ?: [], json_decode($o['shipping_address'], true) ?: [], $visitor),
            ['currency' => Country::currency(), 'value' => (int) $o['total'], 'order_id' => $o['external_id'], 'content_type' => 'product',
             'content_ids' => array_map(fn($i) => (string) $i['cid'], $items),
             'contents' => array_map(fn($i) => ['id' => (string) $i['cid'], 'quantity' => (int) $i['quantity'], 'item_price' => (int) $i['unit_price']], $items),
             'num_items' => array_sum(array_column($items, 'quantity'))],
            site_url('/pedido-confirmado/' . strtolower($o['external_id']) . '/'));
    }
}
