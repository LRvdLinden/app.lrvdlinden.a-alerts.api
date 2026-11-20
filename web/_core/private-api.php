<?php
declare(strict_types=1);
// Private API code: web/.htaccess must deny direct /_core/ access.
function privateConfig(): array {
  static $settings=null;
  if($settings!==null)return $settings;
  $path=getenv('AALERTS_PRIVATE_CONFIG')?:'';
  if(!$path){
    // Works for Apache and CLI cron. Resolve via this source's actual server path;
    // never read configuration from a web-visible directory.
    foreach ([(string)($_SERVER['DOCUMENT_ROOT']??''), (string)realpath(__DIR__)] as $candidate){
      if(preg_match('~^(/home[^/]*/[^/]+)(?:/|$)~',$candidate,$matches)){
        $path=$matches[1].'/aalerts-private/config.php';
        break;
      }
    }
  }
  // IMPORTANT: operator must verify actual path and keep this file outside docroot.
  $settings=($path!==''&&is_file($path))?require $path:[];
  if(!is_array($settings))$settings=[];
  // Separate source secrets are installed by GitHub Actions without rewriting DB credentials.
  // Keys of the Worker, transferred once (aa/overdracht.php). The files below (sources.php) win when both name a key.
  $ws=$path!==''?dirname($path).'/worker-secrets.php':'';
  if($ws!==''&&is_file($ws)){$w=require $ws;if(is_array($w))$settings=array_merge($settings,$w);}
  $sources=$path!==''?dirname($path).'/sources.php':'';
  if($sources!==''&&is_file($sources)){
    $more=require $sources;
    if(is_array($more))$settings=array_replace($settings,$more);
  }
  return $settings;
}
function setting(string $key): string {
  $cfg=privateConfig();
  return (string)($cfg[$key]??getenv($key)?:'');
}
function privateDb(): PDO {
  static $db=null;if($db)return $db;
  foreach(['DB_HOST','DB_NAME','DB_USER','DB_PASS'] as $k)if(setting($k)==='')throw new RuntimeException('database_unconfigured');
  return $db=new PDO('mysql:host='.setting('DB_HOST').';dbname='.setting('DB_NAME').';charset=utf8mb4',
    setting('DB_USER'),setting('DB_PASS'),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
function privateReply(int $status,array $body): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: private, no-store');
  header('X-Robots-Tag: noindex, nofollow');
  header('Referrer-Policy: no-referrer');
  header('X-Content-Type-Options: nosniff');
  echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}
function privateTokenValid(): bool {
  $expected=setting('READ_TOKEN');
  $got=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
  if(strlen($expected)<48||!preg_match('/^Bearer ([A-Za-z0-9._~-]{48,256})$/',$got,$match))return false;
  return hash_equals($expected,$match[1]);
}
function apiRoute(string $path): bool {
  if(!str_starts_with($path,'/v1/'))return false;
  $routes=['meldingen'=>'p2000','nieuws'=>'nieuws','waarschuwingen'=>'waarschuwingen','verkeer'=>'verkeer','helikopters'=>'helikopters','lucht'=>'lucht'];
  $endpoint=trim(substr($path,4),'/');
  $first=explode('/',$endpoint)[0]??'';
  if(!in_array($first,array_merge(array_keys($routes),['status','bronnen','vergelijk','spiegel','eigen']),true))return false;
  // All private routes fail closed even before cPanel configuration.
  if(!privateTokenValid())privateReply(401,['error'=>'unauthorized']);
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')privateReply(405,['error'=>'method_not_allowed']);
  try {
    $db=privateDb();
    if($endpoint==='status'||$endpoint==='bronnen'){
      $sql="SELECT source, MAX(finished_at) as updated_at, SUBSTRING_INDEX(GROUP_CONCAT(error_code ORDER BY id DESC),',',1) AS last_error FROM api_collector_runs GROUP BY source";
      // Separate count query avoids ambiguous GROUP_CONCAT null handling.
      $rows=$db->query('SELECT source, MAX(finished_at) AS updated_at, SUM(ok=1) AS successful_runs, COUNT(*) AS total_runs FROM api_collector_runs GROUP BY source')->fetchAll();
      privateReply(200,['shadow'=>true,'collectors'=>$rows,'database'=>true]);
    }
    // The own engine: what came in through the own intake, and what the Worker has that the own intake missed.
    if($first==='eigen'){
      require_once __DIR__.'/aa/meldingen.php';
      aaEnsureSchema($db);
      $kv=[];foreach(['koyeb_last_connected','koyeb_last_message','koyeb_last_added','koyeb_last_error','koyeb_last_error_text','koyeb_run','aa_koyeb_start','aa_import'] as $k){$v=aaKvGet($k);$kv[$k]=$v===null?null:(is_numeric($v)&&strlen($v)>=12?aaIso((int)$v):(json_decode($v,true)??$v));}
      $start=(int)(aaKvGet('aa_koyeb_start')??0);
      $per=$db->query('SELECT COALESCE(bron,"onbekend") AS bron, COUNT(*) AS n, MAX(datum) AS laatste FROM meldingen GROUP BY bron')->fetchAll();
      foreach($per as &$r){$r['n']=(int)$r['n'];$r['laatste']=$r['laatste']?aaIso((int)$r['laatste']):null;} unset($r);
      $mist=[];$spiegel=0;
      if($start){
        // Worker alerts since the own intake started that are not in the own table (by guid or by the same dispatch).
        $q=$db->query("SELECT payload FROM api_events WHERE kind='p2000' AND source LIKE 'worker.%'");
        $own=$db->prepare('SELECT guid, datum, stad, dienst, full_melding, title, capcode FROM meldingen WHERE guid = ? OR (datum BETWEEN ? AND ? AND stad = ?)');
        foreach($q as $row){$x=json_decode((string)$row['payload'],true);if(!is_array($x))continue;$d=aaMs($x['datum_tijd']??null);if($d===null||$d<$start+60000)continue;
          $spiegel++; $x['datum']=$d; $own->execute([$x['guid'],$d-120000,$d+120000,$x['stad']??'']);
          $ok=false;foreach($own->fetchAll() as $o){if($o['guid']===$x['guid']||aaSameDispatch($x,$o)){$ok=true;break;}}
          if(!$ok&&count($mist)<20)$mist[]=['guid'=>$x['guid'],'datum_tijd'=>$x['datum_tijd'],'dienst'=>$x['dienst']??null,'stad'=>$x['stad']??null,'title'=>$x['title']??null];
          elseif(!$ok)$mist[]=null;
        }
      }
      $missing=count($mist);
      privateReply(200,['shadow'=>true,'p2000'=>['intake'=>$kv,'per_bron'=>$per,'worker_sinds_start'=>$spiegel,'ontbreekt_bij_eigen'=>$missing,
        'voorbeelden_ontbrekend'=>array_values(array_filter($mist)),'push'=>setting('PUSH_ACTIEF')==='1'?'aan':'uit (de Worker verstuurt de pushmeldingen)']]);
    }
    // Exact last answer of a Worker route, as stored by the mirror ("spiegel/meldingen", "spiegel/helikopters/status").
    if($first==='spiegel'){
      $name=substr($endpoint,8);
      if($name===''){
        $rows=$db->query("SELECT key_name,updated_at FROM api_kv WHERE key_name LIKE 'worker:%' ORDER BY key_name")->fetchAll();
        privateReply(200,['shadow'=>true,'spiegel'=>array_map(static fn($r)=>['route'=>substr($r['key_name'],7),'bijgewerkt'=>$r['updated_at']],$rows)]);
      }
      if(!preg_match('#^[a-z0-9/-]{2,60}$#',$name))privateReply(400,['error'=>'invalid_route']);
      $q=$db->prepare('SELECT value_json,updated_at FROM api_kv WHERE key_name=?');$q->execute(['worker:/v1/'.$name]);$row=$q->fetch();
      if(!$row)privateReply(404,['error'=>'not_mirrored']);
      $x=json_decode((string)$row['value_json'],true);
      privateReply(200,['shadow'=>true,'route'=>'/v1/'.$name,'bijgewerkt'=>$row['updated_at'],'data'=>$x['data']??null]);
    }
    if($endpoint==='vergelijk'){
      $groups=['meldingen'=>'p2000','nieuws'=>'nieuws','waarschuwingen'=>'waarschuwingen','verkeer'=>'verkeer','helikopters'=>'helikopters'];
      $result=[];foreach($groups as $key=>$g){
        $ref='reference_'.$key;
        $own=$db->prepare("SELECT COUNT(*) FROM api_events WHERE kind=? AND source NOT LIKE 'worker.%'");
        $own->execute([$g]);$ownCount=(int)$own->fetchColumn();
        $mirror=$db->prepare("SELECT COUNT(*) FROM api_events WHERE kind=? AND source LIKE 'worker.%'");$mirror->execute([$g]);$mirrorCount=(int)$mirror->fetchColumn();
        $reference=$db->prepare('SELECT COUNT(*) FROM api_events WHERE kind=?');
        $reference->execute([$ref]);$refCount=(int)$reference->fetchColumn();
        $missing=$db->prepare("SELECT COUNT(*) FROM api_events r LEFT JOIN api_events o ON o.kind=? AND o.external_id=r.external_id AND o.source LIKE 'worker.%' WHERE r.kind=? AND o.id IS NULL");
        $missing->execute([$g,$ref]);$missingCount=(int)$missing->fetchColumn();
        $result[$key]=['eigen'=>$ownCount,'spiegel'=>$mirrorCount,'worker'=>$refCount,'ontbrekend_in_spiegel'=>(int)$missingCount,'vergelijking'=>'indication_only'];
      }
      privateReply(200,['shadow'=>true,'vergelijking'=>$result,'parity'=>'unverified','note'=>'Different collection windows and IDs; full 1:1 field validation pending']);
    }
    if(!isset($routes[$first]))privateReply(404,['error'=>'not_found']);
    $kind=$routes[$first];
    $limit=min(100,max(1,(int)($_GET['limit']??50)));
    $source=(string)($_GET['bron']??'');
    $sort=(string)($_GET['soort']??'');
    $id=substr($endpoint,strlen($first)+1);
    $filters=['kind=?'];$args=[$kind];
    if($source!==''){if(!preg_match('/^[a-z0-9_.-]{2,48}$/',$source))privateReply(400,['error'=>'invalid_source']);$filters[]='source=?';$args[]=$source;}
    if($sort!==''){if(!preg_match('/^[a-z0-9_-]{2,48}$/',$sort))privateReply(400,['error'=>'invalid_kind']);$filters[]="JSON_UNQUOTE(JSON_EXTRACT(payload,'$.soort'))=?";$args[]=$sort;}
    if($id!==''){if(strlen($id)>191)privateReply(400,['error'=>'invalid_id']);$filters[]='external_id=?';$args[]=rawurldecode($id);}
    $query=$db->prepare('SELECT source,external_id,kind,title,occurred_at,first_seen_at,last_seen_at,payload FROM api_events WHERE '.implode(' AND ',$filters).' ORDER BY COALESCE(occurred_at,last_seen_at) DESC LIMIT '.$limit);
    $query->execute($args);
    $items=[];foreach($query as $row){$x=json_decode($row['payload'],true);$items[]=is_array($x)?$x:['id'=>$row['external_id'],'titel'=>$row['title']];}
    privateReply(200,['shadow'=>true,'data'=>$items,'count'=>count($items),'source'=>$kind]);
  } catch(Throwable $e){error_log('AAlerts private API: '.get_class($e));privateReply(503,['error'=>'private_api_unavailable']);}
}
