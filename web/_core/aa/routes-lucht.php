<?php
declare(strict_types=1);
/**
 * Routes of aircraft, traffic, radar and the map image (index.js), with the same paths, status codes and JSON as the
 * Worker. Called from routes.php (aaRoute) before the other routes.
 */
function aaRouteLucht(string $path): void {
  if(!preg_match('#^/v1/(helikopters|lucht|verkeer|radar|kaart\.png)#',$path))return;
  $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
  if($method==='OPTIONS')aaReply(204,null);
  try{
    if($method==='GET'&&$path==='/v1/helikopters'){ require_once __DIR__.'/heli-lib.php'; aaReply(200,aaHelikoptersAntwoord(),['Cache-Control'=>'public, max-age=10']); }
    if($method==='GET'&&$path==='/v1/lucht'){ require_once __DIR__.'/heli-lib.php'; $a=aaLuchtAntwoord();
      // PH-GOV worldwide from jet_posities (replaces the Dutch-only record when newer); Max (PH-UTL) only with ?bijzonder=1 (112Heli).
      try{ require_once __DIR__.'/jet.php'; $j=aaJetToestellen(($_GET['bijzonder']??'')==='1');
        foreach($j as $jt){ $oud=null; foreach($a['toestellen'] as $i=>$t)if(($t['hex']??'')===$jt['hex']||($t['registratie']??'')===$jt['registratie'])$oud=$i;
          if($oud===null)$a['toestellen'][]=$jt; elseif((aaMs($a['toestellen'][$oud]['gezien']??null)??0)<(aaMs($jt['gezien'])??0))$a['toestellen'][$oud]=$jt; }
      }catch(Throwable $e){ error_log('[aa jet] '.$e->getMessage()); }
      // ?bijzonder=1 (112Heli): a hiccup of the helicopter source must not throw away the jets. The list then only holds
      // what is current (the jets from jet_posities), without "fout"/"verouderd", and says so in "alleen_jets".
      if(($_GET['bijzonder']??'')==='1'&&(!empty($a['fout'])||!empty($a['verouderd']))){
        $a['toestellen']=array_values(array_filter($a['toestellen'],fn($t)=>!empty($t['wereldwijd'])));
        unset($a['fout'],$a['verouderd']); $a['alleen_jets']=true; }
      aaReply(200,$a,['Cache-Control'=>'public, max-age=10']); }
    if($method==='GET'&&$path==='/v1/helikopters/status'){ require_once __DIR__.'/heli-lib.php'; aaReply(200,aaHeliStatusAntwoord(),['Cache-Control'=>'public, max-age=30']); }
    if($method==='GET'&&preg_match('#^/v1/helikopters/([a-z0-9-]{3,40})/spoor$#',$path,$m)){
      require_once __DIR__.'/heli-lib.php'; $s=(string)($_GET['scope']??''); $s=in_array($s,['vlucht','kort','vandaag'],true)?$s:'vlucht';
      aaReply(200,aaSpoor($m[1],$s),['Cache-Control'=>'public, max-age=10']);
    }
    if($method==='GET'&&preg_match('#^/v1/helikopters/([a-z0-9-]{3,40})/vluchten$#',$path,$m)){ require_once __DIR__.'/heli-lib.php'; aaReply(200,aaVluchten($m[1]),['Cache-Control'=>'public, max-age=30']); }
    if($method==='POST'&&preg_match('#^/v1/helikopters/([a-z0-9-]{3,40})/melden$#',$path,$m)){ require_once __DIR__.'/lucht-feedback.php'; aaReply(200,aaToestelMelden($m[1])); }
    // KNMI warning areas of all of the Netherlands (map layer "Weer in Nederland"); refreshed when the KNMI changes.
    if($method==='GET'&&$path==='/v1/weer/landelijk'){ $k=aaParse(aaKvGet('knmi_landelijk'),null); $nu=aaNow();
      $items=array_values(array_filter(is_array($k['items']??null)?$k['items']:[],fn($i)=>(aaMs($i['einde']??null)??0)>=$nu));
      foreach($items as &$i)$i['status']=(aaMs($i['start'])??0)>$nu?'verwacht':'actief'; unset($i);
      aaReply(200,['bijgewerkt'=>is_array($k)?aaIso((int)($k['at']??0)):null,'items'=>$items,'bron'=>'KNMI','link'=>'https://www.knmi.nl/nederland-nu/weer/waarschuwingen'],['Cache-Control'=>'public, max-age=120']); }
    if($method==='GET'&&$path==='/v1/verkeer'){ require_once __DIR__.'/verkeer-lib.php';
      $bb=array_map('floatval',array_filter(explode(',',(string)($_GET['bbox']??'')),'is_numeric')); $bb=count($bb)===4?$bb:null;
      aaReply(200,aaVerkeer(($_GET['landelijk']??'')==='1',$bb),['Cache-Control'=>'public, max-age=60']); }
    $base='https://'.preg_replace('/[^a-z0-9.:-]/i','',(string)($_SERVER['HTTP_HOST']??'api.a-alerts.nl'));
    if($method==='GET'&&$path==='/v1/radar'){ require_once __DIR__.'/kaart-lib.php'; aaReply(200,aaRadarInfo($base),['Cache-Control'=>'public, max-age=60']); }
    if($method==='GET'&&$path==='/v1/radar.png'){ require_once __DIR__.'/kaart-lib.php'; aaRadarImage(); }
    if($method==='GET'&&preg_match('#^/v1/radar/(\d{1,2})/(\d{1,5})/(\d{1,5})\.png$#',$path,$m)){ require_once __DIR__.'/kaart-lib.php'; aaRadarTile((int)$m[1],(int)$m[2],(int)$m[3]); }
    if($method==='GET'&&$path==='/v1/kaart.png'){ require_once __DIR__.'/kaart-lib.php'; aaKaartImage($_GET); }
  }catch(AaHttpFout $e){
    aaReply($e->status,['error'=>$e->getMessage()]);
  }catch(Throwable $e){
    error_log('[aa lucht route] '.get_class($e).' '.$e->getMessage());
    aaReply(500,['error'=>'Server error']);
  }
}
if(!class_exists('AaHttpFout')){ final class AaHttpFout extends RuntimeException { public function __construct(public int $status, string $m){ parent::__construct($m); } } }
