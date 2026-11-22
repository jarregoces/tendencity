<?php /** @var array $p */ $img = product_image($p); $off = Pricing::offerPct($p); $out = (int) $p['stock'] <= 0; $title = product_title($p); ?>
<article class="card">
  <a class="card__link" href="/p/<?= e($p['slug']) ?>/">
    <div class="card__img<?= $img ? '' : ' card__img--empty' ?>">
      <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($title) ?>" loading="lazy" width="400" height="400"><?php endif; ?>
      <?php if ($off): ?><span class="tag tag--off">-<?= $off ?>%</span><?php elseif (!empty($p['badge'])): ?><span class="tag tag--badge"><?= e($p['badge']) ?></span><?php endif; ?>
      <?php if ($out): ?><span class="tag tag--out">Agotado</span><?php endif; ?>
    </div>
    <?php if (!empty($p['cat_name'])): ?><span class="card__cat"><?= e($p['cat_name']) ?></span><?php endif; ?>
    <?php if (!empty($p['brand'])): ?><span class="card__brand"><?= e($p['brand']) ?></span><?php endif; ?>
    <h3 class="card__title"><?= e($title) ?></h3>
    <p class="card__price">
      <strong><?= money($p['price']) ?></strong>
      <?php if ($off): ?><s><?= money($p['price_before']) ?></s><?php endif; ?>
    </p>
    <?php if (shipping_included()): ?><span class="card__ship">Envío incluido a toda Colombia</span><?php endif; ?>
  </a>
  <div class="card__actions">
    <?php if ($out): ?>
      <a class="btn btn--line btn--sm" href="/p/<?= e($p['slug']) ?>/">Ver producto</a>
    <?php else: ?>
      <a class="btn btn--grad btn--sm" href="/p/<?= e($p['slug']) ?>/#pedir">Pedir ahora</a>
    <?php endif; ?>
  </div>
</article>
