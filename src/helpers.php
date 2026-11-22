<?php
declare(strict_types=1);

require_once __DIR__ . '/pixels.php';

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function site_url(string $path = '/'): string
{
    return rtrim((string) env('SITE_URL', 'http://localhost'), '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = dirname(__DIR__) . '/public/assets/' . $path;
    $v = is_file($file) ? filemtime($file) : 1;
    return '/assets/' . $path . '?v=' . $v;
}

function money(int|string|null $v): string
{
    return '$' . number_format((int) $v, 0, ',', '.');
}

function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = db()->query('SELECT k, v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable) {
            $cache = [];
        }
    }
    return $cache[$key] ?? $default;
}

/** Texto con una entrada por línea → array limpio. */
function lines(?string $s): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $s)), 'strlen'));
}

/** "Clave: valor" por línea → [[clave, valor]] */
function pairs(?string $s): array
{
    $out = [];
    foreach (lines($s) as $l) {
        [$k, $v] = array_pad(array_map('trim', explode(':', $l, 2)), 2, '');
        if ($k !== '' && $v !== '') {
            $out[] = [$k, $v];
        }
    }
    return $out;
}

function faq_list(mixed $faq): array
{
    $faq = is_string($faq) ? json_decode($faq, true) : $faq;
    return array_values(array_filter((array) $faq, fn($f) => !empty($f['q']) && !empty($f['a'])));
}

/**
 * Markdown mínimo y seguro: ## y ### títulos, - listas, **negrita**, [texto](/ruta) y párrafos.
 * Todo se escapa primero; solo se permiten enlaces internos o https.
 */
function md(?string $text): string
{
    $html = '';
    $para = [];
    $list = [];
    $flush = function () use (&$html, &$para, &$list) {
        if ($para) {
            $html .= '<p>' . implode('<br>', array_map('md_inline', $para)) . '</p>';
            $para = [];
        }
        if ($list) {
            $html .= '<ul>' . implode('', array_map(fn($i) => '<li>' . md_inline($i) . '</li>', $list)) . '</ul>';
            $list = [];
        }
    };
    foreach (preg_split('/\R/', (string) $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            $flush();
        } elseif (preg_match('/^(#{2,3})\s+(.+)$/', $line, $m)) {
            $flush();
            $tag = strlen($m[1]) === 2 ? 'h2' : 'h3';
            $html .= "<{$tag}>" . md_inline($m[2]) . "</{$tag}>";
        } elseif (str_starts_with($line, '- ')) {
            if ($para) { $l = $list; $list = []; $flush(); $list = $l; }
            $list[] = substr($line, 2);
        } else {
            if ($list) { $p = $para; $para = []; $flush(); $para = $p; }
            $para[] = $line;
        }
    }
    $flush();
    return $html;
}

function md_inline(string $s): string
{
    $s = e($s);
    $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
    return preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $href = html_entity_decode($m[2]);
        if (!preg_match('#^(/|https://)#', $href)) {
            return $m[1];
        }
        $ext = str_starts_with($href, 'https://') ? ' rel="noopener" target="_blank"' : '';
        return '<a href="' . e($href) . '"' . $ext . '>' . $m[1] . '</a>';
    }, $s);
}

function slugify(string $s): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    return strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
}

function view(string $template, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    ob_start();
    try {
        require dirname(__DIR__) . "/templates/{$template}.php";
    } catch (Throwable $e) {
        ob_end_clean();   // un 404 a mitad de página no deja contenido a medio imprimir
        throw $e;
    }
    $content = ob_get_clean();
    require dirname(__DIR__) . '/templates/layout.php';
}

function partial(string $name, array $vars = []): mixed
{
    extract($vars, EXTR_SKIP);
    return require dirname(__DIR__) . "/templates/partials/{$name}.php";
}

function faq_schema(array $faq): ?array
{
    return $faq ? [
        '@context' => 'https://schema.org', '@type' => 'FAQPage',
        'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags(md($f['a']))]], $faq),
    ] : null;
}

function json_ld(array $data): string
{
    return '<script type="application/ld+json">'
        . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
        . '</script>';
}

/** El envío está incluido en el precio solo si no se cobra flete aparte (Ajustes → costo de envío = 0). */
function shipping_included(): bool
{
    return (int) setting('shipping_flat', '0') === 0;
}

/** WhatsApp de soporte formateado: +57 318 216 5374 */
function wa_display(): string
{
    return preg_replace('/^57(\d{3})(\d{3})(\d{4})$/', '+57 $1 $2 $3', preg_replace('/\D/', '', (string) setting('whatsapp', '')));
}

function wa_link(string $text = ''): string
{
    return 'https://wa.me/' . preg_replace('/\D/', '', (string) setting('whatsapp', '')) . ($text ? '?text=' . rawurlencode($text) : '');
}

function product_title(array $p): string
{
    return $p['title'] ?: $p['name'];
}

/** Tus fotos (custom_images) mandan sobre las de Mastershop. */
function product_images(array $p): array
{
    $own = json_decode($p['custom_images'] ?? '[]', true) ?: [];
    return $own ?: (json_decode($p['images'] ?? '[]', true) ?: []);
}

function product_image(array $p, int $i = 0): ?string
{
    return product_images($p)[$i] ?? null;
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => !empty($_SERVER['HTTPS'])]);
    }
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_check(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? ''))) {
        http_response_code(419);
        exit('Sesión vencida. Recarga la página.');
    }
}

final class NotFound extends Exception {}

/** Productos por página en la tienda. */
const PER_PAGE = 24;

/** [página, páginas, offset] desde ?pagina=; una página inexistente es 404 (no páginas vacías indexables). */
function shop_page(int $total, int $per = PER_PAGE): array
{
    $raw = $_GET['pagina'] ?? '1';
    $pg = ctype_digit((string) $raw) ? (int) $raw : 0;
    $pages = max(1, (int) ceil($total / $per));
    if ($pg < 1 || $pg > $pages) { throw new NotFound(); }
    return [$pg, $pages, ($pg - 1) * $per];
}

/** URL de una página del listado: la 1 va sin parámetro (una sola URL canónica). */
function page_url(string $path, int $n, array $keep = []): string
{
    $q = array_filter($keep + ($n > 1 ? ['pagina' => $n] : []), fn($v) => $v !== '' && $v !== null);
    return site_url($path) . ($q ? '?' . http_build_query($q) : '');
}

/** Firma para que solo quien hizo el pedido vea su página de confirmación. */
function order_key(string $externalId): string
{
    return substr(hash_hmac('sha256', $externalId, (string) env('APP_SECRET', 'cambia-esto')), 0, 20);
}
