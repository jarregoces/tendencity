<?php
/** @var Catalog $catalog  @var string $q */
$total = $catalog->countSearch($q);
[$pg, $pages, $off] = shop_page($total);
$results = $catalog->search($q, PER_PAGE, $off);
$meta = ['title' => ($q ? "Resultados para «{$q}»" : 'Buscar') . ' | Tendencity', 'robots' => 'noindex, follow', 'description' => 'Busca productos en Tendencity.'];
?>
<header class="wrap page-head">
  <h1><?= $q ? 'Resultados para «' . e($q) . '»' : 'Buscar productos' ?></h1>
  <?php if ($q): ?><p class="lead"><?= $total ?> <?= $total === 1 ? 'producto' : 'productos' ?><?= $pages > 1 ? " · página {$pg} de {$pages}" : '' ?></p><?php endif; ?>
</header>
<section class="wrap section section--tight">
  <?php if ($results): partial('grid', ['products' => $results]); else: ?>
    <p class="empty">No encontramos productos con esa búsqueda. Prueba con otra palabra, mira las <a href="/ofertas/">ofertas</a>.</p>
  <?php endif; ?>
</section>
<?php partial('pager', ['pg' => $pg, 'pages' => $pages, 'path' => '/buscar/', 'keep' => ['q' => $q]]); ?>
