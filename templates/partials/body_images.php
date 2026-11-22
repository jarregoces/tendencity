<?php /** @var array $bodyImages [['src','alt']]  @var string $title */ if (!$bodyImages) return; ?>
<section class="body-imgs" aria-label="Detalles de <?= e($title) ?>">
  <?php foreach ($bodyImages as $i => $img): ?>
    <figure><img src="<?= e($img['src']) ?>" alt="<?= e($img['alt'] ?: $title) ?>" width="1080" <?= $i ? 'loading="lazy"' : '' ?>>
      <?php if ($img['alt']): ?><figcaption class="sr"><?= e($img['alt']) ?></figcaption><?php endif; ?></figure>
  <?php endforeach; ?>
</section>
