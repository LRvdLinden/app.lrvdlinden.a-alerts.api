<?php
declare(strict_types=1);
/**
 * Own intake of the warning and news sources (cron, every minute): alerts.js ingestWarnings, nieuws.js nieuwsRun and the
 * housekeeping at minute 7, as in index.js. Pushes are only marked (notified), never sent here: sending is up to the push
 * module (aa/push.php) and only when PUSH_ACTIEF is "1".
 *
 * Mode AA_BRONNEN_MODUS:
 * - "proef": every source is read for real and compared with what is stored now (the copy of the Worker database);
 *   nothing is written except the report in kv aa_bronnen_proef. This proves the intake before it takes over.
 * - "actief": the intake writes, and aa/d1.php no longer copies these tables from the Worker.
 * CLI only.
 */
if(PHP_SAPI!=='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===realpath(__FILE__)){http_response_code(404);exit;}
require_once __DIR__.'/bronnen-parse.php';
require_once __DIR__.'/meldingen.php';
require_once __DIR__.'/waarschuwingen.php';
require_once __DIR__.'/nieuws.php';

const AA_BRONNEN_MODUS='proef';
const AA_SOURCES=['nlalert'=>'https://api.public-warning.app/api/v1/providers/nl-alert/alerts','burgernet'=>'https://www.burgernet.nl/active',
  'burgernetSite'=>'https://www.burgernet.nl','knmi'=>'https://www.knmi.nl/api/v1/weather-warnings'];
const AA_BRONNEN_LIJST=['nlalert','burgernet','knmi','kind','vnog'];

final class AaProef {
  public static bool $aan=false;
  public static array $rapport=[];
  public static function verschil(string $bron, string $id, string $veld, mixed $nu, mixed $was): void {
    self::$rapport[$bron]['verschillen']=(self::$rapport[$bron]['verschillen']??0)+1;
    if(count(self::$rapport[$bron]['voorbeelden']??[])<6)self::$rapport[$bron]['voorbeelden'][]=['id'=>$id,'veld'=>$veld,'eigen'=>mb_substr(is_string($nu)?$nu:aaJsJson($nu),0,140),'worker'=>mb_substr(is_string($was)?$was:aaJsJson($was),0,140)];
  }
  public static function gelijk(string $bron): void { self::$rapport[$bron]['gelijk']=(self::$rapport[$bron]['gelijk']??0)+1; }
}

function aaBronFetch(string $url, string $accept='application/json'): string {
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_ENCODING=>'',
    CURLOPT_HTTPHEADER=>['Accept: '.$accept,'User-Agent: A-Alerts/1.0 (+https://a-alerts.nl)']]);
  $b=curl_exec($ch); $c=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  if(!is_string($b)||$c<200||$c>=300)throw new RuntimeException('HTTP '.$c);
  return $b;
}
function aaBronJson(string $url): mixed { $d=json_decode(aaBronFetch($url),true); if($d===null)throw new RuntimeException('json'); return $d; }
/** kv.js kvWrite: only a changed value is written; "I am alive" keys at most once per interval. */
function aaKvWrite(string $key, ?string $value): void {
  $iv=preg_match('/^(bron_ok:.+|status_weer_ok)$/',$key)?240000:0;
  $r=aaKvRow($key);
  if($r&&$r['value']===$value)return;
  if($r&&$iv&&aaNow()-(int)$r['at']<$iv)return;
  aaKvSet($key,(string)$value);
}
function aaWaarde(mixed $a, mixed $b): bool {
  if(($a===null||$a==='')&&($b===null))return $a===null; // JS: (a ?? null) === (b ?? null)
  if(is_numeric($a)&&is_numeric($b)&&!is_string($a)&&!is_string($b))return abs((float)$a-(float)$b)<1e-9;
  if((is_int($a)||is_float($a))&&is_string($b)&&is_numeric($b))return abs((float)$a-(float)$b)<1e-9;
  if((is_int($b)||is_float($b))&&is_string($a)&&is_numeric($a))return abs((float)$a-(float)$b)<1e-9;
  return $a===$b;
}

// ------------------------------------------------------------------ warnings: storage (alerts.js upsert)
function aaWarningExtra(array $w): ?string {
  if($w['bron']==='knmi')return aaJsJson(['verwachting'=>$w['verwachting']??null,'advies'=>$w['advies']??null,'kop'=>$w['kop']??null,'omschrijving'=>$w['omschrijving']??null,
    'details'=>$w['details']??null,'uitgifte'=>$w['uitgifte']??null,'tijdvakken'=>aaOr($w['tijdvakken']??null),'landelijk'=>aaOr($w['landelijk']??null)]);
  if((in_array($w['bron'],AA_KIND_BRONNEN,true)||$w['bron']==='vnog')&&!empty($w['extra']))return aaJsJson($w['extra']);
  if($w['bron']==='burgernet'&&(aaOr($w['afloop']??null)||!empty($w['berichten'])||aaOr($w['straat']??null)))
    return aaJsJson(['startbericht'=>aaOr($w['startbericht']??null),'afloop'=>aaOr($w['afloop']??null),'berichten'=>aaOr($w['berichten']??null)]+(aaOr($w['straat']??null)?['straat'=>$w['straat']]:[]));
  return null;
}
function aaWarningPrev(string $id): ?array {
  $q=aaDb()->prepare('SELECT w.titel, w.tekst, w.tekst_en, w.plaats, w.niveau, w.soort, w.start_at, w.end_at, w.status, w.gebied, w.lat, w.lon, w.link,
      w.notified, w.first_seen, wk.data AS extra FROM waarschuwingen w LEFT JOIN waarschuwing_knmi wk ON wk.id = w.id WHERE w.id = ?');
  $q->execute([$id]); $r=$q->fetch(); return $r?:null;
}
function aaUpsertWarning(array $w, int $now): ?array {
  $prev=aaWarningPrev($w['id']); $extra=aaWarningExtra($w);
  $namen=['titel','tekst','tekst_en','plaats','niveau','soort','start_at','end_at','status','gebied','lat','lon','link'];
  $velden=[$w['titel']??null,$w['tekst']??null,$w['tekst_en']??null,$w['plaats']??null,$w['niveau']??null,$w['soort']??null,$w['start_at'],$w['end_at']??null,$w['status'],
    $w['gebied']===null||!isset($w['gebied'])?null:aaJsJson($w['gebied']),$w['lat']??null,$w['lon']??null,$w['link']??null];
  $rijGelijk=$prev!==null; $eerste=null;
  if($prev)foreach($namen as $i=>$n)if(!aaWaarde($velden[$i],$prev[$n])){$rijGelijk=false;$eerste??=[$n,$velden[$i],$prev[$n]];}
  $extraGelijk=$extra===null||$extra===($prev['extra']??null);
  if(AaProef::$aan){
    $bron=$w['bron'];
    if(!$prev)AaProef::verschil($bron,$w['id'],'(niet bij de Worker)',$w['titel']??'',null);
    elseif(!$rijGelijk)AaProef::verschil($bron,$w['id'],$eerste[0],$eerste[1],$eerste[2]);
    elseif(!$extraGelijk)AaProef::verschil($bron,$w['id'],'extra',$extra,$prev['extra']);
    else AaProef::gelijk($bron);
    return $prev;
  }
  if($rijGelijk&&$extraGelijk)return $prev;
  aaDb()->prepare('INSERT INTO waarschuwingen (id, bron, titel, tekst, tekst_en, plaats, niveau, soort, start_at, end_at, status, gebied, lat, lon, link, first_seen, updated_at, notified)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE titel = VALUES(titel), tekst = VALUES(tekst), tekst_en = VALUES(tekst_en), plaats = VALUES(plaats),
    niveau = VALUES(niveau), soort = VALUES(soort), start_at = VALUES(start_at), end_at = VALUES(end_at), status = VALUES(status), gebied = VALUES(gebied),
    lat = VALUES(lat), lon = VALUES(lon), link = VALUES(link), updated_at = VALUES(updated_at)')
    ->execute(array_merge([$w['id'],$w['bron']],$velden,[$prev['first_seen']??$now,$now,$prev['notified']??null]));
  if(!$prev)aaDb()->prepare('UPDATE waarschuwingen SET volgorde = (SELECT v FROM (SELECT COALESCE(MAX(volgorde),0)+1 AS v FROM waarschuwingen) x) WHERE id = ? AND volgorde IS NULL')->execute([$w['id']]);
  if(!$extraGelijk)aaDb()->prepare('INSERT INTO waarschuwing_knmi (id, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')->execute([$w['id'],$extra]);
  return $prev;
}
function aaMarkNotified(string $id, string $v): void { if(AaProef::$aan)return; aaDb()->prepare('UPDATE waarschuwingen SET notified = ? WHERE id = ?')->execute([$v,$id]); }
function aaSchrijf(string $sql, array $args=[]): void { if(AaProef::$aan)return; aaDb()->prepare($sql)->execute($args); }

/** alerts.js straatPunt for Burgernet and VNOG (cache bn_straat:). */
function aaBnStraatPunt(string $straat, array $plaats): ?array {
  $key='bn_straat:'.mb_strtolower($straat).'|'.mb_strtolower($plaats['naam']);
  $oud=aaKvGet($key); if($oud!==null&&$oud!=='')return $oud==='-'?null:array_map('floatval',explode(',',$oud));
  $punt=null;
  try{ $d=aaPdok('https://api.pdok.nl/bzk/locatieserver/search/v3_1/free?q='.aaEncodeURIComponent("$straat ".$plaats['naam']).'&fq=type:weg&rows=3&fl=weergavenaam,centroide_ll');
    foreach($d['response']['docs']??[] as $doc){ $pt=aaPunt($doc['centroide_ll']??''); if(!$pt)continue;
      if(aaDistanceKm($pt[0],$pt[1],(float)$plaats['lat'],(float)$plaats['lon'])<=15){$punt=[round($pt[0]*1e5)/1e5,round($pt[1]*1e5)/1e5];break;} }
  }catch(Throwable){return null;}
  if(!AaProef::$aan)aaKvSet($key,$punt?implode(',',$punt):'-');
  return $punt;
}
function aaPlaatsNaam(?string $naam): array { foreach(aaPlaatsen() as $p)if($p['naam']===$naam)return $p; foreach(aaPlaatsen() as $p)if($p['naam']==='Apeldoorn')return $p; return []; }
function aaBurgernetLocatie(array $w): array {
  $straat=aaBurgernetStraat(aaOr($w['startbericht']??null)??$w['tekst']); if(!$straat)return $w;
  $punt=aaBnStraatPunt($straat,aaPlaatsNaam($w['plaats'])); if(!$punt)return $w;
  $w['straat']=$straat; $w['lat']=$punt[0]; $w['lon']=$punt[1]; return $w;
}
function aaVnogLocatie(array $w): array {
  $straat=$w['extra']['straat']??null;
  if(!$straat||($w['extra']['plek']??null)==='beheer'||(($w['lat']??null)!==null&&!empty($w['extra']['incident'])))return $w;
  $q=aaDb()->prepare('SELECT m.guid, m.incident, m.dienst, COALESCE(o.locatie, m.locatie) AS locatie, COALESCE(o.lat, m.lat) AS lat, COALESCE(o.lon, m.lon) AS lon FROM meldingen m
    LEFT JOIN overrides o ON o.guid = m.guid WHERE COALESCE(o.hidden, 0) = 0 AND m.datum BETWEEN ? AND ? AND COALESCE(o.lat, m.lat) IS NOT NULL ORDER BY m.datum, m.volgorde LIMIT 400');
  $q->execute([$w['start_at']-AA_VNOG_VOOR_MS,$w['start_at']+AA_VNOG_NA_MS]);
  $in=array_values(array_filter($q->fetchAll(),fn($r)=>aaInStraat($r['locatie'],$straat)));
  $m=null; foreach($in as $r)if($r['dienst']==='brandweer'){$m=$r;break;} $m??=$in[0]??null;
  if($m){ $w['lat']=aaNum($m['lat']); $w['lon']=aaNum($m['lon']); $w['extra']['incident']=aaOr($m['incident'])??$m['guid']; $w['extra']['plek']='p2000'; $w['berichten']=$w['extra']['berichten']; return $w; }
  if(($w['lat']??null)!==null)return $w;
  $punt=aaBnStraatPunt($straat,aaPlaatsNaam($w['plaats']));
  if($punt){ $w['lat']=$punt[0]; $w['lon']=$punt[1]; $w['extra']['plek']='straat'; }
  return $w;
}
function aaWarningRow(string $id): ?array {
  $q=aaDb()->prepare('SELECT w.*, wk.data AS extra FROM waarschuwingen w LEFT JOIN waarschuwing_knmi wk ON wk.id = w.id WHERE w.id = ?'); $q->execute([$id]); $r=$q->fetch(); return $r?:null;
}
function aaBurgernetPaginas(int $now, array $actief): array {
  $q=aaDb()->prepare("SELECT w.id, w.status, wk.data FROM waarschuwingen w LEFT JOIN waarschuwing_knmi wk ON wk.id = w.id WHERE w.bron = 'burgernet' AND (w.status = 'actief' OR w.updated_at > ?)");
  $q->execute([$now-2*86400000]); $ids=$actief;
  foreach($q->fetchAll() as $r){ $id=preg_replace('/^burgernet:/','',$r['id']); if(!preg_match('/^\d{4,}$/',$id)||in_array($id,$ids,true))continue;
    if($r['status']==='actief')$ids[]=$id; elseif(!str_contains((string)$r['data'],'"berichten":['))$ids[]=$id; }
  $out=[];
  foreach(array_slice($ids,0,4) as $id){ try{ $w=aaFromBurgernetSite((string)$id,aaBronFetch(AA_SOURCES['burgernetSite'].'/burgernet/'.$id,'text/html'),$now); if($w)$out[]=$w; }catch(Throwable){} }
  return $out;
}

/** alerts.js ingestWarnings: returns the pushes the Worker would queue. */
function aaIngestWarnings(): array {
  $now=aaNow(); $toPush=[]; $status=[];
  $firstRun=!aaDb()->query('SELECT 1 FROM waarschuwingen LIMIT 1')->fetchColumn();
  $handle=function(string $bron, array $items)use(&$toPush,$firstRun,$now){
    foreach($items as $w){
      $prev=aaUpsertWarning($w,$now);
      if($w['status']==='beeindigd'||$firstRun){ if(empty($prev['notified']))aaMarkNotified($w['id'],(string)(aaOr($w['niveau']??null)??'1')); continue; }
      $up=$w['bron']==='knmi'&&!empty($prev['notified'])&&aaLevelRank($w['niveau'])>aaLevelRank($prev['notified']);
      if(empty($prev['notified']))$toPush[]=['id'=>$w['id'],'upgrade'=>false,'kind'=>'n']; elseif($up)$toPush[]=['id'=>$w['id'],'upgrade'=>true,'kind'=>'u'];
      if(empty($prev['notified'])||$up)aaMarkNotified($w['id'],(string)(aaOr($w['niveau']??null)??'1'));
    }
  };
  try{ $d=aaBronJson(AA_SOURCES['nlalert']); $list=array_values(array_filter(array_map(fn($a)=>aaFromNlAlert($a,$now),is_array($d['data']??null)?$d['data']:[])));
    $handle('nlalert',$list); $status['nlalert']='ok '.count($list); }catch(Throwable $e){ $status['nlalert']='fout: '.$e->getMessage(); }

  // Burgernet: the list of running actions, then the page of each one in our area.
  $merged=[]; $lijst=null; $fouten=[];
  try{ $d=aaBronJson(AA_SOURCES['burgernet'].'?t='.intdiv($now,30000)); if(!is_array($d)||!array_is_list($d))throw new RuntimeException('geen lijst');
    $lijst=array_values(array_filter(array_map(fn($a)=>aaFromBurgernet($a,$now),$d)));
    foreach($lijst as $w){ if($w['startOnbekend']){ $q=aaDb()->prepare('SELECT start_at FROM waarschuwingen WHERE id = ?'); $q->execute([$w['id']]); $o=$q->fetchColumn(); if($o)$w['start_at']=(int)$o; }
      unset($w['startOnbekend']); $merged[$w['id']]=$w; }
  }catch(Throwable $e){ $fouten[]='lijst '.$e->getMessage(); }
  try{ $actief=array_values(array_filter(array_map(fn($w)=>preg_replace('/^burgernet:/','',$w['id']),$lijst??[]),fn($i)=>preg_match('/^\d{4,}$/',$i)));
    foreach(aaBurgernetPaginas($now,$actief) as $w){ if($lijst!==null&&!isset($merged[$w['id']])&&$w['status']==='actief'){$w['status']='beeindigd';$w['end_at']=$w['end_at']??$now;} $merged[$w['id']]=$w; }
  }catch(Throwable $e){ $fouten[]="pagina's ".$e->getMessage(); }
  foreach($merged as $id=>$w){ try{ $merged[$id]=aaBurgernetLocatie($w); }catch(Throwable $e){ $fouten[]='straat '.$e->getMessage(); } }
  if($lijst!==null){
    $handle('burgernet',array_values($merged)); $listed=array_flip(array_map(fn($w)=>$w['id'],$lijst));
    foreach(aaDb()->query("SELECT id FROM waarschuwingen WHERE bron = 'burgernet' AND status = 'actief'")->fetchAll(PDO::FETCH_COLUMN) as $id)
      if(!isset($listed[$id])){ if(AaProef::$aan){ if(!isset($merged[$id]))AaProef::verschil('burgernet',$id,'status','beeindigd','actief'); } else aaSchrijf("UPDATE waarschuwingen SET status = 'beeindigd', end_at = ?, updated_at = ? WHERE id = ?",[$now,$now,$id]); }
    $status['burgernet']='ok '.count($lijst).($fouten?' ('.implode(', ',$fouten).')':'');
  }else{ if($merged)$handle('burgernet',array_values($merged)); $status['burgernet']='fout: '.implode(', ',$fouten); }

  // AMBER Alert and Vermist Kind Alert.
  try{
    $groepen=aaKindGroepeer(aaBronJson(AA_KIND_API),$now); $actief=0;
    foreach($groepen as $id=>$berichten){
      $row=aaWarningRow($id);
      $w=aaKindWarning($berichten,$row?['row'=>$row,'extra'=>aaParse($row['extra'],[])?:[]]:null,$now); if(!$w)continue;
      $nb=$w['_nieuwBericht']; $sn=$w['_soortNieuw']; unset($w['_nieuwBericht'],$w['_soortNieuw']);
      aaUpsertWarning($w,$now); if($w['status']==='actief')$actief++;
      if($firstRun){aaMarkNotified($w['id'],'1');continue;}
      if(empty($row['notified'])){ if($w['status']==='actief')$toPush[]=['id'=>$w['id'],'kind'=>'n']; aaMarkNotified($w['id'],'1'); }
      elseif($nb&&$row['status']!=='beeindigd')$toPush[]=['id'=>$w['id'],'kind'=>in_array('afloop',$sn,true)?'a':'u'];
    }
    foreach(aaDb()->query("SELECT w.*, wk.data AS extra FROM waarschuwingen w LEFT JOIN waarschuwing_knmi wk ON wk.id = w.id WHERE w.bron IN ('amber', 'vermistkind') AND w.status = 'actief'")->fetchAll() as $r){
      if(isset($groepen[$r['id']]))continue;
      $w=aaKindWarning([['id'=>$r['id'],'bron'=>$r['bron'],'messageId'=>'einde-'.$now,'soort'=>'afloop','at'=>$now,'titel'=>'','tekst'=>'','kop'=>'','gebied'=>$r['plaats'],'cirkel'=>null,'link'=>$r['link'],'foto'=>null,'foto_klein'=>null,'lat'=>null,'lon'=>null]],
        ['row'=>$r,'extra'=>aaParse($r['extra'],[])?:[]],$now);
      if($w){ unset($w['_nieuwBericht'],$w['_soortNieuw']); aaUpsertWarning($w,$now); if($r['notified']&&!$firstRun)$toPush[]=['id'=>$w['id'],'kind'=>'a']; }
    }
    $status['kind']="ok $actief";
  }catch(Throwable $e){ $status['kind']='fout: '.$e->getMessage(); }

  // VNOG: running incidents every two minutes; the whole list once when a running incident left it.
  if(aaKvGet('vnog_uit')==='1')$status['vnog']='uit';
  elseif(intdiv($now,60000)%2===0||AaProef::$aan){
    try{
      $verwerk=function($inc)use($now,$firstRun,&$toPush){
        $row=aaWarningRow('vnog:'.($inc['id']??''));
        $w=aaVnogWarning($inc,$row?['row'=>$row,'extra'=>aaParse($row['extra'],[])?:[]]:null,$now); if(!$w)return null;
        if(($w['extra']['plek']??null)!=='beheer'&&(($w['lat']??null)===null||empty($w['extra']['incident']))){
          if(!$row||$w['_nieuw']||$now-$w['start_at']<2*3600000||($w['status']==='actief'&&intdiv($now,60000)%10===0)){ try{ $w=aaVnogLocatie($w); }catch(Throwable){} }
        }
        $flags=[$w['_nieuw'],$w['_gripHoger'],$w['_afgesloten'],$w['_vers']]; unset($w['_nieuw'],$w['_gripHoger'],$w['_afgesloten'],$w['_vers']);
        aaUpsertWarning($w,$now);
        if($firstRun||empty($row['notified'])){ if(!$firstRun&&$w['status']==='actief'&&$flags[3])$toPush[]=['id'=>$w['id'],'kind'=>'n']; aaMarkNotified($w['id'],'1'); }
        elseif($flags[2])$toPush[]=['id'=>$w['id'],'kind'=>'a']; elseif($flags[1])$toPush[]=['id'=>$w['id'],'kind'=>'s']; elseif($flags[0]&&$w['status']==='actief')$toPush[]=['id'=>$w['id'],'kind'=>'u'];
        return $w;
      };
      $gezien=[]; $actief=0;
      foreach(aaVnogLijst(aaBronJson(AA_VNOG_API_ACTIEF)) as $inc){ $w=$verwerk($inc); if($w){$gezien[$w['id']]=true; if($w['status']==='actief')$actief++;} }
      $weg=array_values(array_filter(aaDb()->query("SELECT id FROM waarschuwingen WHERE bron = 'vnog' AND status = 'actief'")->fetchAll(PDO::FETCH_COLUMN),fn($id)=>!isset($gezien[$id])));
      if($weg){ $alles=aaVnogLijst(aaBronJson(AA_VNOG_API_ALLES));
        foreach($weg as $id){ $inc=null; foreach($alles as $i)if('vnog:'.($i['id']??'')===$id){$inc=$i;break;}
          if($inc){$verwerk($inc);continue;}
          $oud=aaWarningRow($id); $verwerk(['id'=>substr($id,5),'title'=>$oud['titel']??'','url'=>$oud['link']??'','updates'=>[],'status'=>false]); } }
      $status['vnog']="ok $actief";
    }catch(Throwable $e){ $status['vnog']='fout: '.$e->getMessage(); }
  }else $status['vnog']='ongewijzigd';

  // KNMI: the full set only when its revision changed (or 30 minutes passed).
  try{
    $v=aaBronJson(AA_SOURCES['knmi'].'/version'); $rev=(string)($v['revision']??'');
    $last=(string)(aaKvGet('knmi_revision')??''); [$lastRev,$lastAt]=array_pad(explode('@',$last),2,'0');
    if($rev===''||"v3:$rev"!==$lastRev||aaNow()-(int)$lastAt>30*60000||AaProef::$aan){
      $d=aaBronJson(AA_SOURCES['knmi'].'.json'); $pagina=[];
      if(!empty($d['warning_events'])){ try{ $pagina=aaKnmiPagina(aaBronFetch('https://www.knmi.nl/nederland-nu/weer/waarschuwingen','text/html')); }catch(Throwable){} }
      $list=aaFromKnmi($d,$now,$pagina); $handle('knmi',$list); $ids=array_flip(array_map(fn($w)=>$w['id'],$list));
      $q=aaDb()->prepare("SELECT id, start_at FROM waarschuwingen WHERE bron = 'knmi' AND end_at >= ? AND status != 'beeindigd'"); $q->execute([$now]);
      foreach($q->fetchAll() as $r)if(!isset($ids[$r['id']])){ if(AaProef::$aan)AaProef::verschil('knmi',$r['id'],'status','beeindigd','actief'); else aaSchrijf("UPDATE waarschuwingen SET status = 'beeindigd', end_at = ?, updated_at = ? WHERE id = ?",[max((int)$r['start_at'],$now),$now,$r['id']]); }
      if(!AaProef::$aan)aaKvWrite('knmi_revision',"v3:$rev@".aaNow());
      $status['knmi']='ok '.count($list);
    }else $status['knmi']='ongewijzigd';
    if(!$firstRun)$toPush=array_merge($toPush,aaKnmiExtraPushes($now));
    aaSchrijf("UPDATE waarschuwingen SET status = 'beeindigd', updated_at = ? WHERE bron = 'knmi' AND end_at < ? AND status != 'beeindigd'",[$now,$now]);
    aaSchrijf("UPDATE waarschuwingen SET status = 'actief' WHERE bron = 'knmi' AND start_at <= ? AND end_at >= ? AND status = 'verwacht'",[$now,$now]);
    aaSchrijf("UPDATE waarschuwingen SET status = 'beeindigd', updated_at = ? WHERE bron = 'nlalert' AND end_at IS NOT NULL AND end_at < ? AND status != 'beeindigd'",[$now,$now]);
  }catch(Throwable $e){ $status['knmi']='fout: '.$e->getMessage(); }

  if(!AaProef::$aan){
    aaKvWrite('warnings_status',aaJsJson(['at'=>aaIso($now)]+$status));
    // admin-alerts.js recordBronnen (verkeer is the traffic module's own).
    foreach(AA_BRONNEN_LIJST as $b){ $s=(string)($status[$b]??''); if(str_starts_with($s,'ok')||$s==='ongewijzigd'||$s==='uit')aaKvWrite("bron_ok:$b",(string)aaNow()); elseif($s!=='')aaKvWrite("bron_fout:$b",$s); }
  }
  AaProef::$rapport['_status']=$status;
  return $toPush;
}
/** alerts.js knmiExtraPushes: "v" one hour before, "g" lifted (marked once in kv knmi_push:<kind>:<id>). */
function aaKnmiExtraPushes(int $now): array {
  $uit=[]; $q=aaDb()->prepare("SELECT id, titel, niveau, status, start_at, end_at, first_seen, notified FROM waarschuwingen WHERE bron = 'knmi' AND end_at >= ?");
  $q->execute([$now-3*3600000]); $rs=$q->fetchAll();
  $actief=array_filter($rs,fn($r)=>$r['start_at']<=$now&&$r['end_at']>=$now&&$r['status']!=='beeindigd');
  foreach($rs as $r){ if(!$r['notified']||$r['status']==='beeindigd')continue;
    if($r['start_at']>$now&&$r['start_at']-$now<=3600000&&$r['start_at']-$r['first_seen']>3600000){ $k='knmi_push:v:'.$r['id']; if(aaKvGet($k)===null){ if(!AaProef::$aan)aaKvSet($k,'1'); $uit[]=['id'=>$r['id'],'kind'=>'v']; } } }
  $groepen=[];
  foreach($rs as $r){ if(!$r['notified']||!($r['status']==='beeindigd'||$r['end_at']<$now))continue;
    foreach($actief as $a)if($a['titel']===$r['titel'])continue 2;
    foreach($rs as $x)if($x['titel']===$r['titel']&&$x['status']!=='beeindigd'&&$x['start_at']>$now&&$x['start_at']-$now<=3600000)continue 2;
    $groepen[$r['titel']][]=$r; }
  foreach($groepen as $g){ $open=array_values(array_filter($g,fn($r)=>aaKvGet('knmi_push:g:'.$r['id'])===null)); if(!$open)continue;
    if(!AaProef::$aan)foreach($open as $r)aaKvSet('knmi_push:g:'.$r['id'],'1');
    $top=array_reduce($open,fn($a,$b)=>$a===null||aaLevelRank($b['niveau'])>aaLevelRank($a['niveau'])||($b['niveau']===$a['niveau']&&$b['end_at']>$a['end_at'])?$b:$a);
    $uit[]=['id'=>$top['id'],'kind'=>'g','levels'=>array_values(array_unique(array_map(fn($r)=>$r['niveau'],$open)))]; }
  return $uit;
}

// ------------------------------------------------------------------ news (nieuws.js)
function aaUpsertNieuws(array $it, int $now): ?array {
  $q=aaDb()->prepare('SELECT id, titel, intro, plaats, lat, lon, url, categorie, status, niveau, at, data, notified FROM nieuws WHERE id = ?'); $q->execute([$it['id']]); $prev=$q->fetch()?:null;
  $data=aaJsJson(aaOr($it['data']??null)??new stdClass());
  $namen=['titel','intro','plaats','lat','lon','url','categorie','status','niveau','at','data'];
  $nieuw=[$it['titel'],$it['intro']??null,$it['plaats']??null,$it['lat']??null,$it['lon']??null,$it['url']??null,$it['categorie']??null,$it['status']??null,$it['niveau']??null,$it['at'],$data];
  $gelijk=$prev!==null; $eerste=null; if($prev)foreach($namen as $i=>$n)if(!aaWaarde($nieuw[$i],$prev[$n])){$gelijk=false;$eerste??=[$n,$nieuw[$i],$prev[$n]];}
  if(AaProef::$aan){
    $b='nieuws_'.$it['soort'];
    if(!$prev)AaProef::verschil($b,$it['id'],'(niet bij de Worker)',$it['titel'],null); elseif(!$gelijk)AaProef::verschil($b,$it['id'],$eerste[0],$eerste[1],$eerste[2]); else AaProef::gelijk($b);
    return $prev;
  }
  if($gelijk)return $prev;
  aaDb()->prepare('INSERT INTO nieuws (id, soort, bron, ref, titel, intro, plaats, lat, lon, url, categorie, status, niveau, at, updated_at, data, hidden, notified, created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NULL,?) ON DUPLICATE KEY UPDATE titel = VALUES(titel), intro = VALUES(intro), plaats = VALUES(plaats), lat = VALUES(lat), lon = VALUES(lon),
    url = VALUES(url), categorie = VALUES(categorie), status = VALUES(status), niveau = VALUES(niveau), at = VALUES(at), updated_at = VALUES(updated_at), data = VALUES(data)')
    ->execute([$it['id'],$it['soort'],$it['bron'],$it['ref']??null,$it['titel'],$it['intro']??null,$it['plaats']??null,$it['lat']??null,$it['lon']??null,$it['url']??null,
      $it['categorie']??null,$it['status']??null,$it['niveau']??null,$it['at'],$now,$data,$now]);
  return $prev;
}
function aaWisKindAanvullingen(string $nid): void {
  if(AaProef::$aan)return;
  $q=aaDb()->prepare('SELECT foto FROM nieuws_updates WHERE nieuws_id = ? AND foto IS NOT NULL'); $q->execute([$nid]); $fotos=$q->fetchAll(PDO::FETCH_COLUMN);
  aaDb()->prepare('UPDATE nieuws_updates SET tekst = ?, foto = NULL, url = NULL WHERE nieuws_id = ? AND tekst != ?')->execute([AA_VERWIJDERD,$nid,AA_VERWIJDERD]);
  aaDb()->prepare('UPDATE nieuws SET foto = NULL WHERE id = ?')->execute([$nid]);
  foreach($fotos as $f)aaDb()->prepare('DELETE FROM nieuws_fotos WHERE id = ?')->execute([$f]);
}
/** nieuws.js syncWaarschuwingen: weather articles and liveblogs follow the warnings. */
function aaSyncWaarschuwingen(int $now, bool $alleWeer=false): void {
  $last=(int)(aaKvGet('nieuws_sync')??0); $since=$last?$last-120000:$now-14*86400000;
  if(AaProef::$aan)$since=$now-14*86400000; // in the test every recent warning is compared
  if($alleWeer)$ids=aaDb()->query("SELECT ref AS id FROM nieuws WHERE soort = 'weer' AND ref IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
  else{ $q=aaDb()->prepare("SELECT w.id FROM waarschuwingen w LEFT JOIN waarschuwing_overrides wo ON wo.id = w.id
    WHERE w.bron IN ('knmi','burgernet','amber','vermistkind','nlalert','vnog') AND (w.updated_at > ? OR w.first_seen > ? OR COALESCE(wo.updated_at, 0) > ?) ORDER BY w.updated_at LIMIT ".(AaProef::$aan?400:60));
    $q->execute([$since,$since,$since]); $ids=$q->fetchAll(PDO::FETCH_COLUMN); }
  foreach($ids as $id){
    $w=aaGetWarning((string)$id); if(!$w)continue;
    if($w['bron']==='knmi'){
      $a=aaWeerArtikel($w); $code=$a['opgeheven']?'green':(aaOr($w['niveau'])??'yellow');
      $q=aaDb()->prepare("SELECT id, niveau, status, at FROM nieuws WHERE soort = 'weer' AND ref = ? ORDER BY created_at DESC, at DESC"); $q->execute([$w['id']]); $eerder=$q->fetchAll();
      $laatste=$eerder[0]??null; $codeVan=fn($r)=>$r['status']==='afgerond'?'green':(aaOr($r['niveau'])??'yellow');
      if(!$laatste){$nid=aaNieuwsId('w',$w['id']);$at=aaDateParse($w['eerst_gezien'])?:aaDateParse($w['start']);}
      elseif($codeVan($laatste)===$code){$nid=$laatste['id'];$at=(int)$laatste['at'];}
      else{$nid=aaNieuwsId('w',$w['id'].'#'.$code.'#'.count($eerder));$at=$now;}
      aaUpsertNieuws(['id'=>$nid,'soort'=>'weer','bron'=>'knmi','ref'=>$w['id'],'titel'=>$a['titel'],'intro'=>$a['intro'],'plaats'=>'Gelderland','url'=>$w['link'],
        'categorie'=>$a['weer']['icoon'],'status'=>$a['opgeheven']?'afgerond':'actief','niveau'=>$w['niveau'],'at'=>$at,'data'=>['alineas'=>$a['alineas'],'weer'=>$a['weer']]],$now);
    }elseif(in_array($w['bron'],AA_LIVE_BRONNEN,true)){
      $l=aaLiveblogVan($w); $nid=aaNieuwsId('l',$w['id']); $klaar=$w['status']==='beeindigd';
      $laatste=max(aaDateParse($w['start'])??0,...array_map(fn($b)=>aaDateParse($b['at'])??0,$l['berichten']?:[['at'=>null]]));
      aaUpsertNieuws(['id'=>$nid,'soort'=>'liveblog','bron'=>$w['bron'],'ref'=>$w['id'],'titel'=>$l['titel'],'intro'=>$l['intro'],'plaats'=>$w['plaats'],'lat'=>$w['lat'],'lon'=>$w['lon'],
        'url'=>$w['link'],'categorie'=>null,'status'=>$klaar?'afgerond':'actief','niveau'=>null,'at'=>$laatste?:$now,'data'=>['berichten'=>$l['berichten'],'start'=>$w['start']]],$now);
      if($klaar&&in_array($w['bron'],AA_KIND_BRONNEN,true))aaWisKindAanvullingen($nid);
    }
  }
  if(!$alleWeer&&!AaProef::$aan)aaKvSet('nieuws_sync',(string)$now);
}
/** nieuws.js syncPolitie: every 10 minutes. Returns new articles to push. */
function aaSyncPolitie(int $now): array {
  $last=(int)(aaKvGet('nieuws_politie_at')??0);
  if(!AaProef::$aan){ if($now-$last<10*60000)return []; aaKvSet('nieuws_politie_at',(string)$now); }
  $eerste=!$last;
  $ch=curl_init('https://api.politie.nl/v4/nieuws?'.http_build_query(['language'=>'nl','lat'=>'52.2112','lon'=>'5.9699','radius'=>'25','maxnumberofitems'=>'25','offset'=>'0']));
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_ENCODING=>'',CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: A-Alerts/2.0 (+https://a-alerts.nl)']]);
  $b=curl_exec($ch); $c=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  if($c===204){ if(!AaProef::$aan)aaKvSet('nieuws_politie_status',aaJsJson(['ok'=>true,'at'=>$now,'n'=>0])); return []; }
  if($c<200||$c>=300){ if(!AaProef::$aan)aaKvSet('nieuws_politie_status',aaJsJson(['ok'=>false,'at'=>$now,'fout'=>"HTTP $c"])); return []; }
  $data=json_decode((string)$b,true); $alle=is_array($data['nieuwsberichten']??null)?$data['nieuwsberichten']:[];
  $lijst=array_values(array_filter(array_map('aaPolitieItem',$alle))); $nieuw=[];
  foreach($lijst as $p){
    $id=aaNieuwsId('p',$p['bronId']);
    $prev=aaUpsertNieuws(['id'=>$id,'soort'=>'politie','bron'=>'politie','ref'=>$p['bronId'],'titel'=>$p['titel'],'intro'=>$p['intro'],'plaats'=>$p['plaats'],'lat'=>$p['lat'],'lon'=>$p['lon'],'url'=>$p['url'],
      'categorie'=>$p['categorie'],'status'=>null,'at'=>$p['at'],'data'=>['alineas'=>$p['alineas'],'plaatsen'=>$p['plaatsen'],'gebied'=>$p['gebied']]],$now);
    if(!$prev&&!$eerste&&$now-$p['at']<6*3600000)$nieuw[]=$id;
    if(!$prev&&$eerste)aaSchrijf("UPDATE nieuws SET notified = '1' WHERE id = ?",[$id]);
  }
  if(!AaProef::$aan)aaKvSet('nieuws_politie_status',aaJsJson(['ok'=>true,'at'=>$now,'n'=>count($lijst),'totaal'=>count($alle)]));
  return $nieuw;
}
/** nieuws.js sync112: Apeldoorn every 5 minutes, the other places one per run. */
function aaSync112(int $now): array {
  $slugs=aaSlugs112(); $doelen=[];
  $lastA=(int)(aaKvGet('n112_apeldoorn_at')??0);
  if($now-$lastA>=5*60000||AaProef::$aan){ $doelen[]=$slugs[0]; if(!AaProef::$aan)aaKvSet('n112_apeldoorn_at',(string)$now); }
  $rest=array_slice($slugs,1); $i=((int)(aaKvGet('n112_i')??0))%count($rest); $doelen[]=$rest[$i]; if(!AaProef::$aan)aaKvSet('n112_i',(string)($i+1));
  $nieuw=[]; $status=aaParse(aaKvGet('n112_status'),[]); if(!is_array($status))$status=[];
  foreach($doelen as $d){
    $eerste=empty($status[$d['slug']]);
    try{
      $items=aaParse112(aaBronFetch('https://112nieuws.net/tag/'.$d['slug'].'/feed/','application/rss+xml, application/xml'),$d['naam']);
      foreach($items as $x){
        $id=aaNieuwsId('r',$x['bronId']); $q=aaDb()->prepare('SELECT id, plaats FROM nieuws WHERE id = ?'); $q->execute([$id]); $best=$q->fetch()?:null;
        $plaats=$best&&!($best['plaats']==='Apeldoorn'&&$d['naam']!=='Apeldoorn')?$best['plaats']:$d['naam'];
        aaUpsertNieuws(['id'=>$id,'soort'=>'regionaal','bron'=>'112nieuws','ref'=>$x['bronId'],'titel'=>$x['titel'],'intro'=>$x['intro'],'plaats'=>$plaats,'url'=>$x['link'],
          'categorie'=>aaCategorie112($x['cats']),'status'=>null,'at'=>$x['at'],'data'=>['categorieen'=>array_slice($x['cats'],0,12),'plaatsen'=>[$plaats]]],$now);
        if(!$best&&!$eerste&&$now-$x['at']<3*3600000)$nieuw[]=$id;
        if(!$best&&$eerste)aaSchrijf("UPDATE nieuws SET notified = '1' WHERE id = ?",[$id]);
      }
      $status[$d['slug']]=['at'=>$now,'n'=>count($items)];
    }catch(Throwable $e){ $status[$d['slug']]=['at'=>$now,'fout'=>mb_substr($e->getMessage(),0,80)]; }
  }
  if(!AaProef::$aan)aaKvSet('n112_status',aaJsJson($status));
  return array_values(array_unique($nieuw));
}
/** nieuws.js kiesStockFotos */
function aaKiesStockFotos(int $now): int {
  if(AaProef::$aan)return 0;
  $rs=aaDb()->query("SELECT n.id, n.soort, n.categorie, n.at FROM nieuws n LEFT JOIN nieuws_stockfoto s ON s.id = n.id WHERE n.soort IN ('politie', 'regionaal') AND s.id IS NULL ORDER BY n.at ASC LIMIT 150")->fetchAll();
  if(!$rs)return 0;
  $q=aaDb()->prepare('SELECT s.foto FROM nieuws_stockfoto s JOIN nieuws n ON n.id = s.id WHERE n.at <= ? ORDER BY n.at DESC LIMIT 1'); $q->execute([$rs[0]['at']]); $vorige=$q->fetchColumn()?:null;
  $ins=aaDb()->prepare('INSERT IGNORE INTO nieuws_stockfoto (id, foto, at) VALUES (?, ?, ?)');
  foreach($rs as $r){ $lijst=$r['soort']==='politie'?(AA_POLITIE_FOTOS[$r['categorie']]??AA_POLITIE_FOTOS['algemeen']):(AA_FOTOS_112[$r['categorie']]??AA_FOTOS_112['algemeen']);
    $foto=$lijst[0]; foreach($lijst as $f)if($f!==$vorige){$foto=$f;break;} $ins->execute([$r['id'],$foto,$now]); $vorige=$foto; }
  return count($rs);
}
function aaBronnenHousekeeping(): void {
  $d=aaDb(); $n=aaNow();
  $d->prepare("DELETE FROM waarschuwingen WHERE status = 'beeindigd' AND COALESCE(end_at, updated_at) < ?")->execute([$n-31*86400000]);
  $d->exec('DELETE FROM device_prefs WHERE token NOT IN (SELECT token FROM devices)');
  $d->exec('DELETE FROM waarschuwing_knmi WHERE id NOT IN (SELECT id FROM waarschuwingen)');
  $d->prepare("DELETE FROM kv WHERE `key` LIKE 'knmi_push:%' AND at < ?")->execute([$n-3*86400000]);
  $grens=$n-90*86400000; $q=$d->prepare('SELECT id FROM nieuws WHERE at < ? AND updated_at < ?'); $q->execute([$grens,$grens]);
  foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
    $d->prepare('DELETE FROM nieuws_fotos WHERE id IN (SELECT foto FROM nieuws_updates WHERE nieuws_id = ? AND foto IS NOT NULL)')->execute([$id]);
    $d->prepare('DELETE FROM nieuws_fotos WHERE id IN (SELECT foto FROM nieuws WHERE id = ? AND foto IS NOT NULL)')->execute([$id]);
    foreach(['nieuws_updates'=>'nieuws_id','nieuws_liveblog'=>'id','nieuws_stockfoto'=>'id','nieuws'=>'id'] as $t=>$c)$d->prepare("DELETE FROM $t WHERE $c = ?")->execute([$id]);
  }
}
/** Hands the pushes to the push module, only when sending is switched on (otherwise the Worker sends them). */
function aaBronnenPush(array $warnings, array $nieuws): void {
  if(setting('PUSH_ACTIEF')!=='1'||AaProef::$aan)return;
  if(is_file(__DIR__.'/push.php'))require_once __DIR__.'/push.php';
  if($warnings&&function_exists('aaPushWaarschuwingen'))aaPushWaarschuwingen($warnings);
  if($nieuws&&function_exists('aaPushNieuws'))foreach($nieuws as $id)aaPushNieuws($id,'p');
}

// ------------------------------------------------------------------ cron run
if(PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===realpath(__FILE__)){
  $lock=fopen(sys_get_temp_dir().'/aalerts-bronnen-'.substr(hash('sha256',__DIR__),0,12).'.lock','c');
  if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
  set_time_limit(170);
  $modus=AA_BRONNEN_MODUS;
  try{
    aaEnsureSchema(aaDb());
    $now=aaNow();
    if($modus==='proef'){
      // A test round every 10 minutes is enough to prove the intake; it reads every source once.
      $r=aaParse(aaKvGet('aa_bronnen_proef'),null); if(is_array($r)&&$now-(int)($r['at_ms']??0)<9*60000)exit(0);
      AaProef::$aan=true;
    }
    $toPush=[]; $nieuws=[];
    try{ $toPush=aaIngestWarnings(); }catch(Throwable $e){ error_log('[aa waarschuwingen] '.$e->getMessage()); AaProef::$rapport['_fout'][]='waarschuwingen: '.$e->getMessage(); }
    if($modus!=='proef'&&aaKvGet('nieuws_weer_v4')===null){ try{ aaSyncWaarschuwingen($now,true); aaKvSet('nieuws_weer_v4','1'); }catch(Throwable){} }
    try{ aaSyncWaarschuwingen($now); }catch(Throwable $e){ AaProef::$rapport['_fout'][]='nieuws-sync: '.$e->getMessage(); }
    try{ aaKiesStockFotos($now); }catch(Throwable $e){ AaProef::$rapport['_fout'][]='foto: '.$e->getMessage(); }
    try{ foreach(aaSyncPolitie($now) as $id){ aaSchrijf("UPDATE nieuws SET notified = '1' WHERE id = ?",[$id]); $nieuws[]=$id; } }
    catch(Throwable $e){ if($modus!=='proef')aaKvSet('nieuws_politie_status',aaJsJson(['ok'=>false,'at'=>$now,'fout'=>$e->getMessage()])); AaProef::$rapport['_fout'][]='politie: '.$e->getMessage(); }
    try{ foreach(aaSync112($now) as $id){ aaSchrijf("UPDATE nieuws SET notified = '1' WHERE id = ?",[$id]); $nieuws[]=$id; } }catch(Throwable $e){ AaProef::$rapport['_fout'][]='112: '.$e->getMessage(); }
    aaBronnenPush($toPush,$nieuws);
    if($modus!=='proef'&&(int)gmdate('i')===7){ try{ aaBronnenHousekeeping(); }catch(Throwable $e){ error_log('[aa bronnen housekeeping] '.$e->getMessage()); } }
    $rapport=['modus'=>$modus,'at'=>aaIso(aaNow()),'at_ms'=>aaNow(),'duur_s'=>round((aaNow()-$now)/1000,1),'pushes_klaar'=>count($toPush),'nieuws_nieuw'=>count($nieuws)]+AaProef::$rapport;
    aaKvSet($modus==='proef'?'aa_bronnen_proef':'aa_bronnen',aaJsJson($rapport));
    echo 'bronnen '.$modus.' '.json_encode($rapport['_status']??[])."\n";
  }catch(Throwable $e){ error_log('[aa bronnen] '.$e->getMessage()); fwrite(STDERR,'bronnen '.get_class($e)."\n"); exit(1); }
}
