<?php
/** @var Catalog $catalog */
$items = $catalog->guides(60);
$meta = [
    'title' => 'Guías prácticas para el hogar, tecnología y cuidado personal | Tendencity',
    'description' => 'Consejos claros para organizar, cuidar y elegir mejor. Sin relleno.',
    'robots' => $items ? 'index, follow' : 'noindex, follow',
    'jsonld' => [partial('crumbs', ['items' => [['Guías', null]]])],
];
?>
<header class="wrap page-head"><h1>Guías</h1><p class="lead">Consejos prácticos para resolver cosas del día a día.</p></header>
<section class="wrap section section--tight">
  <?php if ($items): ?>
    <div class="posts"><?php foreach ($items as $g) { partial('post_card', ['url' => "/guias/{$g['slug']}/", 'title' => $g['title'], 'text' => $g['excerpt'], 'rating' => null]); } ?></div>
  <?php else: ?><p class="empty">Pronto publicaremos las primeras guías.</p><?php endif; ?>
</section>
