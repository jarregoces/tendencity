<?php
declare(strict_types=1);

/**
 * Toda la lógica de precios en un solo lugar: la usa la ficha (para mostrar)
 * y el pedido (para cobrar). Así lo que el cliente ve es lo que se cobra.
 */
final class Pricing
{
    /** Precio con descuento, redondeado a $100 hacia abajo (precios "limpios" en COP). */
    public static function discounted(int $price, int $pct): int
    {
        $pct = max(0, min(90, $pct));
        if ($pct === 0) {
            return $price;
        }
        return (int) (floor($price * (100 - $pct) / 100 / 100) * 100);
    }

    /** Oferta activa = hay precio anterior mayor y (sin fecha o fecha futura). */
    public static function offerActive(array $p): bool
    {
        if (empty($p['price_before']) || (int) $p['price_before'] <= (int) $p['price']) {
            return false;
        }
        if (!empty($p['offer_from']) && strtotime($p['offer_from']) > time()) {
            return false; // oferta programada que todavía no empieza
        }
        return empty($p['offer_until']) || strtotime($p['offer_until']) > time();
    }

    public static function offerPct(array $p): int
    {
        return self::offerActive($p)
            ? (int) round((1 - (int) $p['price'] / (int) $p['price_before']) * 100)
            : 0;
    }

    /** Ofertas por cantidad normalizadas; siempre incluye la opción de 1 unidad. */
    public static function qtyOffers(array $p): array
    {
        if (array_key_exists('qty_offers', $p) && $p['qty_offers'] === null) {
            $raw = self::parseOffers((string) setting('def_qty_offers', ''));   // oferta general de la tienda
        } else {
            $raw = is_string($p['qty_offers'] ?? null) ? json_decode($p['qty_offers'], true) : ($p['qty_offers'] ?? []);
        }
        $out = [1 => ['qty' => 1, 'pct' => 0, 'label' => '']];
        foreach ((array) $raw as $o) {
            $q = (int) ($o['qty'] ?? 0);
            if ($q >= 2 && $q <= 10) {
                $out[$q] = ['qty' => $q, 'pct' => max(0, min(50, (int) ($o['pct'] ?? 0))), 'label' => (string) ($o['label'] ?? '')];
            }
        }
        ksort($out);
        return array_values($out);
    }

    /** "2:10:Más vendido | 3:15" → [['qty'=>2,'pct'=>10,'label'=>'Más vendido'], …] */
    public static function parseOffers(string $s): array
    {
        $out = [];
        foreach (preg_split('/\s*[|\n]\s*/', trim($s)) as $chunk) {
            $parts = array_map('trim', explode(':', $chunk, 3));
            if ((int) ($parts[0] ?? 0) >= 2) { $out[] = ['qty' => (int) $parts[0], 'pct' => (int) ($parts[1] ?? 0), 'label' => $parts[2] ?? '']; }
        }
        return $out;
    }

    /** % total para un pedido: oferta por cantidad + descuento por pago anticipado. */
    public static function orderPct(array $p, int $qty, string $method): int
    {
        $pct = 0;
        foreach (self::qtyOffers($p) as $o) {
            if ($o['qty'] === $qty) {
                $pct = $o['pct'];
            }
        }
        if ($method === 'pia' && !empty($p['allow_pia'])) {
            $pct += (int) $p['pia_discount'];
        }
        return min(60, $pct);
    }

    /** ¿Está configurada la calculadora? (flete y utilidad son obligatorios) */
    public static function calculatorReady(): bool
    {
        return setting('calc_flete', '') !== '' && (setting('calc_utilidad_pct', '') !== '' || setting('calc_utilidad_min', '') !== '');
    }

    /**
     * Precio de venta = costo + flete + publicidad (si el producto lleva) + utilidad,
     * cerrado en ...900. Utilidad = el mayor entre % sobre el costo y la utilidad mínima en pesos.
     * @return array{precio:int, flete:int, publicidad:int, utilidad:int}|null
     */
    public static function calculate(int $cost, bool $withAds): ?array
    {
        if (!self::calculatorReady() || $cost <= 0) {
            return null;
        }
        $flete = (int) setting('calc_flete', '0');
        $pub = $withAds ? (int) setting('calc_publicidad', '0') : 0;
        $util = (int) max(round($cost * (float) str_replace(',', '.', (string) setting('calc_utilidad_pct', '0')) / 100), (int) setting('calc_utilidad_min', '0'));
        $raw = $cost + $flete + $pub + $util;
        $precio = (int) (ceil($raw / 1000) * 1000 - 100);
        if ($precio < $raw) { $precio += 1000; }
        return ['precio' => $precio, 'flete' => $flete, 'publicidad' => $pub, 'utilidad' => $precio - $cost - $flete - $pub];
    }

    public static function shipping(int $subtotal): int
    {
        $flat = (int) setting('shipping_flat', '0');
        $freeFrom = (int) setting('shipping_free_from', '0');
        return ($freeFrom > 0 && $subtotal >= $freeFrom) ? 0 : $flat;
    }
}
