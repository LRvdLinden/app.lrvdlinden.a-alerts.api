<?php
declare(strict_types=1);
// Normalizers for offline parity exploration, NOT a claim of full Worker equivalence.
function shadowP2000(array $rows): array {
 $out=[];
 foreach($rows as $r) {
   if(!is_array($r)||empty($r['uid'])||empty($r['melding'])) continue;
   $raw=trim((string)$r['melding']);
   $pos=explode(',',(string)($r['latlong']??$r['plaats_latlon']??''));
   $lat=isset($pos[0])&&is_numeric(trim($pos[0]))?(float)$pos[0]:null;
   $lon=isset($pos[1])&&is_numeric(trim($pos[1]))?(float)$pos[1]:null;
   $units=[]; $parsed=json_decode((string)($r['capcodes_json']??''),true);
   foreach(($parsed['capcodes']??[]) as $c)if(!empty($c['capcode']))$units[]=$c;
   $prio=preg_match('/^\s*(Prio\s*[1-3]|P\s?[1-3]|A[012]|B[123])\b/i',$raw,$m)?strtoupper(preg_replace('/\s+/','',$m[1])):null;
   $out[]=['id'=>(string)$r['uid'],'guid'=>(string)$r['uid'],'title'=>preg_replace('/^\s*(?:Prio\s*\d|P\s?\d|[AB]\d)\s*/i','',$raw),
      'full_melding'=>$raw,'dienst'=>strtolower((string)($r['dienst']??'onbekend')),
      'prioriteit'=>$prio,'datum'=>(float)($r['timestamp']??0)*1000,
      'stad'=>(string)($r['plaats']??''),'locatie'=>$r['locatie']??null,'lat'=>$lat,'lon'=>$lon,
      'regio'=>$r['vr']??$r['regio']??null,'capcode'=>$units[0]['capcode']??null,
      'eenheden'=>$units,'link'=>$r['link']??'https://p2000.page/api'];
 }
 return uniqueItems($out);
}
function shadowWarnings(array $nl,array $bn,array $knmi,array $kind): array {
 $out=[];
 foreach($nl as $a){if(!is_array($a)||empty($a['id']))continue;
   $text=trim(preg_split('/\*\*\*\s*English/i',(string)($a['message']??''))[0]??'');
   $out[]=['id'=>'nlalert:'.$a['id'],'bron'=>'nlalert','titel'=>mb_substr($text,0,140),'tekst'=>$text,
     'start_at'=>$a['start_at']??null,'end_at'=>$a['stop_at']??null,'status'=>'actief','gebied'=>$a['area']??null];
 }
 foreach($bn as $a){if(!is_array($a))continue;
   $link=(string)($a['link']??'');$id=preg_match('~/burgernet/([0-9]+)~',$link,$m)?$m[1]:substr(hash('sha256',json_encode($a)),0,16);
   $out[]=['id'=>'burgernet:'.$id,'bron'=>'burgernet','titel'=>mb_substr((string)($a['report']??''),0,140),
      'tekst'=>$a['report']??null,'plaats'=>$a['location']??null,'status'=>!empty($a['isClosed'])?'beeindigd':'actief',
      'link'=>$link];
 }
 foreach($kind as $a){if(!is_array($a)||empty($a['AlertId']))continue;
   $level=(string)($a['AlertLevel']??'');$bron=$level==='10'?'amber':($level==='5'?'vermistkind':null);if(!$bron)continue;
   $out[]=['id'=>$bron.':'.$a['AlertId'],'bron'=>$bron,'titel'=>$a['Message']['Title']??'',
      'tekst'=>$a['Message']['Description']??null,'status'=>(strtolower((string)($a['Type']??''))==='cancel')?'beeindigd':'actief',
      'message_id'=>$a['MessageId']??null,'gebied'=>$a['Area']??null];
 }
 // KNMI's original structured warning periods require polygon, level and validity interpretation.
 // Store raw warnings separately, never fabricate normalized events.
 return uniqueItems($out);
}
function shadowTraffic(array $feed): array {
 $items=array_merge(is_array($feed['items']??null)?$feed['items']:[],
     is_array($feed['gepland']??null)?$feed['gepland']:[]);
 return uniqueItems($items);
}
function shadowAircraft(array $feed): array {
 // adsb.lol returns 'ac', unlike the Worker '/v1/helikopters' response.
 return uniqueItems(is_array($feed['ac']??null)?$feed['ac']:($feed['helikopters']??[]));
}
