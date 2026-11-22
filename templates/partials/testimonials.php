<?php /** @var array $testimonials */ if (!$testimonials) return;
$avg = round(array_sum(array_column($testimonials, 'rating')) / count($testimonials), 1);
$stars = fn(int $n) => '<span class="stars" aria-label="' . $n . ' de 5 estrellas">' . str_repeat('★', $n) . '<span class="stars__off">' . str_repeat('★', 5 - $n) . '</span></span>'; ?>
<section class="vb vb-test" aria-labelledby="t-test">
  <h2 id="t-test" class="vb-h2">Lo que dicen <span class="grad-text">nuestros clientes</span></h2>
  <p class="test__avg"><?= $stars((int) round($avg)) ?> <strong><?= e(number_format($avg, 1, ',', '')) ?></strong> de 5 · <?= count($testimonials) ?> <?= count($testimonials) === 1 ? 'opinión' : 'opiniones' ?></p>
  <div class="test">
    <?php foreach ($testimonials as $t): ?>
      <article class="test__c">
        <?php if ($t['photo']): ?><img src="<?= e($t['photo']) ?>" alt="Foto de <?= e($t['name']) ?>" width="400" height="400" loading="lazy"><?php endif; ?>
        <div class="test__b">
          <?= $stars((int) $t['rating']) ?>
          <p><?= e($t['text']) ?></p>
          <p class="test__who"><strong><?= e($t['name']) ?></strong><?= $t['city'] ? ' · ' . e($t['city']) : '' ?>
            <?php if ($t['verified']): ?><span class="test__ok">✓ Compra verificada</span><?php endif; ?></p>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
