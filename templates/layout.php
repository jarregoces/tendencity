<?php
/** @var string $content  @var array $meta */
$meta = ($meta ?? []) + [
    'title'       => 'Tendencity | Tendencias con pago contraentrega en ' . Country::name() . '',
    'description' => 'Productos en tendencia para tu hogar, tecnología y cuidado personal. Envío incluido a toda ' . Country::name() . ' y pagas al recibir.',
    'canonical'   => site_url((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/')),
    'robots'      => 'index, follow',
    'image'       => site_url('/assets/img/logo.png'),
    'type'        => 'website',
    'jsonld'      => [],
];
$menu = (new Catalog(db()))->tree();
$announcement = setting('announcement');
?><!doctype html>
<html lang="<?= e(Country::get('locale')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($meta['title']) ?></title>
<meta name="description" content="<?= e($meta['description']) ?>">
<meta name="robots" content="<?= e($meta['robots']) ?>">
<link rel="canonical" href="<?= e($meta['canonical']) ?>">
<meta property="og:type" content="<?= e($meta['type']) ?>">
<meta property="og:title" content="<?= e($meta['title']) ?>">
<meta property="og:description" content="<?= e($meta['description']) ?>">
<meta property="og:url" content="<?= e($meta['canonical']) ?>">
<meta property="og:image" content="<?= e($meta['image']) ?>">
<meta property="og:locale" content="<?= e(Country::get('og_locale')) ?>">
<meta property="og:site_name" content="Tendencity">
<meta name="theme-color" content="#8E62F0">
<link rel="icon" type="image/png" href="/assets/img/favicon.png">
<link rel="apple-touch-icon" href="/assets/img/apple-touch.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600&family=Sora:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<?= json_ld(['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Tendencity',
    'url' => site_url('/'), 'logo' => site_url('/assets/img/logo.png'), 'slogan' => 'Tendencias que te definen']) ?>
<?php foreach ($meta['jsonld'] as $ld) { echo json_ld($ld); } ?>
<?= pixel_head($meta['events'] ?? []) ?>
</head>
<body>
<?= pixel_body() ?>
<a class="skip" href="#main">Saltar al contenido</a>
<div class="bar">
  <div class="wrap bar__in">
    <?php
    // Marquesina: frases separadas por · o | en Ajustes → «Texto de la barra superior»
    $phrases = array_values(array_filter(array_map('trim', preg_split('/[·|]/u', (string) ($announcement ?: '')))));
    if (!$phrases) { $phrases = ['Pago en casa', shipping_included() ? 'Envío gratis ' . Country::toAll() : 'Envíos ' . Country::toAll()]; }
    $group = '';
    for ($k = 0; $k < 4; $k++) { foreach ($phrases as $ph) { $group .= '<span class="ticker__i">' . e($ph) . '</span><span class="ticker__sep" aria-hidden="true">✦</span>'; } }
    ?>
    <div class="ticker" aria-label="<?= e(implode(' · ', $phrases)) ?>">
      <div class="ticker__track"><div class="ticker__g"><?= $group ?></div><div class="ticker__g" aria-hidden="true"><?= $group ?></div></div>
    </div>
  </div>
</div>
<?php if (!empty($focus)): ?>
<header class="top top--focus">
  <div class="wrap top__in"><span class="logo"><picture><source srcset="/assets/img/logo.webp" type="image/webp"><img src="/assets/img/logo.png" alt="Tendencity" width="180" height="37"></picture></span></div>
</header>
<?php else: ?>
<header class="top">
  <div class="wrap top__in">
    <details class="drawer">
      <summary aria-label="Abrir menú"><span></span><span></span><span></span></summary>
      <nav class="drawer__nav" aria-label="Categorías">
        <?php foreach ($menu as $pl): ?>
          <a class="drawer__pillar" href="/<?= e($pl['slug']) ?>/"><?= e($pl['name']) ?></a>
          <?php foreach ($pl['children'] as $ch): ?>
            <a class="drawer__sub" href="/<?= e($pl['slug']) ?>/<?= e($ch['slug']) ?>/"><?= e($ch['name']) ?></a>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <a class="drawer__pillar" href="/ofertas/">Ofertas</a>
        <a class="drawer__pillar" href="/resenas/">Reseñas</a>
        <a class="drawer__pillar" href="/guias/">Guías</a>
      </nav>
    </details>
    <a class="logo" href="/" aria-label="Tendencity, inicio">
      <picture><source srcset="/assets/img/logo.webp" type="image/webp"><img src="/assets/img/logo.png" alt="Tendencity" width="180" height="37"></picture>
    </a>
    <form class="search" action="/buscar/" method="get" role="search">
      <label class="sr" for="q">Buscar productos</label>
      <input id="q" name="q" type="search" placeholder="Buscar productos…" value="<?= e((string) ($_GET['q'] ?? '')) ?>" autocomplete="off">
      <button aria-label="Buscar"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" d="M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Zm5.3-2.2L21 21"/></svg></button>
    </form>
  </div>
  <nav class="nav" aria-label="Principal">
    <div class="wrap nav__in">
      <div class="allcats">
        <button type="button" class="allcats__btn" aria-expanded="false" aria-controls="allcats">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M3 5h18v2H3zm0 6h18v2H3zm0 6h18v2H3z"/></svg> Todas las categorías</button>
        <div class="allcats__drop" id="allcats">
          <?php foreach ($menu as $pl): ?>
            <div class="allcats__col">
              <a class="allcats__pillar" href="/<?= e($pl['slug']) ?>/"><?= e($pl['name']) ?></a>
              <?php foreach ($pl['children'] as $ch): ?><a href="/<?= e($pl['slug']) ?>/<?= e($ch['slug']) ?>/"><?= e($ch['name']) ?></a><?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <a href="/">Inicio</a>
      <?php foreach ($menu as $pl): ?><a href="/<?= e($pl['slug']) ?>/"><?= e($pl['name']) ?></a><?php endforeach; ?>
      <a href="/resenas/">Reseñas</a>
      <a href="/ofertas/" class="nav__offers">Ofertas relámpago</a>
    </div>
  </nav>
</header>
<?php endif; ?>

<main id="main"><?= $content ?></main>

<?php if (!empty($focus)): ?>
<footer class="foot foot--focus">
  <div class="wrap"><nav aria-label="Información legal">
    <a href="/terminos/" target="_blank">Términos y condiciones</a><a href="/politica-de-privacidad/" target="_blank">Política de privacidad</a>
    <a href="/garantias-y-devoluciones/" target="_blank">Garantías y devoluciones</a><a href="https://www.sic.gov.co" rel="noopener" target="_blank">Superintendencia de Industria y Comercio</a>
  </nav><p>© <?= date('Y') ?> Tendencity. Precios en <?= e(Country::get('currency_name')) ?>, IVA incluido. · Desarrollado por <a href="https://yabandu.com" target="_blank" rel="noopener">yabandu.com</a></p></div>
</footer>
<?php else: ?>
<footer class="foot">
  <div class="wrap foot__in">
    <div class="foot__brand">
      <img src="/assets/img/logo-white.png" alt="Tendencity" width="150" height="31" loading="lazy">
      <p>Productos en tendencia con envío a toda <?= e(Country::name()) ?>. Pagas cuando lo recibes en tu casa.</p>
    </div>
    <nav aria-label="Categorías">
      <h2>Categorías</h2>
      <?php foreach ($menu as $pl): ?><a href="/<?= e($pl['slug']) ?>/"><?= e($pl['name']) ?></a><?php endforeach; ?>
    </nav>
    <nav aria-label="Contenido">
      <h2>Antes de comprar</h2>
      <a href="/resenas/">Reseñas</a>
      <a href="/guias/">Guías</a>
      <a href="/envios-y-pagos/">Envíos y pagos</a>
      <a href="/garantias-y-devoluciones/">Garantías y devoluciones</a>
    </nav>
    <nav aria-label="Legal">
      <h2>Tendencity</h2>
      <a href="/contacto/">Contacto</a>
      <a class="foot__support" href="<?= e(wa_link('Hola, necesito ayuda con')) ?>" rel="noopener" target="_blank">Soporte: <?= e(wa_display()) ?></a>
      <a href="/terminos/">Términos y condiciones</a>
      <a href="/politica-de-privacidad/">Política de privacidad</a>
      <a href="https://www.sic.gov.co" rel="noopener" target="_blank">Superintendencia de Industria y Comercio</a>
    </nav>
  </div>
  <p class="wrap foot__legal">© <?= date('Y') ?> Tendencity. Precios en <?= e(Country::get('currency_name')) ?>, IVA incluido. · Desarrollado por <a href="https://yabandu.com" target="_blank" rel="noopener">yabandu.com</a></p>
</footer>
<?php endif; ?>
<?= country_js() ?>
<script src="<?= asset('js/app.js') ?>" defer></script>
</body>
</html>
