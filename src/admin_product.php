<?php
declare(strict_types=1);

/**
 * Editor de producto en 3 pasos:
 *   1. Lo básico (fotos, nombre, categoría, precio)   2. Para vender (gancho, beneficios, descripción)
 *   3. Imágenes de la descripción (piezas diseñadas)  + Opciones avanzadas plegadas.
 * Cada paso guarda solo sus campos: guardar uno no borra los demás.
 */

/** Campos de la carga masiva que guarda cada paso. */
const EDITOR_SECTIONS = [
    'basico' => ['titulo', 'categoria', 'sku', 'marca', 'costo', 'precio', 'stock', 'activo', 'con_publicidad', 'enfocado'],
    'venta'  => ['atencion_gancho', 'interes_beneficios', 'deseo_texto', 'atencion_titular', 'deseo_para_quien'],
];

/** Lo que le falta al producto para publicarlo y para pautarlo. */
function product_checklist(array $p): array
{
    $imgs = count(product_images($p));
    $benefits = count(lines($p['aida_benefits'] ?? ''));
    $body = count(json_decode($p['body_images'] ?? '[]', true) ?: []);
    $visual = $body >= 2 || !empty($p['features_media']) || !empty($p['usage_steps']);
    return [
        'publicar' => [
            ['Al menos una foto', $imgs >= 1, 'paso1'],
            ['Nombre', (bool) ($p['title'] ?: $p['name']), 'paso1'],
            ['Categoría', (bool) $p['category_id'], 'paso1'],
            ['Precio', (int) $p['price'] > 0, 'paso1'],
            ['Stock disponible', (int) $p['stock'] > 0, 'paso1'],
            ['Gancho (frase bajo el nombre)', (bool) $p['aida_hook'], 'paso2'],
            ['3 beneficios o más', $benefits >= 3, 'paso2'],
        ],
        'pauta' => [
            ['3 fotos o más', $imgs >= 3, 'paso1'],
            ['Costo (para ver tu margen)', (int) $p['cost'] > 0, 'paso1'],
            ['Descripción', (bool) $p['aida_desire'], 'paso2'],
            ['2 imágenes de la descripción (o bloques visuales)', $visual, 'paso3'],
            ['Descripción para Google', (bool) $p['meta_description'], 'avanzado'],
        ],
    ];
}

/** Etiqueta corta de estado para la lista de productos. */
function product_status_badge(array $p): string
{
    $c = product_checklist($p);
    $pub = !in_array(false, array_column($c['publicar'], 1), true);
    $ads = $pub && !in_array(false, array_column($c['pauta'], 1), true);
    $miss = count(array_filter(array_column($c['publicar'], 1), fn($x) => !$x));
    return $ads ? '<span class="pill-ok">Listo para pauta</span>'
         : ($pub ? '<span class="pill-wait">Listo para publicar</span>' : '<span class="bad">Le faltan ' . $miss . '</span>');
}

function page_producto(PDO $db): void
{
    $st = $db->prepare('SELECT * FROM products WHERE id = ?');
    $st->execute([(int) ($_GET['id'] ?? 0)]);
    $p = $st->fetch();
    if (!$p) { echo '<p>Producto no encontrado.</p>'; return; }
    [$cats, $catIds] = category_options($db);
    $chk = product_checklist($p);
    $okPub = !in_array(false, array_column($chk['publicar'], 1), true);
    $okAds = $okPub && !in_array(false, array_column($chk['pauta'], 1), true);
    $id = (int) $p['id'];
    $calc = $p['cost'] ? Pricing::calculate((int) $p['cost'], (bool) $p['with_ads']) : null;
    $body = json_decode($p['body_images'] ?? '[]', true) ?: [];
    $hidden = fn(string $section) => csrf_field() . '<input type="hidden" name="action" value="save_product"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="_section" value="' . $section . '">';
    ?>
    <div class="ed-head">
      <div><h1><?= e(product_title($p)) ?></h1>
        <p class="muted"><?= $p['is_active'] ? '<span class="pill-ok">Publicado</span>' : '<span class="pill-wait">Oculto</span>' ?>
          <?= $p['ms_product_id'] ? 'Vinculado a Mastershop' : 'Sin vincular a Mastershop' ?> · <a href="/p/<?= e($p['slug']) ?>/" target="_blank">Ver en la tienda</a></p></div>
      <nav class="ed-steps"><a href="#paso1"><b>1</b> Lo básico</a><a href="#paso2"><b>2</b> Para vender</a><a href="#paso3"><b>3</b> Imágenes</a><a href="?p=producto&id=<?= $id ?>&av=1#avanzado">Avanzado</a></nav>
    </div>

    <section class="box ed-check">
      <div><h2><?= $okPub ? '✓ Listo para publicar' : 'Para publicarlo le falta' ?></h2>
        <ul><?php foreach ($chk['publicar'] as [$t, $ok, $a]): ?><li class="<?= $ok ? 'ok' : 'no' ?>"><a href="<?= $a === 'avanzado' ? "?p=producto&id={$id}&av=1#avanzado" : "#{$a}" ?>"><?= $ok ? '✓' : '○' ?> <?= e($t) ?></a></li><?php endforeach; ?></ul></div>
      <div><h2><?= $okAds ? '✓ Listo para pauta' : 'Para pautarlo, además' ?></h2>
        <ul><?php foreach ($chk['pauta'] as [$t, $ok, $a]): ?><li class="<?= $ok ? 'ok' : 'no' ?>"><a href="<?= $a === 'avanzado' ? "?p=producto&id={$id}&av=1#avanzado" : "#{$a}" ?>"><?= $ok ? '✓' : '○' ?> <?= e($t) ?></a></li><?php endforeach; ?></ul></div>
    </section>

    <!-- ===================== PASO 1 ===================== -->
    <section class="box ed-step" id="paso1">
      <h2><b>1</b> Lo básico</h2>
      <?php product_photos($p); ?>
      <form method="post" class="form ed-form"><?= $hidden('basico') ?>
        <label>Nombre del producto <input name="titulo" value="<?= e($p['title'] ?: $p['name']) ?>" maxlength="160" required data-count="120"></label>
        <div class="row">
          <label>Categoría <select name="categoria" required><option value="">Elige…</option><?php foreach ($cats as $path => $label): ?><option value="<?= e($path) ?>" <?= $path === ($catIds[(int) $p['category_id']] ?? '') ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
          <label>Marca <small>(opcional)</small><input name="marca" value="<?= e((string) $p['brand']) ?>" maxlength="80"></label>
          <label>SKU <small>(opcional)</small><input name="sku" value="<?= e((string) $p['sku']) ?>" maxlength="120"></label>
        </div>
        <div class="row">
          <label>Costo <input name="costo" inputmode="numeric" value="<?= e((string) $p['cost']) ?>" <?= $p['ms_product_id'] ? 'readonly title="Viene de Mastershop"' : '' ?>></label>
          <label>Precio de venta <input name="precio" inputmode="numeric" value="<?= (int) $p['price'] ?>" required>
            <?php if ($calc): ?><small>Calculadora: <?= money($calc['precio']) ?></small><?php endif; ?></label>
          <label>Stock <input name="stock" inputmode="numeric" value="<?= (int) $p['stock'] ?>" <?= $p['ms_product_id'] ? 'readonly title="Viene de Mastershop"' : '' ?>></label>
        </div>
        <div class="row">
          <label class="check"><input type="checkbox" name="price_auto" value="1" <?= $p['price_auto'] ? 'checked' : '' ?>> Usar calculadora de precios</label>
          <label class="check"><input type="checkbox" name="con_publicidad" value="si" <?= $p['with_ads'] ? 'checked' : '' ?>> Lo voy a pautar (suma publicidad)</label>
          <label class="check"><input type="checkbox" name="activo" value="si" <?= $p['is_active'] ? 'checked' : '' ?>> Publicado en la tienda</label>
        </div>
        <label class="check"><input type="checkbox" name="enfocado" value="si" <?= $p['focus_mode'] ? 'checked' : '' ?>> Modo enfocado para anuncios: solo el logo arriba, sin menú, buscador ni otros productos</label>
        <button>Guardar paso 1</button>
      </form>
    </section>

    <!-- ===================== PASO 2 ===================== -->
    <section class="box ed-step" id="paso2">
      <h2><b>2</b> Para vender</h2>
      <p class="muted">Texto corto: es lo que Google lee y lo que el cliente ve primero. Lo demás puede ir en imágenes (paso 3).</p>
      <form method="post" class="form ed-form"><?= $hidden('venta') ?>
        <label>Gancho <small>(una o dos frases bajo el nombre)</small><textarea name="atencion_gancho" rows="2" data-count="220" placeholder="24 piezas con acabado espejo, en un estuche listo para regalar."><?= e((string) $p['aida_hook']) ?></textarea></label>
        <label>Beneficios <small>(uno por línea, de 3 a 5)</small><textarea name="interes_beneficios" rows="5" placeholder="Servicio completo para 6 personas&#10;Acabado espejo que se ve de restaurante&#10;Llega listo para regalar"><?= e((string) $p['aida_benefits']) ?></textarea></label>
        <label>Descripción <small>(qué es, cómo se usa, qué resuelve. Admite **negrita** y listas con -)</small><textarea name="deseo_texto" rows="6"><?= e((string) $p['aida_desire']) ?></textarea></label>
        <details class="ed-opt"><summary>Opcional: titular grande y «es ideal si…»</summary>
          <label>Titular grande <input name="atencion_titular" value="<?= e((string) $p['aida_headline']) ?>" placeholder="La mesa que todos van a fotografiar"></label>
          <label>Es ideal si… <small>(uno por línea)</small><textarea name="deseo_para_quien" rows="3"><?= e((string) $p['aida_for_whom']) ?></textarea></label>
        </details>
        <button>Guardar paso 2</button>
      </form>
    </section>

    <!-- ===================== PASO 3 ===================== -->
    <section class="box ed-step" id="paso3">
      <h2><b>3</b> Imágenes de la descripción <small class="muted" style="font-weight:400">(opcional)</small></h2>
      <p class="muted">Sube tus piezas diseñadas: características, ingredientes, forma de uso, comparativa, medidas. Se muestran en la página una debajo de otra, en este orden.
        Recomendado: 1080 px de ancho, letra grande y <strong>sin precios ni fechas</strong> (la página los pone y los actualiza sola).</p>
      <?php if ($body): ?>
        <ol class="ed-body">
          <?php foreach ($body as $i => $img): ?>
            <li><img src="<?= e($img['src']) ?>" alt="" width="120">
              <form method="post" class="ed-body__alt"><?= csrf_field() ?><input type="hidden" name="action" value="body_alt"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="i" value="<?= $i ?>">
                <input name="alt" value="<?= e($img['alt'] ?? '') ?>" maxlength="160" placeholder="¿Qué muestra? Ej. Medidas de cada pieza (Google lo lee)"><button class="ghost">Guardar</button></form>
              <form method="post" class="ed-body__btns"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="i" value="<?= $i ?>"><input type="hidden" name="dir" value="">
                <button name="action" value="body_move" class="ghost" <?= $i ? '' : 'disabled' ?> onclick="this.form.dir.value='up'">↑</button>
                <button name="action" value="body_move" class="ghost" <?= $i < count($body) - 1 ? '' : 'disabled' ?> onclick="this.form.dir.value='down'">↓</button>
                <button name="action" value="body_delete" class="ghost" onclick="return confirm('¿Quitar esta imagen?')">Quitar</button></form></li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="upload"><?= csrf_field() ?><input type="hidden" name="action" value="body_upload"><input type="hidden" name="id" value="<?= $id ?>">
        <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple required>
        <button>Subir imágenes</button><small>Puedes elegir varias a la vez. Se optimizan a WebP de 1200 px de ancho.</small></form>
    </section>

    <!-- ===================== AVANZADO ===================== -->
    <details class="box ed-adv" id="avanzado" <?= isset($_GET['av']) ? 'open' : '' ?>>
      <summary><h2 style="display:inline">Opciones avanzadas</h2> <small class="muted">SEO, oferta, página de venta, portada, bloques visuales, formulario de pedido y testimonios</small></summary>
      <?php advanced_form($db, $p, $hidden('avanzado'), $cats, $catIds); ?>
      <?php product_more($p); ?>
    </details>
    <?php
}

function advanced_form(PDO $db, array $p, string $hidden, array $cats, array $catIds): void
{
    $own = $p['qty_offers'] !== null ? (json_decode((string) $p['qty_offers'], true) ?: []) : [];
    $offersTxt = implode("\n", array_map(fn($o) => "{$o['qty']}:" . (!empty($o['total']) ? '=' . (int) $o['total'] : $o['pct']) . (!empty($o['label']) || !empty($o['pill']) ? ':' . ($o['label'] ?? '') : '') . (!empty($o['pill']) ? ":{$o['pill']}" : ''), $own));
    $faqTxt = implode("\n", array_map(fn($f) => "{$f['q']} :: {$f['a']}", faq_list($p['faq'])));
    $vars = $db->query('SELECT name, image FROM product_variants WHERE product_id = ' . (int) $p['id'] . ' AND name IS NOT NULL ORDER BY id')->fetchAll();
    $pics = array_values(array_unique(array_merge(product_images($p), array_column(json_decode($p['body_images'] ?? '[]', true) ?: [], 'src'),
        $db->query('SELECT image FROM product_variants WHERE product_id = ' . (int) $p['id'] . ' AND image IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN))));
    $in = HomeSlots::slotsOf((int) $p['id']);
    ?>
    <form method="post" class="form ed-form" style="max-width:none"><?= $hidden ?>
      <fieldset><legend>Página y Google</legend>
        <div class="row">
          <label class="check"><input type="checkbox" name="destacado" value="1" <?= $p['is_featured'] ? 'checked' : '' ?>> Destacado</label>
          <label>Etiqueta en la tarjeta <input name="etiqueta" value="<?= e((string) $p['badge']) ?>" maxlength="30" list="badges" placeholder="Nuevo, Más vendido…"></label>
        </div>
        <datalist id="badges"><option>Nuevo</option><option>Más vendido</option><option>Últimas unidades</option><option>Para regalar</option><option>Tendencia</option></datalist>
        <label>URL /p/ <input name="slug" value="<?= e($p['slug']) ?>"></label>
        <label>Título para Google <small>(vacío = el nombre)</small><input name="meta_title" value="<?= e((string) $p['meta_title']) ?>" data-count="60"></label>
        <label>Descripción para Google <textarea name="meta_description" rows="2" data-count="155"><?= e((string) $p['meta_description']) ?></textarea></label>
      </fieldset>
      <fieldset><legend>Oferta</legend>
        <div class="row">
          <label>Precio anterior real <input name="precio_antes" inputmode="numeric" value="<?= e((string) $p['price_before']) ?>"></label>
          <label>Oferta desde <input name="oferta_desde" type="date" value="<?= $p['offer_from'] ? e(substr($p['offer_from'], 0, 10)) : '' ?>"></label>
          <label>Oferta hasta <input name="oferta_hasta" type="date" value="<?= $p['offer_until'] ? e(substr($p['offer_until'], 0, 10)) : '' ?>"></label>
        </div>
        <label class="check"><input type="checkbox" name="oferta_general" value="1" <?= $p['qty_offers'] === null ? 'checked' : '' ?>> Usar la oferta por cantidad general de la tienda <small class="muted">(<?= e((string) setting('def_qty_offers', '')) ?: 'ninguna' ?>)</small></label>
        <label>…o una oferta propia <small>(cantidad:%:etiqueta:texto de ahorro, una por línea; vacía y sin la casilla = sin oferta)</small><textarea name="ofertas_cantidad" rows="2" placeholder="2:10:Más vendido&#10;3:15"><?= e($offersTxt) ?></textarea></label>
      </fieldset>
      <fieldset><legend>Formulario de pedido</legend>
        <div class="row">
          <label class="check"><input type="checkbox" name="contraentrega" value="si" <?= $p['allow_cod'] ? 'checked' : '' ?>> Contraentrega</label>
          <label class="check"><input type="checkbox" name="anticipado" value="si" <?= $p['allow_pia'] ? 'checked' : '' ?>> Pago anticipado</label>
          <label>% descuento anticipado <input name="descuento_anticipado" inputmode="numeric" value="<?= (int) $p['pia_discount'] ?>"></label>
        </div>
        <?php if (!$p['ms_product_id']): ?>
          <div class="vars"><input type="hidden" name="variantes_form" value="1">
            <p class="vars__t">Colores o variantes <small class="muted">(opcional; cada una puede mostrar su foto al elegirla)</small></p>
            <table class="vars__tb"><tbody>
              <?php foreach ($vars ?: [] as $v): ?>
                <tr><td><input name="variante_nombre[]" value="<?= e($v['name']) ?>" maxlength="60" placeholder="Ej. Oro rosa"></td>
                  <td><select name="variante_foto[]" class="vars__sel"><option value="">Sin foto</option>
                    <?php foreach ($pics as $k => $img): ?><option value="<?= e($img) ?>" <?= $img === $v['image'] ? 'selected' : '' ?>>Foto <?= $k + 1 ?></option><?php endforeach; ?></select></td>
                  <td><img class="vars__pv" src="<?= e((string) $v['image']) ?>" alt="" width="44" height="44" <?= $v['image'] ? '' : 'hidden' ?>></td>
                  <td><button type="button" class="ghost vars__rm" title="Quitar">✕</button></td></tr>
              <?php endforeach; ?>
            </tbody></table>
            <template id="var-row"><tr><td><input name="variante_nombre[]" maxlength="60" placeholder="Ej. Oro"></td>
              <td><select name="variante_foto[]" class="vars__sel"><option value="">Sin foto</option><?php foreach ($pics as $k => $img): ?><option value="<?= e($img) ?>">Foto <?= $k + 1 ?></option><?php endforeach; ?></select></td>
              <td><img class="vars__pv" src="" alt="" width="44" height="44" hidden></td><td><button type="button" class="ghost vars__rm" title="Quitar">✕</button></td></tr></template>
            <button type="button" class="ghost" id="var-add">+ Agregar color</button>
          </div>
        <?php endif; ?>
        <div class="row">
          <label>Peso con empaque (kg) <input name="peso" inputmode="decimal" value="<?= e((string) $p['weight']) ?>"></label>
          <?php if (!$p['ms_product_id']): ?><label>ID en Mastershop <small>(para vincular)</small><input name="ms_link" inputmode="numeric"></label><?php endif; ?>
        </div>
      </fieldset>
      <fieldset><legend>Cierre de venta</legend>
        <label>Garantía <small>(vacío = la general: «<?= e((string) setting('def_guarantee', '')) ?>»)</small><input name="accion_garantia" value="<?= e((string) $p['aida_guarantee']) ?>"></label>
        <label>Motivo real para decidir hoy <small>(opcional)</small><input name="accion_urgencia" value="<?= e((string) $p['aida_urgency']) ?>"></label>
        <label>Preguntas frecuentes de este producto <small>(Pregunta :: Respuesta, una por línea. Las generales de Ajustes se agregan solas)</small><textarea name="faq" rows="4"><?= e($faqTxt) ?></textarea></label>
      </fieldset>
      <fieldset id="bloques"><legend>Bloques visuales en texto <small class="muted">(alternativa a las imágenes del paso 3)</small></legend>
        <?php if ($pics): ?><div class="pics"><?php foreach ($pics as $k => $img): ?><button type="button" class="pic" data-path="<?= e($img) ?>" title="Copiar ruta"><img src="<?= e($img) ?>" alt="" width="60" height="60"><span>Foto <?= $k + 1 ?> · copiar</span></button><?php endforeach; ?></div><?php endif; ?>
        <label>Características <small>(Clave: valor)</small><textarea name="caracteristicas" rows="3"><?= e((string) $p['specs']) ?></textarea></label>
        <label>Qué trae la caja <small>(empieza por la cantidad)</small><textarea name="contenido_caja" rows="3"><?= e((string) $p['box_contents']) ?></textarea></label>
        <label>Medidas <small>(Alto/Ancho/Fondo o por piezas)</small><textarea name="medidas" rows="3"><?= e((string) $p['measures']) ?></textarea></label>
        <label>Comparación <small>(primera fila: títulos; columnas con |; si / no para ✓ ✕)</small><textarea name="comparacion" rows="4"><?= e((string) $p['comparison']) ?></textarea></label>
        <label>Fotos de la comparación <small>(tu producto | otros)</small><input name="comparacion_fotos" value="<?= e((string) $p['cmp_images']) ?>"></label>
        <label>Características con foto <small>(Título | texto | ruta de la foto)</small><textarea name="caracteristicas_foto" rows="3"><?= e((string) $p['features_media']) ?></textarea></label>
        <label>Formas de uso <small>(Título | texto | ruta de la foto)</small><textarea name="formas_uso" rows="3"><?= e((string) $p['usage_steps']) ?></textarea></label>
      </fieldset>
      <fieldset><legend>Mostrar en la portada</legend><input type="hidden" name="portada_form" value="1">
        <div class="row"><?php foreach (HomeSlots::SLOTS as $sk => [$sn]): ?><label class="check"><input type="checkbox" name="portada[<?= e($sk) ?>]" value="1" <?= in_array($sk, $in, true) ? 'checked' : '' ?>> <?= e($sn) ?></label><?php endforeach; ?></div>
      </fieldset>
      <button class="big">Guardar opciones avanzadas</button>
    </form>
    <script>
    document.querySelectorAll('.pic').forEach(b=>b.addEventListener('click',()=>{navigator.clipboard.writeText(b.dataset.path);const s=b.querySelector('span'),t=s.textContent;s.textContent='¡Copiada!';setTimeout(()=>s.textContent=t,1400);}));
    (() => {
      const tb = document.querySelector('.vars__tb tbody'); if (!tb) return;
      const wire = (tr) => {
        tr.querySelector('.vars__rm').onclick = () => tr.remove();
        const sel = tr.querySelector('.vars__sel'), pv = tr.querySelector('.vars__pv');
        sel.onchange = () => { pv.hidden = !sel.value; if (sel.value) pv.src = sel.value; };
      };
      tb.querySelectorAll('tr').forEach(wire);
      document.getElementById('var-add').onclick = () => {
        const tr = document.getElementById('var-row').content.firstElementChild.cloneNode(true);
        tb.appendChild(tr); wire(tr); tr.querySelector('input').focus();
      };
    })();
    </script>
    <?php
}

/** Acciones de las imágenes de la descripción. */
function handle_body_post(PDO $db, string $action): never
{
    $id = (int) $_POST['id'];
    $st = $db->prepare('SELECT body_images FROM products WHERE id = ?'); $st->execute([$id]);
    $list = json_decode((string) $st->fetchColumn(), true) ?: [];
    $i = (int) ($_POST['i'] ?? -1);
    if ($action === 'body_upload') {
        $files = $_FILES['images'] ?? []; $ok = 0; $errors = [];
        foreach ((array) ($files['tmp_name'] ?? []) as $k => $tmp) {
            try { $list[] = ['src' => ImageUpload::store(['tmp_name' => $tmp, 'error' => $files['error'][$k], 'size' => $files['size'][$k]], $id, 'products', true), 'alt' => '']; $ok++; }
            catch (RuntimeException $e) { $errors[] = ($files['name'][$k] ?? 'archivo') . ': ' . $e->getMessage(); }
        }
        flash($errors ? "Subidas {$ok}. " . implode(' ', $errors) : "{$ok} imágenes subidas. Escribe qué muestra cada una para que Google la entienda.", $errors ? 'bad' : 'ok');
    } elseif ($action === 'body_alt' && isset($list[$i])) {
        $list[$i]['alt'] = mb_substr(trim((string) $_POST['alt']), 0, 160);
    } elseif ($action === 'body_delete' && isset($list[$i])) {
        ImageUpload::delete($list[$i]['src'], $id);
        array_splice($list, $i, 1);
    } elseif ($action === 'body_move' && isset($list[$i])) {
        $j = ($_POST['dir'] ?? '') === 'up' ? $i - 1 : $i + 1;
        if (isset($list[$j])) { [$list[$i], $list[$j]] = [$list[$j], $list[$i]]; }
    }
    $db->prepare('UPDATE products SET body_images = ? WHERE id = ?')->execute([$list ? json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $id]);
    back("?p=producto&id={$id}#paso3");
}
