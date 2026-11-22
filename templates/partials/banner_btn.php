<?php /** @var string $text, $url  @var string $class */
if (!empty($text) && !empty($url)):
  $isWa = $url === 'whatsapp';
  $href = $isWa ? wa_link('Hola, necesito ayuda con') : $url; ?>
  <a class="btn <?= e($class) ?><?= $isWa ? ' btn--wa' : '' ?>" href="<?= e($href) ?>"<?= $isWa ? ' rel="noopener" target="_blank"' : '' ?>><?= e($text) ?></a>
<?php endif; ?>
