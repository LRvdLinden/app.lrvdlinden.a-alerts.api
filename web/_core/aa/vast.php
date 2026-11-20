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
/**
 * Figures of one station. The Worker counted for a year before this server existed: its figures up to the moment
 * they were copied (kv posten_basis:<id>, field basis_tot) are the base, and the own call-outs after that are added.
 */
function aaPostInfo(string $id): ?array {
  $post=null; foreach(aaData('kazernes') as $k)if($k['id']===$id){$post=$k;break;} if(!$post)return null;
  $basis=aaParse(aaKvGet('posten_basis:'.$id),null); $vanaf=(int)($basis['basis_tot']??0);
  $q=aaDb()->prepare('SELECT incident, at FROM post_inzet WHERE post = ? AND at > ? ORDER BY at DESC LIMIT 2000');
  $q->execute([$id,max(aaNow()-366*86400000,$vanaf)]); $rijen=$q->fetchAll();
  $info=aaParse(aaKvGet('post_info:'.$id),null)?:['voertuigen'=>[],'capcodes'=>[]];
  $v=$basis?array_column($basis['voertuigen']??[],'label','nr'):[]; foreach($info['voertuigen']??[] as $nr=>$l)$v[(string)$nr]=$l;
  $c=$basis?array_column($basis['capcodes']??[],'naam','code'):[]; foreach($info['capcodes']??[] as $cd=>$nm)$c[(string)$cd]=$nm;
  $col=new Collator('en');
  $vl=[];foreach($v as $nr=>$l)$vl[]=['nr'=>(string)$nr,'label'=>$l]; usort($vl,fn($a,$b)=>$col->compare($a['nr'],$b['nr']));
  $cl=[];foreach($c as $cd=>$nm)$cl[]=['code'=>(string)$cd,'naam'=>$nm]; usort($cl,fn($a,$b)=>$col->compare($a['naam'],$b['naam']));
  $ver=aaVerdeling(array_column($rijen,'at'));
  if($basis){ foreach($ver['per_uur'] as $i=>&$x)$x+=(int)($basis['per_uur'][$i]??0); unset($x); foreach($ver['per_dag'] as $i=>&$x)$x+=(int)($basis['per_dag'][$i]??0); unset($x); }
  $recent=array_map(fn($r)=>['incident'=>$r['incident'],'at'=>aaIso((int)$r['at'])],array_slice($rijen,0,8));
  if($basis&&count($recent)<8)$recent=array_slice(array_merge($recent,$basis['recent']??[]),0,8);
  $n=count($rijen)+(int)($basis['inzetten']??0);
  $sinds=$basis['sinds']??null; if(!$sinds&&$rijen)$sinds=aaIso((int)end($rijen)['at']);
  $laatste=$rijen?aaIso((int)$rijen[0]['at']):($basis['laatste']??null);
  return ['id'=>$post['id'],'naam'=>$post['naam'],'soort'=>$post['soort'],'plaats'=>$post['plaats'],'lat'=>$post['lat'],'lon'=>$post['lon'],
    'inzetten'=>$n,'sinds'=>$sinds,'laatste'=>$laatste,'per_uur'=>$ver['per_uur'],'per_dag'=>$ver['per_dag'],'voertuigen'=>$vl,'capcodes'=>$cl,'recent'=>$recent];
}

/**
 * Until the switch: texts edited in the beheer of the Worker and the station figures it collected are copied from its
 * public routes, so the own API answers the same. Runs from the mirror cron; stops when WORKER_SPIEGEL is "0".
 */
function aaSyncVanWorker(string $worker, callable $get): array {
  $done=[];
  foreach(['privacy','cookies','terms','disclaimer','hulp'] as $n){
    $d=$get($worker.'/v1/content/'.$n); if(is_array($d)){aaKvSet('content:'.$n,aaJson($d));$done[]=$n;}
  }
  $d=$get($worker.'/v1/changelog'); if(is_array($d)){aaKvSet('content:changelog',aaJson($d));$done[]='changelog';}
  $app=$get($worker.'/v1/app');
  if(is_array($app)){
    $m=$app['mededeling']??null;
    aaKvSet('content:mededeling',aaJson($m?['title'=>$m['titel'],'content'=>$m['tekst'],'version'=>'','updated_at'=>$m['id']]:['title'=>'','content'=>'','version'=>'','updated_at'=>'']));
    $done[]='mededeling';
  }
  if(aaKvGet('posten_basis_klaar')===null){
    $t=aaNow(); $n=0;
    foreach(aaData('kazernes') as $k){ $p=$get($worker.'/v1/posten/'.rawurlencode($k['id'])); if(!is_array($p))continue; $p['basis_tot']=$t; aaKvSet('posten_basis:'.$k['id'],aaJson($p)); $n++; }
    aaKvSet('posten_basis_klaar',(string)$t); $done[]="posten:$n";
  }
  return $done;
}
