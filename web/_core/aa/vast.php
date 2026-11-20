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
  if($v!==null)return json_decode($v,true);
  if($name==='mededeling')return ['title'=>'','content'=>'','version'=>'','updated_at'=>''];
  return aaData('content-'.$name);
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
