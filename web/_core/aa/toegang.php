<?php
declare(strict_types=1);
/**
 * Access gate for the data routes of api.a-alerts.nl: nobody may crawl the data.
 *
 * Header  X-AAlerts-Token: <soort>.<unix-seconden>.<hmac>   (hmac = HMAC-SHA256 over "<soort>.<seconden>", key = AALERTS_KEY)
 *   soort a = app/Homey (computes it itself, valid 10 minutes)
 *   soort w = website (issued by a-alerts.nl/token.php, valid 2 hours; the key never reaches the browser)
 * Settings (aalerts-private):
 *   AALERTS_KEY       the secret. Not set = gate completely off (nothing changes).
 *   TOKEN_VERPLICHT   "1" = no valid token means 401. Otherwise grace for installed apps without a token:
 *                     anonymous gets at most 30 items, no paging or filters and a tight rate limit.
 * Always rate limited per IP: with token 600 calls/minute, without 20.
 */
require_once __DIR__.'/base.php';
const AA_TOKEN_HEADER='x-aalerts-token';

/** Prefixes that need a token (GET only). Everything else (health, app, changelog, content, status, images, tiles, beheer, POST) stays as it was. */
function aaToegangBeschermd(string $path): bool {
  if(preg_match('#^/v1/meldingen/[\w-]{8,64}/afbeelding\.png$#',$path)||str_starts_with($path,'/v1/nieuws/foto/'))return false;
  return (bool)preg_match('#^/v1/(meldingen|waarschuwingen|nieuws|helikopters|lucht|verkeer|adsb|weer|statistiek|afkortingen|posten|locaties|veiligheidsregio)(/|$)#',$path);
}
function aaTokenMaak(string $soort, ?int $sec=null): string {
  $p=$soort.'.'.($sec??time()); return $p.'.'.hash_hmac('sha256',$p,setting('AALERTS_KEY'));
}
function aaTokenGeldig(string $t): bool {
  $sleutel=setting('AALERTS_KEY'); if($sleutel==='')return true;
  if(!preg_match('/^([aw])\.(\d{9,11})\.([0-9a-f]{64})$/',$t,$m))return false;
  if(!hash_equals(hash_hmac('sha256',$m[1].'.'.$m[2],$sleutel),$m[3]))return false;
  $ver=time()-(int)$m[2]; $max=$m[1]==='w'?7200:600;
  return $ver>=-$max&&$ver<=$max;
}
function aaToegangIp(): string {
  $ip=(string)($_SERVER['HTTP_CF_CONNECTING_IP']??'');
  // Old apps still come in through the Worker proxy: then the real address is in X-Forwarded-For.
  if($ip===''&&!empty($_SERVER['HTTP_X_AALERTS_VIA_WORKER']))$ip=trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_FOR']??''))[0]);
  return $ip!==''?$ip:(string)($_SERVER['REMOTE_ADDR']??'onbekend');
}
function aaToegangTelt(string $sleutel, int $max): bool {
  try{
    $minuut=intdiv(time(),60); $k='rl:'.substr(hash('sha256',$sleutel),0,16).':'.$minuut;
    $db=aaDb();
    $db->prepare('INSERT INTO kv (`key`, value, at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE value = value + 1')->execute([$k,aaNow()]);
    $q=$db->prepare('SELECT value FROM kv WHERE `key` = ?'); $q->execute([$k]); $n=(int)$q->fetchColumn();
    if(random_int(1,200)===1)$db->prepare("DELETE FROM kv WHERE `key` LIKE 'rl:%' AND at < ?")->execute([aaNow()-600000]);
    return $n<=$max;
  }catch(Throwable $e){ return true; }
}
function aaToegangAfwijzen(int $status, string $tekst, int $wacht=0): never {
  http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Access-Control-Allow-Origin: *'); header('X-AAlerts-Bron: eigen');
  header('Cache-Control: no-store'); if($wacht)header('Retry-After: '.$wacht);
  echo json_encode(['error'=>$tekst],JSON_UNESCAPED_UNICODE); exit;
}
/** Called before every route. Returns normally when the request may go on (anonymous in grace mode: $_GET is trimmed). */
function aaToegang(string $path): void {
  $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
  if($method!=='GET'||setting('AALERTS_KEY')===''||!aaToegangBeschermd($path))return;
  $ip=aaToegangIp();
  $token=(string)($_SERVER['HTTP_X_AALERTS_TOKEN']??'');
  $geldig=$token!==''&&aaTokenGeldig($token);
  if(!$geldig){
    if(setting('TOKEN_VERPLICHT')==='1'){ aaToegangTelt('geen:'.$ip,20)||aaToegangAfwijzen(429,'Te veel verzoeken',60); aaToegangAfwijzen(401,'Token vereist'); }
    if(!aaToegangTelt('anon:'.$ip,20))aaToegangAfwijzen(429,'Te veel verzoeken',60);
    // Grace for apps from before the token: a small piece of the latest data, nothing to page through.
    $_GET['limit']=min((int)($_GET['limit']??30)?:30,30);
    unset($_GET['before'],$_GET['plaats'],$_GET['eenheid']);
    return;
  }
  if(!aaToegangTelt('tok:'.$ip,600))aaToegangAfwijzen(429,'Te veel verzoeken',30);
}
// deploy
