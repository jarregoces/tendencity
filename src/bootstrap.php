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

// Hora de Colombia para todo: fechas de PHP y de MySQL deben coincidir
date_default_timezone_set(getenv('APP_TIMEZONE') ?: ($_ENV['APP_TIMEZONE'] ?? 'America/Bogota'));

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
        // NOW() y CURRENT_TIMESTAMP de MySQL en la misma hora que PHP (Colombia = UTC-5, sin horario de verano)
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
