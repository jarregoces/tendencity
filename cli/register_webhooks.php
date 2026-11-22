<?php
declare(strict_types=1);
/** Registra los webhooks de Mastershop (también hay botón en Admin → Configuración → Mastershop). */
if (PHP_SAPI !== 'cli') { exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/helpers.php';
require dirname(__DIR__) . '/src/WebhookRegistrar.php';
foreach ((new WebhookRegistrar(db(), mastershop()))->register() as $id => $r) {
    echo str_pad((string) $id, 4) . WebhookRegistrar::EVENTS[$id] . ": {$r}\n";
}
