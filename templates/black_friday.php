<?php
/** @var Catalog $catalog — URL fija que se reutiliza cada año */
$active = setting('black_friday_active') === '1';
$year = date('n') >= 2 ? date('Y') : date('Y') - 1;
$meta = [
    'title' => "Black Friday {$year} en Colombia: descuentos con pago contraentrega | Tendencity",
    'description' => 'Los descuentos de Black Friday en Tendencity: envío a toda Colombia y pagas al recibir.',
    'jsonld' => [partial('crumbs', ['items' => [['Black Friday', null]]])],
];
?>
<section class="bf">
  <div class="wrap">
    <h1>Black Friday <?= $year ?></h1>
    <p class="lead"><?= e(setting('black_friday_text') ?: ($active ? 'Descuentos reales en productos seleccionados, con envío a toda Colombia y pago al recibir.' : 'Nuestro Black Friday todavía no arranca. Mientras tanto, estas son las ofertas activas.')) ?></p>
  </div>
</section>
<section class="wrap section"><?php partial('grid', ['products' => $catalog->onOffer()]); ?></section>
