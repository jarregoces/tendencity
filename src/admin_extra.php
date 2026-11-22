<?php
declare(strict_types=1);

/**
 * Pantallas del admin: Inicio (dashboard), Fuentes de tráfico, Promoción, Páginas,
 * y el bloque de fotos / promoción / enlaces de campaña del editor de producto.
 * Lo incluye public/admin/index.php.
 */

const STATIC_PAGES = [
    'envios-y-pagos' => 'Envíos y pagos', 'garantias-y-devoluciones' => 'Garantías y devoluciones',
    'contacto' => 'Contacto', 'terminos' => 'Términos y condiciones', 'politica-de-privacidad' => 'Política de privacidad',
];

function pct(?float $v, int $dec = 1): string
{
    return $v === null ? '—' : number_format($v, $dec, ',', '.') . '%';
}

function num(int|float|null $v): string
{
    return number_format((float) $v, 0, ',', '.');
}

/** Selector de período compartido por Inicio y Fuentes. */
function period_bar(string $page): array
{
    $key = (string) ($_GET['r'] ?? '7');
    [$from, $to, $label] = Stats::range($key, $_GET['desde'] ?? null, $_GET['hasta'] ?? null);
    ?>
    <form class="period" method="get">
      <input type="hidden" name="p" value="<?= e($page) ?>">
      <?php foreach (['hoy' => 'Hoy', 'ayer' => 'Ayer', '7' => '7 días', '30' => '30 días', '90' => '90 días'] as $k => $l): ?>
        <a href="?p=<?= e($page) ?>&r=<?= $k ?>" class="<?= $key === (string) $k ? 'on' : '' ?>"><?= $l ?></a>
      <?php endforeach; ?>
      <input type="date" name="desde" value="<?= e($_GET['desde'] ?? '') ?>" aria-label="Desde">
      <input type="date" name="hasta" value="<?= e($_GET['hasta'] ?? '') ?>" aria-label="Hasta">
      <button name="r" value="rango" class="ghost">Ver rango</button>
    </form>
    <?php
    return [$from, $to, $label];
}

/**
 * Gráfica en SVG sin librerías: curva de visitas (área + línea) y barras de pedidos.
 * Cada punto tiene su detalle al pasar el mouse.
 */
function svg_daily(array $series, bool $hourly = false): string
{
    $n = count($series);
    if (!$n) return '<p class="muted">Sin datos en este período.</p>';
    $W = 760; $H = 220; $L = 40; $R = 36; $T = 14; $B = 28;
    $pw = $W - $L - $R; $ph = $H - $T - $B;
    $vis = array_column($series, 'visitas');
    $ord = array_column($series, 'pedidos');
    $niceMax = function (int $m): int { if ($m <= 4) return max(1, $m); $p = 10 ** floor(log10($m)); foreach ([1, 2, 2.5, 5, 10] as $f) { if ($m <= $f * $p) return (int) ($f * $p); } return $m; };
    $maxV = $niceMax(max($vis)); $maxO = $niceMax(max(1, max($ord)));
    $x = fn(int $i) => $L + ($n === 1 ? $pw / 2 : $i * $pw / ($n - 1));
    $yV = fn(int $v) => $T + $ph - $v / $maxV * $ph;
    $slot = $pw / max(1, $n);
    $keys = array_keys($series);
    $label = fn(string $k) => $hourly ? substr($k, 11, 2) . 'h' : date('d/m', strtotime($k));

    $svg = "<svg viewBox=\"0 0 {$W} {$H}\" class=\"chart\" role=\"img\" aria-label=\"Visitas y pedidos\">"
         . '<defs><linearGradient id="cl" x1="0" x2="1"><stop offset="0" stop-color="#3bb4f2"/><stop offset=".55" stop-color="#8e62f0"/><stop offset="1" stop-color="#e05ff0"/></linearGradient>'
         . '<linearGradient id="ca" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#8e62f0" stop-opacity=".28"/><stop offset="1" stop-color="#8e62f0" stop-opacity="0"/></linearGradient></defs>';
    // Cuadrícula y eje de visitas (izquierda) y pedidos (derecha)
    for ($g = 0; $g <= 4; $g++) {
        $gy = $T + $ph * $g / 4;
        $svg .= sprintf('<line x1="%d" x2="%d" y1="%.1f" y2="%.1f" stroke="#eeeaf5"/>', $L, $W - $R, $gy, $gy);
        $svg .= sprintf('<text x="%d" y="%.1f" font-size="10" text-anchor="end" fill="#8e62f0">%s</text>', $L - 6, $gy + 3, num((int) round($maxV * (4 - $g) / 4)));
        if ($maxO > 1 || $g === 0 || $g === 4) {
            $svg .= sprintf('<text x="%d" y="%.1f" font-size="10" fill="#c24dd3">%s</text>', $W - $R + 6, $gy + 3, num((int) round($maxO * (4 - $g) / 4)));
        }
    }
    // Barras de pedidos
    foreach ($ord as $i => $o) {
        if (!$o) continue;
        $bh = $o / $maxO * $ph; $bw = max(3, min(22, $slot * .45));
        $svg .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="3" fill="#e05ff0" opacity=".85"/>', $x($i) - $bw / 2, $T + $ph - $bh, $bw, $bh);
    }
    // Curva de visitas
    $pts = array_map(fn($i) => sprintf('%.1f,%.1f', $x($i), $yV($vis[$i])), array_keys($vis));
    if ($n > 1) {
        $svg .= '<path d="M' . sprintf('%.1f,%d', $x(0), $T + $ph) . ' L' . implode(' L', $pts) . ' L' . sprintf('%.1f,%d', $x($n - 1), $T + $ph) . ' Z" fill="url(#ca)"/>';
        $svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="url(#cl)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>';
    }
    foreach ($vis as $i => $v) {
        $svg .= sprintf('<circle cx="%.1f" cy="%.1f" r="%s" fill="#fff" stroke="#8e62f0" stroke-width="2"/>', $x($i), $yV($v), $n > 40 ? '1.5' : '3');
    }
    // Etiquetas del eje X (máx. ~12) y zonas de detalle al pasar el mouse
    $every = (int) ceil($n / 12);
    foreach ($keys as $i => $k) {
        if ($i % $every === 0 || $i === $n - 1) {
            $svg .= sprintf('<text x="%.1f" y="%d" font-size="10" text-anchor="middle" fill="#888">%s</text>', $x($i), $H - 8, $label($k));
        }
        $s = $series[$k];
        $tip = e(($hourly ? $label($k) . ' · ' : date('d/m/Y', strtotime($k)) . ' · ') . num($s['visitas']) . ' visitas · ' . num($s['pedidos']) . ' pedidos · ' . money($s['ventas']));
        $svg .= sprintf('<rect class="hit" x="%.1f" y="%d" width="%.1f" height="%d" fill="transparent"><title>%s</title></rect>', $x($i) - $slot / 2, $T, $slot, $ph, $tip);
    }
    $total = array_sum($vis);
    return $svg . '</svg><p class="legend"><span class="sw sw--line"></span>Visitas (' . num($total) . ') <span class="sw sw--o"></span>Pedidos (' . num(array_sum($ord)) . ')'
         . ($hourly ? ' · por hora' : ' · por día') . '</p>';
}

function channel_table(array $rows): void
{ ?>
  <table class="t">
    <thead><tr><th>Canal</th><th>Visitas</th><th>Pedidos</th><th>Conversión</th><th>Ventas</th><th>Entregados</th><th>Devueltos</th><th>Tasa de entrega</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><strong><?= e($r['nombre'] ?? $r['clave']) ?></strong></td><td><?= num($r['visitas']) ?></td><td><?= num($r['pedidos']) ?></td>
        <td><?= pct($r['conversion'], 2) ?></td><td><?= money($r['ventas']) ?></td><td><?= num($r['entregados']) ?></td><td><?= num($r['devueltos']) ?></td>
        <td class="<?= $r['tasa_entrega'] !== null && $r['tasa_entrega'] < 70 ? 'bad' : '' ?>"><?= pct($r['tasa_entrega'], 0) ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="8">Todavía no hay visitas en este período.</td></tr><?php endif; ?>
    </tbody>
  </table>
<?php }

/* ====================================================================== */

function page_inicio(PDO $db): void
{
    echo '<h1>Dashboard</h1>';
    [$from, $to, $label] = period_bar('inicio');
    $s = new Stats($db, $from, $to);
    $k = $s->kpis();
    $alerts = Stats::alerts($db);
    $f = $s->funnel();
    ?>
    <?php if ($alerts): ?>
    <div class="alerts">
      <?php foreach ($alerts as [$n, $text, $url]): ?><a href="<?= e($url) ?>"><strong><?= num($n) ?></strong> <?= e($text) ?></a><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="kpis">
      <div><span>Visitas</span><strong><?= num($k['visitas']) ?></strong><small><?= num($k['visitantes']) ?> personas</small></div>
      <div><span>Pedidos</span><strong><?= num($k['pedidos']) ?></strong><small>Conversión <?= pct($k['conversion'], 2) ?></small></div>
      <div><span>Ventas</span><strong><?= money($k['ventas']) ?></strong><small>Ticket <?= $k['ticket'] ? money((int) $k['ticket']) : '—' ?></small></div>
      <div><span>Tasa de entrega</span><strong class="<?= $k['tasa_entrega'] !== null && $k['tasa_entrega'] < 70 ? 'bad' : '' ?>"><?= pct($k['tasa_entrega'], 0) ?></strong>
        <small><?= num($k['entregados']) ?> entregados · <?= num($k['devueltos']) ?> devueltos · <?= num($k['en_curso']) ?> en curso</small></div>
      <div><span>Ganancia estimada</span><strong class="<?= $k['ganancia'] < 0 ? 'bad' : '' ?>"><?= money($k['ganancia']) ?></strong>
        <small><?= $k['ganancia_incompleta'] ? 'Faltan costos o fletes en algunos pedidos' : 'Pedidos cerrados del período' ?></small></div>
    </div>

    <section class="box"><h2>Visitas y pedidos <?= $s->isSingleDay() ? 'por hora' : 'por día' ?></h2><?= svg_daily($s->daily(), $s->isSingleDay()) ?></section>

    <div class="cols">
      <section class="box"><h2>Embudo</h2>
        <?php $steps = ['Sesiones' => $f['sesiones'], 'Vieron un producto' => $f['vio_producto'], 'Abrieron el formulario' => $f['abrio_formulario'], 'Hicieron el pedido' => $f['pidio']];
        $max = max(1, $f['sesiones']); $prev = null; ?>
        <?php foreach ($steps as $name => $v): ?>
          <div class="fun"><span><?= e($name) ?></span><div class="fun__bar"><i style="width:<?= round($v / $max * 100, 1) ?>%"></i></div>
            <strong><?= num($v) ?></strong><small><?= $prev ? pct($v / $prev * 100, 0) : '' ?></small></div>
        <?php $prev = $v ?: null; endforeach; ?>
        <p class="muted">Si muchos abren el formulario y pocos piden, el problema está en el formulario o en el precio final con envío.</p>
      </section>
      <section class="box"><h2>Pedidos por estado</h2>
        <table class="t t--plain"><tbody>
        <?php foreach ($s->statusFunnel() as $r): ?>
          <tr><td><?= e(WebhookProcessor::STATUS[(int) $r['s']] ?? 'Sin estado aún') ?></td><td><?= num($r['n']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </section>
    </div>

    <section class="box"><h2>De dónde vienen los pedidos</h2><?php channel_table($s->byChannel()); ?>
      <p><a href="?p=fuentes&r=<?= e($_GET['r'] ?? '7') ?>">Ver campañas y anuncios</a></p></section>

    <section class="box"><h2>Productos</h2>
      <table class="t"><thead><tr><th>Producto</th><th>Vistas</th><th>Abrieron formulario</th><th>Pedidos</th><th>Conversión</th><th>Tasa de entrega</th><th>Margen unitario</th></tr></thead><tbody>
      <?php foreach ($s->topProducts() as $p): $closed = $p['entregados'] + $p['devueltos']; ?>
        <tr><td><a href="?p=producto&id=<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></a></td><td><?= num($p['vistas']) ?></td><td><?= num($p['formularios']) ?></td>
          <td><?= num($p['pedidos']) ?></td><td><?= $p['vistas'] ? pct($p['pedidos'] / $p['vistas'] * 100, 1) : '—' ?></td>
          <td><?= $closed ? pct($p['entregados'] / $closed * 100, 0) : '—' ?></td>
          <td><?= $p['cost'] ? money($p['price'] - $p['cost']) : '—' ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </section>

    <?php $cities = $s->returnsByCity(); if ($cities): ?>
    <section class="box"><h2>Ciudades con más devoluciones</h2>
      <table class="t"><thead><tr><th>Ciudad</th><th>Entregados</th><th>Devueltos</th><th>Tasa de entrega</th></tr></thead><tbody>
      <?php foreach ($cities as $c): $t = $c['entregados'] + $c['devueltos']; ?>
        <tr><td><?= e("{$c['ciudad']}, {$c['departamento']}") ?></td><td><?= num($c['entregados']) ?></td><td><?= num($c['devueltos']) ?></td><td><?= pct($c['entregados'] / $t * 100, 0) ?></td></tr>
      <?php endforeach; ?></tbody></table>
      <p class="muted">Una ciudad con tasa de entrega baja te cuesta dos fletes por pedido devuelto. Considera pedir pago anticipado allí.</p>
    </section>
    <?php endif;
}

function page_fuentes(PDO $db): void
{
    echo '<h1>Fuentes de tráfico</h1>';
    [$from, $to] = period_bar('fuentes');
    $s = new Stats($db, $from, $to);
    $byAd = ($_GET['nivel'] ?? '') === 'anuncio';
    $q = http_build_query(array_intersect_key($_GET, array_flip(['r', 'desde', 'hasta'])));
    ?>
    <section class="box"><h2>Por canal</h2><?php channel_table($s->byChannel()); ?></section>

    <section class="box">
      <div class="bar"><h2><?= $byAd ? 'Por anuncio' : 'Por campaña' ?></h2>
        <nav class="tabs"><a href="?p=fuentes&<?= e($q) ?>" class="<?= $byAd ? '' : 'on' ?>">Campañas</a><a href="?p=fuentes&nivel=anuncio&<?= e($q) ?>" class="<?= $byAd ? 'on' : '' ?>">Anuncios</a></nav></div>
      <table class="t"><thead><tr><th>Canal</th><th>Fuente</th><th>Campaña</th><?php if ($byAd): ?><th>Anuncio</th><?php endif; ?><th>Visitas</th><th>Pedidos</th><th>Conversión</th><th>Ventas</th><th>Entregados</th><th>Tasa de entrega</th></tr></thead><tbody>
      <?php foreach ($s->campaigns($byAd) as $r): ?>
        <tr><td><?= e(Stats::CHANNELS[$r['channel']] ?? $r['channel']) ?></td><td><?= e($r['source']) ?></td><td><?= e($r['campaign']) ?></td>
          <?php if ($byAd): ?><td><?= e($r['content']) ?></td><?php endif; ?>
          <td><?= num($r['visitas']) ?></td><td><?= num($r['pedidos']) ?></td><td><?= pct($r['conversion'], 2) ?></td><td><?= money($r['ventas']) ?></td>
          <td><?= num($r['entregados']) ?></td><td><?= pct($r['tasa_entrega'], 0) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
      <p class="muted">Ventas en pesos de pedidos hechos; para el retorno real de una campaña mira la columna de entregados.</p>
    </section>

    <div class="cols">
      <section class="box"><h2>Orgánico: páginas que traen visitas desde Google</h2>
        <table class="t t--plain"><thead><tr><th>Página</th><th>Visitas</th><th>Pedidos</th></tr></thead><tbody>
        <?php foreach ($s->organicLandings() as $r): ?>
          <tr><td><a href="<?= e($r['landing_path']) ?>" target="_blank"><?= e($r['landing_path']) ?></a></td><td><?= num($r['visitas']) ?></td><td><?= num($r['pedidos']) ?></td></tr>
        <?php endforeach; ?></tbody></table>
        <p class="muted">Para ver las palabras clave exactas usa Google Search Console; Google no las envía a los sitios.</p>
      </section>
      <section class="box"><h2>Dispositivos</h2>
        <table class="t t--plain"><tbody><?php foreach ($s->devices() as $r): ?><tr><td><?= e(ucfirst($r['device'])) ?></td><td><?= num($r['n']) ?></td></tr><?php endforeach; ?></tbody></table>
      </section>
    </div>

    <section class="box help"><h2>Para que tus campañas se midan bien</h2>
      <p>En Meta Ads, en cada anuncio → <em>Parámetros de URL</em>, pega:</p>
      <pre>utm_source=meta&amp;utm_medium=paid&amp;utm_campaign={{campaign.name}}&amp;utm_content={{ad.name}}</pre>
      <p>Para links orgánicos (bio de Instagram, estados de WhatsApp, TikTok) usa el generador de enlaces que hay en cada producto. Sin parámetros, un clic desde WhatsApp cuenta como «Directo».</p>
    </section>
    <?php
}

function page_promocion(PDO $db): void
{
    $products = $db->query('SELECT id, COALESCE(title, name) nombre, is_featured, badge, price, price_before, offer_from, offer_until FROM products WHERE is_active = 1 ORDER BY is_featured DESC, nombre')->fetchAll();
    $hero = (int) setting('hero_product_id', '0');
    ?>
    <h1>Promoción</h1>
    <p class="box">¿Qué productos salen en la portada? Se elige en <a href="?p=portada"><strong>Tienda → Portada</strong></a>, sección por sección.</p>

    <section class="box"><h2>Destacados, etiquetas y ofertas</h2>
      <p class="muted">«Destacado» ordena primero el producto en sus categorías y en las secciones automáticas de la portada. La oferta se edita en cada producto.</p>
      <table class="t"><thead><tr><th>Producto</th><th>Destacado</th><th>Etiqueta</th><th>Oferta</th></tr></thead><tbody>
      <?php foreach ($products as $p): $active = Pricing::offerActive($p); ?>
        <tr><td><a href="?p=producto&id=<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></a></td>
          <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_featured"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button class="<?= $p['is_featured'] ? 'on' : 'ghost' ?>"><?= $p['is_featured'] ? 'Destacado' : 'Destacar' ?></button></form></td>
          <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="save_badge"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input name="badge" value="<?= e($p['badge']) ?>" maxlength="30" list="badges" size="12"><button class="ghost">OK</button></form></td>
          <td><?php if ($p['price_before'] > $p['price']): ?>
            <?= $active ? '<span class="pill-ok">Activa</span>' : (($p['offer_from'] && strtotime($p['offer_from']) > time()) ? '<span class="pill-wait">Programada</span>' : '<span class="muted">Vencida</span>') ?>
            <small><?= $p['offer_from'] ? 'desde ' . e(date('d/m', strtotime($p['offer_from']))) : '' ?> <?= $p['offer_until'] ? 'hasta ' . e(date('d/m', strtotime($p['offer_until']))) : '' ?></small>
          <?php else: ?>—<?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
      <datalist id="badges"><option>Nuevo</option><option>Más vendido</option><option>Últimas unidades</option><option>Envío gratis</option><option>Tendencia</option></datalist>
    </section>
    <?php
}

function page_paginas(PDO $db): void
{
    $slug = (string) ($_GET['slug'] ?? '');
    if (!isset(STATIC_PAGES[$slug])) {
        echo '<h1>Páginas</h1><table class="t"><tbody>';
        foreach (STATIC_PAGES as $s => $t) {
            echo '<tr><td><a href="?p=paginas&slug=' . e($s) . '">' . e($t) . '</a></td><td><a href="/' . e($s) . '/" target="_blank">Ver</a></td></tr>';
        }
        echo '</tbody></table>';
        return;
    }
    $st = $db->prepare('SELECT body FROM pages WHERE slug = ?');
    $st->execute([$slug]);
    $body = $st->fetchColumn();
    if ($body === false) {
        $f = dirname(__DIR__) . "/templates/pages/{$slug}.md";
        $body = is_file($f) ? file_get_contents($f) : '';
    } ?>
    <h1><?= e(STATIC_PAGES[$slug]) ?></h1>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="save_page"><input type="hidden" name="slug" value="<?= e($slug) ?>">
      <label>Contenido <small>## título · **negrita** · - lista · [texto](/enlace)</small><textarea name="body" rows="22"><?= e((string) $body) ?></textarea></label>
      <button class="big">Guardar página</button></form>
    <?php
}

/** Paso 1 del editor: fotos del producto. */
function product_photos(array $p): void
{
    $own = json_decode($p['custom_images'] ?? '[]', true) ?: [];
    $ms = json_decode($p['images'] ?? '[]', true) ?: [];
    $url = site_url("/p/{$p['slug']}/");
    ?>
    <section class="box" id="fotos">
      <h2>Fotos</h2>
      <?php if ($own): ?>
        <p class="muted">Se muestran tus fotos. La primera es la portada.</p>
        <div class="thumbs-admin">
          <?php foreach ($own as $i => $img): ?>
            <figure><img src="<?= e($img) ?>" alt="" width="110" height="110">
              <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="img" value="<?= e($img) ?>">
                <?php if ($i): ?><button name="action" value="cover_image" class="ghost">Portada</button><?php endif; ?>
                <button name="action" value="delete_image" class="ghost" onclick="return confirm('¿Borrar esta foto?')">Borrar</button></form></figure>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="muted"><?= $ms ? 'Se muestran las ' . count($ms) . ' fotos de Mastershop. Si subes las tuyas, reemplazan a esas.' : 'Todavía no tiene fotos: sube al menos una (la primera es la portada).' ?></p>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="upload"><?= csrf_field() ?><input type="hidden" name="action" value="upload_images"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
        <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple required>
        <button>Subir fotos</button><small>JPG, PNG o WebP hasta 8 MB. Se optimizan solas a WebP de 1200 px.</small></form>
    </section>

<?php
}

/** Opciones avanzadas del editor: testimonios y enlace para campañas. */
function product_more(array $p): void
{
    $own = json_decode($p['custom_images'] ?? '[]', true) ?: [];
    $ms = json_decode($p['images'] ?? '[]', true) ?: [];
    $url = site_url("/p/{$p['slug']}/");
    ?>
    <?php $tests = db()->query('SELECT * FROM testimonials WHERE product_id = ' . (int) $p['id'] . ' ORDER BY sort, created_at DESC')->fetchAll(); ?>
    <section class="box" id="testimonios">
      <h2>Testimonios <small class="muted" style="font-weight:400">(opcional)</small></h2>
      <p class="muted">Publica solo opiniones reales, con permiso del cliente para usar su nombre y su foto. Si escribes el número de pedido y coincide con una compra de este
        producto, sale «Compra verificada». Con al menos un testimonio publicado, Google puede mostrar las estrellas del producto.</p>
      <?php if ($tests): ?>
        <table class="t t--plain"><tbody>
        <?php foreach ($tests as $t): ?>
          <tr><td style="width:60px"><?php if ($t['photo']): ?><img src="<?= e($t['photo']) ?>" alt="" width="52" height="52" style="border-radius:8px;object-fit:cover"><?php endif; ?></td>
            <td><strong><?= e($t['name']) ?></strong><?= $t['city'] ? ' · ' . e($t['city']) : '' ?> <span style="color:#f5a623"><?= str_repeat('★', (int) $t['rating']) ?></span>
              <?= $t['verified'] ? '<span class="pill-ok">Compra verificada</span>' : '' ?><br><small class="muted"><?= e(mb_strimwidth($t['text'], 0, 140, '…')) ?></small></td>
            <td class="actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="tid" value="<?= (int) $t['id'] ?>">
              <button name="action" value="toggle_testimonial" class="<?= $t['is_published'] ? 'on' : 'ghost' ?>"><?= $t['is_published'] ? 'Publicado' : 'Oculto' ?></button>
              <button name="action" value="delete_testimonial" class="ghost" onclick="return confirm('¿Eliminar este testimonio?')">Eliminar</button></form></td></tr>
        <?php endforeach; ?></tbody></table>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="form" style="margin-top:1rem;max-width:none"><?= csrf_field() ?><input type="hidden" name="action" value="add_testimonial"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
        <div class="row">
          <label>Nombre del cliente <input name="name" required maxlength="80" placeholder="Ej. Laura M."></label>
          <label>Ciudad <input name="city" maxlength="80" placeholder="Barranquilla"></label>
          <label>Calificación <select name="rating"><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= str_repeat('★', $i) ?> (<?= $i ?>)</option><?php endfor; ?></select></label>
          <label>Número de pedido <small>(opcional)</small><input name="order_ref" maxlength="40" placeholder="TD-000123"></label>
        </div>
        <label>Lo que dijo <textarea name="text" rows="3" required maxlength="600" placeholder="Copia el mensaje del cliente tal como lo escribió"></textarea></label>
        <label>Foto <small>(opcional: del cliente con el producto)</small><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
        <button>Agregar testimonio</button>
      </form>
    </section>

    <section class="box">
      <h2>Enlace para campañas</h2>
      <p class="muted">Copia el enlace con los parámetros listos para que el pedido quede atribuido a su fuente.</p>
      <div class="utm" data-base="<?= e($url) ?>">
        <label>Dónde lo vas a poner
          <select data-utm="preset">
            <option value="meta|paid">Meta Ads (Facebook / Instagram)</option>
            <option value="google|cpc">Google Ads</option>
            <option value="tiktok|paid">TikTok Ads</option>
            <option value="instagram|social">Instagram orgánico (bio, historias)</option>
            <option value="facebook|social">Facebook orgánico</option>
            <option value="tiktok|social">TikTok orgánico</option>
            <option value="whatsapp|social">WhatsApp (estados, grupos, chats)</option>
            <option value="email|email">Email</option>
          </select></label>
        <label>Nombre de la campaña <input data-utm="campaign" placeholder="black-friday-mesa"></label>
        <label>Anuncio o pieza <small>(opcional)</small><input data-utm="content" placeholder="video-1"></label>
        <div class="utm__out"><input readonly data-utm="out"><button type="button" data-utm="copy">Copiar</button></div>
        <p class="muted">En Meta Ads es mejor usar los parámetros dinámicos que están en Fuentes de tráfico.</p>
      </div>
    </section>
    <script>
    document.querySelectorAll('.utm').forEach(box=>{const g=k=>box.querySelector('[data-utm='+k+']');
      const clean=s=>s.trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
      const u=()=>{const [src,med]=g('preset').value.split('|');const p=new URLSearchParams({utm_source:src,utm_medium:med});
        if(g('campaign').value)p.set('utm_campaign',clean(g('campaign').value));if(g('content').value)p.set('utm_content',clean(g('content').value));
        g('out').value=box.dataset.base+'?'+p.toString();};
      box.addEventListener('input',u);u();
      g('copy').addEventListener('click',()=>{navigator.clipboard.writeText(g('out').value);g('copy').textContent='Copiado';setTimeout(()=>g('copy').textContent='Copiar',1500);});});
    </script>
    <?php
}

/* ---------------- Acciones POST ---------------- */

function handle_extra_post(PDO $db, string $action): void
{
    $id = (int) ($_POST['id'] ?? 0);
    switch ($action) {
        case 'upload_images':
            $st = $db->prepare('SELECT custom_images FROM products WHERE id = ?'); $st->execute([$id]);
            $own = json_decode((string) $st->fetchColumn(), true) ?: [];
            $files = $_FILES['images'] ?? [];
            $ok = 0; $errors = [];
            foreach ((array) ($files['tmp_name'] ?? []) as $i => $tmp) {
                try {
                    $own[] = ImageUpload::store(['tmp_name' => $tmp, 'error' => $files['error'][$i], 'size' => $files['size'][$i]], $id);
                    $ok++;
                } catch (RuntimeException $e) {
                    $errors[] = ($files['name'][$i] ?? 'archivo') . ': ' . $e->getMessage();
                }
            }
            $db->prepare('UPDATE products SET custom_images = ? WHERE id = ?')->execute([json_encode(array_slice($own, 0, 12)), $id]);
            flash($errors ? "Subidas {$ok}. " . implode(' ', $errors) : "{$ok} fotos subidas.", $errors ? 'bad' : 'ok');
            back("?p=producto&id={$id}#fotos");
        case 'delete_image':
        case 'cover_image':
            $st = $db->prepare('SELECT custom_images FROM products WHERE id = ?'); $st->execute([$id]);
            $own = json_decode((string) $st->fetchColumn(), true) ?: [];
            $img = (string) $_POST['img'];
            $own = array_values(array_filter($own, fn($x) => $x !== $img));
            if ($action === 'cover_image') { array_unshift($own, $img); } else { ImageUpload::delete($img, $id); }
            $db->prepare('UPDATE products SET custom_images = ? WHERE id = ?')->execute([$own ? json_encode($own) : null, $id]);
            back("?p=producto&id={$id}#fotos");
        case 'upload_landing':
        case 'landing_move':
        case 'landing_delete':
            $st = $db->prepare('SELECT landing_images FROM products WHERE id = ?'); $st->execute([$id]);
            $list = json_decode((string) $st->fetchColumn(), true) ?: [];
            $msg = '';
            if ($action === 'upload_landing') {
                $files = $_FILES['images'] ?? []; $ok = 0; $errors = [];
                foreach ((array) ($files['tmp_name'] ?? []) as $k => $tmp) {
                    try { $list[] = ImageUpload::store(['tmp_name' => $tmp, 'error' => $files['error'][$k], 'size' => $files['size'][$k]], $id, 'products', true); $ok++; }
                    catch (RuntimeException $e) { $errors[] = ($files['name'][$k] ?? 'archivo') . ': ' . $e->getMessage(); }
                }
                $msg = $errors ? "Subidas {$ok}. " . implode(' ', $errors) : "{$ok} piezas subidas.";
            } else {
                $i = (int) $_POST['i'];
                if ($action === 'landing_delete' && isset($list[$i])) { ImageUpload::delete($list[$i], $id); array_splice($list, $i, 1); }
                if ($action === 'landing_move' && isset($list[$i])) {
                    $j = ($_POST['dir'] ?? '') === 'up' ? $i - 1 : $i + 1;
                    if (isset($list[$j])) { [$list[$i], $list[$j]] = [$list[$j], $list[$i]]; }
                }
            }
            $db->prepare('UPDATE products SET landing_images = ? WHERE id = ?')->execute([$list ? json_encode(array_values($list)) : null, $id]);
            if ($msg) { flash($msg, str_contains($msg, ':') ? 'bad' : 'ok'); }
            back("?p=producto&id={$id}#landing");
        case 'add_testimonial':
            $ref = strtoupper(trim((string) ($_POST['order_ref'] ?? '')));
            $verified = 0;
            if ($ref !== '') {
                $st = $db->prepare('SELECT 1 FROM orders o JOIN order_items i ON i.order_id = o.id WHERE o.external_id = ? AND i.product_id = ? LIMIT 1');
                $st->execute([$ref, $id]);
                $verified = $st->fetchColumn() ? 1 : 0;
            }
            $photo = null; $warn = '';
            if (!empty($_FILES['photo']['tmp_name'])) {
                try { $photo = ImageUpload::store($_FILES['photo'], $id, 'testimonials'); } catch (RuntimeException $e) { $warn = ' La foto no se guardó: ' . $e->getMessage(); }
            }
            $db->prepare('INSERT INTO testimonials (product_id, name, city, rating, text, photo, order_ref, verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $id, mb_substr(trim((string) $_POST['name']), 0, 80), trim((string) ($_POST['city'] ?? '')) ?: null, max(1, min(5, (int) $_POST['rating'])),
                mb_substr(trim((string) $_POST['text']), 0, 600), $photo, $ref ?: null, $verified]);
            flash('Testimonio agregado' . ($ref !== '' ? ($verified ? ' como compra verificada.' : '. El pedido no coincide con una compra de este producto: quedó sin verificar.') : '.') . $warn, $warn || ($ref !== '' && !$verified) ? 'bad' : 'ok');
            back("?p=producto&id={$id}#testimonios");
        case 'toggle_testimonial':
            $db->prepare('UPDATE testimonials SET is_published = 1 - is_published WHERE id = ? AND product_id = ?')->execute([(int) $_POST['tid'], $id]);
            back("?p=producto&id={$id}#testimonios");
        case 'delete_testimonial':
            $st = $db->prepare('SELECT photo FROM testimonials WHERE id = ? AND product_id = ?'); $st->execute([(int) $_POST['tid'], $id]);
            if ($ph = $st->fetchColumn()) { @unlink(dirname(__DIR__) . '/public' . $ph); }
            $db->prepare('DELETE FROM testimonials WHERE id = ? AND product_id = ?')->execute([(int) $_POST['tid'], $id]);
            back("?p=producto&id={$id}#testimonios");
        case 'toggle_featured':
            $db->prepare('UPDATE products SET is_featured = 1 - is_featured WHERE id = ?')->execute([$id]);
            back('?p=promocion');
        case 'save_badge':
            $db->prepare('UPDATE products SET badge = ? WHERE id = ?')->execute([trim((string) $_POST['badge']) ?: null, $id]);
            flash('Etiqueta guardada.');
            back('?p=promocion');
        case 'save_hero':
            $st = $db->prepare('REPLACE INTO settings (k, v) VALUES (?, ?)');
            foreach (['hero_product_id', 'hero_title', 'hero_text'] as $k) { $st->execute([$k, trim((string) ($_POST[$k] ?? ''))]); }
            flash('Portada guardada.');
            back('?p=promocion');
        case 'save_page':
            $slug = (string) $_POST['slug'];
            if (isset(STATIC_PAGES[$slug])) {
                $db->prepare('REPLACE INTO pages (slug, body) VALUES (?, ?)')->execute([$slug, (string) $_POST['body']]);
                flash('Página guardada.');
            }
            back('?p=paginas&slug=' . urlencode($slug));
    }
}
