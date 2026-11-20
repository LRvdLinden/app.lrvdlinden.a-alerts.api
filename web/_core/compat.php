<?php
declare(strict_types=1);
// A-Alerts compatibility endpoints. Deliberately separate from existing disabled gateway.
// All endpoints are private; administrative writes require a separate token.
function compatAdmin(): bool {
  $token=setting('ADMIN_TOKEN');
  $header=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
  return strlen($token)>=48 && preg_match('/^Bearer ([A-Za-z0-9._~-]{48,256})$/',$header,$m)===1 && hash_equals($token,$m[1]);
}
function compatAuthorized(): bool{return privateTokenValid()||compatAdmin();}
function compatJsonInput(int $max=32768):array {
 $raw=file_get_contents('php://input',false,null,0,$max+1);
 if(!is_string($raw)||strlen($raw)>$max)privateReply(413,['error'=>'payload_too_large']);
 $d=json_decode($raw,true);
 if(!is_array($d))privateReply(400,['error'=>'invalid_json']);
 return $d;
}
function compatQuery(PDO $db,string $kind,string $id): ?array {
 $q=$db->prepare('SELECT payload FROM api_events WHERE kind=? AND external_id=? LIMIT 1');
 $q->execute([$kind,$id]);$raw=$q->fetchColumn();
 return $raw===false?null:json_decode((string)$raw,true);
}
function compatKV(PDO $db,string $key,mixed $default=null): mixed {
 $q=$db->prepare('SELECT value_json FROM api_kv WHERE key_name=?');$q->execute([$key]);
 $v=$q->fetchColumn();return $v===false?$default:json_decode((string)$v,true);
}
function compatSetKV(PDO $db,string $key,mixed $value):void{
 $q=$db->prepare('INSERT INTO api_kv(key_name,value_json) VALUES(?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json)');
 $q->execute([$key,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
function compatList(PDO $db,string $kind,int $limit=50):array {
 $q=$db->prepare('SELECT payload FROM api_events WHERE kind=? ORDER BY COALESCE(occurred_at,last_seen_at) DESC LIMIT '.min(max($limit,1),100));
 $q->execute([$kind]);$out=[];foreach($q as $r){$d=json_decode($r['payload'],true);if(is_array($d))$out[]=$d;}
 return $out;
}
function compatRecentAircraft(PDO $db,string $kind):array {
 $q=$db->prepare('SELECT payload,last_seen_at FROM api_events WHERE kind=? AND last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) ORDER BY last_seen_at DESC LIMIT 250');
 $q->execute([$kind]);$items=[];$seen=[];
 foreach($q as $row){
  $item=json_decode((string)$row['payload'],true);
  if(!is_array($item)||empty($item['id']))continue;
  $last=strtotime((string)($item['gezien']??''));
  if($last===false)$last=strtotime($row['last_seen_at'].' UTC');
  if($last!==false && time()-$last>600)continue;
  $item['verouderd']=$last===false||time()-$last>120;
  if(!isset($seen[$item['id']])){$seen[$item['id']]=true;$items[]=$item;}
 }
 return $items;
}
/**
 * Temporary bridge while the own collectors are being made complete: the exact last answer of the production Worker,
 * kept by the mirror collector (collect.php worker). Only used while it is fresh and WORKER_SPIEGEL is not "0"; without
 * it every route answers from the own data, so api.a-alerts.nl keeps working on its own when the Worker is gone.
 */
function compatMirror(PDO $db,string $route):mixed{
 if(setting('WORKER_SPIEGEL')==='0')return null;
 $q=$db->prepare('SELECT value_json,TIMESTAMPDIFF(SECOND,updated_at,CURRENT_TIMESTAMP(3)) AS age FROM api_kv WHERE key_name=?');
 $q->execute(['worker:/v1/'.$route]);$row=$q->fetch();
 if(!$row)return null;
 $max=in_array($route,['meldingen','waarschuwingen','nieuws','helikopters'],true)?300:(in_array($route,['locaties','app','veiligheidsregio','changelog'],true)?10800:1800);
 if((int)$row['age']>$max)return null;
 $x=json_decode((string)$row['value_json'],true);
 return is_array($x)&&array_key_exists('data',$x)?$x['data']:null;
}
function compatMirrorRoute(PDO $db,string $route):void{
 $plain=['meldingen','waarschuwingen','nieuws','helikopters','lucht','verkeer','status','helikopters/status','radar','locaties','app','veiligheidsregio','changelog'];
 if(!in_array($route,$plain,true))return;
 if($route==='meldingen'&&(($_GET['before']??'')!==''||(int)($_GET['limit']??100)>100))return;
 if($route==='nieuws'&&(($_GET['before']??'')!==''||($_GET['soort']??'')!==''))return;
 $d=compatMirror($db,$route);
 if(!is_array($d))return;
 if($route==='meldingen'&&array_is_list($d))$d=array_slice($d,0,min(100,max(1,(int)($_GET['limit']??100))));
 if($route==='nieuws'&&array_is_list($d)&&isset($_GET['limit']))$d=array_slice($d,0,max(1,(int)$_GET['limit']));
 // The mirror asks the Worker for everything; give each caller what it asked for, as the Worker does.
 if($route==='waarschuwingen'&&array_is_list($d))$d=array_values(array_filter($d,static fn($w)=>is_array($w)
   &&(($_GET['kind']??'')==='1'||!in_array($w['bron']??'',['amber','vermistkind'],true))
   &&(($_GET['vnog']??'')==='1'||($w['bron']??'')!=='vnog')));
 header('X-AAlerts-Bron: spiegel');
 privateReply(200,$d);
}
function compatMeldingAPI(array $m):array{
 $at=$m['datum']??null;
 $m['datum_tijd']=is_numeric($at)?gmdate('Y-m-d\\TH:i:s\\Z',(int)floor((float)$at/1000)):(string)($m['datum_tijd']??'');
 $m['provincie']='Gelderland';
 $m['views']=$m['views']??0;
 $m['categorie']=$m['categorie']??null;
 $m['incident']=$m['incident']??null;
 return $m;
}
function compatRoute(string $path):void {
 if(!str_starts_with($path,'/v1/'))return;
 $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
 // This never authenticates browser requests with a token embedded in URL.
 if(!compatAuthorized())privateReply(401,['error'=>'unauthorized']);
 try{
  $db=privateDb();
  $route=substr($path,4);
  if($method==='GET')compatMirrorRoute($db,$route);
  // Worker-compatible list response shapes: raw JSON arrays, not shadow-only envelopes.
  if($route==='meldingen'&&$method==='GET'){
    $limit=min(1000,max(1,(int)($_GET['limit']??300)));
    $before=(string)($_GET['before']??'');
    $sql="SELECT payload FROM api_events WHERE kind='p2000'";
    $args=[];
    if($before!==''){
      $dt=strtotime($before);
      if($dt===false)privateReply(400,['error'=>'invalid_before']);
      $sql.=' AND occurred_at < ?';$args[]=gmdate('Y-m-d H:i:s',$dt);
    }
    $sql.=' ORDER BY COALESCE(occurred_at,last_seen_at) DESC LIMIT '.$limit;
    $q=$db->prepare($sql);$q->execute($args);
    $data=[];foreach($q as $row){$m=json_decode($row['payload'],true);if(is_array($m))$data[]=compatMeldingAPI($m);}
    privateReply(200,$data);
  }
  if($route==='nieuws'&&$method==='GET'){
    $items=compatList($db,'nieuws',min(100,(int)($_GET['limit']??50)));
    $soort=(string)($_GET['soort']??'');
    if($soort!=='')$items=array_values(array_filter($items,static fn($r)=>(string)($r['soort']??'')===$soort));
    privateReply(200,$items);
  }
  if($route==='waarschuwingen'&&$method==='GET'){
    privateReply(200,compatList($db,'waarschuwingen',100));
  }
  if($route==='helikopters'&&$method==='GET'){
    $items=compatRecentAircraft($db,'helikopters');
    privateReply(200,['bijgewerkt'=>gmdate('c'),'bron'=>'adsb.lol','helikopters'=>$items,'inzet'=>[],'verrijking_compleet'=>false]);
  }
  if($route==='lucht'&&$method==='GET'){
    privateReply(200,['bijgewerkt'=>gmdate('c'),'bron'=>'adsb.lol','toestellen'=>compatRecentAircraft($db,'lucht')]);
  }

  // Immutable/administratively editable content
  if(preg_match('~^content/(privacy|cookies|terms|disclaimer|hulp)$~',$route,$m)){
   if($method!=='GET')privateReply(405,['error'=>'method_not_allowed']);
   privateReply(200,compatKV($db,'content:'.$m[1],['content'=>null,'title'=>$m[1]]));
  }
  if($route==='app'&&$method==='GET')
   privateReply(200,['min_build'=>compatKV($db,'min_build',['ios'=>0,'android'=>0]),'mededeling'=>compatKV($db,'mededeling')]);
  if($route==='changelog'&&$method==='GET')privateReply(200,compatKV($db,'changelog',[]));
  if($route==='veiligheidsregio'&&$method==='GET')privateReply(200,compatKV($db,'veiligheidsregio',['features'=>[]]));
  if($route==='locaties'&&$method==='GET')privateReply(200,compatKV($db,'locaties',['plaatsen'=>[]]));
  if($route==='widget/ping'&&$method==='GET')privateReply(200,['ok'=>true,'shadow'=>true]);
  if($route==='helikopters/status'&&$method==='GET'){
   $aircraft=compatList($db,'helikopters',100);
   privateReply(200,['bijgewerkt'=>gmdate('c'),'toestellen'=>$aircraft,'weer'=>compatKV($db,'heli:weather')]);
  }
  // Timeline of dispatches within the same linked incident (only when incident links have been assigned).
  if(preg_match('~^meldingen/([a-zA-Z0-9_-]{8,64})/verloop$~',$route,$m)&&$method==='GET'){
   $item=compatQuery($db,'p2000',$m[1]);if(!$item)privateReply(404,['error'=>'not_found']);
   $st=$db->prepare('SELECT incident_key FROM api_incident_links WHERE event_external_id=? LIMIT 1');$st->execute([$m[1]]);
   $key=$st->fetchColumn();$rows=[];
   if($key!==false){$q=$db->prepare('SELECT e.payload FROM api_incident_links l JOIN api_events e ON e.source=l.event_source AND e.external_id=l.event_external_id WHERE l.incident_key=? ORDER BY e.occurred_at ASC');$q->execute([$key]);foreach($q as $r)$rows[]=json_decode($r['payload'],true);}
   privateReply(200,['guid'=>$m[1],'incident'=>$key===false?null:$key,'meldingen'=>$rows?:[$item]]);
  }
  if(preg_match('~^meldingen/([a-zA-Z0-9_-]{8,64})/(routes|pand|weather)$~',$route,$m)&&$method==='GET'){
   $item=compatQuery($db,'p2000',$m[1]);if(!$item)privateReply(404,['error'=>'not_found']);
   // Never make up routes, BAG data, or WeatherKit observations.
   privateReply(503,['error'=>$m[2].'_provider_not_configured','guid'=>$m[1]]);
  }
  if(preg_match('~^waarschuwingen/([^/]+)/weather$~',$route,$m)&&$method==='GET'){
    privateReply(503,['error'=>'weatherkit_not_configured']);
  }
  if(preg_match('~^nieuws/([pwlr]-[0-9a-f]{12})$~',$route,$m)&&$method==='GET'){
   $id=$m[1];$item=null;
   $q=$db->prepare("SELECT payload FROM api_events WHERE kind='nieuws' AND (external_id=? OR JSON_UNQUOTE(JSON_EXTRACT(payload,'$.id'))=?) LIMIT 1");
   $q->execute([$id,$id]);$raw=$q->fetchColumn();if($raw!==false)$item=json_decode((string)$raw,true);
   if(!$item)privateReply(404,['error'=>'not_found']);
   $updates=$db->prepare('SELECT id,title,body,source_url,created_at FROM api_news_updates WHERE news_id=? ORDER BY created_at DESC');
   $updates->execute([$id]);
   $item['tijdlijn']=array_map(static fn($r)=>['id'=>(int)$r['id'],'titel'=>$r['title'],'tekst'=>$r['body'],'url'=>$r['source_url'],'at'=>$r['created_at']],$updates->fetchAll());
   privateReply(200,$item);
  }
  if(preg_match('~^waarschuwingen/([^/]+)$~',$route,$m)&&$method==='GET'){
   $id=rawurldecode($m[1]);$kind=compatQuery($db,'waarschuwingen',$id);
   if(!$kind){$q=$db->prepare("SELECT payload FROM api_events WHERE kind='waarschuwingen' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.id'))=? LIMIT 1");$q->execute([$id]);$v=$q->fetchColumn();$kind=$v===false?null:json_decode((string)$v,true);}
   if(!$kind)privateReply(404,['error'=>'not_found']);
   $kind['meldingen']=[];privateReply(200,$kind);
  }
  if(preg_match('~^helikopters/([a-z0-9-]{3,40})/(spoor|vluchten)$~',$route,$m)&&$method==='GET'){
   $scope=(string)($_GET['scope']??'vlucht');$hours=$scope==='vandaag'?24:($scope==='kort'?1:6);
   $q=$db->prepare('SELECT captured_at,lat,lon,altitude_m FROM api_aircraft_positions WHERE aircraft_id=? AND captured_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL '.$hours.' HOUR) ORDER BY captured_at ASC LIMIT 2000');
   $q->execute([$m[1]]);$points=$q->fetchAll();
   privateReply(200,$m[2]==='spoor'?['toestel'=>$m[1],'scope'=>$scope,'punten'=>$points]:['toestel'=>$m[1],'vluchten'=>[],'punten'=>$points,'provisional'=>true]);
  }
  if(preg_match('~^posten/([a-z0-9_-]{3,60})$~',$route,$m)&&$method==='GET'){
   privateReply(503,['error'=>'station_registry_not_populated']);
  }
  if($route==='radar'&&$method==='GET')privateReply(200,compatKV($db,'radar',['beschikbaar'=>false,'error'=>'radar_provider_not_configured']));
  if($route==='mapkit-token'&&$method==='GET')privateReply(503,['error'=>'mapkit_not_configured']);
  if($route==='radar.png'||$route==='kaart.png'||preg_match('~^radar/\d{1,2}/\d{1,5}/\d{1,5}\.png$~',$route))
   privateReply(503,['error'=>'image_provider_not_configured']);
  // Anonymous mobile events must not be accepted under an owner master token.
  if(in_array($route,['installs','downloads','visit','devices'],true)||preg_match('~^devices/|^meldingen/[^/]+/(view|feedback)$|^helikopters/[^/]+/melden$~',$route))
   privateReply(503,['error'=>'mobile_write_flow_not_migrated']);
  if(str_starts_with($route,'beheer/')){
   if(!compatAdmin())privateReply(403,['error'=>'admin_token_required']);
   compatAdminRoute($db,substr($route,7),$method);
  }
 }catch(Throwable $e){error_log('[AAlerts compat] '.get_class($e));privateReply(503,['error'=>'compat_backend_unavailable']);}
}
function compatAdminRoute(PDO $db,string $route,string $method):never{
 if($route==='ping'&&$method==='GET')privateReply(200,['configured'=>true]);
 if($route==='me'&&$method==='GET')privateReply(200,['user'=>'api-admin','expires'=>null]);
 if(in_array($route,['overview','status'],true)&&$method==='GET'){
   $events=(int)$db->query('SELECT COUNT(*) FROM api_events')->fetchColumn();
   privateReply(200,['events'=>$events,'sources'=>$db->query('SELECT source,COUNT(*) total FROM api_events GROUP BY source')->fetchAll(),'shadow'=>true]);
 }
 if($route==='nieuws'&&$method==='GET')privateReply(200,compatList($db,'nieuws',100));
 if($route==='waarschuwingen'&&$method==='GET')privateReply(200,compatList($db,'waarschuwingen',100));
 if($route==='meldingen'&&$method==='GET')privateReply(200,compatList($db,'p2000',100));
 if($route==='feedback'&&$method==='GET')privateReply(200,$db->query('SELECT id,target,reason,note,status,created_at FROM api_feedback ORDER BY id DESC LIMIT 100')->fetchAll());
 if(preg_match('~^content/(privacy|cookies|terms|disclaimer|hulp|mededeling|changelog)$~',$route,$m)){
   if($method==='GET')privateReply(200,compatKV($db,'content:'.$m[1],[]));
   if($method==='PUT'){compatSetKV($db,'content:'.$m[1],compatJsonInput(65536));privateReply(200,['ok'=>true]);}
   if($method==='DELETE'){compatSetKV($db,'content:'.$m[1],null);privateReply(200,['ok'=>true]);}
 }
 if(preg_match('~^nieuws/([pwlr]-[0-9a-f]{12})/updates$~',$route,$m)&&$method==='POST'){
   $body=compatJsonInput(65536);
   $stmt=$db->prepare('INSERT INTO api_news_updates(news_id,title,body,source_url) VALUES(?,?,?,?)');
   $stmt->execute([$m[1],substr((string)($body['titel']??''),0,255),substr((string)($body['tekst']??''),0,30000),(string)($body['url']??'')]);
   privateReply(201,['ok'=>true,'id'=>(int)$db->lastInsertId(),'push_sent'=>false]);
 }
 if(preg_match('~^feedback/(\d+)$~',$route,$m)){
   if(in_array($method,['PATCH','POST'],true)){
     $body=compatJsonInput();$state=(string)($body['status']??'');
     if(!in_array($state,['open','resolved','ignored'],true))privateReply(400,['error'=>'invalid_status']);
     $q=$db->prepare('UPDATE api_feedback SET status=? WHERE id=?');$q->execute([$state,(int)$m[1]]);
     privateReply(200,['ok'=>$q->rowCount()>0]);
   }
   if($method==='DELETE'){$q=$db->prepare('DELETE FROM api_feedback WHERE id=?');$q->execute([(int)$m[1]]);privateReply(200,['ok'=>$q->rowCount()>0]);}
 }
 // Intentionally do not simulate push, device management, or operational incident edits.
 privateReply(501,['error'=>'admin_endpoint_not_implemented','route'=>$route]);
}
