<?php
declare(strict_types=1);
require_once __DIR__ . '/env_loader.php';
$servidor = getenv('XENIOS_DB_HOST') ?: '127.0.0.1';
$usuario = getenv('XENIOS_DB_USER') ?: '';
$contrasena = getenv('XENIOS_DB_PASSWORD') ?: '';
$baseDeDatos = getenv('XENIOS_DB_NAME') ?: 'xeniosDbX';
