<?php
declare(strict_types=1);

/**
 * Secciones destacadas de la portada. Cada una funciona en modo:
 *  - auto:   el sistema escoge con la regla descrita en SLOTS
 *  - manual: los productos y el orden que eligió el administrador
 * La configuración vive en settings.home_slots (JSON).
 */
final class HomeSlots
{
    /** clave => [nombre, máximo de productos, cómo escoge en automático] */
    public const SLOTS = [
        'hero'      => ['Banner principal (Navidad)', 3, 'Productos en oferta y destacados, con foto'],
        'envio'     => ['Banner de envío incluido', 1, 'El primer producto destacado con foto'],
        'exclusiva' => ['Oferta exclusiva (columna izquierda)', 1, 'La oferta con mayor descuento'],
        'dia'       => ['Oferta del día (con contador)', 1, 'La oferta que termina primero'],
        'populares' => ['Productos populares · primera pestaña', 12, 'Los más pedidos de los últimos 30 días'],
        'ofertas'   => ['En oferta ahora', 10, 'Todos los productos con descuento activo'],
    ];

    private array $cfg;

    public function __construct(private PDO $db, private Catalog $catalog)
    {
        $this->cfg = self::config();
    }

    public static function config(): array
    {
        $raw = json_decode((string) setting('home_slots', '{}'), true) ?: [];
        $out = [];
        foreach (self::SLOTS as $k => [, $max]) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($raw[$k]['ids'] ?? [])))));
            $out[$k] = ['mode' => ($raw[$k]['mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto', 'ids' => array_slice($ids, 0, $max)];
        }
        return $out;
    }

    public static function save(PDO $db, array $cfg): void
    {
        $db->prepare("REPLACE INTO settings (k, v) VALUES ('home_slots', ?)")->execute([json_encode($cfg)]);
    }

    public function isManual(string $slot): bool
    {
        return $this->cfg[$slot]['mode'] === 'manual';
    }

    /** Productos de una sección, ya filtrados (publicados) y en orden. */
    public function products(string $slot, array $exclude = []): array
    {
        $max = self::SLOTS[$slot][1];
        if ($this->isManual($slot) && $this->cfg[$slot]['ids']) {
            $list = $this->catalog->productsByIds($this->cfg[$slot]['ids']);
            if ($slot === 'dia' || $slot === 'hero' || $slot === 'envio' || $slot === 'exclusiva') {
                $list = array_values(array_filter($list, fn($p) => product_image($p)));
            }
            return array_slice($list, 0, $max);
        }
        $withImg = fn(array $l) => array_values(array_filter($l, fn($p) => product_image($p) && !in_array((int) $p['id'], $exclude, true)));
        return match ($slot) {
            'hero'      => array_slice($this->unique($withImg(array_merge($this->catalog->onOffer(12), $this->catalog->featured(24)))), 0, $max),
            'envio'     => array_slice($withImg($this->catalog->featured(24)), 0, 1),
            'exclusiva' => array_slice($withImg($this->catalog->onOffer(12)), 0, 1),
            'dia'       => ($d = $this->catalog->dealOfTheDay()) && product_image($d) && !in_array((int) $d['id'], $exclude, true) ? [$d] : [],
            'populares' => array_slice($this->unique(array_merge($this->bestSellers(30, $max), $this->catalog->featured($max))), 0, $max),
            'ofertas'   => array_slice($this->catalog->onOffer($max), 0, $max),
        };
    }

    /** ¿En qué secciones manuales está este producto? */
    public static function slotsOf(int $productId): array
    {
        return array_keys(array_filter(self::config(), fn($s) => $s['mode'] === 'manual' && in_array($productId, $s['ids'], true)));
    }

    /** Pone o quita un producto de una sección (y la pasa a manual). */
    public static function toggle(PDO $db, string $slot, int $productId, bool $on): void
    {
        $cfg = self::config();
        $ids = array_values(array_filter($cfg[$slot]['ids'], fn($i) => $i !== $productId));
        if ($on) {
            $max = self::SLOTS[$slot][1];
            $max === 1 ? $ids = [$productId] : array_push($ids, $productId);
            $ids = array_slice($ids, -$max);
            $cfg[$slot]['mode'] = 'manual';
        }
        $cfg[$slot]['ids'] = $ids;
        self::save($db, $cfg);
    }

    private function bestSellers(int $days, int $limit): array
    {
        $ids = $this->db->query(
            "SELECT i.product_id FROM order_items i JOIN orders o ON o.id = i.order_id
             WHERE o.created_at > NOW() - INTERVAL {$days} DAY AND i.product_id IS NOT NULL
               AND COALESCE(o.ms_status_id, 0) <> 9
             GROUP BY i.product_id ORDER BY SUM(i.quantity) DESC LIMIT " . (int) $limit
        )->fetchAll(PDO::FETCH_COLUMN);
        return $this->catalog->productsByIds($ids);
    }

    private function unique(array $list): array
    {
        $out = [];
        foreach ($list as $p) { $out[$p['id']] ??= $p; }
        return array_values($out);
    }
}
