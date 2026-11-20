<?php
declare(strict_types=1);
/**
 * Apple services (apple.js): ES256 JWT, APNs, MapKit JS token, WeatherKit, Apple Maps Server API (token, directions,
 * geocode). Same keys and behaviour as the Worker. Own kv keys for cached tokens (aa_apns_jwt, aa_maps_access), so a
 * token of the Worker copied with the database is never mixed up with one made here.
 */
require_once __DIR__.'/meldingen.php';

function aaB64url(string $s): string { return rtrim(strtr(base64_encode($s),'+/','-_'),'='); }
/** GitHub/Cloudflare secrets may store the .p8 with literal "\n". */
function aaPem(mixed $v): string { return trim(str_replace('\n',"\n",(string)($v??''))); }
function aaHasKey(string $keyId, string $teamId, string $p8): bool { return $keyId!==''&&$teamId!==''&&str_contains(aaPem($p8),'PRIVATE KEY'); }
/** DER ECDSA signature → raw r||s (64 bytes) as JWT and Apple expect. */
function aaDerToRaw(string $der): string {
  $o=2; if((ord($der[1])&0x80))$o+=ord($der[1])&0x7f;
  $lr=ord($der[$o+1]); $r=substr($der,$o+2,$lr); $o+=2+$lr; $ls=ord($der[$o+1]); $s=substr($der,$o+2,$ls);
  $r=str_pad(ltrim($r,"\x00"),32,"\x00",STR_PAD_LEFT); $s=str_pad(ltrim($s,"\x00"),32,"\x00",STR_PAD_LEFT);
  return $r.$s;
}
function aaSignRaw(string $p8, string $data): string {
  $pem=aaPem($p8);
  if(!str_contains($pem,"\n")){ // a key stored on one line: rebuild the PEM
    $body=preg_replace('/-----[^-]+-----|\s+/','',$pem); $pem="-----BEGIN PRIVATE KEY-----\n".chunk_split((string)$body,64,"\n")."-----END PRIVATE KEY-----";
  }
  $key=openssl_pkey_get_private($pem); if(!$key)throw new RuntimeException('es256_key');
  if(!openssl_sign($data,$der,$key,OPENSSL_ALGO_SHA256))throw new RuntimeException('es256_sign');
  return aaB64url(aaDerToRaw($der));
}
function aaEs256(array $header, array $claims, string $p8): string {
  $data=aaB64url(json_encode($header,JSON_UNESCAPED_SLASHES)).'.'.aaB64url(json_encode($claims,JSON_UNESCAPED_SLASHES));
  return $data.'.'.aaSignRaw($p8,$data);
}
function aaAppleHttp(string $method, string $url, array $headers=[], ?string $body=null, int $timeout=15, bool $http2=false): array {
  $ch=curl_init($url); $rh=[];
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_HTTPHEADER=>$headers,
    CURLOPT_HEADERFUNCTION=>function($c,$l)use(&$rh){$p=explode(':',$l,2);if(count($p)===2)$rh[strtolower(trim($p[0]))]=trim($p[1]);return strlen($l);}]
    +($body!==null?[CURLOPT_POSTFIELDS=>$body]:[])+($http2?[CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_2_0]:[]));
  $b=curl_exec($ch); $st=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=$b===false?curl_error($ch):null; curl_close($ch);
  return ['status'=>$st,'body'=>is_string($b)?$b:'','headers'=>$rh,'error'=>$err];
}

// ------------------------------------------------------------------ APNs
function aaApnsConfigured(): bool { return aaHasKey(setting('APN_KEY_ID'),setting('APN_TEAM_ID'),setting('APN_P8_CERT')); }
function aaApnsToken(): string {
  $now=time(); $row=aaKvRow('aa_apns_jwt');
  if($row&&$now-(int)$row['at']<50*60)return (string)$row['value'];
  $t=aaEs256(['alg'=>'ES256','kid'=>setting('APN_KEY_ID')],['iss'=>setting('APN_TEAM_ID'),'iat'=>$now],setting('APN_P8_CERT'));
  aaDb()->prepare('INSERT INTO kv (`key`, value, at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), at = VALUES(at)')->execute(['aa_apns_jwt',$t,$now]);
  return $t;
}
/** { ok, status, reason } (fcm: true for Android). */
function aaSendPush(array $device, array $payload, array $opt=[]): array {
  require_once __DIR__.'/fcm.php';
  if(aaIsFcmToken((string)$device['token']))return aaSendFcm((string)$device['token'],$payload,$opt);
  if(!aaApnsConfigured())return ['ok'=>false,'status'=>0,'reason'=>'APNs not configured'];
  $host=($device['environment']??'')==='sandbox'?'https://api.sandbox.push.apple.com':'https://api.push.apple.com';
  try{
    $h=['authorization: bearer '.aaApnsToken(),'apns-topic: '.(($opt['topic']??'')?:(setting('APN_TOPIC')?:'nl.lrvdlinden.p2000apeldoorn')),'apns-push-type: alert','apns-priority: 10',
      'apns-expiration: '.(time()+(!empty($opt['urgent'])?3600:6*3600)),'content-type: application/json'];
    if(!empty($opt['collapseId']))$h[]='apns-collapse-id: '.substr((string)$opt['collapseId'],0,64);
    $r=aaAppleHttp('POST',$host.'/3/device/'.$device['token'],$h,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),15,true);
    if($r['error'])return ['ok'=>false,'status'=>0,'reason'=>$r['error']];
    $reason=null; if($r['status']!==200){$j=json_decode($r['body'],true);$reason=$j['reason']??null;}
    return ['ok'=>$r['status']===200,'status'=>$r['status'],'reason'=>$reason];
  }catch(Throwable $e){ return ['ok'=>false,'status'=>0,'reason'=>$e->getMessage()]; }
}
function aaIsDeadToken(array $r): bool {
  if(!empty($r['fcm'])){ require_once __DIR__.'/fcm.php'; return aaIsDeadFcm($r); }
  return ($r['status']??0)===410||in_array($r['reason']??null,['BadDeviceToken','Unregistered','DeviceTokenNotForTopic'],true);
}

// ------------------------------------------------------------------ MapKit JS
const AA_DEFAULT_ORIGINS='https://a-alerts.nl,https://www.a-alerts.nl,https://beheer.a-alerts.nl,https://p2000-apeldoorn.nl,https://www.p2000-apeldoorn.nl,https://lrvdlinden.github.io';
function aaMapkitConfigured(): bool { return aaHasKey(setting('WEB_MAPKIT_JS_KEY_ID'),setting('WEB_MAPKIT_JS_TEAM_ID')?:setting('APN_TEAM_ID'),setting('WEB_MAPKIT_JS_P8')); }
function aaMapkitToken(string $origin): ?string {
  if(!aaMapkitConfigured())return null;
  $origins=array_values(array_filter(array_map('trim',explode(',',setting('SITE_ORIGINS')?:AA_DEFAULT_ORIGINS))));
  $local=preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#',$origin)===1;
  if($origin!==''&&!in_array($origin,$origins,true)&&!$local)return null;
  $now=time(); $claims=['iss'=>setting('WEB_MAPKIT_JS_TEAM_ID')?:setting('APN_TEAM_ID'),'iat'=>$now,'exp'=>$now+30*60];
  if($origin!==''&&!$local)$claims['origin']=$origin;
  return aaEs256(['alg'=>'ES256','kid'=>setting('WEB_MAPKIT_JS_KEY_ID'),'typ'=>'JWT'],$claims,setting('WEB_MAPKIT_JS_P8'));
}

// ------------------------------------------------------------------ WeatherKit
const AA_APELDOORN=[52.2112,5.9699];
function aaWeatherConfigured(): bool { return aaHasKey(setting('WEB_WEATHERKIT_KEY_ID'),setting('WEB_WEATHERKIT_TEAM_ID')?:setting('APN_TEAM_ID'),setting('WEB_WEATHERKIT_P8')); }
function aaWeatherToken(): string {
  $team=setting('WEB_WEATHERKIT_TEAM_ID')?:setting('APN_TEAM_ID'); $service=setting('WEB_WEATHERKIT_SERVICE_ID')?:'nl.lrvdlinden.p2000apeldoorn.weatherkit'; $now=time();
  return aaEs256(['alg'=>'ES256','kid'=>setting('WEB_WEATHERKIT_KEY_ID'),'id'=>"$team.$service"],['iss'=>$team,'iat'=>$now,'exp'=>$now+3600,'sub'=>$service],setting('WEB_WEATHERKIT_P8'));
}
function aaWeatherData(array $h, bool $live=false): array {
  return ['time'=>$h['forecastStart']??$h['asOf']??null]+($live?['live'=>true]:[])+['condition'=>$h['conditionCode']??null,'temperature'=>$h['temperature']??null,'apparent'=>$h['temperatureApparent']??null,
    'humidity'=>$h['humidity']??null,'cloudCover'=>$h['cloudCover']??null,'windSpeed'=>$h['windSpeed']??null,'windGust'=>$h['windGust']??null,
    'windDirection'=>$h['windDirection']??null,'precipitation'=>$h['precipitationIntensity']??null,'visibility'=>$h['visibility']??null,'daylight'=>$h['daylight']??null,
    'attribution'=>'https://weatherkit.apple.com/legal-attribution.html'];
}
/** $m: guid, lat, lon, datum_tijd. */
function aaWeatherFor(array $m): ?array {
  $q=aaDb()->prepare('SELECT data FROM weather WHERE guid = ?'); $q->execute([$m['guid']]); $c=$q->fetchColumn();
  if($c!==false)return json_decode((string)$c,true);
  if(!aaWeatherConfigured())return null;
  [$lat,$lon]=($m['lat']??null)!==null&&($m['lon']??null)!==null?[(float)$m['lat'],(float)$m['lon']]:AA_APELDOORN;
  $t=aaMs($m['datum_tijd']); if($t===null)return null;
  $hour=intdiv($t,3600000)*3600000;
  $url='https://weatherkit.apple.com/api/v1/weather/nl/'.number_format($lat,4,'.','').'/'.number_format($lon,4,'.','')
    .'?dataSets=forecastHourly&hourlyStart='.aaIso($hour-3600000).'&hourlyEnd='.aaIso($hour+2*3600000).'&timezone=Europe/Amsterdam';
  $r=aaAppleHttp('GET',$url,['authorization: Bearer '.aaWeatherToken()]);
  if($r['status']!==200){error_log('[aa weather] '.$r['status']);return null;}
  $hours=json_decode($r['body'],true)['forecastHourly']['hours']??[];
  $best=null; foreach($hours as $x){ if(!$best||abs((aaMs($x['forecastStart'])??0)-$t)<abs((aaMs($best['forecastStart'])??0)-$t))$best=$x; }
  if(!$best)return null;
  $data=aaWeatherData($best);
  if(aaNow()-$t>3600000)aaDb()->prepare('REPLACE INTO weather (guid, data, fetched_at) VALUES (?, ?, ?)')->execute([$m['guid'],aaJson($data),aaNow()]);
  return $data;
}
function aaWeatherNow(): ?array {
  $row=aaKvRow('weer_nu');
  if($row&&aaNow()-(int)$row['at']<10*60000)return json_decode((string)$row['value'],true);
  if(!aaWeatherConfigured())return null;
  [$lat,$lon]=AA_APELDOORN;
  $r=aaAppleHttp('GET',"https://weatherkit.apple.com/api/v1/weather/nl/$lat/$lon?dataSets=currentWeather&timezone=Europe/Amsterdam",['authorization: Bearer '.aaWeatherToken()]);
  if($r['status']!==200)return $row?json_decode((string)$row['value'],true):null;
  $c=json_decode($r['body'],true)['currentWeather']??null; if(!$c)return null;
  $data=aaWeatherData($c,true); aaKvSet('weer_nu',aaJson($data)); return $data;
}

// ------------------------------------------------------------------ Apple Maps Server API
function aaMapsServerConfigured(): bool { return aaHasKey(setting('MAPS_SERVER_API_KEY_ID'),setting('MAPS_SERVER_API_TEAM_ID')?:setting('APN_TEAM_ID'),setting('MAPS_SERVER_API_P8')); }
function aaMapsAccessToken(): string {
  $now=time(); $row=aaKvRow('aa_maps_access');
  if($row&&(int)$row['at']-$now>60)return (string)$row['value'];
  $auth=aaEs256(['alg'=>'ES256','kid'=>setting('MAPS_SERVER_API_KEY_ID'),'typ'=>'JWT'],['iss'=>setting('MAPS_SERVER_API_TEAM_ID')?:setting('APN_TEAM_ID'),'iat'=>$now,'exp'=>$now+3600],setting('MAPS_SERVER_API_P8'));
  $r=aaAppleHttp('GET','https://maps-api.apple.com/v1/token',['authorization: Bearer '.$auth]);
  if($r['status']!==200)throw new RuntimeException('Maps token HTTP '.$r['status']);
  $b=json_decode($r['body'],true); $exp=$now+(int)($b['expiresInSeconds']??1800);
  aaDb()->prepare('INSERT INTO kv (`key`, value, at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), at = VALUES(at)')->execute(['aa_maps_access',$b['accessToken'],$exp]);
  return (string)$b['accessToken'];
}
function aaMapsDirections(array $from, array $to, ?int $vertrek): ?array {
  if(!aaMapsServerConfigured())return null;
  $q=['origin'=>implode(',',$from),'destination'=>implode(',',$to),'transportType'=>'Automobile','lang'=>'nl-NL'];
  if($vertrek&&$vertrek>aaNow()-60000)$q['departureDate']=aaIso($vertrek);
  $r=aaAppleHttp('GET','https://maps-api.apple.com/v1/directions?'.http_build_query($q),['authorization: Bearer '.aaMapsAccessToken()]);
  if($r['status']!==200){error_log('[aa directions] '.$r['status']);return null;}
  $b=json_decode($r['body'],true); $rt=$b['routes'][0]??null; if(!$rt)return null;
  $punten=[];
  foreach($rt['stepIndexes']??[] as $si){ $pad=$b['stepPaths'][$b['steps'][$si]['stepPathIndex']??-1]??[];
    foreach($pad as $p){ $pt=[round($p['latitude'],5),round($p['longitude'],5)]; $last=end($punten); if(!$last||$last[0]!==$pt[0]||$last[1]!==$pt[1])$punten[]=$pt; } }
  return ['km'=>(float)($rt['distanceMeters']??0)/1000,'min'=>(float)($rt['durationSeconds']??0)/60,'punten'=>$punten];
}
function aaGeocode(mixed $locatie, mixed $stad): ?array {
  if(!aaMapsServerConfigured()||!$locatie)return null;
  try{
    $q=$locatie.', '.($stad?:'Apeldoorn');
    $r=aaAppleHttp('GET','https://maps-api.apple.com/v1/geocode?q='.rawurlencode($q).'&limitToCountries=NL&lang=nl-NL&searchLocation='.implode(',',AA_APELDOORN),['authorization: Bearer '.aaMapsAccessToken()]);
    if($r['status']!==200)throw new RuntimeException('Geocode HTTP '.$r['status']);
    $c=json_decode($r['body'],true)['results'][0]['coordinate']??null;
    $ok=$c&&abs($c['latitude']-AA_APELDOORN[0])<0.3&&abs($c['longitude']-AA_APELDOORN[1])<0.45;
    return $ok?[round($c['latitude'],5),round($c['longitude'],5)]:null;
  }catch(Throwable $e){ error_log('[aa geocode] '.$e->getMessage()); return null; }
}

// ------------------------------------------------------------------ routes.js
const AA_UITRUK=['brandweer'=>4,'ambulance'=>1,'politie'=>0];
function aaKmR(float $aLat,float $aLon,float $bLat,float $bLon): float { $r=M_PI/180; $dLat=($bLat-$aLat)*$r; $dLon=($bLon-$aLon)*$r; $h=sin($dLat/2)**2+cos($aLat*$r)*cos($bLat*$r)*sin($dLon/2)**2; return 12742*asin(sqrt($h)); }
function aaKiesKazernes(array $m): array {
  $soort=['brandweer'=>'brandweer','ambulance'=>'ambulance','politie'=>'politie'][$m['dienst']??'']??null;
  if(!$soort||($m['lat']??null)===null||($m['lon']??null)===null)return ['gekozen'=>[],'genoemd'=>false];
  $kand=array_values(array_filter(aaData('kazernes'),fn($k)=>$k['soort']===$soort));
  $namen=array_map(fn($u)=>mb_strtolower((string)($u['naam']??'')),$m['eenheden']??[]); $namen[]=mb_strtolower((string)($m['full_melding']??''));
  $gekozen=array_values(array_filter($kand,function($k)use($namen){ foreach($k['aliassen']??[] as $a){ $re='/\b'.preg_quote(mb_strtolower($a),'/').'\b/'; foreach($namen as $n)if(preg_match($re,$n))return true; } return false; }));
  $genoemd=count($gekozen)>0;
  if(!$gekozen&&$kand){ $b=$kand[0]; foreach($kand as $k)if(aaKmR($k['lat'],$k['lon'],$m['lat'],$m['lon'])<aaKmR($b['lat'],$b['lon'],$m['lat'],$m['lon']))$b=$k; $gekozen=[$b]; }
  $gekozen=array_slice(array_values(array_filter($gekozen,fn($k)=>aaKmR($k['lat'],$k['lon'],$m['lat'],$m['lon'])<40)),0,5);
  return ['gekozen'=>$gekozen,'genoemd'=>$genoemd];
}
function aaDun(array $p, int $max=160): array { $n=count($p); if($n<=$max)return $p; $step=($n-1)/($max-1); $o=[]; for($i=0;$i<$max;$i++)$o[]=$p[(int)round($i*$step)]; return $o; }
function aaBerekenRoutes(array $m, callable $directions): array {
  ['gekozen'=>$gekozen,'genoemd'=>$genoemd]=aaKiesKazernes($m);
  $alarm=aaMs($m['datum_tijd']??($m['datum']??null))??aaNow(); $spoed=in_array(strtoupper((string)($m['prioriteit']??'')),['A0','A1','P1'],true);
  $kz=[];
  foreach($gekozen as $k){ $uit=AA_UITRUK[$k['soort']]??0; $vertrek=$alarm+$uit*60000;
    try{$r=$directions([$k['lat'],$k['lon']],[(float)$m['lat'],(float)$m['lon']],$vertrek);}catch(Throwable){$r=null;}
    $afstand=$r['km']??aaKmR($k['lat'],$k['lon'],$m['lat'],$m['lon'])*1.3; $rij=$r['min']??($afstand/50)*60; if($spoed)$rij*=0.75;
    $kz[]=['id'=>$k['id'],'naam'=>$k['naam'],'soort'=>$k['soort'],'lat'=>$k['lat'],'lon'=>$k['lon'],'genoemd'=>$genoemd,'km'=>round($afstand*10)/10,'rij_min'=>round($rij*10)/10,'uitruk_min'=>$uit,
      'alarm'=>aaIso($alarm),'vertrek'=>aaIso($vertrek),'aankomst'=>aaIso((int)floor($vertrek+$rij*60000)),'via'=>$r?'route':'schatting',
      'route'=>$r&&count($r['punten']??[])>1?aaDun($r['punten']):[[$k['lat'],$k['lon']],[$m['lat'],$m['lon']]]]; }
  usort($kz,fn($a,$b)=>aaMs($a['aankomst'])<=>aaMs($b['aankomst']));
  return ['guid'=>$m['guid'],'spoed'=>$spoed,'kazernes'=>$kz];
}
function aaRoutesVoor(array $m): array {
  $q=aaDb()->prepare('SELECT data FROM melding_routes WHERE guid = ?'); $q->execute([$m['guid']]); $c=$q->fetchColumn();
  if($c!==false)return json_decode((string)$c,true);
  $data=aaBerekenRoutes($m,fn($f,$t,$v)=>aaMapsDirections($f,$t,$v));
  if($data['kazernes'])aaDb()->prepare('REPLACE INTO melding_routes (guid, data, at) VALUES (?, ?, ?)')->execute([$m['guid'],aaJson($data),aaNow()]);
  return $data;
}

// ------------------------------------------------------------------ pand.js
const AA_GEBRUIK=['woonfunctie'=>'Wonen','winkelfunctie'=>'Winkel','kantoorfunctie'=>'Kantoor','industriefunctie'=>'Industrie','bijeenkomstfunctie'=>'Bijeenkomst',
  'gezondheidszorgfunctie'=>'Zorg','onderwijsfunctie'=>'Onderwijs','logiesfunctie'=>'Logies','sportfunctie'=>'Sport','celfunctie'=>'Cel','overige gebruiksfunctie'=>'Overig'];
function aaAdresUit(mixed $locatie): ?array {
  if(!preg_match('/^(.*?[\p{L}.)])\s+(\d{1,4})\s*([a-zA-Z])?(?:\s*[-\/]\s*\w+)?$/u',trim((string)($locatie??'')),$m)||preg_match('/^[AN]\d/',trim($m[1])))return null;
  return ['straat'=>trim($m[1]),'nummer'=>$m[2].($m[3]??'')];
}
function aaKiesPand(array $features, float $lat, float $lon): ?array {
  $f=null;
  foreach($features as $x){ $g=$x['geometry']??null; $polys=($g['type']??'')==='Polygon'?[$g['coordinates']]:(($g['type']??'')==='MultiPolygon'?$g['coordinates']:[]);
    foreach($polys as $p)if(!empty($p[0])&&aaInRing($p[0],$lat,$lon)){$f=$x;break 2;} }
  $p=$f['properties']??null; if(!$p)return null;
  $g=[]; foreach(explode(',',(string)($p['gebruiksdoel']??'')) as $x){$v=AA_GEBRUIK[trim($x)]??null;if($v&&!in_array($v,$g,true))$g[]=$v;}
  $bj=(int)($p['bouwjaar']??0);
  return ['bouwjaar'=>$bj>1000&&$bj<2100?$bj:null,'gebruik'=>$g,'adressen'=>((int)($p['aantal_verblijfsobjecten']??0))?:null];
}
function aaPandVoor(array $m): ?array {
  $adres=aaAdresUit($m['locatie']??null); if(!$adres||empty($m['stad']))return null;
  $key='pand:'.$m['guid']; $oud=aaKvGet($key); if($oud!==null&&$oud!=='')return $oud==='-'?null:json_decode($oud,true);
  $uit=null;
  try{
    $r=aaAppleHttp('GET','https://api.pdok.nl/bzk/locatieserver/search/v3_1/free?q='.rawurlencode($adres['straat'].' '.$adres['nummer'].' '.$m['stad']).'&fq=type:adres&rows=1&fl=weergavenaam,centroide_ll,straatnaam,huisnummer',['Accept: application/json','User-Agent: A-Alerts/2.0 (+https://a-alerts.nl)'],null,8);
    $doc=$r['status']===200?(json_decode($r['body'],true)['response']['docs'][0]??null):null;
    if($doc&&preg_match('/POINT\((-?[\d.]+) (-?[\d.]+)\)/',(string)($doc['centroide_ll']??''),$pt)&&(string)$doc['huisnummer']===preg_replace('/\D/','',$adres['nummer'])&&mb_strtolower((string)($doc['straatnaam']??''))===mb_strtolower($adres['straat'])){
      $lon=(float)$pt[1];$lat=(float)$pt[2];$d=0.00025;
      $b=aaAppleHttp('GET','https://api.pdok.nl/kadaster/bag/ogc/v2/collections/pand/items?bbox='.($lon-$d).','.($lat-$d).','.($lon+$d).','.($lat+$d).'&f=json&limit=20',['Accept: application/json','User-Agent: A-Alerts/2.0 (+https://a-alerts.nl)'],null,8);
      $pand=$b['status']===200?aaKiesPand(json_decode($b['body'],true)['features']??[],$lat,$lon):null;
      if($pand&&($pand['bouwjaar']||$pand['gebruik']))$uit=$pand+['adres'=>$doc['weergavenaam'],'bron'=>'BAG (Kadaster, via PDOK)'];
    }
    if($r['status']===0)return null; // no answer at all: not cached, try again next time
  }catch(Throwable){ return null; }
  aaKvSet($key,$uit?aaJson($uit):'-');
  return $uit;
}
