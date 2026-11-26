<?php
declare(strict_types=1);

/**
 * Carga .env, autoload simple y conexión PDO.
 * El .env debe vivir en la raíz del proyecto, fuera de public/.
 */

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $k = preg_replace('/^\xEF\xBB\xBF/', '', $k); // BOM de archivos guardados con Bloc de notas
        // Comentarios pegados al lado del valor ("← genéralo", " # nota") no son parte del valor
        $v = trim(preg_split('/\s+#|←/u', $v)[0]);
        $_ENV[$k] = trim($v, "\"'");
    }
}

function env(string $key, ?string $default = null): ?string
{
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});
require_once __DIR__ . '/MastershopClient.php'; // incluye MastershopException
require_once __DIR__ . '/DemoSeeder.php';
require_once __DIR__ . '/Country.php';

// Hora del país de la tienda: fechas de PHP y de MySQL deben coincidir
date_default_timezone_set(env('APP_TIMEZONE') ?: Country::get('timezone'));

/** Correo de respaldo para pedidos sin correo del cliente: FALLBACK_EMAIL o pedidos@<dominio de SITE_URL>. */
function fallback_email(): string
{
    $host = preg_replace('/^www\./', '', (string) parse_url(rtrim((string) env('SITE_URL', ''), '/') . '/', PHP_URL_HOST));
    return (string) (env('FALLBACK_EMAIL') ?: 'pedidos@' . ($host ?: 'localhost'));
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', env('DB_HOST', 'localhost'), env('DB_NAME')),
            env('DB_USER'),
            env('DB_PASS'),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        // NOW() y CURRENT_TIMESTAMP de MySQL en la misma hora que PHP (Colombia UTC-5 / Guatemala UTC-6, sin horario de verano)
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        // Completa sola la base de datos si falta algún cambio de versión (no hace falta correr los .sql)
        Migrator::ensure($pdo);
    }
    return $pdo;
}

function mastershop(): MastershopClient
{
    static $client = null;
    if ($client === null) {
        $key = env('MASTERSHOP_API_KEY');
        if (!$key) {
            throw new RuntimeException('Falta MASTERSHOP_API_KEY en .env');
        }
        $client = new MastershopClient($key, env('MASTERSHOP_BASE_URL', 'https://prod.api.mastershop.com'));
    }
    return $client;
}

/**
 * Cómo se despachan los pedidos (FULFILLMENT en el .env):
 *  - mastershop: se envían a Mastershop por API (por defecto si hay MASTERSHOP_API_KEY).
 *  - dropi:      quedan en cola y Claude los crea en Dropi (ver /api/pedidos en public/api.php).
 *  - manual:     quedan en cola para despacharlos a mano.
 */
function fulfillment(): string
{
    $f = strtolower((string) env('FULFILLMENT', ''));
    if (in_array($f, ['mastershop', 'dropi', 'manual'], true)) {
        return $f;
    }
    return env('MASTERSHOP_API_KEY') ? 'mastershop' : 'manual';
}

function uses_mastershop(): bool
{
    return fulfillment() === 'mastershop';
}

/** Cliente de Mastershop o null si esta tienda no despacha con Mastershop. */
function mastershop_or_null(): ?MastershopClient
{
    return uses_mastershop() ? mastershop() : null;
}
