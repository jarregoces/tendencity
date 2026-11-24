<?php
/** @var array $p  @var array $variants  @var string $title  @var ?string $image */
$offers = Pricing::qtyOffers($p);
$sellable = array_values(array_filter($variants, fn($v) => (int) $v['stock'] > 0));
$methods = array_values(array_filter(['cod' => (bool) $p['allow_cod'], 'pia' => (bool) $p['allow_pia']]));
$cfg = [
    'productId' => (int) $p['id'],
    'msId'      => (string) ($p['sku'] ?: ($p['ms_product_id'] ?: $p['id'])),
    'price'     => (int) $p['price'],
    'variants'  => array_map(fn($v) => ['id' => (int) $v['id'], 'price' => $v['price'] !== null ? (int) $v['price'] : null], $sellable),
    'offers'    => $offers,
    'piaPct'    => $p['allow_pia'] ? (int) $p['pia_discount'] : 0,
    'flat'      => (int) setting('shipping_flat', '0'),
    'freeFrom'  => (int) setting('shipping_free_from', '0'),
];
$departamentos = Country::get('regions');
// Nombre corto para las tarjetas de oferta: "Kit Ritual Glow Body: Aclara…" → "Kit Ritual Glow Body"
$short = trim(preg_split('/\s*(?::|\s[–—|-]\s)\s*/u', $title, 2)[0]) ?: $title;
$svg = fn(string $d, int $s = 20) => '<svg viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="' . $d . '"/></svg>';
$icons = [
    'user'  => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8a7 7 0 0 1 14 0',
    'phone' => 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2',
    'map'   => 'M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2Zm0 0v14m6-12v14',
    'pin'   => 'M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11Zm0-8a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
    'home'  => 'M4 11 12 4l8 7v9h-5v-6H9v6H4v-9Z',
    'mail'  => 'M4 6h16v12H4zM4 7l8 6 8-6',
];
$req = '<b class="req" aria-hidden="true">*</b>';
?>
<dialog class="order" id="order" aria-labelledby="order-t">
  <form class="order__form" method="post" action="/pedido" novalidate data-cfg='<?= e(json_encode($cfg)) ?>'>
    <header class="order__head">
      <?php if ($image): ?><img src="<?= e($image) ?>" alt="" width="56" height="56"><?php endif; ?>
      <h2 id="order-t"><?= e($title) ?></h2>
      <button type="button" class="order__close" data-close aria-label="Cerrar">×</button>
    </header>

    <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
    <div class="hp" aria-hidden="true"><label>Sitio web <input name="website" tabindex="-1" autocomplete="off"></label></div>

    <div class="order__hero">
      <span class="order__hero-ico"><?= $svg('M12 3 4 7v10l8 4 8-4V7l-8-4Zm0 0v18M4 7l8 4 8-4', 30) ?></span>
      <div><strong>¡Ya casi es tuyo!</strong>
        <span>Solo faltan tus datos. <?= shipping_included() ? '<em>Envío gratis</em>' : '<em>Envío ' . e(Country::toAll()) . '</em>' ?><?= $p['allow_cod'] ? ' y pagas al recibir.' : '.' ?></span></div>
    </div>

    <?php if (count($offers) > 1): ?>
    <p class="order__more"><span aria-hidden="true">🔺</span> Entre más llevas, <strong>menos pagas</strong> por cada uno</p>
    <?php endif; ?>
    <fieldset class="order__group offers">
      <legend class="sr">¿Cuántas unidades?</legend>
      <?php foreach ($offers as $i => $o):
          $pill = $o['pill'] !== '' ? $o['pill']
              : ($o['qty'] === 1 ? '¡Pídelo ahora!' : ($o['qty'] === 2 && $o['pct'] ? 'Ahorra ' . ($o['pct'] * 2) . '% en el segundo' : ($o['pct'] ? "Ahorras {$o['pct']}%" : ''))); ?>
        <label class="offer">
          <input type="radio" name="qty" value="<?= $o['qty'] ?>" <?= $i ? '' : 'checked' ?>>
          <span class="offer__box">
            <?php if ($image): ?><img class="offer__img" src="<?= e($image) ?>" alt="" width="72" height="72" loading="lazy"><?php endif; ?>
            <span class="offer__body">
              <span class="offer__name"><?= $o['qty'] === 1 ? 'Lleva 1 ' . e($short) : ($o['label'] !== '' ? '<b>' . e($o['label']) . '</b> ' : '') . $o['qty'] . ' ' . e($short) ?></span>
              <span class="offer__prices"><s data-offer-before="<?= $o['qty'] ?>"></s> <strong class="offer__price" data-offer-price="<?= $o['qty'] ?>"></strong></span>
            </span>
            <span class="offer__radio" aria-hidden="true"></span>
            <?php if ($pill !== ''): ?><span class="offer__pill<?= $o['qty'] === 1 ? ' offer__pill--soft' : '' ?>"><?= e($pill) ?></span><?php endif; ?>
          </span>
        </label>
      <?php endforeach; ?>
    </fieldset>

    <?php if (count($sellable) > 1): ?>
    <fieldset class="order__group">
      <legend>Elige una opción</legend>
      <div class="pills">
        <?php foreach ($sellable as $i => $v): ?>
          <label class="pill"><input type="radio" name="variant_id" value="<?= (int) $v['id'] ?>" <?= $i ? '' : 'checked' ?>><span><?= e($v['name'] ?: 'Opción ' . ($i + 1)) ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <?php elseif ($sellable): ?>
      <input type="hidden" name="variant_id" value="<?= (int) $sellable[0]['id'] ?>">
    <?php endif; ?>


    <fieldset class="order__group">
      <legend>Datos de entrega</legend>
      <label class="field"><span>Nombre y apellido <?= $req ?></span>
        <span class="field__ctl"><?= $svg($icons['user']) ?><input name="full_name" autocomplete="name" required minlength="5" placeholder="Nombre y apellido"></span></label>
      <label class="field"><span>Celular (WhatsApp) <?= $req ?></span>
        <span class="field__ctl"><?= $svg($icons['phone']) ?><input name="phone" type="tel" inputmode="numeric" autocomplete="tel-national" required pattern="<?= e(Country::get('phone_pattern')) ?>" maxlength="<?= (int) Country::get('phone_digits') ?>" placeholder="<?= e(Country::get('phone_example')) ?>"></span></label>
      <div class="field-row">
        <label class="field"><span><?= e(Country::get('region_label')) ?> <?= $req ?></span>
          <span class="field__ctl"><?= $svg($icons['map']) ?><select name="state" required autocomplete="address-level1">
            <option value=""><?= e(Country::get('region_label')) ?></option>
            <?php foreach ($departamentos as $d): ?><option><?= e($d) ?></option><?php endforeach; ?>
          </select></span></label>
        <label class="field"><span><?= e(Country::get('city_label')) ?> <?= $req ?></span>
          <span class="field__ctl"><?= $svg($icons['pin']) ?><input name="city" required autocomplete="address-level2" placeholder="<?= e(Country::get('city_label')) ?>"></span></label>
      </div>
      <label class="field"><span>Dirección <?= $req ?></span>
        <span class="field__ctl"><?= $svg($icons['home']) ?><input name="address1" required autocomplete="address-line1" placeholder="<?= e(Country::get('address_example')) ?>"></span></label>
      <div class="field-row">
        <label class="field"><span><?= e(Country::get('district_label')) ?></span><span class="field__ctl"><?= $svg($icons['pin']) ?><input name="district" placeholder="<?= e(Country::get('district_label')) ?>"></span></label>
        <label class="field"><span>Apto, casa o referencia <small>(opcional)</small></span>
          <span class="field__ctl"><?= $svg($icons['home']) ?><input name="address2" autocomplete="address-line2" placeholder="Referencia"></span></label>
      </div>
      <label class="field"><span>Correo <small>(opcional, para enviarte la guía)</small></span>
        <span class="field__ctl"><?= $svg($icons['mail']) ?><input name="email" type="email" autocomplete="email" inputmode="email" placeholder="Correo"></span></label>
    </fieldset>

    <?php if (count($methods) > 1): ?>
    <fieldset class="order__group">
      <legend>¿Cómo quieres pagar?</legend>
      <label class="pay"><input type="radio" name="payment_method" value="cod" checked>
        <span><strong>Pago contraentrega</strong> En efectivo cuando recibes</span></label>
      <label class="pay"><input type="radio" name="payment_method" value="pia">
        <span><strong>Pago anticipado<?= $p['pia_discount'] ? ' – ' . (int) $p['pia_discount'] . '% menos' : '' ?></strong> <?= e(Country::get('pia_methods')) ?></span></label>
    </fieldset>
    <?php else: ?>
      <input type="hidden" name="payment_method" value="<?= $p['allow_cod'] ? 'cod' : 'pia' ?>">
    <?php endif; ?>

    <dl class="summary">
      <div><dt>Productos</dt><dd data-sum="sub"></dd></div>
      <div><dt>Envío</dt><dd data-sum="ship"></dd></div>
      <div class="summary__total"><dt>Total</dt><dd data-sum="total"></dd></div>
    </dl>

    <p class="order__error" role="alert" hidden></p>
    <button type="submit" class="btn btn--buy btn--xl" data-submit>Completa tu compra - <span data-sum="btn"></span></button>

    <ul class="order__trust" aria-label="Compra con confianza">
      <li><?= $svg('M12 3l2.4 1.8 3-.2 1 2.8 2.4 1.8-.9 2.8.9 2.8-2.4 1.8-1 2.8-3-.2L12 21l-2.4-1.8-3 .2-1-2.8L3.2 14.8l.9-2.8-.9-2.8 2.4-1.8 1-2.8 3 .2L12 3Zm-3.5 9 2.3 2.3 4.7-4.6', 34) ?><span>Garantía de satisfacción</span></li>
      <li><?= $svg('M3 4h2l2.4 10.5a1.5 1.5 0 0 0 1.5 1.2h8.2a1.5 1.5 0 0 0 1.5-1.2L20 8H6.2M9 20.5a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1Zm8 0a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1Zm-.5-15.5 2 1v2.2c0 1.5-.9 2.6-2 3-1.1-.4-2-1.5-2-3V6l2-1Z', 34) ?><span>Compra segura</span></li>
      <li><?= $svg('M2 7h11v9H2zM13 10h4l3 3v3h-7M5.5 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm11 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM5 10h4M5 13h3', 34) ?><span>Pide y paga contra entrega</span></li>
      <li><?= $svg($icons['pin'], 34) ?><span>Envíos <?= e(Country::toAll()) ?></span></li>
    </ul>
    <p class="order__fine">Te confirmamos por WhatsApp antes de despachar. Al pedir aceptas los <a href="/terminos/" target="_blank">términos</a> y la <a href="/politica-de-privacidad/" target="_blank">política de datos</a>.</p>
  </form>
</dialog>
