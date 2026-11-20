<?php
declare(strict_types=1);
/**
 * Traffic (verkeer.js): the small result of the A-Alerts verkeer service (NDW open data, speed cameras from OSM),
 * fetched once a minute by the own cron with ETag, stored in kv "verkeer" exactly like the Worker.
 */
require_once __DIR__.'/base.php';

/** kv.js kvWrite: only when the value changed, heartbeat keys at most once per interval. */
function aaLKvWrite(string $key, ?string $value, ?int $now=null): void {
  $now??=aaNow(); $iv=0;
  foreach([['/^(cron_run|feed_ok)$/',90000],['/^status_heli_ok$/',120000],['/^(bron_ok:.+|status_weer_ok)$/',240000],['/^verkeer$/',120000],['/^verkeer_vers$/',120000]] as [$re,$ms])if(preg_match($re,$key)){$iv=$ms;break;}
  $old=aaKvRow($key);
  if($old&&$old['value']===$value)return;
  if($old&&$now-(int)$old['at']<$iv)return;
  aaDb()->prepare('INSERT INTO kv (`key`, value, at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), at = VALUES(at)')->execute([$key,$value,$now]);
}
function aaVerkeerConfigured(): bool { return setting('VERKEER_URL')!==''&&setting('VERKEER_TOKEN')!==''; }
function aaVerkeerLees(): ?array { $v=aaKvGet('verkeer'); $d=$v?json_decode($v,true):null; return is_array($d)?$d:null; }
/** GET /v1/verkeer */
function aaVerkeer(): array {
  $v=aaVerkeerLees();
  if(!$v||empty($v['data']))return ['beschikbaar'=>aaVerkeerConfigured(),'bijgewerkt'=>null,'items'=>[],'gepland'=>[],'flitsers'=>[]];
  $d=$v['data']; $vers=aaKvGet('verkeer_vers');
  $t=array_values(array_filter([$d['opgehaald']??null,$vers],fn($x)=>aaOr($x)!==null)); sort($t,SORT_STRING); $opgehaald=$t?end($t):null;
  $oud=aaNow()-((aaMs($opgehaald)??0)?:((int)($v['at']??0)))>15*60000;
  return ['beschikbaar'=>true,'bijgewerkt'=>$d['bijgewerkt']??null,'verouderd'=>$oud,'items'=>$oud?[]:($d['items']??[]),'gepland'=>aaOr($d['gepland']??null)??[],'flitsers'=>aaOr($d['flitsers']??null)??[],
    'bron'=>'NDW open data (Rijkswaterstaat, provincies en gemeenten); flitsers © OpenStreetMap-bijdragers'];
}
/** verkeerRun: once a minute from the cron. */
function aaVerkeerRun(): array {
  if(!aaVerkeerConfigured())return ['ok'=>false,'reden'=>'niet ingesteld'];
  $now=aaNow();
  try{
    $oud=aaVerkeerLees();
    $hdr=['Authorization: Bearer '.setting('VERKEER_TOKEN'),'Accept: application/json']; if(!empty($oud['etag']))$hdr[]='If-None-Match: '.$oud['etag'];
    $headers=[];
    $ch=curl_init(rtrim(setting('VERKEER_URL'),'/').'/verkeer');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_ENCODING=>'',CURLOPT_HTTPHEADER=>$hdr,
      CURLOPT_HEADERFUNCTION=>function($c,$l)use(&$headers){$p=explode(':',$l,2);if(count($p)===2)$headers[strtolower(trim($p[0]))]=trim($p[1]);return strlen($l);}]);
    $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $ndwOud=function($o)use($now){$t=aaMs($o)??0;return $t&&$now-$t>600000?$now-$t:0;};
    $vers=$headers['x-opgehaald']??null;
    if($code===304){ $o=$vers??($oud['data']['opgehaald']??null); if($vers)aaLKvWrite('verkeer_vers',$vers,$now); $r=['ok'=>true,'nieuw'=>false,'ndwOud'=>$ndwOud($o)]; }
    else{
      if($code<200||$code>=300)throw new RuntimeException('verkeer HTTP '.$code);
      $data=json_decode((string)$body,true); if(!is_array($data)||!is_array($data['items']??null)||!array_is_list($data['items']))throw new RuntimeException('verkeer: geen lijst');
      if(empty($data['flitsers'])&&!empty($oud['data']['flitsers']))$data['flitsers']=$oud['data']['flitsers'];
      aaLKvWrite('verkeer',aaJson(['etag'=>$headers['etag']??null,'at'=>aaNow(),'data'=>$data]));
      if(!empty($data['opgehaald']))aaLKvWrite('verkeer_vers',(string)$data['opgehaald'],$now);
      $r=['ok'=>true,'nieuw'=>true,'items'=>count($data['items']),'ndwOud'=>$ndwOud($data['opgehaald']??null)];
    }
    if($r['ndwOud'])aaLKvWrite('bron_fout:verkeer','NDW-gegevens zijn '.(int)round($r['ndwOud']/60000).' min oud',$now); else aaLKvWrite('bron_ok:verkeer',(string)$now,$now);
    return $r;
  }catch(Throwable $e){ aaLKvWrite('bron_fout:verkeer',$e->getMessage(),$now); throw $e; }
}
