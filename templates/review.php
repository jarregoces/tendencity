<?php
/** @var array $r  @var Catalog $catalog */
$product = $r['product_id'] ? $catalog->productById((int) $r['product_id']) : null;
$pros = lines($r['pros']); $cons = lines($r['cons']); $forWhom = lines($r['for_whom']);
$url = "/resenas/{$r['cat_slug']}/{$r['slug']}/";
$meta = [
    'title' => $r['meta_title'] ?: "{$r['title']} | Tendencity",
    'description' => $r['meta_description'] ?: mb_substr((string) $r['verdict'], 0, 160),
    'type' => 'article',
    'image' => $product ? (product_image($product) ?? site_url('/assets/img/logo.png')) : site_url('/assets/img/logo.png'),
    'jsonld' => [],
];
$meta['jsonld'][] = partial('crumbs', ['items' => [['Reseñas', '/resenas/'], [$r['cat_name'], "/resenas/{$r['cat_slug']}/"], [$r['title'], null]]]);
if ($product && $r['rating']) {
    $meta['jsonld'][] = array_filter([
        '@context' => 'https://schema.org', '@type' => 'Review',
        'name' => $r['title'], 'reviewBody' => strip_tags(md($r['verdict'] . "\n\n" . $r['body'])),
        'datePublished' => substr((string) $r['published_at'], 0, 10),
        'author' => ['@type' => 'Organization', 'name' => 'Tendencity'],
        'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (float) $r['rating'], 'bestRating' => 5, 'worstRating' => 1],
        'positiveNotes' => $pros ? ['@type' => 'ItemList', 'itemListElement' => array_map(fn($x, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $x], $pros, array_keys($pros))] : null,
        'negativeNotes' => $cons ? ['@type' => 'ItemList', 'itemListElement' => array_map(fn($x, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $x], $cons, array_keys($cons))] : null,
        'itemReviewed' => ['@type' => 'Product', 'name' => product_title($product), 'image' => product_image($product),
            'offers' => ['@type' => 'Offer', 'price' => (int) $product['price'], 'priceCurrency' => Country::currency(), 'url' => site_url("/p/{$product['slug']}/")]],
    ]);
}
?>
<article class="wrap-narrow article">
  <header>
    <h1><?= e($r['title']) ?></h1>
    <p class="article__meta">Actualizada el <?= e(date('d/m/Y', strtotime($r['updated_at']))) ?></p>
  </header>

  <?php if ($r['verdict'] || $r['rating']): ?>
  <section class="verdict" aria-label="Veredicto">
    <?php if ($r['rating']): ?><p class="verdict__score"><?= e(number_format((float) $r['rating'], 1, ',', '')) ?><small> / 5</small></p><?php endif; ?>
    <?php if ($r['verdict']): ?><p class="verdict__text"><?= e($r['verdict']) ?></p><?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($pros || $cons): ?>
  <div class="proscons">
    <?php if ($pros): ?><section><h2>Lo que nos gustó</h2><ul><?php foreach ($pros as $x): ?><li><?= md_inline($x) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
    <?php if ($cons): ?><section><h2>Lo que no</h2><ul><?php foreach ($cons as $x): ?><li><?= md_inline($x) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="prose"><?= md($r['body']) ?></div>

  <?php if ($forWhom): ?>
    <h2>¿Para quién vale la pena?</h2>
    <ul class="forwhom"><?php foreach ($forWhom as $x): ?><li><?= md_inline($x) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>

  <?php if ($product): ?>
  <aside class="buybox">
    <?php if ($img = product_image($product)): ?><img src="<?= e($img) ?>" alt="<?= e(product_title($product)) ?>" width="96" height="96" loading="lazy"><?php endif; ?>
    <div>
      <p class="buybox__name"><?= e(product_title($product)) ?></p>
      <p class="buybox__price"><?= money($product['price']) ?></p>
    </div>
    <a class="btn btn--grad" href="/p/<?= e($product['slug']) ?>/">Ver producto y pedir</a>
  </aside>
  <?php endif; ?>

  <p class="bridge">Más productos de <a href="/<?= e($r['cat_slug']) ?>/"><?= e(mb_strtolower($r['cat_name'])) ?></a> · Más <a href="/resenas/<?= e($r['cat_slug']) ?>/">reseñas de <?= e(mb_strtolower($r['cat_name'])) ?></a></p>
</article>
