<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("Aquest script només es pot executar per consola.\n");
$root = dirname(__DIR__);
$configFile = $root . '/config/config.php';
if (!is_file($configFile)) copy($root . '/config/config.example.php', $configFile);
$config = require $configFile;
$path = $config['database']['path'];
if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);

$pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents(__DIR__ . '/schema.sql'));
$pdo->beginTransaction();
try {
    $pdo->exec("INSERT OR IGNORE INTO clubs (id,code,name,cif,address,manager_name,email,phone) VALUES (1,'CLUB-PROVA','Club Prova ACTLL','G00000000','Adreça de prova','Responsable Prova','club@actll.test','600000000')");
    $pdo->exec("INSERT OR IGNORE INTO seasons (id,name,starts_on,ends_on,fee,active) VALUES (1,'2026-2027','2026-09-01','2027-08-31',30.00,1)");
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO users (club_id,name,email,password_hash,role) VALUES (?,?,?,?,?)');
    $hash = password_hash('Actll2026!', PASSWORD_DEFAULT);
    $stmt->execute([null,'Administració ACTLL','admin@actll.test',$hash,'admin']);
    $stmt->execute([1,'Responsable Club Prova','club@actll.test',$hash,'club']);
    $setting = $pdo->prepare('INSERT OR IGNORE INTO settings (setting_key,setting_value) VALUES (?,?)');
    $setting->execute(['privacy_version','2026-01']);
    $setting->execute(['privacy_text',"He informat l'arquer sobre el tractament de les seves dades i disposo de la base jurídica necessària per comunicar-les a l'ACTLL."]);
    $pdo->commit();
} catch (Throwable $e) { $pdo->rollBack(); throw $e; }
echo "Base de dades preparada a: {$path}\nAdmin: admin@actll.test / Actll2026!\nClub: club@actll.test / Actll2026!\n";

