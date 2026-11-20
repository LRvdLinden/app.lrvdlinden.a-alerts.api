<?php
declare(strict_types=1);
/**
 * Warnings (NL-Alert, Burgernet, KNMI, AMBER/Vermist Kind, VNOG): API shape and lists (alerts.js toAPI, listWarnings,
 * getWarning, knmiOpgeheven, linkedMeldingen, vnogMeldingen). The intake of each source follows in its own module.
 */
require_once __DIR__.'/meldingen.php';

const AA_DAY=86400000;
const AA_KIND_BRONNEN=['amber','vermistkind'];
const AA_KNMI_NL=['fog'=>'Mist','dense fog'=>'Dichte mist','wind'=>'Wind','strong wind'=>'Zware windstoten','wind gusts'=>'Windstoten',
  'thunderstorm'=>'Onweer','thunderstorms'=>'Onweer','lightning'=>'Onweer','rain'=>'Regen','heavy rain'=>'Zware regen',
  'snow'=>'Sneeuw','snow/ice'=>'Sneeuw en gladheid','ice'=>'Gladheid','slippery roads'=>'Gladheid','glaze'=>'IJzel',
  'freezing rain'=>'IJzel','heat'=>'Hitte','high temperature'=>'Hitte','low temperature'=>'Kou','cold'=>'Kou',
  'extreme cold'=>'Strenge vorst','hail'=>'Hagel','tornado'=>'Tornado','coastal event'=>'Kustwaarschuwing'];
const AA_VNOG_VOOR_MS=4*3600000, AA_VNOG_NA_MS=3600000;
const AA_PUBLIC_SELECT="SELECT w.*, wo.ended_at AS ov_ended, wk.data AS knmi_data FROM waarschuwingen w
  LEFT JOIN waarschuwing_overrides wo ON wo.id = w.id LEFT JOIN waarschuwing_knmi wk ON wk.id = w.id WHERE COALESCE(wo.hidden, 0) = 0";

function aaKnmiNaam(mixed $p): mixed { return AA_KNMI_NL[mb_strtolower(trim((string)($p??'')))]??$p; }
function aaJsonOrNull(?string $s): mixed { if($s===null||$s==='')return null; $d=json_decode($s,true); return $d; }
function aaWithOverride(?array $r): ?array {
  if($r&&!empty($r['ov_ended'])&&$r['status']!=='beeindigd'){ $r['status']='beeindigd'; $r['end_at']=min((int)($r['end_at']?:$r['ov_ended']),(int)$r['ov_ended']); }
  return $r;
}
function aaWarningAPI(?array $row, bool $withArea=true, bool $metLandelijk=false): ?array {
  if(!$row)return null;
  $knmi=$row['bron']==='knmi';
  $o=['id'=>$row['id'],'bron'=>$row['bron'],'titel'=>$knmi?aaKnmiNaam($row['titel']):$row['titel'],'tekst'=>$row['tekst'],'tekst_en'=>$row['tekst_en'],'plaats'=>$row['plaats'],
    'niveau'=>$row['niveau'],'soort'=>$knmi?aaKnmiNaam($row['soort']):$row['soort'],'status'=>$row['status'],
    'start'=>aaIso((int)$row['start_at']),'einde'=>$row['end_at']?aaIso((int)$row['end_at']):null,
    'lat'=>aaNum($row['lat']),'lon'=>aaNum($row['lon']),'link'=>$row['link']];
  if($withArea)$o['gebied']=aaJsonOrNull($row['gebied']);
  $o['eerst_gezien']=aaIso((int)$row['first_seen']); $o['bijgewerkt']=aaIso((int)$row['updated_at']);
  $k=aaJsonOrNull($row['knmi_data']??null); if(!is_array($k))$k=[];
  $lijst=fn($x)=>is_array($x)&&$x?$x:null;
  if($knmi){
    $tv=is_array($k['tijdvakken']??null)?$k['tijdvakken']:null;
    $o+=['verwachting'=>$k['verwachting']??null,'advies'=>$k['advies']??null,'kop'=>$k['kop']??null,'omschrijving'=>$k['omschrijving']??null,'details'=>$k['details']??null,'uitgifte'=>$k['uitgifte']??null,
      'tijdvakken'=>$tv&&count($tv)>1?$tv:null,'alleTijdvakken'=>$tv&&count($tv)?$tv:null];
    if($metLandelijk)$o['landelijk']=is_array($k['landelijk']??null)?$k['landelijk']:null;
  }
  if(in_array($row['bron'],AA_KIND_BRONNEN,true))$o+=['berichten'=>$lijst($k['berichten']??null),'foto'=>($k['foto']??null)?:null,'foto_klein'=>($k['foto_klein']??null)?:null,'straal_km'=>$k['cirkel']['km']??null];
  if($row['bron']==='vnog')$o+=['berichten'=>$lijst($k['berichten']??null),'grip'=>$k['grip']??null,'grip_hoogste'=>$k['grip_hoogste']??null,'straat'=>$k['straat']??null,
    'incident'=>$k['incident']??null,'plek'=>$k['plek']??null,'afloop'=>$row['status']==='beeindigd'?'Het incident is afgesloten.':null];
  if($row['bron']==='burgernet')$o+=['startbericht'=>$k['startbericht']??null,'afloop'=>$k['afloop']??null,'berichten'=>$lijst($k['berichten']??null),'straat'=>$k['straat']??null];
  return $o;
}
function aaListWarnings(mixed $days=null, bool $kind=false, bool $vnog=false): array {
  $n=is_numeric($days)?(float)$days:0; $since=aaNow()-(int)(min($n?:7,30)*AA_DAY);
  $q=aaDb()->prepare(AA_PUBLIC_SELECT." AND (COALESCE(w.end_at, w.start_at) >= ? OR w.status != 'beeindigd') ORDER BY w.start_at DESC, w.volgorde DESC LIMIT 200"); $q->execute([$since]);
  $out=[]; foreach($q->fetchAll() as $r){ if(!$kind&&in_array($r['bron'],AA_KIND_BRONNEN,true))continue; if(!$vnog&&$r['bron']==='vnog')continue; $out[]=aaWarningAPI(aaWithOverride($r)); }
  return $out;
}
function aaKnmiOpgeheven(string $id): ?array {
  if(!preg_match('/^knmi:[^:]+:(.+)$/',$id,$m))return null; $start=aaMs($m[1]); if($start===null)return null;
  return ['id'=>$id,'bron'=>'knmi','titel'=>'Geen weerwaarschuwing','tekst'=>'Deze weerwaarschuwing is opgeheven. Er geldt nu code groen.','tekst_en'=>null,
    'plaats'=>null,'niveau'=>'green','soort'=>null,'status'=>'beeindigd','start'=>aaIso($start),'einde'=>null,'lat'=>null,'lon'=>null,
    'link'=>'https://www.knmi.nl/nederland-nu/weer/waarschuwingen','gebied'=>null,'eerst_gezien'=>aaIso($start),'bijgewerkt'=>aaIso(aaNow()),'opgeheven'=>true,
    'verwachting'=>null,'advies'=>null,'kop'=>null,'omschrijving'=>null,'details'=>null,'uitgifte'=>null];
}
function aaGetWarning(string $id): ?array {
  $q=aaDb()->prepare(AA_PUBLIC_SELECT.' AND w.id = ?'); $q->execute([$id]); $r=$q->fetch();
  return $r?aaWarningAPI(aaWithOverride($r),true,true):aaKnmiOpgeheven($id);
}
// vnog.js straatKey / inStraat
function aaStraatKey(mixed $s): string {
  $s=Normalizer::normalize(mb_strtolower((string)($s??'')),Normalizer::FORM_D); $s=(string)preg_replace('/[\x{0300}-\x{036f}]/u','',(string)$s);
  $s=(string)preg_replace('/[^a-z0-9]+/',' ',$s);
  return implode(' ',array_filter(explode(' ',$s),fn($w)=>$w!==''&&!in_array($w,['van','de','der','den','het','t','te','ter','ten','op','aan'],true)));
}
function aaInStraat(mixed $locatie, mixed $straat): bool { $a=aaStraatKey($locatie); $b=aaStraatKey($straat); return strlen($b)>=5&&str_contains(" $a "," $b "); }
function aaLinkedMeldingen(array $w): array {
  $db=aaDb();
  if($w['bron']==='vnog'){
    $rows=[];
    if(!empty($w['incident'])){ $q=$db->prepare(AA_SELECT.' WHERE COALESCE(o.hidden, 0) = 0 AND (m.incident = ? OR m.guid = ?) ORDER BY m.datum DESC LIMIT 40'); $q->execute([$w['incident'],$w['incident']]); $rows=$q->fetchAll(); }
    elseif(!empty($w['straat'])){ $s=aaMs($w['start']); $q=$db->prepare(AA_SELECT.' WHERE COALESCE(o.hidden, 0) = 0 AND m.datum BETWEEN ? AND ? ORDER BY m.datum DESC LIMIT 400'); $q->execute([$s-AA_VNOG_VOOR_MS,$s+AA_VNOG_NA_MS]);
      $rows=array_slice(array_values(array_filter($q->fetchAll(),fn($m)=>aaInStraat($m['locatie'],$w['straat']))),0,40); }
    $rel=!empty($w['incident'])?'zelfde-incident':'zelfde-straat-rond-tijdstip';
    return array_map(fn($r)=>aaMeldingAPI($r)+['relatie'=>$rel],$rows);
  }
  $start=aaMs($w['start'])-2*3600000; $end=($w['einde']?aaMs($w['einde']):aaMs($w['start'])+6*3600000)+3600000;
  $q=$db->prepare(AA_SELECT.' WHERE COALESCE(o.hidden, 0) = 0 AND m.datum BETWEEN ? AND ? ORDER BY m.datum DESC LIMIT 400'); $q->execute([$start,min($end,aaNow()+AA_DAY)]);
  $list=[];
  foreach($q->fetchAll() as $r){ $m=aaMeldingAPI($r);
    if($w['bron']==='burgernet'){ if(!($w['plaats']&&aaMatchesPlaatsen([$w['plaats']],$m)&&$m['dienst']==='politie'))continue; }
    else{ if(!$w['gebied']||$m['lat']===null||$m['lon']===null)continue; if(trim((string)($m['locatie']??''))==='')continue;
      $in=false; foreach($w['gebied'] as $ring)if(aaInRing($ring,(float)$m['lat'],(float)$m['lon'])){$in=true;break;} if(!$in)continue; }
    $list[]=$m; if(count($list)>=30)break; }
  return array_map(fn($m)=>$m+['relatie'=>preg_match('/grip/i',$m['title'].' '.($m['grip']??''))?'grip-in-gebied':'in-gebied-rond-tijdstip'],$list);
}
