<?php $ico = fn(string $d) => '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path fill="none" stroke="url(#tg)" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" d="' . $d . '"/></svg>'; ?>
<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs><linearGradient id="tg" x1="0" x2="1"><stop offset="0" stop-color="#3bb4f2"/><stop offset=".55" stop-color="#8e62f0"/><stop offset="1" stop-color="#e05ff0"/></linearGradient></defs></svg>
<ul class="trust2 wrap" aria-label="Por qué comprar en Tendencity">
  <li><?= $ico('M3 7h11v9H3zM14 10h4l3 3v3h-7M7 19a2 2 0 1 0 0-.1M17 19a2 2 0 1 0 0-.1') ?><span><?php if (shipping_included()): ?><strong>Envío incluido a toda <?= e(Country::name()) ?></strong><small>Ya está en el precio que ves</small><?php else: ?><strong>Envíos a toda <?= e(Country::name()) ?></strong><small>Despacho en 24 a 48 horas</small><?php endif; ?></span></li>
  <li><?= $ico('M3 7h18v10H3zM3 11h18M7 15h3') ?><span><strong>Pagas al recibir</strong><small>En efectivo, cuando llega</small></span></li>
  <li><?= $ico('M12 7v5l3 2M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z') ?><span><strong>Pides en 1 minuto</strong><small>Sin crear cuenta</small></span></li>
  <li><?= $ico('M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6zM9 12l2 2 4-4') ?><span><strong>Garantía</strong><small><a href="/garantias-y-devoluciones/">Así funciona</a></small></span></li>
  <li><?= $ico('M4 19l1.3-4A8 8 0 1 1 9 18.7zM9 10h6M9 13h4') ?><span><strong>Asesoría por WhatsApp</strong><small>Te respondemos rápido</small></span></li>
</ul>
