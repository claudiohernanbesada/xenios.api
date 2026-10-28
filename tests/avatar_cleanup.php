<?php
declare(strict_types=1);

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xenios-avatar-test-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
putenv('XENIOS_AVATAR_DIR=' . $dir);
require dirname(__DIR__) . '/xenios-movil/lib/avatar.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE Usuarios (ImgPerfil TEXT)');
$checks = 0;
function check(bool $condition): void { global $checks; if (!$condition) { throw new RuntimeException('Avatar cleanup check failed'); } $checks++; }
try {
    file_put_contents($dir . '/old.png', 'old avatar');
    check(delete_unused_avatar($pdo, 'old.png') && !file_exists($dir . '/old.png'));
    check(delete_unused_avatar($pdo, 'old.png'));
    file_put_contents($dir . '/shared.png', 'shared avatar');
    $pdo->exec("INSERT INTO Usuarios VALUES ('shared.png')");
    check(!delete_unused_avatar($pdo, 'shared.png') && file_exists($dir . '/shared.png'));
    $pdo->exec('DELETE FROM Usuarios');
    check(delete_unused_avatar($pdo, 'shared.png') && !file_exists($dir . '/shared.png'));
    file_put_contents($dir . '/no-image.png', 'placeholder');
    check(delete_unused_avatar($pdo, 'no-image.png') && file_exists($dir . '/no-image.png'));
    try { delete_unused_avatar($pdo, '../outside.png'); throw new RuntimeException('Traversal accepted'); }
    catch (InvalidArgumentException $exception) { check(true); }
    mkdir($dir . '/directory.png');
    try { delete_unused_avatar($pdo, 'directory.png'); throw new RuntimeException('Directory accepted'); }
    catch (InvalidArgumentException $exception) { check(is_dir($dir . '/directory.png')); }
    file_put_contents($dir . '/keep.png', 'keep avatar');
    $pdo->exec('DROP TABLE Usuarios');
    try { delete_unused_avatar($pdo, 'keep.png'); throw new RuntimeException('Database failure ignored'); }
    catch (PDOException $exception) { check(file_exists($dir . '/keep.png')); }
    echo "AVATAR_CLEANUP_CHECKS $checks\n";
} finally {
    foreach (['old.png', 'shared.png', 'no-image.png', 'keep.png'] as $name) {
        if (is_file($dir . '/' . $name)) { unlink($dir . '/' . $name); }
    }
    if (is_dir($dir . '/directory.png')) { rmdir($dir . '/directory.png'); }
    rmdir($dir);
}
