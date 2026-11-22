<?php
/** @var array $measures [[nombre, valor]]  @var ?string $image  @var string $title */
if (!$measures) return;
$num = fn(string $v) => (float) str_replace(',', '.', preg_replace('/[^\d.,]/', '', $v));
$find = function (array $keys) use ($measures) {
    foreach ($measures as [$k, $v]) { foreach ($keys as $kk) { if (str_starts_with(mb_strtolower($k), $kk)) return $v; } }
    return null;
};
$alto = $find(['alto', 'altura']); $ancho = $find(['ancho', 'largo']); $fondo = $find(['fondo', 'profundidad']);
$dimMode = $image && ($alto || $ancho);
$rest = array_filter($measures, fn($m) => !preg_match('/^(alto|altura|ancho|largo|fondo|profundidad)/i', $m[0]));
?>
<section class="vb vb-measures" aria-labelledby="t-med">
  <h2 id="t-med" class="vb-h2">Medidas</h2>
  <?php if ($dimMode): ?>
    <div class="dim">
      <div class="dim__frame">
        <img src="<?= e($image) ?>" alt="<?= e($title) ?>: medidas" width="600" height="600" loading="lazy">
        <?php if ($alto): ?><span class="dim__v" aria-hidden="true"></span><b class="dim__vl"><?= e($alto) ?></b><?php endif; ?>
        <?php if ($ancho): ?><span class="dim__h" aria-hidden="true"></span><b class="dim__hl"><?= e($ancho) ?></b><?php endif; ?>
        <?php if ($fondo): ?><b class="dim__d">Fondo <?= e($fondo) ?></b><?php endif; ?>
      </div>
    </div>
    <ul class="sr"><?php foreach ($measures as [$k, $v]): ?><li><?= e("{$k}: {$v}") ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <?php $bars = $dimMode ? $rest : $measures; if ($bars): $max = max(array_map(fn($m) => $num($m[1]), $bars)) ?: 1; ?>
    <ul class="bars">
      <?php foreach ($bars as [$k, $v]): $w = max(8, round($num($v) / $max * 100)); ?>
        <li><span class="bars__k"><?= e($k) ?></span><span class="bars__track"><i style="width:<?= $w ?>%"></i></span><b><?= e($v) ?></b></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
