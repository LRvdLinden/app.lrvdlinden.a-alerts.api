<?php
declare(strict_types=1);
/** "Klopt dit toestel niet?" (index.js + extra.js saveFeedback with guid "toestel:<id>" and reden "anders"). */
require_once __DIR__.'/base.php';

function aaToestelMelden(string $id): array {
  $raw=file_get_contents('php://input',false,null,0,32769);
  if(is_string($raw)&&strlen($raw)>32768)throw new AaHttpFout(413,'Body too large');
  $body=[]; if(is_string($raw)&&$raw!==''){ $body=json_decode($raw,true); if(!is_array($body))throw new AaHttpFout(400,'Invalid JSON'); }
  $str=fn($v,$max)=>is_string($v)?mb_substr(trim($v),0,$max):'';
  $num=fn($v,$a,$b)=>(is_int($v)||is_float($v))&&is_finite((float)$v)&&$v>=$a&&$v<=$b?$v:null;
  $toel=$str($body['toelichting']??null,500);
  if($toel==='')throw new AaHttpFout(400,'Schrijf wat er niet klopt');
  $ip=(string)($_SERVER['HTTP_CF_CONNECTING_IP']??($_SERVER['REMOTE_ADDR']??''));
  $hash=substr(hash('sha256','fb:'.$ip),0,16); $now=aaNow();
  $q=aaDb()->prepare('SELECT COUNT(*) FROM melding_feedback WHERE bron = ? AND at > ?'); $q->execute([$hash,$now-3600000]);
  if((int)$q->fetchColumn()>=10)throw new AaHttpFout(429,'Te veel meldingen, probeer het later opnieuw');
  $lat=$num($body['lat']??null,50,54); $lon=$num($body['lon']??null,2,8);
  aaDb()->prepare("INSERT INTO melding_feedback (guid, reden, toelichting, lat, lon, platform, bron, at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open')")
    ->execute(['toestel:'.$id,'anders',$toel,$lat,$lat===null?null:$lon,$str($body['platform']??null,20)?:null,$hash,$now]);
  // The push to the beheer devices ("Toestel gemeld") belongs to the push module and only runs when PUSH_ACTIEF is "1".
  if(setting('PUSH_ACTIEF')==='1'&&is_file(__DIR__.'/push.php')){ require_once __DIR__.'/push.php'; if(function_exists('aaPushAdmins')){ try{ aaPushAdmins('Toestel gemeld','Bekijk het in het beheer onder Gemelde fouten.'); }catch(Throwable){} } }
  return ['ok'=>true,'id'=>(int)aaDb()->lastInsertId()];
}
