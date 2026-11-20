<?php
declare(strict_types=1);
/**
 * A-Alerts engine in PHP: the logic of the Cloudflare Worker (p2000-apeldoorn/worker/src), ported module by module.
 * This file: database, kv, working area and the rules (rules.js, werkgebied.js, plaats.js). No HTTP here.
 */
require_once dirname(__DIR__).'/private-api.php';

function aaNow(): int { return (int)floor(microtime(true)*1000); }
function aaDb(): PDO { return privateDb(); }
/** JS: new Date(ms).toISOString() */
function aaIso(int|float $ms): string { $ms=(int)$ms; $s=intdiv($ms,1000); $r=$ms-$s*1000; if($r<0){$s--;$r+=1000;} return gmdate('Y-m-d\TH:i:s',$s).sprintf('.%03dZ',$r); }
/** JS: Number(new Date(x)) for ISO dates, or null. */
function aaMs(mixed $x): ?int {
  if(is_int($x)||is_float($x))return (int)$x;
  $s=trim((string)$x); if($s==='')return null;
  if(is_numeric($s))return (int)$s;
  try{$d=new DateTimeImmutable($s);}catch(Throwable){return null;}
  return (int)$d->format('Uv');
}
function aaJson(mixed $v): string { return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); }
function aaParse(?string $s, mixed $fallback=[]): mixed { if($s===null||$s==='')return $fallback; $d=json_decode($s,true); return $d===null?$fallback:$d; }

/** Schema of the engine (aa/schema.sql), applied once per deploy version. */
function aaEnsureSchema(PDO $db): void {
  $file=__DIR__.'/schema.sql'; $sql=(string)file_get_contents($file); $ver=hash('sha256',$sql);
  try{ if(aaKvGet('aa_schema')===$ver)return; }catch(Throwable){}
  foreach(preg_split('/;\s*\n/',$sql) as $part){
    $st=trim((string)preg_replace('/^\s*--[^\n]*\n/m','',$part)); if($st==='')continue;
    if(!preg_match('/^CREATE TABLE IF NOT EXISTS [a-z_]+ /',$st))throw new RuntimeException('aa_schema_statement');
    $db->exec($st);
  }
  aaKvSet('aa_schema',$ver);
}

// ------------------------------------------------------------------ kv (kv.js)
function aaKvRow(string $key): ?array { $q=aaDb()->prepare('SELECT value, at FROM kv WHERE `key` = ?'); $q->execute([$key]); $r=$q->fetch(); return $r?:null; }
function aaKvGet(string $key): ?string { $r=aaKvRow($key); return $r?($r['value']===null?null:(string)$r['value']):null; }
function aaKvSet(string $key, string $value): void {
  aaDb()->prepare('INSERT INTO kv (`key`, value, at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), at = VALUES(at)')->execute([$key,$value,aaNow()]);
}

// ------------------------------------------------------------------ working area (werkgebied.js)
function aaData(string $name): array { static $c=[]; return $c[$name]??=json_decode((string)file_get_contents(__DIR__.'/data/'.$name.'.json'),true); }
function aaPlaatsKey(mixed $s): string { return trim((string)preg_replace('/\s+/u',' ',str_replace('-',' ',mb_strtolower((string)($s??''))))); }
function aaKeysOf(array $p): array { return array_map('aaPlaatsKey',array_merge([$p['naam']],$p['aliassen']??[])); }
function aaPlaatsen(): array { return aaData('werkgebied')['plaatsen']; }
function aaWoonplaatsKeys(): array {
  static $k=null; if($k!==null)return $k; $k=[];
  foreach(aaPlaatsen() as $p)if(!empty($p['woonplaats']))foreach(aaKeysOf($p) as $x)$k[$x]=true;
  return $k;
}
function aaWoonplaatsCentrum(mixed $stad): ?array {
  static $m=null;
  if($m===null){$m=[];foreach(aaPlaatsen() as $p)if(!empty($p['woonplaats'])&&is_numeric($p['lat']??null)&&is_numeric($p['lon']??null))foreach(aaKeysOf($p) as $k)$m[$k]??=['naam'=>$p['naam'],'lat'=>$p['lat'],'lon'=>$p['lon']];}
  return $m[aaPlaatsKey($stad)]??null;
}
function aaDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
  $r=M_PI/180; $dLat=($lat2-$lat1)*$r; $dLon=($lon2-$lon1)*$r;
  $a=sin($dLat/2)**2+cos($lat1*$r)*cos($lat2*$r)*sin($dLon/2)**2;
  return 6371*2*atan2(sqrt($a),sqrt(1-$a));
}
function aaInRing(array $ring, float $lat, float $lon): bool {
  $in=false; $n=count($ring);
  for($i=0,$j=$n-1;$i<$n;$j=$i++){
    [$xi,$yi]=$ring[$i]; [$xj,$yj]=$ring[$j];
    if((($yi>$lat)!==($yj>$lat)) && $lon<(($xj-$xi)*($lat-$yi))/($yj-$yi)+$xi)$in=!$in;
  }
  return $in;
}
function aaDistanceToRingKm(array $ring, float $lat, float $lon): float {
  $R=M_PI/180; $kx=111.32*cos($lat*$R); $ky=110.57; $best=INF; $n=count($ring);
  for($i=0,$j=$n-1;$i<$n;$j=$i++){
    $ax=($ring[$j][0]-$lon)*$kx; $ay=($ring[$j][1]-$lat)*$ky; $bx=($ring[$i][0]-$lon)*$kx; $by=($ring[$i][1]-$lat)*$ky;
    $dx=$bx-$ax; $dy=$by-$ay; $len=$dx*$dx+$dy*$dy;
    $t=$len>0?max(0,min(1,-($ax*$dx+$ay*$dy)/$len)):0;
    $best=min($best,hypot($ax+$t*$dx,$ay+$t*$dy));
  }
  return $best;
}
function aaPlaceContains(array $p, float $lat, float $lon): bool {
  if(!empty($p['gebied']))foreach($p['gebied'] as $ring)if(aaInRing($ring,$lat,$lon))return true;
  if(!empty($p['straalKm']))return aaDistanceKm($lat,$lon,(float)$p['lat'],(float)$p['lon'])<=$p['straalKm'];
  return false;
}
function aaHasStreetLoc(mixed $loc): bool { $l=mb_strtolower(trim((string)($loc??''))); return $l!==''&&$l!=='onbekend'; }
/** All places an alert belongs to. */
function aaPlaatsenFor(array $m): array {
  $out=[]; $stad=aaPlaatsKey($m['stad']??'');
  foreach(aaPlaatsen() as $p)if(!empty($p['woonplaats'])&&in_array($stad,aaKeysOf($p),true)){$out[$p['naam']]=true;break;}
  if(aaHasStreetLoc($m['locatie']??null)&&($m['lat']??null)!==null&&($m['lon']??null)!==null)
    foreach(aaPlaatsen() as $p)if((!empty($p['gebied'])||!empty($p['straalKm']))&&aaPlaceContains($p,(float)$m['lat'],(float)$m['lon']))$out[$p['naam']]=true;
  return array_keys($out);
}
function aaMatchesPlaatsen(?array $selected, array $m): bool {
  if(!$selected)return true;
  $chosen=array_flip(array_map('aaPlaatsKey',$selected));
  foreach(aaPlaatsenFor($m) as $p)if(isset($chosen[aaPlaatsKey($p)]))return true;
  return isset($chosen[aaPlaatsKey($m['stad']??'')]);
}
function aaInWerkgebied(?float $lat, ?float $lon, float $tol=0.5): bool {
  if($lat===null||$lon===null)return false;
  $rings=aaData('werkgebied')['gemeente']['gebied'];
  foreach($rings as $r)if(aaInRing($r,$lat,$lon))return true;
  foreach(aaPlaatsen() as $p)if(aaPlaceContains($p,$lat,$lon))return true;
  foreach($rings as $r)if(aaDistanceToRingKm($r,$lat,$lon)<=$tol)return true;
  return false;
}
function aaPolygonOverlaps(mixed $rings): bool {
  if(!is_array($rings)||!$rings)return false;
  foreach($rings as $ring){
    foreach($ring as [$lon,$lat])if(aaInWerkgebied((float)$lat,(float)$lon,0))return true;
    foreach(aaPlaatsen() as $p)if(aaInRing($ring,(float)$p['lat'],(float)$p['lon']))return true;
  }
  return false;
}
/** plaats.js: nearest Dutch place, or null above sea / abroad. */
function aaPlaatsBij(mixed $lat, mixed $lon): ?array {
  if(!is_float($lat)&&!is_int($lat)||!is_float($lon)&&!is_int($lon))return null;
  $c=cos($lat*M_PI/180); $best=null; $bd=INF;
  foreach(aaData('woonplaatsen') as $p){ $dy=$p[2]-$lat; $dx=($p[3]-$lon)*$c; $d=$dx*$dx+$dy*$dy; if($d<$bd){$bd=$d;$best=$p;} }
  if(!$best||sqrt($bd)*111.2>7)return null;
  [$plaats,$gem]=$best;
  return ['plaats'=>$plaats,'gemeente'=>$gem,'tekst'=>$gem&&$gem!==$plaats&&!str_contains($plaats,$gem)?"$plaats, $gem":$plaats];
}

// ------------------------------------------------------------------ rules.js
function aaPrioriteit(string $text): ?string {
  if(preg_match('/^\s*([AB][0-3])\b/i',$text,$m))return strtoupper($m[1]);
  if(preg_match('/^\s*P\s?([1-3])\b/i',$text,$m)||preg_match('/^\s*Prio\s*([1-5])\b/i',$text,$m))return 'P'.$m[1];
  return null;
}
const AA_RULES=[
  ['/BR CONTAINER|CONTAINERBRAND|CONTAINER BRAND/u','Afvalcontainer brand'],
  ['/BR (WONING|GEBOUW).*ZOLDER|ZOLDERBRAND/u','Woningbrand (zolder)'],
  ['/BR WONING|WONINGBRAND|BR FLAT|BR APPARTEMENT/u','Woningbrand'],
  ['/BR (VOERTUIG|AUTO|BUS|VRACHTWAGEN)|VOERTUIGBRAND|AUTOBRAND/u','Voertuigbrand'],
  ['/BR (BUITEN|AFVAL|BERM|BOS|NATUUR|HEIDE)|BUITENBRAND|NATUURBRAND/u','Buitenbrand (afval)'],
  ['/BR (SCHUUR|BIJGEBOUW|SCHUURTJE|GARAGE)/u','Brand bijgebouw'],
  ['/BR (GEBOUW|BEDRIJF|INDUSTRIE|WINKEL|KANTOOR|SCHOOL|HORECA)|GEBOUWBRAND|BEDRIJFSBRAND/u','Gebouwbrand'],
  ['/BRANDGERUCHT/u','Brandgerucht'],
  ['/\bOMS\b|AUTOM\.? ?BRAND|AUTOMATISCH BRANDALARM|\bPAC\b|BRANDMELDINSTALLATIE/u','Automatisch brandalarm'],
  ['/CO[- ]?MELDER|ROOKMELDER|KOOLMONOXIDE/u','Rookmelder'],
  ['/GASLUCHT|GASLEK|GASLEKKAGE/u','Gaslucht'],
  ['/STANK|HIND\.? ?LUCHT|GEURHINDER/u','Stank (geurhinder)'],
  ['/LIFT ?OPSLUITING|OPSLUITING LIFT/u','Liftopsluiting'],
  ['/WATEROVERLAST/u','Wateroverlast'],
  ['/STORMSCHADE|\bSTORM\b/u','Stormschade'],
  ['/VOERTUIG TE WATER|\bVTW\b/u','Voertuig te water'],
  ['/PERSOON TE WATER|\bPTW\b/u','Persoon te water'],
  ['/\bDIER(EN)?\b|\bPAARD(EN)?\b|\bKOE(IEN)?\b|\bHOND(EN)?\b/u','Dier in problemen'],
  ['/AFHIJSEN/u','Assistentie afhijsen patient'],
  ['/\bASS\.? ?AMBU|TILASSISTENTIE|ASSISTENTIE AMBU/u','Assistentie ambulance'],
  ['/BUITENGESLOTEN/u','Persoon buitengesloten'],
  ['/(AANRIJDING|AANR\.?).*LETSEL/u','Aanrijding met letsel'],
  ['/ONGEVAL.*LETSEL|OVL\b/u','Ongeval met letsel'],
  ['/VERKEERSONGEVAL|ONGEVAL WEGVERVOER/u','Verkeersongeval'],
  ['/ONGEVAL|AANRIJDING/u','Ongeval melding'],
  ['/HERBEZET|KAZERNEREN/u','Herbezetting brandweer'],
  ['/NACONTROLE/u','Nacontrole'],
  ['/OEFENING|PROEFALARM|TESTOPROEP/u','Oefening'],
  ['/DIENSTVERLENING/u','Dienstverlening verzoek'],
];
function aaCategorie(?string $dienst, ?string $prio, string $text, mixed $locatie=null): ?string {
  $straat=trim((string)($locatie??''));
  $kern=mb_strlen($straat)>=4?implode(' ',preg_split('/'.preg_quote($straat,'/').'/iu',$text)):$text;
  $t=mb_strtoupper($kern);
  if(preg_match('/REANIM/u',$t))return $dienst==='ambulance'?'Hartstilstand (reanimatie)':'Assistentie bij reanimatie';
  if($dienst==='traumaheli')return 'Traumaheli persoon in levensgevaar';
  if($dienst==='ambulance'){
    if(preg_match('/HERPOS|PARAAT/u',$t))return 'Herpositioneren (paraatstelling)';
    if(preg_match('/\b112\b/u',$t))return 'Ambulance naar 112 melding';
    if(preg_match('/ASSISTENTIE|ASS\.? ?POL/u',$t))return 'Assistentie bij politie';
    if($prio==='B1')return 'Besteld vervoer met medicatie';
    if($prio==='B2'||$prio==='B3')return 'Besteld vervoer zonder medicatie';
    if(preg_match('/\bDIA\b/u',$t))return 'Directe inzet ambulance';
    if($prio==='A0'||$prio==='A1')return 'Ambulance spoedurgentie';
    if($prio==='A2')return 'Ambulance met gepaste spoed';
    return 'Medische assistentie';
  }
  foreach(AA_RULES as [$re,$cat])if(preg_match($re,$t))return $cat;
  if($dienst==='politie')return preg_match('/\b112\b/u',$t)?'Politie naar 112 melding':'Politie-inzet';
  if($dienst==='brandweer')return preg_match('/\bBR\b|BRAND/u',$t)?'Brand melding':'Brandweer-inzet';
  return null;
}
const AA_DIENSTEN=['brandweer','ambulance','politie','traumaheli','kustwacht'];
function aaParseLatLon(mixed $s): ?array {
  if($s===null||$s==='')return null;
  $p=explode(',',(string)$s); $a=isset($p[0])&&is_numeric(trim($p[0]))?(float)trim($p[0]):NAN; $b=isset($p[1])&&is_numeric(trim($p[1]))?(float)trim($p[1]):NAN;
  return is_finite($a)&&is_finite($b)&&$a!=0.0?[$a,$b]:null;
}
/** rules.js fromFeed: one p2000.page record → alert, or null outside the area. */
function aaFromFeed(array $r): ?array {
  if(empty($r['uid'])||empty($r['melding'])||!isset(aaWoonplaatsKeys()[aaPlaatsKey($r['plaats']??'')]))return null;
  $text=trim((string)$r['melding']); $prio=aaPrioriteit($text);
  $caps=aaParse((string)($r['capcodes_json']??''),[]); $caps=is_array($caps['capcodes']??null)?$caps['capcodes']:[];
  $units=[];
  foreach($caps as $c){ if(!is_array($c))continue; $parts=explode(' / ',(string)($c['label']??''));
    $u=['code'=>$c['capcode']??null,'naam'=>($parts[0]??'')!==''?$parts[0]:null,'type'=>($parts[1]??'')!==''?$parts[1]:null,'regio'=>($parts[2]??'')!==''?$parts[2]:null];
    if($u['code']&&!preg_match('/^(monitorcode|lichtkrant)/i',(string)$u['naam']))$units[]=$u; }
  $dienst=mb_strtolower((string)($r['dienst']??''));
  foreach($units as $u)if(preg_match('/lifeliner|traumaheli|\bmmt\b/i',(string)$u['naam']))$dienst='traumaheli';
  if(preg_match('/lifeliner|\bmmt\b/i',$text))$dienst='traumaheli';
  if(!in_array($dienst,AA_DIENSTEN,true))$dienst='onbekend';
  $coord=aaParseLatLon($r['latlong']??null)??aaParseLatLon($r['plaats_latlon']??null);
  $title=$prio?(string)preg_replace('/^\s*(?:Prio\s*\d|P\s?\d|[AB]\d)\s*/i','',$text):$text;
  $datum=is_numeric($r['timestamp']??null)&&(float)$r['timestamp']>0?(int)((float)$r['timestamp']*1000):aaNow();
  $veh=aaParse((string)($r['voertuigen_json']??''),[]); $veh=is_array($veh['voertuig']??null)?$veh['voertuig']:[];
  $vl=[]; foreach(['groot'=>'groot','kruising'=>'kruising','spam'=>'antiespam','groep'=>'groep'] as $k=>$f){$v=$r[$f]??null;if($v!==null&&(string)$v!=='0'&&(string)$v!=='')$vl[]=$k;}
  $loc=($r['locatie']??'')!==''?$r['locatie']:null;
  return ['guid'=>(string)$r['uid'],'title'=>trim($title),'full_melding'=>$text,'dienst'=>$dienst,'prioriteit'=>$prio,'datum'=>$datum,
    'locatie'=>$loc,'lat'=>$coord[0]??null,'lon'=>$coord[1]??null,'stad'=>$r['plaats'],'regio'=>($r['vr']??'')?:(($r['regio']??'')?:null),
    'capcode'=>$units[0]['code']??null,'categorie'=>aaCategorie($dienst,$prio,$text,$loc),
    'grip'=>!empty($r['grip'])&&$r['grip']!=='0'?$r['grip']:null,'opschaling'=>($r['soortopschaling']??'')?:null,
    'eenheden'=>$units,'voertuigen'=>$veh,'link'=>($r['link']??'')?:'https://p2000.page/api','vlaggen'=>$vl?implode(',',$vl):null,'bron'=>'p2000page'];
}

// ------------------------------------------------------------------ koyeb-feed.js
function aaClean(mixed $v): string { return trim((string)($v??'')); }
function aaNormalizedText(mixed $v): string {
  $s=Normalizer::normalize(aaClean($v),Normalizer::FORM_KD); $s=(string)preg_replace('/\p{Mn}/u','',(string)$s);
  $s=mb_strtolower($s); $s=(string)preg_replace('/\s*:\s*/u',':',$s); return (string)preg_replace('/\s+/u',' ',$s);
}
function aaDedupKey(array $m): string {
  return implode('|',[(string)floor((float)$m['datum']/60000),aaPlaatsKey($m['stad']??''),mb_strtolower(aaClean($m['dienst']??'')),aaNormalizedText(($m['full_melding']??'')?:($m['title']??''))]);
}
function aaSameDispatch(array $a, array $b): bool {
  if(!empty($a['guid'])&&!empty($b['guid'])&&$a['guid']===$b['guid'])return true;
  if(!is_numeric($a['datum']??null)||!is_numeric($b['datum']??null))return false;
  if(abs((float)$a['datum']-(float)$b['datum'])>120000)return false;
  if(aaPlaatsKey($a['stad']??'')!==aaPlaatsKey($b['stad']??''))return false;
  if(mb_strtolower(aaClean($a['dienst']??''))!==mb_strtolower(aaClean($b['dienst']??'')))return false;
  if(aaNormalizedText(($a['full_melding']??'')?:($a['title']??''))!==aaNormalizedText(($b['full_melding']??'')?:($b['title']??'')))return false;
  if(!empty($a['capcode'])&&!empty($b['capcode'])&&aaClean($a['capcode'])!==aaClean($b['capcode']))return false;
  return true;
}
function aaFromKoyeb(mixed $r): ?array {
  if(!is_array($r))return null;
  $guid=aaClean($r['id']??''); $stad=aaClean($r['stad']??''); $raw=aaClean($r['p2000']??'');
  $stampRaw=$r['date']['timestamp']??($r['timestamp']??null); $stamp=is_numeric($stampRaw)?(float)$stampRaw:NAN;
  if(!preg_match('/^[0-9a-f-]{16,64}$/i',$guid)||!isset(aaWoonplaatsKeys()[aaPlaatsKey($stad)])||$raw===''||!is_finite($stamp)||$stamp<=0||$stamp*1000>aaNow()+300000)return null;
  $dienst=mb_strtolower(aaClean($r['dienst']??''));
  $units=[];
  foreach(is_array($r['capcodes']??null)?$r['capcodes']:[] as $c){ if(!is_array($c))continue; $parts=explode(' / ',aaClean($c['label']??''));
    $u=['code'=>aaClean($c['capcode']??''),'naam'=>($parts[0]??'')!==''?$parts[0]:null,'type'=>($parts[1]??'')!==''?$parts[1]:null,'regio'=>($parts[2]??'')!==''?$parts[2]:null];
    if($u['code']!==''&&!preg_match('/^(monitorcode|lichtkrant)/i',(string)$u['naam']))$units[]=$u; }
  foreach($units as $u)if(preg_match('/lifeliner|traumaheli|\bmmt\b/i',(string)$u['naam']))$dienst='traumaheli';
  if(preg_match('/lifeliner|\bmmt\b/i',$raw))$dienst='traumaheli';
  if(!in_array($dienst,AA_DIENSTEN,true))$dienst='onbekend';
  $prio=aaPrioriteit($raw); $straat=aaClean($r['straat']??''); $locatie=$straat!==''&&mb_strtolower($straat)!=='onbekend'?$straat:null;
  $title=$prio?(string)preg_replace('/^\s*(?:Prio\s*\d|P\s?\d|[AB]\d)\s*/i','',$raw):$raw;
  $lat=is_numeric($r['lat']??null)?(float)$r['lat']:null; $lon=is_numeric($r['lng']??null)?(float)$r['lng']:null;
  $grip=aaClean($r['grip']??'');
  $m=['guid'=>$guid,'title'=>trim($title),'full_melding'=>$raw,'dienst'=>$dienst,'prioriteit'=>$prio,'datum'=>(int)($stamp*1000),'locatie'=>$locatie,
    'lat'=>$lat!==null&&$lat>=-90&&$lat<=90?$lat:null,'lon'=>$lon!==null&&$lon>=-180&&$lon<=180?$lon:null,
    'stad'=>$stad,'regio'=>aaClean($r['regio']??'')?:null,'capcode'=>$units[0]['code']??null,
    'categorie'=>aaClean($r['categorie']??'')?:aaCategorie($dienst,$prio,$raw,$locatie),
    'grip'=>$grip!==''&&$grip!=='0'?$grip:null,'opschaling'=>aaClean($r['soortopschaling']??'')?:null,'eenheden'=>$units,
    'voertuigen'=>is_array($r['voertuigen']??null)?$r['voertuigen']:[],'link'=>'https://p2000.page/api','vlaggen'=>null,'bron'=>'koyeb'];
  $m['dedup_key']=aaDedupKey($m);
  return $m;
}
