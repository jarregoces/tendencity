<?php
declare(strict_types=1);

/** Registra en Mastershop las suscripciones de webhook. Lo usan el admin y cli/register_webhooks.php. */
final class WebhookRegistrar
{
    /** 15 creado · 19 por alistar (trae la guía) · 23 tránsito · 25 reparto · 26 oficina · 27 novedad · 18 entregado */
    public const EVENTS = [15 => 'Pedido creado', 19 => 'Pedido por alistar', 23 => 'Pedido en transito',
        25 => 'Pedido en reparto', 26 => 'Pedido en oficina', 27 => 'Pedido con novedad', 18 => 'Pedido entregado'];

    public function __construct(private PDO $db, private MastershopClient $ms) {}

    public static function url(): ?string
    {
        $token = (string) env('WEBHOOK_TOKEN', '');
        $base = env('WEBHOOK_URL') ?: site_url('/webhook.php');
        return strlen($token) >= 32 ? "{$base}?t={$token}" : null;
    }

    /** @return array<int, string> resultado por evento */
    public function register(): array
    {
        $url = self::url() ?? throw new RuntimeException('Configura WEBHOOK_TOKEN (64 caracteres) en el archivo .env.');
        $exists = $this->db->prepare('SELECT 1 FROM webhook_subscriptions WHERE id_webhook = ? AND url = ? AND is_enabled = 1');
        $save = $this->db->prepare('INSERT INTO webhook_subscriptions (id_webhook, id_product_webhook, url, raw) VALUES (?, ?, ?, ?)');
        $out = [];
        foreach (self::EVENTS as $id => $name) {
            $exists->execute([$id, $url]);
            if ($exists->fetch()) {
                $out[$id] = 'ya estaba registrado';
                continue;
            }
            try {
                $resp = MastershopClient::unwrap($this->ms->createWebhook($id, $url, 'Tienda web ' . $name));
                $r = is_array($resp) && array_is_list($resp) ? ($resp[0] ?? []) : (array) $resp;
                $save->execute([$id, $r['idProductWebhook'] ?? $r['idConfigWebhook'] ?? null, $url, json_encode($resp)]);
                $out[$id] = 'registrado';
            } catch (Throwable $e) {
                $out[$id] = 'error: ' . $e->getMessage();
            }
        }
        return $out;
    }
}
