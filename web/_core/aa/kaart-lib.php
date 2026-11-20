<?php
declare(strict_types=1);
/**
 * Rain radar (radar.js, KNMI Data Platform WMS) and the square map image (kaart.js, Apple Maps Web Snapshots).
 * Images are cached as files: radar 5 minutes, map images 7 days, like the edge cache of the Worker.
 */
require_once __DIR__.'/base.php';

const AA_WMS='https://api.dataplatform.knmi.nl/wms/adaguc-server';
const AA_WMS_ANON='https://anonymous.api.dataplatform.knmi.nl/wms/adaguc-server';
const AA_HALF=20037508.342789244;
const AA_RADAR_BBOX=['west'=>2.6,'east'=>7.8,'south'=>50.5,'north'=>53.9];
function aaRadarConfigured(): bool { return setting('KNMI_WMS_KEY')!==''; }
function aaMx(float $lon): float { return ($lon*AA_HALF)/180; }
function aaMy(float $lat): float { return (log(tan(((90+$lat)*M_PI)/360))*AA_HALF)/M_PI; }
function aaTileBbox(int $z, int $x, int $y): array { $n=2**$z; $size=(2*AA_HALF)/$n; $minx=-AA_HALF+$x*$size; $maxy=AA_HALF-$y*$size; return [$minx,$maxy-$size,$minx+$size,$maxy]; }
/** JS Number.toFixed(1) */
function aaToFixed1(float $v): string { $r=aaLRoundHalf($v*10)/10; return number_format($r,1,'.',''); }
function aaLRoundHalf(float $x): float { return $x<0?-floor(-$x+0.5):floor($x+0.5); }
function aaWmsUrl(array $bbox, int $w, int $h): string {
  return '?'.http_build_query(['DATASET'=>'radar_reflectivity_composites','SERVICE'=>'WMS','VERSION'=>'1.3.0','REQUEST'=>'GetMap','LAYERS'=>'precipitation','STYLES'=>'',
    'CRS'=>'EPSG:3857','BBOX'=>implode(',',array_map('aaToFixed1',$bbox)),'WIDTH'=>(string)$w,'HEIGHT'=>(string)$h,'FORMAT'=>'image/png','TRANSPARENT'=>'TRUE'],'','&',PHP_QUERY_RFC1738);
}
function aaRadarSlot(): int { return intdiv(aaNow(),300000); }
function aaRadarInfo(string $base): array {
  if(!aaRadarConfigured())return ['beschikbaar'=>false];
  $s=aaRadarSlot();
  return ['beschikbaar'=>true,'bbox'=>AA_RADAR_BBOX,'url'=>"$base/v1/radar.png?t=$s",'tiles'=>"$base/v1/radar/{z}/{x}/{y}.png?t=$s",'bron'=>'KNMI'];
}
function aaCacheDir(string $sub): string { $d=sys_get_temp_dir().'/aalerts-'.$sub.'-'.substr(hash('sha256',__DIR__),0,12); if(!is_dir($d))@mkdir($d,0700,true); return $d; }
function aaHttpGet(string $url, array $headers, int $timeout): array {
  $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false]);
  $b=curl_exec($ch); $c=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); return [$c,is_string($b)?$b:''];
}
function aaPng(string $bytes, int $maxAge): never {
  http_response_code(200); header('Content-Type: image/png'); header('Cache-Control: public, max-age='.$maxAge); header('Access-Control-Allow-Origin: *');
  echo $bytes; exit;
}
function aaCachedRadar(string $key, string $q): never {
  $f=aaCacheDir('radar').'/'.preg_replace('/[^a-z0-9-]/','',$key).'.png';
  if(is_file($f)&&time()-filemtime($f)<300)aaPng((string)file_get_contents($f),300);
  [$c,$b]=aaHttpGet(AA_WMS.$q,['Authorization: '.setting('KNMI_WMS_KEY')],12);
  if($c===401||$c===403)[$c,$b]=aaHttpGet(AA_WMS_ANON.$q,[],12);
  if($c<200||$c>=300){ http_response_code(502); header('Content-Type: application/json'); header('Access-Control-Allow-Origin: *'); echo json_encode(['error'=>'KNMI '.$c]); exit; }
  @file_put_contents($f,$b);
  foreach(glob(dirname($f).'/*.png')?:[] as $old)if(time()-@filemtime($old)>900)@unlink($old);
  aaPng($b,300);
}
function aaRadarImage(): never {
  $b=AA_RADAR_BBOX; $bbox=[aaMx($b['west']),aaMy($b['south']),aaMx($b['east']),aaMy($b['north'])];
  $w=800; $h=(int)aaLRoundHalf(($w*($bbox[3]-$bbox[1]))/($bbox[2]-$bbox[0]));
  aaCachedRadar('nl-'.aaRadarSlot(),aaWmsUrl($bbox,$w,$h));
}
function aaNoContent(): never { http_response_code(204); header('Access-Control-Allow-Origin: *'); exit; }
function aaRadarTile(int $z, int $x, int $y): never {
  if($z<5||$z>12)aaNoContent();
  [$minx,$miny,$maxx,$maxy]=aaTileBbox($z,$x,$y); $b=AA_RADAR_BBOX;
  if($maxx<aaMx($b['west'])||$minx>aaMx($b['east'])||$maxy<aaMy($b['south'])||$miny>aaMy($b['north']))aaNoContent();
  aaCachedRadar("t-$z-$x-$y-".aaRadarSlot(),aaWmsUrl(aaTileBbox($z,$x,$y),256,256));
}

// ------------------------------------------------------------------ kaart.js
const AA_MARKERS=['brandweer'=>['marker_brandweer.png',48,48,1.5],'ambulance'=>['marker_ambulance.png',48,48,1.5],'politie'=>['marker_politie.png',48,48,1.5],
  'traumaheli'=>['marker_traumaheli.png',48,48,1.5],'burgernet'=>['marker_burgernet.png',36,44,0],'nlalert'=>['marker_nlalert.png',36,44,0],
  'amber'=>['marker_amber.png',36,44,0],'vermistkind'=>['marker_vermistkind.png',36,44,0],'vnog'=>['marker_vnog2.png',36,44,0]];
// Same as aaPem in apple.php; guarded so both files can load in one request.
if(!function_exists('aaPem')){ function aaPem(mixed $v): string { return trim(str_replace('\n',"\n",(string)($v??''))); } }
function aaKaartKey(): array { return ['kid'=>setting('WEB_MAPKIT_JS_KEY_ID'),'tid'=>setting('WEB_MAPKIT_JS_TEAM_ID')?:setting('APN_TEAM_ID'),'p8'=>setting('WEB_MAPKIT_JS_P8')]; }
function aaKaartConfigured(): bool { $k=aaKaartKey(); return $k['kid']!==''&&$k['tid']!==''&&str_contains(aaPem($k['p8']),'PRIVATE KEY'); }
// PHP function names ignore case: aaB64url in apple.php is the same name, so declare it only once.
if(!function_exists('aaB64Url')){ function aaB64Url(string $b): string { return rtrim(strtr(base64_encode($b),'+/','-_'),'='); } }
/** ES256 over data; DER (openssl) converted to raw r||s as WebCrypto gives it. */
function aaSignRawEs256(string $p8, string $data): string {
  $key=openssl_pkey_get_private(aaPem($p8)); if(!$key||!openssl_sign($data,$der,$key,OPENSSL_ALGO_SHA256))throw new RuntimeException('es256_sign');
  $o=2; if(ord($der[1])&0x80)$o+=ord($der[1])&0x7f;
  $lr=ord($der[$o+1]); $r=substr($der,$o+2,$lr); $o+=2+$lr; $ls=ord($der[$o+1]); $s=substr($der,$o+2,$ls);
  $fix=fn($x)=>str_pad(ltrim($x,"\0"),32,"\0",STR_PAD_LEFT);
  return aaB64Url($fix($r).$fix($s));
}
function aaWorld(float $lat, float $lon, float $z): array { $s=256*2**$z; $r=$lat*M_PI/180; return [(($lon+180)/360)*$s,((1-log(tan($r)+1/cos($r))/M_PI)/2)*$s]; }
function aaUnworld(float $x, float $y, float $z): array { $s=256*2**$z; return [(atan(sinh(M_PI*(1-(2*$y)/$s)))*180)/M_PI,($x/$s)*360-180]; }
/** kaartParams: null when not allowed. */
function aaKaartParams(array $q): ?array {
  $c=array_map(fn($v)=>is_numeric(trim($v))?(float)$v:NAN,explode(',',(string)($q['c']??'')));
  $zr=is_numeric($q['z']??null)?(float)$q['z']:NAN; $z=aaLRoundHalf($zr*10)/10; $m=(string)($q['m']??'');
  if(count($c)!==2||!is_finite($c[0])||!is_finite($c[1]))return null;
  [$lat,$lon]=$c;
  if($lat<50.5||$lat>53.8||$lon<3||$lon>7.5||!($z>=7&&$z<=17))return null;
  if($m!==''&&!isset(AA_MARKERS[$m]))return null;
  return ['lat'=>aaLRoundHalf($lat*1e5)/1e5,'lon'=>aaLRoundHalf($lon*1e5)/1e5,'z'=>$z,'m'=>$m];
}
function aaJsNum(float $v): string { return json_encode(floor($v)==$v&&abs($v)<1e15?(int)$v:$v); }
function aaSnapshotPath(array $p): string {
  $k=aaKaartKey();
  $q=[['center',aaJsNum($p['lat']).','.aaJsNum($p['lon'])],['z',aaJsNum($p['z'])],['size','600x600'],['scale','2'],['colorScheme','light'],['lang','nl-NL']];
  if($p['m']!==''){
    [$file,$w,$h,$onder]=AA_MARKERS[$p['m']];
    [$x,$y]=aaWorld($p['lat'],$p['lon'],$p['z']); [$plat,$plon]=aaUnworld($x,$y-($h/2-$onder),$p['z']);
    $q[]=['annotations',json_encode([['point'=>number_format($plat,6,'.','').','.number_format($plon,6,'.',''),'markerStyle'=>'img','imgIdx'=>0]],JSON_UNESCAPED_SLASHES)];
    $q[]=['imgs',json_encode([['url'=>'https://a-alerts.nl/assets/img/'.$file,'width'=>$w,'height'=>$h]],JSON_UNESCAPED_SLASHES)];
  }
  $q[]=['teamId',$k['tid']]; $q[]=['keyId',$k['kid']];
  $path='/api/v1/snapshot?'.implode('&',array_map(fn($x)=>$x[0].'='.aaEncodeURIComponent($x[1]),$q));
  return $path.'&signature='.aaSignRawEs256($k['p8'],$path);
}
/** JS encodeURIComponent */
function aaEncodeURIComponent(string $s): string { return strtr(rawurlencode($s),['%21'=>'!','%2A'=>'*','%27'=>"'",'%28'=>'(','%29'=>')']); }
function aaKaartImage(array $q): never {
  $txt=function(int $s,string $m){http_response_code($s);header('Content-Type: text/plain;charset=UTF-8');echo $m;exit;};
  if(!aaKaartConfigured())$txt(503,'Kaart niet beschikbaar');
  $p=aaKaartParams($q); if(!$p)$txt(400,'Ongeldige kaart');
  $f=aaCacheDir('kaart').'/'.hash('sha256',$p['lat'].','.$p['lon'].','.$p['z'].','.$p['m']).'.png';
  if(is_file($f)&&time()-filemtime($f)<604800)aaPng((string)file_get_contents($f),604800);
  [$c,$b]=aaHttpGet('https://snapshot.apple-mapkit.com'.aaSnapshotPath($p),[],15);
  if($c<200||$c>=300)$txt(502,"Kaart mislukt ($c)");
  @file_put_contents($f,$b); aaPng($b,604800);
}
