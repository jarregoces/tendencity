<?php /** @var string $url, $title  @var ?string $text  @var mixed $rating */ ?>
<a class="post" href="<?= e($url) ?>">
  <?php if (!empty($rating)): ?><span class="post__rating" aria-label="Calificación <?= e((string) $rating) ?> de 5"><?= e(number_format((float) $rating, 1, ',', '')) ?> / 5</span><?php endif; ?>
  <h3><?= e($title) ?></h3>
  <?php if (!empty($text)): ?><p><?= e($text) ?></p><?php endif; ?>
</a>
