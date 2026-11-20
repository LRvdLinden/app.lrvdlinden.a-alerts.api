<?php
declare(strict_types=1);
/**
 * Everything around the aircraft on top of the positions (heliplus.js, vliegend.js, extra.js, spoor.js, plaats.js):
 * the prediction where a helicopter heads, the alert a Lifeliner flies to, hospital/base on the ground, several
 * helicopters at one spot, last-seen status, cancelled call-outs, the route trail and the flights.
 */
require_once __DIR__.'/lucht.php';
require_once __DIR__.'/meldingen.php';

/** JS Math.round */
function aaLRound(float $x): float { return floor($x+0.5); }
function aaLKm(array $a, array $b): float {
  $R=M_PI/180;
  $s=sin((($b['lat']-$a['lat'])*$R)/2)**2+cos($a['lat']*$R)*cos($b['lat']*$R)*sin((($b['lon']-$a['lon'])*$R)/2)**2;
  return 6371*2*atan2(sqrt($s),sqrt(1-$s));
}
function aaLBearing(array $a, array $b): float {
  $R=M_PI/180;
  $y=sin(($b['lon']-$a['lon'])*$R)*cos($b['lat']*$R);
  $x=cos($a['lat']*$R)*sin($b['lat']*$R)-sin($a['lat']*$R)*cos($b['lat']*$R)*cos(($b['lon']-$a['lon'])*$R);
  return fmod(atan2($y,$x)/$R+360,360);
}
/** JS ((a - b + 540) % 360): % keeps the sign of the dividend, as fmod does. */
function aaLAngle(float $a, float $b): float { return abs(fmod($a-$b+540,360)-180); }
function aaLRound1(float $x, float $f): float { return aaLRound($x*$f)/$f; }
function aaLNum(float $x): int|float { return floor($x)==$x&&abs($x)<1e15?(int)$x:$x; }

// ------------------------------------------------------------------ heliplus.js
function aaVoorspel(array $h): ?array {
  if(!empty($h['op_grond'])||($h['koers']??null)===null||($h['snelheid_kt']??0)<50)return null;
  $loc=aaData('locaties');
  $isBase=function($b)use($h){ foreach($b['heli']??[] as $x)if($x===$h['id']||($x==='zulu'&&$h['soort']==='politie'))return true; return false; };
  $kand=[];
  if($h['soort']==='trauma')foreach($loc['ziekenhuizen']??[] as $z)$kand[]=array_merge($z,['soort'=>'ziekenhuis','w'=>!empty($z['trauma'])?2:1]);
  foreach($loc['bases']??[] as $b)if($isBase($b))$kand[]=array_merge($b,['soort'=>'basis','w'=>1.6]);
  $best=null; $totaal=0.0;
  foreach($kand as $k){
    $d=aaLKm($h,$k); if($d<1.5||$d>220)continue;
    $diff=aaLAngle((float)$h['koers'],aaLBearing($h,$k)); $kegel=$d<10?35:($d<40?22:14);
    if($diff>$kegel)continue;
    $score=(cos(($diff/$kegel)*(M_PI/2))**2)*$k['w']/(1+$d/60);
    $totaal+=$score; if(!$best||$score>$best['score'])$best=['k'=>$k,'d'=>$d,'score'=>$score];
  }
  if(!$best)return null;
  $kmh=($h['snelheid_kt']??0)*1.852;
  return ['id'=>$best['k']['id'],'naam'=>$best['k']['naam'],'soort'=>$best['k']['soort'],'lat'=>$best['k']['lat'],'lon'=>$best['k']['lon'],
    'afstand_km'=>aaLNum(aaLRound1($best['d'],10)),'eta_min'=>$kmh>40?(int)max(1,aaLRound(($best['d']/$kmh)*60)):null,
    'zekerheid'=>aaLNum(aaLRound1($best['score']/$totaal,100))];
}
function aaMetVoorspelling(array $data): array { $data['helikopters']=array_map(fn($h)=>$h+['voorspelling'=>aaVoorspel($h)],$data['helikopters']??[]); return $data; }
function aaGenoemd(array $m): ?string {
  $t=[]; foreach($m['eenheden']??[] as $u)$t[]=is_array($u)?($u['naam']??''):''; $t[]=$m['full_melding']??''; $t[]=$m['title']??'';
  return preg_match('/life\s*liner\s*0?([1-4])/i',implode(' ',$t),$x)?'lifeliner'.$x[1]:null;
}
function aaIsHeliMelding(array $m): bool { return ($m['dienst']??null)==='traumaheli'||($m['dienst']??null)==='lifeliner'||aaGenoemd($m)!==null; }
function aaHeliVoorMelding(array $m, array $helis): ?array {
  $id=aaGenoemd($m);
  if($id){ foreach($helis as $h)if($h['id']===$id)return $h; return null; }
  if(!aaIsHeliMelding($m)||($m['lat']??null)===null)return null;
  $k=array_values(array_filter($helis,fn($h)=>$h['soort']==='trauma'&&empty($h['medic'])));
  usort($k,fn($a,$b)=>aaLKm($a,$m)<=>aaLKm($b,$m));
  $best=$k[0]??null; return $best&&aaLKm($best,$m)<120?$best:null;
}
/** Trauma helicopters that just left the ground (or appeared in the air after a gap of 10 minutes). */
function aaOpgestegen(array $list, array $prev, int $now): array {
  return array_values(array_filter($list,function($h)use($prev,$now){
    if($h['soort']!=='trauma'||!empty($h['medic'])||!empty($h['op_grond']))return false;
    $p=$prev[$h['id']]??null; return !$p||(int)$p['grond']===1||$now-(int)$p['at']>600000; }));
}

// ------------------------------------------------------------------ vliegend.js
function aaPlekVan(mixed $lat, mixed $lon): ?array {
  if($lat===null||$lon===null)return null; $loc=aaData('locaties'); $best=null;
  foreach([['ziekenhuis',$loc['ziekenhuizen']??[]],['basis',$loc['bases']??[]]] as [$soort,$lijst])
    foreach($lijst as $l){ $d=aaLKm(['lat'=>$lat,'lon'=>$lon],$l); if($d<3&&(!$best||$d<$best['d']))$best=['naam'=>$l['naam'],'soort'=>$soort,'d'=>$d]; }
  return $best?['naam'=>$best['naam'],'soort'=>$best['soort']]:null;
}
const AA_HELI_ICONEN=['heli','kustwacht_aw189','kustwacht_heli','apache','chinook','cougar','nh90'];
function aaIsHeliToestel(array $h): bool { return ($h['soort']??null)==='trauma'||($h['soort']??null)==='politie'||in_array($h['icoon']??null,AA_HELI_ICONEN,true); }
function aaTerPlekke(array $h): bool { return ($h['lat']??null)!==null&&(!empty($h['op_grond'])||((($h['snelheid_kt']??null)??99)<35&&($h['hoogte_ft']??null)!==null&&$h['hoogte_ft']<1500)); }
function aaLuchtIncidenten(array $toestellen): array {
  $kand=array_values(array_filter($toestellen,fn($h)=>aaIsHeliToestel($h)&&aaTerPlekke($h)&&!aaPlekVan($h['lat'],$h['lon'])));
  $uit=[]; $gehad=[];
  foreach($kand as $a){
    if(isset($gehad[$a['id']]))continue;
    $groep=array_values(array_filter($kand,fn($b)=>$b['id']===$a['id']||(!isset($gehad[$b['id']])&&aaLKm($a,$b)<=1.5)));
    if(count($groep)<2)continue;
    foreach($groep as $g)$gehad[$g['id']]=true;
    $lat=array_sum(array_column($groep,'lat'))/count($groep); $lon=array_sum(array_column($groep,'lon'))/count($groep);
    $ids=array_column($groep,'id'); sort($ids,SORT_STRING);
    $uit[]=['sleutel'=>implode('+',$ids),'lat'=>aaLNum(aaLRound1($lat,1e4)),'lon'=>aaLNum(aaLRound1($lon,1e4)),
      'plaats'=>aaPlaatsBij($lat,$lon)['tekst']??null,'toestellen'=>array_map(fn($g)=>['id'=>$g['id'],'naam'=>$g['naam']],$groep)];
  }
  return $uit;
}
function aaVerrijk(array $lijst, ?array $alle=null): array {
  $inc=aaLuchtIncidenten($alle??$lijst);
  return array_map(function($h)use($inc){
    $p=!empty($h['op_grond'])?aaPlekVan($h['lat'],$h['lon']):null; $i=null;
    foreach($inc as $x){ foreach($x['toestellen'] as $t)if($t['id']===$h['id']){$i=$x;break 2;} }
    return $h+['plek'=>$p['naam']??null,'plek_soort'=>$p['soort']??null,
      'samen'=>$i?['plaats'=>$i['plaats'],'met'=>array_values(array_map(fn($t)=>$t['naam'],array_filter($i['toestellen'],fn($t)=>$t['id']!==$h['id'])))]:null];
  },$lijst);
}
function aaStatusBijwerken(array $oud, array $toestellen, int $now): array {
  $uit=$oud;
  foreach($toestellen as $h){
    if(($h['lat']??null)===null)continue;
    $p=!empty($h['op_grond'])?aaPlekVan($h['lat'],$h['lon']):null;
    $uit[$h['id']]=['naam'=>$h['naam'],'at'=>$now,'grond'=>!empty($h['op_grond'])?1:0,'cs'=>aaOr($h['callsign']??null)??aaOr($uit[$h['id']]['cs']??null),
      'waar'=>!empty($h['op_grond'])?(($p['naam']??null)??(aaPlaatsBij($h['lat'],$h['lon'])['tekst']??null)):aaOr($h['boven']??null),'basis'=>($p['soort']??null)==='basis'?1:0];
  }
  $rijen=[]; foreach($uit as $id=>$v)if($now-(int)$v['at']<30*86400000)$rijen[]=[(string)$id,$v];
  usort($rijen,fn($a,$b)=>(int)$b[1]['at']<=>(int)$a[1]['at']);
  $o=[]; foreach(array_slice($rijen,0,150) as [$id,$v])$o[$id]=$v; return $o;
}
function aaStatusLijst(array $status, int $now): array {
  $o=[]; foreach($status as $id=>$v)$o[]=['id'=>(string)$id,'naam'=>$v['naam']??null,'gezien'=>aaIso((int)$v['at']),'waar'=>aaOr($v['waar']??null),
    'status'=>$now-(int)$v['at']>180000?'uit_beeld':(!empty($v['grond'])?(!empty($v['basis'])?'basis':'grond'):'lucht')];
  return $o;
}
function aaWeerBeperking(array $waarschuwingen, int $now): ?array {
  $rang=['yellow'=>1,'orange'=>2,'red'=>3]; $weer=[['/mist|zicht/i','dichte mist','dense fog'],['/onweer/i','onweer','thunderstorms'],['/wind|storm/i','harde wind','strong wind'],['/ijzel|sneeuw|glad/i','sneeuw of ijzel','snow or ice']];
  $best=null;
  foreach($waarschuwingen as $w){
    if(($w['bron']??null)!=='knmi'||!isset($rang[$w['niveau']??''])||($w['status']??null)==='beeindigd')continue;
    if((aaMs($w['start'])??0)>$now||(!empty($w['einde'])&&(aaMs($w['einde'])??0)<$now))continue;
    $r=null; foreach($weer as $x)if(preg_match($x[0],($w['soort']??'').' '.($w['titel']??''))){$r=$x;break;}
    if($r&&(!$best||$rang[$w['niveau']]>$rang[$best['niveau']]))$best=['reden'=>$r[1],'reden_en'=>$r[2],'niveau'=>$w['niveau']];
  }
  return $best;
}

// ------------------------------------------------------------------ extra.js: the alert a Lifeliner flies to, cancelled call-outs
function aaZiekenhuisBij(array $p): ?array {
  $best=null;
  foreach(aaData('locaties')['ziekenhuizen']??[] as $z){ if(empty($z['trauma'])&&empty($z['helipad']))continue; $d=aaLKm($p,$z)/(!empty($z['trauma'])?1.4:1); if(!$best||$d<$best['d'])$best=['z'=>$z,'d'=>$d]; }
  return $best?['id'=>$best['z']['id'],'naam'=>$best['z']['naam'],'lat'=>$best['z']['lat'],'lon'=>$best['z']['lon']]:null;
}
function aaRecenteHeliMeldingen(int $now): array {
  $q=aaDb()->prepare(AA_SELECT.' WHERE COALESCE(o.hidden, 0) = 0 AND m.datum >= ? ORDER BY m.datum DESC, m.volgorde ASC LIMIT 80'); $q->execute([$now-3600000]);
  return array_values(array_filter(array_map('aaMeldingAPI',$q->fetchAll()),fn($m)=>aaIsHeliMelding($m)&&$m['lat']!==null&&$m['lon']!==null));
}
function aaMeldingVoorspelling(array $h, array $meldingen, array $ter, int $now): ?array {
  if(!empty($h['op_grond'])||$h['soort']!=='trauma'||!empty($h['medic']))return null;
  foreach($meldingen as $m){
    if((aaHeliVoorMelding($m,[$h])['id']??null)!==$h['id'])continue;
    if(isset($ter[$h['id'].':'.$m['guid']]))continue;
    if($now-(aaMs($m['datum_tijd'])??0)>50*60000)continue;
    $d=aaLKm($h,$m); if($d<1.5)continue;
    $kmh=max(($h['snelheid_kt']??0)*1.852,180); $daarna=aaZiekenhuisBij($m);
    return ['id'=>'melding-'.$m['guid'],'guid'=>$m['guid'],'naam'=>aaOr($m['title']??null,'Melding'),'plaats'=>aaOr($m['stad']??null),'soort'=>'melding',
      'lat'=>$m['lat'],'lon'=>$m['lon'],'afstand_km'=>aaLNum(aaLRound1($d,10)),'eta_min'=>(int)max(1,aaLRound(($d/$kmh)*60)),
      'zekerheid'=>aaGenoemd($m)===$h['id']?0.95:0.7]+($daarna?['daarna'=>$daarna]:[]);
  }
  return null;
}
function aaTerPlaatseSet(): array { $o=[]; foreach(aaDb()->query("SELECT `key` FROM kv WHERE `key` LIKE 'heli\\_scene:%'") as $r)$o[substr($r['key'],11)]=true; return $o; }
function aaNoteerTerPlaatse(array $list, array $meldingen, int $now): void {
  $st=aaDb()->prepare("INSERT IGNORE INTO kv (`key`, value, at) VALUES (?, '1', ?)");
  foreach($list as $h){ if($h['soort']!=='trauma'||!empty($h['medic']))continue;
    foreach($meldingen as $m){ if((aaHeliVoorMelding($m,[$h])['id']??null)!==$h['id'])continue;
      if(aaLKm($h,$m)<=(!empty($h['op_grond'])?3:1.5))$st->execute(['heli_scene:'.$h['id'].':'.$m['guid'],$now]); } }
}
function aaInzetStap(?array $state, array $h, array $m, int $now): ?array {
  if(($h['lat']??null)===null||($m['lat']??null)===null)return $state;
  if(($state['status']??null)==='geannuleerd')return $state;
  $d=aaLKm($h,$m); $leeftijd=$now-(aaMs($m['datum_tijd'])??0);
  if(!$state||$state['status']==='niet_opgestegen'){
    if(empty($h['op_grond']))return ['first_km'=>$d,'min_km'=>$d,'min_at'=>$now,'min_boven'=>aaOr($h['boven']??null),'status'=>'onderweg'];
    if(!$state&&$leeftijd>=600000&&$d>5)return ['status'=>'niet_opgestegen','at'=>$now];
    return $state;
  }
  $s=$state;
  if($d<$s['min_km']-0.3)$s=array_merge($s,['min_km'=>$d,'min_at'=>$now,'min_boven'=>aaOr($h['boven']??null)??aaOr($s['min_boven']??null)]);
  $grens=preg_match('/\d/',(string)($m['locatie']??''))?4:6; $naderde=$s['first_km']-$s['min_km']>=2;
  if($naderde&&$s['min_km']>$grens&&$d>=$s['min_km']+3&&$now-$s['min_at']>=120000)return array_merge($s,['status'=>'geannuleerd','at'=>$s['min_at']]);
  return $s;
}
/** Returns the call-outs that were found cancelled just now. */
function aaVolgInzetten(array $helis, array $meldingen, array $ter, int $now): array {
  $nieuw=[];
  foreach($meldingen as $m){
    $id=aaGenoemd($m); if(!$id||$now-(aaMs($m['datum_tijd'])??0)>50*60000)continue;
    if(isset($ter[$id.':'.$m['guid']]))continue;
    $h=null; foreach($helis as $x)if($x['id']===$id){$h=$x;break;} if(!$h)continue;
    $key='heli_inzet:'.$id.':'.$m['guid']; $oud=aaParse(aaKvGet($key),null); if(!is_array($oud))$oud=null;
    $s=aaInzetStap($oud,$h,$m,$now); if(!$s||$s===$oud)continue;
    aaKvSet($key,aaJson($s)); if($s['status']==='geannuleerd')$nieuw[]=['heli'=>$h,'melding'=>$m,'state'=>$s];
  }
  return $nieuw;
}
function aaHeliInzetten(): array {
  $o=[];
  foreach(aaDb()->query("SELECT `key`, value FROM kv WHERE `key` >= 'heli_inzet:' AND `key` < 'heli_inzet;' ORDER BY `key`") as $r){
    $p=explode(':',$r['key']); $s=json_decode((string)$r['value'],true);
    if(!is_array($s)||!in_array($s['status']??null,['geannuleerd','niet_opgestegen'],true))continue;
    $o[]=['guid'=>$p[2]??null,'heli'=>$p[1]??null,'status'=>$s['status'],'at'=>aaIso((int)$s['at']),'boven'=>aaOr($s['min_boven']??null)];
  }
  return $o;
}

// ------------------------------------------------------------------ spoor.js + plaats.js plaatsenUit
const AA_SPOOR_KEEP_MS=2*3600000, AA_SPOOR_GAP_MS=600000;
function aaRecordPositions(array $list): array {
  if(!$list)return [];
  $ids=array_column($list,'id'); $in=implode(',',array_fill(0,count($ids),'?'));
  $q=aaDb()->prepare("SELECT p.heli, p.at, p.lat, p.lon, p.grond FROM heli_posities p JOIN (SELECT heli, MAX(at) AS at FROM heli_posities WHERE heli IN ($in) GROUP BY heli) m ON m.heli = p.heli AND m.at = p.at");
  $q->execute($ids); $prev=[]; foreach($q->fetchAll() as $r)$prev[$r['heli']]=['heli'=>$r['heli'],'at'=>(int)$r['at'],'lat'=>(float)$r['lat'],'lon'=>(float)$r['lon'],'grond'=>(int)$r['grond']];
  $ins=aaDb()->prepare('INSERT IGNORE INTO heli_posities (heli, at, lat, lon, hoogte_ft, snelheid_kt, koers, grond) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
  foreach($list as $h){
    $at=aaMs($h['gezien']??null)?:aaNow(); $p=$prev[$h['id']]??null;
    if($p&&$at<=$p['at'])continue;
    $g=!empty($h['op_grond'])?1:0;
    if($p&&$g===$p['grond']&&$at-$p['at']<60000&&aaLKm($p,$h)<0.05)continue;
    $ins->execute([$h['id'],$at,$h['lat'],$h['lon'],$h['hoogte_ft'],$h['snelheid_kt'],$h['koers'],$g]);
  }
  return $prev;
}
function aaRecordLucht(array $lucht): array {
  $list=array_slice($lucht,0,40); if(!$list)return [];
  $ids=array_column($list,'id'); $in=implode(',',array_fill(0,count($ids),'?'));
  $q=aaDb()->prepare("SELECT p.heli, p.at, p.grond FROM heli_posities p JOIN (SELECT heli, MAX(at) AS at FROM heli_posities WHERE heli IN ($in) GROUP BY heli) m ON m.heli = p.heli AND m.at = p.at"); $q->execute($ids);
  $vorige=[]; foreach($q->fetchAll() as $r)$vorige[$r['heli']]=['at'=>(int)$r['at'],'grond'=>(int)$r['grond']];
  $ins=aaDb()->prepare('INSERT IGNORE INTO heli_posities (heli, at, lat, lon, hoogte_ft, snelheid_kt, koers, grond) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
  foreach($list as $h){ $at=aaMs($h['gezien']??null)?:aaNow(); if($at-($vorige[$h['id']]['at']??0)<30000)continue;
    $ins->execute([$h['id'],$at,$h['lat'],$h['lon'],$h['hoogte_ft'],$h['snelheid_kt'],$h['koers'],!empty($h['op_grond'])?1:0]); }
  return $vorige;
}
function aaPosRij(array $r): array { return ['heli'=>$r['heli'],'at'=>(int)$r['at'],'lat'=>(float)$r['lat'],'lon'=>(float)$r['lon'],
  'hoogte_ft'=>$r['hoogte_ft']===null?null:(int)$r['hoogte_ft'],'snelheid_kt'=>$r['snelheid_kt']===null?null:(int)$r['snelheid_kt'],'koers'=>$r['koers']===null?null:(int)$r['koers'],'grond'=>(int)$r['grond']]; }
function aaSpoorPunt(array $r): array { return ['t'=>aaIso($r['at']),'lat'=>aaLNum($r['lat']),'lon'=>aaLNum($r['lon']),'hoogte_ft'=>$r['hoogte_ft'],'snelheid_kt'=>$r['snelheid_kt'],'koers'=>$r['koers'],'grond'=>(bool)$r['grond']]; }
function aaVluchtenUit(array $rows, int $now): array {
  $out=[]; $cur=null;
  $close=function(bool $end)use(&$cur,&$out){
    if($cur&&count($cur)>=2){ $pts=$cur; $last=end($pts)['at']; $afst=0.0; for($i=1;$i<count($pts);$i++)$afst+=aaLKm($pts[$i-1],$pts[$i]);
      $out[]=['start'=>aaIso($pts[0]['at']),'einde'=>$end?aaIso($last):null,'onderweg'=>!$end,'duur_min'=>(int)max(1,aaLRound(($last-$pts[0]['at'])/60000)),
        'van'=>['lat'=>aaLNum($pts[0]['lat']),'lon'=>aaLNum($pts[0]['lon'])],'naar'=>['lat'=>aaLNum(end($pts)['lat']),'lon'=>aaLNum(end($pts)['lon'])],
        'afstand_km'=>aaLNum(aaLRound1($afst,10)),'max_hoogte_ft'=>max(0,...array_map(fn($p)=>$p['hoogte_ft']?:0,$pts))]; }
    $cur=null;
  };
  foreach($rows as $r){
    if($cur&&$r['at']-end($cur)['at']>AA_SPOOR_GAP_MS)$close(true);
    if($r['grond']){ if($cur){$cur[]=$r;$close(true);} continue; }
    $cur??=[]; $cur[]=$r;
  }
  if($cur)$close($now-end($cur)['at']>AA_SPOOR_GAP_MS);
  return array_reverse($out);
}
function aaPlaatsenUit(array $punten): array {
  $runs=[];
  foreach($punten as $p){
    if($p['grond'])continue; $at=$p['at']; $pl=aaPlaatsBij($p['lat'],$p['lon']); $key=$pl?$pl['plaats'].'|'.$pl['gemeente']:'';
    $n=count($runs); if($n&&$runs[$n-1]['key']===$key)$runs[$n-1]['tot']=$at;
    else $runs[]=['key'=>$key,'van'=>$at,'tot'=>$at,'plaats'=>$pl['plaats']??null,'gemeente'=>$pl['gemeente']??null];
  }
  $lang=[]; foreach($runs as $i=>$r)if($i===count($runs)-1||$r['tot']-$r['van']>=($r['key']!==''?45000:180000))$lang[]=$r;
  $uit=[]; foreach($lang as $r){ $n=count($uit); if($n&&$uit[$n-1]['key']===$r['key'])$uit[$n-1]['tot']=$r['tot']; else $uit[]=$r; }
  for($i=0;$i<count($uit)-1;$i++)$uit[$i]['tot']=$uit[$i+1]['van'];
  return array_map(fn($r)=>['van'=>aaIso($r['van']),'tot'=>aaIso($r['tot']),'plaats'=>$r['plaats'],'gemeente'=>$r['gemeente']],array_slice(array_reverse($uit),0,40));
}
function aaSpoor(string $id, string $scope): array {
  $now=aaNow(); $since=$scope==='kort'?$now-1800000:$now-AA_SPOOR_KEEP_MS;
  $q=aaDb()->prepare('SELECT * FROM heli_posities WHERE heli = ? AND at >= ? ORDER BY at ASC LIMIT 5000'); $q->execute([$id,$since]);
  $rows=array_map('aaPosRij',$q->fetchAll()); $vlucht=null;
  if($scope==='vlucht'){
    $vl=aaVluchtenUit($rows,$now); $vlucht=$vl[0]??null;
    if($vlucht){ $s=aaMs($vlucht['start']); $e=$vlucht['einde']?aaMs($vlucht['einde']):null; $rows=array_values(array_filter($rows,fn($r)=>$r['at']>=$s&&($e===null||$r['at']<=$e))); }
    else $rows=[];
  }
  return ['id'=>$id,'scope'=>$scope,'vlucht'=>$vlucht,'punten'=>array_map('aaSpoorPunt',$rows),'plaatsen'=>aaPlaatsenUit($rows),'bron'=>'adsb.lol (ODbL)'];
}
function aaVluchten(string $id): array {
  $q=aaDb()->prepare('SELECT * FROM heli_posities WHERE heli = ? AND at >= ? ORDER BY at ASC LIMIT 20000'); $q->execute([$id,aaNow()-AA_SPOOR_KEEP_MS]);
  return ['id'=>$id,'vluchten'=>array_slice(aaVluchtenUit(array_map('aaPosRij',$q->fetchAll()),aaNow()),0,30),'bron'=>'adsb.lol (ODbL)'];
}

// ------------------------------------------------------------------ the answers of the routes (index.js)
function aaHelikoptersAntwoord(): array {
  $d=aaHelikopters(); $lucht=$d['lucht']??[]; unset($d['lucht']);
  $data=aaMetVoorspelling($d);
  $data['helikopters']=aaVerrijk($data['helikopters'],array_merge($data['helikopters'],$lucht));
  try{
    $now=aaNow(); $vlieg=array_filter($data['helikopters'],fn($h)=>$h['soort']==='trauma'&&empty($h['medic'])&&empty($h['op_grond']));
    if($vlieg){ $meldingen=aaRecenteHeliMeldingen($now); $ter=aaTerPlaatseSet();
      $data['helikopters']=array_map(function($h)use($meldingen,$ter,$now){ $v=aaMeldingVoorspelling($h,$meldingen,$ter,$now); if($v)$h['voorspelling']=$v; return $h; },$data['helikopters']); }
  }catch(Throwable $e){ error_log('[aa voorspelling] '.$e->getMessage()); }
  try{ $data['inzet']=aaHeliInzetten(); }catch(Throwable){ $data['inzet']=[]; }
  return $data;
}
function aaLuchtAntwoord(): array {
  $d=aaHelikopters();
  return ['bijgewerkt'=>$d['bijgewerkt'],'bron'=>$d['bron'],'toestellen'=>aaVerrijk($d['lucht']??[],array_merge($d['helikopters']??[],$d['lucht']??[]))]+(!empty($d['fout'])?['fout'=>true]:[]);
}
function aaHeliStatusAntwoord(): array {
  $rij=aaKvRow('heli_status'); $s=$rij?json_decode((string)$rij['value'],true):[]; if(!is_array($s))$s=[];
  return ['bijgewerkt'=>aaIso($rij?((int)$rij['at']?:aaNow()):aaNow()),'toestellen'=>aaStatusLijst(is_array($s['toestellen']??null)?$s['toestellen']:[],aaNow()),'weer'=>aaOr($s['weer']??null)];
}
