<?php
/** @var array $items [[nombre, url|null], ...]  — el último es la página actual */
$all = array_merge([['Inicio', '/']], $items);
?>
<nav class="crumbs wrap" aria-label="Ruta de navegación">
  <?php foreach ($all as $i => [$name, $url]): ?>
    <?php if ($i < count($all) - 1 && $url): ?><a href="<?= e($url) ?>"><?= e($name) ?></a><span aria-hidden="true">/</span>
    <?php else: ?><span aria-current="page"><?= e($name) ?></span><?php endif; ?>
  <?php endforeach; ?>
</nav>
<?php
// Devuelve el schema para que la plantilla lo agregue a $meta['jsonld']
return [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => array_map(fn($it, $i) => array_filter([
        '@type' => 'ListItem', 'position' => $i + 1, 'name' => $it[0], 'item' => $it[1] ? site_url($it[1]) : null,
    ]), $all, array_keys($all)),
];
