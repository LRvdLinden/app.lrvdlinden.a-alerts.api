<?php
declare(strict_types=1);
function docsHome(): string {
 // Apache can omit DOCUMENT_ROOT under some cPanel PHP handlers.
 // Derive the account home from verified PHP script paths, never from request data.
 foreach([
   (string)($_SERVER['DOCUMENT_ROOT']??''),
   (string)($_SERVER['SCRIPT_FILENAME']??''),
   (string)(realpath(__DIR__)?:''),
   (string)(realpath(dirname(__DIR__))?:'')
 ] as $candidate) {
   if(preg_match('~^(/home[^/]*/[^/]+)(?:/|$)~',$candidate,$m))return $m[1];
 }
 return '';
}
function docsSafe(string $value): string {
 return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
function docsRoute(string $path):void {
 if($path!=='/docs' && !str_starts_with($path,'/docs/'))return;
 $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
 if(!in_array($method,['GET','POST','HEAD'],true)){http_response_code(405);exit;}
 header('Cache-Control: no-store, private');
 header('X-Frame-Options: DENY');
 header('X-Content-Type-Options: nosniff');
 header('Referrer-Policy: no-referrer');
 header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self' https://a-alerts.nl data:; font-src 'self' https://a-alerts.nl; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
 header('X-Robots-Tag: noindex, nofollow, noarchive');
 if(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'){
   header('Strict-Transport-Security: max-age=31536000');
 }
 $home=docsHome();
 $authfile=$home!==''?$home.'/aalerts-private/docs-auth.php':'';
 // On hosts with PHP open_basedir restrictions, the protected _core directory
 // provides a server-only fallback (never tracked in Git or served over HTTP).
 $fallback=__DIR__.'/docs-auth.local.php';
 clearstatcache(true);
 $auth=[];
 if($authfile!==''&&is_readable($authfile)) $auth=require $authfile;
 if((!is_array($auth)||empty($auth['password_hash'])) && is_readable($fallback))
   $auth=require $fallback;
 $configured=is_array($auth)&&strlen((string)($auth['password_hash']??''))>40&&
             strlen((string)($auth['username']??''))>=3;
 if(!$configured){
   error_log('A-Alerts docs auth unavailable: home=' . ($home!==''?'resolved':'unknown') . ' file=' . ($authfile!==''&&is_readable($authfile)?'readable':'not-readable') . ' fallback=' . (is_readable($fallback)?'readable':'not-readable') . ' hash=' . (is_array($auth)&&!empty($auth['password_hash'])?'present':'missing'));
   http_response_code(503);header('Content-Type: text/plain; charset=utf-8');echo 'A-Alerts Docs: account nog niet geconfigureerd.';exit;
 }
 ini_set('session.use_strict_mode','1');
 ini_set('session.use_only_cookies','1');
 ini_set('session.cookie_httponly','1');
 ini_set('session.cookie_secure','1');
 ini_set('session.cookie_samesite','Strict');
 session_name('AALDOCS');
 session_set_cookie_params(['lifetime'=>0,'path'=>'/docs','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
 session_start();
 if(empty($_SESSION['docs_csrf']))$_SESSION['docs_csrf']=bin2hex(random_bytes(24));
 $error='';
 if($path==='/docs/logout' && $method==='POST'){
   if(!hash_equals((string)$_SESSION['docs_csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit;}
   $_SESSION=[];session_regenerate_id(true);header('Location: /docs',true,303);exit;
 }
 if($path==='/docs/login' && $method==='POST'){
   if(!hash_equals((string)$_SESSION['docs_csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit;}
   $locked=(int)($_SESSION['docs_lock_until']??0)>time();
   if($locked){http_response_code(429);$error='Te veel pogingen. Probeer het later opnieuw.';}
   else{
     $user=trim((string)($_POST['username']??''));
     $pass=(string)($_POST['password']??'');
     $valid=hash_equals((string)$auth['username'],$user)&&password_verify($pass,(string)$auth['password_hash']);
     if($valid){
       session_regenerate_id(true);
       $_SESSION['docs_user']=$user;$_SESSION['docs_at']=time();
       $_SESSION['docs_attempts']=0;$_SESSION['docs_lock_until']=0;
       header('Location: /docs',true,303);exit;
     }
     $_SESSION['docs_attempts']=(int)($_SESSION['docs_attempts']??0)+1;
     if($_SESSION['docs_attempts']>=5){$_SESSION['docs_lock_until']=time()+900;$_SESSION['docs_attempts']=0;}
     $error='Gebruikersnaam of wachtwoord onjuist.';
   }
 }
 $logged=isset($_SESSION['docs_user'])&&hash_equals((string)$auth['username'],(string)$_SESSION['docs_user'])
  &&time()-(int)($_SESSION['docs_at']??0)<14400;
 if(!$logged){unset($_SESSION['docs_user']);docsRenderLogin($error,(string)$_SESSION['docs_csrf']);}
 $_SESSION['docs_at']=time();
 $routes=require __DIR__.'/docs-catalog.php';
 require_once __DIR__.'/docs-live.php';
 $sources=require __DIR__.'/docs-sources.php';
 require_once __DIR__.'/docs-provenance.php';
 require_once __DIR__.'/docs-status.php';
 $groups=array_values(array_unique(array_column($routes,'group')));
 $requested=trim((string)($_GET['route']??''));
 $selected=null;foreach($routes as $route){if($route['method'].' '.$route['path']===$requested){$selected=$route;break;}}
 if(!$selected)$selected=$routes[0];
 $live=null;
 if($path==='/docs/preview'){
   if($method!=='POST'){http_response_code(405);exit;}
   if(!hash_equals((string)$_SESSION['docs_csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit;}
   $selection=(string)($_POST['route']??'');
   if(strlen($selection)>200){http_response_code(400);exit;}
   try {
     [$liveRoute,$livePath]=docsLiveEndpoint($routes,$selection,(string)($_POST['record_id']??''),(string)($_POST['limit']??'20'));
     $selected=$liveRoute;
     $live=docsLiveFetch($livePath);
   }catch(InvalidArgumentException $exception){
     $live=['status'=>400,'error'=>$exception->getMessage()];
   }
 }
 docsRenderPage($routes,$groups,$selected,(string)$_SESSION['docs_csrf'],$sources,!isset($_GET['route'])&&$live===null,$live);
}
function docsHeader(string $title):void{
 header('Content-Type: text/html; charset=utf-8');
 echo '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
 echo '<meta name="robots" content="noindex,nofollow"><title>'.docsSafe($title).' — A-Alerts Developer</title>';
 echo '<link rel="icon" href="/images/website/aalerts-icon-32.png"><link rel="apple-touch-icon" href="/images/website/aalerts-icon-180.png"><meta name="theme-color" content="#cf0a1e"><link rel="stylesheet" href="/docs/style.css?v=3"><link rel="stylesheet" href="/docs/brand.css?v=5"><link rel="stylesheet" href="/docs/status.css?v=2"></head><body>';
}
function docsBrand():void{
 echo '<a class="brand" href="/docs/" aria-label="A-Alerts API-documentatie">';
 echo '<span class="brand-logo"><img src="/images/website/aalerts-icon-180.png" width="44" height="44" alt="A-Alerts logo"></span>';
 echo '<span class="brand-copy"><strong>A-Alerts</strong><small>API DOCUMENTATIE</small></span></a>';
}
function docsRenderLogin(string $error,string $csrf):never{
 docsHeader('Inloggen');
 echo '<div class="login-page"><section class="login-card">';
 docsBrand();
 echo '<p class="eyebrow">PRIVATE DEVELOPER PORTAL</p><h1>Welkom terug.</h1>';
 echo '<p class="subcopy">Log in om de beveiligde API-referentie van A-Alerts te bekijken.</p>';
 if($error!=='')echo '<div class="login-error" role="alert">'.docsSafe($error).'</div>';
 echo '<form action="/docs/login" method="post" autocomplete="on"><input type="hidden" name="csrf" value="'.docsSafe($csrf).'">';
 echo '<label for="u">Gebruikersnaam</label><input id="u" name="username" autocomplete="username" required maxlength="100" autofocus>';
 echo '<label for="p">Wachtwoord</label><input id="p" type="password" name="password" autocomplete="current-password" required>';
 echo '<button class="primary" type="submit">Inloggen <span aria-hidden="true">→</span></button></form>';
 echo '<p class="login-foot">Alleen toegankelijk voor geautoriseerde beheerders. Je aanmeldgegevens worden niet in GitHub opgeslagen.</p>';
 echo '</section><aside class="login-art"><div class="hero-tex" aria-hidden="true"></div><div class="hero-stripes" aria-hidden="true"></div><div class="pulse" aria-hidden="true"><i></i><i></i><i></i><i></i></div><div class="login-art-inner"><span class="live-pill"><span class="live-dot"></span> A-ALERTS DEVELOPER</span><img class="login-art-logo" src="/images/website/aalerts-icon-512.png" alt="" width="148" height="148"><h2>Elke melding.<br><span>Direct</span> live.</h2><p>De complete A-Alerts API.<br>Alle bronnen. Eén platform.</p><div class="hero-status"><span class="live-dot"></span> Privé toegang · api.a-alerts.nl</div></div></aside></div></body></html>';exit;
}
function docsRenderPage(array $routes,array $groups,array $selected,string $csrf,array $sources,bool $overview=false,?array $live=null):never{
 $routeKey=$selected['method'].' '.$selected['path'];
 docsHeader('API Referentie');
 echo '<div class="app-shell"><aside class="sidebar" id="docs-nav">';
 docsBrand();
 echo '<div class="side-label">API DOCUMENTATIE</div><p class="nav-legend"><span class="nav-state nav-state-eigen"></span> eigen motor <span class="nav-state nav-state-planned"></span> nog niet</p><nav aria-label="API onderdelen"><a class="side-home" href="/docs">Stand & overzicht <span>↗</span></a>';
 foreach($groups as $group){
  echo '<div class="nav-group"><h3>'.docsSafe($group).'</h3>';
  foreach($routes as $item){
   if($item['group']!==$group)continue;
   $key=$item['method'].' '.$item['path'];
   $href='/docs?route='.rawurlencode($key);
   $active=$routeKey===$key?' active':'';
   $eng=docsRouteInEngine($item);
   echo '<a class="endpoint-nav'.$active.'" href="'.docsSafe($href).'"><span class="mini-method">'.docsSafe($item['method']).'</span><span>'.docsSafe($item['title']).'</span><span class="nav-state nav-state-'.($eng?'eigen':docsSafe($item['status'])).'" title="'.($eng?'Draait in de eigen motor':'Nog niet in de eigen motor').'" aria-label="'.($eng?'Eigen motor':'Nog niet in de eigen motor').'"></span></a>';
  }
  echo '</div>';
 }
 echo '</nav></aside><main class="main"><div class="topbar"><div class="topbar-leading"><a href="#docs-nav" class="mobile-nav-link" aria-label="Ga naar endpointnavigatie">☰ <span>Endpoints</span></a><span><span class="status-dot"></span> API-v1 <span class="crumb">/ Documentatie</span></span></div>';
 echo '<form method="post" action="/docs/logout"><input type="hidden" name="csrf" value="'.docsSafe($csrf).'"><button class="logout">Uitloggen ↗</button></form></div>';
 echo '<div class="body-content"><p class="eyebrow">A-ALERTS / API REFERENCE</p><h1>Developer Documentation<span class="red-period">.</span></h1>';
 echo '<p class="intro">De eigen A-Alerts API op api.a-alerts.nl, los van Cloudflare. Hier zie je live wat er al zelfstandig draait, wat er binnenkomt en per route of hij in de eigen motor zit. Sinds 21 november 2026 is dit de enige server: de oude Worker op workers.dev geeft alleen nog verzoeken van oude appversies door naar hier. Sinds 22 november 2026 ook: hectometerpalen in heel Nederland (veld hm), statistieken (/v1/statistiek), afkortingen (/v1/afkortingen), meldingen per plaats of eenheid en een deelafbeelding per melding; de apps gebruiken die vanaf versie 2.0.3. De pagina\'s van a-alerts.nl (/melding, /plaats, /eenheid, /post, /statistieken, /afkortingen en /sitemap-p2000.xml) worden op a-alerts.nl in PHP gemaakt uit deze openbare routes, met de inhoud direct in de HTML; ambulancemeldingen staan niet in Google (noindex, niet in de sitemap) en in lijsten zonder straat of huisnummer.</p>';
 $inEngine=count(array_filter($routes,'docsRouteInEngine'));
 echo '<div class="overview-stats"><div><strong>'.count($routes).'</strong><span>Routes in totaal</span></div><div><strong>'.$inEngine.'</strong><span>In de eigen motor</span></div><div><strong>'.(count($routes)-$inEngine).'</strong><span>Nog te bouwen</span></div></div>';
 if($overview){docsRenderLiveStatus($routes);docsRenderOverview($sources);}
 echo '<div class="api-divider"></div>';
 $engine=docsRouteInEngine($selected);
 echo '<div class="endpoint-head"><span class="verb verb-'.strtolower($selected['method']).'">'.docsSafe($selected['method']).'</span>';
 echo $engine?'<span class="impl impl-supported">Eigen motor · draait op deze server</span>':'<span class="impl impl-'.docsSafe($selected['status']).'">'.(['supported'=>'Beschikbaar','partial'=>'Nog via oude keten','planned'=>'Nog niet gebouwd'][$selected['status']]??'Onbekend').'</span>';
 echo '</div>';
 echo '<h2>'.docsSafe($selected['title']).'</h2><p class="endpoint-desc">'.docsSafe($selected['description']).'</p>';
 docsRenderEndpointSources($selected,$sources);
 docsRenderLiveViewer($selected,$csrf,$live);
 echo '<div class="path-bar"><code>'.docsSafe($selected['method'].' '.($selected['host']??'https://api.a-alerts.nl').$selected['path']).'</code></div>';
 $private=docsRoutePrivate($selected);
 echo '<section class="section"><h3>Authenticatie</h3><p>'.($private?'Deze route vereist <code>Authorization: Bearer &lt;API_TOKEN&gt;</code>'.(str_starts_with($selected['path'],'/v1/beheer/')?' met beheerrechten':'').'.':'Openbare route, net als op workers.dev: geen token nodig.').' De docs-login is een apart account en geeft zelf geen API-toegang.</p></section>';
 if(!empty($selected['query'])){echo '<section class="section"><h3>Queryparameters</h3><table><thead><tr><th>Naam</th><th>Betekenis</th></tr></thead><tbody>';
 foreach($selected['query'] as $key=>$meaning)echo '<tr><td><code>'.docsSafe($key).'</code></td><td>'.docsSafe($meaning).'</td></tr>';
 echo '</tbody></table></section>';}
 $samplePath=preg_replace('/\{[^}]+\}/','voorbeeld-id',$selected['path']);
 $method=$selected['method'];$query=empty($selected['query'])?'':'?limit=20';
 $curl="curl -i -X ".$method." \\\n".($private?"  -H 'Authorization: Bearer <API_TOKEN>' \\\n":'')."  -H 'Accept: ".(isset($selected['host'])?'text/html':'application/json')."' \\\n  '".($selected['host']??'https://api.a-alerts.nl').$samplePath.$query."'";
 echo '<section class="section"><h3>Voorbeeldrequest</h3><pre><code>'.docsSafe($curl).'</code></pre></section>';
 $example=$selected['example']??['error'=>$selected['status']==='planned'?'feature_not_yet_available':'example_response','note'=>'Illustratief; contract nog niet gevalideerd'];
 echo '<section class="section"><h3>Voorbeeldresponse</h3><pre><code>'.docsSafe(json_encode($example,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</code></pre></section>';
 echo '<section class="section"><h3>HTTP-responsen</h3><div class="response-list"><div><code>200</code> Geslaagd — indien geïmplementeerd en ingericht</div><div><code>401</code> Ontbrekend of ongeldig Bearer-token</div><div><code>403</code> Onvoldoende rechten</div><div><code>404</code> Record niet gevonden</div><div><code>503</code> Bron of onderdeel niet beschikbaar</div></div></section>';
 echo '<p class="disclaimer">“Eigen motor” betekent dat de route op deze server in PHP/MariaDB draait. Een voorbeeldresponse is geen live-resultaat; gebruik daarvoor de live-viewer.</p>';
 echo '</div><footer>© A-Alerts · Private Developer Portal · <a href="https://a-alerts.nl">a-alerts.nl</a></footer></main></div></body></html>';exit;
}

function docsStateLabel(string $s): string {
 return ['live'=>'Zelfstandig · live','overname'=>'Via overname Worker','deels'=>'Deels gebouwd','gepland'=>'Nog te bouwen','fout'=>'Storing','uit'=>'Bewust uit'][$s]??$s;
}
function docsRoutePrivate(array $r): bool {
 $p=(string)$r['path'];
 return str_starts_with($p,'/v1/beheer/')||in_array($p,['/v1/eigen','/v1/bronnen','/v1/vergelijk','/v1/spiegel','/v1/spiegel/{route}'],true);
}
function docsRenderLiveStatus(array $routes):void {
 $st=docsLiveState();
 echo '<section class="live-state" id="stand"><div class="docs-section-heading"><span class="eyebrow">LIVE · STAND VAN DE EIGEN API</span><h2>Wat draait er al zelfstandig?</h2>';
 echo '<p>Alles hieronder komt rechtstreeks uit de database en de bestanden op deze server, bij elk bezoek opnieuw. Iets staat alleen op “Zelfstandig · live” als er een recent teken van leven is.</p></div>';
 if(!$st['db']){echo '<p class="state-error" role="alert">'.docsSafe((string)$st['fout']).'</p></section>';return;}
 echo '<div class="state-steps">';
 foreach($st['onderdelen'] as $i=>$o){
  echo '<div class="state-step state-'.docsSafe($o['staat']).'"><span class="state-num">'.($i+1).'</span><div><strong>'.docsSafe($o['naam']).'</strong><p>'.docsSafe($o['uitleg']).'</p></div><span class="state-pill state-pill-'.docsSafe($o['staat']).'">'.docsSafe(docsStateLabel($o['staat'])).'</span></div>';
 }
 echo '</div>';
 echo '<div class="docs-section-heading"><span class="eyebrow">LIVE · INNAME</span><h2>Wat komt er binnen?</h2><p>Per bron: de laatste ontvangst en waar de gegevens nu vandaan komen.</p></div><div class="state-sources">';
 foreach($st['bronnen'] as $b){
  echo '<article class="state-source state-'.docsSafe($b['staat']).'"><img src="'.docsSafe($b['icoon']).'" width="40" height="40" alt="" loading="lazy"><div class="state-source-body"><div class="state-source-head"><strong>'.docsSafe($b['naam']).'</strong>';
  echo '<span class="state-pill state-pill-'.docsSafe($b['staat']).'">'.docsSafe(docsStateLabel($b['staat'])).'</span></div>';
  echo '<p>'.docsSafe($b['detail']).'</p><small>Laatste activiteit: '.docsSafe(docsAgo($b['laatst'])).'</small></div></article>';
 }
 echo '</div>';
 echo '<div class="state-columns"><div><div class="docs-section-heading"><span class="eyebrow">LIVE · DATABASE</span><h2>Opgeslagen gegevens</h2></div><table class="state-table"><thead><tr><th>Gegevens</th><th>Aantal</th></tr></thead><tbody>';
 foreach($st['tabellen'] as $t)echo '<tr><td>'.docsSafe($t['label']).' <code>'.docsSafe($t['tabel']).'</code></td><td>'.($t['aantal']===null?'—':number_format($t['aantal'],0,',','.')).'</td></tr>';
 echo '</tbody></table></div><div><div class="docs-section-heading"><span class="eyebrow">LIVE · CONFIGURATIE</span><h2>Sleutels aanwezig</h2></div><p class="state-note">Alleen of een sleutel is ingesteld; waarden worden nooit getoond.</p><ul class="state-config">';
 foreach($st['config'] as $c)echo '<li class="'.($c['aanwezig']?'is-set':'is-missing').'"><span aria-hidden="true">'.($c['aanwezig']?'✓':'–').'</span> '.docsSafe($c['label']).'<span class="sr-only">'.($c['aanwezig']?' ingesteld':' ontbreekt').'</span></li>';
 echo '</ul></div></div>';
 $groups=[];foreach($routes as $r){$g=$r['group'];$groups[$g]??=['n'=>0,'eigen'=>0];$groups[$g]['n']++;if(docsRouteInEngine($r))$groups[$g]['eigen']++;}
 echo '<div class="docs-section-heading"><span class="eyebrow">LIVE · ROUTES</span><h2>Routes per onderdeel</h2><p>Hoeveel routes van de Worker al in de eigen motor op deze server zitten.</p></div><div class="state-groups">';
 foreach($groups as $g=>$c){$pct=$c['n']?round($c['eigen']/$c['n']*100):0;
  echo '<div class="state-group"><div class="state-group-head"><strong>'.docsSafe($g).'</strong><span>'.$c['eigen'].' / '.$c['n'].'</span></div><progress class="state-bar" max="'.max(1,$c['n']).'" value="'.$c['eigen'].'" aria-label="'.$pct.' procent in de eigen motor">'.$pct.'%</progress></div>';}
 echo '</div></section>';
}
/** Live state for a source of the herkomst catalog (same data as the overview), or null when there is no live check. */
function docsSourceLive(string $key): ?array {
 static $st=null; if($st===null){ try{ $st=docsLiveState(); }catch(Throwable){ $st=['bronnen'=>[]]; } }
 $map=['p2000'=>'p2000page','koyeb_p2000'=>'p2000','worker'=>'spiegel','nlalert'=>'nlalert','burgernet'=>'burgernet','amber'=>'amber','knmi'=>'knmi','vnog'=>'vnog',
  'politie'=>'nieuws-politie','nieuws112'=>'nieuws-regionaal','adsb'=>'adsb','ndw'=>'verkeer','weatherkit'=>'apple','applemaps'=>'apple','mapkit'=>'apple','apns'=>'push','fcm'=>'push'];
 $id=$map[$key]??null; if(!$id)return null;
 foreach($st['bronnen']??[] as $b)if($b['id']===$id)return $b;
 return null;
}
function docsSourceStatusHtml(string $key,array $item): string {
 $b=docsSourceLive($key);
 if(!$b)return docsSafe((string)$item['status']);
 return '<span class="state-pill state-pill-'.docsSafe($b['staat']).'">'.docsSafe(docsStateLabel($b['staat'])).'</span><br><span>'.docsSafe($b['detail']).'</span>';
}
function docsRenderOverview(array $sources):void {
 echo '<section class="source-overview" id="bronnen"><div class="docs-section-heading"><span class="eyebrow">GEGEVENSHERKOMST</span><h2>Herkomst van alle gegevens.</h2>';
 echo '<p>Waar elk soort gegeven oorspronkelijk vandaan komt. De live stand per bron staat hierboven onder “Wat komt er binnen?”.</p></div>';
 echo '<div class="source-table-wrap"><table class="source-table"><thead><tr><th>Bron of dienst</th><th>Waarvoor gebruikt A-Alerts deze?</th><th>Toelichting</th></tr></thead><tbody>';
 foreach($sources as $key=>$item){
  $image=(string)($item['image']??'');
  echo '<tr><td><div class="source-cell">';
  if($image!=='')echo '<img class="source-thumbnail" src="'.docsSafe($image).'" width="42" height="42" loading="lazy" alt="">';
  echo '<div><strong>'.docsSafe((string)$item['name']).'</strong><small>'.docsSafe((string)$item['type']).'</small>';
  if(!empty($item['url']))echo '<a href="'.docsSafe((string)$item['url']).'" target="_blank" rel="noopener noreferrer">Bronwebsite ↗</a>';
  echo '</div></div></td><td>'.docsSafe((string)$item['role']).'</td><td class="source-status">'.docsSourceStatusHtml((string)$key,$item).'</td></tr>';
 }
 echo '</tbody></table></div>';
 echo '<p class="source-note">Alles draait via api.a-alerts.nl; de Cloudflare Worker geeft alleen nog verzoeken van oude appversies door.</p></section>';
}
function docsRenderEndpointSources(array $endpoint,array $sources):void {
 $refs=docsSourceIds($endpoint);
 echo '<section class="endpoint-provenance"><div class="source-title"><span class="eyebrow">HERKOMST & VERWERKING</span><h3>Welke bronnen gebruikt dit endpoint?</h3></div>';
 echo '<div class="endpoint-source-grid">';
 foreach($refs as $key){
   if(!isset($sources[$key]))continue;
   $item=$sources[$key];$img=(string)($item['image']??'');
   echo '<div class="endpoint-source-card">';
   if($img!=='')echo '<img src="'.docsSafe($img).'" width="42" height="42" loading="lazy" alt="">';
   echo '<div><strong>'.docsSafe((string)$item['name']).'</strong><p>'.docsSafe((string)$item['role']).'</p>';
   echo '<small>'.docsSourceStatusHtml((string)$key,$item).'</small>';
   if(!empty($item['url']))echo '<a href="'.docsSafe((string)$item['url']).'" target="_blank" rel="noopener noreferrer">Externe bron ↗</a>';
   echo '</div></div>';
 }
 echo '</div><p class="source-note">De herkomst van de gegevens; de live stand per bron staat op de overzichtspagina.</p>';
 $media=docsMediaFor($endpoint);
 if($media){
  echo '<h4>Bijbehorende A-Alerts-afbeeldingen</h4><div class="endpoint-media">';
  foreach($media as $image){
    echo '<figure><img src="'.docsSafe($image['file']).'" alt="'.docsSafe($image['label']).'" loading="lazy" width="72" height="72">';
    echo '<figcaption>'.docsSafe($image['label']).'</figcaption></figure>';
  }
  echo '</div>';
 }
 echo '</section>';
}

function docsRenderLiveViewer(array $selected,string $csrf,?array $live):void{
  $key=$selected['method'].' '.$selected['path'];
  $eligible=$selected['method']==='GET'&&str_starts_with($selected['path'],'/v1/')&&!str_starts_with($selected['path'],'/v1/beheer/');
  echo '<section class="docs-live" id="live-json"><div class="docs-live-heading"><div><span class="eyebrow">LIVE API / READ-ONLY</span><h3>Bekijk echte JSON</h3>';
  echo '<p>De aanvraag gaat vanaf de server naar api.a-alerts.nl; een eventueel token verschijnt nooit in je browser of URL.</p></div></div>';
  if($eligible){
    echo '<form class="docs-live-form" method="post" action="/docs/preview#live-json">';
    echo '<input type="hidden" name="csrf" value="'.docsSafe($csrf).'"><input type="hidden" name="route" value="'.docsSafe($key).'">';
    if(str_contains($selected['path'],'{')){
      echo '<label for="docs-record">Record- of toestel-ID<input id="docs-record" name="record_id" maxlength="80" required placeholder="Bijv. een ID uit de lijst" autocomplete="off"></label>';
    }
    if(isset($selected['query']['limit'])){
      echo '<label for="docs-limit">Maximaal aantal<select id="docs-limit" name="limit"><option value="10">10</option><option value="20" selected>20</option><option value="50">50</option><option value="100">100</option></select></label>';
    }
    echo '<button class="docs-live-button" type="submit">Bekijk live JSON ↗</button></form>';
  }else{
    echo '<p class="docs-live-unavailable">Alleen gedocumenteerde lees-endpoints (GET) zijn beschikbaar in deze viewer. Wijzigingen en beheerroutes zijn uitgesloten.</p>';
  }
  if($live!==null){
    $status=(int)($live['status']??0);
    $kind=$status>=200&&$status<300?'success':'failure';
    echo '<div class="docs-live-result"><div class="docs-live-result-head"><strong>HTTP '.docsSafe((string)$status).'</strong>';
    echo '<span class="docs-live-result-'.$kind.'">'.($kind==='success'?'Antwoord ontvangen':'Niet gelukt').'</span></div>';
    if(isset($live['path']))echo '<div class="docs-live-path">GET '.docsSafe((string)$live['path']).'</div>';
    if(isset($live['error']))echo '<p role="alert">'.docsSafe((string)$live['error']).'</p>';
    else echo '<pre><code>'.docsSafe((string)($live['body']??'')).'</code></pre>';
    echo '</div>';
  }
  echo '</section>';
}
