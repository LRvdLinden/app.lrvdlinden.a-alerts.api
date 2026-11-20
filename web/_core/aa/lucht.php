<?php
declare(strict_types=1);
/**
 * Helicopters (trauma, police) and the layer "Defensie en overheid" from adsb.lol (ODbL): heli.js and lucht.js.
 * One upstream request per 12 seconds for every caller together (file cache with a lock), as the Worker does with its
 * memo and edge cache. The last known positions are kept 90 seconds so the map does not flicker.
 */
require_once __DIR__.'/base.php';

const AA_HELIS=[
  ['id'=>'lifeliner1','naam'=>'Lifeliner 1','soort'=>'trauma','basis'=>'Amsterdam','callsigns'=>['LIFELN1','LIFELN01']],
  ['id'=>'lifeliner2','naam'=>'Lifeliner 2','soort'=>'trauma','basis'=>'Rotterdam','callsigns'=>['LIFELN2','LIFELN02']],
  ['id'=>'lifeliner3','naam'=>'Lifeliner 3','soort'=>'trauma','basis'=>'Volkel','callsigns'=>['LIFELN3','LIFELN03']],
  ['id'=>'lifeliner4','naam'=>'Lifeliner 4','soort'=>'trauma','basis'=>'Groningen','callsigns'=>['LIFELN4','LIFELN04']],
  ['id'=>'medic01','naam'=>'Medic 01','soort'=>'trauma','medic'=>true,'basis'=>'Leeuwarden','callsigns'=>['MEDIC01','MEDIC1'],'regs'=>['PH-OOP']],
  ['id'=>'zulu01','naam'=>'Zulu 01','soort'=>'politie','callsigns'=>['ZXP01'],'regs'=>['PH-PXA']],
  ['id'=>'zulu02','naam'=>'Zulu 02','soort'=>'politie','callsigns'=>['ZXP02'],'regs'=>['PH-PXB']],
  ['id'=>'zulu03','naam'=>'Zulu 03','soort'=>'politie','callsigns'=>['ZXP03'],'regs'=>['PH-PXC']],
  ['id'=>'zulu04','naam'=>'Zulu 04','soort'=>'politie','callsigns'=>['ZXP04'],'regs'=>['PH-PXD']],
  ['id'=>'zulu05','naam'=>'Zulu 05','soort'=>'politie','callsigns'=>['ZXP05'],'regs'=>['PH-PXE']],
  ['id'=>'zulu06','naam'=>'Zulu 06','soort'=>'politie','callsigns'=>['ZXP06'],'regs'=>['PH-PXF']],
  ['id'=>'zulu24','naam'=>'Zulu 24','soort'=>'politie','callsigns'=>['ZXP24'],'regs'=>['PH-PXX']],
  ['id'=>'zulu25','naam'=>'Zulu 25','soort'=>'politie','callsigns'=>['ZXP25'],'regs'=>['PH-PXY']],
  ['id'=>'zulu26','naam'=>'Zulu 26','soort'=>'politie','callsigns'=>['ZXP26'],'regs'=>['PH-PXZ']],
];
const AA_HELI_TYPES=['EC35','EC45','A139','H135','H145','EC30','A169','H160'];
const AA_AIRCRAFT_HOLD_MS=90000, AA_AIRCRAFT_STALE_MS=60000;
const AA_LUCHT_TYPES=['H47'=>['chinook','Chinook'],'H64'=>['apache','Apache'],'AS32'=>['cougar','Cougar'],'AS3B'=>['cougar','Cougar'],'NH90'=>['nh90','NH90'],
  'F35'=>['f35','F-35'],'F16'=>['f35','F-16'],'C130'=>['hercules','Hercules'],'C30J'=>['hercules','Hercules'],'A400'=>['hercules','A400M'],'PC7'=>['les','Pilatus PC-7'],'PC21'=>['les','Pilatus PC-21']];
const AA_KUSTWACHT=['PH-CGD'=>'kustwacht_dash8','PH-CGE'=>'kustwacht_dash8','C-FCGE'=>'kustwacht_dash8','PH-NCG'=>'kustwacht_aw189','PH-SAR'=>'kustwacht_aw189','PH-RSQ'=>'kustwacht_aw189','PH-BSR'=>'kustwacht_aw189'];
const AA_KUSTWACHT_HEX=['486449'=>'PH-SAR','48644b'=>'PH-NCG'];
const AA_KMAR=['D-IAMT','D-IBMT'];
const AA_LUCHT_NAMEN=['GL5T'=>'Global 5000','GLF6'=>'Gulfstream G650','GLF5'=>'Gulfstream G550','A332'=>'A330 tanker','A333'=>'A330','A310'=>'A310','A343'=>'A340',
  'K35R'=>'KC-135 tanker','K35E'=>'KC-135 tanker','C17'=>'C-17','E3TF'=>'AWACS','E3CF'=>'AWACS','P8'=>'P-8 Poseidon','C295'=>'C-295','CN35'=>'CN-235',
  'EUFI'=>'Eurofighter','TOR'=>'Tornado','F18'=>'F-18','F15'=>'F-15','RFAL'=>'Rafale','HAWK'=>'Hawk','B350'=>'King Air','BE20'=>'King Air','D228'=>'Dornier 228',
  'H60'=>'Black Hawk','H53'=>'CH-53','TIGR'=>'Tiger','EC45'=>'H145M','H145'=>'H145M','LYNX'=>'Lynx','EH10'=>'Merlin','A149'=>'AW149','EC25'=>'Caracal','H225'=>'Caracal','UH1'=>'Huey'];
const AA_JAGERS=['F35','F16','EUFI','TOR','F18','F15','RFAL'];
const AA_LUCHT_HELI=['H47','H64','AS32','AS3B','NH90','H60','EC25','H225','EC45','EC35','A139','A189','LYNX','MRF1','TIGR','H53','S61','S92','A149','AW10','EH10','UH1','B412','B06'];

function aaHeliById(string $id): ?array { foreach(AA_HELIS as $h)if($h['id']===$id)return $h; return null; }
function aaIdentify(array $ac, array $learned): ?array {
  $cs=strtoupper(trim((string)($ac['flight']??''))); $reg=strtoupper(trim((string)($ac['r']??''))); $hex=strtolower(trim((string)($ac['hex']??'')));
  foreach(AA_HELIS as $h)if(($cs!==''&&in_array($cs,$h['callsigns'],true))||($reg!==''&&in_array($reg,$h['regs']??[],true)))return $h+['herkenning'=>$cs!==''&&in_array($cs,$h['callsigns'],true)?'callsign':'registratie'];
  if(preg_match('/^(?:LIFELN|LIFELINER|LIFE)0?([1-9])$/',$cs,$m)&&aaHeliById('lifeliner'.$m[1]))return aaHeliById('lifeliner'.$m[1])+['herkenning'=>'callsign'];
  if(preg_match('/^MEDIC0?([1-9])$/',$cs,$m))return (aaHeliById('medic0'.$m[1])??['id'=>'medic0'.$m[1],'naam'=>'Medic 0'.$m[1],'soort'=>'trauma','medic'=>true,'callsigns'=>[]])+['herkenning'=>'callsign'];
  if(preg_match('/^(?:ZXP|ZULU|POLITIE)0?(\d{1,2})$/',$cs,$m)){ $nr=str_pad($m[1],2,'0',STR_PAD_LEFT); return (aaHeliById('zulu'.$nr)??['id'=>'zulu'.$nr,'naam'=>'Zulu '.$nr,'soort'=>'politie','callsigns'=>[]])+['herkenning'=>'callsign']; }
  if($hex!==''&&isset($learned[$hex]))return $learned[$hex]+['herkenning'=>'toestel'];
  $owner=($ac['ownOp']??'').' '.($ac['desc']??''); $isHeli=in_array(strtoupper((string)($ac['t']??'')),AA_HELI_TYPES,true)||($ac['category']??null)==='A7';
  if($isHeli&&str_starts_with($reg,'PH-')){
    if(preg_match('/ANWB|MEDICAL AIR|LIFELINER|TRAUMA/i',$owner))return ['id'=>'trauma-'.($hex?:$reg),'naam'=>'Traumaheli '.$reg,'soort'=>'trauma','callsigns'=>[],'herkenning'=>'operator'];
    if(preg_match('/POLITIE|POLICE|KLPD/i',$owner))return ['id'=>'politie-'.($hex?:$reg),'naam'=>'Politieheli '.$reg,'soort'=>'politie','callsigns'=>[],'herkenning'=>'operator'];
  }
  return null;
}
function aaPositie(array $ac): ?array {
  if(is_numeric($ac['lat']??null)&&!is_string($ac['lat'])&&is_numeric($ac['lon']??null)&&!is_string($ac['lon']))return ['lat'=>$ac['lat'],'lon'=>$ac['lon'],'oud'=>(float)($ac['seen_pos']??($ac['seen']??0))];
  $l=$ac['lastPosition']??null;
  if(is_array($l)&&is_numeric($l['lat']??null)&&is_numeric($l['lon']??null)&&(float)($l['seen_pos']??INF)<=300)return ['lat'=>$l['lat'],'lon'=>$l['lon'],'oud'=>(float)$l['seen_pos']];
  return null;
}
function aaRond(mixed $v): ?int { return is_int($v)||is_float($v)?(int)round($v):null; }
function aaNaamSort(array $list): array { $c=new Collator('en'); usort($list,fn($a,$b)=>$c->compare($a['naam'],$b['naam'])); return $list; }
function aaFromAdsb(array $list, int $now, array $learned): array {
  $out=[];
  foreach($list as $ac){ if(!is_array($ac))continue; $h=aaIdentify($ac,$learned); $p=aaPositie($ac); if(!$h||!$p)continue;
    $item=['id'=>$h['id'],'naam'=>$h['naam'],'soort'=>$h['soort']]+(!empty($h['medic'])?['medic'=>true]:[])+['basis'=>$h['basis']??null,'herkenning'=>$h['herkenning'],
      'callsign'=>trim((string)($ac['flight']??''))?:null,'registratie'=>aaOr($ac['r']??null),'type'=>aaOr($ac['t']??null),'hex'=>aaOr($ac['hex']??null),'lat'=>$p['lat'],'lon'=>$p['lon'],
      'koers'=>aaRond($ac['track']??null)??aaRond($ac['true_heading']??null),'hoogte_ft'=>aaRond($ac['alt_baro']??null),'op_grond'=>($ac['alt_baro']??null)==='ground',
      'snelheid_kt'=>aaRond($ac['gs']??null),'stijgsnelheid_fpm'=>aaRond($ac['baro_rate']??null),'gezien'=>aaIso($now-$p['oud']*1000)];
    $prev=$out[$h['id']]??null; if(!$prev||$prev['gezien']<$item['gezien'])$out[$h['id']]=$item; }
  return aaNaamSort(array_values($out));
}
function aaIsNederlands(mixed $hex): bool { $n=preg_match('/^[0-9a-f]+/i',(string)$hex,$m)?hexdec($m[0]):-1; return $n>=0x480000&&$n<=0x487fff; }
function aaLuchtSoort(array $ac, array $learned): ?array {
  $bron=strtoupper(trim((string)($ac['r']??''))); $hex=strtolower(trim((string)($ac['hex']??'')));
  $reg=$bron!==''?$bron:(AA_KUSTWACHT_HEX[$hex]??''); $type=strtoupper(trim((string)($ac['t']??''))); $owner=($ac['ownOp']??'').' '.($ac['desc']??'');
  $isHeli=in_array($type,AA_LUCHT_HELI,true)||($ac['category']??null)==='A7'; $anders=$reg!==$bron?['registratie'=>$reg]:[];
  $fl=strtoupper(trim((string)($ac['flight']??'')));
  if(isset(AA_KUSTWACHT[$reg]))return ['soort'=>'kustwacht','icoon'=>AA_KUSTWACHT[$reg],'naam'=>'Kustwacht '.$reg]+$anders;
  if(preg_match('/^NCG\d{1,3}$/',$fl))return ['soort'=>'kustwacht','icoon'=>$isHeli?'kustwacht_aw189':'kustwacht_dash8','naam'=>'Kustwacht '.($reg?:trim((string)$ac['flight']))]+$anders;
  if($reg==='PH-GOV')return ['soort'=>'regering','icoon'=>'phgov','naam'=>'Regeringsvliegtuig PH-GOV'];
  if(str_starts_with($reg,'PH-')&&preg_match('/KUSTWACHT|COAST\s?GUARD/i',$owner))return ['soort'=>'kustwacht','icoon'=>$isHeli?'kustwacht_aw189':'kustwacht_dash8','naam'=>'Kustwacht '.$reg];
  if(in_array($reg,AA_KMAR,true)||preg_match('/MARECHAUSSEE/i',$owner))return ['soort'=>'kmar','icoon'=>'kmar','naam'=>trim('Marechaussee '.$reg)];
  if(((int)($ac['dbFlags']??0)&1)&&aaIsNederlands($hex)){
    [$icoon,$typeNaam]=AA_LUCHT_TYPES[$type]??[in_array($type,AA_JAGERS,true)?'f35':($isHeli?'heli':'vliegtuig'),AA_LUCHT_NAMEN[$type]??$type];
    $naam=trim("$typeNaam $reg")?:(trim((string)($ac['flight']??''))?:'Militair toestel');
    return ['soort'=>'defensie','icoon'=>$icoon,'naam'=>$naam];
  }
  return $hex!==''&&isset($learned[$hex])?$learned[$hex]:null;
}
function aaLuchtLijst(array $list, int $now, array $overslaan, array $learned): array {
  $out=[];
  foreach($list as $ac){ if(!is_array($ac))continue; $hex=strtolower(trim((string)($ac['hex']??''))); $p=aaPositie($ac);
    if($hex===''||isset($overslaan[$hex])||!$p)continue; $s=aaLuchtSoort($ac,$learned); if(!$s)continue;
    $off=$s['soort']==='kustwacht';
    if($p['lat']<($off?50.3:50.7)||$p['lat']>($off?56.2:53.7)||$p['lon']<($off?-0.5:3.1)||$p['lon']>($off?8.3:7.3))continue;
    $reg=$s['registratie']??aaOr($ac['r']??null); unset($s['registratie']);
    $out[$hex]=['id'=>'lucht-'.$hex]+$s+['callsign'=>trim((string)($ac['flight']??''))?:null,'registratie'=>$reg,'type'=>aaOr($ac['t']??null),'hex'=>$hex,'lat'=>$p['lat'],'lon'=>$p['lon'],
      'koers'=>aaRond($ac['track']??null)??aaRond($ac['true_heading']??null),'hoogte_ft'=>aaRond($ac['alt_baro']??null),'op_grond'=>($ac['alt_baro']??null)==='ground',
      'snelheid_kt'=>aaRond($ac['gs']??null),'gezien'=>aaIso($now-$p['oud']*1000)]; }
  return aaNaamSort(array_values($out));
}
function aaKvPrefix(string $prefix): array {
  $q=aaDb()->prepare('SELECT `key`, value FROM kv WHERE `key` LIKE ?'); $q->execute([$prefix.'%']); $o=[];
  foreach($q as $r){$v=json_decode((string)$r['value'],true); if(is_array($v))$o[substr($r['key'],strlen($prefix))]=$v;} return $o;
}
function aaRetainAircraft(array $current, array $previous, int $now): array {
  $live=[];
  foreach($current as $h){ $at=aaMs($h['gezien']??null); if(empty($h['id'])||$at===null||$now-$at>=AA_AIRCRAFT_HOLD_MS||$at>$now+10000)continue; $h['verouderd']=$now-$at>AA_AIRCRAFT_STALE_MS; $live[$h['id']]=$h; }
  foreach($previous as $h){ if(empty($h['id']))continue; $at=aaMs($h['gezien']??null); if($at===null||$now-$at>=AA_AIRCRAFT_HOLD_MS||$at>$now+10000)continue;
    $n=$live[$h['id']]??null; if(!$n||aaMs($n['gezien'])<$at){$h['verouderd']=$now-$at>AA_AIRCRAFT_STALE_MS;$live[$h['id']]=$h;} }
  return aaNaamSort(array_values($live));
}
function aaVulAan(array &$lijst, array $vorige, int $now): void {
  foreach($lijst as &$h){ $v=$vorige[$h['id']]??null; if(!$v||$now-(aaMs($v['gezien'])??0)>180000)continue;
    foreach(['koers','snelheid_kt','hoogte_ft','callsign'] as $k)if(($h[$k]??null)===null&&($v[$k]??null)!==null&&!($k==='hoogte_ft'&&$h['op_grond']))$h[$k]=$v[$k]; }
  unset($h);
}
/** heli.js helikopters(): the current picture, at most 12 seconds old. */
function aaHelikopters(): array {
  // One shared picture for the web requests and the cron (they may not share a temp folder): kept in the database.
  $now=aaNow(); $cached=aaParse(aaKvGet('adsb_cache'),null);
  $onlyRecent=fn($d)=>['helikopters'=>aaRetainAircraft($d['helikopters']??[],[],aaNow()),'lucht'=>aaRetainAircraft($d['lucht']??[],[],aaNow())]+$d;
  $geef=function($c)use($onlyRecent){unset($c['_at'],$c['_laatste']);return array_merge($c,$onlyRecent($c));};
  if(is_array($cached)&&$now-(int)($cached['_at']??0)<12000)return $geef($cached);
  // After a refusal (429) adsb.lol gets a minute of rest; meanwhile the last picture (greyed when old).
  $db=aaDb(); $lock=(int)$db->query("SELECT GET_LOCK('aalerts_adsb', 0)")->fetchColumn()===1;
  if(!$lock){ if(is_array($cached))return $geef($cached); $db->query("SELECT GET_LOCK('aalerts_adsb', 10)"); $lock=true; }
  $cached=aaParse(aaKvGet('adsb_cache'),null)?:$cached;
  if(is_array($cached)&&aaNow()-(int)($cached['_at']??0)<12000){ $db->query("SELECT RELEASE_LOCK('aalerts_adsb')"); return $geef($cached); }
  $laatste=is_array($cached)?($cached['_laatste']??[]):[];
  $snap=aaParse(aaKvGet('lucht:last-known-v1'),null); if(!is_array($snap))$snap=['helikopters'=>[],'lucht'=>[]];
  try{
    $haal=function(string $url,array $extra){
      $ch=curl_init($url);
      curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_ENCODING=>'',
        CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json','User-Agent: A-Alerts/1.0 (+https://a-alerts.nl)'],$extra)]);
      $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $cerr=curl_errno($ch); curl_close($ch);
      return [$code,$raw,$cerr];
    };
    // First adsb.lol itself. It refuses the shared address of the hosting now and then (HTTP 429): then a minute of rest
    // for adsb.lol, and meanwhile the same adsb.lol request through the own Koyeb service (/adsb, with the token).
    // Only as a reserve: positions come from adsb.lol either way, never from the NDW traffic data.
    $code=0; $raw=''; $cerr=0; $via='direct';
    if(aaNow()>=(int)(aaKvGet('adsb_pauze_tot')??0)){
      [$code,$raw,$cerr]=$haal('https://api.adsb.lol/v2/point/52.2/5.4/160',setting('ADSB_LOL_API_KEY')!==''?['api-auth: '.setting('ADSB_LOL_API_KEY')]:[]);
      if($code===429)aaKvSet('adsb_pauze_tot',(string)(aaNow()+60000));
    }
    if($code!==200&&setting('VERKEER_URL')!==''&&setting('VERKEER_TOKEN')!==''){
      $direct=$code;
      [$code,$raw,$cerr]=$haal(rtrim(setting('VERKEER_URL'),'/').'/adsb?lat=52.2&lon=5.4&nm=160',['Authorization: Bearer '.setting('VERKEER_TOKEN')]);
      $via='koyeb';
      if($code!==200)$code=$code?:$direct;
    }
    try{ aaKvSet('adsb_via',aaJson(['at'=>aaIso(aaNow()),'via'=>$via,'http'=>$code])); }catch(Throwable){}
    if($code!==200)throw new RuntimeException('ADS-B HTTP '.$code.' via '.$via.($cerr?' curl '.$cerr:''));
    $body=json_decode((string)$raw,true); if(!is_array($body)||!is_array($body['ac']??null))throw new RuntimeException('Invalid ADS-B payload');
    $bnow=is_numeric($body['now']??null)&&(float)$body['now']>0?(int)$body['now']:aaNow();
    $learned=aaKvPrefix('heli_hex:');
    $list=aaFromAdsb($body['ac'],$bnow,$learned);
    foreach($list as $h){ if(empty($h['hex'])||!in_array($h['herkenning'],['callsign','registratie'],true))continue; $hex=strtolower($h['hex']);
      if(($learned[$hex]['id']??null)===$h['id'])continue;
      aaKvSet('heli_hex:'.$hex,aaJson(['id'=>$h['id'],'naam'=>$h['naam'],'soort'=>$h['soort']]+(!empty($h['medic'])?['medic'=>true]:[])+['basis'=>$h['basis']??null,'callsigns'=>[]])); }
    $lucht=[];
    try{ $geleerd=aaKvPrefix('lucht_hex:'); $skip=[]; foreach($list as $h)$skip[strtolower((string)$h['hex'])]=true;
      $lucht=aaLuchtLijst($body['ac'],$bnow,$skip,$geleerd);
      foreach($lucht as $t)if($t['soort']!=='defensie'&&!isset($geleerd[$t['hex']]))aaKvSet('lucht_hex:'.$t['hex'],aaJson(['soort'=>$t['soort'],'icoon'=>$t['icoon'],'naam'=>$t['naam']]));
    }catch(Throwable){}
    $alles=array_merge($list,$lucht); aaVulAan($alles,$laatste,aaNow());
    $list=array_slice($alles,0,count($list)); $lucht=array_slice($alles,count($list));
    foreach($alles as $h)$laatste[$h['id']]=$h;
    foreach($laatste as $id=>$v)if(aaNow()-(aaMs($v['gezien'])??0)>600000)unset($laatste[$id]);
    foreach($list as &$h){$h['boven']=$h['op_grond']?null:(aaPlaatsBij($h['lat'],$h['lon'])['tekst']??null);} unset($h);
    foreach($lucht as &$h){$h['boven']=$h['op_grond']?null:(aaPlaatsBij($h['lat'],$h['lon'])['tekst']??null);} unset($h);
    $data=['bijgewerkt'=>aaIso(aaNow()),'bron'=>'adsb.lol (ODbL)','helikopters'=>$list,'lucht'=>$lucht]; $failing=false;
  }catch(Throwable $e){
    if(str_contains($e->getMessage(),'HTTP 429'))try{aaKvSet('adsb_pauze_tot',(string)(aaNow()+60000));}catch(Throwable){}
    // Why it failed, for /docs and the checks (never a key in it): HTTP code or curl error number.
    try{ aaKvSet('adsb_fout',aaJson(['at'=>aaIso(aaNow()),'fout'=>substr($e->getMessage(),0,200)])); }catch(Throwable){}
    $data=['bijgewerkt'=>aaIso(aaNow()),'bron'=>'adsb.lol (ODbL)','helikopters'=>[],'lucht'=>[],'fout'=>true,'verouderd'=>true]; $failing=true;
  }
  $n=aaNow(); $h=aaRetainAircraft($data['helikopters'],$snap['helikopters']??[],$n); $l=aaRetainAircraft($data['lucht'],$snap['lucht']??[],$n);
  $data['helikopters']=$h; $data['lucht']=$l;
  $hasLive=false; foreach(array_merge($h,$l) as $x)if(!$x['verouderd'])$hasLive=true;
  if(!$failing&&$hasLive&&$n-(int)(aaKvRow('lucht:last-known-v1')['at']??0)>30000)aaKvSet('lucht:last-known-v1',aaJson(['helikopters'=>$h,'lucht'=>$l]));
  aaKvSet('adsb_cache',aaJson($data+['_at'=>$n,'_laatste'=>(object)$laatste]));
  if($lock)$db->query("SELECT RELEASE_LOCK('aalerts_adsb')");
  return $data;
}
