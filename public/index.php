<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$route = (string)($_GET['route'] ?? (user() ? 'dashboard' : 'login'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        switch ($route) {
            case 'login':
                $stmt=db()->prepare('SELECT * FROM users WHERE email=? AND active=1');
                $stmt->execute([strtolower(post('email'))]); $found=$stmt->fetch();
                if (!$found || !password_verify(post('password'),$found['password_hash'])) {
                    flash('error','Correu o contrasenya incorrectes.'); redirect('login');
                }
                session_regenerate_id(true);
                $_SESSION['user']=array_intersect_key($found,array_flip(['id','club_id','name','email','role']));
                db()->prepare('UPDATE users SET last_login_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$found['id']]);
                audit('login','user',(int)$found['id']); redirect('dashboard');
            case 'logout':
                require_login(); audit('logout','user',(int)user()['id']); $_SESSION=[]; session_destroy(); redirect('login');
            case 'club_save': save_club();
            case 'club_toggle': toggle_club();
            case 'user_save': save_user();
            case 'season_save': save_season();
            case 'archer_save': save_archer();
            case 'registration_save': save_registration();
            case 'registration_review': review_registration();
            case 'payment_save': save_payment();
            case 'import_preview': preview_import();
            case 'import_confirm': confirm_import();
            case 'settings_save': save_settings();
        }
    } catch (PDOException $e) {
        $message=str_contains(strtolower($e->getMessage()),'unique') ? 'Ja existeix un registre amb aquestes dades.' : 'No s’ha pogut completar l’operació.';
        flash('error',$message); redirect($_SERVER['HTTP_REFERER'] ? parse_route($_SERVER['HTTP_REFERER']) : 'dashboard');
    }
}

if ($route === 'export') export_csv();

switch ($route) {
    case 'login': page_login(); break;
    case 'dashboard': page_dashboard(); break;
    case 'clubs': page_clubs(); break;
    case 'club_form': page_club_form(); break;
    case 'users': page_users(); break;
    case 'user_form': page_user_form(); break;
    case 'seasons': page_seasons(); break;
    case 'archers': page_archers(); break;
    case 'archer_form': page_archer_form(); break;
    case 'registrations': page_registrations(); break;
    case 'registration_form': page_registration_form(); break;
    case 'registration_review_form': page_registration_review(); break;
    case 'payments': page_payments(); break;
    case 'payment_form': page_payment_form(); break;
    case 'import': page_import(); break;
    case 'import_result': page_import_result(); break;
    case 'audit': page_audit(); break;
    case 'settings': page_settings(); break;
    default: http_response_code(404); render('No trobat',fn()=>print '<h1>Pàgina no trobada</h1>');
}

function parse_route(string $url): string { parse_str((string)parse_url($url,PHP_URL_QUERY),$q); return $q['route'] ?? 'dashboard'; }

function save_club(): never {
    require_admin(); $id=(int)post('id');
    foreach(['code','name','manager_name','email'] as $f) if(post($f)===''){ flash('error','Completa els camps obligatoris.'); redirect('club_form',$id?['id'=>$id]:[]); }
    if(!filter_var(post('email'),FILTER_VALIDATE_EMAIL)){ flash('error','El correu no és vàlid.'); redirect('club_form',$id?['id'=>$id]:[]); }
    $values=[strtoupper(post('code')),post('name'),post('cif')?:null,post('address')?:null,post('manager_name'),strtolower(post('email')),post('phone')?:null];
    if($id){ $values[]=$id; db()->prepare('UPDATE clubs SET code=?,name=?,cif=?,address=?,manager_name=?,email=?,phone=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute($values); audit('update','club',$id); }
    else { db()->prepare('INSERT INTO clubs(code,name,cif,address,manager_name,email,phone) VALUES(?,?,?,?,?,?,?)')->execute($values); $id=(int)db()->lastInsertId(); audit('create','club',$id); }
    flash('success','Club desat correctament.'); redirect('clubs');
}
function toggle_club(): never { require_admin(); $id=(int)post('id'); db()->prepare('UPDATE clubs SET active=CASE active WHEN 1 THEN 0 ELSE 1 END,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]); audit('toggle','club',$id); flash('success','Estat del club actualitzat.'); redirect('clubs'); }

function save_user(): never {
    require_admin(); $id=(int)post('id'); $role=post('role'); $club=$role==='club'?(int)post('club_id'):null;
    if(post('name')===''||!filter_var(post('email'),FILTER_VALIDATE_EMAIL)||!in_array($role,['admin','club'],true)||($role==='club'&&!$club)){ flash('error','Revisa els camps obligatoris.'); redirect('user_form',$id?['id'=>$id]:[]); }
    if($id){
        $sql='UPDATE users SET club_id=?,name=?,email=?,role=?,active=?,updated_at=CURRENT_TIMESTAMP'; $args=[$club,post('name'),strtolower(post('email')),$role,isset($_POST['active'])?1:0];
        if(post('password')!==''){ if(strlen(post('password'))<12){flash('error','La contrasenya ha de tenir almenys 12 caràcters.');redirect('user_form',['id'=>$id]);} $sql.=',password_hash=?';$args[]=password_hash(post('password'),PASSWORD_DEFAULT); }
        $sql.=' WHERE id=?';$args[]=$id;db()->prepare($sql)->execute($args);audit('update','user',$id);
    } else {
        if(strlen(post('password'))<12){flash('error','La contrasenya ha de tenir almenys 12 caràcters.');redirect('user_form');}
        db()->prepare('INSERT INTO users(club_id,name,email,password_hash,role) VALUES(?,?,?,?,?)')->execute([$club,post('name'),strtolower(post('email')),password_hash(post('password'),PASSWORD_DEFAULT),$role]);$id=(int)db()->lastInsertId();audit('create','user',$id);
    }
    flash('success','Usuari desat.');redirect('users');
}

function save_season(): never {
    require_admin();
    if(post('name')===''||!valid_date(post('starts_on'))||!valid_date(post('ends_on'))||post('ends_on')<post('starts_on')||!is_numeric(post('fee'))){flash('error','Revisa les dades de la temporada.');redirect('seasons');}
    db()->beginTransaction();
    try { if(isset($_POST['active'])) db()->exec('UPDATE seasons SET active=0'); db()->prepare('INSERT INTO seasons(name,starts_on,ends_on,fee,active) VALUES(?,?,?,?,?)')->execute([post('name'),post('starts_on'),post('ends_on'),(float)post('fee'),isset($_POST['active'])?1:0]); $id=(int)db()->lastInsertId(); db()->commit(); audit('create','season',$id); }
    catch(Throwable $e){db()->rollBack();throw $e;}
    flash('success','Temporada creada.');redirect('seasons');
}

function archer_payload(): array {
    $data=['first_name'=>post('first_name'),'last_name'=>post('last_name'),'document_id'=>strtoupper(post('document_id'))?:null,'birth_date'=>post('birth_date'),'email'=>strtolower(post('email'))?:null,'phone'=>post('phone')?:null,'discipline'=>post('discipline'),'guardian_name'=>post('guardian_name')?:null,'guardian_document_id'=>strtoupper(post('guardian_document_id'))?:null,'guardian_email'=>strtolower(post('guardian_email'))?:null];
    $errors=[]; if($data['first_name']===''||$data['last_name']===''||$data['discipline']===''||!valid_date($data['birth_date']))$errors[]='Completa correctament els camps obligatoris.';
    if($data['email']&&!filter_var($data['email'],FILTER_VALIDATE_EMAIL))$errors[]='El correu de l’arquer no és vàlid.';
    if(is_minor($data['birth_date'])&&(!$data['guardian_name']||!filter_var($data['guardian_email'],FILTER_VALIDATE_EMAIL)))$errors[]='Per als menors cal indicar el nom i el correu del representant legal.';
    return [$data,$errors];
}

function save_archer(): never {
    $u=require_login(); [$d,$errors]=archer_payload(); $id=(int)post('id'); $club=is_admin()?(int)post('club_id'):(int)$u['club_id'];
    if(!$club)$errors[]='Selecciona un club.';
    if(!$id&&!isset($_POST['privacy_acceptance']))$errors[]='Cal confirmar la informació de protecció de dades.';
    if($errors){$_SESSION['form_errors']=$errors;$_SESSION['old']=$_POST;redirect('archer_form',$id?['id'=>$id]:[]);}
    if($id){
        [$where,$args]=club_scope('current_club_id');$check=db()->prepare("SELECT id FROM archers WHERE id=? AND {$where}");$check->execute(array_merge([$id],$args));if(!$check->fetch())abort403();
        db()->prepare('UPDATE archers SET current_club_id=?,first_name=?,last_name=?,document_id=?,birth_date=?,email=?,phone=?,discipline=?,guardian_name=?,guardian_document_id=?,guardian_email=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$club,...array_values($d),$id]); audit('update','archer',$id);flash('success','Dades de l’arquer actualitzades.');redirect('archers');
    }
    $season=(int)post('season_id');if(!$season){flash('error','Selecciona una temporada.');redirect('archer_form');}
    $dup=db()->prepare('SELECT id FROM archers WHERE (document_id IS NOT NULL AND document_id=?) OR (lower(first_name)=lower(?) AND lower(last_name)=lower(?) AND birth_date=?)');$dup->execute([$d['document_id'],$d['first_name'],$d['last_name'],$d['birth_date']]);
    if($dup->fetch()){$_SESSION['form_errors']=['Possible duplicat detectat. Revisa els arquers existents i tramita una renovació.'];$_SESSION['old']=$_POST;redirect('archer_form');}
    db()->beginTransaction();
    try {
        db()->prepare('INSERT INTO archers(current_club_id,first_name,last_name,document_id,birth_date,email,phone,discipline,guardian_name,guardian_document_id,guardian_email) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$club,...array_values($d)]);$id=(int)db()->lastInsertId();
        db()->prepare("INSERT INTO registrations(archer_id,club_id,season_id,type,status,submitted_at,club_notes,created_by) VALUES(?,?,?,'new','pending',CURRENT_TIMESTAMP,?,?)")->execute([$id,$club,$season,post('club_notes')?:null,$u['id']]);$reg=(int)db()->lastInsertId();
        $fee=(float)(db()->query('SELECT fee FROM seasons WHERE id='.(int)$season)->fetchColumn()?:0);db()->prepare('INSERT INTO payments(registration_id,amount) VALUES(?,?)')->execute([$reg,$fee]);
        db()->prepare('INSERT INTO privacy_acceptances(archer_id,registration_id,legal_text_version,acceptance_method,accepted_at,recorded_by) VALUES(?,?,?,?,CURRENT_TIMESTAMP,?)')->execute([$id,$reg,setting('privacy_version','demo'),post('acceptance_method','Confirmació del club'),$u['id']]);
        db()->commit();audit('create','archer',$id);audit('submit','registration',$reg);
    }catch(Throwable $e){db()->rollBack();throw $e;}
    flash('success','Arquer creat i sol·licitud enviada a revisió.');redirect('registrations');
}

function save_registration(): never {
    $u=require_login();$archer=(int)post('archer_id');$season=(int)post('season_id');
    [$where,$args]=club_scope('current_club_id');$s=db()->prepare("SELECT * FROM archers WHERE id=? AND {$where}");$s->execute(array_merge([$archer],$args));$a=$s->fetch();if(!$a)abort403();
    $club=is_admin()?(int)$a['current_club_id']:(int)$u['club_id'];
    db()->beginTransaction();try{db()->prepare("INSERT INTO registrations(archer_id,club_id,season_id,type,status,submitted_at,club_notes,created_by) VALUES(?,?,?,'renewal','pending',CURRENT_TIMESTAMP,?,?)")->execute([$archer,$club,$season,post('club_notes')?:null,$u['id']]);$reg=(int)db()->lastInsertId();$fee=(float)(db()->query('SELECT fee FROM seasons WHERE id='.(int)$season)->fetchColumn()?:0);db()->prepare('INSERT INTO payments(registration_id,amount) VALUES(?,?)')->execute([$reg,$fee]);db()->commit();audit('submit','registration',$reg);}catch(Throwable $e){db()->rollBack();throw $e;}
    flash('success','Renovació enviada.');redirect('registrations');
}

function review_registration(): never {
    $u=require_admin();$id=(int)post('id');$status=post('status');if(!in_array($status,['pending','approved','rejected'],true))abort403();
    $reg=db()->prepare('SELECT * FROM registrations WHERE id=?');$reg->execute([$id]);$r=$reg->fetch();if(!$r)abort403();
    if($status==='rejected'&&post('admin_notes')===''){flash('error','Indica el motiu del rebuig.');redirect('registration_review_form',['id'=>$id]);}
    db()->beginTransaction();try{db()->prepare('UPDATE registrations SET status=?,admin_notes=?,reviewed_at=CURRENT_TIMESTAMP,reviewed_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status,post('admin_notes')?:null,$u['id'],$id]);if($status==='approved'){$a=db()->prepare('SELECT member_number FROM archers WHERE id=?');$a->execute([$r['archer_id']]);$number=$a->fetchColumn()?:member_number((int)$r['archer_id']);db()->prepare("UPDATE archers SET member_number=?,status='active',joined_on=COALESCE(joined_on,date('now')),current_club_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$number,$r['club_id'],$r['archer_id']]);}db()->commit();audit('review','registration',$id,['status'=>$status]);}catch(Throwable $e){db()->rollBack();throw $e;}
    flash('success','Sol·licitud revisada.');redirect('registrations');
}

function save_payment(): never {
    $u=require_admin();$id=(int)post('id');$status=post('status');if(!in_array($status,['pending','paid','exempt','cancelled'],true)||!is_numeric(post('amount')))abort403();
    db()->prepare('UPDATE payments SET amount=?,status=?,paid_at=?,method=?,reference=?,notes=?,verified_by=?,verified_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(float)post('amount'),$status,post('paid_at')?:null,post('method')?:null,post('reference')?:null,post('notes')?:null,$u['id'],$id]);audit('verify','payment',$id,['status'=>$status]);flash('success','Pagament actualitzat.');redirect('payments');
}

function save_settings(): never { require_admin();foreach(['privacy_version','privacy_text'] as $key)db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP')->execute([$key,post($key)]);audit('update','settings');flash('success','Text legal actualitzat.');redirect('settings'); }

function csv_rows(string $file): array {
    $h=fopen($file,'rb');if(!$h)return [];$first=fgets($h);rewind($h);$delimiter=substr_count((string)$first,';')>substr_count((string)$first,',')?';':',';$headers=fgetcsv($h,0,$delimiter);if(!$headers){fclose($h);return [];}$headers=array_map(fn($v)=>strtolower(trim((string)$v)), $headers);$rows=[];while(($values=fgetcsv($h,0,$delimiter))!==false){if(count(array_filter($values,fn($v)=>trim((string)$v)!==''))===0)continue;$values=array_pad($values,count($headers),'');$rows[]=array_combine($headers,array_slice($values,0,count($headers)));}fclose($h);return $rows;
}
function validate_import_row(array $row,int $n,array &$seen): array {
    $required=['nom','cognoms','data_naixement','modalitat','acceptacio_privacitat'];$errors=[];foreach($required as $f)if(trim((string)($row[$f]??''))==='')$errors[]="Falta {$f}";
    if(!valid_date(trim((string)($row['data_naixement']??''))))$errors[]='Data no vàlida (AAAA-MM-DD)';
    if(($row['correu']??'')&&!filter_var(trim($row['correu']),FILTER_VALIDATE_EMAIL))$errors[]='Correu no vàlid';
    if(!in_array(strtolower(trim((string)($row['acceptacio_privacitat']??''))),['si','sí','yes','1'],true))$errors[]='Falta l’acceptació de privacitat';
    if(valid_date(trim((string)($row['data_naixement']??'')))&&is_minor(trim($row['data_naixement']))&&(!trim((string)($row['tutor_nom']??''))||!filter_var(trim((string)($row['tutor_correu']??'')),FILTER_VALIDATE_EMAIL)))$errors[]='Menor sense dades completes del tutor';
    $key=strtolower(trim(($row['document']??'').'|'.($row['nom']??'').'|'.($row['cognoms']??'').'|'.($row['data_naixement']??'')));if(isset($seen[$key]))$errors[]='Fila duplicada dins del CSV';$seen[$key]=$n;
    return $errors;
}
function preview_import(): never {
    $u=require_login();$season=(int)post('season_id');$club=is_admin()?(int)post('club_id'):(int)$u['club_id'];$file=$_FILES['csv']??null;
    if(!$season||!$club||!$file||$file['error']!==UPLOAD_ERR_OK||strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))!=='csv'||$file['size']>2_000_000){flash('error','Selecciona un CSV vàlid (màxim 2 MB), una temporada i un club.');redirect('import');}
    $rows=csv_rows($file['tmp_name']);if(!$rows){flash('error','El fitxer és buit o no té capçaleres vàlides.');redirect('import');}
    $seen=[];$preview=[];$errorCount=0;foreach($rows as $i=>$row){$errors=validate_import_row($row,$i+2,$seen);$doc=strtoupper(trim((string)($row['document']??'')));$dup=db()->prepare('SELECT id,member_number FROM archers WHERE (document_id IS NOT NULL AND document_id=?) OR (lower(first_name)=lower(?) AND lower(last_name)=lower(?) AND birth_date=?)');$dup->execute([$doc?:null,trim((string)($row['nom']??'')),trim((string)($row['cognoms']??'')),trim((string)($row['data_naixement']??''))]);$existing=$dup->fetch();$preview[]=['row'=>$i+2,'data'=>$row,'errors'=>$errors,'existing'=>$existing?:null];$errorCount+=count($errors)>0?1:0;}
    db()->prepare("INSERT INTO imports(club_id,season_id,original_filename,status,total_rows,valid_rows,error_rows,preview_data,created_by) VALUES(?,?,?,'preview',?,?,?,?,?)")->execute([$club,$season,basename($file['name']),count($rows),count($rows)-$errorCount,$errorCount,json_encode($preview,JSON_UNESCAPED_UNICODE),$u['id']]);$id=(int)db()->lastInsertId();foreach($preview as $p)foreach($p['errors'] as $err)db()->prepare('INSERT INTO import_errors(import_id,row_number,message,raw_data) VALUES(?,?,?,?)')->execute([$id,$p['row'],$err,json_encode($p['data'],JSON_UNESCAPED_UNICODE)]);audit('preview','import',$id);redirect('import_result',['id'=>$id]);
}
function confirm_import(): never {
    $u=require_login();$id=(int)post('id');[$where,$args]=club_scope('club_id');$s=db()->prepare("SELECT * FROM imports WHERE id=? AND status='preview' AND error_rows=0 AND {$where}");$s->execute(array_merge([$id],$args));$import=$s->fetch();if(!$import)abort403();$rows=json_decode($import['preview_data'],true);
    db()->beginTransaction();try{foreach($rows as $item){$r=$item['data'];$existing=$item['existing'];if($existing){$archer=(int)$existing['id'];$type='renewal';}else{$type='new';db()->prepare('INSERT INTO archers(current_club_id,first_name,last_name,document_id,birth_date,email,phone,discipline,guardian_name,guardian_document_id,guardian_email) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$import['club_id'],trim($r['nom']),trim($r['cognoms']),strtoupper(trim($r['document']??''))?:null,trim($r['data_naixement']),strtolower(trim($r['correu']??''))?:null,trim($r['telefon']??'')?:null,trim($r['modalitat']),trim($r['tutor_nom']??'')?:null,strtoupper(trim($r['tutor_document']??''))?:null,strtolower(trim($r['tutor_correu']??''))?:null]);$archer=(int)db()->lastInsertId();}
        $check=db()->prepare('SELECT id FROM registrations WHERE archer_id=? AND season_id=?');$check->execute([$archer,$import['season_id']]);if($check->fetch())throw new RuntimeException('Un arquer ja té inscripció en aquesta temporada.');db()->prepare("INSERT INTO registrations(archer_id,club_id,season_id,type,status,submitted_at,created_by) VALUES(?,?,?,?, 'pending',CURRENT_TIMESTAMP,?)")->execute([$archer,$import['club_id'],$import['season_id'],$type,$u['id']]);$reg=(int)db()->lastInsertId();$fee=(float)(db()->query('SELECT fee FROM seasons WHERE id='.(int)$import['season_id'])->fetchColumn()?:0);db()->prepare('INSERT INTO payments(registration_id,amount) VALUES(?,?)')->execute([$reg,$fee]);db()->prepare('INSERT INTO privacy_acceptances(archer_id,registration_id,legal_text_version,acceptance_method,accepted_at,recorded_by) VALUES(?,?,?,?,CURRENT_TIMESTAMP,?)')->execute([$archer,$reg,setting('privacy_version','demo'),'Importació CSV confirmada pel club',$u['id']]);}
        db()->prepare("UPDATE imports SET status='confirmed',confirmed_at=CURRENT_TIMESTAMP,preview_data=NULL WHERE id=?")->execute([$id]);db()->commit();audit('confirm','import',$id);
    }catch(Throwable $e){db()->rollBack();flash('error','No s’ha importat cap fila: '.$e->getMessage());redirect('import_result',['id'=>$id]);}
    flash('success','Importació confirmada. Totes les sol·licituds estan pendents de revisió.');redirect('registrations');
}

function export_csv(): never {
    require_login();$type=$_GET['type']??'archers';header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="actll_'.$type.'_'.date('Y-m-d').'.csv"');$out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");
    if($type==='registrations'){[$w,$a]=club_scope('r.club_id');$s=db()->prepare("SELECT r.id,a.member_number,a.first_name,a.last_name,c.name club,s.name season,r.type,r.status,r.submitted_at FROM registrations r JOIN archers a ON a.id=r.archer_id JOIN clubs c ON c.id=r.club_id JOIN seasons s ON s.id=r.season_id WHERE {$w} ORDER BY r.id DESC");$s->execute($a);}else{[$w,$a]=club_scope('a.current_club_id');$s=db()->prepare("SELECT a.member_number,a.first_name,a.last_name,a.document_id,a.birth_date,a.email,a.phone,a.discipline,c.name club,a.status FROM archers a JOIN clubs c ON c.id=a.current_club_id WHERE {$w} ORDER BY a.last_name");$s->execute($a);}
    $rows=$s->fetchAll();if($rows){fputcsv($out,array_keys($rows[0]),';');foreach($rows as $row)fputcsv($out,$row,';');}fclose($out);exit;
}

function page_login(): void {
    if(user())redirect('dashboard');
    render('Inicia sessió',function(){?><section class="card login"><div class="mark">A</div><h1>Gestió de socis</h1><p class="muted">Accés per a l’administració de l’ACTLL i els responsables dels clubs.</p><form method="post" action="<?=url('login')?>" class="form-grid"><?=csrf_field()?><div class="field full"><label for="email">Correu electrònic</label><input id="email" name="email" type="email" autocomplete="username" required autofocus></div><div class="field full"><label for="password">Contrasenya</label><input id="password" name="password" type="password" autocomplete="current-password" required></div><button class="btn full" type="submit">Inicia sessió</button></form><p class="muted"><small>Demo: admin@actll.test o club@actll.test · Actll2026!</small></p></section><?php });
}

function scalar(string $sql,array $args=[]): int { $s=db()->prepare($sql);$s->execute($args);return (int)$s->fetchColumn(); }
function page_dashboard(): void {
    require_login();[$w,$a]=club_scope('r.club_id');$pending=scalar("SELECT count(*) FROM registrations r WHERE {$w} AND r.status='pending'",$a);$approved=scalar("SELECT count(*) FROM registrations r WHERE {$w} AND r.status='approved'",$a);$archers=scalar('SELECT count(*) FROM archers a WHERE '.club_scope('a.current_club_id')[0],club_scope('a.current_club_id')[1]);$unpaid=scalar("SELECT count(*) FROM payments p JOIN registrations r ON r.id=p.registration_id WHERE {$w} AND p.status='pending'",$a);
    $recent=db()->prepare("SELECT r.id,a.first_name,a.last_name,c.name club,s.name season,r.status FROM registrations r JOIN archers a ON a.id=r.archer_id JOIN clubs c ON c.id=r.club_id JOIN seasons s ON s.id=r.season_id WHERE {$w} ORDER BY r.created_at DESC LIMIT 8");$recent->execute($a);$recent=$recent->fetchAll();
    render('Resum',function()use($pending,$approved,$archers,$unpaid,$recent){?><div class="page-head"><div><h1>Resum</h1><p>Activitat actual del registre de socis.</p></div><a class="btn" href="<?=url('archer_form')?>">Nova alta</a></div><div class="grid"><article class="card stat"><strong><?=$archers?></strong><span>Arquers registrats</span></article><article class="card stat"><strong><?=$pending?></strong><span>Sol·licituds pendents</span></article><article class="card stat"><strong><?=$approved?></strong><span>Inscripcions aprovades</span></article><article class="card stat"><strong><?=$unpaid?></strong><span>Quotes pendents</span></article><section class="card span-12"><h2>Activitat recent</h2><?=registration_table($recent,true)?></section></div><?php });
}

function page_clubs(): void {
    require_admin();$rows=db()->query('SELECT c.*,(SELECT count(*) FROM archers a WHERE a.current_club_id=c.id) archer_count FROM clubs c ORDER BY c.name')->fetchAll();
    render('Clubs',function()use($rows){?><div class="page-head"><div><h1>Clubs</h1><p>Entitats i responsables autoritzats.</p></div><a class="btn" href="<?=url('club_form')?>">Afegeix un club</a></div><div class="filters"><input type="search" placeholder="Cerca un club…" data-table-search="#clubs-table"></div><div class="table-wrap"><table id="clubs-table"><thead><tr><th>Codi</th><th>Club</th><th>Responsable</th><th>Arquers</th><th>Estat</th><th>Accions</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><code><?=e($r['code'])?></code></td><td><strong><?=e($r['name'])?></strong><br><small><?=e($r['cif'])?></small></td><td><?=e($r['manager_name'])?><br><small><?=e($r['email'])?></small></td><td><?=e($r['archer_count'])?></td><td><?=badge($r['active']?'active':'inactive')?></td><td><div class="actions"><a class="btn secondary small" href="<?=url('club_form',['id'=>$r['id']])?>">Edita</a><form method="post" action="<?=url('club_toggle')?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn danger small" data-confirm="Vols canviar l’estat del club?"><?=$r['active']?'Desactiva':'Activa'?></button></form></div></td></tr><?php endforeach;?></tbody></table></div><?php });
}
function page_club_form(): void {
    require_admin();$id=(int)($_GET['id']??0);$r=['id'=>'','code'=>'','name'=>'','cif'=>'','address'=>'','manager_name'=>'','email'=>'','phone'=>''];if($id){$s=db()->prepare('SELECT * FROM clubs WHERE id=?');$s->execute([$id]);$r=$s->fetch()?:$r;}
    render($id?'Edita el club':'Nou club',function()use($r,$id){?><div class="page-head"><div><h1><?=$id?'Edita el club':'Nou club'?></h1><p>Les dades identificatives i de contacte del club.</p></div></div><form class="card form-grid" method="post" action="<?=url('club_save')?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=e($r['id'])?>"><?=input_field('code','Codi del club',$r['code'],true)?><?=input_field('name','Nom del club',$r['name'],true)?><?=input_field('cif','CIF',$r['cif'])?><?=input_field('manager_name','Persona responsable',$r['manager_name'],true)?><?=input_field('email','Correu electrònic',$r['email'],true,'email')?><?=input_field('phone','Telèfon',$r['phone'])?><div class="field full"><label for="address">Adreça</label><textarea id="address" name="address"><?=e($r['address'])?></textarea></div><div class="actions full"><button class="btn">Desa el club</button><a class="btn secondary" href="<?=url('clubs')?>">Cancel·la</a></div></form><?php });
}

function input_field(string $name,string $label,mixed $value='',bool|string $required=false,string $type='text'): string { return '<div class="field"><label for="'.$name.'">'.e($label).($required?' *':'').'</label><input id="'.$name.'" name="'.$name.'" type="'.$type.'" value="'.e($value).'" '.($required?'required':'').'></div>'; }
function select_field(string $name,string $label,array $options,mixed $selected=null,bool $required=false): string { $html='<div class="field"><label for="'.$name.'">'.e($label).($required?' *':'').'</label><select id="'.$name.'" name="'.$name.'" '.($required?'required':'').'><option value="">Selecciona…</option>';foreach($options as $v=>$l)$html.='<option value="'.e($v).'" '.((string)$v===(string)$selected?'selected':'').'>'.e($l).'</option>';return $html.'</select></div>'; }

function page_users(): void {
    require_admin();$rows=db()->query('SELECT u.*,c.name club FROM users u LEFT JOIN clubs c ON c.id=u.club_id ORDER BY u.name')->fetchAll();render('Usuaris',function()use($rows){?><div class="page-head"><div><h1>Usuaris</h1><p>Accés de l’administració i dels clubs.</p></div><a class="btn" href="<?=url('user_form')?>">Nou usuari</a></div><div class="table-wrap"><table><thead><tr><th>Nom</th><th>Correu</th><th>Rol</th><th>Club</th><th>Estat</th><th></th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['name'])?></td><td><?=e($r['email'])?></td><td><?=$r['role']==='admin'?'Administració':'Club'?></td><td><?=e($r['club']??'—')?></td><td><?=badge($r['active']?'active':'inactive')?></td><td><a class="btn secondary small" href="<?=url('user_form',['id'=>$r['id']])?>">Edita</a></td></tr><?php endforeach;?></tbody></table></div><?php });
}
function page_user_form(): void {
    require_admin();$id=(int)($_GET['id']??0);$r=['id'=>'','club_id'=>'','name'=>'','email'=>'','role'=>'club','active'=>1];if($id){$s=db()->prepare('SELECT * FROM users WHERE id=?');$s->execute([$id]);$r=$s->fetch()?:$r;}$clubs=[];foreach(db()->query('SELECT id,name FROM clubs WHERE active=1 ORDER BY name') as $c)$clubs[$c['id']]=$c['name'];render($id?'Edita l’usuari':'Nou usuari',function()use($r,$id,$clubs){?><div class="page-head"><div><h1><?=$id?'Edita l’usuari':'Nou usuari'?></h1></div></div><form class="card form-grid" method="post" action="<?=url('user_save')?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=e($r['id'])?>"><?=input_field('name','Nom',$r['name'],true)?><?=input_field('email','Correu electrònic',$r['email'],true,'email')?><?=select_field('role','Rol',['club'=>'Responsable de club','admin'=>'Administració ACTLL'],$r['role'],true)?><?=select_field('club_id','Club',$clubs,$r['club_id'])?><div class="field"><label for="password">Contrasenya <?=$id?'(deixa-la buida per conservar-la)':'*'?></label><input id="password" type="password" name="password" <?=$id?'':'required'?> minlength="12" autocomplete="new-password"></div><?php if($id):?><label class="check"><input type="checkbox" name="active" value="1" <?=$r['active']?'checked':''?>> Usuari actiu</label><?php endif;?><div class="actions full"><button class="btn">Desa</button><a class="btn secondary" href="<?=url('users')?>">Cancel·la</a></div></form><?php });
}

function page_seasons(): void {
    require_admin();$rows=db()->query('SELECT s.*,(SELECT count(*) FROM registrations r WHERE r.season_id=s.id) registrations FROM seasons s ORDER BY starts_on DESC')->fetchAll();render('Temporades',function()use($rows){?><div class="page-head"><div><h1>Temporades i quotes</h1><p>Una única temporada pot estar marcada com a activa.</p></div></div><div class="grid"><section class="card span-6"><h2>Nova temporada</h2><form class="form-grid" method="post" action="<?=url('season_save')?>"><?=csrf_field()?><?=input_field('name','Nom (p. ex. 2027-2028)','',true)?><?=input_field('fee','Quota (€)','30.00',true,'number')?><?=input_field('starts_on','Data d’inici','',true,'date')?><?=input_field('ends_on','Data de finalització','',true,'date')?><label class="check full"><input type="checkbox" name="active" value="1"> Marcar com a temporada activa</label><button class="btn full">Crea la temporada</button></form></section><section class="card span-6"><h2>Temporades</h2><div class="table-wrap"><table><thead><tr><th>Nom</th><th>Període</th><th>Quota</th><th>Estat</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><strong><?=e($r['name'])?></strong><br><small><?=$r['registrations']?> inscripcions</small></td><td><?=e($r['starts_on'])?><br><?=e($r['ends_on'])?></td><td><?=number_format((float)$r['fee'],2,',','.')?> €</td><td><?=badge($r['active']?'active':'inactive')?></td></tr><?php endforeach;?></tbody></table></div></section></div><?php });
}

function page_archers(): void {
    require_login();[$w,$a]=club_scope('a.current_club_id');$s=db()->prepare("SELECT a.*,c.name club FROM archers a JOIN clubs c ON c.id=a.current_club_id WHERE {$w} ORDER BY a.last_name,a.first_name");$s->execute($a);$rows=$s->fetchAll();render('Arquers',function()use($rows){?><div class="page-head"><div><h1>Arquers</h1><p>Fitxes personals i número estable de soci.</p></div><div class="actions"><a class="btn secondary" href="<?=url('export',['type'=>'archers'])?>">Exporta CSV</a><a class="btn" href="<?=url('archer_form')?>">Nova alta</a></div></div><div class="filters"><input type="search" placeholder="Cerca nom, document o club…" data-table-search="#archers-table"></div><div class="table-wrap"><table id="archers-table"><thead><tr><th>Núm. soci</th><th>Arquer</th><th>Club</th><th>Modalitat</th><th>Estat</th><th>Accions</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['member_number']??'—')?></td><td><strong><?=e($r['last_name'].', '.$r['first_name'])?></strong><br><small><?=e($r['document_id']??'Sense document')?> · <?=e($r['birth_date'])?></small></td><td><?=e($r['club'])?></td><td><?=e($r['discipline'])?></td><td><?=badge($r['status'])?></td><td><div class="actions"><a class="btn secondary small" href="<?=url('archer_form',['id'=>$r['id']])?>">Edita</a><a class="btn small" href="<?=url('registration_form',['archer_id'=>$r['id']])?>">Renova</a></div></td></tr><?php endforeach;?></tbody></table><?php if(!$rows):?><p class="empty">Encara no hi ha cap arquer.</p><?php endif;?></div><?php });
}

function page_archer_form(): void {
    $u=require_login();$id=(int)($_GET['id']??0);$r=['id'=>'','current_club_id'=>$u['club_id'],'first_name'=>'','last_name'=>'','document_id'=>'','birth_date'=>'','email'=>'','phone'=>'','discipline'=>'','guardian_name'=>'','guardian_document_id'=>'','guardian_email'=>''];if($id){[$w,$a]=club_scope('current_club_id');$s=db()->prepare("SELECT * FROM archers WHERE id=? AND {$w}");$s->execute(array_merge([$id],$a));$r=$s->fetch();if(!$r)abort403();}$old=$_SESSION['old']??[];$errors=$_SESSION['form_errors']??[];unset($_SESSION['old'],$_SESSION['form_errors']);$r=array_merge($r,$old);$clubs=[];foreach(db()->query('SELECT id,name FROM clubs WHERE active=1 ORDER BY name') as $c)$clubs[$c['id']]=$c['name'];$seasons=[];foreach(db()->query('SELECT id,name FROM seasons ORDER BY starts_on DESC') as $s)$seasons[$s['id']]=$s['name'];
    render($id?'Edita l’arquer':'Nova alta',function()use($r,$id,$errors,$clubs,$seasons){?><div class="page-head"><div><h1><?=$id?'Edita l’arquer':'Nova alta d’arquer'?></h1><p><?=$id?'Actualitza les dades de la fitxa.':'La sol·licitud quedarà pendent de validació.'?></p></div></div><?php if($errors):?><div class="alert error"><ul class="error-list"><?php foreach($errors as $e):?><li><?=e($e)?></li><?php endforeach;?></ul></div><?php endif;?><form class="card form-grid" method="post" action="<?=url('archer_save')?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=e($r['id'])?>"><?php if(is_admin()):?><?=select_field('club_id','Club',$clubs,$r['current_club_id'],true)?><?php endif;?><?=input_field('first_name','Nom',$r['first_name'],true)?><?=input_field('last_name','Cognoms',$r['last_name'],true)?><?=input_field('document_id','DNI/NIE o document',$r['document_id'])?><?=input_field('birth_date','Data de naixement',$r['birth_date'],true,'date')?><?=input_field('email','Correu electrònic',$r['email'],false,'email')?><?=input_field('phone','Telèfon',$r['phone'])?><?=input_field('discipline','Modalitat de tir',$r['discipline'],true)?><fieldset class="card full" data-guardian hidden><legend>Dades del representant legal</legend><div class="form-grid"><?=input_field('guardian_name','Nom del representant',$r['guardian_name'])?><?=input_field('guardian_document_id','Document del representant',$r['guardian_document_id'])?><?=input_field('guardian_email','Correu del representant',$r['guardian_email'],false,'email')?></div></fieldset><?php if(!$id):?><?=select_field('season_id','Temporada',$seasons,$r['season_id']??'',true)?><div class="field full"><label for="club_notes">Observacions del club</label><textarea id="club_notes" name="club_notes"><?=e($r['club_notes']??'')?></textarea></div><label class="check full"><input type="checkbox" name="privacy_acceptance" value="1" required> <span><?=e(setting('privacy_text'))?></span></label><input type="hidden" name="acceptance_method" value="Confirmació del responsable del club"><?php endif;?><div class="actions full"><button class="btn"><?=$id?'Desa els canvis':'Envia la sol·licitud'?></button><a class="btn secondary" href="<?=url('archers')?>">Cancel·la</a></div></form><?php });
}

function registration_table(array $rows,bool $compact=false): string {
    $html='<div class="table-wrap"><table><thead><tr><th>Arquer</th>';
    if(!$compact)$html.='<th>Club</th>';
    $html.='<th>Temporada</th><th>Estat</th>';
    if(!$compact)$html.='<th>Tipus</th><th>Accions</th>';
    $html.='</tr></thead><tbody>';
    foreach($rows as $r){
        $html.='<tr><td><strong>'.e($r['last_name'].', '.$r['first_name']).'</strong>';
        if(array_key_exists('member_number',$r))$html.='<br><small>'.e($r['member_number']??'Sense número').'</small>';
        $html.='</td>';
        if(!$compact)$html.='<td>'.e($r['club']).'</td>';
        $html.='<td>'.e($r['season']).'</td><td>'.badge($r['status']).'</td>';
        if(!$compact){
            $html.='<td>'.($r['type']==='new'?'Alta':'Renovació').'</td><td>';
            $html.=is_admin()?'<a class="btn secondary small" href="'.url('registration_review_form',['id'=>$r['id']]).'">Revisa</a>':'<span class="muted">'.e($r['admin_notes']??'').'</span>';
            $html.='</td>';
        }
        $html.='</tr>';
    }
    $html.='</tbody></table>'.(!$rows?'<p class="empty">No hi ha resultats.</p>':'').'</div>';
    return $html;
}
function page_registrations(): void {
    require_login();[$w,$a]=club_scope('r.club_id');$status=$_GET['status']??'';$sql="SELECT r.*,a.first_name,a.last_name,a.member_number,c.name club,s.name season FROM registrations r JOIN archers a ON a.id=r.archer_id JOIN clubs c ON c.id=r.club_id JOIN seasons s ON s.id=r.season_id WHERE {$w}";$args=$a;if(in_array($status,['draft','pending','approved','rejected'],true)){$sql.=' AND r.status=?';$args[]=$status;}$sql.=' ORDER BY r.created_at DESC';$s=db()->prepare($sql);$s->execute($args);$rows=$s->fetchAll();render('Inscripcions',function()use($rows,$status){?><div class="page-head"><div><h1>Inscripcions</h1><p>Altes i renovacions per temporada.</p></div><a class="btn secondary" href="<?=url('export',['type'=>'registrations'])?>">Exporta CSV</a></div><div class="filters"><a class="btn secondary small" href="<?=url('registrations')?>">Totes</a><?php foreach(['pending'=>'Pendents','approved'=>'Aprovades','rejected'=>'Rebutjades'] as $v=>$l):?><a class="btn secondary small" href="<?=url('registrations',['status'=>$v])?>"><?=$l?></a><?php endforeach;?></div><?=registration_table($rows)?><?php });
}
function page_registration_form(): void {
    require_login();$id=(int)($_GET['archer_id']??0);[$w,$a]=club_scope('a.current_club_id');$s=db()->prepare("SELECT a.*,c.name club FROM archers a JOIN clubs c ON c.id=a.current_club_id WHERE a.id=? AND {$w}");$s->execute(array_merge([$id],$a));$archer=$s->fetch();if(!$archer)abort403();$seasons=[];foreach(db()->query('SELECT id,name FROM seasons ORDER BY starts_on DESC') as $season)$seasons[$season['id']]=$season['name'];render('Renova l’arquer',function()use($archer,$seasons){?><div class="page-head"><div><h1>Renova <?=e($archer['first_name'].' '.$archer['last_name'])?></h1><p><?=e($archer['club'])?> · <?=e($archer['member_number']??'Encara sense número de soci')?></p></div></div><form method="post" action="<?=url('registration_save')?>" class="card form-grid"><?=csrf_field()?><input type="hidden" name="archer_id" value="<?=$archer['id']?>"><?=select_field('season_id','Temporada',$seasons,'',true)?><div class="field full"><label for="club_notes">Observacions</label><textarea id="club_notes" name="club_notes"></textarea></div><div class="actions full"><button class="btn">Envia la renovació</button><a class="btn secondary" href="<?=url('archers')?>">Cancel·la</a></div></form><?php });
}
function page_registration_review(): void {
    require_admin();$id=(int)($_GET['id']??0);$s=db()->prepare('SELECT r.*,a.first_name,a.last_name,a.document_id,a.birth_date,a.email,a.discipline,a.member_number,c.name club,se.name season,p.status payment_status,p.amount FROM registrations r JOIN archers a ON a.id=r.archer_id JOIN clubs c ON c.id=r.club_id JOIN seasons se ON se.id=r.season_id JOIN payments p ON p.registration_id=r.id WHERE r.id=?');$s->execute([$id]);$r=$s->fetch();if(!$r)abort403();render('Revisa la inscripció',function()use($r){?><div class="page-head"><div><h1>Revisa la sol·licitud #<?=$r['id']?></h1><p><?=e($r['club'])?> · <?=e($r['season'])?></p></div></div><div class="grid"><section class="card span-6"><h2><?=e($r['first_name'].' '.$r['last_name'])?></h2><dl><dt>Document</dt><dd><?=e($r['document_id']??'—')?></dd><dt>Naixement</dt><dd><?=e($r['birth_date'])?></dd><dt>Correu</dt><dd><?=e($r['email']??'—')?></dd><dt>Modalitat</dt><dd><?=e($r['discipline'])?></dd><dt>Quota</dt><dd><?=number_format((float)$r['amount'],2,',','.')?> € · <?=badge($r['payment_status'])?></dd></dl><p><strong>Observacions del club:</strong><br><?=nl2br(e($r['club_notes']??'Sense observacions'))?></p></section><section class="card span-6"><h2>Decisió administrativa</h2><form method="post" action="<?=url('registration_review')?>" class="form-grid"><?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>"><?=select_field('status','Estat',['pending'=>'Pendent','approved'=>'Aprovada','rejected'=>'Rebutjada'],$r['status'],true)?><div class="field full"><label for="admin_notes">Observacions / motiu del rebuig</label><textarea id="admin_notes" name="admin_notes"><?=e($r['admin_notes']??'')?></textarea></div><button class="btn full" data-confirm="Vols desar aquesta decisió?">Desa la revisió</button></form></section></div><?php });
}

function page_payments(): void {
    require_login();[$w,$a]=club_scope('r.club_id');$s=db()->prepare("SELECT p.*,r.club_id,a.first_name,a.last_name,c.name club,se.name season FROM payments p JOIN registrations r ON r.id=p.registration_id JOIN archers a ON a.id=r.archer_id JOIN clubs c ON c.id=r.club_id JOIN seasons se ON se.id=r.season_id WHERE {$w} ORDER BY p.created_at DESC");$s->execute($a);$rows=$s->fetchAll();render('Quotes',function()use($rows){?><div class="page-head"><div><h1>Quotes i pagaments</h1><p>Seguiment econòmic vinculat a cada inscripció.</p></div></div><div class="table-wrap"><table><thead><tr><th>Arquer</th><th>Club</th><th>Temporada</th><th>Import</th><th>Estat</th><?php if(is_admin()):?><th></th><?php endif;?></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['last_name'].', '.$r['first_name'])?></td><td><?=e($r['club'])?></td><td><?=e($r['season'])?></td><td><?=number_format((float)$r['amount'],2,',','.')?> €</td><td><?=badge($r['status'])?></td><?php if(is_admin()):?><td><a class="btn secondary small" href="<?=url('payment_form',['id'=>$r['id']])?>">Gestiona</a></td><?php endif;?></tr><?php endforeach;?></tbody></table></div><?php });
}
function page_payment_form(): void {
    require_admin();$id=(int)($_GET['id']??0);$s=db()->prepare('SELECT p.*,a.first_name,a.last_name,c.name club,se.name season FROM payments p JOIN registrations r ON r.id=p.registration_id JOIN archers a ON a.id=r.archer_id JOIN clubs c ON c.id=r.club_id JOIN seasons se ON se.id=r.season_id WHERE p.id=?');$s->execute([$id]);$r=$s->fetch();if(!$r)abort403();render('Gestiona el pagament',function()use($r){?><div class="page-head"><div><h1>Pagament de <?=e($r['first_name'].' '.$r['last_name'])?></h1><p><?=e($r['club'].' · '.$r['season'])?></p></div></div><form class="card form-grid" method="post" action="<?=url('payment_save')?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>"><?=input_field('amount','Import (€)',$r['amount'],true,'number')?><?=select_field('status','Estat',['pending'=>'Pendent','paid'=>'Pagat','exempt'=>'Exempt','cancelled'=>'Anul·lat'],$r['status'],true)?><?=input_field('paid_at','Data de pagament',$r['paid_at'],'','date')?><?=input_field('method','Mètode',$r['method'])?><?=input_field('reference','Referència',$r['reference'])?><div class="field full"><label for="notes">Observacions</label><textarea id="notes" name="notes"><?=e($r['notes'])?></textarea></div><div class="actions full"><button class="btn">Desa</button><a class="btn secondary" href="<?=url('payments')?>">Cancel·la</a></div></form><?php });
}

function page_import(): void {
    require_login();$seasons=[];foreach(db()->query('SELECT id,name FROM seasons ORDER BY starts_on DESC') as $s)$seasons[$s['id']]=$s['name'];$clubs=[];if(is_admin())foreach(db()->query('SELECT id,name FROM clubs WHERE active=1 ORDER BY name') as $c)$clubs[$c['id']]=$c['name'];render('Importa un CSV',function()use($seasons,$clubs){?><div class="page-head"><div><h1>Importació massiva</h1><p>Valida totes les files abans de crear cap sol·licitud.</p></div><a class="btn secondary" href="/templates/plantilla_inscripcions.csv" download>Descarrega la plantilla</a></div><div class="grid"><section class="card span-6"><h2>1. Selecciona el fitxer</h2><form method="post" action="<?=url('import_preview')?>" enctype="multipart/form-data" class="form-grid"><?=csrf_field()?><?php if(is_admin()):?><?=select_field('club_id','Club',$clubs,'',true)?><?php endif;?><?=select_field('season_id','Temporada',$seasons,'',true)?><div class="field full"><label for="csv">Fitxer CSV (màxim 2 MB)</label><input id="csv" type="file" name="csv" accept=".csv,text/csv" required></div><button class="btn full">Comprova el fitxer</button></form></section><aside class="card span-6"><h2>Com funciona?</h2><ol><li>Descarrega la plantilla i conserva les capçaleres.</li><li>Afegeix una fila per arquer i desa en format CSV.</li><li>El sistema comprova camps, duplicats, dates, correus i menors.</li><li>Revisa el resum. No es desa cap arquer fins que confirmis.</li></ol><p class="muted">S’accepten separadors de coma o punt i coma.</p></aside></div><?php });
}
function page_import_result(): void {
    require_login();$id=(int)($_GET['id']??0);[$w,$a]=club_scope('i.club_id');$s=db()->prepare("SELECT i.*,c.name club,se.name season FROM imports i JOIN clubs c ON c.id=i.club_id JOIN seasons se ON se.id=i.season_id WHERE i.id=? AND {$w}");$s->execute(array_merge([$id],$a));$r=$s->fetch();if(!$r)abort403();$preview=json_decode($r['preview_data']??'[]',true)?:[];render('Resultat de la importació',function()use($r,$preview){?><div class="page-head"><div><h1>Previsualització del CSV</h1><p><?=e($r['original_filename'].' · '.$r['club'].' · '.$r['season'])?></p></div></div><div class="grid"><article class="card stat"><strong><?=$r['total_rows']?></strong><span>Files totals</span></article><article class="card stat"><strong><?=$r['valid_rows']?></strong><span>Files vàlides</span></article><article class="card stat"><strong><?=$r['error_rows']?></strong><span>Files amb errors</span></article><article class="card stat"><strong><?=count(array_filter($preview,fn($x)=>!empty($x['existing'])))?></strong><span>Renovacions detectades</span></article><section class="span-12"><div class="table-wrap"><table><thead><tr><th>Fila</th><th>Arquer</th><th>Resultat</th></tr></thead><tbody><?php foreach($preview as $p):?><tr><td><?=$p['row']?></td><td><strong><?=e(($p['data']['cognoms']??'').', '.($p['data']['nom']??''))?></strong><br><small><?=e($p['data']['document']??'')?></small></td><td><?php if($p['errors']):?><ul class="error-list"><?php foreach($p['errors'] as $err):?><li><?=e($err)?></li><?php endforeach;?></ul><?php else:?><?=badge('approved')?> <?=$p['existing']?'Renovació':'Alta nova'?><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php if($r['status']==='preview'&&(int)$r['error_rows']===0):?><form method="post" action="<?=url('import_confirm')?>" class="card" style="margin-top:1rem"><?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>"><p><strong>La confirmació crearà <?=$r['valid_rows']?> sol·licituds pendents.</strong> L’operació és transaccional: si falla una fila, no se’n desarà cap.</p><button class="btn" data-confirm="Vols confirmar tota la importació?">Confirma la importació</button></form><?php elseif((int)$r['error_rows']>0):?><div class="alert error" style="margin-top:1rem">Corregeix les files indicades al CSV i torna’l a carregar. No s’ha desat cap arquer.</div><?php endif;?></section></div><?php });
}

function page_audit(): void {
    require_admin();$rows=db()->query('SELECT l.*,u.name user_name FROM audit_log l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.created_at DESC LIMIT 250')->fetchAll();render('Auditoria',function()use($rows){?><div class="page-head"><div><h1>Registre d’auditoria</h1><p>Darreres 250 accions rellevants.</p></div><a class="btn secondary" href="<?=url('settings')?>">Text de privacitat</a></div><div class="table-wrap"><table><thead><tr><th>Data</th><th>Usuari</th><th>Acció</th><th>Entitat</th><th>Detalls</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['created_at'])?></td><td><?=e($r['user_name']??'Sistema')?></td><td><?=e($r['action'])?></td><td><?=e($r['entity_type'])?> #<?=e($r['entity_id']??'—')?></td><td><small><?=e($r['details']??'')?></small></td></tr><?php endforeach;?></tbody></table></div><?php });
}
function page_settings(): void {
    require_admin();render('Privacitat',function(){?><div class="page-head"><div><h1>Text de protecció de dades</h1><p>Ha de ser revisat per l’assessorament jurídic de l’ACTLL.</p></div></div><form class="card form-grid" method="post" action="<?=url('settings_save')?>"><?=csrf_field()?><?=input_field('privacy_version','Versió del text',setting('privacy_version'),true)?><div class="field full"><label for="privacy_text">Text de confirmació</label><textarea id="privacy_text" name="privacy_text" required><?=e(setting('privacy_text'))?></textarea></div><button class="btn full">Desa el text</button></form><?php });
}

