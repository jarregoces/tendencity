<?php /** @var string $until  @var ?string $class */ if (!empty($until) && strtotime($until) > time()): ?>
<div class="cd <?= e($class ?? '') ?>" data-countdown="<?= e(date('c', strtotime($until))) ?>" aria-label="Tiempo restante de la oferta">
  <span><b data-u="d">00</b><small>Días</small></span><span><b data-u="h">00</b><small>Horas</small></span>
  <span><b data-u="m">00</b><small>Min</small></span><span><b data-u="s">00</b><small>Seg</small></span>
</div>
<?php endif; ?>
