<?php
declare(strict_types=1);
/**
 * Copy of the Worker database (Cloudflare D1, only SELECT) into the own MariaDB, so that the own API has all data the
 * Worker collected: devices and their settings, beheer corrections, news, warnings, statistics, station history.
 * - "vol": every table once, page by page (rowid), resumable; runs until done.
 * - "klein": the small tables that apps and the beheer change all the time (devices, settings, corrections, texts),
 *   every 15 minutes until the switch, so the own API can send pushes to everyone from the first minute.
 * Own data wins where the own engine already writes (alerts from the own intake, own intake status).
 * Reads count against the free D1 allowance: paging by rowid reads each row once.
 * CLI only.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/base.php';

const AA_D1_TABELLEN=['devices','device_prefs','device_install','admin_devices','admin_prefs','overrides','waarschuwing_overrides','status_incidenten',
  'widget_sites','widget_blok','nieuws_liveblog','nieuws_stockfoto','waarschuwing_knmi','waarschuwingen','nieuws','nieuws_updates','installs',
  'install_days','visits','pageviews','downloads','melding_feedback','status_dag','status_push','widget_views','post_inzet','melding_routes','weather',
  'heli_posities','nieuws_fotos','kv','meldingen'];
/** Tables copied whole again until the switch, per interval in minutes (their own intake does not exist yet or they are
 * written by the apps and the beheer). Each run reads every row once: together well within the free D1 allowance. */
const AA_D1_KLEIN=[5=>['waarschuwingen','waarschuwing_knmi','waarschuwing_overrides','overrides'],
  15=>['devices','device_prefs','device_install','admin_devices','admin_prefs','status_incidenten','widget_blok','nieuws','nieuws_updates','nieuws_liveblog','nieuws_stockfoto']];
/** Tables the own engine writes itself: rows of the Worker never replace own rows there. */
const AA_D1_EIGEN_WINT=['meldingen','post_inzet'];

function aaD1(string $sql, array $params=[]): array {
  static $db=null;
  $acc=setting('CLOUDFLARE_ACCOUNT_ID'); $tok=setting('CLOUDFLARE_API_TOKEN');
  if($acc===''||$tok==='')throw new RuntimeException('cloudflare_keys_missing');
  $call=function(string $method,string $url,?array $body=null)use($tok){
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>40,CURLOPT_CUSTOMREQUEST=>$method,
      CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$tok,'Content-Type: application/json']]+($body!==null?[CURLOPT_POSTFIELDS=>json_encode($body)]:[]));
    $r=curl_exec($ch); $c=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $j=is_string($r)?json_decode($r,true):null;
    if($c!==200||!is_array($j)||empty($j['success']))throw new RuntimeException('d1_http_'.$c.' '.substr((string)json_encode($j['errors']??null),0,160));
    return $j['result'];
  };
  if($db===null){
    $db=aaKvGet('aa_d1_db');
    if(!$db){ foreach($call('GET','https://api.cloudflare.com/client/v4/accounts/'.$acc.'/d1/database?name=p2000-apeldoorn') as $d)if(($d['name']??'')==='p2000-apeldoorn')$db=$d['uuid']; }
    if(!$db)throw new RuntimeException('d1_database_not_found');
    aaKvSet('aa_d1_db',$db);
  }
  $res=$call('POST','https://api.cloudflare.com/client/v4/accounts/'.$acc.'/d1/database/'.$db.'/query',['sql'=>$sql,'params'=>$params]);
  return $res[0]['results']??[];
}
function aaKolommen(string $t): array {
  static $c=[]; if(isset($c[$t]))return $c[$t];
  $q=aaDb()->query('SHOW COLUMNS FROM `'.$t.'`'); $c[$t]=[];
  foreach($q as $r)if(stripos((string)$r['Extra'],'GENERATED')===false&&stripos((string)$r['Extra'],'VIRTUAL')===false&&stripos((string)$r['Extra'],'PERSISTENT')===false&&stripos((string)$r['Extra'],'STORED')===false)$c[$t][]=$r['Field'];
  return $c[$t];
}
/** Rows of D1 into MariaDB. Returns the number of rows read. */
function aaD1Schrijf(string $t, array $rows): int {
  if(!$rows)return 0;
  $cols=array_values(array_intersect(aaKolommen($t),array_keys($rows[0])));
  $verb=in_array($t,AA_D1_EIGEN_WINT,true)?'INSERT IGNORE':'REPLACE';
  $st=aaDb()->prepare($verb.' INTO `'.$t.'` ('.implode(',',array_map(fn($c)=>'`'.$c.'`',$cols)).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')');
  foreach($rows as $r){
    // The own intake status keys belong to this server.
    if($t==='kv'&&preg_match('/^(koyeb_|aa_)/',(string)$r['key']))continue;
    $v=[]; foreach($cols as $c){ $x=$r[$c]; if(is_array($x))$x=pack('C*',...$x); $v[]=$x; }
    $st->execute($v);
  }
  if($t==='meldingen')aaDb()->exec("UPDATE meldingen SET bron='d1' WHERE bron IS NULL");
  return count($rows);
}
/** One table page by page from where it stopped. Returns true when the table is done. */
function aaD1Tabel(string $t, float $until): bool {
  $key='aa_d1_rowid:'.$t; $rid=(int)(aaKvGet($key)??0);
  while(microtime(true)<$until){
    $rows=aaD1('SELECT rowid AS _rid, * FROM '.$t.' WHERE rowid > ? ORDER BY rowid LIMIT 500',[$rid]);
    if(!$rows){aaKvSet($key,'klaar');return true;}
    aaD1Schrijf($t,$rows); $rid=(int)end($rows)['_rid']; aaKvSet($key,(string)$rid);
    if(count($rows)<500){aaKvSet($key,'klaar');return true;}
  }
  return false;
}

$lock=fopen(sys_get_temp_dir().'/aalerts-d1-'.substr(hash('sha256',__DIR__),0,12).'.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
if(setting('WORKER_SPIEGEL')==='0'){echo "d1 uit\n";exit(0);}
$until=microtime(true)+50;
try{
  aaEnsureSchema(aaDb());
  $status=aaParse(aaKvGet('aa_d1'),null)?:[];
  foreach(['klein_ms','klein','tellingen'] as $k)if(isset($status[$k])&&!is_array($status[$k]))unset($status[$k]);
  if(empty($status['vol_klaar'])){
    foreach(AA_D1_TABELLEN as $t){
      if(aaKvGet('aa_d1_rowid:'.$t)==='klaar')continue;
      if(!aaD1Tabel($t,$until))break;
    }
    $klaar=true; foreach(AA_D1_TABELLEN as $t)if(aaKvGet('aa_d1_rowid:'.$t)!=='klaar')$klaar=false;
    if($klaar){$status['vol_klaar']=aaIso(aaNow());}
    $status['vol_bezig']=aaIso(aaNow());
  }else{
    foreach(AA_D1_KLEIN as $min=>$tabellen){
      if(aaNow()-(int)($status['klein_ms'][$min]??0)<($min*60000-30000))continue;
      foreach($tabellen as $t){
        $rows=aaD1('SELECT * FROM '.$t.' LIMIT 20000');
        // Removed on the Worker (a device that unsubscribed, a hidden item): removed here too.
        aaDb()->beginTransaction(); aaDb()->exec('DELETE FROM `'.$t.'`'); aaD1Schrijf($t,$rows); aaDb()->commit();
        $status['tellingen'][$t]=count($rows);
      }
      if($min===15){
        aaD1Schrijf('kv',aaD1("SELECT * FROM kv WHERE key LIKE 'content:%' OR key LIKE 'post_info:%'"));
        $max=(int)aaDb()->query('SELECT COALESCE(MAX(at),0) FROM nieuws_fotos')->fetchColumn();
        aaD1Schrijf('nieuws_fotos',aaD1('SELECT * FROM nieuws_fotos WHERE at > ?',[$max]));
        $max=(int)aaDb()->query('SELECT COALESCE(MAX(at),0) FROM post_inzet')->fetchColumn();
        aaD1Schrijf('post_inzet',aaD1('SELECT * FROM post_inzet WHERE at > ?',[$max-3600000]));
      }
      $status['klein_ms'][$min]=aaNow(); $status['klein'][$min]=aaIso(aaNow());
    }
  }
  $status['fout']=null; aaKvSet('aa_d1',aaJson($status));
  echo "d1 ".json_encode($status)."\n";
}catch(Throwable $e){
  if(aaDb()->inTransaction())aaDb()->rollBack();
  $status=aaParse(aaKvGet('aa_d1'),null)?:[]; $status['fout']=substr($e->getMessage(),0,200); $status['fout_at']=aaIso(aaNow());
  aaKvSet('aa_d1',aaJson($status)); fwrite(STDERR,'d1 '.$status['fout']."\n"); exit(1);
}
