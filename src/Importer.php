<?php
declare(strict_types=1);

/**
 * Carga masiva de contenido de productos (AIDA, SEO, oferta, formulario) y reseñas.
 *
 * - Acepta .xlsx (primera hoja) o .csv (separador ; o , detectado solo).
 * - La fila se identifica por ms_product_id: el producto debe existir (lo crea la sync de Mastershop).
 * - Celda vacía = no cambia ese dato. Escribe BORRAR para vaciarlo.
 * - Listas dentro de una celda se separan con |
 */
final class Importer
{
    public const COLUMNS = [
        'ms_product_id'       => 'ID en Mastershop. Opcional: sin él el producto se crea en la tienda y se vincula después por SKU',
        'sku'                 => 'Tu código único del producto (ej. TD-HOG-001). Usa el mismo SKU que en Mastershop para que se vinculen solos',
        'activo'              => 'si / no: publicar en la tienda',
        'categoria'           => 'Ruta de la subcategoría: hogar-y-cocina/organizadores',
        'titulo'              => 'Nombre comercial (H1). Vacío = nombre de Mastershop',
        'marca'               => 'Marca del producto (ej. Sokany, VGR). Vacío = sin marca. No uses marcas que el producto no es',
        'slug'                => 'URL: mesa-organizador-3-cajones. Vacío = se genera del título',
        'precio'              => 'Precio de venta en pesos, sin puntos. Vacío + costo = lo calcula la calculadora de precios (flete + publicidad + utilidad)',
        'con_publicidad'      => 'si / no: la calculadora suma el costo de publicidad por venta',
        'costo'               => 'Lo que te cuesta el producto, en pesos (para calcular tu margen)',
        'stock'               => 'Unidades disponibles (solo productos sin vincular; los vinculados toman el stock de Mastershop)',
        'peso'                => 'Peso con empaque en kg (ej. 0.5)',
        'imagenes'            => 'URLs de las fotos separadas por | (https://…). La primera es la portada. También puedes subirlas en el editor',
        'variantes'           => 'Opciones separadas por | (ej. Negro | Blanco). Con foto por opción: Oro = /uploads/oro.webp | Oro rosa = /uploads/rosa.webp',
        'medidas'             => 'Medidas, separadas por |. Alto/ancho/fondo se dibujan con flechas sobre la foto (Alto: 75 cm | Ancho: 40 cm | Fondo: 30 cm); piezas se ven como barras (Cuchillo: 22 cm | Cuchara: 20 cm)',
        'caracteristicas_foto'=> 'Características con foto, separadas por ||. Cada una: Título | texto | ruta de la foto',
        'formas_uso'          => 'Formas de uso, separadas por ||. Cada una: Título | texto | ruta de la foto',
        'comparacion_fotos'   => 'Fotos de la comparación: ruta foto tu producto | ruta foto otros (opcional)',
        'contenido_caja'      => 'Qué trae la caja: cantidad y nombre separados por | (ej. 6 Cucharas de mesa | 6 Tenedores | 1 Estuche)',
        'comparacion'         => 'Tabla comparativa. Filas separadas por ||, columnas por | . La primera fila son los títulos (ej. Característica | Este producto | Otros || Acabado | Espejo | Opaco). Usa si / no para ✓ y ✗',
        'precio_antes'        => 'Precio anterior REAL (para mostrar descuento)',
        'oferta_hasta'        => 'Fecha fin de la oferta: 2026-11-30',
        'meta_title'          => 'Máx. 60 caracteres',
        'meta_description'    => 'Máx. 155 caracteres',
        'atencion_titular'    => 'A: frase grande que detiene la lectura',
        'atencion_gancho'     => 'A: 1-2 líneas bajo el nombre del producto',
        'interes_beneficios'  => 'I: beneficios separados por |',
        'deseo_texto'         => 'D: cómo se usa / qué resuelve. Admite ## títulos, **negrita** y - listas',
        'deseo_para_quien'    => 'D: perfiles separados por |',
        'caracteristicas'     => 'Clave: valor | Clave: valor',
        'accion_garantia'     => 'A: texto de garantía',
        'accion_urgencia'     => 'A: motivo REAL para decidir hoy (stock, fecha)',
        'faq'                 => 'Pregunta :: Respuesta || Pregunta :: Respuesta',
        'contraentrega'       => 'si / no',
        'anticipado'          => 'si / no',
        'descuento_anticipado'=> '% de descuento por pagar anticipado',
        'ofertas_cantidad'    => 'cantidad:%:etiqueta | ...  Ej: 2:10:Más vendido | 3:15. Vacío = la oferta general de la tienda; ninguna = sin oferta',
        'imagenes_descripcion'=> 'Imágenes diseñadas para el cuerpo de la página (características, ingredientes, uso, comparativa), separadas por |. Opcional: ruta :: qué muestra',
        'orden'               => 'Número para ordenar (menor sale primero)',
        'destacado'           => 'si / no: sale primero en portada y categoría',
        'enfocado'            => 'si / no: modo enfocado para anuncios (solo el logo arriba, sin menú ni otros productos)',
        'etiqueta'            => 'Texto en la tarjeta: Nuevo, Más vendido…',
        'oferta_desde'        => 'Fecha de inicio de la oferta (programada): 2026-11-20',
        'resena_titulo'       => 'Título de la reseña. Vacío = sin reseña',
        'resena_calificacion' => '1 a 5, admite decimales: 4,5',
        'resena_veredicto'    => 'Resumen honesto en 1-2 frases',
        'resena_pros'         => 'Separados por |',
        'resena_contras'      => 'Separados por | (obligatorio al publicar)',
        'resena_para_quien'   => 'Separados por |',
        'resena_texto'        => 'Cuerpo de la reseña (markdown simple)',
        'resena_publicar'     => 'si / no',
    ];

    private array $report = ['ok' => 0, 'errors' => [], 'warnings' => []];
    private array $catMap = [];
    private bool $createdNow = false;

    public function __construct(private PDO $db) {}

    public function import(string $file, string $originalName): array
    {
        try {
            $rows = str_ends_with(strtolower($originalName), '.xlsx') ? $this->readXlsx($file) : $this->readCsv($file);
        } catch (RuntimeException $e) {
            return ['ok' => 0, 'errors' => [$e->getMessage()], 'warnings' => []];
        }
        if (!$rows) {
            return ['ok' => 0, 'errors' => ['El archivo está vacío o no se pudo leer.'], 'warnings' => []];
        }
        $header = array_map(fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), array_shift($rows));
        if (!array_intersect(['ms_product_id', 'sku', 'titulo'], $header)) {
            return ['ok' => 0, 'errors' => ['El archivo necesita al menos una de estas columnas: ms_product_id, sku o titulo. Usa la plantilla.'], 'warnings' => []];
        }
        $this->loadCategories();

        foreach ($rows as $i => $cells) {
            $line = $i + 2;
            $row = [];
            foreach ($header as $j => $h) {
                $row[$h] = isset($cells[$j]) ? trim((string) $cells[$j]) : '';
            }
            if (implode('', $row) === '') {
                continue;
            }
            try {
                $this->db->beginTransaction();
                $this->importRow($row, $line);
                $this->db->commit();
                $this->report['ok']++;
            } catch (Throwable $e) {
                $this->db->rollBack();
                $this->report['errors'][] = "Fila {$line}: " . $e->getMessage();
            }
        }
        return $this->report;
    }

    /** Para el editor del admin: misma validación que la carga masiva. */
    public function importOne(array $row): array
    {
        $this->loadCategories();
        $this->db->beginTransaction();
        try {
            $this->importRow($row, 1);
            $this->db->commit();
            $this->report['ok'] = 1;
        } catch (Throwable $e) {
            $this->db->rollBack();
            $this->report['errors'][] = $e->getMessage();
        }
        return $this->report;
    }

    private function importRow(array $r, int $line): void
    {
        $p = $this->resolveProduct($r, $line);
        // Vincular a mano: producto propio + ID de Mastershop en la fila
        $msLink = (int) preg_replace('/\D/', '', (string) ($r['ms_product_id'] ?? ''));
        if ($msLink && empty($p['ms_product_id'])) {
            $chk = $this->db->prepare('SELECT id FROM products WHERE ms_product_id = ? AND id <> ?');
            $chk->execute([$msLink, $p['id']]);
            if ($chk->fetch()) {
                throw new DomainException("el ID de Mastershop {$msLink} ya está vinculado a otro producto.");
            }
            $this->db->prepare('UPDATE products SET ms_product_id = ? WHERE id = ?')->execute([$msLink, $p['id']]);
            $p['ms_product_id'] = $msLink;
            $this->report['warnings'][] = "Fila {$line}: producto vinculado al ID {$msLink} de Mastershop. Sincroniza para traer sus variantes.";
        }

        $set = [];
        $text = function (string $col, string $field, ?int $max = null) use ($r, &$set, $line) {
            if (!isset($r[$col]) || $r[$col] === '') return;
            $v = strtoupper($r[$col]) === 'BORRAR' ? null : $r[$col];
            if ($v !== null && $max && mb_strlen($v) > $max) {
                $this->report['warnings'][] = "Fila {$line}: {$col} tiene " . mb_strlen($v) . " caracteres (recomendado máx. {$max}).";
            }
            $set[$field] = $v;
        };
        $list = function (string $col, string $field) use ($r, &$set) {
            if (!isset($r[$col]) || $r[$col] === '') return;
            $set[$field] = strtoupper($r[$col]) === 'BORRAR' ? null : implode("\n", array_filter(array_map('trim', explode('|', $r[$col]))));
        };
        $bool = function (string $col, string $field) use ($r, &$set) {
            if (!isset($r[$col]) || $r[$col] === '') return;
            $set[$field] = in_array(mb_strtolower($r[$col]), ['si', 'sí', 's', '1', 'x', 'true'], true) ? 1 : 0;
        };
        $int = function (string $col, string $field) use ($r, &$set) {
            if (!isset($r[$col]) || $r[$col] === '') return;
            $set[$field] = strtoupper($r[$col]) === 'BORRAR' ? null : (int) preg_replace('/[^\d]/', '', explode(',', $r[$col])[0]);
        };

        $text('titulo', 'title', 120);
        $text('marca', 'brand', 80);
        $bool('con_publicidad', 'with_ads');
        $text('meta_title', 'meta_title', 60);
        $text('meta_description', 'meta_description', 155);
        $text('atencion_titular', 'aida_headline');
        $text('atencion_gancho', 'aida_hook', 220);
        $list('interes_beneficios', 'aida_benefits');
        $text('deseo_texto', 'aida_desire');
        $list('deseo_para_quien', 'aida_for_whom');
        $list('caracteristicas', 'specs');
        $list('contenido_caja', 'box_contents');
        $list('medidas', 'measures');
        foreach (['caracteristicas_foto' => 'features_media', 'formas_uso' => 'usage_steps'] as $col => $field) {
            if (($r[$col] ?? '') !== '') {
                $set[$field] = strtoupper($r[$col]) === 'BORRAR' ? null : implode("\n", array_filter(array_map('trim', explode('||', $r[$col]))));
            }
        }
        $text('comparacion_fotos', 'cmp_images');
        if (($r['comparacion'] ?? '') !== '') {
            $set['comparison'] = strtoupper($r['comparacion']) === 'BORRAR' ? null
                : implode("\n", array_filter(array_map('trim', explode('||', $r['comparacion']))));
        }
        $text('accion_garantia', 'aida_guarantee');
        $text('accion_urgencia', 'aida_urgency');
        $bool('activo', 'is_active');
        $bool('contraentrega', 'allow_cod');
        $bool('anticipado', 'allow_pia');
        $int('descuento_anticipado', 'pia_discount');
        $int('precio', 'price');
        $int('precio_antes', 'price_before');
        $int('orden', 'sort');
        $int('costo', 'cost');
        if (($r['peso'] ?? '') !== '') {
            $set['weight'] = strtoupper($r['peso']) === 'BORRAR' ? null : (float) str_replace(',', '.', $r['peso']);
        }
        if (($r['sku'] ?? '') !== '' && strtoupper($r['sku']) !== 'BORRAR') {
            $sku = mb_substr(trim($r['sku']), 0, 120);
            $chk = $this->db->prepare('SELECT id FROM products WHERE sku = ? AND id <> ?');
            $chk->execute([$sku, $p['id']]);
            if ($chk->fetch()) {
                throw new DomainException("el SKU «{$sku}» ya lo usa otro producto.");
            }
            $set['sku'] = $sku;
        }
        if (($r['imagenes_descripcion'] ?? '') !== '') {
            $list = [];
            if (strtoupper($r['imagenes_descripcion']) !== 'BORRAR') {
                foreach (explode('|', $r['imagenes_descripcion']) as $chunk) {
                    [$src, $alt] = array_pad(array_map('trim', explode('::', $chunk, 2)), 2, '');
                    if (preg_match('#^(https://|/uploads/|/assets/)\S+$#', $src)) { $list[] = ['src' => $src, 'alt' => mb_substr($alt, 0, 160)]; }
                }
            }
            $set['body_images'] = $list ? json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        }
        if (($r['imagenes'] ?? '') !== '') {
            $imgs = [];
            if (strtoupper($r['imagenes']) !== 'BORRAR') {
                foreach (explode('|', $r['imagenes']) as $u) {
                    $u = trim($u);
                    if (preg_match('#^(https://|/uploads/|/assets/)\S+$#', $u)) {
                        $imgs[] = $u;
                    } elseif ($u !== '') {
                        $this->report['warnings'][] = "Fila {$line}: la imagen «" . mb_substr($u, 0, 60) . "» no es una URL https válida y se ignoró.";
                    }
                }
            }
            $set['custom_images'] = $imgs ? json_encode(array_slice($imgs, 0, 12), JSON_UNESCAPED_SLASHES) : null;
        }
        $isLinked = !empty($p['ms_product_id']);
        if (($r['stock'] ?? '') !== '' && !$isLinked) {
            $set['stock'] = max(0, (int) preg_replace('/[^\d-]/', '', $r['stock']));
        }
        $bool('destacado', 'is_featured');
        $bool('enfocado', 'focus_mode');
        $text('etiqueta', 'badge', 30);
        if (($r['oferta_desde'] ?? '') !== '') {
            $set['offer_from'] = strtoupper($r['oferta_desde']) === 'BORRAR' ? null : $this->date($r['oferta_desde'], $line) . ' 00:00:00';
        }

        if (isset($set['price'])) {
            $set['price_locked'] = 1; // tu precio manda sobre el sugerido de Mastershop
            $set['price_auto'] = 0;
        } elseif (($r['precio'] ?? '') === '' && (isset($set['cost']) || isset($set['with_ads'])) && ($p['price_auto'] || $this->createdNow)) {
            // Precio vacío: lo calcula la calculadora con el costo
            $calc = Pricing::calculate((int) ($set['cost'] ?? $p['cost']), (bool) ($set['with_ads'] ?? $p['with_ads']));
            if ($calc) {
                $set['price'] = $calc['precio'];
                $set['price_locked'] = 1;
                $set['price_auto'] = 1;
            }
        }
        $this->createdNow = false;
        if (($r['oferta_hasta'] ?? '') !== '') {
            $set['offer_until'] = strtoupper($r['oferta_hasta']) === 'BORRAR' ? null : $this->date($r['oferta_hasta'], $line) . ' 23:59:59';
        }
        $price = (int) ($set['price'] ?? $p['price']);
        $before = array_key_exists('price_before', $set) ? $set['price_before'] : $p['price_before'];
        if ($before && $before <= $price) {
            $this->report['warnings'][] = "Fila {$line}: precio_antes no es mayor que el precio; no se mostrará descuento.";
        }

        if (($r['categoria'] ?? '') !== '') {
            $key = trim(strtolower($r['categoria']), '/ ');
            $set['category_id'] = $this->catMap[$key] ?? throw new DomainException("la categoría «{$r['categoria']}» no existe. Créala en el admin o revisa la ruta.");
        }

        if (($r['faq'] ?? '') !== '') {
            $set['faq'] = strtoupper($r['faq']) === 'BORRAR' ? null : json_encode($this->parseFaq($r['faq']), JSON_UNESCAPED_UNICODE);
        }
        if (($r['ofertas_cantidad'] ?? '') !== '') {
            $offers = [];
            if (strtoupper($r['ofertas_cantidad']) !== 'BORRAR') {
                foreach (explode('|', $r['ofertas_cantidad']) as $chunk) {
                    $parts = array_map('trim', explode(':', $chunk, 3));
                    if ((int) ($parts[0] ?? 0) >= 2) {
                        $offers[] = ['qty' => (int) $parts[0], 'pct' => (int) ($parts[1] ?? 0), 'label' => $parts[2] ?? ''];
                    }
                }
            }
            $none = in_array(mb_strtolower(trim($r['ofertas_cantidad'])), ['ninguna', 'no', '0'], true);
            $set['qty_offers'] = $offers ? json_encode($offers, JSON_UNESCAPED_UNICODE) : ($none ? '[]' : null);
        }

        // Slug: si cambia, se deja redirección 301 desde el anterior
        $newSlug = ($r['slug'] ?? '') !== '' ? slugify($r['slug'])
            : (isset($set['title']) && $set['title'] ? slugify($set['title']) : null);
        if ($newSlug && $newSlug !== $p['slug']) {
            $chk = $this->db->prepare('SELECT id FROM products WHERE slug = ? AND id <> ?');
            $chk->execute([$newSlug, $p['id']]);
            if ($chk->fetch()) {
                throw new DomainException("la URL «{$newSlug}» ya la usa otro producto.");
            }
            $set['slug'] = $newSlug;
            $this->db->prepare('INSERT IGNORE INTO redirects (from_path, to_path) VALUES (?, ?)')
                ->execute(["/p/{$p['slug']}/", "/p/{$newSlug}/"]);
            $this->db->prepare('UPDATE redirects SET to_path = ? WHERE to_path = ?')
                ->execute(["/p/{$newSlug}/", "/p/{$p['slug']}/"]);
        }

        if (!empty($set['is_active']) && !($set['category_id'] ?? $p['category_id'])) {
            throw new DomainException('para activar el producto necesita categoría.');
        }

        if ($set) {
            $cols = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($set)));
            $this->db->prepare("UPDATE products SET {$cols} WHERE id = ?")->execute([...array_values($set), $p['id']]);
        }
        if (!$isLinked) {
            $this->syncLocalVariants((int) $p['id'], $r, $set['sku'] ?? $p['sku'] ?? null, $set['stock'] ?? null);
        }

        if (($r['resena_titulo'] ?? '') !== '') {
            $this->importReview($r, (int) $p['id'], (int) ($set['category_id'] ?? $p['category_id']), $line);
        }
    }

    /**
     * Busca el producto de la fila: por ID interno (editor), ID de Mastershop, SKU o URL.
     * Si no existe y la fila trae título, precio y categoría, lo crea como producto propio.
     */
    private function resolveProduct(array $r, int $line): array
    {
        $find = function (string $col, mixed $val): ?array {
            $st = $this->db->prepare("SELECT * FROM products WHERE {$col} = ? LIMIT 1");
            $st->execute([$val]);
            return $st->fetch() ?: null;
        };
        if (!empty($r['_id']) && ($p = $find('id', (int) $r['_id']))) {
            return $p;
        }
        $msId = (int) preg_replace('/\D/', '', (string) ($r['ms_product_id'] ?? ''));
        $sku = trim((string) ($r['sku'] ?? ''));
        if ($msId && ($p = $find('ms_product_id', $msId))) {
            return $p;
        }
        if ($sku !== '' && ($p = $find('sku', $sku))) {
            if ($msId && empty($p['ms_product_id'])) { // vincular a mano con el ID de Mastershop
                $this->db->prepare('UPDATE products SET ms_product_id = ? WHERE id = ?')->execute([$msId, $p['id']]);
                $p['ms_product_id'] = $msId;
            }
            return $p;
        }
        if (($r['slug'] ?? '') !== '' && ($p = $find('slug', slugify($r['slug'])))) {
            return $p;
        }

        // Crear producto propio
        $title = trim((string) ($r['titulo'] ?? ''));
        $price = (int) preg_replace('/[^\d]/', '', explode(',', (string) ($r['precio'] ?? ''))[0]);
        if ($price <= 0 && ($r['costo'] ?? '') !== '') {
            $calc = Pricing::calculate((int) preg_replace('/[^\d]/', '', (string) $r['costo']), in_array(mb_strtolower((string) ($r['con_publicidad'] ?? '')), ['si', 'sí', 's', '1', 'x'], true));
            if (!$calc) {
                throw new DomainException('falta precio y la calculadora de precios no está configurada (Tienda → Calculadora de precios): configúrala o escribe el precio.');
            }
            $price = $calc['precio'];
        }
        $missing = array_keys(array_filter([
            'titulo' => $title === '',
            'precio' => $price <= 0,
            'categoria' => trim((string) ($r['categoria'] ?? '')) === '',
        ]));
        if ($missing) {
            $ref = $sku ?: ($title ?: ($msId ?: 'sin identificar'));
            $noCol = array_values(array_filter($missing, fn($c) => !array_key_exists($c, $r)));
            throw new DomainException("no se pudo crear «{$ref}»: falta " . implode(', ', $missing)
                . ($noCol ? ' (el archivo no tiene la columna ' . implode(', ', $noCol) . ': descarga la plantilla actualizada)' : '')
                . ($msId ? '. Si ya existe en Mastershop, sincroniza primero.' : '.'));
        }
        $slug = slugify(($r['slug'] ?? '') !== '' ? $r['slug'] : $title);
        $base = $slug; $n = 2;
        while ($find('slug', $slug)) { $slug = "{$base}-{$n}"; $n++; }
        $this->db->prepare(
            'INSERT INTO products (ms_product_id, sku, name, title, slug, price, price_locked, stock, images, is_active, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?, 0, NULL)'
        )->execute([$msId ?: null, $sku !== '' ? mb_substr($sku, 0, 120) : null, mb_substr($title, 0, 255), mb_substr($title, 0, 160), $slug, $price, '[]']);
        $this->report['created'] = ($this->report['created'] ?? 0) + 1;
        $this->createdNow = true;
        return $find('id', (int) $this->db->lastInsertId());
    }

    /** Variantes de un producto propio: una por cada opción de la columna "variantes", o una única. */
    /**
     * Colores o variantes de un producto propio. Si la fila trae la columna «variantes», la lista guardada
     * queda EXACTAMENTE así: los que no estén se eliminan, la foto se pone o se quita, y «ninguna» deja
     * el producto sin variantes. Si la columna viene vacía, las variantes no se tocan.
     */
    private function syncLocalVariants(int $productId, array $r, ?string $sku, ?int $stock): void
    {
        $st = $this->db->prepare('SELECT id, name FROM product_variants WHERE product_id = ? AND ms_variant_id IS NULL ORDER BY id');
        $st->execute([$productId]);
        $rows = $st->fetchAll();
        $baseStock = $stock ?? (int) $this->db->query('SELECT stock FROM products WHERE id = ' . $productId)->fetchColumn();
        $ins = $this->db->prepare('INSERT INTO product_variants (product_id, sku, name, stock, image) VALUES (?, ?, ?, ?, ?)');
        $raw = trim((string) ($r['variantes'] ?? ''));

        if ($raw === '') {                                   // sin cambios en la lista
            if (!$rows) { $ins->execute([$productId, $sku, null, $baseStock, null]); }
        } else {
            $list = [];
            if (!in_array(mb_strtolower($raw), ['ninguna', 'no', 'borrar'], true)) {
                foreach (array_filter(array_map('trim', explode('|', $raw))) as $opt) {
                    [$n, $img] = array_pad(array_map('trim', explode('=', $opt, 2)), 2, '');
                    if ($n === '' || isset($list[mb_strtolower($n)])) continue;
                    $list[mb_strtolower($n)] = [mb_substr($n, 0, 60), preg_match('#^(https://|/uploads/|/assets/)\S+$#', $img) ? $img : null];
                }
            }
            $byName = [];
            foreach ($rows as $v) { $byName[mb_strtolower((string) $v['name'])] = (int) $v['id']; }
            $keep = [];
            if (!$list) {                                    // sin colores: una sola variante sin nombre
                if (isset($byName[''])) { $keep[] = $byName['']; }
                else { $ins->execute([$productId, $sku, null, $baseStock, null]); $keep[] = (int) $this->db->lastInsertId(); }
            } else {
                $up = $this->db->prepare('UPDATE product_variants SET name = ?, image = ? WHERE id = ?');
                foreach ($list as $key => [$name, $img]) {
                    if (isset($byName[$key])) {
                        $up->execute([$name, $img, $byName[$key]]);
                        $keep[] = $byName[$key];
                    } else {
                        $vsku = $sku ? $sku . '-' . strtoupper(substr(slugify($name), 0, 12)) : null;
                        $ins->execute([$productId, $vsku, $name, $baseStock, $img]);
                        $keep[] = (int) $this->db->lastInsertId();
                    }
                }
            }
            $del = array_diff(array_map(fn($v) => (int) $v['id'], $rows), $keep);
            if ($del) { $this->db->exec('DELETE FROM product_variants WHERE id IN (' . implode(',', $del) . ')'); }
        }
        if ($stock !== null) {
            $this->db->prepare('UPDATE product_variants SET stock = ? WHERE product_id = ? AND ms_variant_id IS NULL')->execute([$stock, $productId]);
        }
    }

    private function importReview(array $r, int $productId, int $categoryId, int $line): void
    {
        // Las reseñas cuelgan del PILAR de la categoría del producto
        $st = $this->db->prepare('SELECT COALESCE(parent_id, id) FROM categories WHERE id = ?');
        $st->execute([$categoryId]);
        $pillarId = (int) $st->fetchColumn() ?: null;

        $publish = in_array(mb_strtolower($r['resena_publicar'] ?? ''), ['si', 'sí', 's', '1', 'x'], true);
        $cons = implode("\n", array_filter(array_map('trim', explode('|', $r['resena_contras'] ?? ''))));
        if ($publish && $cons === '') {
            throw new DomainException('una reseña publicada necesita al menos un contra real (resena_contras).');
        }
        $rating = ($r['resena_calificacion'] ?? '') !== '' ? max(1, min(5, (float) str_replace(',', '.', $r['resena_calificacion']))) : null;

        $data = [
            'category_id' => $pillarId,
            'title'       => $r['resena_titulo'],
            'rating'      => $rating,
            'verdict'     => $r['resena_veredicto'] ?? null,
            'pros'        => implode("\n", array_filter(array_map('trim', explode('|', $r['resena_pros'] ?? '')))),
            'cons'        => $cons,
            'for_whom'    => implode("\n", array_filter(array_map('trim', explode('|', $r['resena_para_quien'] ?? '')))),
            'body'        => $r['resena_texto'] ?? null,
            'is_published'=> $publish ? 1 : 0,
        ];

        $st = $this->db->prepare('SELECT id, published_at FROM reviews WHERE product_id = ?');
        $st->execute([$productId]);
        if ($ex = $st->fetch()) {
            $cols = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
            $this->db->prepare("UPDATE reviews SET {$cols}, published_at = COALESCE(published_at, IF(?, NOW(), NULL)) WHERE id = ?")
                ->execute([...array_values($data), $publish ? 1 : 0, $ex['id']]);
        } else {
            $slug = slugify($r['resena_titulo']);
            $chk = $this->db->prepare('SELECT COUNT(*) FROM reviews WHERE slug LIKE ?');
            $chk->execute([$slug . '%']);
            if ((int) $chk->fetchColumn()) {
                $slug .= '-' . $productId;
            }
            $data += ['product_id' => $productId, 'slug' => $slug, 'published_at' => $publish ? date('Y-m-d H:i:s') : null];
            $cols = implode(', ', array_keys($data));
            $qs = implode(', ', array_fill(0, count($data), '?'));
            $this->db->prepare("INSERT INTO reviews ({$cols}) VALUES ({$qs})")->execute(array_values($data));
        }
    }

    private function parseFaq(string $s): array
    {
        $out = [];
        foreach (explode('||', $s) as $pair) {
            [$q, $a] = array_pad(array_map('trim', explode('::', $pair, 2)), 2, '');
            if ($q !== '' && $a !== '') {
                $out[] = ['q' => $q, 'a' => $a];
            }
        }
        return $out;
    }

    private function date(string $v, int $line): string
    {
        if (is_numeric($v)) { // fecha serial de Excel
            return gmdate('Y-m-d', ((int) $v - 25569) * 86400);
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $f) {
            $d = DateTime::createFromFormat($f, $v);
            if ($d) {
                return $d->format('Y-m-d');
            }
        }
        throw new DomainException("oferta_hasta «{$v}» no es una fecha válida (usa 2026-11-30).");
    }

    private function loadCategories(): void
    {
        $rows = $this->db->query('SELECT c.id, c.slug, p.slug AS parent FROM categories c LEFT JOIN categories p ON p.id = c.parent_id')->fetchAll();
        foreach ($rows as $c) {
            $this->catMap[$c['parent'] ? "{$c['parent']}/{$c['slug']}" : $c['slug']] = (int) $c['id'];
        }
        foreach ($rows as $c) { // también se acepta solo el slug de la subcategoría si es único
            if ($c['parent'] && !isset($this->catMap[$c['slug']])) {
                $this->catMap[$c['slug']] = (int) $c['id'];
            }
        }
    }

    /* ---------------- Lectores ---------------- */

    private function readCsv(string $file): array
    {
        $raw = (string) file_get_contents($file);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'); // CSV guardado desde Excel en Windows
        }
        $first = strtok($raw, "\n");
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $h = fopen('php://memory', 'r+');
        fwrite($h, $raw);
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, 0, $sep, '"', '')) !== false) {
            $rows[] = $r;
        }
        return $rows;
    }

    /** Lector mínimo de .xlsx (primera hoja), sin librerías. */
    private function readXlsx(string $file): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('El servidor no tiene la extensión zip de PHP. Guarda el Excel como CSV y súbelo así.');
        }
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            throw new RuntimeException('No se pudo abrir el Excel.');
        }
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sx = simplexml_load_string($xml);
            foreach ($sx->si as $si) {
                $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', iterator_to_array($si->xpath('.//*[local-name()="t"]'), false)));
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet === false) {
            throw new RuntimeException('El Excel no tiene hojas.');
        }
        $sx = simplexml_load_string($sheet);
        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $col = $this->colIndex(preg_replace('/\d+/', '', (string) $c['r']));
                $t = (string) $c['t'];
                $v = match ($t) {
                    's'         => $shared[(int) $c->v] ?? '',
                    'inlineStr' => (string) ($c->is->t ?? ''),
                    default     => (string) $c->v,
                };
                $cells[$col] = $v;
            }
            if ($cells) {
                $max = max(array_keys($cells));
                $rows[] = array_map(fn($i) => $cells[$i] ?? '', range(0, $max));
            }
        }
        return $rows;
    }

    private function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }
}
