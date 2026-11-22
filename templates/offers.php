<?php
/** @var Catalog $catalog — página de navegación: noindex, follow */
$total = $catalog->countOffers();
[$pg, $pages, $off] = shop_page($total);
$meta = ['title' => 'Ofertas | Tendencity' . ($pg > 1 ? " · Página {$pg}" : ''), 'description' => 'Productos con descuento por tiempo limitado.', 'robots' => 'noindex, follow'];
?>
<header class="wrap page-head"><h1>Ofertas</h1><p class="lead">Productos con descuento ahora mismo. Los precios anteriores son reales.</p></header>
<section class="wrap section section--tight"><?php partial('grid', ['products' => $catalog->onOffer(PER_PAGE, $off)]); ?></section>
<?php partial('pager', ['pg' => $pg, 'pages' => $pages, 'path' => '/ofertas/']); ?>
