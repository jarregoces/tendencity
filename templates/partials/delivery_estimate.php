<?php
/** Fecha estimada de entrega (debajo del botón de compra). */
$est = Delivery::estimate();
$ico = fn($d) => '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="' . $d . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$steps = [
    [$ico('M3 4h2l2.4 11h10.2L20 7H6.2M10 20a1 1 0 1 0 0-.1M17 20a1 1 0 1 0 0-.1'), 'Hoy, ' . Delivery::fmt($est['ordered']), 'Pides'],
    [$ico('M3 7h11v9H3zM14 10h4l3 3v3h-7M7 19a2 2 0 1 0 0-.1M17 19a2 2 0 1 0 0-.1'), Delivery::fmtRange($est['ready']), 'Despachamos'],
    [$ico('M4 11h16v9H4zM3 7h18v4H3zM12 7v13M12 7c-1.5-3-5-3-5-1s3 1 5 1c2 0 5 1 5-1s-3.5-2-5 1'), Delivery::fmtRange($est['delivered']), 'Te llega'],
];
?>
<div class="eta" aria-label="Fecha estimada de entrega">
  <p class="eta__title">Pide hoy y recíbelo entre el <strong><?= e(Delivery::fmt($est['delivered'][0])) ?></strong> y el <strong><?= e(Delivery::fmt($est['delivered'][1])) ?></strong></p>
  <ol class="eta__steps">
    <?php foreach ($steps as [$i, $date, $label]): ?><li><span class="eta__dot"><?= $i ?></span><b><?= e($date) ?></b><small><?= e($label) ?></small></li><?php endforeach; ?>
  </ol>
  <p class="eta__note"><?= e(setting('ship_note', 'Fecha estimada para ciudades principales. Municipios alejados pueden tardar unos días más.')) ?></p>
</div>
