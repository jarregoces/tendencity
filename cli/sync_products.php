<?php
declare(strict_types=1);
/**
 * Sincroniza el catálogo de Mastershop.
 *   php cli/sync_products.php          → sincroniza todo
 *   php cli/sync_products.php --dump   → imprime el primer producto crudo (para revisar el mapeo)
 * Cron sugerido: cada 30 min  (ver README para la línea de crontab)
 */
if (PHP_SAPI !== 'cli') { exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

$lock = fopen(sys_get_temp_dir() . '/ms_sync_products.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "Ya hay una sync corriendo\n"); exit(1); }

$stats = (new ProductSync(db(), mastershop()))->run(in_array('--dump', $argv, true));
echo json_encode($stats), "\n";
