<?php
declare(strict_types=1);

/**
 * Actualiza la base de datos sola: revisa qué le falta y aplica solo eso.
 * Así no hace falta correr los .sql de cada versión en phpMyAdmin, y si alguno
 * quedó a medias se completa sin errores de "columna duplicada".
 *
 * Se ejecuta una vez y guarda la versión en settings.schema_version; las visitas
 * siguientes solo leen ese número.
 */
final class Migrator
{
    public const VERSION = 21;

    public function __construct(private PDO $db) {}

    public static function ensure(PDO $db): void
    {
        try {
            $v = (int) $db->query("SELECT v FROM settings WHERE k = 'schema_version'")->fetchColumn();
        } catch (PDOException) {
            return; // tienda aún no instalada (sin tabla settings): se instala con los .sql base
        }
        if ($v >= self::VERSION) {
            return;
        }
        (new self($db))->run();
    }

    public function run(): array
    {
        $done = [];
        if (!$this->hasTable('visits'))  { $this->runFile('schema_v3_analitica.sql'); $done[] = 'v3 analítica'; }
        if (!$this->hasTable('banners')) { $this->runFile('schema_v4_banners.sql');   $done[] = 'v4 banners'; }
        if (!$this->hasColumn('admins', 'name')) { $this->runFile('schema_v5_admin.sql'); $done[] = 'v5 usuarios y píxeles'; }

        // v6 paso a paso (cada cambio solo si falta)
        if (!$this->hasColumn('products', 'sku')) {
            $this->db->exec('ALTER TABLE products ADD COLUMN sku VARCHAR(120) NULL AFTER ms_product_id');
            $done[] = 'products.sku';
        }
        if (!$this->hasIndex('products', 'uq_products_sku')) {
            $this->db->exec('ALTER TABLE products ADD UNIQUE KEY uq_products_sku (sku)');
        }
        foreach ([['products', 'ms_product_id'], ['product_variants', 'ms_variant_id'], ['order_items', 'ms_product_id'], ['order_items', 'ms_variant_id']] as [$t, $c]) {
            if (!$this->isNullable($t, $c)) {
                $this->db->exec("ALTER TABLE {$t} MODIFY {$c} BIGINT UNSIGNED NULL");
                $done[] = "{$t}.{$c} opcional";
            }
        }
        if (!$this->hasColumn('order_items', 'product_id')) {
            $this->db->exec('ALTER TABLE order_items ADD COLUMN product_id INT UNSIGNED NULL AFTER order_id, ADD INDEX (product_id)');
            $done[] = 'order_items.product_id';
        }
        if (!$this->hasColumn('order_items', 'variant_id')) {
            $this->db->exec('ALTER TABLE order_items ADD COLUMN variant_id INT UNSIGNED NULL AFTER product_id');
            $done[] = 'order_items.variant_id';
        }
        // Pedidos viejos: completar product_id a partir del ID de Mastershop
        $this->db->exec('UPDATE order_items i JOIN products p ON p.ms_product_id = i.ms_product_id SET i.product_id = p.id WHERE i.product_id IS NULL');

        // v7: Black Friday → Navidad y "precio mayorista" → "envío incluido" (solo si no los editaste)
        $this->db->exec("UPDATE banners SET eyebrow = 'Temporada navideña', title = 'Navidad', highlight = 'en tendencia',
            text = 'Regalos que sí van a usar, con el envío incluido y pago al recibir en toda Colombia.',
            button_text = 'Ver regalos y ofertas', button_url = '/ofertas/', until = '" . date('Y') . "-12-24 23:59:59'
            WHERE slot = 'principal' AND title = 'Black' AND highlight = 'Friday'");
        $this->db->exec("UPDATE banners SET eyebrow = 'Sin costos sorpresa', title = 'El precio que ves', highlight = 'ya incluye el envío',
            text = 'Llega a tu casa en cualquier ciudad de Colombia y pagas en efectivo cuando lo recibes.',
            button_text = 'Ver productos', button_url = '/ofertas/', button2_text = 'Pedir por WhatsApp', button2_url = 'whatsapp'
            WHERE slot = 'mayorista' AND title = 'Precio mayorista'");
        $this->db->exec("UPDATE settings SET v = 'Envío incluido a toda Colombia · Pagas al recibir'
            WHERE k = 'announcement' AND v LIKE '%a toda Colombia%Paga al recibir%' AND v NOT LIKE '%incluido%'");

        // v8: WhatsApp solo para soporte, con el número de Tendencity
        $this->db->exec("UPDATE settings SET v = '573182165374' WHERE k = 'whatsapp' AND (v IS NULL OR v = '' OR v = '573000000000')");
        $this->db->exec("UPDATE banners SET button2_text = NULL, button2_url = NULL WHERE button2_url = 'whatsapp'");

        // v9: marca, calculadora de precios y categorías nuevas
        if (!$this->hasColumn('products', 'brand')) {
            $this->db->exec('ALTER TABLE products ADD COLUMN brand VARCHAR(80) NULL AFTER title');
            $done[] = 'products.brand';
        }
        if (!$this->hasColumn('products', 'price_auto')) {
            $this->db->exec('ALTER TABLE products ADD COLUMN price_auto TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN with_ads TINYINT(1) NOT NULL DEFAULT 0');
            $done[] = 'calculadora de precios';
        }
        $this->db->exec("INSERT IGNORE INTO settings (k, v) VALUES ('calc_flete', ''), ('calc_publicidad', ''), ('calc_utilidad_pct', ''), ('calc_utilidad_min', '')");
        $this->ensureCategory(null, 'herramientas', 'Herramientas', 'Herramientas eléctricas con envío incluido y pago contraentrega', 5);
        $this->ensureCategory('tecnologia', 'accesorios-para-carro', 'Accesorios para carro', 'Accesorios para carro', 3);
        $this->ensureCategory('hogar-y-cocina', 'iluminacion', 'Iluminación', 'Iluminación y cintas LED', 3);

        // v10: Portada configurable. Lo que ya estaba elegido (producto de portada e IDs del banner) pasa a modo manual
        $get = fn(string $k) => $this->db->query('SELECT v FROM settings WHERE k = ' . $this->db->quote($k))->fetchColumn();
        if (!$get('home_slots')) {
            $cfg = [];
            $heroIds = array_filter(array_map('intval', explode(',', (string) $this->db->query("SELECT product_ids FROM banners WHERE slot = 'principal' AND product_ids <> '' LIMIT 1")->fetchColumn())));
            if ($heroIds) { $cfg['hero'] = ['mode' => 'manual', 'ids' => array_slice(array_values($heroIds), 0, 3)]; }
            $env = (int) $get('hero_product_id');
            if ($env) { $cfg['envio'] = ['mode' => 'manual', 'ids' => [$env]]; }
            $this->db->prepare("INSERT IGNORE INTO settings (k, v) VALUES ('home_slots', ?)")->execute([json_encode((object) $cfg)]);
        }

        // v11: API de Conversiones de Meta
        $this->db->exec("INSERT IGNORE INTO settings (k, v) VALUES ('meta_capi_token', ''), ('meta_test_code', ''), ('meta_capi_last', '')");

        // v12: comparación, contenido de la caja e imagen por variante (color)
        if (!$this->hasColumn('products', 'comparison')) {
            $this->db->exec('ALTER TABLE products ADD COLUMN comparison TEXT NULL, ADD COLUMN box_contents TEXT NULL');
            $done[] = 'comparación y contenido de la caja';
        }
        if (!$this->hasColumn('product_variants', 'image')) {
            $this->db->exec('ALTER TABLE product_variants ADD COLUMN image VARCHAR(255) NULL');
            $done[] = 'imagen por variante';
        }

        // v13: modo landing para productos que se pautan
        if (!$this->hasColumn('products', 'landing')) {
            $this->db->exec('ALTER TABLE products ADD COLUMN landing TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN landing_images JSON NULL');
            $this->db->exec("UPDATE products SET landing = 1 WHERE sku = 'AMC-11'"); // el set de cubiertos que se va a pautar
            $done[] = 'modo landing';
        }

        // v14: bloques visuales (medidas, características con foto, formas de uso, fotos de comparación) y testimonios
        if (!$this->hasColumn('products', 'measures')) {
            $this->db->exec('ALTER TABLE products ADD COLUMN measures TEXT NULL, ADD COLUMN features_media TEXT NULL,
                             ADD COLUMN usage_steps TEXT NULL, ADD COLUMN cmp_images VARCHAR(600) NULL');
            $done[] = 'bloques visuales';
        }
        if (!$this->hasTable('testimonials')) {
            $this->db->exec("CREATE TABLE testimonials (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT UNSIGNED NULL,
                name VARCHAR(80) NOT NULL,
                city VARCHAR(80) NULL,
                rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
                text TEXT NOT NULL,
                photo VARCHAR(255) NULL,
                order_ref VARCHAR(40) NULL,
                verified TINYINT(1) NOT NULL DEFAULT 0,
                is_published TINYINT(1) NOT NULL DEFAULT 1,
                sort SMALLINT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX (product_id, is_published)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $done[] = 'testimonios';
        }

        // v15: fecha estimada de entrega
        $this->db->exec("INSERT IGNORE INTO settings (k, v) VALUES ('ship_prep_days', '0-1'), ('ship_transit_days', '2-3'), ('ship_cutoff_hour', '14'), ('ship_saturday', '1'),
            ('ship_note', 'Fecha estimada para ciudades principales. Municipios alejados pueden tardar unos días más.')");

        // v16: imágenes de la descripción para todos los productos y valores por defecto de la tienda
        if (!$this->hasColumn('products', 'body_images')) {
            $this->db->exec('ALTER TABLE products ADD COLUMN body_images JSON NULL');
            // Las piezas de la landing pasan a ser imágenes de la descripción
            foreach ($this->db->query('SELECT id, landing_images FROM products WHERE landing_images IS NOT NULL') as $r) {
                $list = array_map(fn($src) => ['src' => $src, 'alt' => ''], json_decode((string) $r['landing_images'], true) ?: []);
                if ($list) { $this->db->prepare('UPDATE products SET body_images = ? WHERE id = ?')->execute([json_encode($list, JSON_UNESCAPED_SLASHES), $r['id']]); }
            }
            // Los productos existentes sin oferta por cantidad siguen sin oferta (la oferta general es para los nuevos)
            $this->db->exec("UPDATE products SET qty_offers = '[]' WHERE qty_offers IS NULL");
            $done[] = 'imágenes de la descripción';
        }
        $this->db->exec("INSERT IGNORE INTO settings (k, v) VALUES
            ('def_guarantee', 'Garantía por defectos de fábrica'),
            ('def_urgency', ''),
            ('def_qty_offers', '2:10:Más vendido'),
            ('def_faq', '¿Puedo pagar al recibir? :: Sí. Pagas en efectivo cuando te llega el pedido.\n¿El envío tiene costo? :: No. El envío a toda Colombia ya está incluido en el precio.\n¿Cuánto tarda en llegar? :: Normalmente entre 2 y 4 días hábiles en ciudades principales; a municipios alejados puede tardar unos días más.\n¿Qué pasa si llega con un defecto? :: Escríbenos a soporte por WhatsApp con una foto o video y te ayudamos con la garantía.')");

        // v18: modo enfocado (solo logo) para productos que se pautan
        if (!$this->hasColumn('products', 'focus_mode')) {
            $this->db->exec('ALTER TABLE products ADD COLUMN focus_mode TINYINT(1) NOT NULL DEFAULT 0');
            $done[] = 'modo enfocado';
        }
        // v17: frases de la marquesina (solo si seguía el texto anterior)
        $this->db->exec("UPDATE settings SET v = 'Pago en casa · Envío gratis a toda Colombia' WHERE k = 'announcement' AND v LIKE '%a toda Colombia%Pagas al recibir%'");

        // v19: despacho fuera de Mastershop (Dropi vía Claude) y pago anticipado general
        if (!$this->hasColumn('orders', 'fulfillment_ref')) {
            $this->db->exec('ALTER TABLE orders ADD COLUMN fulfillment_ref VARCHAR(60) NULL AFTER ms_order_id, ADD INDEX (fulfillment_ref)');
            $done[] = 'orders.fulfillment_ref';
        }
        $this->db->exec("INSERT IGNORE INTO settings (k, v) VALUES ('pia_global', '1'), ('pia_global_discount', '5')");
        $this->localizeTexts();

        // v21: confirmación del pedido por WhatsApp antes de despacharlo
        if (!$this->hasColumn('orders', 'confirm_status')) {
            $this->db->exec("ALTER TABLE orders
                ADD COLUMN confirm_status VARCHAR(16) NOT NULL DEFAULT 'confirmado' AFTER sync_status,
                ADD COLUMN confirmed_at DATETIME NULL AFTER confirm_status,
                ADD COLUMN confirmed_by VARCHAR(120) NULL AFTER confirmed_at,
                ADD INDEX (confirm_status)");
            $done[] = 'confirmación de pedidos';   // los pedidos que ya existían quedan como confirmados
        }

        // v20: celulares suscritos a los avisos de venta (notificaciones push)
        if (!$this->hasTable('push_subscriptions')) {
            $this->db->exec("CREATE TABLE push_subscriptions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                endpoint_hash CHAR(64) NOT NULL UNIQUE,
                endpoint TEXT NOT NULL,
                admin_email VARCHAR(190) NOT NULL,
                user_agent VARCHAR(255) NULL,
                fails SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                last_ok DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $done[] = 'avisos push';
        }

        $this->db->prepare("REPLACE INTO settings (k, v) VALUES ('schema_version', ?)")->execute([(string) self::VERSION]);
        if ($done) {
            error_log('[migrator] aplicado: ' . implode(', ', $done));
        }
        return $done;
    }

    /**
     * Los textos de ejemplo que traen los .sql están escritos para Colombia.
     * En una instalación de otro país se adaptan una sola vez (luego los editas en el panel).
     */
    private function localizeTexts(): void
    {
        $code = Country::code();
        $done = (string) $this->db->query("SELECT v FROM settings WHERE k = 'texts_country'")->fetchColumn();
        if ($code === 'CO' || $done === $code) {
            if ($done === '') {
                $this->db->prepare("REPLACE INTO settings (k, v) VALUES ('texts_country', ?)")->execute([$code]);
            }
            return;
        }
        $name = Country::name();
        $pia = Country::get('pia_methods');
        $sql = "REPLACE(REPLACE(REPLACE(%s, 'Nequi, Daviplata o transferencia', ?), 'toda Colombia', ?), 'de Colombia', ?)";
        $args = [$pia, "toda {$name}", "de {$name}"];
        $this->db->prepare('UPDATE settings SET v = ' . sprintf($sql, 'v') . " WHERE v LIKE '%Colombia%' OR v LIKE '%Nequi%'")->execute($args);
        if ($this->hasTable('banners')) {
            $this->db->prepare('UPDATE banners SET text = ' . sprintf($sql, 'text') . " WHERE text LIKE '%Colombia%'")->execute($args);
        }
        // El WhatsApp de ejemplo es colombiano: en otro país se deja vacío para poner el propio
        $this->db->prepare("UPDATE settings SET v = '' WHERE k = 'whatsapp' AND v LIKE '57%' AND ? <> '57'")->execute([Country::get('phone_code')]);
        $this->db->prepare("REPLACE INTO settings (k, v) VALUES ('texts_country', ?)")->execute([$code]);
    }

    private function ensureCategory(?string $parentSlug, string $slug, string $name, string $h1, int $sort): void
    {
        $parentId = null;
        if ($parentSlug) {
            $st = $this->db->prepare('SELECT id FROM categories WHERE slug = ? AND parent_id IS NULL');
            $st->execute([$parentSlug]);
            $parentId = $st->fetchColumn() ?: null;
            if (!$parentId) return;
        }
        $st = $this->db->prepare('SELECT 1 FROM categories WHERE slug = ? AND parent_id ' . ($parentId ? '= ?' : 'IS NULL'));
        $st->execute($parentId ? [$slug, $parentId] : [$slug]);
        if (!$st->fetchColumn()) {
            $this->db->prepare('INSERT INTO categories (parent_id, slug, name, h1, sort) VALUES (?, ?, ?, ?, ?)')->execute([$parentId, $slug, $name, $h1, $sort]);
        }
    }

    private function runFile(string $file): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__) . "/sql/{$file}");
        $sql = preg_replace('/^\s*--.*$/m', '', $sql); // quitar comentarios de línea
        foreach (preg_split('/;\s*(\R|$)/', $sql) as $stmt) {
            if (trim($stmt) !== '') {
                $this->db->exec($stmt);
            }
        }
    }

    private function hasTable(string $t): bool
    {
        $st = $this->db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $st->execute([$t]);
        return (bool) $st->fetchColumn();
    }

    private function hasColumn(string $t, string $c): bool
    {
        $st = $this->db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$t, $c]);
        return (bool) $st->fetchColumn();
    }

    private function isNullable(string $t, string $c): bool
    {
        $st = $this->db->prepare('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$t, $c]);
        return $st->fetchColumn() === 'YES';
    }

    private function hasIndex(string $t, string $i): bool
    {
        $st = $this->db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $st->execute([$t, $i]);
        return (bool) $st->fetchColumn();
    }
}
