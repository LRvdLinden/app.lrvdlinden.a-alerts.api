<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
header('Access-Control-Allow-Origin: null'); // private server-to-server only; browser clients not supported
if(!tokenAllowed())respond(401,['error'=>'unauthorized']);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')respond(405,['error'=>'method_not_allowed']);
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
$routes=['/v1/meldingen'=>'p2000','/v1/waarschuwingen'=>'waarschuwingen','/v1/nieuws'=>'nieuws',
'/v1/verkeer'=>'verkeer','/v1/helikopters'=>'helikopters','/v1/lucht'=>'helikopters'];
try{
  if(isset($routes[$path])){
    $snap=loadSnapshot($routes[$path]);
    $items=$snap['items']??[];
    $limit=max(1,min(100,(int)($_GET['limit']??50)));
    $kind=(string)($_GET['soort']??$_GET['bron']??'');
    if($kind!=='')$items=array_values(array_filter($items,fn($x)=>($x['soort']??$x['bron']??'')===$kind));
    respond(200,['data'=>array_slice($items,0,$limit),'count'=>count($items),
      'source'=>$snap['source']??$routes[$path],'updated_at'=>$snap['updated_at']??null,
      'error'=>$snap['error']??null,'shadow'=>true]);
  }
  if($path==='/v1/status'||$path==='/v1/bronnen'){
    $sources=['p2000','websocket','nlalert','burgernet','knmi','kind','verkeer','helikopters','nieuws','nieuws_politie','nieuws_112'];
    $out=[];foreach($sources as $source){$s=loadSnapshot($source);$out[$source]=[
      'updated_at'=>$s['updated_at']??null,'count'=>count($s['items']??[]),'error'=>$s['error']??null];}
    respond(200,['sources'=>$out,'shadow'=>true]);
  }
  if($path==='/v1/vergelijk'){
    $names=['meldingen','waarschuwingen','nieuws','verkeer','helikopters'];
    $map=['meldingen'=>'p2000','waarschuwingen'=>'waarschuwingen','nieuws'=>'nieuws',
      'verkeer'=>'verkeer','helikopters'=>'helikopters'];
    $out=[];foreach($names as $n){
      $own=loadSnapshot($map[$n]);$ref=loadSnapshot('reference_'.$n);
      $ownIds=array_unique(array_map(fn($r)=>(string)($r['id']??$r['guid']??$r['url']??''),$own['items']??[]));
      $refIds=array_unique(array_map(fn($r)=>(string)($r['id']??$r['guid']??$r['url']??''),$ref['items']??[]));
      $ownIds=array_values(array_filter($ownIds));$refIds=array_values(array_filter($refIds));
      $out[$n]=['local_count'=>count($ownIds),'worker_count'=>count($refIds),
        'missing_count'=>count(array_diff($refIds,$ownIds)),'extra_count'=>count(array_diff($ownIds,$refIds)),
        'local_updated_at'=>$own['updated_at']??null,'reference_updated_at'=>$ref['updated_at']??null,
        'note'=>'IDs only; counts do not prove payload parity or matching time windows'];
    }
    respond(200,['comparison'=>$out,'warning'=>'NOT a 1:1 certification: different normalization, timestamps and derived content may differ']);
  }
  respond(404,['error'=>'not_found']);
}catch(Throwable $e){error_log('A-Alerts shadow API: '.get_class($e));respond(503,['error'=>'unavailable']);}
