<?php
$ico = fn($d) => '<svg viewBox="0 0 24 24" width="34" height="34" aria-hidden="true"><path d="' . $d . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$cards = [
    [$ico('M3 7h18v10H3zM3 11h18M7 15h3'), 'Pago al recibir', 'No pagas nada por adelantado: pagas en efectivo cuando el pedido llega.'],
    [$ico('M3 7h11v9H3zM14 10h4l3 3v3h-7M7 19a2 2 0 1 0 0-.1M17 19a2 2 0 1 0 0-.1'), shipping_included() ? 'Envío incluido ' . Country::toAll() : 'Envíos ' . Country::toAll(), shipping_included() ? 'El precio que ves ya incluye el envío a tu ciudad o municipio.' : 'Llegamos a cualquier ciudad o municipio del país.'],
    [$ico('M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM21 21l-5-5'), 'Seguimiento de tu pedido', 'Te enviamos el número de guía para que sepas dónde va.'],
    [$ico('M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6zM9 12l2 2 4-4'), 'Compra segura', 'Confirmamos cada pedido contigo antes de despacharlo.'],
    [$ico('M4 15v-3a8 8 0 0 1 16 0v3M4 15a2 2 0 0 0 2 2h1v-5H6a2 2 0 0 0-2 2zM20 15a2 2 0 0 1-2 2h-1v-5h1a2 2 0 0 1 2 2zM17 17v1a3 3 0 0 1-3 3h-2'), 'Atención por WhatsApp', 'Te ayudamos con cualquier duda antes y después de tu compra.'],
];
?>
<section class="why" aria-labelledby="t-why">
  <h2 id="t-why" class="vb-h2">¿Por qué comprar en <span class="grad-text">Tendencity</span>?</h2>
  <p class="why__sub">Trabajamos para que tu compra sea segura, clara y sin complicaciones.</p>
  <div class="why__cards">
    <?php foreach ($cards as [$i, $t, $txt]): ?><article><span class="why__ico"><?= $i ?></span><h3><?= e($t) ?></h3><p><?= e($txt) ?></p></article><?php endforeach; ?>
  </div>
</section>
