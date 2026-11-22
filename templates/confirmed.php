<?php
/** @var string $ref, $key */
$ext = strtoupper($ref);
$meta = ['title' => 'Pedido recibido | Tendencity', 'robots' => 'noindex, nofollow'];
$order = null;
if (hash_equals(order_key($ext), $key)) {
    $st = db()->prepare('SELECT id, external_id, total, subtotal, shipping_charge, payment_method, payment_status, customer, shipping_address, created_at FROM orders WHERE external_id = ?');
    $st->execute([$ext]);
    $order = $st->fetch();
}
if ($order) {
    // Purchase con el número de pedido como eventID: si recargan la página, Meta y GA4 no lo cuentan dos veces
    $items = db()->query('SELECT COALESCE(p.sku, i.ms_product_id, i.product_id) AS ms_product_id, i.name, i.quantity, i.unit_price
                          FROM order_items i LEFT JOIN products p ON p.id = i.product_id WHERE i.order_id = ' . (int) $order['id'])->fetchAll();
    $meta['events'] = [['Purchase', [
        'value' => (int) $order['total'], 'currency' => Country::currency(), 'content_type' => 'product',
        'content_ids' => array_map(fn($i) => (string) $i['ms_product_id'], $items),
        'num_items' => array_sum(array_column($items, 'quantity')),
        'items' => array_map(fn($i) => ['item_id' => (string) $i['ms_product_id'], 'item_name' => $i['name'], 'quantity' => (int) $i['quantity'], 'price' => (int) $i['unit_price']], $items),
    ], $order['external_id']]];
}
$lines = []; $related = [];
if ($order) {
    $lines = db()->query('SELECT i.name, i.quantity, i.unit_price, p.slug, p.images, p.custom_images, p.category_id, p.id pid
                          FROM order_items i LEFT JOIN products p ON p.id = i.product_id WHERE i.order_id = ' . (int) $order['id'])->fetchAll();
    $first = $lines[0] ?? null;
    if ($first && $first['category_id']) {
        $cat = new Catalog(db());
        $related = $cat->related(['id' => (int) $first['pid'], 'category_id' => (int) $first['category_id']], 4);
    }
}
$c = $order ? (json_decode($order['customer'], true) ?: []) : [];
$a = $order ? (json_decode($order['shipping_address'], true) ?: []) : [];
$phone = preg_replace('/^(\d{3})(\d{3})(\d{4})$/', '$1 $2 $3', (string) ($c['phone'] ?? ''));
$isPia = $order && $order['payment_method'] === 'pia' && $order['payment_status'] !== 'pagado';
$ico = fn($d) => '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="' . $d . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
?>
<?php if (!$order): ?>
<section class="wrap thanks">
  <div class="thanks__card thanks__empty">
    <h1>No encontramos ese pedido</h1>
    <p>Si acabas de hacer un pedido, escríbenos a soporte y lo revisamos de inmediato.</p>
    <div class="thanks__btns"><a class="btn btn--grad" href="/">Ir a la tienda</a>
      <a class="btn btn--line" href="<?= e(wa_link('Hola, acabo de hacer un pedido y no veo la confirmación')) ?>" rel="noopener" target="_blank">Soporte <?= e(wa_display()) ?></a></div>
  </div>
</section>
<?php else: ?>
<section class="wrap thanks">
  <header class="thanks__head">
    <div class="thanks__check" aria-hidden="true"><svg viewBox="0 0 52 52"><defs><linearGradient id="tcg" x1="0" x2="1"><stop offset="0" stop-color="#3bb4f2"/><stop offset=".55" stop-color="#8e62f0"/><stop offset="1" stop-color="#e05ff0"/></linearGradient></defs><circle cx="26" cy="26" r="25"/><path d="M15 27l7 7 15-16"/></svg></div>
    <p class="thanks__eyebrow">Pedido recibido</p>
    <h1>¡Gracias, <?= e($c['first_name'] ?? '') ?>!</h1>
    <p class="thanks__lead"><?= $isPia ? 'Tu pedido quedó reservado. Lo despachamos apenas confirmemos tu pago.' : 'Tu pedido quedó registrado. Te escribimos por WhatsApp para confirmarlo antes de enviarlo.' ?></p>
    <button type="button" class="thanks__ref" data-copy="<?= e($order['external_id']) ?>" title="Copiar número de pedido">Pedido <strong><?= e($order['external_id']) ?></strong> <span>Copiar</span></button>
  </header>

  <div class="thanks__grid">
    <section class="thanks__card">
      <h2>Qué sigue</h2>
      <ol class="timeline">
        <li class="is-on"><span class="timeline__dot"><?= $ico('M20 6L9 17l-5-5') ?></span><div><strong>Recibimos tu pedido</strong><p><?= e(date('d/m/Y \a \l\a\s g:i a', strtotime($order['created_at']))) ?></p></div></li>
        <?php if ($isPia): ?>
        <li><span class="timeline__dot"><?= $ico('M3 7h18v10H3zM3 11h18') ?></span><div><strong>Haz tu pago</strong><p><?= e(setting('pia_instructions')) ?></p>
          <a class="btn btn--grad btn--sm" href="<?= e(wa_link("Hola, quiero pagar mi pedido {$order['external_id']}")) ?>" rel="noopener" target="_blank">Enviar comprobante</a></div></li>
        <?php else: ?>
        <li><span class="timeline__dot"><?= $ico('M21 12a9 9 0 1 1-3.5-7.1L21 3v6h-6') ?></span><div><strong>Confirmamos por WhatsApp</strong><p>Te escribimos al <strong><?= e($phone) ?></strong> para confirmar la dirección. Responde para que despachemos más rápido.</p></div></li>
        <?php endif; ?>
        <li><span class="timeline__dot"><?= $ico('M3 7h11v9H3zM14 10h4l3 3v3h-7M7 19a2 2 0 1 0 0-.1M17 19a2 2 0 1 0 0-.1') ?></span><div><strong>Despachamos tu pedido</strong><p>Te enviamos el número de guía para que sigas el envío. <?= shipping_included() ? 'El envío ya está incluido.' : '' ?></p></div></li>
        <li><span class="timeline__dot"><?= $ico('M12 21s-7-4.5-7-11a7 7 0 0 1 14 0c0 6.5-7 11-7 11zM12 12a2 2 0 1 0 0-4 2 2 0 0 0 0 4z') ?></span><div><strong><?= $isPia ? 'Lo recibes en tu casa' : 'Lo recibes y pagas' ?></strong>
          <p><?= $isPia ? 'Llega a la dirección que nos diste.' : 'Ten listos <strong>' . money($order['total']) . '</strong> en efectivo para el transportador.' ?></p></div></li>
      </ol>
    </section>

    <aside class="thanks__card thanks__summary">
      <h2>Resumen del pedido</h2>
      <ul class="sum-items">
        <?php foreach ($lines as $l): $img = product_image($l); ?>
          <li><?php if ($img): ?><img src="<?= e($img) ?>" alt="" width="64" height="64"><?php else: ?><span class="sum-noimg"></span><?php endif; ?>
            <span class="sum-name"><?= e($l['name']) ?><small><?= (int) $l['quantity'] ?> × <?= money($l['unit_price']) ?></small></span>
            <strong><?= money((int) $l['quantity'] * (int) $l['unit_price']) ?></strong></li>
        <?php endforeach; ?>
      </ul>
      <dl class="sum-tot">
        <div><dt>Productos</dt><dd><?= money($order['subtotal']) ?></dd></div>
        <div><dt>Envío</dt><dd class="ok"><?= (int) $order['shipping_charge'] ? money($order['shipping_charge']) : 'Incluido ' . e(Country::toAll()) ?></dd></div>
        <div class="sum-total"><dt>Total</dt><dd><?= money($order['total']) ?></dd></div>
      </dl>
      <div class="sum-meta">
        <div><h3>Entrega</h3><p><?= e(trim(($c['full_name'] ?? '') )) ?><br><?= e($a['address1'] ?? '') ?><?= !empty($a['address2']) ? ', ' . e($a['address2']) : '' ?><?= !empty($a['district']) ? '<br>' . e($a['district']) : '' ?><br><?= e(($a['city'] ?? '') . ', ' . ($a['state'] ?? '')) ?></p></div>
        <div><h3>Pago</h3><p><?= $order['payment_method'] === 'cod' ? 'Contraentrega, en efectivo' : ($isPia ? 'Pago anticipado (pendiente)' : 'Pago anticipado (recibido)') ?></p></div>
      </div>
    </aside>
  </div>

  <div class="thanks__btns">
    <a class="btn btn--grad" href="/">Seguir comprando</a>
    <a class="btn btn--line" href="<?= e(wa_link("Hola, tengo una pregunta sobre mi pedido {$order['external_id']}")) ?>" rel="noopener" target="_blank">¿Dudas? Soporte <span class="thanks__num"><?= e(wa_display()) ?></span></a>
  </div>
  <p class="thanks__fine">Guarda tu número de pedido. Si cambias de opinión, escríbenos antes del despacho.</p>
</section>

<?php if ($related): ?>
<section class="wrap section">
  <div class="section__head"><h2>También te puede interesar</h2></div>
  <?php partial('grid', ['products' => $related]); ?>
</section>
<?php endif; ?>
<script>
document.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', () => {
  navigator.clipboard?.writeText(b.dataset.copy); const s = b.querySelector('span'); s.textContent = 'Copiado'; setTimeout(() => s.textContent = 'Copiar', 1500);
}));
</script>
<?php endif; ?>
