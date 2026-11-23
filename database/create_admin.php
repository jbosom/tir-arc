<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Aquest script només es pot executar per consola.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

$email = $argv[1] ?? '';
$password = $argv[2] ?? '';
$name = $argv[3] ?? 'Administració ACTLL';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    exit("Ús: php database/create_admin.php correu contrasenya-segura [nom]\nLa contrasenya ha de tenir 12 caràcters com a mínim.\n");
}

$stmt = db()->prepare("INSERT INTO users (name,email,password_hash,role,active) VALUES (?,?,?,'admin',1)");
$stmt->execute([$name, strtolower($email), password_hash($password, PASSWORD_DEFAULT)]);
echo "Administrador creat correctament.\n";

