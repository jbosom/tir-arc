<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$failures = [];
function check(bool $condition, string $message): void {
    global $failures;
    echo ($condition ? '[OK] ' : '[ERROR] ') . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
}

$tables = db()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['users','clubs','archers','seasons','registrations','payments','privacy_acceptances','imports','import_errors','audit_log'] as $table) {
    check(in_array($table, $tables, true), "Existeix la taula {$table}");
}
$stmt = db()->prepare('SELECT password_hash,role FROM users WHERE email=?');
$stmt->execute(['admin@actll.test']);
$admin = $stmt->fetch();
check((bool)$admin && $admin['role'] === 'admin', 'Existeix l’administrador de demostració');
check((bool)$admin && password_verify('Actll2026!', $admin['password_hash']), 'La contrasenya de demostració és verificable');
check((int)db()->query('PRAGMA foreign_keys')->fetchColumn() === 1, 'Les claus foranes estan activades');
check(scalar_test("SELECT count(*) FROM seasons WHERE active=1") === 1, 'Hi ha una temporada activa');

db()->beginTransaction();
try {
    $clubId = (int)db()->query("SELECT id FROM clubs WHERE code='CLUB-PROVA'")->fetchColumn();
    $userId = (int)db()->query("SELECT id FROM users WHERE email='club@actll.test'")->fetchColumn();
    $seasonId = (int)db()->query('SELECT id FROM seasons WHERE active=1')->fetchColumn();
    $archer = db()->prepare('INSERT INTO archers(current_club_id,first_name,last_name,birth_date,discipline) VALUES(?,?,?,?,?)');
    $archer->execute([$clubId,'Prova','Transaccional','1990-01-01','Tradicional']);
    $archerId = (int)db()->lastInsertId();
    $registration = db()->prepare("INSERT INTO registrations(archer_id,club_id,season_id,type,status,submitted_at,created_by) VALUES(?,?,?,'new','pending',CURRENT_TIMESTAMP,?)");
    $registration->execute([$archerId,$clubId,$seasonId,$userId]);
    $registrationId = (int)db()->lastInsertId();
    db()->prepare('INSERT INTO payments(registration_id,amount) VALUES(?,?)')->execute([$registrationId,30]);
    db()->prepare('INSERT INTO privacy_acceptances(archer_id,registration_id,legal_text_version,acceptance_method,accepted_at,recorded_by) VALUES(?,?,?,?,CURRENT_TIMESTAMP,?)')->execute([$archerId,$registrationId,'test','prova',$userId]);
    check(true, 'El flux alta → inscripció → quota → consentiment respecta les claus foranes');
} catch (Throwable $e) {
    check(false, 'El flux transaccional falla: ' . $e->getMessage());
} finally {
    db()->rollBack();
}

exit($failures ? 1 : 0);

function scalar_test(string $sql): int { return (int)db()->query($sql)->fetchColumn(); }

