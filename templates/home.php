<?php
/** @var Catalog $catalog */
$pillars  = $catalog->tree();
$counts   = $catalog->pillarCounts();
$hero     = $catalog->banners('principal')[0] ?? null;
$mayor    = $catalog->banners('mayorista')[0] ?? null;
$middles  = array_slice($catalog->banners('intermedio'), 0, 3);
// Cada sección destacada toma sus productos de Tienda → Portada (automático o manual)
$slots = new HomeSlots(db(), $catalog);
$deal = $slots->products('dia')[0] ?? null;
$exclusive = $slots->products('exclusiva', $deal ? [(int) $deal['id']] : [])[0] ?? null;
$heroProducts = $slots->products('hero');
$mprod = $slots->products('envio')[0] ?? null;
$offers = $slots->products('ofertas');
$tabs = ['destacados' => [$slots->isManual('populares') ? 'Destacados' : 'Más vendidos', $slots->products('populares')]];
foreach ($pillars as $pl) {
    if ($items = $catalog->productsIn($pl, 10)) { $tabs[$pl['slug']] = [$pl['name'], $items]; }
}
$meta = [
    'title' => 'Tendencity | Productos en tendencia con pago contraentrega en ' . Country::name() . '',
    'description' => 'Hogar, tecnología, belleza y bienestar con el envío incluido a toda ' . Country::name() . '. Pide en un minuto y paga en efectivo al recibir.',
    'jsonld' => [['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'Tendencity', 'url' => site_url('/'),
        'potentialAction' => ['@type' => 'SearchAction', 'target' => site_url('/buscar/?q={q}'), 'query-input' => 'required name=q']]],
];
$img = fn(?array $b, ?array $fallback) => ($b['image'] ?? null) ?: ($fallback ? product_image($fallback) : null);
?>
<h1 class="sr">Tendencity: productos en tendencia con pago contraentrega en <?= e(Country::name()) ?></h1>

<?php /* ============ 1. Banner principal ============ */ if ($hero): ?>
<section class="wrap hero2<?= stripos($hero['title'] . ' ' . $hero['eyebrow'], 'navidad') !== false ? ' hero2--xmas' : '' ?>">
  <div class="hero2__txt">
    <?php if ($hero['eyebrow']): ?><span class="hero2__eyebrow"><?= e($hero['eyebrow']) ?></span><?php endif; ?>
    <p class="hero2__title"><?= e($hero['title']) ?><?php if ($hero['highlight']): ?> <span class="grad-text"><?= e($hero['highlight']) ?></span><?php endif; ?></p>
    <?php if ($hero['text']): ?><p class="hero2__text"><?= e($hero['text']) ?></p><?php endif; ?>
    <?php partial('countdown', ['until' => $hero['until'], 'class' => 'cd--dark']); ?>
    <?php partial('banner_btn', ['text' => $hero['button_text'], 'url' => $hero['button_url'], 'class' => 'btn--grad']); ?>
  </div>
  <?php if ($hero['image']): ?>
    <img class="hero2__img" src="<?= e($hero['image']) ?>" alt="" width="560" height="420">
  <?php elseif ($heroProducts): ?>
    <div class="hero2__products">
      <?php foreach (array_slice($heroProducts, 0, 3) as $hp): $o = Pricing::offerPct($hp); ?>
        <a class="mini" href="/p/<?= e($hp['slug']) ?>/">
          <?php if ($o): ?><span class="tag tag--off">-<?= $o ?>%</span><?php endif; ?>
          <?php if ($i = product_image($hp)): ?><img src="<?= e($i) ?>" alt="<?= e(product_title($hp)) ?>" width="200" height="200"><?php endif; ?>
          <span class="mini__name"><?= e(product_title($hp)) ?></span>
          <span class="mini__price"><?= money($hp['price']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php /* ============ 2. Categorías + envío incluido ============ */ ?>
<section class="wrap duo">
  <nav class="catlist" aria-label="Categorías">
    <?php foreach ($pillars as $pl): ?>
      <a href="/<?= e($pl['slug']) ?>/"><span><?= e($pl['name']) ?></span><small><?= (int) ($counts[$pl['id']] ?? 0) ?></small></a>
    <?php endforeach; ?>
    <a href="/ofertas/" class="catlist__offers"><span>Ofertas</span><small>›</small></a>
  </nav>
  <?php if ($mayor && shipping_included()): $mimg = $mayor['image'] ?: ($mprod ? product_image($mprod) : null); ?>
  <div class="envio">
    <div class="envio__txt">
      <?php if ($mayor['eyebrow']): ?><span class="eyebrow"><?= e($mayor['eyebrow']) ?></span><?php endif; ?>
      <h2 class="envio__title"><?= e($mayor['title']) ?><?php if ($mayor['highlight']): ?> <span class="grad-text"><?= e($mayor['highlight']) ?></span><?php endif; ?></h2>
      <?php if ($mayor['text']): ?><p><?= e($mayor['text']) ?></p><?php endif; ?>
      <ol class="steps">
        <li><b>1</b><span><strong>Pides en 1 minuto</strong>Sin crear cuenta ni tarjeta</span></li>
        <li><b>2</b><span><strong>Te llega a casa</strong>A cualquier ciudad de <?= e(Country::name()) ?></span></li>
        <li><b>3</b><span><strong>Pagas al recibir</strong>En efectivo, al transportador</span></li>
      </ol>
      <div class="btns">
        <?php partial('banner_btn', ['text' => $mayor['button_text'], 'url' => $mayor['button_url'], 'class' => 'btn--dark']); ?>
        <?php partial('banner_btn', ['text' => $mayor['button2_text'], 'url' => $mayor['button2_url'], 'class' => 'btn--line']); ?>
      </div>
    </div>
    <?php if ($mimg): ?>
      <div class="envio__img">
        <img src="<?= e($mimg) ?>" alt="" width="340" height="340" loading="lazy">
        <svg class="stamp" viewBox="0 0 120 120" aria-hidden="true">
          <defs><path id="sc" d="M60,60 m-46,0 a46,46 0 1,1 92,0 a46,46 0 1,1 -92,0"/>
            <linearGradient id="sg" x1="0" x2="1"><stop offset="0" stop-color="#3bb4f2"/><stop offset=".55" stop-color="#8e62f0"/><stop offset="1" stop-color="#e05ff0"/></linearGradient></defs>
          <circle cx="60" cy="60" r="58" fill="url(#sg)"/><circle cx="60" cy="60" r="34" fill="#000"/>
          <text font-size="10.5" font-weight="700" fill="#fff"><textPath href="#sc" textLength="286" lengthAdjust="spacing">ENVÍO INCLUIDO • PAGO AL RECIBIR • </textPath></text>
          <text x="60" y="57" text-anchor="middle" font-size="11" font-weight="700" fill="#fff">$0</text>
          <text x="60" y="71" text-anchor="middle" font-size="8" fill="#cfc9dd">de envío</text>
        </svg>
        <?php if ($mprod && !$mayor['image']): ?><a class="envio__chip" href="/p/<?= e($mprod['slug']) ?>/"><span><?= e(product_title($mprod)) ?></span><strong><?= money($mprod['price']) ?> <small>envío incluido a toda <?= e(Country::name()) ?></small></strong></a><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>

<?php /* ============ 3. Beneficios ============ */ partial('trust'); ?>

<?php /* ============ 4. Populares con pestañas + columna de ofertas ============ */ ?>
<section class="wrap popular">
  <aside class="popular__side">
    <?php if ($exclusive): $eo = Pricing::offerPct($exclusive); ?>
    <a class="side-ex" href="/p/<?= e($exclusive['slug']) ?>/">
      <span class="side-ex__eyebrow">Oferta exclusiva · -<?= $eo ?>%</span>
      <strong><?= e(product_title($exclusive)) ?></strong>
      <?php if ($i = product_image($exclusive)): ?><img src="<?= e($i) ?>" alt="" width="220" height="220" loading="lazy"><?php endif; ?>
      <span class="btn btn--grad btn--sm">Comprar ahora</span>
    </a>
    <?php endif; ?>
    <?php if ($deal): ?>
    <div class="side-deal">
      <p class="side-deal__t">Oferta del día</p>
      <?php partial('countdown', ['until' => $deal['offer_until'], 'class' => 'cd--sm']); ?>
      <a href="/p/<?= e($deal['slug']) ?>/" class="side-deal__p">
        <?php if ($i = product_image($deal)): ?><img src="<?= e($i) ?>" alt="" width="180" height="180" loading="lazy"><?php endif; ?>
        <span><?= e(product_title($deal)) ?></span>
        <strong><?= money($deal['price']) ?> <s><?= money($deal['price_before']) ?></s></strong>
      </a>
    </div>
    <?php endif; ?>
  </aside>
  <div class="popular__main">
    <div class="section__head">
      <h2>Productos populares</h2>
      <div class="tabs" role="tablist">
        <?php $first = true; foreach ($tabs as $k => [$label]): ?>
          <button type="button" role="tab" class="tabs__b<?= $first ? ' is-on' : '' ?>" aria-selected="<?= $first ? 'true' : 'false' ?>" aria-controls="tab-<?= e($k) ?>"><?= e($label) ?></button>
        <?php $first = false; endforeach; ?>
      </div>
    </div>
    <?php $first = true; foreach ($tabs as $k => [$label, $items]): ?>
      <div class="tabs__p" id="tab-<?= e($k) ?>" role="tabpanel"<?= $first ? '' : ' hidden' ?>><?php partial('grid', ['products' => $items]); ?></div>
    <?php $first = false; endforeach; ?>
  </div>
</section>

<?php /* ============ 5. Banners intermedios ============ */ if ($middles): ?>
<section class="wrap mids">
  <?php foreach ($middles as $i => $b):
      $catProd = null;
      if (!$b['image'] && $i > 0 && preg_match('#^/([a-z0-9-]+)/$#', (string) $b['button_url'], $m) && ($c = $catalog->categoryByPath($m[1]))) {
          $catProd = $catalog->productsIn($c, 1)[0] ?? null;
      }
      $bimg = $b['image'] ?: ($catProd ? product_image($catProd) : null); ?>
    <a class="mid<?= $i === 0 ? ' mid--dark' : '' ?>" href="<?= e($b['button_url'] ?: '/ofertas/') ?>">
      <span class="mid__txt">
        <?php if ($b['eyebrow']): ?><span class="eyebrow"><?= e($b['eyebrow']) ?></span><?php endif; ?>
        <strong class="mid__title"><?= e($b['title']) ?><?php if ($b['highlight']): ?> <span class="<?= $i === 0 ? 'mid__big' : 'grad-text' ?>"><?= e($b['highlight']) ?></span><?php endif; ?></strong>
        <?php if ($b['text']): ?><span class="mid__text"><?= e($b['text']) ?></span><?php endif; ?>
        <?php if ($b['button_text']): ?><span class="btn <?= $i === 0 ? 'btn--grad' : 'btn--dark' ?> btn--sm"><?= e($b['button_text']) ?></span><?php endif; ?>
      </span>
      <?php if ($bimg): ?><img src="<?= e($bimg) ?>" alt="" width="200" height="200" loading="lazy"><?php endif; ?>
    </a>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php /* ============ 6. Ofertas ============ */ if ($offers): ?>
<section class="wrap section">
  <div class="section__head"><h2>En oferta ahora</h2><a href="/ofertas/">Ver todas</a></div>
  <?php partial('grid', ['products' => $offers]); ?>
</section>
<?php endif; ?>

<?php /* ============ 7. Reseñas ============ */ $reviews = $catalog->reviews(null, 3); if ($reviews): ?>
<section class="wrap section">
  <div class="section__head"><h2>Reseñas antes de comprar</h2><a href="/resenas/">Todas las reseñas</a></div>
  <div class="posts"><?php foreach ($reviews as $r) { partial('post_card', ['url' => "/resenas/{$r['cat_slug']}/{$r['slug']}/", 'title' => $r['title'], 'text' => $r['verdict'], 'rating' => $r['rating']]); } ?></div>
</section>
<?php endif; ?>
