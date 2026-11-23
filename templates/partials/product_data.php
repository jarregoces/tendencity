<?php
/* Datos de la ficha compartidos entre la ficha normal y la landing de venta. */
/** @var array $p  @var Catalog $catalog */
$title    = product_title($p);
$images   = product_images($p);
$gallery  = product_media($p); // galería de la ficha: fotos + mini videos
$cat      = $p['category_id'] ? $catalog->category((int) $p['category_id']) : null;
$variants = $catalog->variants((int) $p['id']);
// Las fotos de cada color entran a la galería (si no están ya)
foreach ($variants as $v) {
    if (!empty($v['image']) && !in_array($v['image'], $images, true)) { $images[] = $v['image']; }
    if (!empty($v['image']) && !in_array($v['image'], $gallery, true)) { $gallery[] = $v['image']; }
}
$colorVariants = array_values(array_filter($variants, fn($v) => $v['name'] !== null && $v['name'] !== ''));
$swatch = function (string $name): ?string {
    $n = mb_strtolower($name);
    foreach (['rosa' => '#e8b4a0', 'oro' => '#d4af37', 'dorad' => '#d4af37', 'plata' => '#c9ccd1', 'plate' => '#c9ccd1', 'negro' => '#1b1b1b',
              'blanco' => '#ffffff', 'azul' => '#3b6fd8', 'rojo' => '#d23b3b', 'verde' => '#3ba55d', 'gris' => '#8a8f98', 'cafe' => '#7a5230', 'café' => '#7a5230'] as $k => $hex) {
        if (str_contains($n, $k)) return $hex;
    }
    return null;
};
$boxItems = array_map(function ($l) { return preg_match('/^(\d+)\s*[x×]?\s*(.+)$/u', $l, $m) ? [$m[1], $m[2]] : ['', $l]; }, lines($p['box_contents'] ?? ''));
$cmp = array_map(fn($l) => array_map('trim', explode('|', $l)), lines($p['comparison'] ?? ''));
$related  = $catalog->related($p);
$review   = $catalog->reviewForProduct((int) $p['id']);
$faq      = faq_list($p['faq']);
$benefits = lines($p['aida_benefits']);
$forWhom  = lines($p['aida_for_whom']);
$specs    = pairs($p['specs']);
$offPct   = Pricing::offerPct($p);
$inStock  = (int) $p['stock'] > 0;
$url      = "/p/{$p['slug']}/";
$desc     = $p['meta_description'] ?: mb_substr(trim(strip_tags($p['aida_hook'] ?: (string) $p['description'])), 0, 160);

$meta = [
    'title'       => $p['meta_title'] ?: "{$title} | Pago contraentrega | Tendencity",
    'description' => $desc,
    'type'        => 'product',
    'image'       => $images[0] ?? site_url('/assets/img/logo.png'),
    'jsonld'      => [],
];

// Migas: el producto sube SOLO a su categoría principal (regla del silo)
$crumbs = [];
if ($cat) {
    if (!empty($cat['parent'])) { $crumbs[] = [$cat['parent']['name'], "/{$cat['parent']['slug']}/"]; }
    $crumbs[] = [$cat['name'], Catalog::categoryUrl($cat)];
}
$crumbs[] = [$title, null];

$offer = array_filter([
    '@type'         => 'Offer',
    'url'           => site_url($url),
    'priceCurrency' => Country::currency(),
    'price'         => (int) $p['price'],
    'availability'  => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
    'itemCondition' => 'https://schema.org/NewCondition',
    'priceValidUntil' => ($offPct && $p['offer_until']) ? date('Y-m-d', strtotime($p['offer_until'])) : null,
    'seller'        => ['@type' => 'Organization', 'name' => 'Tendencity'],
    'shippingDetails' => ['@type' => 'OfferShippingDetails',
        'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => Country::code()],
        'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => (int) setting('shipping_flat', '0'), 'currency' => Country::currency()]],
]);
$meta['jsonld'][] = array_filter([
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    'name'        => $title,
    'image'       => array_slice($images, 0, 6) ?: null,
    'description' => $desc,
    'sku'         => $variants[0]['sku'] ?? ($p['sku'] ?: (string) ($p['ms_product_id'] ?: $p['id'])),
    'category'    => $cat['name'] ?? null,
    'brand'       => $p['brand'] ? ['@type' => 'Brand', 'name' => $p['brand']] : null,
    'offers'      => $offer,
]);
// Modo enfocado (productos que se pautan): sin menú, buscador, migas ni relacionados
$focus = !empty($p['focus_mode']);
if ($focus) { ob_start(); }
$meta['jsonld'][] = partial('crumbs', ['items' => $crumbs]);   // el schema de migas se mantiene para Google
if ($focus) { ob_end_clean(); }
if ($s = faq_schema($faq)) { $meta['jsonld'][] = $s; }

// Píxeles: ViewContent (Meta / TikTok) y view_item (GA4)
$meta['events'] = [['ViewContent', [
    'content_ids' => [(string) ($p['sku'] ?: ($p['ms_product_id'] ?: $p['id']))], 'content_type' => 'product', 'content_name' => $title,
    'value' => (int) $p['price'], 'currency' => Country::currency(),
    'items' => [['item_id' => (string) ($p['sku'] ?: ($p['ms_product_id'] ?: $p['id'])), 'item_name' => $title, 'item_category' => $cat['name'] ?? null, 'price' => (int) $p['price']]],
]]];

$ctaLabel = $p['allow_cod'] ? 'Pedir ahora, pagas al recibir' : 'Pedir ahora';

// ---- Bloques visuales (v14)
$splitRow = fn(string $l) => array_pad(array_map('trim', explode('|', $l, 3)), 3, '');
$measures = pairs($p['measures'] ?? '');
$featuresMedia = array_map($splitRow, lines($p['features_media'] ?? ''));
$usageSteps = array_map($splitRow, lines($p['usage_steps'] ?? ''));
$cmpImgs = array_values(array_filter(array_map('trim', explode('|', (string) ($p['cmp_images'] ?? '')))));
$st = db()->prepare('SELECT * FROM testimonials WHERE product_id = ? AND is_published = 1 ORDER BY sort, created_at DESC LIMIT 30');
$st->execute([(int) $p['id']]);
$testimonials = $st->fetchAll();
if ($testimonials) {
    // Calificación real de clientes: Google puede mostrar las estrellas en los resultados
    $avg = round(array_sum(array_column($testimonials, 'rating')) / count($testimonials), 1);
    foreach ($meta['jsonld'] as &$ld) {
        if (($ld['@type'] ?? '') === 'Product') {
            $ld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $avg, 'reviewCount' => count($testimonials), 'bestRating' => 5, 'worstRating' => 1];
            $ld['review'] = array_map(fn($t) => ['@type' => 'Review', 'author' => ['@type' => 'Person', 'name' => $t['name']],
                'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (int) $t['rating'], 'bestRating' => 5],
                'reviewBody' => $t['text'], 'datePublished' => substr($t['created_at'], 0, 10)], array_slice($testimonials, 0, 5));
        }
    }
    unset($ld);
}

// ---- Valores por defecto de la tienda (v16): lo que el producto no tenga, lo toma de Ajustes
$p['aida_guarantee'] = $p['aida_guarantee'] ?: (setting('def_guarantee', '') ?: null);
$p['aida_urgency'] = $p['aida_urgency'] ?: (setting('def_urgency', '') ?: null);
$seenQ = array_map(fn($f) => mb_strtolower(trim($f['q'])), $faq);
foreach (lines((string) setting('def_faq', '')) as $l) {
    [$q, $a] = array_pad(array_map('trim', explode('::', $l, 2)), 2, '');
    if ($q !== '' && $a !== '' && !in_array(mb_strtolower($q), $seenQ, true)) { $faq[] = ['q' => $q, 'a' => $a]; $seenQ[] = mb_strtolower($q); }
}
// El schema FAQ se arma de nuevo con las preguntas generales incluidas
foreach ($meta['jsonld'] as $k => $ld) { if (($ld['@type'] ?? '') === 'FAQPage') { unset($meta['jsonld'][$k]); } }
if ($s = faq_schema($faq)) { $meta['jsonld'][] = $s; }
$meta['jsonld'] = array_values($meta['jsonld']);

// ---- Imágenes de la descripción (características, ingredientes, uso, comparativa…)
$bodyImages = array_values(array_filter(json_decode($p['body_images'] ?? '[]', true) ?: [], fn($i) => !empty($i['src'])));

