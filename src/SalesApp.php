<?php
declare(strict_types=1);

/**
 * App de ventas para el celular (public/admin/ventas.php).
 * Acceso: la sesión del panel o una cookie firmada de 30 días (para que la app instalada
 * no pida la contraseña cada vez). La cookie solo sirve para esta app, no para el panel.
 */
final class SalesApp
{
    private const COOKIE = 'td_ventas';
    private const DAYS = 30;

    /** Correo del administrador con acceso, o null. */
    public static function auth(PDO $db): ?string
    {
        $email = $_SESSION['admin'] ?? null;
        if (!$email) {
            $email = self::fromCookie();
        }
        if (!$email) {
            return null;
        }
        $st = $db->prepare('SELECT email FROM admins WHERE email = ?');
        $st->execute([$email]);
        return $st->fetchColumn() ?: null;   // un usuario eliminado pierde el acceso al instante
    }

    public static function remember(string $email): void
    {
        $exp = time() + self::DAYS * 86400;
        $payload = base64_encode($email) . '.' . $exp;
        setcookie(self::COOKIE, $payload . '.' . self::sign($payload), [
            'expires' => $exp, 'path' => '/admin/', 'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    public static function forget(): void
    {
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/admin/']);
    }

    private static function fromCookie(): ?string
    {
        $parts = explode('.', (string) ($_COOKIE[self::COOKIE] ?? ''));
        if (count($parts) !== 3) {
            return null;
        }
        [$b64, $exp, $sig] = $parts;
        if ((int) $exp < time() || !hash_equals(self::sign("{$b64}.{$exp}"), $sig)) {
            return null;
        }
        return base64_decode($b64, true) ?: null;
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', 'ventas|' . $payload, (string) env('APP_SECRET', 'cambia-esto'));
    }

    /** Ventas de hoy (hora de la tienda) y pedidos nuevos desde $sinceId. */
    public static function today(PDO $db, int $sinceId = 0): array
    {
        $notTest = "COALESCE(o.sync_error, '') NOT LIKE 'Pedido de prueba%'";
        $orders = $db->query(
            "SELECT o.id, o.external_id, o.created_at, o.total, o.payment_method, o.payment_status, o.customer, o.shipping_address,
                    (SELECT GROUP_CONCAT(CONCAT(i.quantity, ' × ', i.name) SEPARATOR ' · ') FROM order_items i WHERE i.order_id = o.id) AS items,
                    (SELECT SUM(i.quantity) FROM order_items i WHERE i.order_id = o.id) AS units
             FROM orders o
             WHERE o.created_at >= CURDATE() AND {$notTest}
             ORDER BY o.id DESC"
        )->fetchAll();

        // Ayer hasta esta misma hora, para comparar
        $y = $db->query(
            "SELECT COUNT(*) n, COALESCE(SUM(total), 0) t FROM orders o
             WHERE o.created_at >= CURDATE() - INTERVAL 1 DAY AND o.created_at < NOW() - INTERVAL 1 DAY AND {$notTest}"
        )->fetch();

        $list = array_map(function (array $o): array {
            $c = json_decode((string) $o['customer'], true) ?: [];
            $a = json_decode((string) $o['shipping_address'], true) ?: [];
            return [
                'id'     => (int) $o['id'],
                'ref'    => $o['external_id'],
                'time'   => date('g:i a', strtotime($o['created_at'])),
                'total'  => (int) $o['total'],
                'name'   => trim(($c['first_name'] ?? '') . ' ' . mb_substr((string) ($c['last_name'] ?? ''), 0, 1) . '.'),
                'place'  => trim(($a['city'] ?? '') . ', ' . ($a['state'] ?? ''), ', '),
                'items'  => (string) $o['items'],
                'units'  => (int) $o['units'],
                'method' => $o['payment_method'] === 'cod' ? 'Contra entrega' : ($o['payment_status'] === 'pagado' ? 'Anticipado · pagado' : 'Anticipado · por pagar'),
            ];
        }, $orders);

        $total = array_sum(array_column($list, 'total'));
        return [
            'ok'        => true,
            'date'      => date('Y-m-d'),
            'now'       => date('g:i a'),
            'count'     => count($list),
            'total'     => $total,
            'units'     => array_sum(array_column($list, 'units')),
            'avg'       => $list ? (int) round($total / count($list)) : 0,
            'yesterday' => ['count' => (int) $y['n'], 'total' => (int) $y['t']],
            'last_id'   => $list ? $list[0]['id'] : (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM orders')->fetchColumn(),
            'new'       => $sinceId > 0 ? array_values(array_filter($list, fn($o) => $o['id'] > $sinceId)) : [],
            'orders'    => $list,
        ];
    }
}
