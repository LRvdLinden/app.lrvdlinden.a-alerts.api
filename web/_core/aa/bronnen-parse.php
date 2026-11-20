<?php
declare(strict_types=1);
/**
 * Reading the warning and news sources, without I/O: alerts.js (NL-Alert, Burgernet, KNMI), kind.js (AMBER Alert,
 * Vermist Kind Alert), vnog.js (VNOG) and nieuws.js (politie.nl, 112 Nieuws, weather articles, liveblogs), ported
 * one to one so that ids, texts and fields are the same as those of the Worker.
 */
require_once __DIR__.'/base.php';

// ------------------------------------------------------------------ JS helpers
/** JSON.stringify: whole numbers without ".0", no escaped slashes, unicode or line separators. */
function aaJsNum(mixed $v): mixed {
  if(is_float($v))return (is_finite($v)&&floor($v)==$v&&abs($v)<9007199254740992)?(int)$v:$v;
  if(is_array($v)){foreach($v as $k=>$x)$v[$k]=aaJsNum($x);return $v;}
  return $v;
}
function aaJsJson(mixed $v): string {
  return json_encode(aaJsNum($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_INVALID_UTF8_SUBSTITUTE);
}
/** JS encodeURIComponent */
function aaEncodeURIComponent(string $s): string { return strtr(rawurlencode($s),['%21'=>'!','%2A'=>'*','%27'=>"'",'%28'=>'(','%29'=>')']); }
/** JS String.length (UTF-16 code units). */
function aaJsLen(string $s): int { return intdiv(strlen((string)mb_convert_encoding($s,'UTF-16LE','UTF-8')),2); }
/** JS str.slice(0, n) in UTF-16 code units (n >= 0). */
function aaJsSlice(string $s, int $n): string {
  $u=(string)mb_convert_encoding($s,'UTF-16LE','UTF-8'); if(strlen($u)<=2*$n)return $s;
  return (string)mb_convert_encoding(substr($u,0,2*$n),'UTF-8','UTF-16LE');
}
/** Dutch local time (d, m, y, h, mi, s) → epoch ms (Europe/Amsterdam). */
function aaAmsMs(int $y, int $m, int $d, int $h, int $mi, int $s=0): int {
  $dt=(new DateTimeImmutable('now',new DateTimeZone('Europe/Amsterdam')))->setDate($y,$m,$d)->setTime($h,$mi,$s);
  return $dt->getTimestamp()*1000;
}
/** alerts.js amsterdamTime: "06/11/2026", "13:15" → ms, or null. */
function aaAmsterdamTime(mixed $dmy, mixed $hm='12:00'): ?int {
  $p=array_map(fn($x)=>is_numeric($x)?(int)$x:0,explode('/',(string)($dmy??'')));
  [$d,$m,$y]=array_pad($p,3,0);
  $q=explode(':',(string)(($hm===null||$hm==='')?'12:00':$hm)); $h=is_numeric($q[0]??'')?(int)$q[0]:0; $mi=is_numeric($q[1]??'')?(int)$q[1]:0;
  if(!$y||!$m||!$d)return null;
  return aaAmsMs($y,$m,$d,$h,$mi);
}
/** Date.parse for the formats of the sources (ISO, RFC 2822); null for NaN. */
function aaDateParse(mixed $s): ?int { if($s===null||$s==='')return null; if(is_int($s)||is_float($s))return null; return aaMs((string)$s); }

// ------------------------------------------------------------------ NL-Alert
function aaParseNlAlertRing(mixed $text): array {
  $out=[];
  foreach(preg_split('/\s+/',trim((string)($text??''))) as $pair){
    $p=explode(',',$pair); if(count($p)!==2)continue;
    // JS Number(): an empty part is 0.
    $n=array_map(fn($x)=>trim($x)===''?0.0:(is_numeric(trim($x))?(float)trim($x):null),$p);
    if($n[0]===null||$n[1]===null)continue;
    $out[]=[$n[1],$n[0]];
  }
  return $out;
}
function aaSimplify(array $rings, int $n=160): array {
  return array_map(function($ring)use($n){ $c=count($ring); if($c<=$n)return $ring; $step=$c/$n; $o=[]; for($i=0;$i<$n;$i++)$o[]=$ring[(int)floor($i*$step)]; $o[]=$ring[0]; return $o; },$rings);
}
function aaRondAf(array $rings): array { return array_map(fn($r)=>array_map(fn($p)=>[round($p[0]*1000)/1000,round($p[1]*1000)/1000],$r),$rings); }
function aaCentroid(array $rings): array {
  $pts=array_merge(...array_values($rings)?:[[]]); if(!$pts)return [null,null];
  $a=0;$b=0; foreach($pts as $p){$a+=$p[1];$b+=$p[0];} return [$a/count($pts),$b/count($pts)];
}
function aaNlPart(mixed $m): string { return trim((string)preg_replace('/\s+/u',' ',explode('***',(string)($m??''))[0])); }
function aaEnPart(mixed $m): ?string { $p=explode('***',(string)($m??'')); return count($p)>1?trim((string)preg_replace('/\s+/u',' ',implode(' ',array_slice($p,1)))):null; }
function aaFromNlAlert(mixed $a, int $now): ?array {
  if(!is_array($a)||empty($a['id']))return null;
  $areas=is_array($a['area']??null)&&array_is_list($a['area'])?$a['area']:[$a['area']??null];
  $rings=array_values(array_filter(array_map(fn($x)=>aaParseNlAlertRing(is_scalar($x)||$x===null?$x:''),$areas),fn($r)=>count($r)>=3));
  if(!$rings||!aaPolygonOverlaps($rings))return null;
  $start=aaDateParse($a['start_at']??null); $stop=aaDateParse($a['stop_at']??null);
  $nl=aaNlPart($a['message']??null); [$lat,$lon]=aaCentroid($rings);
  $first=preg_split('/(?<=[.!?])\s/u',$nl)[0]; if($first==='')$first=$nl;
  return ['id'=>'nlalert:'.$a['id'],'bron'=>'nlalert','titel'=>aaJsSlice((string)preg_replace('/[.!]$/u','',$first),140),'tekst'=>$nl,'tekst_en'=>aaEnPart($a['message']??null),
    'plaats'=>null,'niveau'=>null,'soort'=>aaOr($a['type']??null,'alert'),'start_at'=>$start??$now,'end_at'=>$stop,
    'status'=>$stop!==null&&$stop<$now?'beeindigd':'actief','gebied'=>$rings,'lat'=>$lat,'lon'=>$lon,
    'link'=>'https://actueel.nl-alert.nl/alert/'.aaEncodeURIComponent((string)$a['id']).'/'];
}

// ------------------------------------------------------------------ Burgernet
const AA_BURGERNET_SOORT=[['/vermis|vermist/i','Vermissing'],['/kenteken|voertuig|auto|scooter|motor/i','Voertuig'],
  ['/inbra|overval|diefstal|beroving|steek|verdachte|gevlucht|vluchtte/i','Verdachte']];
function aaBurgernetSoort(string $r): string { foreach(AA_BURGERNET_SOORT as [$re,$s])if(preg_match($re,$r))return $s; return 'Zoekactie'; }
function aaBurgernetPlaats(mixed $report, mixed $location): ?array {
  $text=' '.mb_strtolower((string)($report??'')).' ';
  $ps=array_values(array_filter(aaPlaatsen(),fn($p)=>$p['naam']!=='Apeldoorn'));
  usort($ps,fn($a,$b)=>mb_strlen($b['naam'])<=>mb_strlen($a['naam']));
  foreach($ps as $p)foreach(array_merge([$p['naam']],$p['aliassen']??[]) as $n){
    $pat=preg_replace('/[-\s]/u','[-\s]',mb_strtolower($n));
    if(@preg_match('~[^a-z]'.$pat.'[^a-z]~',$text))return $p;
  }
  if(aaPlaatsKey($location)==='apeldoorn')foreach(aaPlaatsen() as $p)if($p['naam']==='Apeldoorn')return $p;
  return null;
}
/** alerts.js ontdubbel */
function aaOntdubbel(mixed $tekst): string {
  $t=trim((string)preg_replace('/[ \t]+/',' ',(string)($tekst??'')));
  $plat=fn($x)=>trim((string)preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower($x)));
  preg_match_all('/[.!?:](?=\s)/',$t,$mm,PREG_OFFSET_CAPTURE);
  foreach(array_reverse($mm[0]) as [$c,$off]){
    $i=$off+1; $kop=substr($t,0,$i); $rest=trim(substr($t,$i)); $k=$plat($kop);
    if(aaJsLen($k)>=15&&str_starts_with($plat($rest),$k))return $rest;
  }
  return $t;
}
function aaBurgernetTitel(mixed $report): string {
  $clean=(string)preg_replace('/^\d{4}\s?[A-Z]{2}\s+/','',(string)($report??''));
  $z=preg_split('/(?<=[.:!?])\s/u',$clean); $t=($z[0]??'')!==''?$z[0]:$clean;
  if(aaJsLen($t)<25&&($z[1]??'')!=='')$t.=' '.$z[1];
  return aaJsSlice(trim((string)preg_replace('/\s*(Tips\?|Bel nu 112)[\s\S]*$/iu','',$t)),140);
}
const AA_STRAAT_RE="/(?<![\\p{L}])((?:(?:Van|De|Den|Het|Ter|Ten)\\s)*[A-Z][\\p{L}'.-]+(?:straat|weg|laan|plein|singel|dreef|kade|dijk|allee|hof|pad|steeg|gracht|markt|ring|baan|park|veld|erf))(?![\\p{L}])|(?<![\\p{L}])((?:Laan|Weg|Plein)\\s(?:van|der|de|den|het|\\s)*[A-Z][\\p{L}'-]+)/u";
function aaBurgernetStraat(mixed $report): ?string {
  preg_match_all(AA_STRAAT_RE,(string)($report??''),$mm,PREG_SET_ORDER);
  foreach($mm as $m){ $s=trim(($m[1]??'')!==''?$m[1]:($m[2]??'')); if(aaJsLen($s)>=5&&!preg_match('/^(Burgerweg|Snelweg|Rijksweg|Onderweg|Uitweg|Omweg|Zijweg)$/',$s))return $s; }
  return null;
}
function aaFromBurgernet(mixed $a, int $now): ?array {
  if(!is_array($a))return null;
  $location=trim((string)($a['location']??'')); $key=aaPlaatsKey($location);
  if($key!=='apeldoorn'&&$key!=='voorst')return null;
  $plaats=aaBurgernetPlaats($a['report']??null,$location);
  if(!$plaats||($key==='voorst'&&$plaats['naam']!=='Teuge'))return null;
  $link=(string)($a['link']??''); $idm=preg_match('~/burgernet/([^/?#]+)~',$link,$im)?$im[1]:null;
  $date=$a['date']??null;
  $ds=!array_key_exists('date',$a)?'undefined':($date===null?'null':(is_bool($date)?($date?'true':'false'):(is_scalar($date)?(string)$date:'')));
  $id=$idm??($key.'-'.$ds.'-'.aaJsSlice((string)($a['report']??''),40));
  $report=aaOntdubbel(str_replace("\r",'',(string)($a['report']??'')));
  $t=preg_match('/(\d{1,2}):(\d{2})/',(string)($a['time']??''),$tm)?$tm:null;
  $start=$t?(aaAmsterdamTime($date,$t[1].':'.$t[2])??$now):$now;
  return ['id'=>'burgernet:'.$id,'bron'=>'burgernet','startOnbekend'=>!$t,'titel'=>aaBurgernetTitel($report),'tekst'=>$report,'tekst_en'=>null,
    'plaats'=>$plaats['naam'],'niveau'=>null,'soort'=>aaBurgernetSoort($report),'start_at'=>$start,'end_at'=>null,
    'status'=>($a['isClosed']??null)===true?'beeindigd':'actief','gebied'=>null,'lat'=>$plaats['lat'],'lon'=>$plaats['lon'],
    'link'=>$idm!==null?'https://www.burgernet.nl/burgernet/'.$idm:($link!==''?$link:null)];
}
function aaBnDecode(mixed $s): string {
  $s=(string)preg_replace('/<[^>]+>/',' ',(string)($s??''));
  $s=str_replace(['&nbsp;','&amp;','&quot;'],[' ','&','"'],$s); $s=(string)preg_replace('/&#0?39;|&apos;/',"'",$s);
  $s=str_replace(['&lt;','&gt;'],['<','>'],$s);
  $s=(string)preg_replace('/[ \t]+/',' ',$s); $s=(string)preg_replace('/\s*\n\s*/u',"\n",$s);
  return trim($s);
}
/** JS str.slice(0, i) where i may be -1 (then the last character is dropped). */
function aaSliceTo(string $s, int $i): string { return $i<0?(string)mb_substr($s,0,max(0,mb_strlen($s)+$i)):substr($s,0,$i); }
function aaBurgernetSiteIds(mixed $html): array { preg_match_all('~href="/burgernet/(\d{4,})"~',(string)$html,$m); return array_values(array_unique($m[1])); }
function aaBurgernetBerichten(mixed $html): array {
  $delen=array_slice(preg_split('/<h5[^>]*action-detail-block__title[^>]*>/i',(string)($html??'')),1); $out=[];
  foreach($delen as $deel){
    $p=strpos($deel,'</h5>'); $label=aaBnDecode(aaSliceTo($deel,$p===false?-1:$p));
    $tekst=aaOntdubbel(aaBnDecode(preg_match('/<h3[^>]*>([\s\S]*?)<\/h3>/i',$deel,$h3)?$h3[1]:''));
    if($label===''||$tekst==='')continue;
    $q=preg_match('/<h3/i',$deel,$x,PREG_OFFSET_CAPTURE)?$x[0][1]:-1;
    preg_match_all('/action-message__heading-item[^>]*>([^<]*)</i',aaSliceTo($deel,$q),$it); $items=array_map('aaBnDecode',$it[1]);
    $datum=null;$tijd=null; foreach($items as $i)if($datum===null&&preg_match('/^\d{2}\/\d{2}\/\d{4}$/',$i))$datum=$i; foreach($items as $i)if($tijd===null&&preg_match('/^\d{1,2}:\d{2}$/',$i))$tijd=$i;
    $plaats=null; foreach($items as $i)if($i!==$datum&&$i!==$tijd){$plaats=$i;break;}
    $soort=preg_match('/start/i',$label)?'start':(preg_match('/afloop|einde|gesloten/i',$label)?'afloop':'update');
    $at=$datum?aaAmsterdamTime($datum,$tijd?:'00:00'):null;
    $pl=($plaats!==null&&$plaats!=='')?preg_replace_callback('/(^|[\s-])(\p{L})/u',fn($m)=>$m[1].mb_strtoupper($m[2]),mb_strtolower($plaats)):null;
    $out[]=['soort'=>$soort,'label'=>$label,'plaats'=>$pl,'at'=>$at?aaIso($at):null,'tekst'=>$tekst];
  }
  $rang=['start'=>0,'update'=>1,'afloop'=>2];
  $out=array_reverse($out);
  usort($out,fn($a,$b)=>($a['at']&&$b['at']&&$a['at']!==$b['at'])?(aaMs($a['at'])<=>aaMs($b['at'])):($rang[$a['soort']]<=>$rang[$b['soort']]));
  return $out;
}
function aaFromBurgernetSite(string $id, mixed $html, int $now): ?array {
  $h=(string)($html??'');
  $spec=function($label)use($h){ return preg_match('~'.$label.'</p>\s*<p[^>]*>([^<]*)</p>~i',$h,$m)?aaBnDecode($m[1]):''; };
  $message=function($label)use($h){ return preg_match('~>'.$label.'</h5>[\s\S]*?<h3[^>]*>([\s\S]*?)</h3>~i',$h,$m)?aaOntdubbel(aaBnDecode($m[1])):''; };
  $location=$spec('Locatie'); $key=aaPlaatsKey($location);
  if($key!=='apeldoorn'&&$key!=='voorst')return null;
  $report=$message('Startbericht'); if($report==='')$report=$message('Afloopbericht');
  $plaats=aaBurgernetPlaats($report,$location);
  if(!$plaats||($key==='voorst'&&$plaats['naam']!=='Teuge'))return null;
  $s=preg_split('/\s+/u',$spec('Start actie')); $sd=$s[0]??''; $st=$s[1]??null;
  $e=preg_split('/\s+/u',$spec('Einde actie')); $ed=$e[0]??''; $et=$e[1]??null;
  $status=$spec('Status van deze actie');
  $h1=preg_match('/<h1[^>]*>([\s\S]*?)<\/h1>/',$h,$hm)?$hm[1]:'';
  $ended=preg_match('/afgerond|be[eë]indigd|gesloten/iu',$status)||preg_match('/Afgeronde actie/i',$h1);
  $lat=$plaats['lat'];$lon=$plaats['lon'];
  if(preg_match('/data-center="(-?\d+\.\d+),(-?\d+\.\d+)"/',$h,$c)){$lat=(float)$c[1];$lon=(float)$c[2];}
  // The Worker compares the distance with two objects (always NaN): only a missing number falls back to the town.
  $afloop=$message('Afloopbericht'); $berichten=aaBurgernetBerichten($h);
  return ['id'=>'burgernet:'.$id,'bron'=>'burgernet','titel'=>aaBurgernetTitel($report),
    'tekst'=>$afloop!==''&&$ended?"$report\n\n$afloop":$report,'tekst_en'=>null,'startbericht'=>$report,'afloop'=>$afloop!==''?$afloop:null,
    'berichten'=>$berichten?:null,'plaats'=>$plaats['naam'],'niveau'=>null,'soort'=>aaBurgernetSoort($report),
    'start_at'=>aaAmsterdamTime($sd,$st??'12:00')??$now,'end_at'=>$ended?(aaAmsterdamTime($ed,$et??'12:00')??$now):null,
    'status'=>$ended?'beeindigd':'actief','gebied'=>null,'lat'=>$lat,'lon'=>$lon,'link'=>'https://www.burgernet.nl/burgernet/'.$id];
}

// ------------------------------------------------------------------ KNMI
function aaTxt(mixed $v): string {
  if($v===null)return '';
  if(is_array($v)){ $x=aaOr($v['nl']??null)??aaOr($v['en']??null)??aaOr(array_values($v)[0]??null); return $x===null?'':(is_scalar($x)?(string)$x:''); }
  if(is_bool($v))return $v?'true':'false';
  return trim((string)$v);
}
function aaNormLevel(mixed $v): string {
  $s=mb_strtolower((string)($v??''));
  if(in_array($s,['red','rood','extreme'],true))return 'red';
  if(in_array($s,['orange','oranje','amber','severe'],true))return 'orange';
  if(in_array($s,['yellow','geel','moderate'],true))return 'yellow';
  return 'green';
}
function aaLevelRank(mixed $l): int { $i=array_search($l,['green','yellow','orange','red'],true); return $i===false?-1:$i; }
function aaGeomRings(mixed $g): array {
  if(!is_array($g))return [];
  if(($g['type']??'')==='Polygon')return [$g['coordinates'][0]];
  if(($g['type']??'')==='MultiPolygon')return array_map(fn($p)=>$p[0],$g['coordinates']);
  return [];
}
function aaOntHTML(mixed $s): string {
  $s=(string)preg_replace('/<br\s*\/?>/i',"\n",(string)($s??'')); $s=(string)preg_replace('/<\/p>\s*<p[^>]*>/i',"\n",$s); $s=(string)preg_replace('/<[^>]+>/','',$s);
  $s=(string)preg_replace('/&#39;|&apos;/',"'",$s); $s=str_replace(['&quot;','&nbsp;','&lt;','&gt;','&amp;'],['"',' ','<','>','&'],$s);
  return implode("\n",array_values(array_filter(array_map(fn($r)=>trim((string)preg_replace('/\s+/u',' ',$r)),explode("\n",$s)),fn($r)=>$r!=='')));
}
function aaKnmiPagina(mixed $html): array {
  $uit=[];
  foreach(array_slice(preg_split('/<section[^>]+id="waarschuwing-/i',(string)($html??'')),1) as $s){
    if(!preg_match('/^([^"]+)"/',$s,$m))continue;
    $pak=function($re)use($s){ if(!preg_match($re,$s,$x))return null; $v=aaOntHTML($x[1]); return $v!==''?$v:null; };
    $uit[$m[1]]=['kop'=>$pak('/class="weather-warnings-details__title"[^>]*>([\s\S]*?)<\/h2>/i'),'omschrijving'=>$pak('/class="weather-warnings-details__body"[^>]*>([\s\S]*?)<\/div>/i'),
      'details'=>$pak('/<details class="weather-warnings-more">[\s\S]*?<\/summary>([\s\S]*?)<\/details>/i'),'uitgifte'=>$pak('/class="weather-warnings-details__issued"[^>]*>\s*(?:Uitgifte:)?([\s\S]*?)<\/p>/i')];
  }
  return $uit;
}
function aaFromKnmi(mixed $data, int $now, array $pagina=[]): array {
  $events=is_array($data['warning_events']??null)?$data['warning_events']:[]; $out=[];
  foreach($events as $ei=>$ev){
    $evid=array_key_exists('id',$ev)&&$ev['id']!==null?$ev['id']:$ei;
    $phen=aaTxt($ev['label']??null); if($phen==='')$phen=(string)aaKnmiNaam(aaTxt($ev['phenomenon']??null));
    $site=$pagina[(string)(is_bool($evid)?($evid?'true':'false'):$evid)]??[];
    foreach($ev['periods']??[] as $period){
      $start=aaDateParse($period['starts_at']??null); $end=aaDateParse($period['ends_at']??null);
      if($start===null||$end===null)continue;
      $parts=[];
      foreach($period['parts']??[] as $p){ $q=['level'=>aaNormLevel($p['level']??null),'area'=>aaTxt($p['area_description']??null),'rings'=>aaGeomRings($p['geometry']??null),'impact'=>aaTxt($p['impact']??null),'instruction'=>aaTxt($p['instruction']??null)];
        if($q['level']!=='green'&&($q['rings']?aaPolygonOverlaps($q['rings']):preg_match('/gelderland|heel nederland|alle provincies/i',$q['area'])))$parts[]=$q; }
      if(!$parts)continue;
      $niveau='green'; foreach($parts as $p)if(aaLevelRank($p['level'])>aaLevelRank($niveau))$niveau=$p['level'];
      $rings=array_merge(...array_map(fn($p)=>$p['rings'],$parts));
      [$lat,$lon]=$rings?aaCentroid($rings):[52.2112,5.9699];
      $desc=aaTxt($period['description']??null); if($desc==='')$desc=aaTxt($ev['description']??null); if($desc==='')$desc=aaTxt($period['text']??null); if($desc==='')$desc=aaTxt($ev['text']??null);
      $top=array_values(array_filter($parts,fn($p)=>$p['level']===$niveau));
      $uniek=function($k)use($top){ $v=implode("\n",array_values(array_unique(array_filter(array_map(fn($p)=>$p[$k],$top),fn($x)=>$x!=='')))); return $v!==''?$v:null; };
      $verw=$uniek('impact'); $adv=$uniek('instruction');
      $areas=implode(', ',array_values(array_unique(array_filter(array_map(fn($p)=>$p['area'],$parts),fn($x)=>$x!==''))));
      $out[]=['id'=>'knmi:'.(is_bool($evid)?($evid?'true':'false'):$evid).':'.($period['starts_at']),'bron'=>'knmi','titel'=>$phen!==''?$phen:'Weerwaarschuwing','verwachting'=>$verw,'advies'=>$adv,
        'kop'=>aaOr($site['kop']??null),'omschrijving'=>aaOr($site['omschrijving']??null),'details'=>aaOr($site['details']??null),'uitgifte'=>aaOr($site['uitgifte']??null),
        'tekst'=>aaOr($desc)??aaOr($site['omschrijving']??null)??aaOr($verw)??$areas,
        'tekst_en'=>null,'plaats'=>$areas!==''?$areas:null,'niveau'=>$niveau,'soort'=>$phen,'start_at'=>$start,'end_at'=>$end,
        'status'=>$end<$now?'beeindigd':($start>$now?'verwacht':'actief'),'gebied'=>$rings?aaSimplify($rings,400):null,'lat'=>$lat,'lon'=>$lon,
        'link'=>'https://www.knmi.nl/nederland-nu/weer/waarschuwingen','_ev'=>$evid];
    }
  }
  $col=new Collator('en');
  usort($out,fn($a,$b)=>$col->compare((string)$a['_ev'],(string)$b['_ev'])?:($a['start_at']<=>$b['start_at']));
  $joined=[];
  foreach($out as $w){
    $n=count($joined);
    if($n&&$joined[$n-1]['_ev']===$w['_ev']&&$joined[$n-1]['niveau']===$w['niveau']&&$w['start_at']<=$joined[$n-1]['end_at']+60000){
      $p=&$joined[$n-1]; $p['end_at']=max($p['end_at'],$w['end_at']);
      $p['status']=$p['end_at']<$now?'beeindigd':($p['start_at']>$now?'verwacht':'actief');
      if(aaOr($w['tekst'])!==null&&!str_contains((string)$p['tekst'],(string)$w['tekst']))$p['tekst']=aaOr($p['tekst'])??$w['tekst'];
      $p['verwachting']=aaOr($p['verwachting'])??$w['verwachting']; $p['advies']=aaOr($p['advies'])??$w['advies']; unset($p);
      continue;
    }
    $joined[]=$w;
  }
  $land=[];
  foreach($events as $ei=>$ev){
    $evid=array_key_exists('id',$ev)&&$ev['id']!==null?$ev['id']:$ei; $per=[];
    foreach($ev['periods']??[] as $period)foreach($period['parts']??[] as $p){
      $level=aaNormLevel($p['level']??null); $rings=aaGeomRings($p['geometry']??null);
      if($level==='green'||!$rings)continue;
      $key=aaTxt($p['area_description']??null); if($key==='')$key=json_encode(aaJsNum($rings[0][0]));
      if(!isset($per[$key])||aaLevelRank($level)>aaLevelRank($per[$key]['niveau']))$per[$key]=['niveau'=>$level,'gebied'=>aaRondAf(aaSimplify($rings,120))];
    }
    $land[(string)$evid]=array_values($per);
  }
  $slots=[];
  foreach($joined as $w)$slots[(string)$w['_ev']][]=['niveau'=>$w['niveau'],'start'=>aaIso($w['start_at']),'einde'=>aaIso($w['end_at'])];
  return array_map(function($w)use($slots,$land){ $e=(string)$w['_ev']; unset($w['_ev']); $w['tijdvakken']=$slots[$e]; $w['landelijk']=!empty($land[$e])?$land[$e]:null; return $w; },$joined);
}

// ------------------------------------------------------------------ AMBER Alert, Vermist Kind (kind.js)
const AA_KIND_API='https://services.burgernet.nl/landactiehost/api/v1/alerts';
const AA_KIND_READMORE='https://www.politie.nl/amberalert';
const AA_KIND_AFLOOP=['amber'=>'De AMBER Alert is beëindigd.','vermistkind'=>'De Vermist Kind Alert is beëindigd.'];
const AA_VERWIJDERD='Dit bericht is na afloop verwijderd.';
function aaKindTxt(mixed $v): string { return $v===null?'':trim((string)preg_replace('/\s+/u',' ',is_scalar($v)?(is_bool($v)?($v?'true':'false'):(string)$v):'')); }
function aaParseCircle(mixed $c, mixed $ck): ?array {
  if(preg_match('/(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)/',(string)($c??''),$m))return ['lat'=>(float)$m[1],'lon'=>(float)$m[2],'km'=>(float)$m[3]/1000];
  if(preg_match('/(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)/',(string)($ck??''),$m))return ['lat'=>(float)$m[1],'lon'=>(float)$m[2],'km'=>(float)$m[3]];
  return null;
}
function aaCircleRing(array $c, int $n=48): array {
  $r=[]; for($i=0;$i<=$n;$i++){ $a=($i/$n)*2*M_PI;
    $r[]=[(float)number_format($c['lon']+($c['km']/(111.32*cos(($c['lat']*M_PI)/180)))*cos($a),5,'.',''),(float)number_format($c['lat']+($c['km']/110.57)*sin($a),5,'.','')]; }
  return $r;
}
function aaRaaktWerkgebied(?array $c): bool {
  if(!$c)return false; if(aaInWerkgebied((float)$c['lat'],(float)$c['lon'],1))return true;
  foreach(aaPlaatsen() as $p)if(aaDistanceKm((float)$c['lat'],(float)$c['lon'],(float)$p['lat'],(float)$p['lon'])<=$c['km']+1)return true;
  return false;
}
function aaKindBericht(mixed $a, int $now): ?array {
  if(!is_array($a))return null;
  $level=(string)($a['AlertLevel']??''); $bron=$level==='10'?'amber':($level==='5'?'vermistkind':null);
  if(!$bron||empty($a['AlertId']))return null;
  $type=mb_strtolower(aaKindTxt($a['Type']??null)); $msg=is_array($a['Message']??null)?$a['Message']:[]; $area=is_array($a['Area']??null)?$a['Area']:[];
  $c=aaParseCircle($area['Circle']??null,$area['CircleKM']??null)
    ??(isset($a['Location']['Latitude'],$a['Location']['Longitude'])?['lat'=>(float)$a['Location']['Latitude'],'lon'=>(float)$a['Location']['Longitude'],'km'=>0]:null);
  $sent=is_numeric($a['Sent']??null)&&(float)$a['Sent']>1e9?(int)((float)$a['Sent']*((float)$a['Sent']<1e12?1000:1)):$now;
  return ['id'=>$bron.':'.aaKindTxt($a['AlertId']),'bron'=>$bron,'messageId'=>aaOr(aaKindTxt($a['MessageId']??null))??(string)$sent,
    'soort'=>$type==='cancel'?'afloop':($type==='update'?'update':'start'),'at'=>$sent,'titel'=>aaKindTxt($msg['Title']??null),'tekst'=>aaKindTxt($msg['Description']??null),
    'kop'=>aaKindTxt($msg['DescriptionExt']??null),'foto'=>aaOr($msg['Media']['Image']??null),'foto_klein'=>aaOr($msg['Media']['SmallImage']??null),
    'gebied'=>aaOr(aaKindTxt($area['Description']??null))??aaOr(aaKindTxt($a['Location']['Description']??null)),'cirkel'=>$c&&$c['km']>0?$c:null,
    'lat'=>$c?$c['lat']:null,'lon'=>$c?$c['lon']:null,'link'=>aaOr($msg['Readmore_URL']??null,AA_KIND_READMORE)];
}
function aaKindGroepeer(mixed $list, int $now): array {
  $per=[]; foreach(is_array($list)&&array_is_list($list)?$list:[] as $a){ $b=aaKindBericht($a,$now); if(!$b)continue; $per[$b['id']][]=$b; } return $per;
}
function aaKindWarning(array $berichten, ?array $prev, int $now): ?array {
  $first=$berichten[0]; $extra=$prev['extra']??[]; if(!is_array($extra))$extra=[];
  $lijst=is_array($extra['berichten']??null)?$extra['berichten']:[];
  $cirkel=aaOr($extra['cirkel']??null); $foto=aaOr($extra['foto']??null); $fotoKlein=aaOr($extra['foto_klein']??null); $titel=(string)($prev['row']['titel']??''); $kop=(string)($extra['kop']??'');
  $sorted=$berichten; usort($sorted,fn($x,$y)=>$x['at']<=>$y['at']);
  foreach($sorted as $b){
    $has=false; foreach($lijst as $x)if(($x['id']??null)===$b['messageId'])$has=true;
    if(!$has)$lijst[]=['id'=>$b['messageId'],'soort'=>$lijst&&$b['soort']==='start'?'update':$b['soort'],'plaats'=>$b['gebied'],'at'=>aaIso($b['at']),
      'tekst'=>aaOr($b['tekst'])??($b['soort']==='afloop'?AA_KIND_AFLOOP[$b['bron']]:'')];
    if($b['cirkel'])$cirkel=$b['cirkel'];
    if($b['foto']){$foto=$b['foto'];$fotoKlein=aaOr($b['foto_klein'])??$b['foto'];}
    if($b['titel']!=='')$titel=$b['titel'];
    if($b['kop']!=='')$kop=$b['kop'];
  }
  if($first['bron']==='vermistkind'&&!aaRaaktWerkgebied($cirkel))return null;
  $afloop=null; foreach($lijst as $x)if(($x['soort']??'')==='afloop'){$afloop=$x;break;}
  $ended=(bool)$afloop||($prev['row']['status']??null)==='beeindigd';
  $tijdlijn=$ended?array_map(fn($x)=>($x['soort']??'')==='afloop'?$x:array_merge($x,['tekst'=>AA_VERWIJDERD]),$lijst):$lijst;
  $laatste=null; foreach(array_reverse($tijdlijn) as $x)if(($x['soort']??'')!=='afloop'){$laatste=$x;break;} $laatste??=$tijdlijn[0]??null;
  $start=aaDateParse($tijdlijn[0]['at']??null)?:$first['at'];
  $ids=array_map(fn($x)=>$x['id']??null,is_array($extra['berichten']??null)?$extra['berichten']:[]);
  $nieuw=array_values(array_filter($berichten,fn($b)=>!in_array($b['messageId'],$ids,true)));
  return ['id'=>$first['id'],'bron'=>$first['bron'],
    'titel'=>$ended?($first['bron']==='amber'?'AMBER Alert beëindigd':'Vermist Kind Alert beëindigd'):(aaOr($titel)??($first['bron']==='amber'?'AMBER Alert':'Vermist Kind Alert')),
    'tekst'=>$ended?(aaOr($afloop['tekst']??null)??AA_KIND_AFLOOP[$first['bron']]):(string)($laatste['tekst']??''),'tekst_en'=>null,
    'plaats'=>$first['bron']==='amber'?'Nederland':(aaOr($first['gebied'])??aaOr($prev['row']['plaats']??null)),'niveau'=>null,'soort'=>aaOr($kop)??'Vermist',
    'start_at'=>$start,'end_at'=>$ended?(aaDateParse($afloop['at']??null)?:(aaOr($prev['row']['end_at']??null)??$now)):null,'status'=>$ended?'beeindigd':'actief',
    'gebied'=>$cirkel?[aaCircleRing($cirkel)]:null,'lat'=>$cirkel?$cirkel['lat']:null,'lon'=>$cirkel?$cirkel['lon']:null,'link'=>aaOr($first['link'])??AA_KIND_READMORE,
    'berichten'=>$tijdlijn,'extra'=>['berichten'=>$tijdlijn,'cirkel'=>$cirkel,'foto'=>$ended?null:$foto,'foto_klein'=>$ended?null:$fotoKlein,'kop'=>$kop],
    '_nieuwBericht'=>(bool)$nieuw,'_soortNieuw'=>array_map(fn($b)=>$b['soort'],$nieuw)];
}

// ------------------------------------------------------------------ VNOG (vnog.js)
const AA_VNOG_API_ACTIEF='https://www.vnog.nl/api/v1/incidents?municipality=47,66&status=true';
const AA_VNOG_API_ALLES='https://www.vnog.nl/api/v1/incidents?municipality=47,66';
const AA_VNOG_AFGESLOTEN='Het incident is afgesloten.';
const AA_VNOG_VERS_MS=3*3600000;
const AA_MAANDEN=['januari','februari','maart','april','mei','juni','juli','augustus','september','oktober','november','december'];
function aaVnogDatum(mixed $s): ?int {
  if(!preg_match('/(\d{1,2})\s+([a-z]+)\s+(\d{4})\D+(\d{1,2})[:.](\d{2})/',mb_strtolower((string)($s??'')),$m))return null;
  $maand=array_search($m[2],AA_MAANDEN,true); if($maand===false)return null;
  return aaAmsterdamTime(sprintf('%02d/%02d/%s',(int)$m[1],$maand+1,$m[3]),sprintf('%02d:%s',(int)$m[4],$m[5]));
}
const AA_VNOG_ENT=['nbsp'=>' ','amp'=>'&','quot'=>'"','apos'=>"'",'lt'=>'<','gt'=>'>','euml'=>'ë','eacute'=>'é','egrave'=>'è','iuml'=>'ï','ouml'=>'ö','uuml'=>'ü',
  'rsquo'=>'’','lsquo'=>'‘','ldquo'=>'“','rdquo'=>'”','ndash'=>'–','mdash'=>'—','hellip'=>'…','euro'=>'€','deg'=>'°'];
function aaPlatTekst(mixed $html): string {
  $s=(string)preg_replace('/<\s*br\s*\/?>/i',"\n",(string)($html??'')); $s=(string)preg_replace('/<\/\s*(p|li|div|h\d)\s*>/i',"\n",$s); $s=(string)preg_replace('/<[^>]*>/','',$s);
  $s=(string)preg_replace_callback('/&#(\d+);/',fn($m)=>mb_chr((int)$m[1]),$s); $s=(string)preg_replace_callback('/&#x([0-9a-f]+);/i',fn($m)=>mb_chr((int)hexdec($m[1])),$s);
  $s=(string)preg_replace_callback('/&([a-z]+);/i',fn($m)=>AA_VNOG_ENT[strtolower($m[1])]??$m[0],$s);
  $s=(string)preg_replace('/[ \t\x{00a0}]+/u',' ',$s); $s=(string)preg_replace('/ *\n\s*/u',"\n",$s);
  return trim($s);
}
function aaVnogHash(string $s): string { $h=5381; foreach(mb_str_split($s) as $c){ $h=((($h*33)&0xFFFFFFFF)^mb_ord($c))&0xFFFFFFFF; } return base_convert((string)$h,10,36); }
function aaHeeft(string $tekst, string $naam): bool { return @preg_match('/(?<![\p{L}])'.preg_replace('/[-\s]/u','[-\s]',$naam).'(?![\p{L}])/iu',$tekst)===1; }
function aaVnogPlaats(array $inc): ?array {
  $gem=array_map('strval',array_keys(is_array($inc['municipalities']??null)?$inc['municipalities']:[]));
  $titel=(string)($inc['title']??'');
  $tekst=implode(' ',array_map(fn($u)=>($u['title']??'').' '.aaPlatTekst($u['description']??null),is_array($inc['updates']??null)?$inc['updates']:[]));
  $teuge=null; foreach(aaPlaatsen() as $p)if($p['naam']==='Teuge')$teuge=$p;
  if(!in_array('47',$gem,true))return in_array('66',$gem,true)&&$teuge&&aaHeeft("$titel $tekst",'Teuge')?$teuge:null;
  $dorpen=array_values(array_filter(aaPlaatsen(),fn($p)=>$p['naam']!=='Apeldoorn'&&$p['naam']!=='Teuge'));
  usort($dorpen,fn($a,$b)=>mb_strlen($b['naam'])<=>mb_strlen($a['naam']));
  $namen=fn($p)=>array_merge([$p['naam']],$p['aliassen']??[]);
  foreach($dorpen as $p)foreach($namen($p) as $n)if(aaHeeft($titel,$n))return $p;
  $stad=null; foreach(aaPlaatsen() as $p)if($p['naam']==='Apeldoorn')$stad=$p;
  if(aaHeeft($titel,'Apeldoorn'))return $stad;
  foreach($dorpen as $p)foreach($namen($p) as $n)if(aaHeeft($tekst,"(?:in|te) $n"))return $p;
  return $stad;
}
function aaVnogGrip(mixed $g): ?int { if(is_bool($g)||$g===null)return null; if(!is_numeric($g))return null; $n=(float)$g; return floor($n)==$n&&$n>=1&&$n<=5?(int)$n:null; }
function aaVnogAfgesloten(mixed $s): bool { return $s===false||$s===0||$s==='0'||$s==='false'; }
function aaVnogEinde(array $inc): bool {
  $u=(is_array($inc['updates']??null)?$inc['updates']:[])[0]??null;
  return (bool)$u&&preg_match('/einde\s+(berichtgeving|bericht|persbericht)(?![\p{L}])/iu',($u['title']??'').' '.aaPlatTekst($u['description']??null))===1;
}
function aaVnogStraat(array $inc): ?string {
  foreach([(string)($inc['title']??''),implode(' ',array_map(fn($u)=>aaPlatTekst($u['description']??null),is_array($inc['updates']??null)?$inc['updates']:[]))] as $tekst){
    $s=aaBurgernetStraat($tekst); if(!$s)continue;
    $voor=substr($tekst,0,(int)strpos($tekst,$s));
    return preg_match('/(Lage|Hoge|Oude|Nieuwe|Korte|Lange|Grote|Kleine|Eerste|Tweede|Derde|Sint|St\.)\s$/',$voor,$m)?$m[1].' '.$s:$s;
  }
  return null;
}
function aaVnogWarning(mixed $inc, ?array $prev, int $now): ?array {
  if(!is_array($inc))return null;
  $plaats=aaVnogPlaats($inc);
  if(!$plaats&&!empty($prev['row']['plaats']))foreach(aaPlaatsen() as $p)if($p['naam']===$prev['row']['plaats'])$plaats=$p;
  if(!$plaats||!isset($inc['id']))return null;
  $extra=is_array($prev['extra']??null)?$prev['extra']:[];
  $lijst=array_values(array_filter(is_array($extra['berichten']??null)?$extra['berichten']:[],fn($b)=>($b['soort']??null)!=='afloop'));
  $created=aaVnogDatum($inc['created']??null)??(isset($prev['row']['start_at'])?(int)$prev['row']['start_at']:$now);
  $updated=aaVnogDatum($inc['updated']??null)??$created;
  $updates=array_reverse(is_array($inc['updates']??null)?$inc['updates']:[]); $telling=[]; $nieuw=[];
  foreach($updates as $i=>$u){
    $kop=trim((string)($u['title']??'')); $n=$telling[$kop]??0; $telling[$kop]=$n+1;
    $id=aaVnogHash($kop).($n?'-'.$n:'');
    $tekst=implode(': ',array_filter([$kop,aaPlatTekst($u['description']??null)],fn($x)=>$x!==''));
    $link=!empty($u['link']['uri'])?(string)$u['link']['uri']:null;
    $found=false; foreach($lijst as &$b)if(($b['id']??null)===$id){$b['tekst']=$tekst;if($link)$b['link']=$link;$found=true;break;} unset($b);
    if($found)continue;
    $at=$prev?$now:($i===count($updates)-1&&count($updates)>1?max($updated,$created):$created);
    $lijst[]=['id'=>$id,'soort'=>$lijst?'update':'start','plaats'=>$plaats['naam'],'at'=>aaIso($at),'tekst'=>$tekst]+($link?['link'=>$link]:[]);
    $nieuw[]=$id;
  }
  $klaar=aaVnogAfgesloten($inc['status']??null)||aaVnogEinde($inc);
  $wasKlaar=($prev['row']['status']??null)==='beeindigd';
  $laat=$klaar&&(bool)$prev&&!$wasKlaar&&!aaVnogAfgesloten($inc['status']??null)&&$now-max($updated,$created)>30*60000;
  $eind=$klaar?($wasKlaar&&!empty($prev['row']['end_at'])?(int)$prev['row']['end_at']:($prev&&!$laat?$now:max($updated,$created))):null;
  if($klaar)$lijst[]=['id'=>'afloop','soort'=>'afloop','plaats'=>$plaats['naam'],'at'=>aaIso($eind),'tekst'=>AA_VNOG_AFGESLOTEN];
  $grip=aaVnogGrip($inc['grip']??null);
  $laatste=null; foreach(array_reverse($lijst) as $b)if(($b['soort']??null)!=='afloop'){$laatste=$b;break;}
  return ['id'=>'vnog:'.$inc['id'],'bron'=>'vnog','titel'=>aaOr(trim((string)($inc['title']??'')))??'Incident','tekst'=>(string)($laatste['tekst']??''),'tekst_en'=>null,
    'plaats'=>$plaats['naam'],'niveau'=>null,'soort'=>$grip?"GRIP $grip":'Incident','start_at'=>$created,'end_at'=>$eind,'status'=>$klaar?'beeindigd':'actief','gebied'=>null,
    'lat'=>$prev['row']['lat']??null,'lon'=>$prev['row']['lon']??null,'link'=>(string)(aaOr($inc['url']??null)??'https://www.vnog.nl/incidenten'),'berichten'=>$lijst,
    'extra'=>['berichten'=>$lijst,'grip'=>$grip,'grip_hoogste'=>aaOr(max($grip??0,(int)($extra['grip_hoogste']??0))),'straat'=>aaOr($extra['straat']??null)??aaVnogStraat($inc),
      'incident'=>aaOr($extra['incident']??null),'plek'=>aaOr($extra['plek']??null)],
    '_nieuw'=>(bool)$prev&&count($nieuw)>0,'_gripHoger'=>(bool)$prev&&($grip??0)>(int)($extra['grip']??0),'_afgesloten'=>$klaar&&(bool)$prev&&!$wasKlaar&&!$laat,'_vers'=>$now-$created<=AA_VNOG_VERS_MS];
}
function aaVnogLijst(mixed $d): array { if(!is_array($d))return []; return array_values($d); }

// ------------------------------------------------------------------ politie.nl and 112 Nieuws (nieuws.js)
const AA_ENT=['amp'=>'&','lt'=>'<','gt'=>'>','quot'=>'"','apos'=>"'",'nbsp'=>' ','euml'=>'ë','eacute'=>'é','egrave'=>'è','iuml'=>'ï','ouml'=>'ö','uuml'=>'ü','auml'=>'ä','ecirc'=>'ê','ccedil'=>'ç','rsquo'=>'’','lsquo'=>'‘','ldquo'=>'“','rdquo'=>'”','ndash'=>'–','mdash'=>'—','hellip'=>'…','euro'=>'€'];
function aaDecodeEntities(mixed $s): string {
  return (string)preg_replace_callback('/&(#x?[0-9a-f]+|\w+);/i',function($m){ $e=$m[1];
    if($e[0]==='#'){ $n=($e[1]==='x'||$e[1]==='X')?(preg_match('/^[0-9a-f]+/i',substr($e,2),$x)?hexdec($x[0]):null):(preg_match('/^\d+/',substr($e,1),$x)?(int)$x[0]:null);
      return $n!==null&&$n<=0x10FFFF?mb_chr((int)$n):$m[0]; }
    return AA_ENT[strtolower($e)]??$m[0]; },(string)($s??''));
}
function aaHtmlNaarAlineas(mixed $html): array {
  $s=(string)preg_replace('/<(script|style|iframe|figure)[\s\S]*?<\/\1>/i','',(string)($html??''));
  $s=(string)preg_replace('/<br\s*\/?>/i',"\n",$s); $s=(string)preg_replace('/<\/(p|div|h\d)>/i',"\n\n",$s);
  $s=(string)preg_replace('/<li[^>]*>/i','• ',$s); $s=(string)preg_replace('/<\/li>/i',"\n",$s); $s=(string)preg_replace('/<[^>]+>/','',$s);
  $out=[];
  foreach(preg_split('/\n{2,}/',aaDecodeEntities($s)) as $p){
    $l=implode("\n",array_values(array_filter(array_map(fn($x)=>trim((string)preg_replace('/[ \t\x{00a0}]+/u',' ',$x)),explode("\n",$p)),fn($x)=>$x!=='')));
    if($l!=='')$out[]=$l;
  }
  return $out;
}
const AA_ZWAK=['Engeland','De Krim','Groenendaal','Meerveld','Hooilanden','De Jonas','Oosterhuizen','Lieren','Assel','Loenen','De Haere','Woeste Hoeve','Gerritsflesch','Teuge'];
function aaPlaatsenIn(mixed $tekst, bool $regio=false): array {
  $t=(string)($tekst??''); $hits=[];$sterk=false;
  foreach(aaPlaatsen() as $p){ $alt=implode('|',array_map(fn($n)=>preg_quote($n,'/'),array_merge([$p['naam']],$p['aliassen']??[])));
    if(preg_match('/(^|[^\p{L}])('.$alt.')(?![\p{L}])/iu',$t)){ $hits[]=$p['naam']; if(!in_array($p['naam'],AA_ZWAK,true))$sterk=true; } }
  if($sterk||$regio||preg_match('/\b(apeldoorn|veluwe|oost-nederland|noord-?\s?en oost-gelderland|gelderland)\b/i',$t))return $hits;
  return [];
}
const AA_CATEGORIEEN=[['vermist','/\b(vermist|vermiste|vermissing)\b/iu'],
  ['verkeer','/\b(aanrijding|verkeer\w*|ongeval|ongeluk|snelheid|rijbewijs|bestuurder|automobilist|fietser|scooter|rijden|flitser|A1|A50|alcohol(controle)?)\b/iu'],
  ['inbraak','/\b(inbra(ak|ken|ker|kers)|ingebroken|diefstal|gestolen|beroving|overval)\b/iu'],
  ['drugs','/\b(drugs|hennep|cocaïne|xtc|wietplantage|drugslab|ondermijning)\b/iu'],
  ['brand','/\b(brand|brandstichting|explosie|vuurwerk)\b/iu'],
  ['getuigen','/\b(getuigen|tips|opsporing|gezocht|camerabeelden|signalement)\b/iu']];
function aaCategorieVan(mixed $t): string { foreach(AA_CATEGORIEEN as [$c,$re])if(preg_match($re,(string)($t??'')))return $c; return 'algemeen'; }
function aaPolitieTijd(mixed $s): ?int {
  if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/',(string)($s??''),$m))return null;
  return aaAmsMs((int)$m[1],(int)$m[2],(int)$m[3],(int)$m[4],(int)$m[5],(int)($m[6]??0));
}
function aaPolitieItem(mixed $n): ?array {
  if(!is_array($n)||empty($n['titel'])||(empty($n['uid'])&&empty($n['url'])))return null;
  $alineas=[];
  foreach(is_array($n['alineas']??null)?$n['alineas']:[] as $a){
    $delen=aaHtmlNaarAlineas(aaOr($a['opgemaaktetekst']??null)??aaOr($a['tekst']??null)??''); if(!$delen)continue;
    $alineas[]=['titel'=>!empty($a['titel'])?(aaOr(trim(aaDecodeEntities((string)$a['titel'])))):null,'tekst'=>implode("\n\n",$delen)];
  }
  $intro=implode("\n\n",aaHtmlNaarAlineas($n['introductie']??''));
  $alles=implode("\n",[(string)(aaOr($n['gebied']??null)??''),(string)$n['titel'],$intro]);
  // JS Number(): null is 0, a missing value or text is NaN.
  $num=fn($l,$k)=>is_array($l)&&array_key_exists($k,$l)?($l[$k]===null||$l[$k]===''?0.0:(is_numeric($l[$k])?(float)$l[$k]:null)):null;
  $locs=[]; foreach(is_array($n['locaties']??null)?$n['locaties']:[] as $l){ $la=$num($l,'latitude'); $lo=$num($l,'longitude'); if($la!==null&&$lo!==null)$locs[]=['lat'=>$la,'lon'=>$lo]; }
  $inG=false; $loc=null; foreach($locs as $l)if(aaInWerkgebied($l['lat'],$l['lon'],2)){$inG=true;$loc??=$l;}
  $plaatsen=aaPlaatsenIn($alles,$inG);
  if(!$plaatsen&&!$inG)return null;
  $plaats=null; foreach($plaatsen as $p)if($p!=='Apeldoorn'){$plaats=$p;break;} $plaats??=$plaatsen[0]??'Apeldoorn';
  return ['bronId'=>(string)(aaOr($n['uid']??null)??$n['url']),'titel'=>trim(aaDecodeEntities($n['titel'])),'intro'=>$intro,'alineas'=>$alineas,'at'=>aaPolitieTijd($n['publicatiedatum']??null)??aaNow(),
    'url'=>aaOr($n['url']??null)??'https://www.politie.nl/nieuws','plaats'=>$plaats,'plaatsen'=>$plaatsen,'gebied'=>aaOr($n['gebied']??null),
    'lat'=>$loc['lat']??null,'lon'=>$loc['lon']??null,'categorie'=>aaCategorieVan($n['titel'].' '.$intro)];
}
const AA_ZWAK_112=['Loenen','Lieren','Engeland','De Krim','Groenendaal','Meerveld','Hooilanden','De Jonas','Oosterhuizen','Assel','De Haere','Woeste Hoeve','Gerritsflesch'];
function aaSlugs112(): array { return array_map(fn($p)=>['naam'=>$p['naam'],'slug'=>preg_replace('/\s+/u','-',mb_strtolower($p['naam']))],aaPlaatsen()); }
function aaCdata(mixed $s): string { return (string)preg_replace('/^\s*<!\[CDATA\[([\s\S]*?)\]\]>\s*$/','$1',(string)($s??'')); }
function aaTag(string $xml, string $t): string { return preg_match('/<'.$t.'[^>]*>([\s\S]*?)<\/'.$t.'>/i',$xml,$m)?trim(aaCdata($m[1])):''; }
function aaKorteIntro(mixed $html, int $max=260): string {
  $t=trim((string)preg_replace('/\[…\]|\[\.\.\.\]|The post .*$/iu','',(string)preg_replace('/\s+/u',' ',implode(' ',aaHtmlNaarAlineas($html)))));
  if(aaJsLen($t)<=$max)return $t;
  $cut=aaJsSlice($t,$max);
  $punt=max(mb_strrpos($cut,'. ')===false?-1:mb_strrpos($cut,'. '),mb_strrpos($cut,'! ')===false?-1:mb_strrpos($cut,'! '),mb_strrpos($cut,'? ')===false?-1:mb_strrpos($cut,'? '));
  return $punt>80?mb_substr($cut,0,$punt+1):((string)preg_replace('/\s+\S*$/u','',$cut)).'…';
}
function aaParse112(mixed $xml, string $plaats): array {
  $out=[];
  foreach(array_slice(preg_split('/<item>/i',(string)($xml??'')),1) as $deel){
    $it=preg_split('/<\/item>/i',$deel)[0];
    $titel=aaDecodeEntities(aaTag($it,'title')); $link=aaTag($it,'link');
    if($titel===''||!preg_match('~^https://112nieuws\.net/~',$link))continue;
    preg_match_all('/<category>([\s\S]*?)<\/category>/i',$it,$cm); $cats=array_map(fn($c)=>aaDecodeEntities(trim(aaCdata($c))),$cm[1]);
    if(in_array($plaats,AA_ZWAK_112,true)){ $ok=false; foreach($cats as $c)if(preg_match('/^(gelderland|apeldoorn)$/i',$c))$ok=true; if(!$ok)continue; }
    $out[]=['bronId'=>aaOr(aaTag($it,'guid'))??$link,'titel'=>$titel,'link'=>$link,'at'=>aaDateParse(aaTag($it,'pubDate'))??aaNow(),'cats'=>$cats,'plaats'=>$plaats,'intro'=>aaKorteIntro(aaTag($it,'description'))];
  }
  return $out;
}
function aaCategorie112(array $cats): string {
  $c=array_map('mb_strtolower',$cats); $any=function($re)use($c){foreach($c as $x)if(preg_match($re,$x))return true;return false;};
  if($any('/traumaheli|lifeliner|helikopter/'))return 'traumaheli';
  if($any('/ongeval|aanrijding|verkeer|eenzijdig|file/'))return 'verkeer';
  if($any('/^brand$|uitslaande|explosie|rook|bosbrand|autobrand|woningbrand/'))return 'brand';
  if($any('/brandweer/')&&!$any('/politie|ambulance/'))return 'brand';
  if($any('/ambulance|reanimatie|medisch|letsel/'))return 'ambulance';
  if($any('/politie|steekpartij|schiet|overval|inbraak|aanhouding|vermis/'))return 'politie';
  return 'algemeen';
}
function aaNieuwsId(string $prefix, mixed $bron): string { return $prefix.'-'.substr(sha1((string)$bron),0,12); }

// ------------------------------------------------------------------ weather articles and liveblogs
const AA_CODE=['yellow'=>'Code geel','orange'=>'Code oranje','red'=>'Code rood','green'=>'Code groen'];
function aaFmtNL(mixed $iso, string $pattern): string {
  $ms=aaMs($iso); $f=new IntlDateFormatter('nl_NL',IntlDateFormatter::NONE,IntlDateFormatter::NONE,'Europe/Amsterdam',IntlDateFormatter::GREGORIAN,$pattern);
  return (string)$f->format(intdiv((int)$ms,1000));
}
function aaDagKey(mixed $iso): string { return aaFmtNL($iso,'dd-MM-yyyy'); }
function aaUurMin(mixed $iso): string { return aaFmtNL($iso,'HH:mm'); }
function aaWeekdag(mixed $iso): string { return aaFmtNL($iso,'EEEE'); }
function aaTijdvakTekst(mixed $start, mixed $einde): string {
  if(!$start)return ''; $van=aaWeekdag($start).' '.aaUurMin($start);
  if(!$einde)return "vanaf $van";
  return "$van tot ".(aaDagKey($einde)===aaDagKey($start)?'':aaWeekdag($einde).' ').aaUurMin($einde);
}
function aaHoofd(?string $s): ?string { return $s?mb_strtoupper(mb_substr($s,0,1)).mb_substr($s,1):$s; }
const AA_KNMI_ICOON=[['/mist/i','fog'],['/wind/i','wind'],['/onweer|tornado|hagel/i','thunderstorm'],['/regen/i','rain'],['/sneeuw/i','snow'],['/glad|ijzel|vorst/i','ice'],['/hitte/i','heat'],['/kou/i','cold'],['/kust/i','waterspout']];
function aaAdviezen(mixed ...$teksten): array {
  $uit=[];
  foreach($teksten as $t)foreach(preg_split('/\n+/',(string)($t??'')) as $regel)foreach(preg_split('/(?<=[.!?])\s+(?=[A-Z0-9])/u',$regel) as $zin){
    $z=trim((string)preg_replace('/^[-•›>\s]+/u','',$zin)); if($z!==''&&!in_array($z,$uit,true))$uit[]=$z; }
  return $uit;
}
function aaWeerArtikel(array $w): array {
  $code=AA_CODE[$w['niveau']??'']??'Weerwaarschuwing'; $soort=aaOr($w['soort']??null)??'het weer';
  $opgeheven=($w['status']??null)==='beeindigd'||($w['niveau']??null)==='green';
  $vakken=is_array($w['alleTijdvakken']??null)&&$w['alleTijdvakken']?$w['alleTijdvakken']:(is_array($w['tijdvakken']??null)&&$w['tijdvakken']?$w['tijdvakken']:[['niveau'=>$w['niveau']??null,'start'=>$w['start']??null,'einde'=>$w['einde']??null]]);
  $begin=null; foreach($vakken as $v)if(!$begin||aaMs($v['start'])<aaMs($begin))$begin=$v['start']; $begin=aaOr($begin)??($w['start']??null);
  $eind=null; foreach($vakken as $v)if(!empty($v['einde'])&&(!$eind||aaMs($v['einde'])>aaMs($eind)))$eind=$v['einde']; $eind=aaOr($eind)??($w['einde']??null);
  $tijdvak=aaTijdvakTekst($begin,$eind);
  $kop=aaOr($w['kop']??null)??"$code voor ".mb_strtolower($soort);
  $titel=$opgeheven?'Opgeheven: '.mb_strtolower(mb_substr($kop,0,1)).mb_substr($kop,1):$kop;
  $omschr=aaOr($w['omschrijving']??null)??aaOr($w['verwachting']??null)??aaOr($w['tekst']??null)??'';
  $advies=aaAdviezen(aaOr($w['omschrijving']??null)?($w['verwachting']??null):null,$w['advies']??null);
  $icoon='warning'; foreach(AA_KNMI_ICOON as [$re,$i])if(preg_match($re,$soort)){$icoon=$i;break;}
  $weer=['kop'=>$kop,'code'=>$code,'niveau'=>$w['niveau']??null,'soort'=>$soort,'icoon'=>$icoon,'omschrijving'=>$omschr,'uitgifte'=>aaOr($w['uitgifte']??null),'details'=>aaOr($w['details']??null),
    'adviezen'=>$advies,'opgeheven'=>$opgeheven,'tijdvak'=>$tijdvak,'datumKop'=>$begin?aaHoofd(aaFmtNL($begin,'EEEE d MMMM')):null,
    'vanafTot'=>$begin?aaHoofd('vanaf '.aaUurMin($begin).($eind?' tot '.(aaDagKey($eind)===aaDagKey($begin)?'':aaWeekdag($eind).' ').aaUurMin($eind):'')):null,
    'tijdvakken'=>count($vakken)>1?array_map(fn($v)=>['niveau'=>$v['niveau'],'code'=>AA_CODE[$v['niveau']]??$code,'tekst'=>aaTijdvakTekst($v['start'],$v['einde'])],$vakken):null,
    'gebieden'=>is_array($w['landelijk']??null)&&$w['landelijk']?$w['landelijk']:(!empty($w['gebied'])?[['niveau'=>$w['niveau']??null,'gebied'=>$w['gebied']]]:[])];
  $al=[];
  if($opgeheven)$al[]=['titel'=>null,'tekst'=>'Het KNMI heeft deze waarschuwing opgeheven. Er geldt nu code groen.'];
  $al[]=['titel'=>'Wanneer','tekst'=>$weer['tijdvakken']?implode("\n",array_map(fn($v)=>$v['code'].': '.$v['tekst'],$weer['tijdvakken'])):"$code: $tijdvak"];
  if($omschr!=='')$al[]=['titel'=>null,'tekst'=>$omschr];
  if($advies)$al[]=['titel'=>"Risico's en advies",'tekst'=>implode("\n",array_map(fn($a)=>"› $a",$advies))];
  if(aaOr($w['details']??null))$al[]=['titel'=>'Details','tekst'=>(string)$w['details']];
  if(aaOr($w['uitgifte']??null))$al[]=['titel'=>null,'tekst'=>'Uitgifte: '.$w['uitgifte']];
  return ['titel'=>$titel,'intro'=>$omschr,'alineas'=>$al,'opgeheven'=>$opgeheven,'weer'=>$weer];
}
function aaLiveblogVan(array $w): array {
  $b=[]; $i=0;
  foreach(is_array($w['berichten']??null)?$w['berichten']:[] as $x){ if(!$x||(!aaOr($x['tekst']??null)&&!aaOr($x['at']??null))){continue;}
    $b[]=['key'=>'b'.$i.':'.(string)(aaOr($x['id']??null)??aaOr($x['at']??null)??''),'at'=>aaOr($x['at']??null)??$w['start'],'soort'=>aaOr($x['soort']??null)??'update','plaats'=>aaOr($x['plaats']??null),'tekst'=>(string)(aaOr($x['tekst']??null)??''),'door'=>$w['bron']]; $i++; }
  if(!$b&&aaOr($w['tekst']??null))$b[]=['key'=>'b0','at'=>$w['start'],'soort'=>'start','plaats'=>aaOr($w['plaats']??null),'tekst'=>$w['tekst'],'door'=>$w['bron']];
  return ['titel'=>(string)(aaOr($w['titel']??null)??''),'intro'=>(string)(aaOr($w['tekst']??null)??''),'berichten'=>$b];
}
