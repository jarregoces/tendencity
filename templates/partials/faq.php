<?php /** @var array $faq  @var string $title */ if ($faq): ?>
<section class="faq wrap-narrow" aria-labelledby="faq-t">
  <h2 id="faq-t"><?= e($title ?? 'Preguntas frecuentes') ?></h2>
  <?php foreach ($faq as $f): ?>
    <details class="faq__item"><summary><?= e($f['q']) ?></summary><div><?= md($f['a']) ?></div></details>
  <?php endforeach; ?>
</section>
<?php endif; ?>
