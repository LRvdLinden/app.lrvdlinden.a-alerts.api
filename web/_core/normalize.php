<?php
declare(strict_types=1);
function aalertsPlaceKey(string $value): string {
 return trim((string)preg_replace('/\\s+/u',' ',str_replace('-',' ',mb_strtolower($value))));
}
function aalertsAllowedPlace(string $place): bool {
 static $allowed=[
 'apeldoorn','beekbergen','beemte broekland','hoenderloo','hoog soeren',
 'klarenbeek','loenen','uddel','ugchelen','wenum wiesel','wenum',
 'wiesel','teuge','lieren','radio kootwijk'];
 return in_array(aalertsPlaceKey($place),$allowed,true);
}
function normalizeP2000(array $rows):array{
 $out=[];
 foreach($rows as $r){
  if(!is_array($r)||empty($r['uid'])||empty($r['melding'])||!aalertsAllowedPlace((string)($r['plaats']??'')))continue;
  $raw=trim((string)$r['melding']);$prioriteit=null;
  if(preg_match('/^\s*(?:Prio\s*([1-3])|P\s?([1-3])|([AB][0-3]))\b/i',$raw,$m))
   $prioriteit=isset($m[3])&&$m[3]!==''?strtoupper($m[3]):'P'.($m[1]??$m[2]);
  $units=json_decode((string)($r['capcodes_json']??'{}'),true);$vehicles=json_decode((string)($r['voertuigen_json']??'{}'),true);
  $units=is_array($units['capcodes']??null)?$units['capcodes']:[];
  $units=array_values(array_filter(array_map(static function($x){
    if(!is_array($x)||empty($x['capcode']))return null;
    $parts=explode(' / ',(string)($x['label']??''));
    if(preg_match('/^(monitorcode|lichtkrant)/i',$parts[0]??''))return null;
    return ['code'=>(string)$x['capcode'],'naam'=>$parts[0]??null,'type'=>$parts[1]??null,'regio'=>$parts[2]??null];
  },$units)));
  $dienst=strtolower((string)($r['dienst']??'onbekend'));
  if(preg_match('/lifeliner|traumaheli|\bmmt\b/i',$raw.' '.implode(' ',array_column($units,'naam'))))$dienst='traumaheli';
  if(!in_array($dienst,['brandweer','ambulance','politie','traumaheli','kustwacht']))$dienst='onbekend';
  $lat=null;$lon=null;
  $coords=(string)($r['latlong']??$r['plaats_latlon']??'');
  if(preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/',$coords,$m)){
   $lat=(float)$m[1];$lon=(float)$m[2];
   if(abs($lat)>90||abs($lon)>180||($lat===0.0&&$lon===0.0)){$lat=null;$lon=null;}
  }
  $when=(float)($r['timestamp']??0);$out[]=[
   'id'=>(string)$r['uid'],'guid'=>(string)$r['uid'],
   'title'=>trim(preg_replace('/^\s*(?:Prio\s*\d|P\s?\d|[AB]\d)\s*/i','',$raw)),
   'full_melding'=>$raw,'dienst'=>$dienst,'prioriteit'=>$prioriteit,
   'datum'=>$when>0?(int)round($when*1000):null,
   'locatie'=>$r['locatie']??null,'lat'=>$lat,'lon'=>$lon,
   'stad'=>$r['plaats']??null,'regio'=>$r['vr']??$r['regio']??null,
   'capcode'=>$units[0]['code']??null,'eenheden'=>$units,
   'voertuigen'=>$vehicles['voertuig']??[],'grip'=>(!empty($r['grip'])&&(string)$r['grip']!=='0')?$r['grip']:null,
   'opschaling'=>$r['soortopschaling']??null,
   'vlaggen'=>implode(',',array_keys(array_filter(['groot'=>$r['groot']??null,'kruising'=>$r['kruising']??null,'spam'=>$r['antiespam']??null,'groep'=>$r['groep']??null],static fn($v)=>$v!==null&&(string)$v!==''&&(string)$v!=='0')))?:null,
   'bron'=>'p2000.page','link'=>$r['link']??null
  ];
 }
 return $out;
}
function normalizeNlAlert(array $rows):array{
 $out=[];
 foreach($rows as $a){
  if(!is_array($a)||empty($a['id']))continue;
  $message=(string)($a['message']??'');
  $nl=trim(preg_split('/\*\*\*\s*English/i',$message)[0]??'');
  $out[]=['id'=>'nlalert:'.$a['id'],'bron'=>'nlalert','titel'=>mb_substr(trim(preg_split('/(?<=[.!?])\s+/u',$nl)[0]??''),0,140),
   'tekst'=>$nl,'tekst_en'=>null,'start'=>$a['start_at']??null,'einde'=>$a['stop_at']??null,
   'status'=>(!empty($a['stop_at'])&&strtotime((string)$a['stop_at'])<time())?'beeindigd':'actief',
   'gebied'=>$a['area']??null,'soort'=>$a['type']??'alert','link'=>'https://actueel.nl-alert.nl/alert/'.rawurlencode((string)$a['id']).'/'];
 }
 return $out;
}
function normalizeBurgernet(array $rows):array{
 $out=[];
 foreach($rows as $a){
  if(!is_array($a))continue;
  $place=trim((string)($a['location']??''));
  if(!preg_match('/apeldoorn|voorst/i',$place))continue;
  $url=(string)($a['link']??'');$id=preg_match('~/burgernet/(\d+)~',$url,$m)?$m[1]:substr(sha1(json_encode($a)),0,16);
  $text=trim((string)($a['report']??''));
  $out[]=['id'=>'burgernet:'.$id,'bron'=>'burgernet','titel'=>mb_substr($text,0,140),'tekst'=>$text,
   'plaats'=>$place,'start'=>$a['date']??null,'status'=>!empty($a['isClosed'])?'beeindigd':'actief','link'=>$url];
 }
 return $out;
}
function normalizeKind(array $rows):array{
 $out=[];foreach($rows as $a){
  if(!is_array($a)||empty($a['AlertId']))continue;
  $lvl=(string)($a['AlertLevel']??'');$bron=$lvl==='10'?'amber':($lvl==='5'?'vermistkind':null);
  if(!$bron)continue;
  $m=$a['Message']??[];$id=$bron.':'.$a['AlertId'];
  $out[]=['id'=>$id,'bron'=>$bron,'message_id'=>$a['MessageId']??null,'titel'=>$m['Title']??'',
   'tekst'=>$m['Description']??null,'omschrijving'=>$m['DescriptionExt']??null,'gebied'=>$a['Area']??null,
   'status'=>strtolower((string)($a['Type']??''))==='cancel'?'beeindigd':'actief',
   'link'=>$m['Readmore_URL']??'https://www.politie.nl/amberalert','at'=>$a['Sent']??null];
 }return $out;
}
function aalertsClassifyAircraft(array $ac): ?array {
  $callsign=strtoupper(trim((string)($ac['flight']??'')));
  $reg=strtoupper(trim((string)($ac['r']??'')));
  $hex=strtolower(trim((string)($ac['hex']??'')));
  $type=strtoupper(trim((string)($ac['t']??'')));
  $operator=strtoupper((string)($ac['ownOp']??'').' '.(string)($ac['desc']??''));
  if(preg_match('/^(?:LIFELN|LIFELINER|LIFE)0?([1-4])$/',$callsign,$m))
    return ['id'=>'lifeliner'.$m[1],'naam'=>'Lifeliner '.$m[1],'soort'=>'trauma','herkenning'=>'callsign'];
  if(preg_match('/^MEDIC0?([1-9])$/',$callsign,$m)||$reg==='PH-OOP')
    return ['id'=>'medic01','naam'=>'Medic 01','soort'=>'trauma','medic'=>true,'herkenning'=>'callsign'];
  if(preg_match('/^(?:ZXP|ZULU|POLITIE)0?(\\d{1,2})$/',$callsign,$m))
    return ['id'=>'zulu'.str_pad($m[1],2,'0',STR_PAD_LEFT),'naam'=>'Zulu '.str_pad($m[1],2,'0',STR_PAD_LEFT),'soort'=>'politie','herkenning'=>'callsign'];
  $zulu=['PH-PXA'=>'01','PH-PXB'=>'02','PH-PXC'=>'03','PH-PXD'=>'04','PH-PXE'=>'05','PH-PXF'=>'06','PH-PXX'=>'24','PH-PXY'=>'25','PH-PXZ'=>'26'];
  if(isset($zulu[$reg]))return ['id'=>'zulu'.$zulu[$reg],'naam'=>'Zulu '.$zulu[$reg],'soort'=>'politie','herkenning'=>'registratie'];
  $heli=in_array($type,['EC35','EC45','A139','H135','H145','EC30','A169','H160'],true)||($ac['category']??'')==='A7';
  if($heli&&str_starts_with($reg,'PH-')&&preg_match('/ANWB|MEDICAL AIR|LIFELINER|TRAUMA/',$operator))
    return ['id'=>'trauma-'.($hex?:$reg),'naam'=>'Traumaheli '.$reg,'soort'=>'trauma','herkenning'=>'operator'];
  if($heli&&str_starts_with($reg,'PH-')&&preg_match('/POLITIE|POLICE|KLPD/',$operator))
    return ['id'=>'politie-'.($hex?:$reg),'naam'=>'Politieheli '.$reg,'soort'=>'politie','herkenning'=>'operator'];
  $kust=['PH-CGD','PH-CGE','C-FCGE','PH-NCG','PH-SAR','PH-RSQ','PH-BSR'];
  $coast=in_array($reg,$kust,true)||preg_match('/^NCG\\d{1,3}$/',$callsign)||in_array($hex,['486449','48644b'],true);
  if($coast)return ['id'=>'lucht-'.$hex,'naam'=>'Kustwacht '.($reg?:$callsign),'soort'=>'kustwacht','icoon'=>$heli?'kustwacht_aw189':'kustwacht_dash8'];
  if($reg==='PH-GOV')return ['id'=>'lucht-'.$hex,'naam'=>'Regeringsvliegtuig PH-GOV','soort'=>'regering','icoon'=>'phgov'];
  if(in_array($reg,['D-IAMT','D-IBMT'],true)||str_contains($operator,'MARECHAUSSEE'))
    return ['id'=>'lucht-'.$hex,'naam'=>'Marechaussee '.$reg,'soort'=>'kmar','icoon'=>'kmar'];
  $national=ctype_xdigit($hex)&&(hexdec($hex)>=0x480000&&hexdec($hex)<=0x487fff);
  if(((int)($ac['dbFlags']??0)&1)&&$national)return ['id'=>'lucht-'.$hex,'naam'=>trim($type.' '.$reg)?:'Militair toestel','soort'=>'defensie','icoon'=>$heli?'heli':'vliegtuig'];
  return null;
}
function normalizeAircraft(array $rows):array{
 $out=[];
 foreach($rows as $ac){
  if(!is_array($ac))continue;
  $hex=strtolower(trim((string)($ac['hex']??'')));
  if(!preg_match('/^[0-9a-f]{6}$/',$hex))continue;
  $lat=$ac['lat']??$ac['lastPosition']['lat']??null;
  $lon=$ac['lon']??$ac['lastPosition']['lon']??null;
  if(!is_numeric($lat)||!is_numeric($lon)||abs((float)$lat)>90||abs((float)$lon)>180)continue;
  $ident=aalertsClassifyAircraft($ac);
  if(!$ident)continue;
  $seen=(float)($ac['seen_pos']??$ac['seen']??$ac['lastPosition']['seen_pos']??0);
  if($seen>300||$seen<0)continue;
  $ground=($ac['alt_baro']??null)==='ground';
  $out[]=[
   ...$ident,'hex'=>$hex,'icao'=>$hex,'callsign'=>trim((string)($ac['flight']??''))?:null,
   'registratie'=>$ac['r']??null,'type'=>$ac['t']??null,'lat'=>(float)$lat,'lon'=>(float)$lon,
   'koers'=>is_numeric($ac['track']??null)?(int)round($ac['track']):null,
   'hoogte_ft'=>is_numeric($ac['alt_baro']??null)?(int)round($ac['alt_baro']):null,
   'altitude_ft'=>$ac['alt_baro']??$ac['alt_geom']??null,
   'op_grond'=>$ground,'snelheid_kt'=>is_numeric($ac['gs']??null)?(int)round($ac['gs']):null,
   'stijgsnelheid_fpm'=>is_numeric($ac['baro_rate']??null)?(int)round($ac['baro_rate']):null,
   'gezien'=>gmdate('c',(int)(time()-$seen)),'bron'=>'adsb.lol',
  ];
 }
 return $out;
}
