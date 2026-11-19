<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/private-api.php';
require_once __DIR__.'/bootstrap-db.php';
require __DIR__.'/normalize.php';
set_time_limit(180);
$group=$argv[1]??'all';
if(!in_array($group,['all','p2000','alerts','news','air','traffic','reference'],true)){
  fwrite(STDERR,"UNKNOWN_COLLECTOR_GROUP\n");exit(2);
}
function groupAllows(string $source): bool {
  global $group;
  if($group==='all')return true;
  $map=['p2000.page'=>'p2000','nlalert'=>'alerts','burgernet'=>'alerts','kind'=>'alerts','knmi'=>'alerts',
    'ndw'=>'traffic','adsb.lol'=>'air','politie'=>'news','112nieuws'=>'news'];
  return ($map[$source]??(str_starts_with($source,'reference_')?'reference':''))===$group;
}
$lock=fopen(sys_get_temp_dir().'/aalerts-api-private-'.substr(hash('sha256',__DIR__),0,16).'.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
function sourceFetch(string $url,string $key=''): mixed {
  $p=parse_url($url);
  if(($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass']))throw new RuntimeException('invalid_source');
  $ch=curl_init($url);
  $headers=['Accept: application/json, application/rss+xml, application/xml','User-Agent: A-Alerts-private/1.0 (+https://a-alerts.nl)'];
  if($key!=='')$headers[]='x-api-key: '.$key;
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
     CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers,
     CURLOPT_ENCODING=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
  $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  if($status===429||$status===403){
    // Avoid burning free rate-limited keys. Do not retry automatically in the same cron run.
    throw new RuntimeException('upstream_rate_or_access_'.$status);
  }
  if(!is_string($body)||$status!==200||strlen($body)>8000000)throw new RuntimeException('upstream_http_'.$status);
  return $body;
}
function sourceJson(string $url,string $key=''): array {
  $data=json_decode(sourceFetch($url,$key),true,512,JSON_THROW_ON_ERROR);
  if(!is_array($data))throw new RuntimeException('unexpected_json');
  return $data;
}
function entries(array $data): array {
  if(array_is_list($data))return $data;
  foreach(['data','meldingen','results','items','alerts','ac','nieuwsberichten'] as $key)
    if(isset($data[$key])&&is_array($data[$key])&&array_is_list($data[$key]))return $data[$key];
  throw new RuntimeException('unexpected_feed_shape');
}
function itemRow(string $source,string $kind,array $item): array {
 $id=$item['id']??$item['guid']??$item['uid']??$item['AlertId']??$item['hex']??$item['icao']??$item['url']??$item['link']??null;
 if(!is_scalar($id)||trim((string)$id)==='')$id=hash('sha256',json_encode($item,JSON_UNESCAPED_UNICODE));
 $id=(string)$id;if(strlen($id)>190)$id=hash('sha256',$id);
 $title=(string)($item['titel']??$item['title']??$item['melding']??$item['Message']['Title']??'');
 $time=$item['datum']??$item['at']??$item['timestamp']??$item['publicatiedatum']??$item['Sent']??null;
 $date=null;
 if(is_numeric($time)){$n=(float)$time;if($n>1000000000){if($n>100000000000)$n/=1000;$date=gmdate('Y-m-d H:i:s',(int)$n);}}
 elseif(is_string($time)&&strtotime($time)!==false)$date=gmdate('Y-m-d H:i:s',strtotime($time));
 return [$source,$id,$kind,mb_substr($title,0,512),$date,json_encode($item,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)];
}
function runCollector(PDO $db,string $source,string $kind,callable $fetch): void {
 if(!groupAllows($source))return;
 $run=$db->prepare('INSERT INTO api_collector_runs(source) VALUES (?)');$run->execute([$source]);$rid=(int)$db->lastInsertId();
 $fetched=0;$added=0;$changed=0;$ok=false;$error=null;
 try {
  $items=$fetch();
  if(!is_array($items))throw new RuntimeException('collector_nonarray');
  $stmt=$db->prepare('INSERT INTO api_events(source,external_id,kind,title,occurred_at,fingerprint,payload) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE last_seen_at=CURRENT_TIMESTAMP(3),title=IF(fingerprint<>VALUES(fingerprint),VALUES(title),title),payload=IF(fingerprint<>VALUES(fingerprint),VALUES(payload),payload),fingerprint=VALUES(fingerprint),occurred_at=COALESCE(VALUES(occurred_at),occurred_at)');
  $old=$db->prepare('SELECT fingerprint FROM api_events WHERE source=? AND external_id=?');
  $aircraft=$source==='adsb.lol'?$db->prepare('INSERT IGNORE INTO api_aircraft_positions(aircraft_id,captured_at,lat,lon,altitude_m) VALUES (?,?,?,?,?)'):null;
  $roundCaptured=gmdate('Y-m-d H:i:s',(int)(floor(time()/300)*300));
  foreach($items as $item){
    if(!is_array($item))continue;
    $fetched++;
    [$s,$id,$type,$title,$time,$payload]=itemRow($source,$kind,$item);
    $hash=hash('sha256',$payload);
    $old->execute([$s,$id]);$existing=$old->fetchColumn();
    $stmt->execute([$s,$id,$type,$title,$time,$hash,$payload]);
    if($existing===false)$added++;
    elseif(!hash_equals((string)$existing,$hash))$changed++;
    if($aircraft&&is_numeric($item['lat']??null)&&is_numeric($item['lon']??null)){
      $alt=$item['altitude_ft']??null;
      $aircraft->execute([$id,$roundCaptured,(float)$item['lat'],(float)$item['lon'],is_numeric($alt)?(int)round((float)$alt*0.3048):null]);
    }
  }
  $ok=true;
 }catch(Throwable $e){$error=substr($e->getMessage(),0,80);error_log('[AAlerts collector '.$source.'] '.$error);}
 finally{
  $done=$db->prepare('UPDATE api_collector_runs SET finished_at=CURRENT_TIMESTAMP(3),ok=?,total=?,inserted=?,changed=?,error_code=? WHERE id=?');
  $done->execute([(int)$ok,$fetched,$added,$changed,$error,$rid]);
  echo $source.': '.($ok?'OK':'FAIL')." total=$fetched new=$added changed=$changed\n";
 }
}
function newsFromPolice(): array {
 $u='https://api.politie.nl/v4/nieuws?language=nl&lat=52.2112&lon=5.9699&radius=25&maxnumberofitems=25&offset=0';
 $items=entries(sourceJson($u));$out=[];
 foreach($items as $x){if(!is_array($x)||empty($x['titel']))continue;
  $text=trim(strip_tags((string)($x['introductie']??'')));
  $all=mb_strtolower(implode(' ',[$x['titel'],$x['gebied']??'',$text]));
  // Conservative local relevance check until complete worker geo matching is ported.
  if(!preg_match('/apeldoorn|beekbergen|ugchelen|uddel|teuge|hoenderloo|klarenbeek|loenen|wenum|wiesel|radio kootwijk|hoog soeren|beemte/i',$all))continue;
  $ref=(string)($x['uid']??$x['url']??$x['titel']);
  $out[]=['id'=>'p-'.substr(sha1($ref),0,12),'soort'=>'politie','bron'=>'politie','titel'=>(string)$x['titel'],
   'intro'=>$text,'at'=>$x['publicatiedatum']??null,'bron_url'=>$x['url']??null,'plaats'=>'Apeldoorn'];
 }
 return $out;
}
function newsFrom112(): array {
 if(!function_exists('simplexml_load_string'))throw new RuntimeException('simplexml_missing');
 $out=[];
 $places=['apeldoorn','beekbergen','ugchelen','loenen','hoenderloo','klarenbeek','teuge','uddel'];
 $places=['apeldoorn',$places[1+(int)(floor(time()/300)%(count($places)-1))]]; // One village per round to avoid excessive requests
 foreach($places as $slug){
  try{$xml=simplexml_load_string(sourceFetch('https://112nieuws.net/tag/'.$slug.'/feed/'),'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
   if(!$xml)continue;
   foreach($xml->channel->item??[] as $x){
    $u=(string)$x->link;if(!str_starts_with($u,'https://112nieuws.net/'))continue;
    $reference=(string)$x->guid?:$u;
    $out[]=['id'=>'r-'.substr(sha1($reference),0,12),'soort'=>'regionaal','bron'=>'112nieuws',
     'titel'=>(string)$x->title,'intro'=>mb_substr(trim(strip_tags((string)$x->description)),0,260),
     'plaats'=>ucfirst($slug),'at'=>(string)$x->pubDate,'bron_url'=>$u];
   }
  }catch(Throwable $e){error_log('112Nieuws '.$slug.': '.$e->getMessage());}
 }
 return $out;
}
try{
 $db=privateDb();
 aalertsEnsureSchema($db);
 if(setting('P2000_PAGE_API_URL')!=='')runCollector($db,'p2000.page','p2000',fn()=>normalizeP2000(entries(sourceJson(setting('P2000_PAGE_API_URL')))));
 runCollector($db,'nlalert','waarschuwingen',fn()=>normalizeNlAlert(entries(sourceJson('https://api.public-warning.app/api/v1/providers/nl-alert/alerts'))));
 runCollector($db,'burgernet','waarschuwingen',fn()=>normalizeBurgernet(entries(sourceJson('https://www.burgernet.nl/active'))));
 runCollector($db,'kind','waarschuwingen',fn()=>normalizeKind(entries(sourceJson('https://services.burgernet.nl/landactiehost/api/v1/alerts'))));
 runCollector($db,'knmi','waarschuwingen',fn()=>entries(sourceJson('https://www.knmi.nl/api/v1/weather-warnings')));
 if(setting('VERKEER_URL')!==''&&setting('VERKEER_TOKEN')!==''){
   runCollector($db,'ndw','verkeer',function(){
     $url=rtrim(setting('VERKEER_URL'),'/').'/verkeer';
     $ch=curl_init($url);
     curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,
       CURLOPT_HTTPHEADER=>['Authorization: Bearer '.setting('VERKEER_TOKEN'),'Accept: application/json']]);
     $raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
     if($code!==200||!is_string($raw))throw new RuntimeException('traffic_http_'.$code);
     $d=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
     return array_merge($d['items']??[],$d['gepland']??[]);
   });
 }
 // 5-minute cron = 288 ADS-B requests/day, within a typical free 1000/day allowance.
 // GPS coordinates and range are identical to the currently configured A-Alerts region center only approximately.
 if(setting('ADSB_LOL_API_KEY')!=='')runCollector($db,'adsb.lol','helikopters',fn()=>normalizeAircraft(entries(sourceJson('https://api.adsb.lol/v2/point/52.2112/5.9699/125',setting('ADSB_LOL_API_KEY')))));
 runCollector($db,'politie','nieuws',fn()=>newsFromPolice());
 runCollector($db,'112nieuws','nieuws',fn()=>newsFrom112());
 // Read-only reference snapshots from the existing Cloudflare Worker.
 // Rate-limited to one round every 15 minutes; no writes to Worker or Cloudflare.
 if($group==='reference'||($group==='all'&&(int)(floor(time()/300)%3)===0)){
   $worker=rtrim(setting('REFERENCE_WORKER_URL'),'/');
   if($worker!==''){
     foreach(['meldingen','waarschuwingen','nieuws','verkeer','helikopters'] as $endpoint){
       runCollector($db,'reference_'.$endpoint,'reference_'.$endpoint,function()use($worker,$endpoint){
         $d=sourceJson($worker.'/v1/'.$endpoint.($endpoint==='meldingen'||$endpoint==='nieuws'?'?limit=100':''));
         if(array_is_list($d))return $d;
         foreach(['data','meldingen','items','helikopters','waarschuwingen'] as $k)
           if(isset($d[$k])&&is_array($d[$k])&&array_is_list($d[$k]))return $d[$k];
         throw new RuntimeException('reference_shape_unverified');
       });
     }
   }
 }
}catch(Throwable $e){error_log('[AAlerts bootstrap collector] '.get_class($e));exit(1);}
