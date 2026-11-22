<?php
declare(strict_types=1);
/**
 * Public routes of the A-Alerts engine, with the same paths, status codes, headers and JSON as the Worker (index.js).
 * Every route that is ported lives here; routes not here yet fall through to the existing handlers.
 */
require_once __DIR__.'/meldingen.php';
require_once __DIR__.'/vast.php';
require_once __DIR__.'/waarschuwingen.php';
require_once __DIR__.'/nieuws.php';

function aaReply(int $status, mixed $body, array $extra=[]): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: content-type, authorization');
  header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
  header('X-AAlerts-Bron: eigen');
  foreach($extra as $k=>$v)header($k.': '.$v);
  if($body!==null)echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

function aaRoute(string $path): void {
  // Modules each with their own file (they never edit this file): aircraft/map/traffic/radar and Apple/devices/statistics/beheer.
  foreach(['lucht'=>'aaRouteLucht','apple'=>'aaRouteApple','bronnen'=>'aaRouteBronnen'] as $f=>$fn){
    if(is_file(__DIR__."/routes-$f.php")){require_once __DIR__."/routes-$f.php"; if(function_exists($fn))$fn(rtrim($path,'/')?:'/');}
  }
  $path=rtrim($path,'/')?:'/';
  $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
  static $eigen=['/','/health','/v1/meldingen','/v1/locaties','/v1/veiligheidsregio','/v1/changelog','/v1/content/','/v1/app','/v1/posten/','/v1/waarschuwingen','/v1/nieuws','/v1/afkortingen','/v1/statistiek'];
  $hit=false; foreach($eigen as $p)if($path===$p||($p!=='/'&&str_starts_with($path,rtrim($p,'/').'/'))){$hit=true;break;}
  if(!$hit)return;
  if($method==='OPTIONS')aaReply(204,null);
  if($path==='/'||$path==='/health'){ require_once __DIR__.'/health.php'; try{ aaReply(200,aaHealth()); }catch(Throwable $e){ error_log('[aa health] '.$e->getMessage()); aaReply(500,['error'=>'Internal error']); } }
  try{
    if($method==='GET'&&$path==='/v1/meldingen')
      aaReply(200,aaListMeldingen($_GET['limit']??null,$_GET['before']??null,$_GET['plaats']??null,$_GET['eenheid']??null),['Cache-Control'=>'public, max-age=5']);
    if(preg_match('#^/v1/meldingen/([\w-]{8,64})$#',$path,$m)&&$method==='GET'){
      $item=aaGetMelding($m[1]); $item?aaReply(200,$item):aaReply(404,['error'=>'Not found']);
    }
    if($method==='GET'&&preg_match('#^/v1/meldingen/([\w-]{8,64})/afbeelding\.png$#',$path,$m)){ require_once __DIR__.'/afbeelding.php'; aaAfbeeldingRoute($m[1],(string)($_GET['formaat']??'og')); }
    if($method==='GET'&&$path==='/v1/afkortingen'){ require_once __DIR__.'/afkortingen.php'; aaReply(200,aaAfkortingen(),['Cache-Control'=>'public, max-age=3600']); }
    if($method==='GET'&&$path==='/v1/statistiek'){ require_once __DIR__.'/statistiek.php'; aaReply(200,aaStatistiek($_GET['dagen']??28,$_GET['plaats']??null,$_GET['eenheid']??null),['Cache-Control'=>'public, max-age=300']); }
    if(preg_match('#^/v1/meldingen/([\w-]{8,64})/view$#',$path,$m)&&$method==='POST')aaReply(200,['views'=>aaAddView($m[1])]);
    if(preg_match('#^/v1/meldingen/([\w-]{8,64})/verloop$#',$path,$m)&&$method==='GET'){
      $item=aaGetMelding($m[1]); if(!$item)aaReply(404,['error'=>'Not found']);
      $lijst=$item['incident']?aaIncidentMeldingen($item['incident']):[$item];
      aaReply(200,['guid'=>$item['guid'],'incident'=>$item['incident'],'meldingen'=>$lijst],['Cache-Control'=>'public, max-age=20']);
    }
    if($method==='GET'&&$path==='/v1/waarschuwingen')
      aaReply(200,aaListWarnings($_GET['days']??null,($_GET['kind']??'')==='1',($_GET['vnog']??'')==='1'),['Cache-Control'=>'public, max-age=30']);
    if($method==='GET'&&preg_match('#^/v1/waarschuwingen/([\w:.\-%+]{4,160})$#',$path,$m)){
      $w=aaGetWarning(rawurldecode($m[1])); if(!$w)aaReply(404,['error'=>'Not found']);
      $w['meldingen']=$w['bron']==='knmi'?[]:aaLinkedMeldingen($w);
      aaReply(200,$w,['Cache-Control'=>'public, max-age=30']);
    }
    $origin='https://'.preg_replace('/[^a-z0-9.:-]/i','',(string)($_SERVER['HTTP_HOST']??'api.a-alerts.nl'));
    if($method==='GET'&&$path==='/v1/nieuws')aaReply(200,aaListNieuws($origin,$_GET['soort']??null,$_GET['limit']??null,$_GET['before']??null),['Cache-Control'=>'public, max-age=30']);
    if($method==='GET'&&preg_match('#^/v1/nieuws/foto/([0-9a-f]{32})$#',$path,$m))aaFotoResponse($m[1]);
    if($method==='GET'&&preg_match('#^/v1/nieuws/([pwlr]-[0-9a-f]{12})$#',$path,$m)){$n=aaGetNieuws($origin,$m[1]);$n?aaReply(200,$n,['Cache-Control'=>'public, max-age=20']):aaReply(404,['error'=>'Not found']);}
    if($method==='GET'&&$path==='/v1/locaties')aaReply(200,aaLocatiesData(),['Cache-Control'=>'public, max-age=86400']);
    if($method==='GET'&&$path==='/v1/veiligheidsregio')aaReply(200,aaData('veiligheidsregio'),['Cache-Control'=>'public, max-age=86400']);
    if($method==='GET'&&$path==='/v1/changelog')aaReply(200,aaContentFor('changelog'),['Cache-Control'=>'public, max-age=300']);
    if($method==='GET'&&preg_match('#^/v1/content/(privacy|cookies|terms|disclaimer|hulp)$#',$path,$m))aaReply(200,aaContentFor($m[1]),['Cache-Control'=>'public, max-age=300']);
    if($method==='GET'&&$path==='/v1/app')aaReply(200,aaAppInfo(),['Cache-Control'=>'public, max-age=600']);
    if($method==='GET'&&preg_match('#^/v1/posten/([\w-]{3,60})$#',$path,$m)){$p=aaPostInfo($m[1]);$p?aaReply(200,$p,['Cache-Control'=>'public, max-age=120']):aaReply(404,['error'=>'Not found']);}
  }catch(Throwable $e){
    error_log('[aa route] '.get_class($e).' '.$e->getMessage());
    aaReply(500,['error'=>'Internal error']);
  }
}
