<?php
declare(strict_types=1);
/**
 * History from before the own intake started: alerts the Worker mirror stored (api_events) are copied once into the
 * engine's own table. Only alerts older than the first own Koyeb connection (kv aa_koyeb_start), so everything after
 * that moment comes from the own intake alone and a gap there stays visible in /docs.
 */
require_once __DIR__.'/meldingen.php';

function aaImportUitSpiegel(): int {
  $start=(int)(aaKvGet('aa_koyeb_start')??0);
  if(!$start)return 0;
  if(aaKvGet('aa_import_klaar')===(string)$start)return 0;
  $q=aaDb()->query("SELECT payload FROM api_events WHERE kind='p2000' AND source LIKE 'worker.%'");
  $n=0;
  foreach($q as $row){
    $x=json_decode((string)$row['payload'],true);
    if(!is_array($x)||empty($x['guid'])||!preg_match('/^[\w-]{8,64}$/',(string)$x['guid']))continue;
    $datum=aaMs($x['datum_tijd']??null); if($datum===null||$datum>=$start)continue;
    $m=['guid'=>$x['guid'],'title'=>(string)($x['title']??''),'full_melding'=>$x['full_melding']??null,'dienst'=>$x['dienst']??'onbekend',
      'prioriteit'=>$x['prioriteit']??null,'datum'=>$datum,'locatie'=>$x['locatie']??null,
      'lat'=>!empty($x['plaatscentrum'])?null:($x['lat']??null),'lon'=>!empty($x['plaatscentrum'])?null:($x['lon']??null),
      'stad'=>$x['stad']??null,'regio'=>$x['regio']??null,'capcode'=>$x['capcode']??null,'categorie'=>$x['categorie']??null,
      'grip'=>$x['grip']??null,'opschaling'=>$x['opschaling']??null,'eenheden'=>$x['eenheden']??[],'voertuigen'=>$x['voertuigen']??[],
      'link'=>$x['link']??null,'incident'=>$x['incident']??null,'vlaggen'=>!empty($x['groot'])?'groot':null,'bron'=>'import','created_at'=>$datum];
    $m['dedup_key']=aaDedupKey($m);
    if(aaInsertMelding($m)){
      $n++;
      if(!empty($x['views']))aaDb()->prepare('UPDATE meldingen SET views = ? WHERE guid = ?')->execute([(int)$x['views'],$x['guid']]);
    }
  }
  aaKvSet('aa_import_klaar',(string)$start);
  aaKvSet('aa_import',aaJson(['at'=>aaNow(),'meldingen'=>$n,'tot'=>aaIso($start)]));
  return $n;
}
