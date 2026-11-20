<?php
declare(strict_types=1);
/**
 * P2000 alerts: storage and API shape (db.js), incident linking (incident.js), hectometre posts and street centres
 * (hm.js), station figures (posten.js) and the intake (index.js ingestParsed). Pushes are queued only when PUSH_ACTIEF
 * is "1" in the private configuration: while the Worker is production, a second sender would notify everyone twice.
 */
require_once __DIR__.'/base.php';

const AA_SELECT="SELECT m.guid, COALESCE(o.title, m.title) AS title, m.full_melding, m.dienst, m.prioriteit, m.datum,
  COALESCE(o.locatie, m.locatie) AS locatie, COALESCE(o.lat, m.lat) AS lat, COALESCE(o.lon, m.lon) AS lon, m.stad, m.regio,
  m.capcode, COALESCE(o.categorie, m.categorie) AS categorie, m.grip, m.opschaling, m.eenheden, m.voertuigen, m.link, m.views, m.incident, m.vlaggen,
  COALESCE(o.hidden, 0) AS hidden, (o.guid IS NOT NULL) AS edited
  FROM meldingen m LEFT JOIN overrides o ON o.guid = m.guid";

function aaNum(mixed $v): int|float|null { if($v===null)return null; $f=(float)$v; return floor($f)==$f&&abs($f)<1e15?(int)$f:$f; }
/** db.js toAPI */
function aaMeldingAPI(?array $row): ?array {
  if(!$row)return null;
  $has=$row['lat']!==null&&$row['lon']!==null&&is_numeric($row['lat'])&&is_numeric($row['lon'])&&(float)$row['lat']!=0.0&&(float)$row['lon']!=0.0;
  $town=!$has?aaWoonplaatsCentrum($row['stad']):null;
  $out=['id'=>$row['guid'],'guid'=>$row['guid'],'title'=>$row['title'],'full_melding'=>$row['full_melding'],'dienst'=>$row['dienst'],
    'prioriteit'=>$row['prioriteit'],'datum_tijd'=>aaIso((int)$row['datum']),'locatie'=>$row['locatie'],
    'lat'=>$town['lat']??aaNum($row['lat']),'lon'=>$town['lon']??aaNum($row['lon'])];
  if($town)$out['plaatscentrum']=true;
  $out+=['stad'=>$row['stad'],'regio'=>$row['regio'],'provincie'=>'Gelderland','capcode'=>$row['capcode'],'categorie'=>$row['categorie'],
    'grip'=>$row['grip'],'opschaling'=>$row['opschaling'],'eenheden'=>aaParse($row['eenheden']),'voertuigen'=>aaParse($row['voertuigen']),
    'link'=>$row['link'],'views'=>(int)$row['views'],'incident'=>$row['incident']??null];
  if(str_contains((string)($row['vlaggen']??''),'groot'))$out['groot']=true;
  if(array_key_exists('hidden',$row)){$out['hidden']=(bool)(int)$row['hidden'];$out['edited']=(bool)(int)$row['edited'];}
  return $out;
}
function aaListMeldingen(mixed $limit=null, mixed $before=null): array {
  $lim=min(max((int)($limit?:300),1),1000);
  if($before!==null&&$before!==''){
    $q=aaDb()->prepare(AA_SELECT.' WHERE COALESCE(o.hidden, 0) = 0 AND m.datum < ? ORDER BY m.datum DESC, m.volgorde ASC LIMIT '.$lim); $q->execute([aaMs($before)??0]);
  }else{ $q=aaDb()->query(AA_SELECT.' WHERE COALESCE(o.hidden, 0) = 0 ORDER BY m.datum DESC, m.volgorde ASC LIMIT '.$lim); }
  return array_map('aaMeldingAPI',$q->fetchAll());
}
function aaGetMelding(string $guid, bool $includeHidden=false): ?array {
  $q=aaDb()->prepare(AA_SELECT.' WHERE m.guid = ?'); $q->execute([$guid]); $row=$q->fetch();
  if(!$row||((int)$row['hidden']&&!$includeHidden))return null;
  return aaMeldingAPI($row);
}
function aaIncidentMeldingen(string $incident): array {
  $q=aaDb()->prepare(AA_SELECT.' WHERE m.incident = ? AND COALESCE(o.hidden, 0) = 0 ORDER BY m.datum ASC LIMIT 40'); $q->execute([$incident]);
  return array_map('aaMeldingAPI',$q->fetchAll());
}
function aaAddView(string $guid): ?int {
  aaDb()->prepare('UPDATE meldingen SET views = views + 1 WHERE guid = ?')->execute([$guid]);
  $q=aaDb()->prepare('SELECT views FROM meldingen WHERE guid = ?'); $q->execute([$guid]); $v=$q->fetchColumn();
  return $v===false?null:(int)$v;
}
function aaInsertMelding(array $m): bool {
  $st=aaDb()->prepare('INSERT IGNORE INTO meldingen (guid, title, full_melding, dienst, prioriteit, datum, locatie, lat, lon, stad, regio, capcode,
    categorie, grip, opschaling, eenheden, voertuigen, link, created_at, incident, vlaggen, dedup_key, bron) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $st->execute([$m['guid'],$m['title']??'',$m['full_melding']??null,($m['dienst']??'')?:'onbekend',$m['prioriteit']??null,(int)$m['datum'],$m['locatie']??null,
    $m['lat']??null,$m['lon']??null,$m['stad']??null,$m['regio']??null,$m['capcode']??null,$m['categorie']??null,$m['grip']??null,$m['opschaling']??null,
    aaJson($m['eenheden']??[]),aaJson($m['voertuigen']??[]),$m['link']??null,$m['created_at']??aaNow(),$m['incident']??null,($m['vlaggen']??'')?:null,$m['dedup_key']??null,$m['bron']??null]);
  $ok=$st->rowCount()>0;
  if($ok)aaDb()->prepare('UPDATE meldingen SET volgorde = (SELECT v FROM (SELECT COALESCE(MAX(volgorde),0)+1 AS v FROM meldingen) x) WHERE guid = ? AND volgorde IS NULL')->execute([$m['guid']]);
  return $ok;
}

// ------------------------------------------------------------------ incident.js
function aaNorm(mixed $s): string { $s=Normalizer::normalize(mb_strtolower((string)($s??'')),Normalizer::FORM_D); return (string)preg_replace('/[^a-z0-9]/','',(string)preg_replace('/\p{Mn}/u','',(string)$s)); }
function aaHasStreet(array $m): bool { return aaHasStreetLoc($m['locatie']??null); }
function aaPlaats(array $m): string { return aaNorm($m['stad']??'')?:'apeldoorn'; }
function aaLos(array $m): bool { return in_array(strtoupper((string)($m['prioriteit']??'')),['B1','B2','B3'],true)||preg_match('/herbezet|kazerneren|proefalarm/i',(string)($m['title']??''))===1; }
function aaScore(array $m, array $o): int {
  $groot=($m['grip']??null)||($m['opschaling']??null)||($o['grip']??null)||($o['opschaling']??null);
  if(abs($o['datum']-$m['datum'])>($groot?120:45)*60000||aaLos($m)||aaLos($o))return 0;
  $s=0;
  if(aaHasStreet($m)&&aaHasStreet($o)&&aaPlaats($m)===aaPlaats($o)&&aaNorm($m['locatie'])===aaNorm($o['locatie']))$s+=3;
  elseif(aaHasStreet($m)&&aaHasStreet($o)&&($m['lat']??null)!==null&&($o['lat']??null)!==null&&aaDistanceKm((float)$m['lat'],(float)$m['lon'],(float)$o['lat'],(float)$o['lon'])<=0.35)$s+=2;
  $codes=[]; foreach($m['eenheden']??[] as $u)if(!empty($u['code']))$codes[$u['code']]=true;
  foreach($o['eenheden']??[] as $u)if(!empty($u['code'])&&isset($codes[$u['code']])){$s+=2;break;}
  if(!$s)return 0;
  return $s+(($o['dienst']??null)!==($m['dienst']??null)?1:0);
}
function aaSoortAmbu(array $m): ?string { $t=(string)($m['title']??''); return preg_match('/reanimatie/i',$t)?'reanimatie':(preg_match('/\bHV\s+weg\b|\bVKO\b|aanrijding|ongeval/i',$t)?'verkeer':null); }
function aaAmbuZonderAdres(array $m): bool { return ($m['dienst']??null)==='ambulance'&&!aaHasStreet($m)&&!aaLos($m)&&aaSoortAmbu($m)!==null; }
function aaMetAdres(array $m, ?string $soort): bool {
  if(!aaHasStreet($m)||aaLos($m))return false; $t=(string)($m['title']??'');
  return $soort==='reanimatie'?preg_match('/reanimatie/i',$t)===1:(($m['dienst']??null)!=='ambulance'&&preg_match('/aanrijding|ongeval|\bVKO\b|\bHV\s+weg\b|beknel|voertuig/i',$t)===1);
}
function aaZonderAdres(array $m, array $bekend): ?string {
  $dichtbij=fn($o)=>$o['guid']!==$m['guid']&&abs($o['datum']-$m['datum'])<=300000&&aaPlaats($o)===aaPlaats($m);
  $een=function(array $k){$i=[];foreach($k as $o)$i[($o['incident']??null)?:$o['guid']]=true;return count($i)===1?array_key_first($i):null;};
  if(aaAmbuZonderAdres($m)){
    $soort=aaSoortAmbu($m);
    $adres=array_filter($bekend,fn($o)=>$dichtbij($o)&&aaMetAdres($o,$soort));
    if($adres)return $een($adres);
    return $soort==='reanimatie'?$een(array_filter($bekend,fn($o)=>$dichtbij($o)&&aaAmbuZonderAdres($o)&&aaSoortAmbu($o)===$soort)):null;
  }
  foreach(['reanimatie','verkeer'] as $soort)
    if(aaMetAdres($m,$soort))return $een(array_filter($bekend,fn($o)=>$dichtbij($o)&&aaAmbuZonderAdres($o)&&aaSoortAmbu($o)===$soort));
  return null;
}
function aaAdresVan(string $incident, array $lijst): ?array {
  $k=array_values(array_filter($lijst,fn($o)=>((($o['incident']??null)?:$o['guid'])===$incident)&&aaHasStreet($o)&&($o['lat']??null)!==null));
  usort($k,fn($a,$b)=>$a['datum']<=>$b['datum']); return $k[0]??null;
}
/** Gives every new alert its incident (by reference). */
function aaKenToe(array &$nieuw, array $eerder): void {
  $bekend=$eerder; $idx=array_keys($nieuw);
  usort($idx,fn($a,$b)=>$nieuw[$a]['datum']<=>$nieuw[$b]['datum']);
  foreach($idx as $i){
    $m=&$nieuw[$i]; $best=null; $bs=0;
    foreach($bekend as $o){
      if($o['guid']===$m['guid'])continue;
      $s=aaScore($m,$o);
      if($s>$bs||($s===$bs&&$s>0&&$best&&abs($o['datum']-$m['datum'])<abs($best['datum']-$m['datum']))){$best=$o;$bs=$s;}
    }
    $m['incident']=$bs>=3&&$best?(($best['incident']??null)?:$best['guid']):(aaZonderAdres($m,$bekend)?:$m['guid']);
    if($m['incident']!==$m['guid']&&aaAmbuZonderAdres($m)){
      $bron=aaAdresVan($m['incident'],$bekend);
      if($bron){$m['lat']=$bron['lat'];$m['lon']=$bron['lon'];$m['locatie']=$bron['locatie'];}
    }
    $bekend[]=$m; unset($m);
  }
}
function aaRij(array $r): array { $r['datum']=(int)$r['datum']; $r['eenheden']=aaParse($r['eenheden']??null); $r['lat']=$r['lat']===null?null:(float)$r['lat']; $r['lon']=$r['lon']===null?null:(float)$r['lon']; return $r; }
function aaRecenteVoorIncident(?int $tot=null): array {
  $q=aaDb()->prepare('SELECT guid, title, dienst, prioriteit, datum, locatie, lat, lon, stad, grip, opschaling, eenheden, incident FROM meldingen WHERE datum > ? ORDER BY datum DESC LIMIT 150');
  $q->execute([($tot??aaNow())-125*60000]); return array_map('aaRij',$q->fetchAll());
}
function aaIncidentHerstel(): int {
  $q=aaDb()->prepare('SELECT guid, title, dienst, prioriteit, datum, locatie, lat, lon, stad, grip, opschaling, eenheden, incident FROM meldingen WHERE datum > ? ORDER BY datum ASC LIMIT 400');
  $q->execute([aaNow()-6*3600000]); $alle=array_map('aaRij',$q->fetchAll());
  $upd=aaDb()->prepare('UPDATE meldingen SET incident = ? WHERE guid = ?');
  foreach($alle as &$m){
    if(($m['incident']??null)!==$m['guid'])continue;
    foreach($alle as $o)if($o['guid']!==$m['guid']&&($o['incident']??null)===$m['guid'])continue 2;
    if($m['dienst']!=='ambulance'||aaHasStreet($m))continue;
    $inc=aaZonderAdres($m,array_filter($alle,fn($o)=>!empty($o['incident'])));
    if($inc&&$inc!==$m['guid']){$m['incident']=$inc;$upd->execute([$inc,$m['guid']]);}
  } unset($m);
  $pos=aaDb()->prepare('UPDATE meldingen SET lat = ?, lon = ?, locatie = ? WHERE guid = ?');
  foreach($alle as &$m){
    if(!aaAmbuZonderAdres($m)||empty($m['incident']))continue;
    $g=$m['guid']; $soort=aaSoortAmbu($m);
    $bron=aaAdresVan($m['incident'],array_filter($alle,fn($o)=>$o['guid']!==$g&&aaMetAdres($o,$soort)));
    if(!$bron)continue;
    $m['lat']=$bron['lat'];$m['lon']=$bron['lon'];$m['locatie']=$bron['locatie'];
    $pos->execute([$bron['lat'],$bron['lon'],$bron['locatie'],$g]);
  } unset($m);
  $zonder=array_values(array_filter($alle,fn($r)=>empty($r['incident'])));
  if(!$zonder)return 0;
  aaKenToe($zonder,array_values(array_filter($alle,fn($r)=>!empty($r['incident']))));
  $u=aaDb()->prepare('UPDATE meldingen SET incident = ? WHERE guid = ? AND incident IS NULL');
  foreach($zonder as $m)$u->execute([$m['incident'],$m['guid']]);
  return count($zonder);
}

// ------------------------------------------------------------------ hm.js
const AA_MIDDEN=[52.2112,5.9699];
function aaPdok(string $url): ?array {
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: A-Alerts/2.0 (+https://a-alerts.nl)']]);
  $b=curl_exec($ch); $c=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  if($c!==200||!is_string($b))throw new RuntimeException('HTTP '.$c);
  $d=json_decode($b,true); return is_array($d)?$d:null;
}
function aaHmPaal(mixed $tekst): ?array {
  if(!preg_match('/(?<![\p{L}\d])([AN])\s?(\d{1,3})\s+(?:(Re|Li|R|L)\s+)?(\d{1,3})[,.](\d)(?!\d)/iu',(string)$tekst,$m))return null;
  $z=strtolower($m[3]??'');
  return ['weg'=>strtoupper($m[1]).$m[2],'hm'=>(int)$m[4]*10+(int)$m[5],'zijde'=>str_starts_with($z,'r')?'Re':(str_starts_with($z,'l')?'Li':null)];
}
function aaPunt(mixed $s): ?array { return preg_match('/POINT\((-?[\d.]+) (-?[\d.]+)\)/',(string)$s,$m)?[(float)$m[2],(float)$m[1]]:null; }
function aaKiesPaal(array $docs): ?array {
  $p=[]; foreach($docs as $d){$pt=aaPunt($d['centroide_ll']??'');if($pt&&aaDistanceKm($pt[0],$pt[1],AA_MIDDEN[0],AA_MIDDEN[1])<=60)$p[]=['letter'=>$d['hectometerletter']??'','lat'=>$pt[0],'lon'=>$pt[1]];}
  $k=null; foreach($p as $x)if($x['letter']===''){$k=$x;break;} $k??=$p[0]??null;
  return $k?[round($k['lat'],5),round($k['lon'],5)]:null;
}
function aaKvPunt(string $key): array|false|null { $v=aaKvGet($key); if($v===null||$v==='')return false; return $v==='-'?null:array_map('floatval',explode(',',$v)); }
function aaHmPunt(array $paal): ?array {
  $key='hm:'.$paal['weg'].'|'.$paal['hm']; $oud=aaKvPunt($key); if($oud!==false)return $oud;
  try{ $d=aaPdok('https://api.pdok.nl/bzk/locatieserver/search/v3_1/free?q='.$paal['weg'].'&fq=type:hectometerpaal&fq=wegnummer:'.$paal['weg'].'&fq=hectometernummer:'.$paal['hm'].'&rows=10&fl=weergavenaam,centroide_ll,hectometerletter');
    $p=aaKiesPaal($d['response']['docs']??[]); }catch(Throwable){return null;}
  aaKvSet($key,$p?implode(',',$p):'-'); return $p;
}
function aaAlleenStraat(mixed $l): bool { $s=trim((string)($l??'')); return mb_strlen($s)>=4&&!preg_match('/\d/',$s); }
function aaKiesStraat(array $docs, string $straat, string $stad): ?array {
  $wil=mb_strtolower("$straat, $stad");
  foreach($docs as $d){ if(mb_strtolower((string)($d['weergavenaam']??''))!==$wil)continue; $pt=aaPunt($d['centroide_ll']??''); if(!$pt)continue;
    if(aaDistanceKm($pt[0],$pt[1],AA_MIDDEN[0],AA_MIDDEN[1])<=60)return [round($pt[0],5),round($pt[1],5)]; }
  return null;
}
function aaStraatPunt(mixed $locatie, mixed $stad): ?array {
  if(!aaAlleenStraat($locatie))return null;
  $straat=trim((string)$locatie); $plaats=trim((string)($stad?:'Apeldoorn'));
  $key='straat:'.mb_strtolower($straat).'|'.mb_strtolower($plaats); $oud=aaKvPunt($key); if($oud!==false)return $oud;
  try{ $d=aaPdok('https://api.pdok.nl/bzk/locatieserver/search/v3_1/free?q='.rawurlencode("$straat $plaats").'&fq=type:weg&rows=5&fl=weergavenaam,centroide_ll');
    $p=aaKiesStraat($d['response']['docs']??[],$straat,$plaats); }catch(Throwable){return null;}
  aaKvSet($key,$p?implode(',',$p):'-'); return $p;
}
function aaHmHerstel(int $max=5): int {
  $q=aaDb()->prepare("SELECT guid, title, lat, lon FROM meldingen WHERE datum > ? AND (title REGEXP 'A[0-9].*[0-9][,.][0-9]' OR title REGEXP 'N[0-9].*[0-9][,.][0-9]') ORDER BY datum DESC LIMIT 40");
  $q->execute([aaNow()-48*3600000]); $n=0; $u=aaDb()->prepare('UPDATE meldingen SET lat = ?, lon = ? WHERE guid = ?');
  foreach($q->fetchAll() as $r){ if($n>=$max)break; $paal=aaHmPaal($r['title']); if(!$paal)continue; $p=aaHmPunt($paal);
    if(!$p||((float)$r['lat']===$p[0]&&(float)$r['lon']===$p[1]))continue; $u->execute([$p[0],$p[1],$r['guid']]); $n++; }
  return $n;
}
function aaStraatHerstel(int $max=6): int {
  $q=aaDb()->prepare("SELECT guid, title, locatie, stad, lat, lon FROM meldingen WHERE datum > ? AND locatie IS NOT NULL AND locatie != '' AND locatie NOT REGEXP '[0-9]' AND (vlaggen IS NULL OR vlaggen NOT LIKE '%kruising%') ORDER BY datum DESC LIMIT 30");
  $q->execute([aaNow()-6*3600000]); $n=0; $u=aaDb()->prepare('UPDATE meldingen SET lat = ?, lon = ? WHERE guid = ?');
  foreach($q->fetchAll() as $r){ if($n>=$max)break; if(aaHmPaal($r['title']))continue; $p=aaStraatPunt($r['locatie'],$r['stad']);
    if(!$p||((float)$r['lat']===$p[0]&&(float)$r['lon']===$p[1]))continue; $u->execute([$p[0],$p[1],$r['guid']]); $n++; }
  return $n;
}
function aaCategorieHerstel(): void {
  $q=aaDb()->prepare("SELECT guid, title, full_melding, dienst, prioriteit, categorie, locatie FROM meldingen WHERE datum > ? AND dienst != 'ambulance' ORDER BY datum DESC LIMIT 150");
  $q->execute([aaNow()-2*86400000]); $u=aaDb()->prepare('UPDATE meldingen SET categorie = ? WHERE guid = ?');
  foreach($q->fetchAll() as $r){ $n=aaCategorie($r['dienst'],$r['prioriteit'],(string)(($r['full_melding']??'')?:($r['title']??'')),$r['locatie']); if($n&&$n!==$r['categorie'])$u->execute([$n,$r['guid']]); }
}

// ------------------------------------------------------------------ posten.js
function aaHeeftAlias(string $tekst, string $alias): bool {
  $e=implode('[-\s]',array_map(fn($p)=>preg_quote($p,'/'),preg_split('/[-\s]/u',mb_strtolower($alias))));
  return preg_match('/(^|[^a-z])'.$e.'([^a-z]|$)/u',$tekst)===1;
}
function aaGenoemdePosten(array $m): array {
  $soort=['brandweer'=>'brandweer','ambulance'=>'ambulance','politie'=>'politie'][$m['dienst']??'']??null; if(!$soort)return [];
  $d=[]; foreach($m['eenheden']??[] as $u)$d[]=$u['naam']??''; foreach($m['voertuigen']??[] as $v)$d[]=is_array($v)?($v['label']??''):'';
  $d[]=($m['full_melding']??'')?:($m['title']??''); $tekst=mb_strtolower(implode(' | ',$d));
  return array_values(array_filter(aaData('kazernes'),function($k)use($soort,$tekst){ if(($k['soort']??'')!==$soort)return false; foreach($k['aliassen']??[] as $a)if(aaHeeftAlias($tekst,$a))return true; return false; }));
}
function aaEigenVanPost(array $m, array $post): array {
  $mijn=function($s)use($post){foreach($post['aliassen']??[] as $a)if(aaHeeftAlias(mb_strtolower((string)($s??'')),$a))return true;return false;};
  $v=[];foreach($m['voertuigen']??[] as $x)if(is_array($x)&&!empty($x['nrb'])&&$mijn($x['label']??''))$v[]=[$x['nrb'],(string)preg_replace('/^.*? - /','',(string)$x['label'],1)];
  $c=[];foreach($m['eenheden']??[] as $u)if(!empty($u['code'])&&$mijn($u['naam']??''))$c[]=[$u['code'],(string)preg_replace('/\s*\(.*\)\s*$/','',(string)$u['naam'])];
  return ['voertuigen'=>$v,'capcodes'=>$c];
}
function aaNoteerPosten(array $meldingen): int {
  $ins=aaDb()->prepare('INSERT IGNORE INTO post_inzet (post, incident, at) VALUES (?, ?, ?)'); $n=0; $info=[];
  foreach($meldingen as $m)foreach(aaGenoemdePosten($m) as $p){
    $ins->execute([$p['id'],($m['incident']??null)?:$m['guid'],(int)$m['datum']]); $n++;
    $e=aaEigenVanPost($m,$p); if($e['voertuigen']||$e['capcodes']){$info[$p['id']]['voertuigen']=array_merge($info[$p['id']]['voertuigen']??[],$e['voertuigen']);$info[$p['id']]['capcodes']=array_merge($info[$p['id']]['capcodes']??[],$e['capcodes']);}
  }
  foreach($info as $id=>$nieuw){
    $key='post_info:'.$id; $oud=aaParse(aaKvGet($key),null)?:['voertuigen'=>[],'capcodes'=>[]]; $anders=false;
    foreach($nieuw['voertuigen']??[] as [$nr,$l])if(($oud['voertuigen'][$nr]??null)!==$l){$oud['voertuigen'][$nr]=$l;$anders=true;}
    foreach($nieuw['capcodes']??[] as [$c,$nm])if(($oud['capcodes'][$c]??null)!==$nm){$oud['capcodes'][$c]=$nm;$anders=true;}
    if($anders)aaKvSet($key,aaJson(['voertuigen'=>(object)$oud['voertuigen'],'capcodes'=>(object)$oud['capcodes']]));
  }
  return $n;
}

// ------------------------------------------------------------------ index.js ingestParsed
function aaKnownGuids(array $guids): array {
  if(!$guids)return []; $q=aaDb()->prepare('SELECT guid FROM meldingen WHERE guid IN ('.implode(',',array_fill(0,count($guids),'?')).')'); $q->execute(array_values($guids));
  return array_flip($q->fetchAll(PDO::FETCH_COLUMN));
}
/** New alerts → stored, linked, placed. Returns the alerts that were actually added. */
function aaIngestParsed(array $parsed, int $budget=45): array {
  $known=aaKnownGuids(array_column($parsed,'guid'));
  $unique=[]; $cand=aaDb()->prepare('SELECT guid, datum, stad, dienst, full_melding, title, capcode FROM meldingen WHERE datum BETWEEN ? AND ? AND stad = ? AND dienst = ? ORDER BY datum DESC LIMIT 60');
  foreach($parsed as $m){
    if(isset($known[$m['guid']]))continue;
    $m['dedup_key']=aaDedupKey($m);
    $cand->execute([$m['datum']-120000,$m['datum']+120000,$m['stad'],$m['dienst']]);
    foreach($cand->fetchAll() as $o)if(aaSameDispatch($m,$o))continue 2;
    foreach($unique as $o)if(aaSameDispatch($m,$o))continue 2;
    $unique[]=$m;
  }
  $fresh=$unique;
  foreach($fresh as &$m){
    $paal=aaHmPaal($m['title']); $hp=$paal&&$budget-->20?aaHmPunt($paal):null;
    if($hp){[$m['lat'],$m['lon']]=$hp;continue;}
    if(str_contains((string)($m['vlaggen']??''),'kruising')&&($m['lat']??null)!==null)continue;
    $sp=!empty($m['locatie'])&&$budget-->20?aaStraatPunt($m['locatie'],$m['stad']):null;
    if($sp){[$m['lat'],$m['lon']]=$sp;continue;}
    // Apple Maps geocoding of an address with a number: follows with the Apple module (MAPS keys in the private config).
  } unset($m);
  if($fresh){ try{ aaKenToe($fresh,array_values(array_filter(aaRecenteVoorIncident(),fn($r)=>!empty($r['incident'])))); }catch(Throwable){ foreach($fresh as &$m)$m['incident']??=$m['guid']; unset($m);} }
  $added=[]; foreach($fresh as $m)if(aaInsertMelding($m))$added[]=$m;
  if($added){ try{aaNoteerPosten($added);}catch(Throwable $e){error_log('[aa posten] '.$e->getMessage());} }
  $last=(int)(aaKvGet('aa_herstel_at')??0);
  if($added||aaNow()-$last>15*60000){
    aaKvSet('aa_herstel_at',(string)aaNow());
    foreach(['aaHmHerstel','aaStraatHerstel','aaCategorieHerstel','aaIncidentHerstel'] as $f){try{$f();}catch(Throwable $e){error_log('[aa '.$f.'] '.$e->getMessage());}}
  }
  return $added;
}
