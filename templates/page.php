<?php
/** @var string $slug, $title — el contenido vive en templates/pages/{slug}.md */
$file = __DIR__ . "/pages/{$slug}.md";
$local = __DIR__ . '/pages/' . strtolower(Country::code()) . "/{$slug}.md";   // versión del país (pages/gt/…)
if (!Country::isCO() && is_file($local)) { $file = $local; }
$meta = ['title' => "{$title} | Tendencity", 'description' => "{$title} de Tendencity.",
    'robots' => in_array($slug, ['terminos', 'politica-de-privacidad'], true) ? 'noindex, follow' : 'index, follow'];
?>
<article class="wrap-narrow article">
  <h1><?= e($title) ?></h1>
  <?php $st = db()->prepare('SELECT body FROM pages WHERE slug = ?'); $st->execute([$slug]); $body = $st->fetchColumn(); ?>
  <div class="prose"><?= md($body !== false && $body !== null ? $body : (is_file($file) ? file_get_contents($file) : '')) ?></div>
  <?php if ($slug === 'contacto'): ?><a class="btn btn--grad" href="<?= e(wa_link('Hola')) ?>" rel="noopener" target="_blank">Soporte por WhatsApp · <?= e(wa_display()) ?></a><?php endif; ?>
</article>
