<?php
declare(strict_types=1);
/** Crea o cambia la contraseña de un admin:  php cli/create_admin.php correo@dominio.com */
if (PHP_SAPI !== 'cli') { exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
$email = $argv[1] ?? exit("Uso: php cli/create_admin.php correo@dominio.com\n");
echo "Contraseña (mín. 10 caracteres): ";
system('stty -echo 2>/dev/null'); $pass = trim((string) fgets(STDIN)); system('stty echo 2>/dev/null'); echo "\n";
if (strlen($pass) < 10) { exit("Muy corta.\n"); }
db()->prepare('INSERT INTO admins (email, password_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)')
    ->execute([$email, password_hash($pass, PASSWORD_DEFAULT)]);
echo "Listo: {$email}\n";
