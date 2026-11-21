<?php
declare(strict_types=1);
/**
 * Fixed and beheer-managed data: map locations, the safety region border, texts (privacy, terms, changelog, help,
 * announcement), the app start-up info and the station figures (index.js, admin.js contentFor, posten.js postInfo).
 */
require_once __DIR__.'/meldingen.php';

const AA_CONTENT_NAMES=['privacy','cookies','terms','disclaimer','changelog','hulp','mededeling'];

function aaLocatiesData(): array {
  $l=aaData('locaties');
  return ['bron'=>$l['bron']??'OpenStreetMap (ODbL)','ziekenhuizen'=>$l['ziekenhuizen']??[],
    'bases'=>array_map(function($b){$h=$b['heli']??null;unset($b['heli']);$b['helikopters']=$h;return $b;},$l['bases']??[])];
}
/** admin.js contentFor: the text saved in the beheer, else the text that comes with the app. */
function aaContentFor(string $name): mixed {
  $v=aaKvGet('content:'.$name);
  $opgeslagen=$v!==null?json_decode($v,true):null;
  if($name==='mededeling')return $opgeslagen??['title'=>'','content'=>'','version'=>'','updated_at'=>''];
  $mee=aaData('content-'.$name);
  if(!is_array($opgeslagen))return $mee;
  // A text saved in the beheer wins, unless the text that comes with a newer release is newer: otherwise a new
  // "Wat is nieuw" or help page never shows up once the beheer has saved that text once.
  if($name==='changelog'){
    if(!array_is_list($opgeslagen))return $mee;
    $ids=[]; foreach($opgeslagen as $e)if(is_array($e))$ids[(string)($e['id']??'')]=true;
    $alle=$opgeslagen; foreach($mee as $e)if(is_array($e)&&!isset($ids[(string)($e['id']??'')]))$alle[]=$e;
    usort($alle,fn($a,$b)=>strcmp((string)($b['release_date']??''),(string)($a['release_date']??'')));
    return $alle;
  }
  $dOpg=aaContentDatum($opgeslagen['updated_at']??$opgeslagen['version']??null); $dMee=aaContentDatum($mee['version']??null);
  return $dMee!==null&&$dOpg!==null&&$dMee>$dOpg?$mee:$opgeslagen;
}
/** "2026-11-21", "6 november 2026" or an ISO time → "Y-m-d", else null. */
function aaContentDatum(mixed $v): ?string {
  $s=mb_strtolower(trim((string)($v??''))); if($s==='')return null;
  if(preg_match('/^(\d{4})-(\d{2})-(\d{2})/',$s,$m))return "$m[1]-$m[2]-$m[3]";
  $maanden=['januari'=>1,'februari'=>2,'maart'=>3,'april'=>4,'mei'=>5,'juni'=>6,'juli'=>7,'augustus'=>8,'september'=>9,'oktober'=>10,'november'=>11,'december'=>12];
  if(preg_match('/^(\d{1,2})\s+([a-z]+)\s+(\d{4})$/u',$s,$m)&&isset($maanden[$m[2]]))return sprintf('%04d-%02d-%02d',$m[3],$maanden[$m[2]],$m[1]);
  return null;
}
function aaAppInfo(): array {
  $n=fn($v)=>max(0,(int)$v);
  $mededeling=null;
  try{
    $c=aaContentFor('mededeling');
    $tekst=trim((string)preg_replace('/\s+/u',' ',(string)preg_replace('/<[^>]+>/',' ',(string)($c['content']??''))));
    if($tekst!=='')$mededeling=['id'=>($c['updated_at']??'')?:(($c['version']??'')?:'1'),'titel'=>trim((string)($c['title']??''))?:'Mededeling','tekst'=>mb_substr($tekst,0,600)];
  }catch(Throwable){}
  return ['min_build'=>['ios'=>$n(setting('MIN_BUILD_IOS')),'android'=>$n(setting('MIN_BUILD_ANDROID'))],'mededeling'=>$mededeling];
}

// ------------------------------------------------------------------ posten.js postInfo
function aaVerdeling(array $tijden): array {
  $uur=array_fill(0,24,0); $dag=array_fill(0,7,0); $tz=new DateTimeZone('Europe/Amsterdam');
  foreach($tijden as $t){ $d=(new DateTimeImmutable('@'.intdiv((int)$t,1000)))->setTimezone($tz); $uur[(int)$d->format('G')]++; $dag[(int)$d->format('N')-1]++; }
  return ['per_uur'=>$uur,'per_dag'=>$dag];
}
/** posten.js postInfo: everything about one station, or null when the id is unknown. */
function aaPostInfo(string $id): ?array {
  $post=null; foreach(aaData('kazernes') as $k)if($k['id']===$id){$post=$k;break;} if(!$post)return null;
  $q=aaDb()->prepare('SELECT incident, at FROM post_inzet WHERE post = ? AND at > ? ORDER BY at DESC LIMIT 2000');
  $q->execute([$id,aaNow()-366*86400000]); $rijen=$q->fetchAll();
  $info=aaParse(aaKvGet('post_info:'.$id),null)?:['voertuigen'=>[],'capcodes'=>[]];
  $col=new Collator('en');
  $vl=[];foreach($info['voertuigen']??[] as $nr=>$l)$vl[]=['nr'=>(string)$nr,'label'=>$l]; usort($vl,fn($a,$b)=>$col->compare($a['nr'],$b['nr']));
  $cl=[];foreach($info['capcodes']??[] as $cd=>$nm)$cl[]=['code'=>(string)$cd,'naam'=>$nm]; usort($cl,fn($a,$b)=>$col->compare($a['naam'],$b['naam']));
  $ver=aaVerdeling(array_column($rijen,'at'));
  return ['id'=>$post['id'],'naam'=>$post['naam'],'soort'=>$post['soort'],'plaats'=>$post['plaats'],'lat'=>$post['lat'],'lon'=>$post['lon'],
    'inzetten'=>count($rijen),'sinds'=>$rijen?aaIso((int)end($rijen)['at']):null,'laatste'=>$rijen?aaIso((int)$rijen[0]['at']):null,
    'per_uur'=>$ver['per_uur'],'per_dag'=>$ver['per_dag'],'voertuigen'=>$vl,'capcodes'=>$cl,
    'recent'=>array_map(fn($r)=>['incident'=>$r['incident'],'at'=>aaIso((int)$r['at'])],array_slice($rijen,0,8))];
}
