<?php
declare(strict_types=1);

/** /sitemap.xml — solo lo indexable: categorías con productos, productos, reseñas y guías publicadas. */
header('Content-Type: application/xml; charset=utf-8');

$urls = [[site_url('/'), date('Y-m-d')]];
$db = db();

$cats = $db->query(
    'SELECT c.id, c.slug, c.parent_id, pc.slug AS parent_slug,
            (SELECT MAX(p.updated_at) FROM products p WHERE p.is_active = 1
               AND (p.category_id = c.id OR p.category_id IN (SELECT id FROM categories WHERE parent_id = c.id))) AS last
     FROM categories c LEFT JOIN categories pc ON pc.id = c.parent_id
     WHERE c.is_active = 1'
)->fetchAll();
foreach ($cats as $c) {
    if (!$c['last']) {
        continue; // una categoría vacía no se manda a Google
    }
    $path = $c['parent_slug'] ? "/{$c['parent_slug']}/{$c['slug']}/" : "/{$c['slug']}/";
    $urls[] = [site_url($path), substr($c['last'], 0, 10)];
}

foreach ($db->query('SELECT slug, updated_at FROM products WHERE is_active = 1') as $p) {
    $urls[] = [site_url("/p/{$p['slug']}/"), substr($p['updated_at'], 0, 10)];
}

$reviews = $db->query(
    'SELECT r.slug, r.updated_at, c.slug cat FROM reviews r JOIN categories c ON c.id = r.category_id WHERE r.is_published = 1'
)->fetchAll();
if ($reviews) {
    $urls[] = [site_url('/resenas/'), substr(max(array_column($reviews, 'updated_at')), 0, 10)];
    $seenPillars = [];
    foreach ($reviews as $r) {
        if (!isset($seenPillars[$r['cat']])) {
            $urls[] = [site_url("/resenas/{$r['cat']}/"), substr($r['updated_at'], 0, 10)];
            $seenPillars[$r['cat']] = true;
        }
        $urls[] = [site_url("/resenas/{$r['cat']}/{$r['slug']}/"), substr($r['updated_at'], 0, 10)];
    }
}

$guides = $db->query('SELECT slug, updated_at FROM guides WHERE is_published = 1')->fetchAll();
if ($guides) {
    $urls[] = [site_url('/guias/'), substr(max(array_column($guides, 'updated_at')), 0, 10)];
    foreach ($guides as $g) {
        $urls[] = [site_url("/guias/{$g['slug']}/"), substr($g['updated_at'], 0, 10)];
    }
}

if (setting('black_friday_active') === '1') {
    $urls[] = [site_url('/black-friday/'), date('Y-m-d')];
}
foreach (['envios-y-pagos', 'garantias-y-devoluciones', 'contacto'] as $s) {
    $urls[] = [site_url("/{$s}/"), null];
}

echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach ($urls as [$loc, $mod]) {
    echo '  <url><loc>', e($loc), '</loc>', $mod ? "<lastmod>{$mod}</lastmod>" : '', "</url>\n";
}
echo '</urlset>';
