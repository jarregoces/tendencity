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
$departamentos = ['Amazonas','Antioquia','Arauca','Atlántico','Bogotá D.C.','Bolívar','Boyacá','Caldas','Caquetá','Casanare','Cauca','Cesar','Chocó','Córdoba','Cundinamarca','Guainía','Guaviare','Huila','La Guajira','Magdalena','Meta','Nariño','Norte de Santander','Putumayo','Quindío','Risaralda','San Andrés y Providencia','Santander','Sucre','Tolima','Valle del Cauca','Vaupés','Vichada'];
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

    <?php if (count($offers) > 1): ?>
    <fieldset class="order__group">
      <legend>¿Cuántas unidades?</legend>
      <?php foreach ($offers as $i => $o): ?>
        <label class="offer">
          <input type="radio" name="qty" value="<?= $o['qty'] ?>" <?= $i ? '' : 'checked' ?>>
          <span class="offer__box">
            <span class="offer__name"><?= $o['qty'] ?> <?= $o['qty'] > 1 ? 'unidades' : 'unidad' ?>
              <?php if ($o['label']): ?><em><?= e($o['label']) ?></em><?php endif; ?></span>
            <?php if ($o['pct']): ?><span class="offer__save">Ahorras <?= $o['pct'] ?>%</span><?php endif; ?>
            <span class="offer__price" data-offer-price="<?= $o['qty'] ?>"></span>
          </span>
        </label>
      <?php endforeach; ?>
    </fieldset>
    <?php else: ?>
      <input type="hidden" name="qty" value="1">
    <?php endif; ?>

    <fieldset class="order__group">
      <legend>Datos de entrega</legend>
      <label class="field"><span>Nombre y apellido</span>
        <input name="full_name" autocomplete="name" required minlength="5" placeholder="Ej: Carlos Pérez"></label>
      <label class="field"><span>Celular (WhatsApp)</span>
        <input name="phone" type="tel" inputmode="numeric" autocomplete="tel-national" required pattern="3[0-9]{9}" maxlength="10" placeholder="3001234567"></label>
      <div class="field-row">
        <label class="field"><span>Departamento</span>
          <select name="state" required autocomplete="address-level1">
            <option value="">Elige…</option>
            <?php foreach ($departamentos as $d): ?><option><?= e($d) ?></option><?php endforeach; ?>
          </select></label>
        <label class="field"><span>Ciudad o municipio</span>
          <input name="city" required autocomplete="address-level2"></label>
      </div>
      <label class="field"><span>Dirección</span>
        <input name="address1" required autocomplete="address-line1" placeholder="Calle 10 # 20-30"></label>
      <div class="field-row">
        <label class="field"><span>Barrio</span><input name="district"></label>
        <label class="field"><span>Apto, casa o referencia <small>(opcional)</small></span>
          <input name="address2" autocomplete="address-line2"></label>
      </div>
      <label class="field"><span>Correo <small>(opcional, para enviarte la guía)</small></span>
        <input name="email" type="email" autocomplete="email" inputmode="email"></label>
    </fieldset>

    <?php if (count($methods) > 1): ?>
    <fieldset class="order__group">
      <legend>¿Cómo quieres pagar?</legend>
      <label class="pay"><input type="radio" name="payment_method" value="cod" checked>
        <span><strong>Pago contraentrega</strong> En efectivo cuando recibes</span></label>
      <label class="pay"><input type="radio" name="payment_method" value="pia">
        <span><strong>Pago anticipado<?= $p['pia_discount'] ? ' – ' . (int) $p['pia_discount'] . '% menos' : '' ?></strong> Nequi, Daviplata o transferencia</span></label>
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
    <button type="submit" class="btn btn--grad btn--xl" data-submit>Confirmar pedido</button>
    <p class="order__fine">Te confirmamos por WhatsApp antes de despachar. Al pedir aceptas los <a href="/terminos/" target="_blank">términos</a> y la <a href="/politica-de-privacidad/" target="_blank">política de datos</a>.</p>
  </form>
</dialog>
