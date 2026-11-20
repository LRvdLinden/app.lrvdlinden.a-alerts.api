<?php
declare(strict_types=1);
/** GET / and /health (index.php): the state of the intake, as the Worker answers it, from the own database. */
require_once __DIR__.'/base.php';
require_once __DIR__.'/koyeb-login.php';
foreach(['apple','fcm','kaart-lib'] as $f)if(is_file(__DIR__."/$f.php"))require_once __DIR__."/$f.php";

function aaHealth(): array {
  $iso=function(string $k){ $v=aaKvGet($k); return $v!==null&&$v!==''&&is_numeric($v)?aaIso((int)$v):null; };
  $json=function(string $k){ $v=aaKvGet($k); if($v===null)return null; $d=json_decode($v,true); return $d; };
  $err=aaKvGet('feed_error');
  $v=aaKoyebVerbinding();
  $db=aaDb();
  $call=fn(string $f)=>function_exists($f)?(bool)$f():false;
  return ['ok'=>true,
    'feed'=>['configured'=>setting('P2000_PAGE_API_URL')!=='','lastOk'=>$iso('feed_ok'),'lastError'=>$err!==null&&$err!==''?$err:null],
    'koyeb'=>['configured'=>$v!==null,'login'=>$v['soort']??null,'lastMessage'=>$iso('koyeb_last_message'),'lastAdded'=>$iso('koyeb_last_added')],
    'cron'=>$iso('cron_run'),'lastTry'=>$iso('feed_try'),
    'meldingen'=>(int)$db->query('SELECT COUNT(*) FROM meldingen')->fetchColumn(),
    'apns'=>$call('aaApnsConfigured'),'fcm'=>$call('aaFcmConfigured'),'mapkit'=>$call('aaMapkitConfigured'),'weatherkit'=>$call('aaWeatherConfigured'),
    'mapsServer'=>$call('aaMapsServerConfigured'),'radar'=>$call('aaRadarConfigured'),
    'devices'=>(int)$db->query('SELECT COUNT(*) FROM devices WHERE enabled = 1')->fetchColumn(),
    'waarschuwingen'=>$json('warnings_status'),'nieuws'=>$json('nieuws_politie_status'),'nieuws112'=>$json('n112_status')];
}
