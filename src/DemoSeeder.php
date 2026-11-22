<?php
declare(strict_types=1);

/**
 * 5 productos de prueba con fotos, contenido AIDA, ofertas y una reseña.
 * Usan IDs de Mastershop falsos (990000001…): los pedidos de estos productos
 * se guardan pero NUNCA se envían a Mastershop (ver OrderService::pushToMastershop).
 */
final class DemoSeeder
{
    public const ID_FROM = 990000000;

    public static function isDemo(int $msProductId): bool
    {
        return $msProductId > self::ID_FROM;
    }

    public function __construct(private PDO $db) {}

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM products WHERE ms_product_id > ' . self::ID_FROM)->fetchColumn();
    }

    public function seed(): int
    {
        $this->remove();
        $n = 0;
        foreach ($this->data() as $i => $p) {
            $msId = self::ID_FROM + $i + 1;
            $catId = $this->category($p['cat']);
            $imgs = array_map(fn($k) => "/assets/demo/{$p['img']}-{$k}.webp", [1, 2, 3]);
            $this->db->prepare(
                'INSERT INTO products (ms_product_id, category_id, name, title, slug, description, category, price, cost, stock,
                    weight, images, custom_images, is_active, price_locked, price_before, offer_until, aida_headline, aida_hook,
                    aida_benefits, aida_desire, aida_for_whom, aida_guarantee, aida_urgency, specs, faq, allow_cod, allow_pia,
                    pia_discount, qty_offers, badge, is_featured, meta_title, meta_description, sort, synced_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                $msId, $catId, $p['title'], $p['title'], $p['slug'], $p['hook'], $p['cat'], $p['price'], $p['cost'], $p['stock'],
                $p['weight'], json_encode($imgs), json_encode($imgs), $p['before'] ?? null,
                isset($p['before']) ? date('Y-m-d 23:59:59', strtotime('+20 days')) : null,
                $p['headline'], $p['hook'], implode("\n", $p['benefits']), $p['desire'], implode("\n", $p['for']),
                $p['guarantee'], $p['urgency'], implode("\n", $p['specs']),
                json_encode(array_map(fn($f) => ['q' => $f[0], 'a' => $f[1]], $p['faq']), JSON_UNESCAPED_UNICODE),
                $p['pia'] ? 1 : 0, $p['pia'], json_encode($p['offers'], JSON_UNESCAPED_UNICODE), $p['badge'] ?? null,
                $p['featured'] ? 1 : 0, $p['meta_title'], $p['meta_description'], $i,
            ]);
            $pid = (int) $this->db->lastInsertId();
            $variants = $p['variants'] ?? [['Única', null]];
            foreach ($variants as $j => [$vname, $vprice]) {
                $this->db->prepare('INSERT INTO product_variants (product_id, ms_variant_id, sku, name, price, stock, weight) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$pid, $msId * 10 + $j, 'DEMO-' . ($i + 1) . '-' . ($j + 1), count($variants) > 1 ? $vname : null, $vprice, $p['stock'], $p['weight']]);
            }
            if (!empty($p['review'])) {
                $pillar = (int) $this->db->query('SELECT COALESCE(parent_id, id) FROM categories WHERE id = ' . $catId)->fetchColumn();
                $r = $p['review'];
                $this->db->prepare('INSERT INTO reviews (category_id, product_id, slug, title, meta_description, rating, verdict, pros, cons, for_whom, body, is_published, published_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())')
                    ->execute([$pillar, $pid, $r['slug'], $r['title'], $r['verdict'], $r['rating'], $r['verdict'],
                        implode("\n", $r['pros']), implode("\n", $r['cons']), implode("\n", $r['for']), $r['body']]);
            }
            $n++;
        }
        return $n;
    }

    public function remove(): int
    {
        $ids = $this->db->query('SELECT id FROM products WHERE ms_product_id > ' . self::ID_FROM)->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_map('intval', $ids));
        $this->db->exec("DELETE FROM reviews WHERE product_id IN ({$in})");
        $this->db->exec("DELETE FROM products WHERE id IN ({$in})"); // variantes se borran en cascada
        $this->db->exec("DELETE FROM redirects WHERE to_path LIKE '/p/%' AND to_path NOT IN (SELECT CONCAT('/p/', slug, '/') FROM products)");
        if ((int) setting('hero_product_id', '0') && !$this->db->query('SELECT 1 FROM products WHERE id = ' . (int) setting('hero_product_id'))->fetch()) {
            $this->db->exec("UPDATE settings SET v = '' WHERE k = 'hero_product_id'");
        }
        return count($ids);
    }

    /** Busca la categoría por ruta; si no existe (la borraste o renombraste), crea la que haga falta. */
    private function category(string $path): int
    {
        [$pillar, $sub] = array_pad(explode('/', $path), 2, null);
        $names = ['hogar-y-cocina' => 'Hogar y cocina', 'tecnologia' => 'Tecnología', 'salud-y-bienestar' => 'Salud y bienestar',
                  'organizadores' => 'Organizadores', 'cocina' => 'Cocina', 'audio' => 'Audio', 'power-banks-y-cargadores' => 'Power banks y cargadores'];
        $find = function (?int $parent, string $slug) use ($names): int {
            $st = $this->db->prepare('SELECT id FROM categories WHERE slug = ? AND parent_id ' . ($parent ? '= ?' : 'IS NULL'));
            $st->execute($parent ? [$slug, $parent] : [$slug]);
            if ($id = $st->fetchColumn()) {
                return (int) $id;
            }
            $this->db->prepare('INSERT INTO categories (parent_id, slug, name) VALUES (?, ?, ?)')->execute([$parent, $slug, $names[$slug] ?? ucfirst($slug)]);
            return (int) $this->db->lastInsertId();
        };
        $pid = $find(null, $pillar);
        return $sub ? $find($pid, $sub) : $pid;
    }

    private function data(): array
    {
        return [
            [
                'slug' => 'mesa-organizadora-3-cajones', 'img' => 'mesa', 'cat' => 'hogar-y-cocina/organizadores',
                'title' => 'Mesa organizadora de 3 cajones con ruedas', 'price' => 89900, 'before' => 119900, 'cost' => 48000,
                'stock' => 40, 'weight' => 2.4, 'badge' => 'Más vendido', 'featured' => true, 'pia' => 5,
                'meta_title' => 'Mesa organizadora 3 cajones con ruedas | Paga al recibir',
                'meta_description' => 'Mesa organizadora de 3 cajones con ruedas para baño, cocina o habitación. Se arma sin herramientas. Envío a toda Colombia y pago contraentrega.',
                'headline' => 'El rincón desordenado de tu casa, resuelto en 10 minutos',
                'hook' => 'Tres cajones con ruedas que caben al lado de la nevera, el lavamanos o el escritorio.',
                'benefits' => ['Ganas espacio sin hacer huecos en la pared', 'Se arma sin herramientas en 10 minutos',
                               'Ruedas con freno: la mueves y la dejas fija', 'Cajones translúcidos: ves todo sin abrir'],
                'desire' => "## Cómo la usan nuestros clientes\nEn la cocina guardan especias, bolsas y paños. En el baño, toallas y productos de aseo. En la habitación, maquillaje, cargadores o papelería.\n\n- Mide 30 cm de ancho: entra en espacios angostos\n- Cada cajón aguanta hasta 3 kg\n- Se limpia con un trapo húmedo",
                'for' => ['Vives en apartamento y te falta espacio', 'Quieres ordenar el baño sin remodelar', 'Necesitas una mesa auxiliar que se pueda mover'],
                'guarantee' => 'Garantía de 30 días por defectos de fábrica', 'urgency' => 'Precio de lanzamiento por tiempo limitado',
                'specs' => ['Material: plástico PP y estructura metálica', 'Medidas: 30 x 40 x 65 cm', 'Peso: 2,4 kg', 'Color: blanco'],
                'faq' => [['¿Viene armada?', 'No. Se arma en unos 10 minutos y no necesitas herramientas.'],
                          ['¿Puedo pagar al recibir?', 'Sí. Pagas en efectivo cuando te llega.'],
                          ['¿Cuánto peso aguanta?', 'Hasta 3 kg por cajón y 5 kg en la superficie de arriba.']],
                'offers' => [['qty' => 2, 'pct' => 10, 'label' => 'Más vendido'], ['qty' => 3, 'pct' => 15, 'label' => '']],
            ],
            [
                'slug' => 'organizador-tapas-sartenes', 'img' => 'tapas', 'cat' => 'hogar-y-cocina/cocina',
                'title' => 'Organizador de tapas y sartenes ajustable', 'price' => 59900, 'cost' => 26000,
                'stock' => 35, 'weight' => 0.9, 'badge' => 'Nuevo', 'featured' => false, 'pia' => 0,
                'meta_title' => 'Organizador de tapas y sartenes ajustable | Tendencity',
                'meta_description' => 'Organizador de tapas y sartenes con divisiones ajustables. Libera el gabinete de la cocina. Envío a toda Colombia, pagas al recibir.',
                'headline' => 'Abre el gabinete y encuentra la tapa a la primera',
                'hook' => 'Divisiones ajustables para tapas, sartenes y tablas de picar, en vertical.',
                'benefits' => ['Las tapas dejan de caerse cada vez que abres el gabinete', 'Mueves las divisiones según el tamaño de tus ollas', 'Base antideslizante que no raya'],
                'desire' => "## Qué cambia en tu cocina\nGuardar en vertical ocupa la mitad del espacio. Ves todas las tapas de un vistazo y sacas la que necesitas sin desarmar la pila.",
                'for' => ['Tienes el gabinete lleno de tapas sueltas', 'Tu cocina es pequeña', 'Te gusta tener cada cosa en su sitio'],
                'guarantee' => 'Garantía de 30 días por defectos de fábrica', 'urgency' => '',
                'specs' => ['Material: acero con pintura epóxica', 'Medidas: 40 x 22 x 18 cm', 'Divisiones: 5 ajustables'],
                'faq' => [['¿Cabe dentro del gabinete?', 'Sí, mide 40 cm de largo y 18 cm de alto.'],
                          ['¿Sirve para sartenes grandes?', 'Sí, separando las divisiones caben sartenes de hasta 30 cm.']],
                'offers' => [['qty' => 2, 'pct' => 10, 'label' => 'Para cocina y alacena']],
            ],
            [
                'slug' => 'power-bank-20000mah-carga-rapida', 'img' => 'powerbank', 'cat' => 'tecnologia/power-banks-y-cargadores',
                'title' => 'Power bank 20.000 mAh con carga rápida', 'price' => 99900, 'cost' => 52000,
                'stock' => 50, 'weight' => 0.4, 'badge' => null, 'featured' => true, 'pia' => 5,
                'variants' => [['Negro', null], ['Blanco', null]],
                'meta_title' => 'Power bank 20.000 mAh carga rápida | Paga al recibir',
                'meta_description' => 'Power bank de 20.000 mAh con carga rápida y pantalla de porcentaje. Carga tu celular hasta 4 veces. Envío a toda Colombia, pago contraentrega.',
                'headline' => 'Nunca más con el celular muerto a mitad del día',
                'hook' => 'Hasta 4 cargas completas, pantalla con el porcentaje exacto y dos salidas para cargar dos equipos a la vez.',
                'benefits' => ['Carga un celular hasta 4 veces', 'Carga rápida de 22,5 W', 'Pantalla que muestra la batería restante', 'Dos salidas: celular y audífonos al tiempo'],
                'desire' => "## Para el día a día\nLo llevas en el bolso o la maleta del carro. Sirve en viajes largos, en la universidad y en los cortes de luz.\n\n- Trae cable tipo C\n- Se recarga completo en unas 5 horas",
                'for' => ['Usas mucho el celular fuera de casa', 'Viajas en bus o en carro con frecuencia', 'Trabajas en la calle o haces domicilios'],
                'guarantee' => 'Garantía de 3 meses', 'urgency' => '',
                'specs' => ['Capacidad: 20.000 mAh', 'Potencia: 22,5 W', 'Salidas: USB-A y USB-C', 'Peso: 400 g'],
                'faq' => [['¿Sirve para iPhone?', 'Sí. Usas el cable de tu iPhone en la salida USB.'],
                          ['¿Se puede llevar en el avión?', 'Sí, en el equipaje de mano. 20.000 mAh está dentro del límite permitido.']],
                'offers' => [['qty' => 2, 'pct' => 12, 'label' => 'Uno para ti, otro para regalar']],
                'review' => [
                    'slug' => 'power-bank-20000mah-opiniones', 'title' => 'Power bank 20.000 mAh: ¿vale la pena? Opinión honesta', 'rating' => 4.3,
                    'verdict' => 'Cumple lo que promete en capacidad y carga rápida; su punto débil es el peso si lo llevas en el bolsillo.',
                    'pros' => ['Rinde unas 4 cargas reales', 'La pantalla de porcentaje es muy práctica', 'Carga dos equipos al tiempo'],
                    'cons' => ['Pesa 400 g: incómodo en el bolsillo', 'Tarda unas 5 horas en recargarse completo'],
                    'for' => ['Quien pasa el día fuera de casa', 'Viajeros'],
                    'body' => "## Cómo lo probamos\nLo usamos dos semanas con un celular de gama media. Desde el 100 % logró cuatro cargas completas antes de agotarse.\n\n## Carga rápida\nCon un cable tipo C compatible, el celular pasó del 10 % al 50 % en unos 30 minutos.",
                ],
            ],
            [
                'slug' => 'audifonos-inalambricos-estuche', 'img' => 'audifonos', 'cat' => 'tecnologia/audio',
                'title' => 'Audífonos inalámbricos con estuche de carga', 'price' => 69900, 'before' => 89900, 'cost' => 29000,
                'stock' => 60, 'weight' => 0.15, 'badge' => null, 'featured' => false, 'pia' => 0,
                'meta_title' => 'Audífonos inalámbricos con estuche de carga | Tendencity',
                'meta_description' => 'Audífonos inalámbricos Bluetooth 5.3 con estuche de carga y hasta 20 horas de uso. Envío a toda Colombia y pago contraentrega.',
                'headline' => 'Tu música sin cables, todo el día',
                'hook' => 'Bluetooth 5.3, controles táctiles y un estuche que los recarga hasta 4 veces.',
                'benefits' => ['Se conectan solos al abrir el estuche', 'Hasta 20 horas con el estuche', 'Micrófono para llamadas', 'Livianos: no se sienten en el oído'],
                'desire' => "## Para el gimnasio, el bus y la oficina\nTocas una vez para pausar y dos para pasar canción. Funcionan con Android y iPhone.",
                'for' => ['Haces ejercicio con música', 'Te mueves en transporte público', 'Haces llamadas largas'],
                'guarantee' => 'Garantía de 3 meses', 'urgency' => 'Precio de oferta por pocos días',
                'specs' => ['Bluetooth: 5.3', 'Batería: 5 h por carga, 20 h con estuche', 'Carga: USB-C'],
                'faq' => [['¿Sirven con iPhone?', 'Sí, se conectan con cualquier celular con Bluetooth.'],
                          ['¿Se puede usar solo uno?', 'Sí, cada audífono funciona por separado.']],
                'offers' => [['qty' => 2, 'pct' => 10, 'label' => '']],
            ],
            [
                'slug' => 'masajeador-cuello-electrico', 'img' => 'masajeador', 'cat' => 'salud-y-bienestar',
                'title' => 'Masajeador de cuello eléctrico con calor', 'price' => 129900, 'cost' => 64000,
                'stock' => 20, 'weight' => 0.3, 'badge' => 'Tendencia', 'featured' => true, 'pia' => 5,
                'meta_title' => 'Masajeador de cuello eléctrico con calor | Paga al recibir',
                'meta_description' => 'Masajeador de cuello eléctrico con calor y 6 modos. Recargable y portátil. Envío a toda Colombia y pago contraentrega.',
                'headline' => 'Quince minutos para soltar la tensión del cuello',
                'hook' => 'Se pone como un collar, tiene 6 modos de masaje y calor suave. Recargable, sin cables mientras lo usas.',
                'benefits' => ['6 modos y 9 niveles de intensidad', 'Calor suave que relaja el músculo', 'Se apaga solo a los 15 minutos', 'Recargable por USB'],
                'desire' => "## Cuándo lo usan\nDespués de un día frente al computador, al llegar de manejar o antes de dormir. Lo puedes usar mientras ves televisión o lees.",
                'for' => ['Trabajas muchas horas sentado', 'Manejas a diario', 'Quieres un regalo útil'],
                'guarantee' => 'Garantía de 3 meses', 'urgency' => 'Quedan pocas unidades este mes',
                'specs' => ['Modos: 6', 'Intensidad: 9 niveles', 'Batería: 2 h de uso', 'Carga: USB'],
                'faq' => [['¿Lo puede usar cualquier persona?', 'Si tienes marcapasos, estás embarazada o tienes una condición médica, consulta a tu médico antes de usarlo.'],
                          ['¿Se puede usar en otras partes del cuerpo?', 'Sí, en hombros, espalda baja y pantorrillas.']],
                'offers' => [['qty' => 2, 'pct' => 10, 'label' => 'Para regalar']],
            ],
        ];
    }
}
