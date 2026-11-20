<?php
declare(strict_types=1);
/** A-Alerts endpoint provenance and visual media map; no tokens or connection secrets. */
function docsSourceIds(array $endpoint): array {
 $path=(string)$endpoint['path'];
 $group=(string)$endpoint['group'];
 // Every API response ultimately reads its own MySQL state or own API configuration,
 // but references here show the original upstream(s), not a claim of live ingestion.
 if(str_contains($path,'/verloop'))return ['p2000','koyeb_p2000','mysql'];
 if(str_contains($path,'/routes'))return ['p2000','applemaps','pdok','mysql'];
 if(str_contains($path,'/pand'))return ['p2000','pdok','mysql'];
 if(str_contains($path,'/weather'))return ['weatherkit','mysql'];
 if(str_contains($path,'/meldingen/')||$path==='/v1/meldingen')return ['p2000','koyeb_p2000','mysql'];
 if(str_contains($path,'/waarschuwingen')||$group==='Waarschuwingen')return ['nlalert','burgernet','amber','knmi','vnog','mysql'];
 if($path==='/v1/nieuws/foto/{md5}')return ['politie','nieuws112','site','mysql'];
 if(str_contains($path,'/nieuws'))return ['politie','nieuws112','knmi','burgernet','amber','mysql'];
 if(str_contains($path,'/helikopters')||$path==='/v1/lucht')return ['adsb','mysql'];
 if(str_contains($path,'/radar'))return ['knmi_radar','mysql'];
 if(str_contains($path,'/kaart.png'))return ['mapkit','mysql'];
 if(str_contains($path,'/mapkit-token'))return ['mapkit'];
 if(str_contains($path,'/verkeer'))return ['ndw','mysql'];
 if(str_contains($path,'/posten'))return ['p2000','applemaps','mysql'];
 if(str_contains($path,'/veiligheidsregio'))return ['vnog','site','mysql'];
 if(str_contains($path,'/locaties'))return ['site','mysql'];
 if(str_contains($path,'/devices')||str_contains($path,'/push')||str_contains($path,'/toestel'))return ['apns','fcm','mysql'];
 if(str_contains($path,'/status')||str_contains($path,'/health')||$path==='/')return ['mysql','worker','p2000','koyeb_p2000'];
 if(str_contains($path,'/bronnen')||str_contains($path,'/vergelijk'))return ['mysql','worker'];
 if($group==='Registratie & push')return ['site','apns','fcm','mysql'];
 if($group==='Beheer')return ['site','mysql'];
 return ['site','mysql'];
}
function docsMediaFor(array $endpoint):array {
 $path=(string)$endpoint['path'];$group=(string)$endpoint['group'];
 if($group==='Meldingen')return [
  ['file'=>'/images/website/icon_brandweer.png','label'=>'Brandweer'],
  ['file'=>'/images/website/marker_ambulance.png','label'=>'Ambulancemarker'],
  ['file'=>'/images/website/marker_politie.png','label'=>'Politiemarker'],
  ['file'=>'/images/website/marker_traumaheli.png','label'=>'Traumahelimarker'],
 ];
 if($group==='Waarschuwingen')return [
  ['file'=>'/images/website/icon_nlalert.png','label'=>'NL-Alert'],
  ['file'=>'/images/website/icon_burgernet.png','label'=>'Burgernet'],
  ['file'=>'/images/website/icon_amber.png','label'=>'AMBER Alert'],
  ['file'=>'/images/website/icon_knmi.png','label'=>'KNMI'],
  ['file'=>'/images/website/icon_vnog.png','label'=>'VNOG'],
 ];
 if($group==='Nieuws')return [
  ['file'=>'/images/website/nieuws/politie-brand.webp','label'=>'Politiebericht'],
  ['file'=>'/images/website/nieuws/112-brand-1.webp','label'=>'112nieuws'],
  ['file'=>'/images/website/icon_knmi.png','label'=>'KNMI-nieuws'],
 ];
 if($group==='Luchtvaart')return [
  ['file'=>'/images/aircraft/heli_lifeliner_ec135.svg','label'=>'Lifeliner EC135'],
  ['file'=>'/images/aircraft/heli_politie_aw139.svg','label'=>'Zulu AW139'],
  ['file'=>'/images/aircraft/kustwacht_aw189.svg','label'=>'Kustwacht AW189'],
  ['file'=>'/images/aircraft/chinook.svg','label'=>'Chinook'],
  ['file'=>'/images/aircraft/apache.svg','label'=>'Apache'],
 ];
 if($group==='Weer & kaarten')return [
  ['file'=>'/images/website/icon_knmi.png','label'=>'KNMI'],
  ['file'=>'/images/website/marker_brandweer.png','label'=>'Kaartmarker'],
  ['file'=>'/images/aircraft/heli_lifeliner_ec135.svg','label'=>'Helikoptersymbool'],
 ];
 if($group==='Geografie')return [
  ['file'=>'/images/website/marker_brandweer.png','label'=>'Brandweermarker'],
  ['file'=>'/images/website/marker_ambulance.png','label'=>'Ambulancemarker'],
  ['file'=>'/images/website/marker_vnog.png','label'=>'VNOG-marker'],
 ];
 if($group==='Verkeer')return [
  ['file'=>'/images/website/verkeer_file.png','label'=>'File'],
  ['file'=>'/images/website/verkeer_ongeval.png','label'=>'Ongeval'],
  ['file'=>'/images/website/verkeer_werk.png','label'=>'Werkzaamheden'],
  ['file'=>'/images/website/verkeer_flitser.png','label'=>'Flitser'],
 ];
 return [['file'=>'/images/website/aalerts-icon-180.png','label'=>'A-Alerts']];
}
