<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/helpers.php';

ob_start(); // permite poner cookies de medición después de renderizar

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$catalog = new Catalog(db());

/* ---------- Endpoints sin barra final ---------- */
if ($path === '/sitemap.xml') { require dirname(__DIR__) . '/src/sitemap.php'; exit; }
if ($path === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: /admin/\nDisallow: /api/\nDisallow: /pedido\nDisallow: /evento\nDisallow: /buscar/\nDisallow: /pedido-confirmado/\nSitemap: " . site_url('/sitemap.xml') . "\n";
    exit;
}
if (str_starts_with($path, '/api/')) { require dirname(__DIR__) . '/src/api_endpoint.php'; exit; }
if ($path === '/pedido' && $method === 'POST') { require dirname(__DIR__) . '/src/order_endpoint.php'; exit; }
if ($path === '/evento' && $method === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    if (($in['type'] ?? '') === 'form_open') { (new Tracker(db()))->event('form_open', (int) ($in['product_id'] ?? 0) ?: null); }
    http_response_code(204); exit;
}

/* ---------- Redirecciones 301 guardadas en BD ---------- */
if ($to = $catalog->redirectFor($path)) { header('Location: ' . $to, true, 301); exit; }

/* ---------- Normalizar: siempre con barra final, minúsculas ---------- */
if ($path !== '/' && !str_ends_with($path, '/')) { header('Location: ' . $path . '/', true, 301); exit; }
if ($path !== strtolower($path)) { header('Location: ' . strtolower($path), true, 301); exit; }

$seg = array_values(array_filter(explode('/', $path), 'strlen'));
$n = count($seg);

/* ---------- Páginas fijas fuera de los silos ---------- */
$static = [
    'envios-y-pagos'           => 'Envíos y pagos',
    'garantias-y-devoluciones' => 'Garantías y devoluciones',
    'contacto'                 => 'Contacto',
    'politica-de-privacidad'   => 'Política de privacidad',
    'terminos'                 => 'Términos y condiciones',
];

$trackProduct = null;
try {
    if ($n === 0) {
        view('home', ['catalog' => $catalog]);
    } elseif ($n === 2 && $seg[0] === 'p') {
        // Destino común de los 3 silos
        $p = $catalog->product($seg[1]) ?? throw new NotFound();
        $trackProduct = (int) $p['id'];
        view('product', ['p' => $p, 'catalog' => $catalog]);
    } elseif ($seg[0] === 'resenas' && $n <= 3) {
        // SILO 2: /resenas/  /resenas/{pilar}/  /resenas/{pilar}/{slug}/
        if ($n === 1) {
            view('reviews', ['catalog' => $catalog, 'pillar' => null]);
        } elseif ($n === 2) {
            $pillar = $catalog->categoryByPath($seg[1]) ?? throw new NotFound();
            view('reviews', ['catalog' => $catalog, 'pillar' => $pillar]);
        } else {
            $r = $catalog->review($seg[1], $seg[2]) ?? throw new NotFound();
            view('review', ['r' => $r, 'catalog' => $catalog]);
        }
    } elseif ($seg[0] === 'guias' && $n <= 2) {
        // SILO 3: /guias/  /guias/{slug}/
        if ($n === 1) {
            view('guides', ['catalog' => $catalog]);
        } else {
            $g = $catalog->guide($seg[1]) ?? throw new NotFound();
            view('guide', ['g' => $g, 'catalog' => $catalog]);
        }
    } elseif ($n === 1 && $seg[0] === 'buscar') {
        view('search', ['catalog' => $catalog, 'q' => trim((string) ($_GET['q'] ?? ''))]);
    } elseif ($n === 1 && $seg[0] === 'ofertas') {
        view('offers', ['catalog' => $catalog]);
    } elseif ($n === 1 && $seg[0] === 'black-friday') {
        view('black_friday', ['catalog' => $catalog]);
    } elseif ($n === 2 && $seg[0] === 'pedido-confirmado') {
        view('confirmed', ['ref' => $seg[1], 'key' => (string) ($_GET['k'] ?? '')]);
    } elseif ($n === 1 && isset($static[$seg[0]])) {
        view('page', ['slug' => $seg[0], 'title' => $static[$seg[0]]]);
    } elseif ($n <= 2) {
        // SILO 1: /{pilar}/  /{pilar}/{subcategoria}/
        $cat = $catalog->categoryByPath($seg[0], $seg[1] ?? null) ?? throw new NotFound();
        view('category', ['cat' => $cat, 'catalog' => $catalog]);
    } else {
        throw new NotFound();
    }
    if (!($n === 2 && $seg[0] === 'pedido-confirmado')) {
        (new Tracker(db()))->hit($path, $trackProduct);
    }
} catch (NotFound) {
    http_response_code(404);
    view('404', ['catalog' => $catalog]);
}
