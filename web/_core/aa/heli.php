<?php
declare(strict_types=1);
/**
 * Cron "heli" (every minute, three rounds of about 20 seconds as the Worker cron does): the part of heliRun (index.js)
 * that keeps the data: positions for the trail and flights, last-seen status with weather that limits flying, Lifeliners
 * on scene, cancelled call-outs, take-off moments, housekeeping. Pushes only when PUSH_ACTIEF is "1" (the push module
 * of the engine sends them); while the Worker is production they stay off, else everyone gets them twice.
 * CLI only.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/heli-lib.php';
require_once __DIR__.'/waarschuwingen.php';

function aaHeliRun(): array {
  $incoming=aaHelikopters();
  $now=aaNow();
  aaDb()->prepare('INSERT INTO kv (`key`, value, at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), at = VALUES(at)')
    ->execute([!empty($incoming['fout'])?'status_heli_fout':'status_heli_ok',(string)$now,$now]);
  if(!empty($incoming['fout']))return ['fout'=>true];
  $helis=array_values(array_filter($incoming['helikopters']??[],fn($h)=>empty($h['verouderd'])));
  $lucht=array_values(array_filter($incoming['lucht']??[],fn($h)=>empty($h['verouderd'])));
  $prev=$helis?aaRecordPositions($helis):[];
  try{ aaRecordLucht($lucht); }catch(Throwable $e){ error_log('[aa lucht spoor] '.$e->getMessage()); }
  // Last seen per aircraft (one kv row, at most once a minute) with weather that limits flying (read once in ten minutes).
  try{
    $alle=array_merge($helis,$lucht); $nu=aaNow(); $rij=aaKvRow('heli_status');
    $oud=$rij?json_decode((string)$rij['value'],true):[]; if(!is_array($oud))$oud=[];
    $wissel=false; foreach($alle as $h)if(!empty($h['callsign'])&&!empty($oud['toestellen'][$h['id']]['cs'])&&$oud['toestellen'][$h['id']]['cs']!==$h['callsign'])$wissel=true;
    if(!$rij||$wissel||$nu-(int)$rij['at']>55000){
      $weer=$oud['weer']??null; $ververs=empty($oud['weer_at'])||$nu-(int)$oud['weer_at']>600000;
      if($ververs){ try{ $weer=aaWeerBeperking(aaListWarnings(2),$nu); }catch(Throwable){} }
      aaKvSet('heli_status',aaJson(['toestellen'=>(object)aaStatusBijwerken(is_array($oud['toestellen']??null)?$oud['toestellen']:[],$alle,$nu),'weer'=>$weer,'weer_at'=>$ververs?$nu:$oud['weer_at']]));
    }
  }catch(Throwable $e){ error_log('[aa heli status] '.$e->getMessage()); }
  // Lifeliners on scene and call-outs that were probably cancelled (P2000 sends no cancellation).
  $geannuleerd=[];
  try{
    $trauma=array_values(array_filter($helis,fn($h)=>$h['soort']==='trauma'&&empty($h['medic'])));
    if($trauma){
      $meldingen=aaRecenteHeliMeldingen(aaNow()); aaNoteerTerPlaatse($trauma,$meldingen,aaNow());
      if($meldingen)$geannuleerd=aaVolgInzetten($trauma,$meldingen,aaTerPlaatseSet(),aaNow());
    }
  }catch(Throwable $e){ error_log('[aa heli zones] '.$e->getMessage()); }
  // Take-off moments (checkOpstijgen); the take-off push itself belongs to the push module.
  $st=aaDb()->prepare('INSERT INTO kv (`key`, value, at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), at = VALUES(at)');
  foreach(aaOpgestegen($helis,$prev,aaNow()) as $h)$st->execute(['heli_start:'.$h['id'],(string)aaNow(),aaNow()]);
  if(setting('PUSH_ACTIEF')==='1'&&is_file(__DIR__.'/push.php')){
    require_once __DIR__.'/push.php';
    if(function_exists('aaHeliPushes'))aaHeliPushes($helis,$lucht,$prev,$geannuleerd);
  }
  return ['helikopters'=>count($helis),'lucht'=>count($lucht),'geannuleerd'=>count($geannuleerd)];
}

$lock=fopen(sys_get_temp_dir().'/aalerts-heli-'.substr(hash('sha256',__DIR__),0,12).'.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
try{ aaEnsureSchema(aaDb()); }catch(Throwable $e){ fwrite(STDERR,"schema\n"); exit(1); }
$start=microtime(true); $out=[];
for($i=0;$i<3;$i++){
  try{ $out[]=aaHeliRun(); }catch(Throwable $e){ $out[]=['fout'=>substr($e->getMessage(),0,80)]; }
  if($i===0&&(int)gmdate('i')%10===0){
    try{
      aaDb()->prepare('DELETE FROM heli_posities WHERE at < ?')->execute([aaNow()-AA_SPOOR_KEEP_MS]);
      aaDb()->prepare("DELETE FROM kv WHERE (`key` LIKE 'heli\\_push:%' OR `key` LIKE 'heli\\_start:%') AND at < ?")->execute([aaNow()-86400000]);
    }catch(Throwable){}
  }
  $wacht=$start+($i+1)*19-microtime(true); if($i<2&&$wacht>0)usleep((int)($wacht*1e6));
}
echo 'heli '.json_encode($out)."\n";
