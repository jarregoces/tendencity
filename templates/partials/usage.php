<?php /** @var array $usageSteps [[título, texto, foto]]  @var string $title */ if (!$usageSteps) return; ?>
<section class="vb vb-use" aria-labelledby="t-use">
  <h2 id="t-use" class="vb-h2">Formas <span class="grad-text">de uso</span></h2>
  <div class="use">
    <?php foreach ($usageSteps as [$t, $txt, $img]): ?>
      <article class="use__c">
        <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($title . ': ' . $t) ?>" width="400" height="300" loading="lazy"><?php endif; ?>
        <div><h3><?= e($t) ?></h3><?php if ($txt): ?><p><?= md_inline($txt) ?></p><?php endif; ?></div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
