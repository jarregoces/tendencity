<?php
/** @var Catalog $catalog  @var ?array $pillar */
$items = $catalog->reviews($pillar ? (int) $pillar['id'] : null, 60);
$pillars = $catalog->reviewPillars();
$name = $pillar ? 'Reseñas de ' . mb_strtolower($pillar['name']) : 'Reseñas';
$meta = [
    'title' => $pillar ? "{$name}: opiniones antes de comprar | Tendencity" : 'Reseñas y opiniones de productos | Tendencity',
    'description' => 'Probamos los productos y te contamos lo bueno, lo malo y para quién vale la pena, antes de que pidas.',
    'robots' => $items ? 'index, follow' : 'noindex, follow',
    'jsonld' => [],
];
$meta['jsonld'][] = partial('crumbs', ['items' => $pillar ? [['Reseñas', '/resenas/'], [$pillar['name'], null]] : [['Reseñas', null]]]);
?>
<header class="wrap page-head">
  <h1><?= e($name) ?></h1>
  <p class="lead">Lo bueno, lo que no nos gustó y para quién sirve cada producto, para que decidas con información.</p>
</header>
<?php if ($pillars): ?>
<nav class="wrap chips chips--sub" aria-label="Temas de reseñas">
  <a class="chip<?= $pillar ? '' : ' is-on' ?>" href="/resenas/">Todas</a>
  <?php foreach ($pillars as $pl): ?>
    <a class="chip<?= ($pillar && $pillar['id'] == $pl['id']) ? ' is-on' : '' ?>" href="/resenas/<?= e($pl['slug']) ?>/"><?= e($pl['name']) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<section class="wrap section section--tight">
  <?php if ($items): ?>
    <div class="posts"><?php foreach ($items as $r) { partial('post_card', ['url' => "/resenas/{$r['cat_slug']}/{$r['slug']}/", 'title' => $r['title'], 'text' => $r['verdict'], 'rating' => $r['rating']]); } ?></div>
  <?php else: ?>
    <p class="empty">Pronto publicaremos las primeras reseñas. Mientras tanto, mira <a href="/">lo más pedido</a>.</p>
  <?php endif; ?>
</section>
<?php if ($pillar): ?>
<p class="wrap bridge">¿Ya sabes lo que buscas? <a href="/<?= e($pillar['slug']) ?>/">Ver todos los productos de <?= e(mb_strtolower($pillar['name'])) ?></a></p>
<?php endif; ?>
