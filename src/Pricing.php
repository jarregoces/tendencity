<?php
declare(strict_types=1);

/**
 * Toda la lógica de precios en un solo lugar: la usa la ficha (para mostrar)
 * y el pedido (para cobrar). Así lo que el cliente ve es lo que se cobra.
 */
final class Pricing
{
    /** Precio con descuento, redondeado hacia abajo a un valor "limpio" ($100 en COP, Q1 en GTQ). */
    public static function discounted(int $price, int $pct): int
    {
        $pct = max(0, min(90, $pct));
        if ($pct === 0) {
            return $price;
        }
        $step = (int) Country::get('round_step');
        return (int) (floor($price * (100 - $pct) / 100 / $step) * $step);
    }

    /**
     * Pago anticipado general de la tienda (Ajustes → "Pago anticipado en todos los productos").
     * Si está activo, todo producto lo ofrece; un producto con su propio % lo conserva.
     */
    public static function withStoreDefaults(array $p): array
    {
        if (setting('pia_global', '0') === '1') {
            $p['allow_pia'] = 1;
            if ((int) ($p['pia_discount'] ?? 0) <= 0) {
                $p['pia_discount'] = max(0, min(30, (int) setting('pia_global_discount', '5')));
            }
        }
        return $p;
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
        $out = [1 => ['qty' => 1, 'pct' => 0, 'label' => '', 'pill' => '']];
        foreach ((array) $raw as $o) {
            $q = (int) ($o['qty'] ?? 0);
            if ($q >= 2 && $q <= 10) {
                $out[$q] = ['qty' => $q, 'pct' => max(0, min(50, (int) ($o['pct'] ?? 0))), 'label' => (string) ($o['label'] ?? ''),
                            'pill' => (string) ($o['pill'] ?? '')];
            }
        }
        ksort($out);
        return array_values($out);
    }

    /** "2:10:Más vendido:Ahorra 20% en el segundo | 3:15" → [['qty'=>2,'pct'=>10,'label'=>'Más vendido','pill'=>'Ahorra…'], …] */
    public static function parseOffers(string $s): array
    {
        $out = [];
        foreach (preg_split('/\s*[|\n]\s*/', trim($s)) as $chunk) {
            $parts = array_map('trim', explode(':', $chunk, 4));
            if ((int) ($parts[0] ?? 0) >= 2) { $out[] = ['qty' => (int) $parts[0], 'pct' => (int) ($parts[1] ?? 0), 'label' => $parts[2] ?? '', 'pill' => $parts[3] ?? '']; }
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
     * cerrado en ...900 (COP) o ...9 (GTQ). Utilidad = el mayor entre % sobre el costo y la utilidad mínima.
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
        $stepP = (int) Country::get('price_step');
        $back = (int) Country::get('price_back');
        $precio = (int) (ceil($raw / $stepP) * $stepP - $back);
        if ($precio < $raw) { $precio += $stepP; }
        return ['precio' => $precio, 'flete' => $flete, 'publicidad' => $pub, 'utilidad' => $precio - $cost - $flete - $pub];
    }

    public static function shipping(int $subtotal): int
    {
        $flat = (int) setting('shipping_flat', '0');
        $freeFrom = (int) setting('shipping_free_from', '0');
        return ($freeFrom > 0 && $subtotal >= $freeFrom) ? 0 : $flat;
    }
}
