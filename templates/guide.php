<?php
/** @var array $g  @var Catalog $catalog */
$cat = $g['category_id'] ? $catalog->category((int) $g['category_id']) : null;
$products = $catalog->productsByIds(explode(',', (string) $g['product_ids']));
$meta = [
    'title' => $g['meta_title'] ?: "{$g['title']} | Tendencity",
    'description' => $g['meta_description'] ?: (string) $g['excerpt'],
    'type' => 'article',
    'jsonld' => [],
];
$meta['jsonld'][] = partial('crumbs', ['items' => [['Guías', '/guias/'], [$g['title'], null]]]);
$meta['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $g['title'],
    'datePublished' => substr((string) $g['published_at'], 0, 10), 'dateModified' => substr((string) $g['updated_at'], 0, 10),
    'author' => ['@type' => 'Organization', 'name' => 'Tendencity'], 'mainEntityOfPage' => site_url("/guias/{$g['slug']}/")];
?>
<article class="wrap-narrow article">
  <header><h1><?= e($g['title']) ?></h1><p class="article__meta">Actualizada el <?= e(date('d/m/Y', strtotime($g['updated_at']))) ?></p></header>
  <div class="prose"><?= md($g['body']) ?></div>
</article>
<?php if ($products): ?>
<section class="wrap section"><div class="section__head"><h2>Productos que mencionamos</h2></div><?php partial('grid', ['products' => $products]); ?></section>
<?php endif; ?>
<?php if ($cat): ?><p class="wrap bridge">Ver todos los productos de <a href="<?= e(Catalog::categoryUrl($cat)) ?>"><?= e(mb_strtolower($cat['name'])) ?></a></p><?php endif; ?>
