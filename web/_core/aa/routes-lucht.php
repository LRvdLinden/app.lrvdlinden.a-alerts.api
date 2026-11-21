<?php
declare(strict_types=1);
/**
 * Routes of aircraft, traffic, radar and the map image (index.js), with the same paths, status codes and JSON as the
 * Worker. Called from routes.php (aaRoute) before the other routes.
 */
function aaRouteLucht(string $path): void {
  if(!preg_match('#^/v1/(helikopters|lucht|verkeer|radar|kaart\.png)#',$path))return;
  $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
  if($method==='OPTIONS')aaReply(204,null);
  try{
    if($method==='GET'&&$path==='/v1/helikopters'){ require_once __DIR__.'/heli-lib.php'; aaReply(200,aaHelikoptersAntwoord(),['Cache-Control'=>'public, max-age=10']); }
    if($method==='GET'&&$path==='/v1/lucht'){ require_once __DIR__.'/heli-lib.php'; $a=aaLuchtAntwoord();
      // ?bijzonder=1 (112Heli): also PH-UTL and PH-GOV, worldwide, from jet_posities. Never without the parameter.
      if(($_GET['bijzonder']??'')==='1'){ require_once __DIR__.'/jet.php'; $j=aaJetToestellen(); $hex=array_column($j,'hex');
        $a['toestellen']=array_merge(array_values(array_filter($a['toestellen'],fn($t)=>!in_array($t['hex']??'',$hex,true))),$j); }
      aaReply(200,$a,['Cache-Control'=>'public, max-age=10']); }
    if($method==='GET'&&$path==='/v1/helikopters/status'){ require_once __DIR__.'/heli-lib.php'; aaReply(200,aaHeliStatusAntwoord(),['Cache-Control'=>'public, max-age=30']); }
    if($method==='GET'&&preg_match('#^/v1/helikopters/([a-z0-9-]{3,40})/spoor$#',$path,$m)){
      require_once __DIR__.'/heli-lib.php'; $s=(string)($_GET['scope']??''); $s=in_array($s,['vlucht','kort','vandaag'],true)?$s:'vlucht';
      aaReply(200,aaSpoor($m[1],$s),['Cache-Control'=>'public, max-age=10']);
    }
    if($method==='GET'&&preg_match('#^/v1/helikopters/([a-z0-9-]{3,40})/vluchten$#',$path,$m)){ require_once __DIR__.'/heli-lib.php'; aaReply(200,aaVluchten($m[1]),['Cache-Control'=>'public, max-age=30']); }
    if($method==='POST'&&preg_match('#^/v1/helikopters/([a-z0-9-]{3,40})/melden$#',$path,$m)){ require_once __DIR__.'/lucht-feedback.php'; aaReply(200,aaToestelMelden($m[1])); }
    if($method==='GET'&&$path==='/v1/verkeer'){ require_once __DIR__.'/verkeer-lib.php'; aaReply(200,aaVerkeer(),['Cache-Control'=>'public, max-age=60']); }
    $base='https://'.preg_replace('/[^a-z0-9.:-]/i','',(string)($_SERVER['HTTP_HOST']??'api.a-alerts.nl'));
    if($method==='GET'&&$path==='/v1/radar'){ require_once __DIR__.'/kaart-lib.php'; aaReply(200,aaRadarInfo($base),['Cache-Control'=>'public, max-age=60']); }
    if($method==='GET'&&$path==='/v1/radar.png'){ require_once __DIR__.'/kaart-lib.php'; aaRadarImage(); }
    if($method==='GET'&&preg_match('#^/v1/radar/(\d{1,2})/(\d{1,5})/(\d{1,5})\.png$#',$path,$m)){ require_once __DIR__.'/kaart-lib.php'; aaRadarTile((int)$m[1],(int)$m[2],(int)$m[3]); }
    if($method==='GET'&&$path==='/v1/kaart.png'){ require_once __DIR__.'/kaart-lib.php'; aaKaartImage($_GET); }
  }catch(AaHttpFout $e){
    aaReply($e->status,['error'=>$e->getMessage()]);
  }catch(Throwable $e){
    error_log('[aa lucht route] '.get_class($e).' '.$e->getMessage());
    aaReply(500,['error'=>'Server error']);
  }
}
if(!class_exists('AaHttpFout')){ final class AaHttpFout extends RuntimeException { public function __construct(public int $status, string $m){ parent::__construct($m); } } }
