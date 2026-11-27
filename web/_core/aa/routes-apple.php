<?php
declare(strict_types=1);
/**
 * Routes of the A-Alerts engine for Apple services, devices, statistics, status and the beheer (index.js handle and
 * handleBeheer). Same paths, methods, status codes and JSON as the Worker. Called from routes.php before the others.
 */
require_once __DIR__.'/beheer.php';
require_once __DIR__.'/status.php';

function aaJsonBody(int $max=32768): array {
  $t=(string)file_get_contents('php://input',false,null,0,$max+1);
  if(strlen($t)>$max)throw new AaHttpError(413,'Body too large');
  if($t==='')return [];
  $d=json_decode($t,true); if(!is_array($d)&&$d!==null||json_last_error()!==JSON_ERROR_NONE)throw new AaHttpError(400,'Invalid JSON');
  return is_array($d)?$d:[];
}
function aaNoteWeer(bool $ok): void { aaKvSet($ok?'status_weer_ok':'status_weer_fout',(string)aaNow()); }

function aaRouteApple(string $path): void {
  $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
  $mine=$path==='/v1/mapkit-token'||$path==='/v1/status'||$path==='/v1/installs'||$path==='/v1/downloads'||$path==='/v1/visit'||$path==='/v1/widget/ping'
    ||str_starts_with($path,'/v1/devices')||str_starts_with($path,'/v1/beheer/')
    ||preg_match('#^/v1/meldingen/[\w-]{8,64}/(routes|pand|weather|feedback)$#',$path)||preg_match('#^/v1/waarschuwingen/[\w:.\-%+]{4,160}/weather$#',$path);
  if(!$mine)return;
  // Kept apart by their own handlers in index.php (one-time key transfer, schema install).
  if($path==='/v1/beheer/sleutels-overdracht'||$path==='/v1/beheer/install')return;
  if($method==='OPTIONS')aaReply(204,null);
  $origin='https://'.preg_replace('/[^a-z0-9.:-]/i','',(string)($_SERVER['HTTP_HOST']??'api.a-alerts.nl'));
  try{
    // ---------------------------------------------------------------- public
    if($method==='GET'&&$path==='/v1/mapkit-token'){
      $t=aaMapkitToken((string)($_SERVER['HTTP_ORIGIN']??''));
      if(!$t)aaReply(aaMapkitConfigured()?403:503,['error'=>'MapKit JS unavailable']);
      http_response_code(200); header('Content-Type: text/plain'); header('Cache-Control: no-store'); header('Access-Control-Allow-Origin: *');
      header('Access-Control-Allow-Headers: content-type, authorization, x-aalerts-token'); header('Access-Control-Max-Age: 86400'); header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS'); header('X-AAlerts-Bron: eigen');
      echo $t; exit;
    }
    if($method==='GET'&&$path==='/v1/status')aaReply(200,aaStatusOverzicht(),['Cache-Control'=>'public, max-age=30']);
    if(preg_match('#^/v1/meldingen/([\w-]{8,64})/routes$#',$path,$m)&&$method==='GET'){
      $item=aaGetMelding($m[1]); if(!$item)aaReply(404,['error'=>'Not found']);
      if(trim((string)($item['locatie']??''))===''||$item['lat']===null)aaReply(200,['guid'=>$item['guid'],'kazernes'=>[]]);
      aaReply(200,aaRoutesVoor($item),['Cache-Control'=>'public, max-age=3600']);
    }
    if(preg_match('#^/v1/meldingen/([\w-]{8,64})/pand$#',$path,$m)&&$method==='GET'){
      $item=aaGetMelding($m[1]); if(!$item)aaReply(404,['error'=>'Not found']);
      aaReply(200,['guid'=>$item['guid'],'pand'=>aaPandVoor($item)],['Cache-Control'=>'public, max-age=3600']);
    }
    if(preg_match('#^/v1/meldingen/([\w-]{8,64})/weather$#',$path,$m)&&$method==='GET'){
      $item=aaGetMelding($m[1]); if(!$item)aaReply(404,['error'=>'Not found']);
      $w=aaWeatherFor($item); if($item['lat']!==null){try{aaNoteWeer((bool)$w);}catch(Throwable){}}
      $w?aaReply(200,$w,['Cache-Control'=>'public, max-age=3600']):aaReply(503,['error'=>'Weather unavailable']);
    }
    if(preg_match('#^/v1/waarschuwingen/([\w:.\-%+]{4,160})/weather$#',$path,$m)&&$method==='GET'){
      $w=aaGetWarning(rawurldecode($m[1])); if(!$w)aaReply(404,['error'=>'Not found']);
      if($w['bron']==='knmi'){ $nu=aaWeatherNow(); try{aaNoteWeer((bool)$nu);}catch(Throwable){}
        $nu?aaReply(200,$nu,['Cache-Control'=>'public, max-age=300']):aaReply(503,['error'=>'Weather unavailable']); }
      $d=aaWeatherFor(['guid'=>'w:'.$w['id'],'lat'=>$w['lat']??null,'lon'=>$w['lon']??null,'datum_tijd'=>$w['start']]);
      if(($w['lat']??null)!==null){try{aaNoteWeer((bool)$d);}catch(Throwable){}}
      $d?aaReply(200,$d,['Cache-Control'=>'public, max-age=3600']):aaReply(503,['error'=>'Weather unavailable']);
    }
    if(preg_match('#^/v1/meldingen/([\w-]{8,64})/feedback$#',$path,$m)&&$method==='POST'){
      $r=aaSaveFeedback($m[1],aaJsonBody()); aaPushAdminsAlsActief('Melding gemeld als onjuist','Bekijk het in het beheer onder Gemelde fouten.'); aaReply(200,$r);
    }
    if($method==='POST'&&$path==='/v1/installs'){ aaRecordInstall(aaJsonBody()); aaReply(200,['ok'=>true]); }
    if($method==='POST'&&$path==='/v1/downloads'){ aaRecordDownload(aaJsonBody()); aaReply(200,['ok'=>true]); }
    if($method==='POST'&&$path==='/v1/visit'){ aaRecordVisit(aaJsonBody()); aaReply(200,['ok'=>true]); }
    if($method==='GET'&&$path==='/v1/widget/ping')aaReply(200,aaWidgetPing($_GET),['Cache-Control'=>'no-store']);
    if($method==='POST'&&$path==='/v1/devices'){ aaRegisterDevice(aaJsonBody()); aaReply(200,['ok'=>true]); }
    $tok='([0-9a-f]{64,200}|fcm(?::|%3A)[A-Za-z0-9_:%-]{100,420})';
    if($method==='DELETE'&&preg_match("#^/v1/devices/$tok$#i",$path,$m))aaReply(200,['ok'=>aaDeleteDeviceRoute(aaDeviceKey($m[1]))]);
    if($method==='POST'&&preg_match("#^/v1/devices/$tok/test$#i",$path,$m)){
      $d=aaGetDevice(aaDeviceKey($m[1])); if(!$d)aaReply(404,['error'=>'Unknown device']);
      $nl=str_starts_with((string)(($d['language']??'')?:'nl'),'nl');
      $r=aaSendPush($d,['aps'=>['alert'=>['title'=>'A-Alerts','body'=>$nl?'Testmelding: je pushmeldingen werken! 🚨':'Test notification: your push notifications work! 🚨'],'sound'=>'default']]);
      if(aaIsDeadToken($r))aaDeleteDevice($d['token']);
      aaReply($r['ok']?200:502,$r+['platform'=>$d['platform'],'environment'=>$d['environment']]);
    }
    if(!str_starts_with($path,'/v1/beheer/'))return;

    // ---------------------------------------------------------------- beheer
    if($method==='POST'&&$path==='/v1/beheer/login')aaReply(200,aaLogin(aaJsonBody()));
    if($method==='GET'&&$path==='/v1/beheer/ping')aaReply(200,['configured'=>aaBeheerConfigured()]);
    $admin=aaRequireAdmin();
    // Until the switch, the old apps still read from the Worker. So every beheer change made here also goes to the
    // Worker (same login, same token), so old and new apps show the same. Off with WORKER_SPIEGEL=0.
    if($method!=='GET'&&$method!=='HEAD'&&$method!=='OPTIONS')aaBeheerDoorsturen($method,$path);
    if($method==='GET'&&$path==='/v1/beheer/me')aaReply(200,['user'=>$admin['u']??null,'expires'=>aaIso((int)$admin['exp'])]);
    if($method==='GET'&&$path==='/v1/beheer/overview')aaReply(200,aaOverview(aaStatusInfo())+['aAlerts'=>aaAlertsOverview(),'android'=>aaDownloadStats()]);
    if($method==='GET'&&$path==='/v1/beheer/widgets')aaReply(200,['sites'=>aaWidgetSites()]);
    if(($method==='POST'||$method==='PUT')&&$path==='/v1/beheer/widgets/blok')aaWidgetBlok(aaJsonBody())?aaReply(200,['sites'=>aaWidgetSites()]):aaReply(400,['error'=>'Ongeldige site']);
    if($method==='GET'&&$path==='/v1/beheer/downloads')aaReply(200,aaDownloadStats());
    if($method==='GET'&&$path==='/v1/beheer/waarschuwingen')aaReply(200,aaAdminWarnings($_GET));
    if(preg_match('#^/v1/beheer/bronnen/([a-z]{3,12})$#',$path,$m)&&in_array($method,['PATCH','PUT','POST'],true)){ $r=aaSetBron($m[1],aaJsonBody()['aan']??null); $r?aaReply(200,$r):aaReply(400,['error'=>'Onbekende bron']); }
    if(preg_match('#^/v1/beheer/waarschuwingen/([\w:.\-%+]{4,160})$#',$path,$m)&&in_array($method,['PATCH','PUT'],true)){ $i=aaUpdateWarning(rawurldecode($m[1]),aaJsonBody()); $i?aaReply(200,$i):aaReply(404,['error'=>'Niet gevonden']); }
    if($method==='GET'&&$path==='/v1/beheer/nieuws')aaReply(200,aaListNieuws($origin,$_GET['soort']??null,($_GET['limit']??'')?:100,$_GET['before']??null,true));
    if($path==='/v1/beheer/nieuws/stock'){ if($method==='GET')aaReply(200,aaStockLijst($origin)); if($method==='PUT'||$method==='POST'){ aaZetStock(aaJsonBody(3000000)); aaReply(200,aaStockLijst($origin)); } }
    if(preg_match('#^/v1/beheer/nieuws/([pwlr]-[0-9a-f]{12})$#',$path,$m)){
      if($method==='GET'){ $i=aaGetNieuws($origin,$m[1],true); $i?aaReply(200,$i):aaReply(404,['error'=>'Niet gevonden']); }
      if($method==='PATCH'||$method==='PUT'){ aaWijzigNieuws($m[1],aaJsonBody(3000000))?aaReply(200,aaGetNieuws($origin,$m[1],true)):aaReply(404,['error'=>'Niet gevonden']); }
    }
    if(preg_match('#^/v1/beheer/nieuws/([pwlr]-[0-9a-f]{12})/updates$#',$path,$m)&&$method==='POST'){
      $b=aaJsonBody(3000000); $r=aaVoegToe($m[1],$b); if(!$r)aaReply(404,['error'=>'Niet gevonden']);
      $n=($b['push']??null)!==false?aaPushNieuws($m[1],'a',$r['id']):0;
      aaReply(200,aaGetNieuws($origin,$m[1],true)+['ontvangers'=>$n]);
    }
    if(preg_match('#^/v1/beheer/nieuws/([pwlr]-[0-9a-f]{12})/updates/(\d+)$#',$path,$m)){
      if($method==='PATCH'||$method==='PUT')aaWijzigUpdate($m[1],(int)$m[2],aaJsonBody(3000000))?aaReply(200,aaGetNieuws($origin,$m[1],true)):aaReply(404,['error'=>'Niet gevonden']);
      if($method==='DELETE')aaVerwijderUpdate($m[1],(int)$m[2])?aaReply(200,aaGetNieuws($origin,$m[1],true)):aaReply(404,['error'=>'Niet gevonden']);
    }
    if($method==='GET'&&$path==='/v1/beheer/meldingen')aaReply(200,aaAdminMeldingen($_GET));
    if(preg_match('#^/v1/beheer/meldingen/([\w-]{8,64})$#',$path,$m)){
      if($method==='GET'){ $i=aaGetMelding($m[1],true); $i?aaReply(200,$i):aaReply(404,['error'=>'Niet gevonden']); }
      if($method==='PATCH'||$method==='PUT')aaReply(200,aaUpdateMelding($m[1],aaJsonBody()));
    }
    if($method==='POST'&&$path==='/v1/beheer/push')aaReply(200,aaBroadcast(aaJsonBody()));
    if($method==='GET'&&preg_match('#^/v1/beheer/toestel/([0-9A-Fa-f-]{6,9})$#',$path,$m))aaReply(200,aaZoekToestel($m[1]));
    if($method==='POST'&&preg_match('#^/v1/beheer/toestel/([0-9A-Fa-f-]{6,9})/test$#',$path,$m))aaReply(200,aaTestToestel($m[1]));
    if($method==='GET'&&$path==='/v1/beheer/feedback')aaReply(200,aaFeedbackLijst($_GET));
    if(preg_match('#^/v1/beheer/feedback/(\d+)$#',$path,$m)){
      if($method==='POST'||$method==='PATCH'){ $st=(aaJsonBody()['status']??null)==='afgehandeld'?'afgehandeld':'open'; aaDb()->prepare('UPDATE melding_feedback SET status = ? WHERE id = ?')->execute([$st,(int)$m[1]]); aaReply(200,['ok'=>true,'id'=>(int)$m[1],'status'=>$st]); }
      if($method==='DELETE'){ aaDb()->prepare('DELETE FROM melding_feedback WHERE id = ?')->execute([(int)$m[1]]); aaReply(200,['ok'=>true]); }
    }
    if($method==='GET'&&$path==='/v1/beheer/push')aaReply(200,aaBroadcastHistory());
    if(preg_match('#^/v1/beheer/content/(\w+)$#',$path,$m)&&in_array($m[1],AA_BEHEER_CONTENT,true)){
      require_once __DIR__.'/vast.php';
      if($method==='GET')aaReply(200,aaContentFor($m[1]));
      if($method==='PUT')aaReply(200,aaSaveContent($m[1],json_decode((string)file_get_contents('php://input'),true)));
      if($method==='DELETE'){ aaDb()->prepare('DELETE FROM kv WHERE `key` = ?')->execute(['content:'.$m[1]]); aaReply(200,aaContentFor($m[1])); }
    }
    if($method==='GET'&&$path==='/v1/beheer/bronnen')aaReply(200,aaBeheerBronnen());
    if($method==='GET'&&$path==='/v1/beheer/status')aaReply(200,aaStatusOverzicht(true));
    if($method==='POST'&&$path==='/v1/beheer/status/incidenten')aaReply(200,aaMaakIncident(aaJsonBody()));
    if(preg_match('#^/v1/beheer/status/incidenten/(\d+)$#',$path,$m)){
      if($method==='POST'){ $r=aaUpdateIncident((int)$m[1],aaJsonBody()); $r?aaReply(200,$r):aaReply(404,['error'=>'Not found']); }
      if($method==='DELETE'){ aaDb()->prepare('DELETE FROM status_incidenten WHERE id = ?')->execute([(int)$m[1]]); aaReply(200,['ok'=>true]); }
    }
    if($method==='POST'&&$path==='/v1/beheer/devices'){ $b=aaJsonBody(); aaRegisterAdminDevice($b); aaSetAdminPrefs(strtolower((string)($b['token']??'')),$b); aaReply(200,['ok'=>true]); }
    if($method==='POST'&&$path==='/v1/beheer/devices/test')aaReply(200,aaPushAdminsBeheer('A-Alerts-beheer','Testmelding: meldingen van het A-Alerts-beheer werken. ✅'));
    aaReply(404,['error'=>'Not found']);
  }catch(AaHttpError $e){
    aaReply($e->status,['error'=>$e->getMessage()]);
  }catch(Throwable $e){
    error_log('[aa apple route] '.get_class($e).' '.$e->getMessage());
    aaReply(500,['error'=>'Server error']);
  }
}

/** Sends the same beheer change to the Worker (best effort, never blocks the change here). */
function aaBeheerDoorsturen(string $method, string $path): void {
  // A test push to the beheer phones is sent here only (else it arrives twice).
  if(setting('WORKER_SPIEGEL')==='0'||$path==='/v1/beheer/devices/test')return;
  $worker=rtrim(setting('REFERENCE_WORKER_URL')?:'https://p2000-apeldoorn-api.lrvdlinden.workers.dev','/');
  $auth=(string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
  $q=(string)($_SERVER['QUERY_STRING']??'');
  $ch=curl_init($worker.$path.($q!==''?'?'.$q:''));
  curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_POSTFIELDS=>(string)file_get_contents('php://input'),CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_HTTPHEADER=>['Authorization: '.$auth,'Content-Type: '.((string)($_SERVER['CONTENT_TYPE']??'application/json')),'User-Agent: A-Alerts-API/1.0 (beheer)']]);
  $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  try{ aaKvSet('beheer_doorsturen',aaJson(['at'=>aaIso(aaNow()),'methode'=>$method,'pad'=>$path,'http'=>$code])); }catch(Throwable){}
  if($code<200||$code>=300)error_log('[aa beheer doorsturen] '.$method.' '.$path.' HTTP '.$code);
}

