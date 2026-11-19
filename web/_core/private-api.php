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
  $routes=['meldingen'=>'p2000','nieuws'=>'nieuws','waarschuwingen'=>'waarschuwingen','verkeer'=>'verkeer','helikopters'=>'helikopters','lucht'=>'helikopters'];
  $endpoint=trim(substr($path,4),'/');
  $first=explode('/',$endpoint)[0]??'';
  if(!in_array($first,array_merge(array_keys($routes),['status','bronnen','vergelijk']),true))return false;
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
    if($endpoint==='vergelijk'){
      $groups=['meldingen'=>'p2000','nieuws'=>'nieuws','waarschuwingen'=>'waarschuwingen','verkeer'=>'verkeer','helikopters'=>'helikopters'];
      $result=[];foreach($groups as $key=>$g){
        $ref='reference_'.$key;
        $own=$db->prepare('SELECT COUNT(*) FROM api_events WHERE kind=?');
        $own->execute([$g]);$ownCount=(int)$own->fetchColumn();
        $reference=$db->prepare('SELECT COUNT(*) FROM api_events WHERE kind=?');
        $reference->execute([$ref]);$refCount=(int)$reference->fetchColumn();
        $missing=$db->prepare('SELECT COUNT(*) FROM api_events r LEFT JOIN api_events o ON o.kind=? AND o.external_id=r.external_id WHERE r.kind=? AND o.id IS NULL');
        $missing->execute([$g,$ref]);$missingCount=(int)$missing->fetchColumn();
        $result[$key]=['eigen'=>$ownCount,'worker'=>$refCount,'ontbrekend_op_id'=>(int)$missingCount,'vergelijking'=>'indication_only'];
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
