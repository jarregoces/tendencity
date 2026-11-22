<?php /** @var array $featuresMedia [[título, texto, foto]]  @var string $title */ if (!$featuresMedia) return; ?>
<section class="vb vb-feat" aria-labelledby="t-feat">
  <h2 id="t-feat" class="vb-h2"><?= count($featuresMedia) ?> características <span class="grad-text">que se notan</span></h2>
  <?php foreach ($featuresMedia as $i => [$t, $txt, $img]): ?>
    <article class="feat<?= $i % 2 ? ' feat--rev' : '' ?>">
      <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($title . ': ' . $t) ?>" width="600" height="450" loading="lazy"><?php endif; ?>
      <div class="feat__txt"><span class="feat__n"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span><h3><?= e($t) ?></h3><?php if ($txt): ?><p><?= md_inline($txt) ?></p><?php endif; ?></div>
    </article>
  <?php endforeach; ?>
</section>
