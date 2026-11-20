<?php
declare(strict_types=1);
/**
 * Beheer and the anonymous counters (admin.js, admin-alerts.js, toestel.js, widget.js, extra.js feedback, nieuws.js
 * beheer part). Sessions are the same signed tokens as the Worker (HMAC with BEHEER_USER and BEHEER_PASSWORD), so a
 * beheer app that is logged in on the Worker stays logged in here.
 */
require_once __DIR__.'/push.php';
require_once __DIR__.'/nieuws.php';

const AA_BEHEER_TOPIC='nl.lrvdlinden.p2000beheer';
const AA_LRBEHEER_TOPIC='app.lrvdlinden.lrbeheer';
function aaDayKey(?int $ms=null): string { return aaDagVanTz($ms??aaNow()); }
function aaDagVanTz(int $ms): string { return (new DateTimeImmutable('@'.intdiv($ms,1000)))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d'); }
function aaDaysAgo(int $n): string { return aaDayKey(aaNow()-$n*86400000); }
function aaClientIp(): string { return (string)($_SERVER['HTTP_CF_CONNECTING_IP']??($_SERVER['REMOTE_ADDR']??'')); }

// ------------------------------------------------------------------ sessions
function aaBeheerConfigured(): bool { return setting('BEHEER_USER')!==''&&mb_strlen(setting('BEHEER_PASSWORD'))>=6; }
function aaHmacKey(): string { return hash('sha256','p2000beheer|'.setting('BEHEER_USER').'|'.setting('BEHEER_PASSWORD'),true); }
function aaLogin(array $body): array {
  if(!aaBeheerConfigured())throw new AaHttpError(503,'Beheer is nog niet ingesteld (BEHEER_USER / BEHEER_PASSWORD)');
  $key='login_fail:'.(aaClientIp()?:'unknown'); $f=aaKvRow($key);
  $recent=$f&&aaNow()-(int)$f['at']<15*60000?(int)$f['value']:0;
  if($recent>=8)throw new AaHttpError(429,'Te veel pogingen. Probeer het over een kwartier opnieuw.');
  $userOk=mb_strtolower((string)($body['user']??''))===mb_strtolower(setting('BEHEER_USER'));
  $passOk=hash_equals(hash('sha256',setting('BEHEER_PASSWORD')),hash('sha256',(string)($body['password']??'')));
  if(!$userOk||!$passOk){ aaKvSet($key,(string)($recent+1)); throw new AaHttpError(401,'Gebruikersnaam of wachtwoord klopt niet'); }
  aaDb()->prepare('DELETE FROM kv WHERE `key` = ?')->execute([$key]);
  $exp=aaNow()+30*86400000;
  $payload=aaB64url(json_encode(['u'=>setting('BEHEER_USER'),'exp'=>$exp]));
  return ['token'=>$payload.'.'.aaB64url(hash_hmac('sha256',$payload,aaHmacKey(),true)),'user'=>setting('BEHEER_USER'),'expires'=>aaIso($exp)];
}
function aaRequireAdmin(): array {
  if(!aaBeheerConfigured())throw new AaHttpError(503,'Beheer is nog niet ingesteld');
  $auth=(string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
  $token=str_starts_with($auth,'Bearer ')?substr($auth,7):'';
  $p=explode('.',$token); if(count($p)!==2||$p[0]===''||$p[1]==='')throw new AaHttpError(401,'Niet ingelogd');
  $sig=base64_decode(strtr($p[1],'-_','+/'),false);
  if(!is_string($sig)||!hash_equals(hash_hmac('sha256',$p[0],aaHmacKey(),true),$sig))throw new AaHttpError(401,'Sessie ongeldig');
  $d=json_decode((string)base64_decode(strtr($p[0],'-_','+/')),true);
  if(!is_array($d)||empty($d['exp'])||$d['exp']<aaNow())throw new AaHttpError(401,'Sessie verlopen');
  return $d;
}

// ------------------------------------------------------------------ anonymous counters
function aaStrN(mixed $v, int $n): ?string { return is_string($v)&&$v!==''?mb_substr($v,0,$n):null; }
function aaRecordInstall(array $b): void {
  $id=strtolower((string)($b['id']??'')); if(!preg_match('/^[0-9a-f-]{32,40}$/',$id))throw new AaHttpError(400,'Invalid id');
  $pl=in_array($b['platform']??null,['ios','ipados','macos','watchos','tvos','visionos','android'],true)?$b['platform']:'ios'; $now=aaNow();
  $build=array_key_exists('build',$b)&&$b['build']!==null?(is_bool($b['build'])?($b['build']?'true':'false'):(string)$b['build']):'';
  aaDb()->prepare('INSERT INTO installs (id, platform, model, os_version, app_version, build, apple_login, push, language, first_seen, last_seen) VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE platform=VALUES(platform), model=VALUES(model), os_version=VALUES(os_version), app_version=VALUES(app_version), build=VALUES(build),
    apple_login=VALUES(apple_login), push=VALUES(push), language=VALUES(language), last_seen=VALUES(last_seen)')
    ->execute([$id,$pl,aaStrN($b['model']??null,40),aaStrN($b['os']??null,20),aaStrN($b['app_version']??null,20),aaStrN($build,10),!empty($b['apple_login'])?1:0,!empty($b['push'])?1:0,aaStrN($b['language']??null,8),$now,$now]);
  aaDb()->prepare('INSERT IGNORE INTO install_days (day, id) VALUES (?, ?)')->execute([aaDayKey(),$id]);
}
function aaVisitorKey(string $day): ?string {
  $ua=(string)($_SERVER['HTTP_USER_AGENT']??'');
  if($ua===''||preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|headless|lighthouse|monitor|curl|wget|python|node-fetch/i',$ua))return null;
  $k="visit_salt:$day"; $salt=aaKvGet($k);
  if($salt===null){ aaDb()->prepare('INSERT IGNORE INTO kv (`key`, value, at) VALUES (?, ?, ?)')->execute([$k,aaB64url(random_bytes(24)),aaNow()]); $salt=aaKvGet($k); }
  return substr(aaB64url(hash('sha256',$salt.'|'.aaClientIp().'|'.$ua,true)),0,22);
}
function aaRecordVisit(array $b): void {
  $day=aaDayKey(); $v=aaVisitorKey($day); if(!$v)return;
  $ua=(string)($_SERVER['HTTP_USER_AGENT']??''); $dev=preg_match('/iPad|Tablet/i',$ua)?'tablet':(preg_match('/Mobi|iPhone|Android/i',$ua)?'mobiel':'desktop');
  $path=mb_substr(explode('?',(string)($b['path']??'/'))[0],0,80);
  if(preg_match('#^/(p2000-apeldoorn/)?(pager|melding)#',$path))$path='/pager/'; elseif(preg_match('#^/(p2000-apeldoorn/)?info#',$path))$path='/info/';
  elseif(preg_match('#^/android#',$path))$path='/android/'; elseif(str_contains($path,'melding'))$path='/melding/'; else $path='/';
  aaDb()->prepare('INSERT INTO visits (day, visitor, pages, device) VALUES (?, ?, 1, ?) ON DUPLICATE KEY UPDATE pages = pages + 1')->execute([$day,$v,$dev]);
  aaDb()->prepare('INSERT INTO pageviews (day, path, n) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$day,$path]);
}
function aaRecordDownload(array $b): bool {
  $day=aaDayKey(); $v=aaVisitorKey($day); if(!$v)return false;
  if(($b['app']??null)!=='android')throw new AaHttpError(400,'Unknown app');
  $ver=preg_match('/^[\w.\- ()]{1,24}$/',(string)($b['version']??''))?(string)$b['version']:'onbekend';
  aaDb()->prepare('INSERT IGNORE INTO downloads (day, app, version, visitor, at) VALUES (?,?,?,?,?)')->execute([$day,'android',$ver,$v,aaNow()]);
  return true;
}
function aaBhRows(string $sql, array $args=[]): array { $q=aaDb()->prepare($sql); $q->execute($args); return $q->fetchAll(); }
function aaBhCount(string $sql, array $args=[]): int { $q=aaDb()->prepare($sql); $q->execute($args); return (int)$q->fetchColumn(); }
function aaBhSeries(int $days, array $list, string $key='n'): array {
  $map=[]; foreach($list as $r)$map[$r['day']]=$r; $out=[];
  for($i=$days-1;$i>=0;$i--){ $d=aaDaysAgo($i); if(isset($map[$d])){$r=$map[$d];unset($r['day']);foreach($r as $k=>$v)$r[$k]=is_numeric($v)?(int)$v:$v;$out[]=['day'=>$d]+$r;} else $out[]=['day'=>$d,$key=>0]; }
  return $out;
}
function aaDownloadStats(): array {
  $t=aaBhRows("SELECT COUNT(*) AS n, SUM(CASE WHEN day >= ? THEN 1 ELSE 0 END) AS d30, SUM(CASE WHEN day = ? THEN 1 ELSE 0 END) AS vandaag FROM downloads WHERE app = 'android'",[aaDaysAgo(29),aaDayKey()])[0];
  $pd=aaBhRows("SELECT day, COUNT(*) AS n FROM downloads WHERE app = 'android' AND day >= ? GROUP BY day",[aaDaysAgo(29)]);
  $pv=aaBhRows("SELECT version, COUNT(*) AS n, MAX(day) AS laatst FROM downloads WHERE app = 'android' GROUP BY version ORDER BY laatst DESC, n DESC LIMIT 10");
  $dev=aaBhCount("SELECT COUNT(*) FROM devices WHERE enabled = 1 AND platform = 'android'");
  $i=aaBhRows("SELECT COUNT(*) AS n, SUM(CASE WHEN last_seen > ? THEN 1 ELSE 0 END) AS actief FROM installs WHERE platform = 'android'",[aaNow()-30*86400000])[0];
  return ['totaal'=>(int)($t['n']??0),'dagen30'=>(int)($t['d30']??0),'vandaag'=>(int)($t['vandaag']??0),'perDag'=>aaBhSeries(30,$pd),
    'perVersie'=>array_map(fn($r)=>['version'=>$r['version'],'n'=>(int)$r['n'],'laatst'=>$r['laatst']],$pv),'installaties'=>(int)($i['n']??0),'actief'=>(int)($i['actief']??0),'pushToestellen'=>$dev];
}

// ------------------------------------------------------------------ overview
function aaStatusInfo(): array {
  $ok=aaKvGet('feed_ok'); $cron=aaKvGet('cron_run');
  require_once __DIR__.'/status.php';
  return ['feed'=>['lastOk'=>$ok?aaIso((int)$ok):null,'lastError'=>aaKvGet('feed_error')],'cron'=>$cron?aaIso((int)$cron):null,
    'apns'=>aaApnsConfigured(),'fcm'=>aaFcmConfigured(),'homey'=>setting('HOMEY_WEBHOOK_ID')!=='','weatherkit'=>aaWeatherConfigured(),'mapkit'=>aaMapkitConfigured(),
    'mapsServer'=>aaMapsServerConfigured(),'radar'=>setting('KNMI_WMS_KEY')!=='','meldingenInDatabase'=>aaBhCount('SELECT COUNT(*) FROM meldingen'),
    'outbox'=>aaBhCount('SELECT COUNT(*) FROM outbox'),'beheerApparaten'=>aaBhCount('SELECT COUNT(*) FROM admin_devices')];
}
function aaOverview(array $status): array {
  $today=aaDayKey(); $yesterday=aaDaysAgo(1); $a30=aaNow()-30*86400000;
  $mr=aaBhRows('SELECT m.dienst, m.datum FROM meldingen m LEFT JOIN overrides o ON o.guid = m.guid WHERE COALESCE(o.hidden, 0) = 0 AND m.datum > ?',[aaNow()-(int)(2.2*86400000)]);
  $it=aaBhRows('SELECT COUNT(*) AS totaal, SUM(CASE WHEN last_seen > ? THEN 1 ELSE 0 END) AS actief, SUM(CASE WHEN last_seen > ? AND apple_login = 1 THEN 1 ELSE 0 END) AS apple,
    SUM(CASE WHEN last_seen > ? AND push = 1 THEN 1 ELSE 0 END) AS push FROM installs',[$a30,$a30,$a30])[0];
  $int=fn($rows)=>array_map(fn($r)=>array_map(fn($v)=>is_string($v)&&is_numeric($v)&&!str_contains($v,'.')&&strlen($v)<15?(int)$v:$v,$r),$rows);
  $platforms=$int(aaBhRows('SELECT platform, COUNT(*) AS n FROM installs WHERE last_seen > ? GROUP BY platform ORDER BY n DESC',[$a30]));
  $versions=$int(aaBhRows('SELECT app_version AS version, build, COUNT(*) AS n FROM installs WHERE last_seen > ? GROUP BY app_version, build ORDER BY n DESC LIMIT 8',[$a30]));
  foreach($versions as &$v){ $v['version']=$v['version']===null?null:(string)$v['version']; $v['build']=$v['build']===null?null:(string)$v['build']; } unset($v);
  $new=[]; foreach(aaBhRows('SELECT first_seen FROM installs WHERE first_seen > ?',[aaNow()-31*86400000]) as $r){ $d=gmdate('Y-m-d',intdiv((int)$r['first_seen'],1000)+3600); $new[$d]=($new[$d]??0)+1; }
  $newRows=[]; foreach($new as $d=>$n)$newRows[]=['day'=>$d,'n'=>$n];
  $act=aaBhRows('SELECT day, COUNT(*) AS n FROM install_days WHERE day >= ? GROUP BY day',[aaDaysAgo(29)]);
  $vis=aaBhRows('SELECT day, COUNT(*) AS bezoekers, SUM(pages) AS paginas FROM visits WHERE day >= ? GROUP BY day',[aaDaysAgo(29)]);
  $pages=aaBhRows('SELECT day, SUM(n) AS n FROM pageviews WHERE day >= ? GROUP BY day',[aaDaysAgo(29)]);
  $top=$int(aaBhRows('SELECT path, SUM(n) AS n FROM pageviews WHERE day >= ? GROUP BY path ORDER BY n DESC LIMIT 6',[aaDaysAgo(29)]));
  $devs=$int(aaBhRows('SELECT device, COUNT(*) AS n FROM visits WHERE day >= ? GROUP BY device',[aaDaysAgo(29)]));
  $views=aaBhRows(AA_SELECT.' WHERE m.views > 0 ORDER BY m.views DESC LIMIT 8');
  $push=aaBhCount('SELECT COUNT(*) FROM devices WHERE enabled = 1');
  $pd=[];$pdg=[];$vandaag=0;$gisteren=0;
  foreach($mr as $r){ $d=aaDayKey((int)$r['datum']); if($d===$today){$vandaag++;$pd[$r['dienst']]=($pd[$r['dienst']]??0)+1;} elseif($d===$yesterday){$gisteren++;$pdg[$r['dienst']]=($pdg[$r['dienst']]??0)+1;} }
  $visits=array_map(fn($r)=>['day'=>$r['day'],'bezoekers'=>(int)($r['bezoekers']??0),'paginas'=>(int)($r['paginas']??0)],aaBhSeries(30,$vis,'bezoekers'));
  $actief=(int)($it['actief']??0); $apple=(int)($it['apple']??0); $pa=(int)($it['push']??0);
  $sum=fn($l)=>array_sum(array_column($l,'bezoekers'));
  return ['generated'=>aaIso(aaNow()),'status'=>$status,'meldingen'=>['vandaag'=>$vandaag,'gisteren'=>$gisteren,'perDienst'=>(object)$pd,'perDienstGisteren'=>(object)$pdg],
    'app'=>['totaal'=>(int)($it['totaal']??0),'actief'=>$actief,'appleLogin'=>['ja'=>$apple,'nee'=>$actief-$apple],'push'=>['aan'=>$pa,'uit'=>$actief-$pa,'toestellen'=>$push],
      'platforms'=>$platforms,'versies'=>$versions,'nieuwPerDag'=>aaBhSeries(30,$newRows),'actiefPerDag'=>aaBhSeries(30,$act)],
    'website'=>['vandaag'=>end($visits)['bezoekers'],'gisteren'=>$visits[count($visits)-2]['bezoekers'],'bezoeken7'=>$sum(array_slice($visits,-7)),'bezoeken30'=>$sum($visits),
      'paginasVandaag'=>end($visits)['paginas'],'perDag'=>$visits,'paginas'=>aaBhSeries(30,$pages),'topPaginas'=>$top,'apparaten'=>$devs],
    'topBekeken'=>array_map(fn($r)=>['guid'=>$r['guid'],'title'=>$r['title'],'dienst'=>$r['dienst'],'views'=>(int)$r['views'],'datum_tijd'=>aaIso((int)$r['datum'])],$views)];
}

// ------------------------------------------------------------------ meldingen
function aaAdminMeldingen(array $q): array {
  $s=mb_strtolower(trim((string)($q['q']??''))); $lim=min((int)($q['limit']??0)?:200,500);
  $where='WHERE 1 = 1'; $args=[];
  if(($q['hidden']??'')==='1')$where.=' AND COALESCE(o.hidden, 0) = 1';
  if($s!==''){ $where.=" AND (LOWER(COALESCE(o.title, m.title)) LIKE ? OR LOWER(m.stad) LIKE ? OR LOWER(COALESCE(o.locatie, m.locatie, '')) LIKE ? OR m.capcode LIKE ? OR LOWER(COALESCE(o.categorie, m.categorie, '')) LIKE ?)"; $l="%$s%"; array_push($args,$l,$l,$l,$l,$l); }
  return array_map('aaMeldingAPI',aaBhRows(AA_SELECT." $where ORDER BY m.datum DESC LIMIT ".max(1,$lim),$args));
}
function aaUpdateMelding(string $guid, array $b): ?array {
  if(!aaBhCount('SELECT COUNT(*) FROM meldingen WHERE guid = ?',[$guid]))throw new AaHttpError(404,'Melding niet gevonden');
  if(!empty($b['reset']))aaDb()->prepare('DELETE FROM overrides WHERE guid = ?')->execute([$guid]);
  else{
    $cur=aaBhRows('SELECT * FROM overrides WHERE guid = ?',[$guid])[0]??[];
    $pick=fn($k,$fn)=>array_key_exists($k,$b)?$fn($b[$k]):($cur[$k]??null);
    $text=fn($n)=>fn($v)=>is_string($v)&&trim($v)!==''?mb_substr(trim($v),0,$n):null;
    $num=fn($min,$max)=>fn($v)=>(is_int($v)||is_float($v))&&$v>=$min&&$v<=$max?$v:null;
    aaDb()->prepare('REPLACE INTO overrides (guid, hidden, title, categorie, locatie, lat, lon, updated_at) VALUES (?,?,?,?,?,?,?,?)')
      ->execute([$guid,$pick('hidden',fn($v)=>$v?1:0)??0,$pick('title',$text(200)),$pick('categorie',$text(80)),$pick('locatie',$text(120)),$pick('lat',$num(50,54)),$pick('lon',$num(3,8)),aaNow()]);
  }
  return aaGetMelding($guid,true);
}

// ------------------------------------------------------------------ push from the beheer
function aaBroadcast(array $b): array {
  $title=mb_substr(trim((string)($b['title']??'')),0,120); $text=mb_substr(trim((string)($b['body']??'')),0,1000);
  if($title===''||$text==='')throw new AaHttpError(400,'Titel en tekst zijn verplicht');
  $namen=array_column(aaPlaatsen(),'naam'); $pl=array_values(array_filter(is_array($b['plaatsen']??null)?$b['plaatsen']:[],fn($p)=>in_array($p,$namen,true)));
  $keys=array_map('aaPlaatsKey',$pl);
  $dev=array_values(array_filter(aaActiveDevices(),function($d)use($keys){ if(!$keys||!$d['plaatsen'])return true; foreach($d['plaatsen'] as $p)if(in_array(aaPlaatsKey($p),$keys,true))return true; return false; }));
  $id='broadcast:'.aaNow();
  aaKvSet($id,aaJson(['title'=>$title,'body'=>$text,'guid'=>($b['guid']??null)?:null,'plaatsen'=>$pl,'ontvangers'=>count($dev)]));
  aaQueue($id,array_column($dev,'token'));
  return ['queued'=>count($dev)];
}
function aaBroadcastHistory(): array {
  return array_map(fn($r)=>['id'=>$r['key'],'at'=>aaIso((int)$r['at'])]+(json_decode((string)$r['value'],true)?:[]),
    aaBhRows("SELECT `key`, value, at FROM kv WHERE `key` LIKE 'broadcast:%' AND `key` NOT LIKE 'broadcast:heli:%' AND `key` NOT LIKE 'broadcast:hz:%' ORDER BY at DESC LIMIT 25"));
}
function aaRegisterAdminDevice(array $b): void {
  $t=strtolower((string)($b['token']??'')); if(!preg_match('/^[0-9a-f]{64,200}$/',$t))throw new AaHttpError(400,'Invalid token'); $now=aaNow();
  aaDb()->prepare('INSERT INTO admin_devices (token, environment, platform, created_at, updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE environment=VALUES(environment), platform=VALUES(platform), updated_at=VALUES(updated_at)')
    ->execute([$t,($b['environment']??null)==='sandbox'?'sandbox':'production',($b['platform']??null)==='macos'?'macos':'ios',$now,$now]);
}
function aaSetAdminPrefs(string $token, array $b): void {
  if(!is_bool($b['bronnen']??null))return;
  aaDb()->prepare('REPLACE INTO admin_prefs (token, bronnen, updated_at) VALUES (?, ?, ?)')->execute([$token,$b['bronnen']?1:0,aaNow()]);
}
function aaPushAdminsBeheer(string $title, string $body, ?string $kind=null): array {
  $list=$kind==='bronnen'?aaBhRows('SELECT a.* FROM admin_devices a LEFT JOIN admin_prefs p ON p.token = a.token WHERE COALESCE(p.bronnen, 1) = 1'):aaBhRows('SELECT * FROM admin_devices');
  $sent=0;
  foreach($list as $d){
    $r=aaSendPush($d,['aps'=>['alert'=>['title'=>$title,'body'=>$body],'sound'=>'default']],['topic'=>AA_BEHEER_TOPIC]);
    if(!$r['ok']&&($r['reason']??null)==='DeviceTokenNotForTopic')$r=aaSendPush($d,['aps'=>['alert'=>['title'=>$title,'body'=>$body],'sound'=>'default'],'lrenv'=>'p2000'],['topic'=>setting('LRBEHEER_TOPIC')?:AA_LRBEHEER_TOPIC]);
    if($r['ok'])$sent++; elseif(aaIsDeadToken($r))aaDb()->prepare('DELETE FROM admin_devices WHERE token = ?')->execute([$d['token']]);
  }
  return ['sent'=>$sent,'total'=>count($list)];
}
/** Beheer pushes for user feedback: only from the production server (PUSH_ACTIEF), else the beheer gets them twice. */
function aaPushAdminsAlsActief(string $title, string $body): void { if(aaPushActief()){ try{aaPushAdminsBeheer($title,$body);}catch(Throwable){} } }

// ------------------------------------------------------------------ texts
const AA_BEHEER_CONTENT=['privacy','cookies','terms','disclaimer','changelog','hulp','mededeling'];
function aaSaveContent(string $name, mixed $b): array {
  if(!in_array($name,AA_BEHEER_CONTENT,true))throw new AaHttpError(404,'Onbekende tekst');
  if($name==='changelog'){
    if(!is_array($b)||!array_is_list($b))throw new AaHttpError(400,'Changelog moet een lijst zijn');
    $v=array_map(fn($e)=>['id'=>(string)(($e['id']??null)?:(($e['version']??null)?:aaNow())),'title'=>mb_substr((string)($e['title']??''),0,120),'version'=>mb_substr((string)($e['version']??''),0,20),
      'release_date'=>($e['release_date']??null)?:aaIso(aaNow()),'content'=>mb_substr((string)($e['content']??''),0,20000)],array_slice($b,0,200));
  }else{
    if(!is_array($b)||!is_string($b['content']??null))throw new AaHttpError(400,'Tekst ontbreekt');
    $v=['title'=>mb_substr((string)($b['title']??''),0,120),'content'=>mb_substr($b['content'],0,100000),'version'=>substr(aaIso(aaNow()),0,10),'updated_at'=>aaIso(aaNow())];
  }
  aaKvSet('content:'.$name,aaJson($v)); return $v;
}

// ------------------------------------------------------------------ toestel-ID (toestel.js)
function aaToestelCode(mixed $id): ?string { $h=strtoupper(substr(preg_replace('/[^0-9a-f]/i','',(string)$id),0,8)); return strlen($h)===8?substr($h,0,4).'-'.substr($h,4):null; }
function aaToestelPrefix(string $code): string { $h=strtolower(preg_replace('/[^0-9a-f]/i','',$code)); if(strlen($h)<6||strlen($h)>8)throw new AaHttpError(400,'Toestel-ID is 8 tekens, bijvoorbeeld 3F9A-12BC'); return $h; }
function aaZoekToestel(string $code): array {
  $like=substr(aaToestelPrefix($code),0,8).'%'; $out=[];
  foreach(aaBhRows("SELECT id, platform, model, os_version, app_version, build, apple_login, push, language, first_seen, last_seen FROM installs WHERE REPLACE(id, '-', '') LIKE ? ORDER BY last_seen DESC LIMIT 5",[$like]) as $i){
    $dev=aaBhRows('SELECT d.token, d.platform, d.environment, d.language, d.app_version, d.enabled, d.quiet, d.radius_km, d.updated_at, p.data AS prefs FROM device_install di JOIN devices d ON d.token = di.token
      LEFT JOIN device_prefs p ON p.token = d.token WHERE di.install_id = ? ORDER BY d.updated_at DESC LIMIT 3',[$i['id']]);
    $out[]=['code'=>aaToestelCode($i['id']),'platform'=>$i['platform'],'model'=>$i['model'],'os'=>$i['os_version'],'app_version'=>$i['app_version'],'build'=>$i['build'],
      'apple_login'=>(bool)$i['apple_login'],'push_aan'=>(bool)$i['push'],'taal'=>$i['language'],'eerst'=>aaIso((int)$i['first_seen']),'laatst'=>aaIso((int)$i['last_seen']),
      'push'=>array_map(function($d){ $p=aaParse($d['prefs'],[])?:[]; return ['id'=>substr($d['token'],0,10),'platform'=>$d['platform'],'omgeving'=>$d['environment'],'app_version'=>$d['app_version'],
        'aan'=>(bool)$d['enabled'],'stille_uren'=>(bool)$d['quiet'],'straal_km'=>$d['radius_km']===null?null:aaNum($d['radius_km']),'bijgewerkt'=>$d['updated_at']?aaIso((int)$d['updated_at']):null,
        'profielen'=>count($p['profielen']??[]),'heli_zones'=>count($p['heli']['zones']??[]),'groot_incident'=>!empty($p['groot']['aan']),'capcodes'=>count($p['capcodes']??[])]; },$dev)];
  }
  return ['gevonden'=>count($out),'toestellen'=>$out];
}
function aaTestToestel(string $code): array {
  $rows=aaBhRows("SELECT d.* FROM installs i JOIN device_install di ON di.install_id = i.id JOIN devices d ON d.token = di.token WHERE REPLACE(i.id, '-', '') LIKE ? LIMIT 5",[substr(aaToestelPrefix($code),0,8).'%']);
  if(!$rows)throw new AaHttpError(404,'Geen pushregistratie bij dit toestel-ID');
  $out=[];
  foreach($rows as $d){ $nl=str_starts_with((string)(($d['language']??'')?:'nl'),'nl');
    $r=aaSendPush($d,['aps'=>['alert'=>['title'=>'A-Alerts','body'=>$nl?'Testmelding van de beheerder: pushmeldingen werken.':'Test notification from the admin: notifications work.'],'sound'=>'default']],['urgent'=>false]);
    $out[]=['platform'=>$d['platform'],'ok'=>(bool)$r['ok'],'status'=>$r['status']??null,'reason'=>$r['reason']??null]; }
  $all=true; foreach($out as $x)if(!$x['ok'])$all=false;
  return ['ok'=>$all,'resultaten'=>$out];
}

// ------------------------------------------------------------------ widgets (widget.js)
function aaSiteVan(mixed $s): ?string {
  $h=mb_strtolower(trim((string)($s??''))); if($h==='')return null;
  if(preg_match('#^https?://#',$h)){ $u=parse_url($h,PHP_URL_HOST); if(!is_string($u))return null; $h=$u; }
  $h=preg_replace('/:\d+$/','',preg_replace('/^www\./','',$h));
  return preg_match('/^[a-z0-9.-]{3,120}$/',$h)&&str_contains($h,'.')?$h:null;
}
function aaWidgetPing(array $q): array {
  $site=aaSiteVan($q['site']??null);
  if(!$site||preg_match('/(^|\.)a-alerts\.nl$|(^|\.)lrvdlinden\.app$|^localhost$|^127\.0\.0\.1$/',$site))return ['blok'=>false];
  $blok=aaBhCount('SELECT COUNT(*) FROM widget_blok WHERE site = ?',[$site])>0; $now=aaNow();
  $w=[]; foreach(explode(',',(string)($q['w']??'')) as $x){$x=trim($x);if(in_array($x,['kaart','verkeer','weer'],true)&&!in_array($x,$w,true))$w[]=$x;}
  aaDb()->prepare('INSERT INTO widget_sites (site, widgets, eerst, laatst) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE widgets=VALUES(widgets), laatst=VALUES(laatst)')->execute([$site,implode(',',$w)?:'kaart,verkeer',$now,$now]);
  aaDb()->prepare('INSERT INTO widget_views (site, dag, n) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$site,gmdate('Y-m-d',intdiv($now,1000))]);
  return ['blok'=>$blok];
}
function aaWidgetSites(): array {
  $van=gmdate('Y-m-d',intdiv(aaNow()-6*86400000,1000));
  return array_map(fn($r)=>['site'=>$r['site'],'widgets'=>$r['widgets'],'eerst'=>aaIso((int)$r['eerst']),'laatst'=>aaIso((int)$r['laatst']),'views7'=>(int)$r['views7'],'totaal'=>(int)$r['totaal'],
    'blok'=>(bool)$r['blok'],'actief'=>aaNow()-(int)$r['laatst']<3*86400000],
    aaBhRows('SELECT s.site, s.widgets, s.eerst, s.laatst, COALESCE((SELECT SUM(n) FROM widget_views v WHERE v.site = s.site AND v.dag >= ?), 0) AS views7,
      COALESCE((SELECT SUM(n) FROM widget_views v WHERE v.site = s.site), 0) AS totaal, EXISTS (SELECT 1 FROM widget_blok b WHERE b.site = s.site) AS blok
      FROM widget_sites s ORDER BY s.laatst DESC LIMIT 200',[$van]));
}
function aaWidgetBlok(array $b): bool {
  $site=aaSiteVan($b['site']??null); if(!$site)return false;
  if(!empty($b['blok']))aaDb()->prepare('REPLACE INTO widget_blok (site, at) VALUES (?, ?)')->execute([$site,aaNow()]);
  else aaDb()->prepare('DELETE FROM widget_blok WHERE site = ?')->execute([$site]);
  return true;
}

// ------------------------------------------------------------------ feedback (extra.js)
function aaSaveFeedback(string $guid, array $b): array {
  $reden=in_array($b['reden']??null,['locatie','dienst','prioriteit','dubbel','test','anders'],true)?$b['reden']:null;
  if(!$reden)throw new AaHttpError(400,'Kies een reden');
  $toel=is_string($b['toelichting']??null)?mb_substr(trim($b['toelichting']),0,500):'';
  if(!str_starts_with($guid,'toestel:')){ if(!aaBhCount('SELECT COUNT(*) FROM meldingen WHERE guid = ?',[$guid]))throw new AaHttpError(404,'Melding niet gevonden'); }
  elseif($toel==='')throw new AaHttpError(400,'Schrijf wat er niet klopt');
  $hash=substr(hash('sha256','fb:'.aaClientIp()),0,16); $now=aaNow();
  if(aaBhCount('SELECT COUNT(*) FROM melding_feedback WHERE bron = ? AND at > ?',[$hash,$now-3600000])>=10)throw new AaHttpError(429,'Te veel meldingen, probeer het later opnieuw');
  $lat=aaPushNum($b['lat']??null,50,54); $lon=aaPushNum($b['lon']??null,2,8);
  aaDb()->prepare("INSERT INTO melding_feedback (guid, reden, toelichting, lat, lon, platform, bron, at, status) VALUES (?,?,?,?,?,?,?,?,'open')")
    ->execute([$guid,$reden,$toel?:null,$lat,$lat===null?null:$lon,(is_string($b['platform']??null)?mb_substr(trim($b['platform']),0,20):'')?:null,$hash,$now]);
  return ['ok'=>true,'id'=>(int)aaDb()->lastInsertId()];
}
function aaFeedbackLijst(array $q): array {
  $st=$q['status']??null; $w=$st==='open'||$st==='afgehandeld'?'WHERE f.status = ?':'';
  $rows=aaBhRows("SELECT f.id, f.guid, f.reden, f.toelichting, f.lat, f.lon, f.platform, f.at, f.status, COALESCE(m.title, CASE WHEN f.guid LIKE 'toestel:%' THEN CONCAT('Toestel op de kaart: ', SUBSTRING(f.guid, 9)) END) AS title, m.stad, m.dienst, m.datum
    FROM melding_feedback f LEFT JOIN meldingen m ON m.guid = f.guid $w ORDER BY f.at DESC LIMIT 200",$w?[$st]:[]);
  return ['open'=>aaBhCount("SELECT COUNT(*) FROM melding_feedback WHERE status = 'open'"),
    'items'=>array_map(fn($r)=>['id'=>(int)$r['id'],'lat'=>aaNum($r['lat']),'lon'=>aaNum($r['lon']),'at'=>aaIso((int)$r['at']),'datum'=>$r['datum']?aaIso((int)$r['datum']):null]+$r,$rows)];
}

// ------------------------------------------------------------------ warnings in the beheer (admin-alerts.js)
function aaApplyOverride(array $r): array { if(!empty($r['ov_ended'])&&$r['status']!=='beeindigd'){ $r['status']='beeindigd'; $r['end_at']=$r['end_at']&&(int)$r['end_at']<(int)$r['ov_ended']?$r['end_at']:$r['ov_ended']; } return $r; }
function aaAdminWarnings(array $q): array {
  $bron=$q['bron']??null; $st=$q['status']??null; $s=mb_strtolower(trim((string)($q['q']??''))); $days=min((int)($q['days']??0)?:14,60);
  $list=aaBhRows("SELECT w.*, COALESCE(wo.hidden, 0) AS ov_hidden, wo.ended_at AS ov_ended, wo.updated_at AS ov_at, CASE WHEN w.bron = 'vnog' THEN wk.data END AS knmi_data
    FROM waarschuwingen w LEFT JOIN waarschuwing_overrides wo ON wo.id = w.id LEFT JOIN waarschuwing_knmi wk ON wk.id = w.id
    WHERE (COALESCE(w.end_at, w.start_at) >= ? OR w.status != 'beeindigd') ORDER BY w.start_at DESC, w.volgorde DESC LIMIT 300",[aaNow()-$days*86400000]);
  $out=[];
  foreach(array_map('aaApplyOverride',$list) as $r){
    if($bron&&$r['bron']!==$bron||$st&&$r['status']!==$st)continue;
    if($s!==''){ $hit=false; foreach([$r['titel'],$r['tekst'],$r['plaats'],$r['soort']] as $v)if(str_contains(mb_strtolower((string)$v),$s))$hit=true; if(!$hit)continue; }
    $out[]=aaWarningAPI($r,false)+['verborgen'=>(bool)(int)$r['ov_hidden'],'handmatig_beeindigd'=>(bool)$r['ov_ended']];
  }
  return $out;
}
function aaVnogPlek(string $id, array $b): void {
  if(!array_key_exists('melding',$b)&&!array_key_exists('lat',$b)&&!array_key_exists('plek',$b))return;
  $q=aaDb()->prepare('SELECT data FROM waarschuwing_knmi WHERE id = ?'); $q->execute([$id]); $extra=aaParse($q->fetchColumn()?:null,null)?:[];
  if(($b['plek']??null)==='auto'){$extra['plek']=null;$extra['incident']=null;$lat=null;$lon=null;}
  elseif(($b['plek']??null)==='geen'){$extra['plek']='beheer';$extra['incident']=null;$lat=null;$lon=null;}
  elseif(!empty($b['melding'])){ $m=aaBhRows('SELECT m.guid, m.incident, COALESCE(o.lat, m.lat) AS lat, COALESCE(o.lon, m.lon) AS lon FROM meldingen m LEFT JOIN overrides o ON o.guid = m.guid WHERE m.guid = ?',[(string)$b['melding']])[0]??null;
    if(!$m)return; $extra['plek']='beheer'; $extra['incident']=$m['incident']?:$m['guid']; $lat=$m['lat']; $lon=$m['lon']; }
  else{ $lat=(float)($b['lat']??0); $lon=(float)($b['lon']??0); if(!($lat>50&&$lat<54&&$lon>3&&$lon<8))return; $extra['plek']='beheer'; }
  aaDb()->prepare('UPDATE waarschuwingen SET lat = ?, lon = ?, updated_at = ? WHERE id = ?')->execute([$lat,$lon,aaNow(),$id]);
  aaDb()->prepare('INSERT INTO waarschuwing_knmi (id, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')->execute([$id,aaJson($extra)]);
}
function aaUpdateWarning(string $id, array $b): ?array {
  $row=aaBhRows('SELECT id, bron FROM waarschuwingen WHERE id = ?',[$id])[0]??null; if(!$row)return null;
  if($row['bron']==='vnog')aaVnogPlek($id,$b);
  $cur=aaBhRows('SELECT hidden, ended_at FROM waarschuwing_overrides WHERE id = ?',[$id])[0]??null;
  $hidden=is_bool($b['verborgen']??null)?($b['verborgen']?1:0):(int)($cur['hidden']??0);
  $ended=is_bool($b['beeindigd']??null)?($b['beeindigd']?(($cur['ended_at']??null)?:aaNow()):null):($cur['ended_at']??null);
  if(!$hidden&&!$ended)aaDb()->prepare('DELETE FROM waarschuwing_overrides WHERE id = ?')->execute([$id]);
  else aaDb()->prepare('REPLACE INTO waarschuwing_overrides (id, hidden, ended_at, updated_at) VALUES (?,?,?,?)')->execute([$id,$hidden,$ended,aaNow()]);
  foreach(aaAdminWarnings(['days'=>60]) as $w)if($w['id']===$id)return $w;
  return null;
}
function aaSetBron(string $bron, mixed $aan): ?array { if($bron!=='vnog'||!is_bool($aan))return null; aaKvSet('vnog_uit',$aan?'0':'1'); return ['bron'=>$bron,'aan'=>$aan]; }
function aaAlertsOverview(): array {
  $now=aaNow(); $last=aaParse(aaKvGet('warnings_status'),null)?:[]; $namen=['nlalert'=>'NL-Alert','burgernet'=>'Burgernet','knmi'=>'KNMI','kind'=>'AMBER Alert / Vermist Kind','verkeer'=>'Verkeer (NDW, Koyeb)','vnog'=>'VNOG'];
  $br=[];
  foreach(['nlalert','burgernet','knmi','kind','vnog','verkeer'] as $b){ $ok=aaKvGet("bron_ok:$b"); $f=aaKvGet("bron_fout:$b"); $lo=$ok!==null?(int)$ok:null;
    $br[$b]=['naam'=>$namen[$b],'laatsteStatus'=>($last[$b]??null)?:null]+($b==='vnog'?['schakelbaar'=>true,'aan'=>aaKvGet('vnog_uit')!=='1']:[])
      +['laatstOk'=>$lo?aaIso($lo):null,'laatsteFout'=>$f,'storing'=>!$lo||$now-$lo>30*60000]; }
  $heli=['helikopters'=>[],'fout'=>true];
  if(is_file(__DIR__.'/lucht.php')){ try{ require_once __DIR__.'/lucht.php'; $heli=aaHelikopters(); }catch(Throwable){} }
  $br['helikopters']=['naam'=>'adsb.lol','laatsteStatus'=>!empty($heli['fout'])?'fout':(!empty($heli['verouderd'])?'verouderd':'ok '.count($heli['helikopters'])),
    'laatstOk'=>!empty($heli['fout'])?null:($heli['bijgewerkt']??null),'laatsteFout'=>!empty($heli['fout'])?'geen antwoord':null,'storing'=>!empty($heli['fout'])];
  $ws=array_map('aaApplyOverride',aaBhRows("SELECT w.bron, w.status, w.niveau, w.start_at, w.end_at, COALESCE(wo.hidden, 0) AS ov_hidden, wo.ended_at AS ov_ended FROM waarschuwingen w
    LEFT JOIN waarschuwing_overrides wo ON wo.id = w.id WHERE COALESCE(w.end_at, w.start_at) >= ? OR w.status != 'beeindigd'",[$now-7*86400000]));
  $per=[]; foreach(['nlalert','burgernet','knmi','amber','vermistkind','vnog'] as $b)$per[$b]=['actief'=>0,'week'=>0]; $verborgen=0;
  foreach($ws as $w){ if((int)$w['ov_hidden']){$verborgen++;continue;} if(!isset($per[$w['bron']]))continue; $per[$w['bron']]['week']++; if($w['status']==='actief')$per[$w['bron']]['actief']++; }
  $verd=['1'=>0,'2'=>0,'3'=>0,'4'=>0,'5'=>0]; $plaatsen=[]; $wil=['nlalert'=>0,'burgernet'=>0,'amber'=>0,'vermistkind'=>0,'vnog'=>0,'knmi'=>['yellow'=>0,'orange'=>0,'red'=>0]];
  $met=0;$tot=0;$tijden=0;$uit=0;
  foreach(aaBhRows('SELECT p.data FROM device_prefs p JOIN devices d ON d.token = p.token WHERE d.enabled = 1') as $r){
    $p=aaParse($r['data'],null); if(!empty($p['amber']))$wil['amber']++; if(!empty($p['vermistkind']))$wil['vermistkind']++; if(!empty($p['vnog']))$wil['vnog']++;
    $l=is_array($p['profielen']??null)?$p['profielen']:null; if(!$l)continue;
    $met++; $tot+=count($l); $verd[(string)min(5,count($l))]++;
    $act=array_values(array_filter($l,fn($x)=>($x['actief']??null)!==false)); if(!$act)$uit++;
    $any=fn($f)=>(bool)array_filter($act,$f);
    if($any(fn($x)=>($x['altijd']??null)===false))$tijden++; if($any(fn($x)=>!empty($x['nlalert'])))$wil['nlalert']++; if($any(fn($x)=>!empty($x['burgernet'])))$wil['burgernet']++;
    foreach(['yellow','orange','red'] as $lv)if($any(fn($x)=>in_array($lv,$x['knmi']??[],true)))$wil['knmi'][$lv]++;
    $own=[]; foreach($act as $x)foreach($x['plaatsen']??[] as $pl)$own[$pl]=true; foreach(array_keys($own) as $pl)$plaatsen[$pl]=($plaatsen[$pl]??0)+1;
  }
  $push=aaBhCount('SELECT COUNT(*) FROM devices WHERE enabled = 1');
  arsort($plaatsen); $top=[]; foreach(array_slice($plaatsen,0,10,true) as $pl=>$n)$top[]=['plaats'=>(string)$pl,'n'=>$n];
  return ['bronnen'=>$br,'inDeLucht'=>array_map(fn($h)=>['naam'=>$h['naam'],'soort'=>$h['soort'],'hoogte_m'=>$h['op_grond']?0:($h['hoogte_ft']===null?null:(int)round($h['hoogte_ft']*0.3048)),
      'snelheid_kmh'=>$h['snelheid_kt']===null?null:(int)round($h['snelheid_kt']*1.852),'gezien'=>$h['gezien']],$heli['helikopters']??[]),
    'waarschuwingen'=>['perBron'=>$per,'verborgen'=>$verborgen],
    'profielen'=>['pushToestellen'=>$push,'metProfielen'=>$met,'zonderProfielen'=>max(0,$push-$met),'gemiddeld'=>$met?round($tot/$met*10)/10:0,'verdeling'=>$verd,'metTijden'=>$tijden,
      'allesUit'=>$uit,'waarschuwingen'=>$wil,'topPlaatsen'=>$top]];
}

// ------------------------------------------------------------------ news in the beheer (nieuws.js)
function aaLeesFoto(mixed $dataUrl): array {
  if(!preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=\s]+)$#',(string)($dataUrl??''),$m))throw new AaHttpError(400,'Foto moet een JPEG, PNG of WebP zijn');
  $bin=base64_decode(preg_replace('/\s+/','',$m[2]),true); if($bin===false)throw new AaHttpError(400,'Foto moet een JPEG, PNG of WebP zijn');
  if(strlen($bin)>1800000)throw new AaHttpError(413,'Foto is te groot (max. 1,8 MB)');
  return ['mime'=>$m[1],'bytes'=>$bin];
}
function aaBewaarFoto(mixed $dataUrl): string {
  $f=aaLeesFoto($dataUrl); $id=bin2hex(random_bytes(16));
  aaDb()->prepare('INSERT INTO nieuws_fotos (id, mime, data, at) VALUES (?, ?, ?, ?)')->execute([$id,$f['mime'],$f['bytes'],aaNow()]); return $id;
}
function aaIsKindAfgerond(?array $r): bool { return $r&&in_array($r['bron'],['amber','vermistkind'],true)&&$r['status']==='afgerond'; }
function aaWijzigNieuws(string $id, array $b): bool {
  $row=aaBhRows('SELECT * FROM nieuws WHERE id = ?',[$id])[0]??null; if(!$row)return false;
  if(array_key_exists('hidden',$b))aaDb()->prepare('UPDATE nieuws SET hidden = ? WHERE id = ?')->execute([!empty($b['hidden'])?1:0,$id]);
  if(array_key_exists('liveblog',$b)){
    if(!empty($b['liveblog']))aaDb()->prepare('INSERT IGNORE INTO nieuws_liveblog (id, at) VALUES (?, ?)')->execute([$id,aaNow()]); else aaDb()->prepare('DELETE FROM nieuws_liveblog WHERE id = ?')->execute([$id]);
    aaDb()->prepare('UPDATE nieuws SET updated_at = ? WHERE id = ?')->execute([aaNow(),$id]);
  }
  if(array_key_exists('foto',$b)){
    if(!empty($b['foto'])&&aaIsKindAfgerond($row))throw new AaHttpError(400,'Na afloop van een kind-alert kan er geen foto meer bij');
    $foto=!empty($b['foto'])?aaBewaarFoto($b['foto']):null;
    aaDb()->prepare('UPDATE nieuws SET foto = ? WHERE id = ?')->execute([$foto,$id]);
    if($row['foto'])aaDb()->prepare('DELETE FROM nieuws_fotos WHERE id = ?')->execute([$row['foto']]);
  }
  return true;
}
function aaHttpsUrl(mixed $u): string { $u=trim((string)($u??'')); if($u!==''&&!preg_match('#^https://\S+$#i',$u))throw new AaHttpError(400,'Link moet met https:// beginnen'); return $u; }
function aaVoegToe(string $id, array $b): ?array {
  $row=aaBhRows('SELECT * FROM nieuws WHERE id = ?',[$id])[0]??null; if(!$row)return null;
  $tekst=mb_substr(trim((string)($b['tekst']??'')),0,4000); $titel=mb_substr(trim((string)($b['titel']??'')),0,160)?:null; $url=aaHttpsUrl($b['url']??'');
  if($tekst===''&&empty($b['foto'])&&$url==='')throw new AaHttpError(400,'Vul een tekst, foto of link in');
  if(!empty($b['foto'])&&aaIsKindAfgerond($row))throw new AaHttpError(400,'Na afloop van een kind-alert kan er geen foto meer bij');
  $foto=!empty($b['foto'])?aaBewaarFoto($b['foto']):null;
  $at=!empty($b['at'])&&aaMs($b['at'])!==null?min(aaMs($b['at']),aaNow()):aaNow();
  aaDb()->prepare('INSERT INTO nieuws_updates (nieuws_id, titel, tekst, url, foto, at, created_at) VALUES (?,?,?,?,?,?,?)')->execute([$id,$titel,$tekst,$url?:null,$foto,$at,aaNow()]);
  return ['id'=>(int)aaDb()->lastInsertId(),'nieuws'=>$row];
}
function aaWijzigUpdate(string $id, int $uid, array $b): bool {
  $u=aaBhRows('SELECT u.*, n.bron, n.status FROM nieuws_updates u JOIN nieuws n ON n.id = u.nieuws_id WHERE u.id = ? AND u.nieuws_id = ?',[$uid,$id])[0]??null; if(!$u)return false;
  $sets=[];$args=[];
  if(array_key_exists('tekst',$b)){$sets[]='tekst = ?';$args[]=mb_substr(trim((string)($b['tekst']??'')),0,4000);}
  if(array_key_exists('titel',$b)){$sets[]='titel = ?';$args[]=mb_substr(trim((string)($b['titel']??'')),0,160)?:null;}
  if(array_key_exists('url',$b)){$sets[]='url = ?';$args[]=aaHttpsUrl($b['url'])?:null;}
  $oud=null;
  if(array_key_exists('foto',$b)){ if(!empty($b['foto'])&&aaIsKindAfgerond($u))throw new AaHttpError(400,'Na afloop van een kind-alert kan er geen foto meer bij');
    $sets[]='foto = ?';$args[]=!empty($b['foto'])?aaBewaarFoto($b['foto']):null; $oud=$u['foto']; }
  if($sets){ $args[]=$uid; aaDb()->prepare('UPDATE nieuws_updates SET '.implode(', ',$sets).' WHERE id = ?')->execute($args); if($oud)aaDb()->prepare('DELETE FROM nieuws_fotos WHERE id = ?')->execute([$oud]); }
  return true;
}
function aaVerwijderUpdate(string $id, int $uid): bool {
  $u=aaBhRows('SELECT foto FROM nieuws_updates WHERE id = ? AND nieuws_id = ?',[$uid,$id])[0]??null; if(!$u)return false;
  aaDb()->prepare('DELETE FROM nieuws_updates WHERE id = ?')->execute([$uid]); if($u['foto'])aaDb()->prepare('DELETE FROM nieuws_fotos WHERE id = ?')->execute([$u['foto']]);
  return true;
}
function aaStockLijst(string $origin): array {
  $s=aaStockFotos();
  return array_map(fn($c)=>['categorie'=>$c,'eigen'=>isset($s[$c]),'afbeelding'=>isset($s[$c])?"$origin/v1/nieuws/foto/".$s[$c]:AA_IMG."politie-$c.webp".AA_IMG_V],AA_STOCK_CATEGORIEEN);
}
function aaZetStock(array $b): void {
  foreach(AA_STOCK_CATEGORIEEN as $c){ if(!array_key_exists($c,$b))continue; $oud=aaKvGet("nieuws_stock:$c");
    if(!empty($b[$c]))aaKvSet("nieuws_stock:$c",aaBewaarFoto($b[$c])); else aaDb()->prepare('DELETE FROM kv WHERE `key` = ?')->execute(["nieuws_stock:$c"]);
    if($oud)aaDb()->prepare('DELETE FROM nieuws_fotos WHERE id = ?')->execute([$oud]); }
}
/** Recipients of a beheer message on a news item (nieuws.js nieuwsOntvangers); queued, sent only when PUSH_ACTIEF. */
function aaPushNieuws(string $id, string $kind, int $updateId=0): int {
  $row=aaBhRows('SELECT id, soort, bron, ref, titel, plaats, data FROM nieuws WHERE id = ? AND hidden = 0',[$id])[0]??null; if(!$row)return 0;
  $data=aaParse($row['data'],[])?:[]; $pl=($data['plaatsen']??null)?:null;
  $warning=$row['soort']==='politie'||$row['soort']==='regionaal'?null:aaGetWarning((string)$row['ref']);
  $prefs=aaDevicePrefs(); $tok=[];
  foreach(aaActiveDevices() as $d){ if(empty($d['enabled']))continue; $p=$prefs[$d['token']]??null;
    $n=['liveblog'=>($p['nieuwsLiveblog']??null)===true,'politie'=>($p['nieuwsPolitie']??null)===true,'regio'=>($p['nieuwsRegio']??null)===true];
    if($row['soort']==='politie'||$row['soort']==='regionaal'){ if($row['soort']==='politie'?!$n['politie']:!$n['regio'])continue;
      $ch=$d['plaatsen']; $ok=!$ch; foreach($ch as $c)foreach($pl?:[$row['plaats']] as $x)if(mb_strtolower((string)$x)===mb_strtolower((string)$c))$ok=true; if($ok)$tok[]=$d['token']; continue; }
    if(!$n['liveblog'])continue;
    if(!$warning||aaWarningWanted($d,$p,['status'=>'actief']+$warning))$tok[]=$d['token']; }
  if($tok)aaQueue("n:$kind:$id:$updateId",$tok);
  return count($tok);
}
