<?php
declare(strict_types=1);
/**
 * Push pipeline (index.js queue/drain, rules.js matches/payloadFor, profielen.js, alerts.js wantsPush/payloadForWarning,
 * extra.js grootWanted, nieuws.js payloadVoorNieuws): devices, preferences, matching, the outbox and sending.
 * Sending from the outbox only runs when PUSH_ACTIEF is "1" in the private configuration: while the Cloudflare Worker is
 * production it sends every push, and a second sender would notify everybody twice.
 */
require_once __DIR__.'/apple.php';
require_once __DIR__.'/fcm.php';
require_once __DIR__.'/waarschuwingen.php';

/** PUSH_ACTIEF: "1" = every device (after the switch); "eigen" = only devices that registered here themselves (the
 * TestFlight test, while the Worker still serves everybody else); anything else = nothing is sent. */
function aaPushModus(): string { $v=setting('PUSH_ACTIEF'); return $v==='1'?'alle':($v==='eigen'?'eigen':'uit'); }
function aaPushActief(): bool { return aaPushModus()!=='uit'; }

// ------------------------------------------------------------------ devices (db.js, rules.js deviceFrom)
function aaDeviceRow(?array $d): ?array {
  if(!$d)return null;
  foreach(['plaatsen','diensten','prioriteiten'] as $k)$d[$k]=aaParse($d[$k]??'[]',[]);
  foreach(['enabled','quiet','critical','quiet_from','quiet_to'] as $k)if(isset($d[$k]))$d[$k]=(int)$d[$k];
  foreach(['radius_km','lat','lon'] as $k)$d[$k]=$d[$k]===null?null:(float)$d[$k];
  return $d;
}
function aaActiveDevices(): array { return array_map('aaDeviceRow',aaDb()->query('SELECT * FROM devices WHERE enabled = 1'.(aaPushModus()==='eigen'?' AND eigen = 1':''))->fetchAll()); }
function aaGetDevice(string $token): ?array { $q=aaDb()->prepare('SELECT * FROM devices WHERE token = ?'); $q->execute([$token]); return aaDeviceRow($q->fetch()?:null); }
function aaDeleteDevice(string $token): bool {
  $q=aaDb()->prepare('DELETE FROM devices WHERE token = ?'); $q->execute([$token]); $n=$q->rowCount();
  aaDb()->prepare('DELETE FROM outbox WHERE token = ?')->execute([$token]); return $n>0;
}
/** Path part → devices.token: hex tokens lower case, Android tokens keep their case. */
function aaDeviceKey(string $s): string { $t=rawurldecode($s); return preg_match('/^fcm:/i',$t)?'fcm:'.substr($t,4):strtolower($t); }
final class AaHttpError extends RuntimeException { public function __construct(public int $status, string $m){ parent::__construct($m); } }
function aaPushStrList(mixed $v, int $max=40): array { return is_array($v)?array_slice(array_values(array_filter($v,fn($x)=>is_string($x)&&mb_strlen($x)<64)),0,$max):[]; }
function aaPushNum(mixed $v, float $min, float $max): int|float|null { return (is_int($v)||is_float($v))&&is_finite((float)$v)&&$v>=$min&&$v<=$max?$v:null; }
function aaDeviceFrom(array $body): array {
  $android=($body['platform']??null)==='android'; $raw=(string)($body['token']??'');
  if($android&&!aaValidFcmToken($raw))throw new AaHttpError(400,'Invalid token');
  $token=$android?AA_FCM_PREFIX.$raw:strtolower($raw);
  if(!$android&&!preg_match('/^[0-9a-f]{64,200}$/',$token))throw new AaHttpError(400,'Invalid token');
  $s=is_array($body['settings']??null)?$body['settings']:[];
  $radius=aaPushNum($s['radius_km']??null,0.5,100); $lat=aaPushNum($s['lat']??null,-90,90); $lon=aaPushNum($s['lon']??null,-180,180);
  return ['token'=>$token,'platform'=>$android?'android':(($body['platform']??null)==='macos'?'macos':'ios'),
    'environment'=>$android?'fcm':(($body['environment']??null)==='sandbox'?'sandbox':'production'),
    'language'=>is_string($body['language']??null)?mb_substr($body['language'],0,8):'nl','app_version'=>is_string($body['app_version']??null)?mb_substr($body['app_version'],0,32):null,
    'enabled'=>($s['enabled']??null)!==false,'plaatsen'=>aaPushStrList($s['plaatsen']??null),'diensten'=>aaPushStrList($s['diensten']??null),
    'prioriteiten'=>array_map('strtoupper',aaPushStrList($s['prioriteiten']??null)),'quiet'=>(bool)($s['quiet']??false),
    'quiet_from'=>aaPushNum($s['quiet_from']??null,0,1439)??1380,'quiet_to'=>aaPushNum($s['quiet_to']??null,0,1439)??420,'critical'=>($s['critical']??null)!==false,
    'radius_km'=>$radius&&$lat!==null&&$lon!==null?$radius:null,'lat'=>$radius&&$lat!==null?round($lat*1000)/1000:null,'lon'=>$radius&&$lon!==null?round($lon*1000)/1000:null];
}
function aaUpsertDevice(array $d): void {
  $now=aaNow();
  aaDb()->prepare('INSERT INTO devices (token, platform, environment, language, app_version, enabled, plaatsen, diensten, prioriteiten, quiet, quiet_from, quiet_to, critical, radius_km, lat, lon, created_at, updated_at, eigen)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE eigen=1, platform=VALUES(platform), environment=VALUES(environment), language=VALUES(language), app_version=VALUES(app_version),
    enabled=VALUES(enabled), plaatsen=VALUES(plaatsen), diensten=VALUES(diensten), prioriteiten=VALUES(prioriteiten), quiet=VALUES(quiet), quiet_from=VALUES(quiet_from), quiet_to=VALUES(quiet_to),
    critical=VALUES(critical), radius_km=VALUES(radius_km), lat=VALUES(lat), lon=VALUES(lon), updated_at=VALUES(updated_at)')
    ->execute([$d['token'],$d['platform'],$d['environment'],$d['language'],$d['app_version'],$d['enabled']?1:0,aaJson($d['plaatsen']),aaJson($d['diensten']),aaJson($d['prioriteiten']),
      $d['quiet']?1:0,$d['quiet_from'],$d['quiet_to'],$d['critical']?1:0,$d['radius_km'],$d['lat'],$d['lon'],$now,$now]);
}

// ------------------------------------------------------------------ preferences
function aaNormLevel(mixed $v): string { $s=mb_strtolower((string)($v??'')); return in_array($s,['red','rood','extreme'],true)?'red':(in_array($s,['orange','oranje','amber','severe'],true)?'orange':(in_array($s,['yellow','geel','moderate'],true)?'yellow':'green')); }
function aaPrefsFrom(mixed $s): ?array {
  if(!is_array($s)||array_is_list($s)&&$s)return null;
  $knmi=is_array($s['knmi']??null)?array_values(array_unique(array_filter(array_map('aaNormLevel',$s['knmi']),fn($l)=>$l!=='green'))):[];
  return ['nlalert'=>($s['nlalert']??null)!==false,'burgernet'=>($s['burgernet']??null)===true,'knmi'=>$knmi,'amber'=>($s['amber']??null)===true,'vermistkind'=>($s['vermistkind']??null)===true,
    'vnog'=>($s['vnog']??null)===true,'nieuwsLiveblog'=>($s['nieuwsLiveblog']??null)===true,'nieuwsPolitie'=>($s['nieuwsPolitie']??null)===true,'nieuwsRegio'=>($s['nieuwsRegio']??null)===true,
    'knmiVooraf'=>($s['knmiVooraf']??null)!==false,'knmiOpgeheven'=>($s['knmiOpgeheven']??null)!==false];
}
function aaProfielenFrom(mixed $list): ?array {
  if(!is_array($list)||!array_is_list($list))return null;
  $int=fn($v,$min,$max,$d)=>is_int($v)&&$v>=$min&&$v<=$max?$v:$d; $out=[];
  foreach(array_slice($list,0,5) as $p){ if(!is_array($p))continue;
    $dagen=is_array($p['dagen']??null)?array_values(array_unique(array_filter($p['dagen'],fn($d)=>is_int($d)&&$d>=1&&$d<=7))):[];
    $out[]=['id'=>is_string($p['id']??null)?mb_substr($p['id'],0,64):'','actief'=>($p['actief']??null)!==false,'diensten'=>aaPushStrList($p['diensten']??null),'plaatsen'=>aaPushStrList($p['plaatsen']??null),
      'prioriteiten'=>array_map('strtoupper',aaPushStrList($p['prioriteiten']??null)),'nlalert'=>($p['nlalert']??null)===true,'burgernet'=>($p['burgernet']??null)===true,
      'knmi'=>array_values(array_filter(aaPushStrList($p['knmi']??null),fn($l)=>in_array($l,['yellow','orange','red'],true))),'altijd'=>($p['altijd']??null)!==false,
      'van'=>$int($p['van']??null,0,1439,420),'tot'=>$int($p['tot']??null,0,1439,1380),'dagen'=>$dagen?:[1,2,3,4,5,6,7]]; }
  return $out;
}
function aaCapcodesFrom(mixed $list): ?array {
  if(!is_array($list)||!array_is_list($list))return null; $o=[];
  foreach($list as $c){ $c=preg_replace('/\D/','',(string)($c??'')); if(strlen($c)>=6&&strlen($c)<=10&&!in_array($c,$o,true))$o[]=$c; }
  return array_slice($o,0,50);
}
function aaHeliPrefsFrom(mixed $s): ?array {
  if(!is_array($s)||array_is_list($s)&&$s)return null;
  $str=fn($v,$m)=>is_string($v)?mb_substr(trim($v),0,$m):''; $bool=fn($v,$d)=>is_bool($v)?$v:$d; $zones=[];
  foreach(is_array($s['zones']??null)?$s['zones']:[] as $i=>$z){ $lat=aaPushNum($z['lat']??null,50,54);$lon=aaPushNum($z['lon']??null,2,8);$r=aaPushNum($z['km']??null,0.5,50);
    if($lat===null||$lon===null||$r===null)continue; $zones[]=['id'=>$str($z['id']??null,40)?:"z$i",'naam'=>$str($z['naam']??null,40)?:'Zone','lat'=>round($lat*1e4)/1e4,'lon'=>round($lon*1e4)/1e4,'km'=>$r]; }
  $soorten=is_array($s['soorten']??null)?array_values(array_unique(array_filter($s['soorten'],fn($x)=>in_array($x,['trauma','politie'],true)))):['trauma'];
  $volg=is_array($s['volg']??null)?array_slice(array_values(array_unique(array_filter($s['volg'],fn($x)=>is_string($x)&&preg_match('/^[\w-]{3,40}$/',$x)))),0,40):[];
  return ['zones'=>array_slice($zones,0,5),'opstijgen'=>$bool($s['opstijgen']??null,true),'landen'=>$bool($s['landen']??null,true),'binnen'=>$bool($s['binnen']??null,true),
    'soorten'=>$soorten?:['trauma'],'pauze_tot'=>aaPushNum($s['pauze_tot']??null,0,4e12),'volg'=>$volg,'volg_landen'=>($s['volg_landen']??null)===true,
    'volg_terug'=>($s['volg_terug']??null)!==false,'volg_roepnaam'=>($s['volg_roepnaam']??null)!==false,'meerdere'=>($s['meerdere']??null)!==false];
}
function aaGrootPrefsFrom(mixed $s): ?array {
  if(!is_array($s)||array_is_list($s)&&$s)return null;
  return ['aan'=>($s['aan']??null)===true,'plaatsen'=>aaPushStrList($s['plaatsen']??null)];
}
function aaSavePrefs(string $token, ?array $prefs): void {
  if(!$prefs)return;
  aaDb()->prepare('REPLACE INTO device_prefs (token, data, updated_at) VALUES (?, ?, ?)')->execute([$token,json_encode($prefs,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION),aaNow()]);
}
function aaDevicePrefs(): array { $o=[]; foreach(aaDb()->query('SELECT token, data FROM device_prefs') as $r)$o[$r['token']]=aaParse($r['data'],null); return $o; }
function aaKoppelInstall(string $token, mixed $installId): void {
  $id=strtolower((string)($installId??'')); if(!preg_match('/^[0-9a-f-]{32,40}$/',$id))return;
  aaDb()->prepare('REPLACE INTO device_install (token, install_id, at) VALUES (?, ?, ?)')->execute([$token,$id,aaNow()]);
}
/** POST /v1/devices */
function aaRegisterDevice(array $body): void {
  $d=aaDeviceFrom($body); aaUpsertDevice($d);
  try{ aaKoppelInstall($d['token'],$body['install_id']??null); }catch(Throwable){}
  $s=is_array($body['settings']??null)?$body['settings']:[];
  $w=aaPrefsFrom($s['waarschuwingen']??null); $prof=aaProfielenFrom($s['profielen']??null); $cap=aaCapcodesFrom($s['capcodes']??null);
  $heli=aaHeliPrefsFrom($s['heli']??null); $groot=aaGrootPrefsFrom($s['groot']??null);
  if($w||$prof||$cap||$heli||$groot)aaSavePrefs($d['token'],($w??['nlalert'=>true,'burgernet'=>false,'knmi'=>['orange','red']])+($prof?['profielen'=>$prof]:[])+($cap?['capcodes'=>$cap]:[])+($heli?['heli'=>$heli]:[])+($groot?['groot'=>$groot]:[]));
}

// ------------------------------------------------------------------ matching (rules.js, profielen.js, alerts.js, extra.js)
function aaLocalTime(?int $ms=null): array { $d=(new DateTimeImmutable('@'.intdiv($ms??aaNow(),1000)))->setTimezone(new DateTimeZone('Europe/Amsterdam')); return ['minutes'=>(int)$d->format('G')*60+(int)$d->format('i'),'day'=>(int)$d->format('N')]; }
function aaIsQuiet(array $d, ?int $ms=null): bool {
  if(empty($d['quiet']))return false; $now=aaLocalTime($ms)['minutes']; $f=(int)$d['quiet_from']; $t=(int)$d['quiet_to'];
  if($f===$t)return true; return $f<$t?($now>=$f&&$now<$t):($now>=$f||$now<$t);
}
function aaIsUrgent(array $m): bool { return in_array(strtoupper((string)($m['prioriteit']??'')),['A0','A1','P1'],true); }
function aaMatches(array $d, array $m, ?int $ms=null): bool {
  if(empty($d['enabled']))return false;
  if($d['diensten']&&!in_array($m['dienst'],$d['diensten'],true))return false;
  if(!aaMatchesPlaatsen($d['plaatsen'],$m))return false;
  if($d['prioriteiten']&&!in_array(strtoupper((string)($m['prioriteit']??'')),$d['prioriteiten'],true))return false;
  if(($d['radius_km']??0)>0&&$d['lat']!==null&&$d['lon']!==null){
    if(($m['lat']??null)===null||($m['lon']??null)===null)return false;
    if(aaDistanceKm((float)$d['lat'],(float)$d['lon'],(float)$m['lat'],(float)$m['lon'])>$d['radius_km'])return false;
  }
  if(aaIsQuiet($d,$ms)&&!(!empty($d['critical'])&&aaIsUrgent($m)))return false;
  return true;
}
function aaGeldt(array $p, ?int $ms=null): bool {
  if(empty($p['actief']))return false; if(!empty($p['altijd']))return true;
  ['minutes'=>$min,'day'=>$dag]=aaLocalTime($ms);
  if($p['van']===$p['tot'])$in=true; elseif($p['van']<$p['tot'])$in=$min>=$p['van']&&$min<$p['tot'];
  else{ $in=$min>=$p['van']||$min<$p['tot']; if($min<$p['tot'])$dag=$dag===1?7:$dag-1; }
  return $in&&in_array($dag,$p['dagen'],true);
}
function aaVolgtCapcode(?array $prefs, array $m): bool {
  $v=$prefs['capcodes']??null; if(!is_array($v)||!$v)return false;
  $codes=[$m['capcode']??null]; foreach($m['eenheden']??[] as $e)$codes[]=$e['code']??null;
  foreach($codes as $c){ $c=preg_replace('/\D/','',(string)($c??'')); if($c!==''&&in_array($c,$v,true))return true; }
  return false;
}
function aaMeldingWanted(array $d, ?array $prefs, array $m, ?int $ms=null): bool {
  if(!empty($d['enabled'])&&aaVolgtCapcode($prefs,$m)&&!(aaIsQuiet($d,$ms)&&!(!empty($d['critical'])&&aaIsUrgent($m))))return true;
  $prof=$prefs['profielen']??null;
  if(!is_array($prof)||!$prof)return aaMatches($d,$m,$ms);
  foreach($prof as $p)if(aaGeldt($p,$ms)&&aaMatches(['diensten'=>$p['diensten'],'plaatsen'=>$p['plaatsen'],'prioriteiten'=>$p['prioriteiten']]+$d,$m,$ms))return true;
  return false;
}
function aaIsGrootIncident(array $m): bool {
  if((float)($m['grip']??0)>0||($m['groot']??null)===true||str_contains((string)($m['vlaggen']??''),'groot')||preg_match('/middel|groot|grip/i',(string)($m['opschaling']??'')))return true;
  $t=implode(' ',array_filter([$m['title']??null,$m['full_melding']??null,$m['categorie']??null]));
  if(preg_match('/\bGRIP\s*[1-5]\b|zeer\s+grote\s+brand|\bgrote\s+brand\b|middel\s*brand|\bopschaling\b|\bramp\b|\bCoPI\b|\bOvD\b.*\bHOvD\b|grootschalig|\bMMA\b|multi\s*trauma/i',$t))return true;
  return is_array($m['eenheden']??null)&&count($m['eenheden'])>=10;
}
function aaGrootWanted(array $d, ?array $prefs, array $m, ?int $ms=null): bool {
  $g=$prefs['groot']??null; if(empty($d['enabled'])||empty($g['aan'])||!aaIsGrootIncident($m))return false;
  if(!empty($g['plaatsen'])&&!aaMatchesPlaatsen($g['plaatsen'],$m))return false;
  return !(aaIsQuiet($d,$ms)&&!(!empty($d['critical'])&&aaIsUrgent($m)));
}
function aaWithinRadius(array $d, array $w): bool {
  $r=(float)$d['radius_km']; $lat=(float)$d['lat']; $lon=(float)$d['lon'];
  if(is_array($w['gebied']??null)&&$w['gebied']&&$w['bron']!=='burgernet'){
    foreach($w['gebied'] as $ring){ if(aaInRing($ring,$lat,$lon))return true; foreach($ring as [$x,$y])if(aaDistanceKm($lat,$lon,(float)$y,(float)$x)<=$r)return true; } return false;
  }
  if(($w['lat']??null)!==null&&($w['lon']??null)!==null)return aaDistanceKm($lat,$lon,(float)$w['lat'],(float)$w['lon'])<=$r+2;
  return true;
}
function aaWantsPush(array $d, ?array $p, array $w): bool {
  if(!$p||empty($d['enabled']))return false;
  if($w['bron']==='nlalert'&&empty($p['nlalert']))return false;
  if($w['bron']==='burgernet'&&empty($p['burgernet']))return false;
  if($w['bron']==='knmi'&&!in_array($w['niveau'],$p['knmi']??[],true))return false;
  if($w['bron']==='amber')return !empty($p['amber']);
  if($w['bron']==='vermistkind')return !empty($p['vermistkind']);
  if($w['bron']==='vnog'&&empty($p['vnog']))return false;
  if(($d['radius_km']??0)>0&&$d['lat']!==null&&$d['lon']!==null&&!aaWithinRadius($d,$w))return false;
  $chosen=$d['plaatsen']??[]; if(!$chosen)return true;
  if($w['bron']==='burgernet'||$w['bron']==='vnog')return aaMatchesPlaatsen($chosen,['stad'=>$w['plaats'],'locatie'=>'','lat'=>null,'lon'=>null]);
  $places=array_filter(aaPlaatsen(),function($p)use($chosen){foreach($chosen as $c)if(aaPlaatsKey($c)===aaPlaatsKey($p['naam']))return true;return false;});
  if(empty($w['gebied']))return true;
  foreach($places as $p)foreach($w['gebied'] as $ring){ if(aaInRing($ring,(float)$p['lat'],(float)$p['lon']))return true; foreach($ring as [$lon,$lat])if(aaDistanceKm((float)$lat,(float)$lon,(float)$p['lat'],(float)$p['lon'])<=2)return true; }
  return false;
}
function aaWarningWanted(array $d, ?array $prefs, array $w, ?int $ms=null): bool {
  if(in_array($w['bron'],['amber','vermistkind','vnog'],true))return aaWantsPush($d,$prefs,$w);
  $prof=$prefs['profielen']??null; if(!is_array($prof)||!$prof)return aaWantsPush($d,$prefs,$w);
  foreach($prof as $p)if(aaGeldt($p,$ms)&&aaWantsPush(['plaatsen'=>$p['plaatsen']]+$d,['nlalert'=>$p['nlalert'],'burgernet'=>$p['burgernet'],'knmi'=>$p['knmi']],$w))return true;
  return false;
}

// ------------------------------------------------------------------ payloads
const AA_PUSH_LABELS=['nl'=>['brandweer'=>'Brandweer','ambulance'=>'Ambulance','politie'=>'Politie','traumaheli'=>'Traumaheli','kustwacht'=>'Kustwacht','onbekend'=>'Melding'],
  'en'=>['brandweer'=>'Fire brigade','ambulance'=>'Ambulance','politie'=>'Police','traumaheli'=>'Trauma helicopter','kustwacht'=>'Coastguard','onbekend'=>'Alert']];
function aaPayloadFor(array $m, ?string $lang='nl'): array {
  $L=AA_PUSH_LABELS[$lang]??AA_PUSH_LABELS['nl']; $urgent=aaIsUrgent($m); $prio=strtoupper((string)($m['prioriteit']??''));
  $tekst=trim((string)($m['full_melding']??''))?:implode(' ',array_filter([$prio,$m['title']??null]));
  $ms=is_numeric($m['datum']??null)?(int)$m['datum']:aaMs($m['datum']??($m['datum_tijd']??null));
  $tijd=$ms?(new DateTimeImmutable('@'.intdiv($ms,1000)))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('H:i:s'):'';
  $p=['aps'=>['alert'=>['title'=>implode(' • ',array_filter([$L[$m['dienst']]??$L['onbekend'],$prio])),'body'=>$tijd?"$tekst ($tijd)":$tekst],'sound'=>'default',
    'thread-id'=>($m['incident']??null)?:$m['dienst'],'interruption-level'=>$urgent?'time-sensitive':'active','relevance-score'=>$urgent?1:0.5,'mutable-content'=>1,'category'=>'MELDING'],
    'guid'=>$m['guid'],'dienst'=>$m['dienst']];
  if(!empty($m['locatie'])&&($m['lat']??null)!==null&&($m['lon']??null)!==null){$p['lat']=$m['lat'];$p['lon']=$m['lon'];}
  return $p;
}
function aaMeldingPayload(array $m, ?string $lang): array {
  $p=aaPayloadFor($m,$lang);
  if(aaIsGrootIncident($m)){ $p['aps']['alert']['title']=(str_starts_with((string)($lang?:'nl'),'nl')?'Groot incident':'Large incident').' · '.$p['aps']['alert']['title']; $p['groot']=true; }
  return $p;
}
function aaBroadcastPayload(array $b, ?string $lang): array {
  $t=!empty($b['en'])&&!str_starts_with((string)($lang?:'nl'),'nl')?$b['en']:$b;
  $aps=['alert'=>['title'=>$t['title']??'','body'=>$t['body']??''],'sound'=>!empty($b['heli'])?'heli.wav':'default'];
  if(!empty($b['heli']))$aps+=['thread-id'=>'lifeliner','interruption-level'=>'time-sensitive'];
  return ['aps'=>$aps]+(!empty($b['guid'])?['guid'=>$b['guid']]:[])+(!empty($b['heli'])?['heli'=>$b['heli']]:[]);
}
function aaPayloadVoorNieuws(array $item, ?string $lang='nl', string $kind='p', ?array $update=null): array {
  $nl=str_starts_with((string)($lang?:'nl'),'nl');
  $t=$nl?['live'=>['burgernet'=>'Liveblog · Burgernet','amber'=>'Liveblog · AMBER Alert','vermistkind'=>'Liveblog · Vermist Kind','vnog'=>'Liveblog · VNOG'],'politie'=>'Politienieuws','weer'=>'Weer','regio'=>'112 Nieuws']
    :['live'=>['burgernet'=>'Live blog · Burgernet','amber'=>'Live blog · AMBER Alert','vermistkind'=>'Live blog · Missing Child','vnog'=>'Live blog · VNOG'],'politie'=>'Police news','weer'=>'Weather','regio'=>'112 Nieuws'];
  $head=$item['soort']==='liveblog'?($t['live'][$item['bron']]??'Liveblog'):($item['soort']==='weer'?$t['weer'].' · '.$item['titel']:($item['soort']==='regionaal'?$t['regio']:$t['politie']).(!empty($item['plaats'])?' · '.$item['plaats']:''));
  $body=$kind==='a'&&$update?(implode(' – ',array_filter([$update['titel']??null,$update['tekst']??null]))?:$item['titel']):$item['titel'];
  $alert=['title'=>mb_substr($head,0,120),'body'=>mb_substr((string)$body,0,400)];
  if($kind==='a'&&$item['soort']!=='liveblog')$alert['subtitle']=mb_substr((string)$item['titel'],0,120);
  return ['aps'=>['alert'=>$alert,'sound'=>'default','thread-id'=>'nieuws-'.$item['id'],'interruption-level'=>'active','relevance-score'=>0.5,'category'=>'NIEUWS'],'nieuws'=>$item['id']];
}
function aaPayloadForWarning(array $w, ?string $lang='nl', string $kind='n'): array {
  $nl=str_starts_with((string)($lang?:'nl'),'nl');
  $L=$nl?['nlalert'=>'NL-Alert','burgernet'=>'Burgernet','amber'=>'AMBER Alert','vermistkind'=>'Vermist Kind Alert','kindUpdate'=>'Update','kindEinde'=>'Beëindigd','vnog'=>'VNOG','vnogEinde'=>'Afgesloten','vnogGrip'=>'Opgeschaald naar',
      'knmi'=>['yellow'=>'Code geel','orange'=>'Code oranje','red'=>'Code rood','green'=>'Code groen'],'upgrade'=>'Opgeschaald naar']
    :['nlalert'=>'NL-Alert','burgernet'=>'Burgernet','amber'=>'AMBER Alert','vermistkind'=>'Missing Child Alert','kindUpdate'=>'Update','kindEinde'=>'Ended','vnog'=>'VNOG','vnogEinde'=>'Closed','vnogGrip'=>'Raised to',
      'knmi'=>['yellow'=>'Code yellow','orange'=>'Code orange','red'=>'Code red','green'=>'Code green'],'upgrade'=>'Raised to'];
  $tijd=fn($iso)=>(new DateTimeImmutable('@'.intdiv(aaMs($iso)??0,1000)))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('H:i');
  $code=$L['knmi'][$w['niveau']??'']??'KNMI';
  $head=$w['bron']==='knmi'?trim(($kind==='u'?$L['upgrade'].' ':'').$code.' · '.($w['soort']??'')):($w['bron']==='burgernet'?$L['burgernet'].' · '.(($w['plaats']??'')?:'Apeldoorn'):$L['nlalert']);
  $body=$w['bron']==='knmi'?(($w['tekst']??'')?:($w['plaats']??'')):($w['bron']==='nlalert'?$w['tekst']:(string)$w['titel']);
  if($w['bron']==='knmi'&&$kind==='v')$head=preg_replace('/ · $/','',($nl?'Om '.$tijd($w['start']).' gaat '.mb_strtolower($code).' in':$code.' starts at '.$tijd($w['start'])).' · '.($w['soort']??''));
  if($w['bron']==='knmi'&&$kind==='g'){ $head=preg_replace('/ · $/','',$L['knmi']['green'].' · '.($w['soort']??''));
    $s=$w['soort']??''; $body=$nl?'De waarschuwing'.($s?' voor '.mb_strtolower($s):'').' is opgeheven. Er geldt nu code groen.':'The warning'.($s?' for '.mb_strtolower($s):'').' has been lifted. Code green applies now.'; }
  if(in_array($w['bron'],AA_KIND_BRONNEN,true)){ $head=$L[$w['bron']].($kind==='u'?' · '.$L['kindUpdate']:($kind==='a'?' · '.$L['kindEinde']:'')).($w['bron']==='vermistkind'&&!empty($w['plaats'])?' · '.$w['plaats']:'');
    $body=$kind==='a'?$w['tekst']:implode(' – ',array_filter([$w['titel']??null,$w['tekst']??null])); }
  if($w['bron']==='vnog'){ $grip=!empty($w['grip'])?'GRIP '.$w['grip']:null;
    $head=implode(' · ',array_filter([$L['vnog'],$kind==='u'?$L['kindUpdate']:($kind==='a'?$L['vnogEinde']:($kind==='s'&&$grip?$L['vnogGrip'].' '.$grip:$grip)),$w['plaats']??null]));
    $body=$kind==='a'?$w['titel'].' – '.(($w['afloop']??'')?:'Het incident is afgesloten.'):($kind==='s'?$w['titel']:implode(' – ',array_filter([$w['titel']??null,$w['tekst']??null]))); }
  $urgent=$w['bron']==='vnog'?$kind!=='a'&&(float)($w['grip']??0)>=1:(in_array($w['bron'],AA_KIND_BRONNEN,true)?$kind!=='a':$kind!=='g'&&($w['bron']==='nlalert'||($w['niveau']??null)==='red'));
  $p=['aps'=>['alert'=>['title'=>$head,'body'=>mb_substr((string)(($body??'')?:($w['titel']??'')),0,400)],'sound'=>'default','thread-id'=>$w['bron'],'interruption-level'=>$urgent?'time-sensitive':'active',
    'relevance-score'=>$urgent?1:0.6,'mutable-content'=>1,'category'=>'WAARSCHUWING'],'waarschuwing'=>$w['id'],'bron'=>$w['bron']];
  if(!empty($w['niveau']))$p['niveau']=$w['niveau'];
  if($w['bron']==='vnog'&&!empty($w['grip']))$p['grip']=$w['grip'];
  if(($w['lat']??null)!==null&&($w['lon']??null)!==null){$p['lat']=$w['lat'];$p['lon']=$w['lon'];}
  return $p;
}

// ------------------------------------------------------------------ outbox
function aaQueue(string $guid, array $tokens): void {
  $st=aaDb()->prepare('INSERT IGNORE INTO outbox (guid, token, created_at) VALUES (?, ?, ?)'); $now=aaNow();
  foreach($tokens as $t)$st->execute([$guid,$t,$now]);
}
/** Queues a new P2000 alert for every device that wants it (ingest calls this; sending follows the PUSH_ACTIEF switch). */
function aaQueueMelding(array $m): int {
  $devices=aaActiveDevices(); $prefs=aaDevicePrefs(); $t=[];
  foreach($devices as $d){ $p=$prefs[$d['token']]??null; if(aaMeldingWanted($d,$p,$m)||aaGrootWanted($d,$p,$m))$t[]=$d['token']; }
  aaQueue($m['guid'],$t); return count($t);
}
/** index.js warnings(): queues NL-Alert, Burgernet, KNMI, Amber and VNOG pushes for the devices that want them. */
function aaPushWaarschuwingen(array $toPush): int {
  $devices=aaActiveDevices(); $prefs=aaDevicePrefs(); $n=0;
  foreach($toPush as $t){
    $kind=$t['kind']??(!empty($t['upgrade'])?'u':'n'); $w=aaGetWarning((string)$t['id']); if(!$w)continue;
    $tok=[];
    foreach($devices as $d){ $p=$prefs[$d['token']]??null;
      if($kind==='v'&&($p['knmiVooraf']??null)===false)continue;
      if($kind==='g'&&($p['knmiOpgeheven']??null)===false)continue;
      if(($w['bron']??'')==='vnog'&&aaIsQuiet($d)&&!(!empty($d['critical'])&&(float)($w['grip']??0)>=1))continue;
      if($kind==='g'){ $ok=false; foreach(($t['levels']??null)?:[$w['niveau']??null] as $niv)if(aaWarningWanted($d,$p,['niveau'=>$niv,'status'=>'actief']+$w)){$ok=true;break;} if(!$ok)continue; }
      elseif(!aaWarningWanted($d,$p,$w))continue;
      $tok[]=$d['token']; }
    if($tok){ aaQueue("w:$kind:".$t['id'],$tok); $n+=count($tok); }
  }
  return $n;
}
function aaNotePushes(array $list): void {
  if(!$list)return; $bucket=intdiv(aaNow(),600000)*600000; $per=[];
  foreach($list as $p){ $s=&$per[$p['platform']]; $s??=['ok'=>0,'fail'=>0,'ls'=>0,'ln'=>0];
    if($p['ok'])$s['ok']++; elseif(!$p['dead'])$s['fail']++;
    if($p['ok']&&$p['latency']!==null&&$p['latency']>=0&&$p['latency']<1800000){$s['ls']+=$p['latency'];$s['ln']++;} unset($s); }
  $st=aaDb()->prepare('INSERT INTO status_push (bucket, platform, ok, fail, lat_sum, lat_n) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE ok=ok+VALUES(ok), fail=fail+VALUES(fail), lat_sum=lat_sum+VALUES(lat_sum), lat_n=lat_n+VALUES(lat_n)');
  foreach($per as $pl=>$s)$st->execute([$bucket,$pl,$s['ok'],$s['fail'],$s['ls'],$s['ln']]);
}
/** Sends what waits in the outbox (at most $max). Does nothing while PUSH_ACTIEF is not "1". */
function aaDrain(int $max=45): array {
  if(!aaPushActief())return ['actief'=>false,'verstuurd'=>0];
  $q=aaDb()->prepare('SELECT o.guid, o.token, o.created_at, d.environment, d.language FROM outbox o JOIN devices d ON d.token = o.token'.(aaPushModus()==='eigen'?' WHERE d.eigen = 1':'').' ORDER BY o.created_at LIMIT '.max(1,$max)); $q->execute();
  $rows=$q->fetchAll(); $items=[]; $done=aaDb()->prepare('DELETE FROM outbox WHERE guid = ? AND token = ?'); $sent=[];
  foreach($rows as $row){ $g=$row['guid']; if(array_key_exists($g,$items))continue;
    if(str_starts_with($g,'w:')){ $parts=explode(':',$g); $kind=$parts[1]??'n'; $w=aaGetWarning(implode(':',array_slice($parts,2))); $items[$g]=$w?['warning'=>$w,'kind'=>$kind]:null; }
    elseif(str_starts_with($g,'n:')){ [, $kind, $id, $uid]=array_pad(explode(':',$g),4,null);
      $s=aaDb()->prepare('SELECT id, soort, bron, titel, plaats FROM nieuws WHERE id = ? AND hidden = 0'); $s->execute([$id]); $it=$s->fetch();
      $up=null; if($kind==='a'){ $u=aaDb()->prepare('SELECT titel, tekst FROM nieuws_updates WHERE id = ?'); $u->execute([(int)$uid]); $up=$u->fetch()?:null; }
      $items[$g]=$it&&($kind!=='a'||$up)?['nieuws'=>$it,'kind'=>$kind,'update'=>$up]:null; }
    elseif(str_starts_with($g,'broadcast:')){ $b=aaKvGet($g); $items[$g]=$b?['broadcast'=>json_decode($b,true)]:null; }
    else{ $m=aaGetMelding($g); $items[$g]=$m?['melding'=>$m+['datum'=>aaMs($m['datum_tijd'])]]:null; } }
  foreach($rows as $row){
    $it=$items[$row['guid']]; if(!$it){$done->execute([$row['guid'],$row['token']]);continue;}
    $dev=['token'=>$row['token'],'environment'=>$row['environment']];
    if(isset($it['nieuws']))$r=aaSendPush($dev,aaPayloadVoorNieuws($it['nieuws'],$row['language'],$it['kind'],$it['update']),['collapseId'=>substr($row['guid'],-64)]);
    elseif(isset($it['warning'])){ $w=$it['warning']; $k=$it['kind'];
      $urgent=$w['bron']==='vnog'?$k!=='a'&&(float)($w['grip']??0)>=1:(in_array($w['bron'],AA_KIND_BRONNEN,true)?$k!=='a':$k!=='g'&&($w['bron']==='nlalert'||($w['niveau']??null)==='red'));
      $r=aaSendPush($dev,aaPayloadForWarning($w,$row['language'],$k),['urgent'=>$urgent,'collapseId'=>substr($w['id'],0,64)]); }
    elseif(isset($it['melding']))$r=aaSendPush($dev,aaMeldingPayload($it['melding'],$row['language']),['urgent'=>aaIsUrgent($it['melding']),'collapseId'=>$row['guid']]);
    else $r=aaSendPush($dev,aaBroadcastPayload($it['broadcast'],$row['language']),!empty($it['broadcast']['heli'])?['collapseId'=>substr($row['guid'],-64)]:[]);
    $dead=aaIsDeadToken($r);
    $sent[]=['platform'=>str_starts_with($row['token'],'fcm:')?'android':'apple','ok'=>$r['ok'],'dead'=>$dead,'latency'=>isset($it['melding'])&&$r['ok']?aaNow()-(int)$row['created_at']:null];
    if($dead)aaDeleteDevice($row['token']);
    elseif(!$r['ok'])aaKvSet('push_laatste_fout',aaJson(['at'=>aaIso(aaNow()),'platform'=>str_starts_with($row['token'],'fcm:')?'android':$row['environment'],'status'=>$r['status'],'reason'=>$r['reason']??null,'soort'=>explode(':',$row['guid'])[0]]));
    $done->execute([$row['guid'],$row['token']]);
  }
  try{ aaNotePushes($sent); }catch(Throwable){}
  return ['actief'=>true,'verstuurd'=>count($sent)];
}
