<?php
declare(strict_types=1);

/**
 * Sincroniza GET /api/products (catálogo del negocio) a la BD local.
 *
 * Mapeo confirmado con la respuesta real de GET /api/products:
 *   idProduct, name, description, basePrice (costo proveedor), suggestedPrice (venta sugerida),
 *   stockTotal, urlImageProduct, prodFormatName (categoría), nameState ("Activo"), variation[].
 * Pendiente de confirmar: el id de la variante por defecto (variation llega vacío en productos
 * sin variantes). Corre `php cli/sync_products.php --dump` para ver el detalle completo.
 * La respuesta cruda se guarda siempre en la columna raw.
 *
 * Productos nuevos entran INACTIVOS: tú decides cuáles publicar y a qué precio.
 */
final class ProductSync
{
    public function __construct(private PDO $db, private MastershopClient $ms) {}

    /** Sincronización completa de una vez (consola / cron, sin límite de tiempo). */
    public function run(bool $dump = false): array
    {
        $runStart = date('Y-m-d H:i:s');
        $state = ['page' => 1, 'offset' => 0, 'stats' => ['vistos' => 0, 'nuevos' => 0, 'actualizados' => 0, 'errores' => 0]];
        do {
            $state = $this->batch($state, INF, $dump);
            if ($dump) {
                return $state['stats'];
            }
        } while (!$state['done']);
        $this->finalize($runStart, $state['stats']);
        return $state['stats'];
    }

    /**
     * Procesa productos hasta agotar el tiempo disponible y devuelve dónde quedó,
     * para continuar en la siguiente petición (el admin lo usa por tandas: los hostings
     * cortan los procesos a los 30 segundos).
     *
     * @param array{page:int, offset:int, stats:array} $state
     * @return array{page:int, offset:int, stats:array, done:bool}
     */
    public function batch(array $state, float $seconds, bool $dump = false): array
    {
        $deadline = microtime(true) + $seconds;
        $page = max(1, (int) $state['page']);
        $offset = max(0, (int) $state['offset']);
        $stats = $state['stats'];

        while (true) {
            $list = $this->extractList(MastershopClient::unwrap($this->ms->listProducts($page, 50)));
            if (!$list) {
                return ['page' => $page, 'offset' => 0, 'stats' => $stats, 'done' => true];
            }
            for ($i = $offset; $i < count($list); $i++) {
                if (microtime(true) > $deadline) {
                    return ['page' => $page, 'offset' => $i, 'stats' => $stats, 'done' => false];
                }
                $summary = $list[$i];
                $id = (int) $this->pick((array) $summary, ['idProduct', 'id_product', 'id']);
                if (!$id) {
                    continue;
                }
                try {
                    $detail = MastershopClient::unwrap($this->ms->getProduct($id, true));
                    if (is_array($detail) && array_is_list($detail)) {
                        $detail = $detail[0] ?? [];
                    }
                    $detail = array_merge((array) $summary, is_array($detail) ? $detail : []);
                    if ($dump) {
                        echo json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
                        return ['page' => $page, 'offset' => $i, 'stats' => $stats, 'done' => true];
                    }
                    $before = $this->linked ?? 0;
                    $isNew = $this->upsert($id, $detail);
                    if (($this->linked ?? 0) > $before) {
                        $stats['vinculados'] = ($stats['vinculados'] ?? 0) + 1;
                        $isNew = false;
                    }
                    $stats[$isNew ? 'nuevos' : 'actualizados']++;
                } catch (Throwable $e) {
                    $stats['errores']++;
                    $stats['ultimo_error'] = "Producto {$id}: " . mb_substr($e->getMessage(), 0, 300);
                    error_log("[mastershop-sync] producto {$id}: " . $e->getMessage());
                    // Se marca como visto para no desactivarlo por un error puntual
                    $this->db->prepare('UPDATE products SET synced_at = NOW() WHERE ms_product_id = ?')->execute([$id]);
                }
                $stats['vistos']++;
            }
            if (count($list) < 50) {
                return ['page' => $page, 'offset' => 0, 'stats' => $stats, 'done' => true];
            }
            $page++;
            $offset = 0;
        }
    }

    /**
     * Al terminar una sincronización completa: lo que no apareció en Mastershop se desactiva
     * (no se borra: puede tener pedidos). Si no se vio ningún producto no se toca nada,
     * para no apagar la tienda por una respuesta vacía de la API.
     */
    public function finalize(string $runStart, array $stats): int
    {
        if (($stats['vistos'] ?? 0) === 0) {
            return 0;
        }
        $st = $this->db->prepare(
            'UPDATE products SET is_active = 0, stock = 0
             WHERE ms_product_id <= ' . DemoSeeder::ID_FROM . ' AND (synced_at IS NULL OR synced_at < ?)'
        );
        $st->execute([$runStart]);
        return $st->rowCount();
    }

    private function upsert(int $msId, array $p): bool
    {
        $name   = (string) $this->pick($p, ['nameProduct', 'name', 'productName'], "Producto {$msId}");
        $price  = (int) round((float) $this->pick($p, ['suggestedPrice', 'price', 'basePrice'], 0));
        // basePrice = lo que te cobra el proveedor; suggestedPrice = precio de venta sugerido
        $cost   = $this->pick($p, ['basePrice', 'providerPrice']);
        $stock  = (int) $this->pick($p, ['stockTotal', 'stock', 'availableStock'], 0);
        // nameState distinto de "Activo" = no se puede vender
        $msActive = mb_strtolower((string) $this->pick($p, ['nameState'], 'Activo')) === 'activo';
        if (!$msActive) {
            $stock = 0;
        }
        $images = $this->pick($p, ['images', 'gallery', 'urlImages'], []);
        if (is_string($images)) {
            $images = [$images];
        }
        if ($img = $this->pick($p, ['urlImageProduct', 'image', 'urlImage'])) {
            array_unshift($images, $img);
        }

        $existing = $this->db->prepare('SELECT id, price_locked FROM products WHERE ms_product_id = ?');
        $existing->execute([$msId]);
        $row = $existing->fetch();
        if (!$row && ($linked = $this->linkBySku($msId, $p))) {
            $row = $linked;
        }

        $common = [
            'name'        => mb_substr($name, 0, 255),
            'description' => $this->pick($p, ['description', 'descriptionProduct']),
            'category'    => $this->pick($p, ['prodFormatName', 'categoryName', 'category']),
            'cost'        => $cost !== null ? (int) round((float) $cost) : null,
            'stock'       => $stock,
            'weight'      => $this->pick($p, ['weight', 'weightProduct']),
            'images'      => json_encode(array_values(array_unique(array_filter($images, 'is_string'))), JSON_UNESCAPED_SLASHES),
            'raw'         => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if ($row) {
            $sets = $common;
            if (!$row['price_locked']) {
                $sets['price'] = $price;
            }
            if (!$msActive) {
                $sets['is_active'] = 0; // Mastershop lo desactivó
            }
            $cols = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($sets)));
            $this->db->prepare("UPDATE products SET {$cols}, synced_at = NOW() WHERE id = ?")
                ->execute([...array_values($sets), $row['id']]);
            $productId = (int) $row['id'];
            $isNew = false;
        } else {
            $common['price'] = $price;
            $common['ms_product_id'] = $msId;
            $common['slug'] = $this->slug($name, $msId);
            $cols = implode(', ', array_keys($common));
            $qs = implode(', ', array_fill(0, count($common), '?'));
            $this->db->prepare("INSERT INTO products ({$cols}, synced_at) VALUES ({$qs}, NOW())")
                ->execute(array_values($common));
            $productId = (int) $this->db->lastInsertId();
            $isNew = true;
        }

        $this->syncVariants($productId, $msId, $p, $stock, $common['weight']);
        return $isNew;
    }

    /**
     * Producto creado en la tienda con el mismo SKU que en Mastershop: se vincula en vez de duplicarlo.
     * Busca por el SKU del producto o de sus variantes.
     */
    private function linkBySku(int $msId, array $p): ?array
    {
        $skus = array_filter(array_merge(
            [$this->pick($p, ['sku', 'skuProduct', 'codeProduct'])],
            array_map(fn($v) => is_array($v) ? $this->pick($v, ['sku']) : null, (array) $this->pick($p, ['variation', 'variations', 'variants'], []))
        ), fn($s) => is_string($s) && trim($s) !== '');
        foreach ($skus as $sku) {
            $st = $this->db->prepare(
                'SELECT p.id, p.price_locked FROM products p LEFT JOIN product_variants v ON v.product_id = p.id
                 WHERE p.ms_product_id IS NULL AND (p.sku = ? OR v.sku = ?) LIMIT 1'
            );
            $st->execute([trim($sku), trim($sku)]);
            if ($row = $st->fetch()) {
                $this->db->prepare('UPDATE products SET ms_product_id = ? WHERE id = ?')->execute([$msId, $row['id']]);
                // Las variantes locales sin ID se reemplazan por las de Mastershop en syncVariants
                $this->linked = ($this->linked ?? 0) + 1;
                return $row;
            }
        }
        return null;
    }

    public ?int $linked = null;

    private function syncVariants(int $productId, int $msId, array $p, int $stock, mixed $weight): void
    {
        $variants = $this->pick($p, ['variation', 'variations', 'variants'], []);
        if (!is_array($variants) || !$variants) {
            // Producto sin variantes: Mastershop usa una variante por defecto.
            $defaultId = $this->pick($p, ['idVariant', 'id_variant', 'idDefaultVariant']);
            if (!$defaultId) {
                error_log("[mastershop-sync] producto {$msId} sin id de variante; revisar con --dump");
                return;
            }
            $variants = [['idVariant' => $defaultId, 'stock' => $stock, 'sku' => $this->pick($p, ['sku'])]];
        }

        // Variantes creadas en la tienda (sin ID de Mastershop): se emparejan por SKU, o 1 a 1 si hay una sola
        $local = $this->db->prepare('SELECT id, sku FROM product_variants WHERE product_id = ? AND ms_variant_id IS NULL');
        $local->execute([$productId]);
        $local = $local->fetchAll();
        if ($local) {
            $assign = $this->db->prepare('UPDATE product_variants SET ms_variant_id = ? WHERE id = ?');
            $pending = $local;
            foreach ($variants as $v) {
                $vid = (int) $this->pick((array) $v, ['idVariant', 'id_variant', 'idVariation', 'id']);
                $vsku = trim((string) $this->pick((array) $v, ['sku'], ''));
                if (!$vid) continue;
                $exists = $this->db->prepare('SELECT 1 FROM product_variants WHERE ms_variant_id = ?');
                $exists->execute([$vid]);
                if ($exists->fetch()) continue;
                foreach ($pending as $k => $lv) {
                    if ($vsku !== '' && $lv['sku'] !== null && strcasecmp($vsku, $lv['sku']) === 0) {
                        $assign->execute([$vid, $lv['id']]);
                        unset($pending[$k]);
                        continue 2;
                    }
                }
            }
            if (count($local) === 1 && count($variants) === 1 && $pending) {
                $vid = (int) $this->pick((array) $variants[0], ['idVariant', 'id_variant', 'idVariation', 'id']);
                if ($vid) { $assign->execute([$vid, reset($pending)['id']]); $pending = []; }
            }
            // Las que no se pudieron emparejar quedan sin stock para que nadie las pida
            foreach ($pending as $lv) {
                $this->db->prepare('UPDATE product_variants SET stock = 0 WHERE id = ?')->execute([$lv['id']]);
            }
        }

        $stmt = $this->db->prepare(
            'INSERT INTO product_variants (product_id, ms_variant_id, sku, name, price, stock, weight, raw)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sku = COALESCE(VALUES(sku), sku), name = COALESCE(VALUES(name), name), stock = VALUES(stock),
                                     weight = COALESCE(VALUES(weight), weight), raw = VALUES(raw)'
        );
        foreach ($variants as $v) {
            $vid = (int) $this->pick($v, ['idVariant', 'id_variant', 'idVariation', 'id']);
            if (!$vid) {
                continue;
            }
            $vPrice = $this->pick($v, ['suggestedPrice', 'price']);
            $stmt->execute([
                $productId,
                $vid,
                $this->pick($v, ['sku']),
                $this->pick($v, ['name', 'nameVariant', 'variantName']),
                $vPrice !== null ? (int) round((float) $vPrice) : null,
                (int) $this->pick($v, ['stock', 'availableStock'], 0),
                $this->pick($v, ['weight'], $weight),
                json_encode($v, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    private function extractList(mixed $data): array
    {
        if (is_array($data) && array_is_list($data)) {
            return $data;
        }
        foreach (['results', 'products', 'items', 'rows'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                return $data[$k];
            }
        }
        return [];
    }

    private function pick(array $a, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $k) {
            if (isset($a[$k]) && $a[$k] !== '') {
                return $a[$k];
            }
        }
        return $default;
    }

    private function slug(string $name, int $id): string
    {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
        return ($s ?: 'producto') . '-' . $id;
    }
}
