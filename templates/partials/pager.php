<?php
/** @var int $pg  @var int $pages  @var string $path  @var array $keep (parámetros a conservar, p. ej. q) */
if ($pages <= 1) return;
$keep ??= [];
$u = fn(int $n) => page_url($path, $n, $keep);
$nums = array_unique(array_filter([1, $pg - 1, $pg, $pg + 1, $pages], fn($n) => $n >= 1 && $n <= $pages));
sort($nums);
?>
<nav class="pager wrap" aria-label="Páginas">
  <?php if ($pg > 1): ?><a class="pager__nav" href="<?= e($u($pg - 1)) ?>" rel="prev">‹ Anterior</a><?php endif; ?>
  <?php $prev = 0; foreach ($nums as $n): if ($n - $prev > 1): ?><span class="pager__gap">…</span><?php endif; ?>
    <?php if ($n === $pg): ?><span class="pager__n is-on" aria-current="page"><?= $n ?></span><?php else: ?><a class="pager__n" href="<?= e($u($n)) ?>"><?= $n ?></a><?php endif; ?>
  <?php $prev = $n; endforeach; ?>
  <?php if ($pg < $pages): ?><a class="pager__nav" href="<?= e($u($pg + 1)) ?>" rel="next">Siguiente ›</a><?php endif; ?>
</nav>
