<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$configFile = $root . '/config/config.php';
if (!is_file($configFile)) {
    throw new RuntimeException('Falta config/config.php. Executa: php database/init.php');
}
$GLOBALS['config'] = require $configFile;
date_default_timezone_set($GLOBALS['config']['app']['timezone'] ?? 'Europe/Madrid');

if (PHP_SAPI !== 'cli') {
    session_name($GLOBALS['config']['app']['session_name'] ?? 'actll_session');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true,
        'secure' => (bool)($GLOBALS['config']['app']['secure_cookies'] ?? false),
        'samesite' => 'Lax',
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
}

function config(string $path, mixed $default = null): mixed {
    $value = $GLOBALS['config'];
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) return $default;
        $value = $value[$key];
    }
    return $value;
}

function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $path = (string)config('database.path');
    if (!is_file($path)) throw new RuntimeException('Base de dades no inicialitzada. Executa: php database/init.php');
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $route = '', array $params = []): string {
    if ($route !== '') $params = ['route' => $route] + $params;
    return '/index.php' . ($params ? '?' . http_build_query($params) : '');
}
function redirect(string $route, array $params = []): never {
    header('Location: ' . url($route, $params), true, 303); exit;
}
function flash(string $type, string $message): void { $_SESSION['flashes'][] = compact('type','message'); }
function take_flashes(): array { $f = $_SESSION['flashes'] ?? []; unset($_SESSION['flashes']); return $f; }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function verify_csrf(): void {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf'])) {
        http_response_code(419); exit('La sessió del formulari ha caducat. Torna enrere i prova-ho de nou.');
    }
}
function user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): array { if (!user()) redirect('login'); return user(); }
function require_admin(): array { $u=require_login(); if ($u['role'] !== 'admin') abort403(); return $u; }
function abort403(): never { http_response_code(403); exit('No tens permís per accedir a aquest recurs.'); }
function is_admin(): bool { return (user()['role'] ?? null) === 'admin'; }
function club_scope(string $column = 'club_id'): array {
    return is_admin() ? ['1=1', []] : ["{$column} = ?", [(int)user()['club_id']]];
}
function audit(string $action, string $entity, ?int $id = null, array $details = []): void {
    $stmt=db()->prepare('INSERT INTO audit_log(user_id,action,entity_type,entity_id,details,ip_address) VALUES(?,?,?,?,?,?)');
    $stmt->execute([user()['id'] ?? null,$action,$entity,$id,$details ? json_encode($details,JSON_UNESCAPED_UNICODE) : null,$_SERVER['REMOTE_ADDR'] ?? null]);
}
function setting(string $key, string $default=''): string {
    $s=db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?'); $s->execute([$key]);
    return (string)($s->fetchColumn() ?: $default);
}
function post(string $key, string $default=''): string { return trim((string)($_POST[$key] ?? $default)); }
function valid_date(string $value): bool { $d=DateTimeImmutable::createFromFormat('Y-m-d',$value); return $d && $d->format('Y-m-d')===$value; }
function is_minor(string $birthDate): bool { return (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable('today'))->y < 18; }
function member_number(int $id): string { return 'ACTLL-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT); }

function render(string $title, callable $content): void {
    $u=user(); $flashes=take_flashes();
    ?><!doctype html><html lang="ca"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?=e($title)?> · ACTLL</title><link rel="stylesheet" href="/assets/app.css"><script defer src="/assets/app.js"></script></head>
    <body><a class="skip" href="#content">Ves al contingut</a>
    <?php if($u): ?><header class="topbar"><a class="brand" href="<?=url('dashboard')?>"><span>ACTLL</span><small>Gestió de socis</small></a>
    <button class="nav-toggle" type="button" aria-label="Obre el menú">☰</button><nav>
    <a href="<?=url('dashboard')?>">Resum</a><a href="<?=url('archers')?>">Arquers</a><a href="<?=url('registrations')?>">Inscripcions</a>
    <a href="<?=url('import')?>">Importar CSV</a><a href="<?=url('payments')?>">Quotes</a>
    <?php if(is_admin()): ?><a href="<?=url('clubs')?>">Clubs</a><a href="<?=url('seasons')?>">Temporades</a><a href="<?=url('users')?>">Usuaris</a><a href="<?=url('audit')?>">Auditoria</a><?php endif; ?>
    </nav><div class="account"><span><?=e($u['name'])?></span><form method="post" action="<?=url('logout')?>"><?=csrf_field()?><button class="link-button">Surt</button></form></div></header><?php endif; ?>
    <main id="content" class="container <?=!$u?'auth-container':''?>">
    <?php foreach($flashes as $f): ?><div class="alert <?=e($f['type'])?>" role="status"><?=e($f['message'])?></div><?php endforeach; ?>
    <?php $content(); ?></main><footer>Demo ACTLL · Les dades i els textos legals s'han de revisar abans de producció.</footer></body></html><?php
}

function badge(string $status): string {
    $labels=['draft'=>'Esborrany','pending'=>'Pendent','approved'=>'Aprovada','rejected'=>'Rebutjada','active'=>'Actiu','inactive'=>'Inactiu','paid'=>'Pagat','exempt'=>'Exempt','cancelled'=>'Anul·lat'];
    return '<span class="badge ' . e($status) . '">' . e($labels[$status] ?? ucfirst($status)) . '</span>';
}

