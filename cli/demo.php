<?php
declare(strict_types=1);
/**
 * Productos de prueba.
 *   php cli/demo.php           → carga los 5 productos de prueba
 *   php cli/demo.php --borrar  → los borra
 * También se hace desde Admin → Ajustes.
 */
if (PHP_SAPI !== 'cli') { exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/helpers.php';
$s = new DemoSeeder(db());
echo in_array('--borrar', $argv, true) ? 'Borrados: ' . $s->remove() : 'Cargados: ' . $s->seed(), "\n";
