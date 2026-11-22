<?php
/** @var array $cat  @var Catalog $catalog */
$isPillar = $cat['parent_id'] === null;
$pillar = $isPillar ? $cat : $cat['parent'];
$url = Catalog::categoryUrl($cat);
$total = $catalog->countIn($cat);
[$pg, $pages, $off] = shop_page($total);
$products = $catalog->productsIn($cat, PER_PAGE, $off);
$subs = $catalog->children((int) $pillar['id']);         // pilar: sus hijas · sub: sus hermanas
$faq = faq_list($cat['faq']);
$reviewsHere = $catalog->reviews((int) $pillar['id'], 3);   // puente temático al silo de reseñas

$meta = [
    'title' => ($cat['meta_title'] ?: "{$cat['name']} con pago contraentrega | Tendencity") . ($pg > 1 ? " · Página {$pg}" : ''),
    'description' => ($cat['meta_description'] ?: "Compra {$cat['name']} con envío a toda Colombia y paga al recibir. {$total} productos disponibles.") . ($pg > 1 ? " Página {$pg} de {$pages}." : ''),
    // Cada página es su propia URL canónica (la 1 sin parámetro)
    'canonical' => page_url($url, $pg),
    // Sin productos no se indexa: evita páginas delgadas
    'robots' => $products ? 'index, follow' : 'noindex, follow',
    'jsonld' => [],
];
$crumbs = $isPillar ? [[$cat['name'], null]] : [[$pillar['name'], "/{$pillar['slug']}/"], [$cat['name'], null]];
$meta['jsonld'][] = partial('crumbs', ['items' => $crumbs]);
$meta['jsonld'][] = [
    '@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $cat['h1'] ?: $cat['name'], 'url' => site_url($url),
    'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => array_map(fn($p, $i) => [
        '@type' => 'ListItem', 'position' => $i + 1, 'url' => site_url("/p/{$p['slug']}/")], $products, array_keys($products))],
];
if ($pg === 1 && ($s = faq_schema($faq))) { $meta['jsonld'][] = $s; }
?>
<header class="wrap page-head">
  <h1><?= e($cat['h1'] ?: $cat['name']) ?></h1>
  <?php if ($cat['intro']): ?><p class="lead"><?= e($cat['intro']) ?></p><?php endif; ?>
</header>

<?php if ($subs): ?>
<nav class="wrap chips chips--sub" aria-label="Subcategorías de <?= e($pillar['name']) ?>">
  <a class="chip<?= $isPillar ? ' is-on' : '' ?>" href="/<?= e($pillar['slug']) ?>/">Todo</a>
  <?php foreach ($subs as $s): ?>
    <a class="chip<?= (!$isPillar && $s['id'] == $cat['id']) ? ' is-on' : '' ?>" href="/<?= e($pillar['slug']) ?>/<?= e($s['slug']) ?>/"><?= e($s['name']) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<section class="wrap section section--tight"><?php partial('grid', ['products' => $products]); ?></section>
<?php partial('pager', ['pg' => $pg, 'pages' => $pages, 'path' => $url]); ?>

<?php if ($pg > 1) return; /* el texto SEO, las preguntas y las reseñas solo en la página 1 */ ?>
<?php if ($cat['seo_text']): ?><section class="prose wrap-narrow"><?= md($cat['seo_text']) ?></section><?php endif; ?>

<?php partial('faq', ['faq' => $faq, 'title' => "Preguntas sobre {$cat['name']}"]); ?>

<?php if ($reviewsHere): ?>
<section class="wrap section">
  <div class="section__head"><h2>Reseñas de <?= e(mb_strtolower($pillar['name'])) ?></h2><a href="/resenas/<?= e($pillar['slug']) ?>/">Ver todas</a></div>
  <div class="posts"><?php foreach ($reviewsHere as $r) { partial('post_card', ['url' => "/resenas/{$r['cat_slug']}/{$r['slug']}/", 'title' => $r['title'], 'text' => $r['verdict'], 'rating' => $r['rating']]); } ?></div>
</section>
<?php endif; ?>
