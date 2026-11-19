<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/normalize.php';
$lock=fopen(directory().'/.collector.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
$now=gmdate('c');
function collect(string $name, callable $callback): void {
  try{$items=$callback();if(!is_array($items))throw new RuntimeException('invalid data');
    saveSnapshot($name,['source'=>$name,'items'=>uniqueItems($items),'error'=>null]);
    echo "$name: ".count($items)." items\n";
  }catch(Throwable $e){error_log("[shadow:$name] ".get_class($e).": ".$e->getMessage());
    // Preserve the old snapshot; mark it stale rather than losing previous data.
    $old=loadSnapshot($name);$old['error']='collector_failed';saveSnapshot($name,$old);
    echo "$name: failed\n";
  }
}
function feed(string $url,string $token=''): array{
  $data=jsonPayload(fetchRemote($url,$token)['body']);
  if(is_array($data)&&array_is_list($data))return $data;
  foreach(['data','results','meldingen','items','alerts'] as $k)if(isset($data[$k])&&is_array($data[$k])&&array_is_list($data[$k]))return $data[$k];
  throw new RuntimeException('unexpected feed shape');
}
function article(string $id,string $type,string $title,string $intro,string $url,string $place='',string $published=''):array {
  return ['id'=>$id,'soort'=>$type,'titel'=>$title,'intro'=>$intro,'url'=>$url,'plaats'=>$place,'at'=>$published,'bron'=>$type];
}
// Source values come only from operator configuration, never an HTTP query.
if(setting('P2000_PAGE_API_URL')!=='')collect('p2000',fn()=>shadowP2000(feed(setting('P2000_PAGE_API_URL'))));
collect('nlalert',fn()=>feed('https://api.public-warning.app/api/v1/providers/nl-alert/alerts'));
collect('kind',fn()=>feed('https://services.burgernet.nl/landactiehost/api/v1/alerts'));
collect('burgernet',fn()=>feed('https://www.burgernet.nl/active'));
collect('knmi',fn()=>feed('https://www.knmi.nl/api/v1/weather-warnings'));
// Group source-specific warnings; KNMI stays raw until its geo/time parser is parity-verified.
$warnings=shadowWarnings(loadSnapshot('nlalert')['items']??[],loadSnapshot('burgernet')['items']??[],loadSnapshot('knmi')['items']??[],loadSnapshot('kind')['items']??[]);
saveSnapshot('waarschuwingen',['source'=>'waarschuwingen','items'=>$warnings,'error'=>'KNMI normalization and regional filtering incomplete']);
if(setting('VERKEER_URL')!==''&&setting('VERKEER_TOKEN')!=='')
 collect('verkeer',fn()=>shadowTraffic(jsonPayload(fetchRemote(rtrim(setting('VERKEER_URL'),'/').'/verkeer',setting('VERKEER_TOKEN'))['body'])));
if(setting('AALERTS_ADSB_URL')!=='')collect('helikopters',fn()=>shadowAircraft(jsonPayload(fetchRemote(setting('AALERTS_ADSB_URL'),setting('AALERTS_ADSB_TOKEN'))['body'])));
collect('nieuws_politie',function(){
  $url='https://api.politie.nl/v4/nieuws?language=nl&lat=52.21&lon=5.96&radius=25&maxnumberofitems=25&offset=0';
  $data=jsonPayload(fetchRemote($url)['body']);$out=[];
  foreach($data['nieuwsberichten']??[] as $n){
    if(!is_array($n)||empty($n['titel']))continue;
    $out[]=article('p-'.substr(sha1((string)($n['uid']??$n['url']??$n['titel'])),0,12),'politie',
      (string)$n['titel'],trim(strip_tags((string)($n['introductie']??''))),(string)($n['url']??''),'',(string)($n['publicatiedatum']??''));
  }return $out;
});
collect('nieuws_112',function(){
  if(!function_exists('simplexml_load_string'))throw new RuntimeException('SimpleXML extension required');
  $places=['apeldoorn','beekbergen','ugchelen','loenen','hoenderloo','klarenbeek','teuge','uddel'];
  $out=[];foreach($places as $slug){
    $body=fetchRemote('https://112nieuws.net/tag/'.$slug.'/feed/')['body'];
    $xml=simplexml_load_string($body,'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
    if($xml===false)continue;
    foreach($xml->channel->item??[] as $item){
      $url=(string)$item->link;
      if(!str_starts_with($url,'https://112nieuws.net/'))continue;
      $out[]=article('r-'.substr(sha1((string)($item->guid ?: $url)),0,12),'regionaal',
       (string)$item->title,mb_substr(trim(strip_tags((string)$item->description)),0,260),$url,$slug,(string)$item->pubDate);
    }
  }return $out;
});
$news=array_merge(loadSnapshot('nieuws_politie')['items']??[],loadSnapshot('nieuws_112')['items']??[]);
saveSnapshot('nieuws',['source'=>'nieuws','items'=>uniqueItems($news),'error'=>'derived KNMI/liveblogs not yet implemented; regional filtering not parity-validated']);
foreach(['meldingen','waarschuwingen','nieuws','verkeer','helikopters'] as $section){
  $url=rtrim(setting('AALERTS_REFERENCE_URL'),' /');
  if($url==='')continue;
  $route=$section==='helikopters'?'helikopters':$section;
  collect('reference_'.$section,function()use($url,$route){
    $d=jsonPayload(fetchRemote($url.'/v1/'.$route.'?limit=100')['body']);
    if(array_is_list($d))return $d;
    foreach(['data','meldingen','items','helikopters'] as $key)if(isset($d[$key])&&is_array($d[$key]))return $d[$key];
    throw new RuntimeException('worker response shape unrecognized');
  });
}
echo "Shadow collections completed. No live systems modified.\n";
