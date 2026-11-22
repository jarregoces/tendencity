<?php
$ico = fn($d) => '<svg viewBox="0 0 24 24" width="42" height="42" aria-hidden="true"><path d="' . $d . '" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$steps = [
    [$ico('M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8M7.5 5.5l9 5'), 'Preparamos tu paquete', 'Recibimos tu pedido y lo alistamos con cuidado.'],
    [$ico('M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.6A8.4 8.4 0 1 1 21 11.5zM9 11l2 2 4-4'), 'Te confirmamos por WhatsApp', 'Verificamos tus datos y tu dirección antes de despachar.'],
    [$ico('M3 7h11v9H3zM14 10h4l3 3v3h-7M7 19a2 2 0 1 0 0-.1M17 19a2 2 0 1 0 0-.1'), 'Lo recoge la transportadora', 'Te enviamos el número de guía para que lo sigas.'],
    [$ico('M12 21s-7-4.5-7-11a7 7 0 0 1 14 0c0 6.5-7 11-7 11zM12 12a2 2 0 1 0 0-4 2 2 0 0 0 0 4z'), 'En camino', 'Tu pedido viaja a la dirección que nos diste.'],
    [$ico('M4 11h16v9H4zM3 7h18v4H3zM12 7v13M12 7c-1.5-3-5-3-5-1s3 1 5 1c2 0 5 1 5-1s-3.5-2-5 1'), 'Lo recibes y pagas', 'Pagas en efectivo cuando lo tienes en tus manos.'],
];
?>
<section class="arr" aria-labelledby="t-arr">
  <h2 id="t-arr" class="vb-h2">Así llega <span class="grad-text">tu pedido</span></h2>
  <ol class="arr__steps">
    <?php foreach ($steps as $n => [$i, $t, $txt]): ?>
      <li><span class="arr__n"><?= $n + 1 ?></span><span class="arr__ico"><?= $i ?></span><h3><?= e($t) ?></h3><p><?= e($txt) ?></p></li>
    <?php endforeach; ?>
  </ol>
</section>
