<?php
declare(strict_types=1);
/**
 * Public routes of the A-Alerts engine, with the same paths, status codes, headers and JSON as the Worker (index.js).
 * Every route that is ported lives here; routes not here yet fall through to the existing handlers.
 */
require_once __DIR__.'/meldingen.php';

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
  $path=rtrim($path,'/')?:'/';
  $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
  if(!str_starts_with($path,'/v1/meldingen'))return;
  if($method==='OPTIONS')aaReply(204,null);
  try{
    if($method==='GET'&&$path==='/v1/meldingen')
      aaReply(200,aaListMeldingen($_GET['limit']??null,$_GET['before']??null),['Cache-Control'=>'public, max-age=5']);
    if(preg_match('#^/v1/meldingen/([\w-]{8,64})$#',$path,$m)&&$method==='GET'){
      $item=aaGetMelding($m[1]); $item?aaReply(200,$item):aaReply(404,['error'=>'Not found']);
    }
    if(preg_match('#^/v1/meldingen/([\w-]{8,64})/view$#',$path,$m)&&$method==='POST')aaReply(200,['views'=>aaAddView($m[1])]);
    if(preg_match('#^/v1/meldingen/([\w-]{8,64})/verloop$#',$path,$m)&&$method==='GET'){
      $item=aaGetMelding($m[1]); if(!$item)aaReply(404,['error'=>'Not found']);
      $lijst=$item['incident']?aaIncidentMeldingen($item['incident']):[$item];
      aaReply(200,['guid'=>$item['guid'],'incident'=>$item['incident'],'meldingen'=>$lijst],['Cache-Control'=>'public, max-age=20']);
    }
  }catch(Throwable $e){
    error_log('[aa route] '.get_class($e).' '.$e->getMessage());
    aaReply(500,['error'=>'Internal error']);
  }
}
