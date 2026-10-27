<?php
declare(strict_types=1);
require_once __DIR__ . '/_inc/security.php';
xenios_security_headers();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['service' => 'Xenios API']);
