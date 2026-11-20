<?php
declare(strict_types=1);
/** News (nieuws.js): list, article with timeline, photos. The intake of each news source follows in its own module. */
require_once __DIR__.'/base.php';

const AA_NIEUWS_SOORTEN=['politie','weer','liveblog','regionaal'];
const AA_LIVE_BRONNEN=['burgernet','amber','vermistkind','nlalert','vnog'];
const AA_IMG='https://a-alerts.nl/assets/img/nieuws/';
const AA_IMG_V='?v=6';
const AA_SITE='https://a-alerts.nl/nieuws/';
const AA_STOCK_CATEGORIEEN=['algemeen','verkeer','inbraak','getuigen','drugs','brand','vermist'];
const AA_WEER_ICONEN=['fog','wind','thunderstorm','rain','snow','ice','heat','cold','waterspout','warning'];
const AA_FOTOS_112=[
  'brand'=>['js-brand-1','112-brand-4','112-brand-1','112-brand-2','112-brand-3'],
  'ambulance'=>['js-ambulance-1','js-ambulance-2','112-ambulance-4','112-ambulance-1','112-ambulance-2','112-ambulance-3','112-ambulance-5'],
  'traumaheli'=>['js-traumaheli-1','js-traumaheli-2','112-traumaheli-1'],
  'verkeer'=>['js-ambulance-2','politie-motor-2','112-ambulance-5','politie-verkeer','112-ambulance-2'],
  'politie'=>['js-politie-1','js-politieheli-1','politie-auto','politie-motor-1','politie-algemeen','politie-getuigen','politie-drugs'],
  'algemeen'=>['js-ambulance-1','js-brand-1','js-traumaheli-2','politie-auto','112-ambulance-4','112-brand-4']];
const AA_POLITIE_FOTOS=[
  'algemeen'=>['js-politie-1','politie-algemeen','js-politieheli-1','politie-auto','politie-motor-1'],'verkeer'=>['politie-verkeer','politie-motor-2','js-ambulance-2'],
  'brand'=>['politie-brand','js-brand-1','112-brand-4'],'inbraak'=>['politie-inbraak','politie-auto'],'getuigen'=>['politie-getuigen','js-politie-1','politie-motor-1'],
  'drugs'=>['politie-drugs','politie-auto'],'vermist'=>['js-politieheli-1','politie-vermist','politie-algemeen']];
const AA_NIEUWS_LIST_SQL="SELECT n.*, (SELECT MAX(at) FROM nieuws_updates u WHERE u.nieuws_id = n.id) AS laatste_update,
  EXISTS (SELECT 1 FROM nieuws_liveblog lb WHERE lb.id = n.id) AS is_liveblog,
  (SELECT foto FROM nieuws_stockfoto sf WHERE sf.id = n.id) AS stock_foto,
  (SELECT COUNT(*) FROM nieuws_updates u WHERE u.nieuws_id = n.id) AS n_updates FROM nieuws n";

/** JS parseInt(String(id).slice(2, 10), 16) || 0 */
function aaParseHexPrefix(string $s): int { return preg_match('/^[0-9a-f]+/i',$s,$m)?(int)hexdec($m[0]):0; }
function aaKiesOpId(array $lijst, mixed $id): string { return $lijst[aaParseHexPrefix(substr((string)$id,2,8))%count($lijst)]; }
function aaFoto112(array $row): string { $f=aaOr($row['stock_foto']??null)??aaKiesOpId(AA_FOTOS_112[$row['categorie']??'']??AA_FOTOS_112['algemeen'],$row['id']); return AA_IMG.$f.'.webp'.AA_IMG_V; }
function aaStockFotos(): array { $o=[]; foreach(aaDb()->query("SELECT `key`, value FROM kv WHERE `key` LIKE 'nieuws_stock:%'") as $r)$o[substr($r['key'],13)]=$r['value']; return $o; }
function aaAfbeeldingVoor(array $row, string $origin, array $stock): string {
  if(!empty($row['foto']))return $origin.'/v1/nieuws/foto/'.$row['foto'];
  $live=!empty($row['is_liveblog']);
  if($row['soort']==='politie'){
    $cat=in_array($row['categorie'],AA_STOCK_CATEGORIEEN,true)?$row['categorie']:'algemeen';
    $eigen=$stock[$cat]??($stock['algemeen']??null);
    return $eigen?$origin.'/v1/nieuws/foto/'.$eigen:AA_IMG.(aaOr($row['stock_foto']??null)??aaKiesOpId(AA_POLITIE_FOTOS[$cat]??AA_POLITIE_FOTOS['algemeen'],$row['id'])).'.webp'.AA_IMG_V;
  }
  if($row['soort']==='weer'){
    $code=$row['status']==='afgerond'||$row['niveau']==='green'?'green':(in_array($row['niveau'],['yellow','orange','red'],true)?$row['niveau']:'yellow');
    return AA_IMG.'knmi-'.$code.'-'.(in_array($row['categorie'],AA_WEER_ICONEN,true)?$row['categorie']:'warning').($live?'-live':'').'.webp'.AA_IMG_V;
  }
  if($row['soort']==='regionaal')return aaFoto112($row);
  if($row['status']==='afgerond'&&in_array($row['bron'],AA_LIVE_BRONNEN,true))return AA_IMG.$row['bron'].'-afgerond.webp'.AA_IMG_V;
  return AA_IMG.$row['bron'].($live?'-live':'').'.webp'.AA_IMG_V;
}
function aaSamenvatting(array $row, string $origin, array $stock, bool $beheer=false): array {
  $at=(int)$row['at'];
  $o=['id'=>$row['id'],'soort'=>$row['soort'],'bron'=>$row['bron'],'titel'=>$row['titel'],'intro'=>aaOr($row['intro']??null),'plaats'=>aaOr($row['plaats']??null),
    'at'=>aaIso($at),'bijgewerkt'=>aaIso(max((int)($row['laatste_update']??0),$at)),
    'start'=>$row['soort']==='liveblog'?aaOr($row['start']??null,aaIso($at)):null,
    'status'=>aaOr($row['status']??null),'niveau'=>aaOr($row['niveau']??null),'categorie'=>aaOr($row['categorie']??null),
    'afbeelding'=>aaAfbeeldingVoor($row,$origin,$stock),'link'=>AA_SITE.$row['id'].'/','bron_url'=>aaOr($row['url']??null),
    'waarschuwing'=>$row['soort']==='politie'?null:$row['ref'],'aantal'=>$row['aantal']??null,'liveblog'=>(bool)($row['is_liveblog']??false),'foto_bron'=>null];
  if($beheer)$o['hidden']=(bool)(int)$row['hidden'];
  return $o;
}
function aaListNieuws(string $origin, mixed $soort=null, mixed $limit=null, mixed $before=null, bool $beheer=false): array {
  $lim=min(max((int)(is_numeric($limit)?$limit:0)?:40,1),100);
  $where=[$beheer?'1=1':'n.hidden = 0']; $args=[];
  if($soort==='liveblog'&&!$beheer)$where[]='EXISTS (SELECT 1 FROM nieuws_liveblog lb WHERE lb.id = n.id)';
  elseif(in_array($soort,AA_NIEUWS_SOORTEN,true)){$where[]='n.soort = ?';$args[]=$soort;}
  $sort='GREATEST(n.at, COALESCE((SELECT MAX(at) FROM nieuws_updates u WHERE u.nieuws_id = n.id), 0))';
  if($before!==null&&$before!==''){$where[]="$sort < ?";$args[]=aaMs($before)??0;}
  $q=aaDb()->prepare(AA_NIEUWS_LIST_SQL.' WHERE '.implode(' AND ',$where)." ORDER BY $sort DESC, n.created_at DESC LIMIT $lim"); $q->execute($args);
  $stock=aaStockFotos(); $out=[];
  foreach($q->fetchAll() as $r){ $d=aaParse($r['data'],[]); if(!is_array($d))$d=[];
    $r['aantal']=$r['soort']==='liveblog'?count($d['berichten']??[])+(int)$r['n_updates']:(int)$r['n_updates'];
    $r['start']=aaOr($d['start']??null); $out[]=aaSamenvatting($r,$origin,$stock,$beheer); }
  return $out;
}
function aaGetNieuws(string $origin, string $id, bool $beheer=false): ?array {
  $q=aaDb()->prepare(AA_NIEUWS_LIST_SQL.' WHERE n.id = ?'); $q->execute([$id]); $row=$q->fetch();
  if(!$row||((int)$row['hidden']&&!$beheer))return null;
  $d=aaParse($row['data'],[]); if(!is_array($d))$d=[];
  $u=aaDb()->prepare('SELECT * FROM nieuws_updates WHERE nieuws_id = ? ORDER BY at DESC'); $u->execute([$id]);
  $eigen=array_map(fn($x)=>['id'=>(int)$x['id'],'at'=>aaIso((int)$x['at']),'soort'=>'aanvulling','door'=>'a-alerts','titel'=>aaOr($x['titel']??null),'tekst'=>(string)($x['tekst']??''),
    'foto'=>$x['foto']?$origin.'/v1/nieuws/foto/'.$x['foto']:null,'url'=>aaOr($x['url']??null)],$u->fetchAll());
  $officieel=array_map(fn($b)=>['id'=>null,'at'=>$b['at']??null,'soort'=>$b['soort']??null,'door'=>aaOr($b['door']??null,$row['bron']),'titel'=>null,'tekst'=>$b['tekst']??null,'plaats'=>aaOr($b['plaats']??null),'foto'=>null,'url'=>null],$d['berichten']??[]);
  $tijdlijn=array_merge($eigen,$officieel);
  // JS Array.sort is stable: equal times keep their order (own additions first).
  $idx=array_keys($tijdlijn); usort($idx,fn($a,$b)=>((aaMs($tijdlijn[$b]['at'])??0)<=>(aaMs($tijdlijn[$a]['at'])??0))?:($a<=>$b));
  $tijdlijn=array_map(fn($i)=>$tijdlijn[$i],$idx);
  $laatste=(int)$row['at']; foreach($tijdlijn as $t)$laatste=max($laatste,aaMs($t['at'])??0);
  $row['aantal']=count($tijdlijn); $row['laatste_update']=$laatste; $row['start']=aaOr($d['start']??null);
  $bron=$row['soort']==='regionaal'?'112 Nieuws':($row['soort']==='politie'?'politie.nl':($row['soort']==='weer'?'KNMI':($row['bron']==='burgernet'?'Burgernet':($row['bron']==='vnog'?'VNOG':'Politie (AMBER Alert)'))));
  return aaSamenvatting($row,$origin,aaStockFotos(),$beheer)+['alineas'=>$d['alineas']??[],'plaatsen'=>aaOr($d['plaatsen']??null),'tijdlijn'=>$tijdlijn,'weer'=>aaOr($d['weer']??null),'bron_naam'=>$bron];
}
function aaFotoResponse(string $id): never {
  $q=aaDb()->prepare('SELECT mime, data FROM nieuws_fotos WHERE id = ?'); $q->execute([$id]); $r=$q->fetch();
  if(!preg_match('/^[0-9a-f]{32}$/',$id)||!$r){http_response_code(404);header('Content-Type: text/plain;charset=UTF-8');echo 'Niet gevonden';exit;}
  header('Content-Type: '.$r['mime']); header('Cache-Control: public, max-age=31536000, immutable'); header('Access-Control-Allow-Origin: *');
  echo $r['data']; exit;
}
