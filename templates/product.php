<?php
/** @var array $p  @var Catalog $catalog */
require __DIR__ . '/partials/product_data.php';
?>
<div class="wrap pdp">
  <div class="pdp__gallery">
    <div class="gallery" id="gallery">
      <?php foreach ($gallery ?: [null] as $i => $img): ?>
        <figure class="gallery__slide">
          <?php if ($img && is_video_url($img)): ?>
          <?= video_tag($img, $title . ' – video') ?>
          <?php elseif ($img): ?>
          <img src="<?= e($img) ?>" alt="<?= e($title) ?><?= $i ? ' – foto ' . ($i + 1) : '' ?>" width="800" height="800" <?= $i ? 'loading="lazy"' : 'fetchpriority="high"' ?>>
          <?php endif; ?>
        </figure>
      <?php endforeach; ?>
    </div>
    <?php if (count($gallery) > 1): ?>
    <div class="thumbs" role="tablist" aria-label="Fotos del producto">
      <?php foreach ($gallery as $i => $img): ?>
        <button type="button" data-slide="<?= $i ?>" aria-label="Ver <?= is_video_url($img) ? 'video' : 'foto' ?> <?= $i + 1 ?>"><?= is_video_url($img)
          ? '<video src="' . e($img) . '#t=0.5" muted playsinline preload="metadata"></video><span class="thumbs__play" aria-hidden="true">▶</span>'
          : '<img src="' . e($img) . '" alt="" width="72" height="72" loading="lazy">' ?></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="pdp__info">
    <?php if ($p['brand']): ?><p class="pdp__brand">Marca: <strong><?= e($p['brand']) ?></strong></p><?php endif; ?>
    <h1 class="pdp__title"><?= e($title) ?></h1>
    <?php if ($p['aida_hook']): ?><p class="pdp__hook"><?= e($p['aida_hook']) ?></p><?php endif; ?>

    <div class="price">
      <span class="price__now"><?= money($p['price']) ?></span>
      <?php if ($offPct): ?>
        <s class="price__before"><?= money($p['price_before']) ?></s>
        <span class="tag">-<?= $offPct ?>%</span>
      <?php endif; ?>
    </div>
    <?php if ($offPct && $p['offer_until']): ?>
      <p class="countdown" data-until="<?= e(date('c', strtotime($p['offer_until']))) ?>">Precio de oferta hasta el <?= e(date('d/m/Y', strtotime($p['offer_until']))) ?></p>
    <?php endif; ?>

    <?php if (count($colorVariants) > 1): ?>
    <div class="colors" role="radiogroup" aria-label="Color">
      <p class="colors__label">Color: <strong data-color-name><?= e($colorVariants[0]['name']) ?></strong></p>
      <div class="colors__list">
        <?php foreach ($colorVariants as $i => $v): $hex = $swatch($v['name']); $slide = $v['image'] ? array_search($v['image'], $gallery, true) : false; ?>
          <button type="button" role="radio" aria-checked="<?= $i ? 'false' : 'true' ?>" class="colors__b<?= $i ? '' : ' is-on' ?><?= (int) $v['stock'] > 0 ? '' : ' is-out' ?>"
            data-variant="<?= (int) $v['id'] ?>" data-name="<?= e($v['name']) ?>" <?= $slide !== false ? 'data-slide="' . (int) $slide . '"' : '' ?>>
            <?php if ($hex): ?><span class="colors__sw" style="background:<?= e($hex) ?>"></span><?php endif; ?><?= e($v['name']) ?><?= (int) $v['stock'] > 0 ? '' : ' · agotado' ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($inStock): ?>
      <button type="button" class="btn btn--grad btn--xl" data-open-order><?= e($ctaLabel) ?></button>
      <?php partial('delivery_estimate'); ?>
    <?php else: ?>
      <p class="out">Agotado por ahora.</p>
      <?php if ($cat): ?><a class="btn btn--line btn--xl" href="<?= e(Catalog::categoryUrl($cat)) ?>">Ver otros productos de <?= e(mb_strtolower($cat['name'])) ?></a><?php endif; ?>
    <?php endif; ?>

    <ul class="assure">
      <li><?= shipping_included() ? '<strong>Envío incluido</strong> ' . e(Country::toAll()) : 'Envío ' . e(Country::toAll()) ?></li>
      <?php if ($p['allow_cod']): ?><li>Pagas en efectivo al recibir</li><?php endif; ?>
      <?php if ($p['allow_pia'] && $p['pia_discount']): ?><li><?= (int) $p['pia_discount'] ?>% menos si pagas por adelantado</li><?php endif; ?>
      <?php if ($p['aida_guarantee']): ?><li><?= e($p['aida_guarantee']) ?></li><?php endif; ?>
    </ul>
    <?php if ($review): ?>
      <a class="pdp__review" href="/resenas/<?= e($review['cat_slug']) ?>/<?= e($review['slug']) ?>/">Lee nuestra reseña<?= $review['rating'] ? ' (' . e(number_format((float) $review['rating'], 1, ',', '')) . ' / 5)' : '' ?></a>
    <?php endif; ?>
  </div>
</div>

<?php /* ===================== AIDA ===================== */ ?>
<div class="aida">
  <?php
  // Imágenes de la descripción: una después de cada sección, en el orden en que se subieron
  $imgQueue = $bodyImages;
  $nextImg = function (int $n = 1, bool $inText = false) use (&$imgQueue, $title) {
      for ($i = 0; $i < $n && $imgQueue; $i++) {
          $im = array_shift($imgQueue);
          echo '<figure class="body-img' . ($inText ? ' body-img--in' : ' wrap-narrow') . '">'
             . (is_video_url($im['src']) ? video_tag($im['src'], $im['alt'] ?: $title)
                                         : '<img src="' . e($im['src']) . '" alt="' . e($im['alt'] ?: $title) . '" width="1080" loading="lazy">')
             . '</figure>';
      }
  };
  // Texto de venta con imágenes intercaladas: una antes de cada subtítulo, hasta 3 (como una landing)
  $desireWithImages = function (string $text) use ($nextImg): void {
      // Corta antes de cada subtítulo: "## …", "### …" o una línea entera en negrita "**…**"
      $chunks = preg_split('/\R(?=#{2,3}\s|\*\*[^*\n]+\*\*\h*(?:\R|$))/', trim($text));
      foreach ($chunks as $k => $chunk) {
          if ($k > 0 && $k <= 3) { $nextImg(1, true); }
          echo '<div class="prose">' . md($chunk) . '</div>';
      }
  };
  ?>
  <?php if ($p['aida_headline']): ?>
  <section class="aida__a wrap-narrow">
    <h2><?= e($p['aida_headline']) ?></h2>
  </section>
  <?php endif; ?>

  <?php if ($benefits): ?>
  <section class="aida__i wrap-narrow" aria-labelledby="t-benef">
    <h2 id="t-benef">Lo que vas a notar</h2>
    <ul class="benefits"><?php foreach ($benefits as $b): ?><li><?= md_inline($b) ?></li><?php endforeach; ?></ul>
  </section>
  <?php $nextImg(); endif; ?>

  <div class="wrap-narrow"><?php partial('features_media', ['featuresMedia' => $featuresMedia, 'usageSteps' => $usageSteps, 'measures' => $measures, 'testimonials' => $testimonials, 'title' => $title, 'image' => $images[0] ?? null]); ?></div>
  <?php if ($featuresMedia) { $nextImg(); } ?>

  <?php if ($boxItems): ?>
  <section class="box-in wrap-narrow" aria-labelledby="t-box">
    <h2 id="t-box">Qué trae la caja</h2>
    <ul class="box-in__list">
      <?php foreach ($boxItems as [$qty, $name]): ?><li><?php if ($qty !== ''): ?><b><?= e($qty) ?></b><?php endif; ?><span><?= e($name) ?></span></li><?php endforeach; ?>
    </ul>
  </section>
  <?php $nextImg(); endif; ?>

  <?php if ($p['aida_desire'] || $forWhom || $specs): ?>
  <section class="aida__d wrap-narrow">
    <?php if ($p['aida_desire']) { $desireWithImages((string) $p['aida_desire']); } ?>
    <?php if ($forWhom): ?>
      <h2>Es para ti si…</h2>
      <ul class="forwhom"><?php foreach ($forWhom as $w): ?><li><?= md_inline($w) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($specs): ?>
      <h2>Características</h2>
      <table class="specs"><tbody>
        <?php foreach ($specs as [$k, $v]): ?><tr><th scope="row"><?= e($k) ?></th><td><?= e($v) ?></td></tr><?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
  </section>
  <?php $nextImg(); endif; ?>

  <div class="wrap-narrow"><?php partial('measures', ['featuresMedia' => $featuresMedia, 'usageSteps' => $usageSteps, 'measures' => $measures, 'testimonials' => $testimonials, 'title' => $title, 'image' => $images[0] ?? null]); partial('usage', ['featuresMedia' => $featuresMedia, 'usageSteps' => $usageSteps, 'measures' => $measures, 'testimonials' => $testimonials, 'title' => $title, 'image' => $images[0] ?? null]); ?></div>
  <?php if ($measures || $usageSteps) { $nextImg(); } ?>

  <?php if (count($cmp) > 1): $head = array_shift($cmp); ?>
  <section class="cmp wrap-narrow" aria-labelledby="t-cmp">
    <h2 id="t-cmp">¿Por qué elegir este?</h2>
    <div class="cmp__scroll"><table class="cmp__t">
      <thead><tr><?php foreach ($head as $j => $h): ?><th scope="col"<?= $j === 1 ? ' class="cmp__us"' : '' ?>><?= e($h) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($cmp as $row): ?><tr>
        <?php foreach ($head as $j => $_): $cell = $row[$j] ?? ''; $lc = mb_strtolower($cell); ?>
          <?php if ($j === 0): ?><th scope="row"><?= e($cell) ?></th>
          <?php else: ?><td<?= $j === 1 ? ' class="cmp__us"' : '' ?>><?= in_array($lc, ['si', 'sí', '✓', 'x'], true) ? '<span class="cmp__yes" aria-label="Sí">✓</span>' : ($lc === 'no' ? '<span class="cmp__no" aria-label="No">✕</span>' : e($cell)) ?></td><?php endif; ?>
        <?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </section>
  <?php $nextImg(); endif; ?>

  <?php if ($inStock): ?>
  <section class="aida__ac wrap-narrow">
    <?php if ($p['aida_urgency']): ?><p class="aida__urgency"><?= e($p['aida_urgency']) ?></p><?php endif; ?>
    <button type="button" class="btn btn--grad btn--xl" data-open-order><?= e($ctaLabel) ?></button>
    <?php if ($p['aida_guarantee']): ?><p class="aida__guarantee"><?= e($p['aida_guarantee']) ?></p><?php endif; ?>
  </section>
  <?php endif; ?>
  <?php $nextImg(PHP_INT_MAX); /* las que sobren, al final */ ?>
</div>

<div class="wrap"><?php partial('testimonials', ['featuresMedia' => $featuresMedia, 'usageSteps' => $usageSteps, 'measures' => $measures, 'testimonials' => $testimonials, 'title' => $title, 'image' => $images[0] ?? null]); ?></div>

<div class="wrap"><?php partial('how_it_arrives'); ?></div>

<?php partial('faq', ['faq' => $faq]); ?>

<?php if ($related && $cat && !$focus): ?>
<section class="wrap section">
  <div class="section__head"><h2>Más en <?= e($cat['name']) ?></h2><a href="<?= e(Catalog::categoryUrl($cat)) ?>">Ver todo</a></div>
  <?php partial('grid', ['products' => $related]); ?>
</section>
<?php endif; ?>

<!-- Por qué comprar en Tendencity: último bloque antes del pie de página -->
<div class="wrap"><?php partial('why_trust'); ?></div>

<?php if ($inStock): ?>
<div class="sticky-buy" aria-hidden="true">
  <div><strong><?= money($p['price']) ?></strong><?php if ($offPct): ?> <s><?= money($p['price_before']) ?></s><?php endif; ?></div>
  <button type="button" class="btn btn--grad" data-open-order tabindex="-1">Pedir ahora</button>
</div>
<?php partial('order_form', ['p' => $p, 'variants' => $variants, 'title' => $title, 'image' => $images[0] ?? null]); ?>
<?php endif; ?>
