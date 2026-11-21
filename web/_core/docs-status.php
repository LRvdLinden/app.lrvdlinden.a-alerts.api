<?php
declare(strict_types=1);
/**
 * Live state of the own A-Alerts API for /docs. Only reads: the database (kv, tables, collector runs), which module
 * files are deployed and which settings are present (names only, never values). Nothing here claims more than the
 * server can show: a part counts as running only with a recent sign of life in the database.
 */
require_once __DIR__.'/private-api.php';

function docsNowMs(): int { return (int)floor(microtime(true)*1000); }
function docsKv(PDO $db, string $key): ?string {
  try{ $q=$db->prepare('SELECT value FROM kv WHERE `key` = ?'); $q->execute([$key]); $v=$q->fetchColumn(); return $v===false?null:(string)$v; }catch(Throwable){ return null; }
}
function docsKvAt(PDO $db, string $key): ?int {
  try{ $q=$db->prepare('SELECT at FROM kv WHERE `key` = ?'); $q->execute([$key]); $v=$q->fetchColumn(); return $v===false||$v===null?null:(int)$v; }catch(Throwable){ return null; }
}
function docsMsOf(?string $v): ?int { if($v===null||$v==='')return null; if(ctype_digit($v))return (int)$v; $t=strtotime($v); return $t===false?null:$t*1000; }
function docsScalar(PDO $db, string $sql, array $args=[]): mixed {
  try{ $q=$db->prepare($sql); $q->execute($args); $v=$q->fetchColumn(); return $v===false?null:$v; }catch(Throwable){ return null; }
}
function docsTableExists(PDO $db, string $t): bool { return docsScalar($db,'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',[$t])>0; }
/** "3 min geleden", "2 uur geleden", "20-11 14:05". */
function docsAgo(?int $ms): string {
  if(!$ms)return 'nog nooit';
  $s=max(0,intdiv(docsNowMs()-$ms,1000));
  if($s<90)return $s.' s geleden';
  if($s<5400)return round($s/60).' min geleden';
  if($s<172800)return round($s/3600).' uur geleden';
  return (new DateTimeImmutable('@'.intdiv($ms,1000)))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('d-m-Y H:i');
}
/** Module file of the own engine present on this server. */
function docsModule(string $file): bool { return is_file(__DIR__.'/aa/'.$file); }
/** Route of the catalog served by the own engine: all literal parts of its path occur in one deployed route file. */
function docsRouteInEngine(array $route): bool {
  static $files=null;
  // Regex escapes are removed first, so '\.png$' in a route pattern reads as '.png$'.
  if($files===null){ $files=[]; foreach(glob(__DIR__.'/aa/routes*.php')?:[] as $f)$files[]=str_replace('\\','',(string)file_get_contents($f)); }
  // The own API routes of this server (status, sources, comparison, mirror) live in private-api.php.
  if(($route['group']??'')==='Eigen API'&&($route['status']??'')==='supported')return true;
  $parts=array_values(array_filter(preg_split('/\{[^}]+\}/',(string)$route['path']),fn($p)=>$p!==''&&$p!=='/'));
  // Root route "/": no literal part, so look for its exact path check.
  if(!$parts){ foreach($files as $code)if(str_contains($code,"\$path==='".(string)$route['path']."'"))return true; return false; }
  foreach($files as $code){
    $all=true;
    foreach($parts as $p){
      // A part that ends a path segment must end there in the code too ("/routes" is not "/routes-lucht.php").
      $re=str_ends_with($p,'/')?'~'.preg_quote($p,'~').'~':'~'.preg_quote($p,'~').'(?=[$\'"#?/(])~';
      if(!preg_match($re,$code)){$all=false;break;}
    }
    if($all)return true;
  }
  return false;
}
function docsSet(string $k): bool { return setting($k)!==''; }

/** Everything /docs shows live. Every block carries a state: live, overname, deels, gepland, fout, uit. */
function docsLiveState(): array {
  $now=docsNowMs(); $out=['db'=>false,'bronnen'=>[],'onderdelen'=>[],'tabellen'=>[],'config'=>[],'fout'=>null];
  try{ $db=privateDb(); $db->query('SELECT 1'); $out['db']=true; }catch(Throwable $e){ $out['fout']='Database niet bereikbaar'; return $out; }
  $aa=docsTableExists($db,'meldingen')&&docsTableExists($db,'kv');

  // --- tables
  foreach(['meldingen'=>'P2000-meldingen','waarschuwingen'=>'Waarschuwingen','nieuws'=>'Nieuwsartikelen','nieuws_updates'=>'Nieuwsaanvullingen','devices'=>'Toestellen (push)',
    'device_prefs'=>'Pushvoorkeuren','installs'=>'Installaties','post_inzet'=>'Kazerne-inzetten','heli_posities'=>'Vliegposities','overrides'=>'Beheercorrecties',
    'melding_feedback'=>'Gemelde fouten','weather'=>'Weer-cache','melding_routes'=>'Routes-cache','status_incidenten'=>'Statusincidenten','kv'=>'Sleutel/waarde'] as $t=>$label)
    $out['tabellen'][]=['tabel'=>$t,'label'=>$label,'aantal'=>docsTableExists($db,$t)?(int)docsScalar($db,"SELECT COUNT(*) FROM `$t`"):null];

  // --- collector runs of the first PHP collectors (api_collector_runs)
  $runs=[];
  try{ foreach($db->query("SELECT r.source, UNIX_TIMESTAMP(r.finished_at)*1000 AS at_ms, r.ok, r.error_code, r.total FROM api_collector_runs r JOIN (SELECT source, MAX(id) AS id FROM api_collector_runs GROUP BY source) m ON m.id = r.id") as $r)$runs[$r['source']]=$r; }catch(Throwable){}
  $run=function(string $src)use($runs){ $r=$runs[$src]??null; return $r?['at'=>$r['at_ms']!==null?(int)$r['at_ms']:null,'ok'=>(int)$r['ok']===1,'fout'=>$r['error_code'],'n'=>(int)$r['total']]:null; };

  // --- P2000 via own Koyeb intake
  $kStart=docsMsOf(docsKv($db,'aa_koyeb_start')); $kMsg=docsMsOf(docsKv($db,'koyeb_last_message')); $kAdd=docsMsOf(docsKv($db,'koyeb_last_added'));
  $kErr=docsMsOf(docsKv($db,'koyeb_last_error')); $kErrT=docsKv($db,'koyeb_last_error_text');
  $nKoyeb=$aa?(int)docsScalar($db,"SELECT COUNT(*) FROM meldingen WHERE bron = 'koyeb'"):0;
  $p2State=$kMsg&&$now-$kMsg<180000?'live':($kStart?'fout':'gepland');
  $login=null; if(is_file(__DIR__.'/aa/koyeb-login.php')){ require_once __DIR__.'/aa/koyeb-login.php'; $login=aaKoyebVerbinding()['soort']??null; }
  $loginTekst=$login==='website'?'Aanmelding: website-login met code van de beheerder van de stream (vers tijdstempel per verbinding)':($login==='homey'?'Aanmelding: eigen Homey-identiteit (reserve, zonder P2000_CODE)':'Aanmelding: niet ingesteld');
  $out['bronnen'][]=['id'=>'p2000','naam'=>'P2000 · eigen Koyeb-inname','icoon'=>'/images/website/icon_brandweer.png','staat'=>$p2State,
    'laatst'=>$kMsg,'detail'=>$loginTekst.' · Laatste nieuwe melding uit het werkgebied: '.docsAgo($kAdd).' · '.$nKoyeb.' meldingen zelf binnengehaald sinds '.($kStart?docsAgo($kStart):'—')
      .($kErr&&(!$kMsg||$kErr>$kMsg)?' · laatste fout: '.(string)$kErrT:'')];
  $pp=$run('p2000.page');
  $out['bronnen'][]=['id'=>'p2000page','naam'=>'P2000 · p2000.page (eigen poller)','icoon'=>'/images/website/icon_ambulance.png','staat'=>'uit','laatst'=>$pp['at']??null,
    'detail'=>'Gepauzeerd: de persoonlijke sleutel staat maar een beperkt aantal IP-adressen toe en de Worker gebruikt hem al. Meldingen komen via de eigen Koyeb-inname.'];

  // --- D1 copy of the Worker database
  $d1=json_decode((string)docsKv($db,'aa_d1'),true); $d1=is_array($d1)?$d1:[];
  $d1Last=max(docsMsOf($d1['vol_bezig']??null)??0,...array_map(fn($v)=>docsMsOf((string)$v)??0,array_values(is_array($d1['klein']??null)?$d1['klein']:[0])));
  $out['bronnen'][]=['id'=>'d1','naam'=>'Overname Worker-database (D1, alleen lezen)','icoon'=>'/images/website/aalerts-icon-180.png',
    'staat'=>setting('WORKER_SPIEGEL')==='0'?'uit':(!empty($d1['fout'])?'fout':(!empty($d1['vol_klaar'])?($d1Last&&$now-$d1Last<1200000?'overname':'fout'):($d1?'deels':'gepland'))),'laatst'=>$d1Last?:null,
    'detail'=>setting('WORKER_SPIEGEL')==='0'?'Klaar: sinds 21-11-2026 draait alles hier, de Worker geeft alleen nog door. De laatste kopie staat in de database.':((!empty($d1['vol_klaar'])?'Volledige kopie klaar: '.docsAgo(docsMsOf($d1['vol_klaar'])).'. ':'Volledige kopie loopt nog. ')
      .'Waarschuwingen elke 5 min, toestellen/nieuws/teksten elke 15 min.'.(!empty($d1['fout'])?' Fout: '.$d1['fout']:''))];

  // --- Worker mirror
  // The database keeps local times: let it convert them, so the age is right in every time zone.
  $mAt=(int)(docsScalar($db,"SELECT UNIX_TIMESTAMP(MAX(updated_at))*1000 FROM api_kv WHERE key_name LIKE 'worker:/v1/%'")??0)?:null;
  $out['bronnen'][]=['id'=>'spiegel','naam'=>'Spiegel van workers.dev (controle)','icoon'=>'/images/website/aalerts-icon-180.png',
    'staat'=>setting('WORKER_SPIEGEL')==='0'?'uit':($mAt&&$now-$mAt<300000?'overname':($mAt?'fout':'gepland')),'laatst'=>$mAt,
    'detail'=>'Leest de openbare routes van de Worker om te vergelijken; tijdelijk, uit met WORKER_SPIEGEL=0.'];

  $k5=docsMsOf((string)(($d1['klein']??[])[5]??($d1['klein']??[])['5']??'')); $k15=docsMsOf((string)(($d1['klein']??[])[15]??($d1['klein']??[])['15']??''));
  // --- warnings per source: own intake module present, else data from the D1 copy
  // Own intake of warnings and news (aa/bronnen.php): "actief" writes and reports in kv aa_bronnen; "proef" only reads the
  // sources, compares with the stored data and reports in kv aa_bronnen_proef. Only a recent "actief" report counts as live.
  $actief=json_decode((string)docsKv($db,'aa_bronnen'),true); $proef=json_decode((string)docsKv($db,'aa_bronnen_proef'),true);
  $eigenBronnen=is_array($actief)&&$now-(int)($actief['at_ms']??0)<300000;
  $proefRecent=!$eigenBronnen&&is_array($proef)&&$now-(int)($proef['at_ms']??0)<1500000;
  $proefTekst=function(string $b)use($proef,$proefRecent){ if(!$proefRecent)return ''; $r=$proef[$b]??null; $v=is_array($r)?(int)($r['verschillen']??0):0;
    return ' Proefdraai eigen inname ('.docsAgo((int)$proef['at_ms']).'): '.($v?"$v verschillen met de huidige gegevens":'geen verschillen').'; schrijft nog niets.'; };
  foreach([['nlalert','NL-Alert','/images/website/logo_nlalert.png'],['burgernet','Burgernet','/images/website/icon_burgernet.png'],['knmi','KNMI-weerwaarschuwingen','/images/website/icon_knmi.png'],
           ['amber','AMBER Alert','/images/website/logo_amber.png'],['vermistkind','Vermist Kind Alert','/images/website/icon_vermistkind.png'],['vnog','VNOG (Veiligheidsregio)','/images/website/logo_vnog.png']] as [$b,$naam,$ico]){
    $n=$aa?(int)docsScalar($db,'SELECT COUNT(*) FROM waarschuwingen WHERE bron = ?',[$b]):0;
    $act=$aa?(int)docsScalar($db,"SELECT COUNT(*) FROM waarschuwingen WHERE bron = ? AND status != 'beeindigd'",[$b]):0;
    $last=$aa?(int)(docsScalar($db,'SELECT MAX(updated_at) FROM waarschuwingen WHERE bron = ?',[$b])??0):0;
    $r=$run($b==='vermistkind'||$b==='amber'?'kind':$b);
    $out['bronnen'][]=['id'=>$b,'naam'=>$naam,'icoon'=>$ico,'staat'=>$eigenBronnen?'live':($k5&&$now-$k5<1200000?'overname':($d1?'fout':'gepland')),'laatst'=>$eigenBronnen?(int)$actief['at_ms']:$k5,
      'detail'=>"$n opgeslagen, $act actief, laatste wijziging ".docsAgo($last?:null).'. '.($eigenBronnen?'Eigen inname actief.':'Gegevens via de overname uit de Worker.'.$proefTekst($b==='amber'||$b==='vermistkind'?'kind':$b))];
  }
  // --- news per kind
  foreach([['politie','Politie.nl-nieuws','/images/website/icon_politie.png','politie'],['regionaal','112nieuws (regionaal)','/images/website/nieuws/112-brand-1.webp','112nieuws'],
           ['weer','KNMI-weerartikelen','/images/website/icon_knmi.png',null],['liveblog','Liveblogs','/images/website/icon_burgernet.png',null]] as [$s,$naam,$ico,$src]){
    $n=$aa?(int)docsScalar($db,'SELECT COUNT(*) FROM nieuws WHERE soort = ?',[$s]):0;
    $last=$aa?(int)(docsScalar($db,'SELECT MAX(updated_at) FROM nieuws WHERE soort = ?',[$s])??0):0;
    $r=$src?$run($src):null;
    $out['bronnen'][]=['id'=>'nieuws-'.$s,'naam'=>$naam,'icoon'=>$ico,'staat'=>$eigenBronnen?'live':($k15&&$now-$k15<2400000?'overname':($d1?'fout':'gepland')),'laatst'=>$eigenBronnen?(int)$actief['at_ms']:$k15,
      'detail'=>"$n artikelen, laatste wijziging ".docsAgo($last?:null).'. '.($eigenBronnen?'Eigen inname actief.':'Via de overname uit de Worker.'.($proefRecent?' Eigen inname draait als proef en schrijft nog niets.':''))];
  }
  // --- aircraft (adsb.lol)
  $heliPos=$aa?(int)(docsScalar($db,'SELECT MAX(at) FROM heli_posities')??0):0;
  $snap=docsKvAt($db,'lucht:last-known-v1');
  $lucht=docsModule('routes-lucht.php');
  $via=json_decode((string)(docsKv($db,'adsb_via')??''),true); $fout=json_decode((string)(docsKv($db,'adsb_fout')??''),true);
  $viaAt=is_array($via)?docsMsOf((string)($via['at']??'')):null; $foutAt=is_array($fout)?docsMsOf((string)($fout['at']??'')):null;
  $viaOk=is_array($via)&&(int)($via['http']??0)===200;
  $viaTekst=is_array($via)?'Laatste opvraag '.docsAgo($viaAt).' '.(($via['via']??'')==='koyeb'?'via de eigen Koyeb-dienst (reserve)':(($via['via']??'')==='adsb.fi'?'bij adsb.fi (reserve)':'rechtstreeks bij adsb.lol')).' · HTTP '.(int)($via['http']??0).'. ':'';
  $out['bronnen'][]=['id'=>'adsb','naam'=>'Helikopters & luchtvaart (adsb.lol)','icoon'=>'/images/website/heli_lifeliner.png',
    'staat'=>$lucht&&$viaOk&&$viaAt&&$now-$viaAt<180000?'live':($lucht&&$viaAt&&$now-$viaAt<180000?'fout':($lucht?'deels':'gepland')),'laatst'=>$viaAt?:($lucht?(max($snap??0,$heliPos)?:null):null),
    'detail'=>$viaTekst.'adsb.lol staat per adres nog maar 500 opvragen per dag toe; daarna (HTTP 429) wacht de server tot de teller terugspringt (hoogstens een uur) en komen de posities van adsb.fi. Lukt dat ook niet, dan via de eigen Koyeb-dienst. Laatste vastgelegde positie: '.docsAgo($heliPos?:null).'.'
      .(is_array($fout)&&$foutAt&&(!$viaOk||$foutAt>($viaAt??0))?' Laatste fout: '.(string)($fout['fout']??'').' ('.docsAgo($foutAt).').':'')];
  // --- traffic
  $vk=docsKvAt($db,'verkeer');
  $out['bronnen'][]=['id'=>'verkeer','naam'=>'Verkeer (NDW, flitsers, werkzaamheden)','icoon'=>'/images/website/verkeer_file.png',
    'staat'=>!docsSet('VERKEER_URL')?'gepland':($vk&&$now-$vk<900000&&docsModule('routes-lucht.php')?'live':(docsModule('routes-lucht.php')?'deels':'gepland')),'laatst'=>docsModule('routes-lucht.php')?$vk:null,
    'detail'=>(docsSet('VERKEER_URL')?'Eigen cron (elke minuut) haalt de verkeerslijst op bij de eigen Koyeb-dienst (NDW open data en vaste flitsers). ':'Bron nog niet ingesteld. ')
      .'Laatst bijgewerkt: '.docsAgo($vk).'; NDW laatst gelezen: '.docsAgo(docsMsOf(docsKv($db,'verkeer_vers'))).'.'];
  // --- Apple services and push
  $apple=docsModule('routes-apple.php');
  $wAt=$aa?(int)(docsScalar($db,'SELECT MAX(fetched_at) FROM weather')??0):0; $rAt=$aa?(int)(docsScalar($db,'SELECT MAX(at) FROM melding_routes')??0):0;
  $out['bronnen'][]=['id'=>'apple','naam'=>'Apple-diensten (WeatherKit, Maps, MapKit JS)','icoon'=>'/images/website/route_applemaps.png',
    // Own activity: weather or routes fetched after the copy of the Worker database was made.
    'staat'=>$apple&&max($wAt,$rAt)>(docsMsOf($d1['vol_klaar']??null)??PHP_INT_MAX)?'live':($apple?'deels':'gepland'),'laatst'=>max($wAt,$rAt)?:null,
    'detail'=>'Sleutels '.(docsSet('WEB_WEATHERKIT_P8')&&docsSet('MAPS_SERVER_API_P8')&&docsSet('WEB_MAPKIT_JS_P8')?'aanwezig':'nog niet compleet').'. '.($apple?'Eigen routes staan op de server.':'Eigen routes nog in aanbouw.')];
  $pm=setting('PUSH_ACTIEF'); $push=$pm==='1'||$pm==='eigen';
  $nEigen=0; try{ $nEigen=(int)$db->query('SELECT COUNT(*) FROM devices WHERE eigen = 1 AND enabled = 1')->fetchColumn(); }catch(Throwable){}
  $pushAt=null; try{ $pushAt=(int)$db->query('SELECT MAX(bucket) FROM status_push')->fetchColumn()?:null; }catch(Throwable){}
  $out['bronnen'][]=['id'=>'push','naam'=>'Pushmeldingen (APNs, FCM)','icoon'=>'/images/website/aalerts-icon-180.png',
    'staat'=>$push?'live':(docsModule('push.php')?'uit':'gepland'),'laatst'=>$pushAt,
    'detail'=>($pm==='1'?'Versturen staat aan voor alle toestellen.':($pm==='eigen'?"Versturen naar toestellen die zich zelf bij deze API hebben aangemeld (nu $nEigen): de Apple-apps vanaf 2.0.2 (build 106) en Android vanaf 2.0.2. Alle soorten: P2000, NL-Alert, Burgernet, KNMI, AMBER Alert, Vermist Kind Alert, VNOG en politienieuws. Oudere app-versies krijgen hun meldingen nog van de Worker; een nieuwe app meldt zich daar af, dus niets dubbel.":'Versturen staat uit: de Worker verstuurt nu, anders krijgt iedereen alles dubbel.')).' Sleutels '.(docsSet('APN_P8_CERT')&&docsSet('FCM_SERVICE_ACCOUNT')?'aanwezig':'nog niet compleet').'.'];

  $staat=function(string $id)use(&$out){ foreach($out['bronnen'] as $b)if($b['id']===$id)return $b['staat']; return null; };
  // --- building blocks of the migration
  $schema=docsKv($db,'aa_schema')!==null;
  $out['onderdelen']=[
    ['naam'=>'Eigen database (MariaDB, zelfde tabellen als de Worker)','staat'=>$schema?'live':'gepland','uitleg'=>$schema?'Schema geïnstalleerd.':'Nog niet geïnstalleerd.'],
    ['naam'=>'P2000-inname zonder Cloudflare','staat'=>$p2State,'uitleg'=>'Eigen WebSocket naar Koyeb, elke minuut via cron, met plaatsfilter, ontdubbeling en incidentkoppeling.'],
    ['naam'=>'Meldingen, waarschuwingen, nieuws en vaste routes','staat'=>docsModule('meldingen.php')&&docsModule('waarschuwingen.php')&&docsModule('nieuws.php')?'live':'deels','uitleg'=>'Leesroutes draaien in de eigen motor en worden met de vergelijkingsworkflow tegen workers.dev gecontroleerd.'],
    ['naam'=>'Eigen inname waarschuwingen en nieuws','staat'=>$eigenBronnen?'live':($proefRecent?'deels':'gepland'),'uitleg'=>$eigenBronnen?'Eigen inname schrijft zelf (NL-Alert, Burgernet, KNMI, Amber/Vermist Kind, VNOG, politie.nl, 112nieuws).':($proefRecent?'Draait als proef: leest alle bronnen en vergelijkt met de huidige gegevens, schrijft nog niets. Gegevens komen nog via de overname uit de Worker.':'Nu via de overname uit de Worker-database; eigen inname volgt.')],
    ['naam'=>'Helikopters, luchtvaart, verkeer en radar','staat'=>$staat('adsb')==='live'&&$staat('verkeer')==='live'?'live':($lucht?'deels':'gepland'),
      'uitleg'=>$lucht?'Module staat op de server. Live zodra zowel de eigen vliegtuigdata als het eigen verkeer recent binnenkomen (zie hierboven).':'In aanbouw.'],
    ['naam'=>'Apple-diensten, toestellen, statistieken en beheer','staat'=>$staat('apple')==='live'?'live':($apple?'deels':'gepland'),
      'uitleg'=>$apple?'Module staat op de server. Live zodra de eigen motor zelf weer of routes heeft opgehaald.':'In aanbouw.'],
    ['naam'=>'Pushmeldingen','staat'=>$push?'live':(docsModule('push.php')?'uit':'gepland'),'uitleg'=>'Alle pushmeldingen (P2000, waarschuwingen, nieuws, helikopters en beheer) gaan via deze server.'],
    ['naam'=>'Overstap van de apps en site naar api.a-alerts.nl','staat'=>setting('PUSH_ACTIEF')==='1'?'live':'gepland','uitleg'=>'Sinds 21-11-2026: alles draait hier; de oude Worker geeft alleen verzoeken van oude appversies door.'],
  ];
  // --- settings present (names only)
  foreach(['P2000_PAGE_API_URL'=>'P2000.page','P2000_CODE'=>'Koyeb-aanmelding (website)','KOYEB_SUBPROTOCOL'=>'Koyeb-reserve (Homey)','VERKEER_URL'=>'Verkeer','ADSB_LOL_API_KEY'=>'adsb.lol','KNMI_WMS_KEY'=>'KNMI radar',
    'APN_P8_CERT'=>'APNs','FCM_SERVICE_ACCOUNT'=>'FCM','WEB_WEATHERKIT_P8'=>'WeatherKit','MAPS_SERVER_API_P8'=>'Apple Maps','WEB_MAPKIT_JS_P8'=>'MapKit JS',
    'HOMEY_WEBHOOK_ID'=>'Homey','BEHEER_USER'=>'Beheer-login','CLOUDFLARE_API_TOKEN'=>'D1-overname'] as $k=>$label)$out['config'][]=['label'=>$label,'aanwezig'=>docsSet($k)];
  return $out;
}
