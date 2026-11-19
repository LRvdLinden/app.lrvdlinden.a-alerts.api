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
 $sources=require __DIR__.'/docs-sources.php';
 require_once __DIR__.'/docs-provenance.php';
 $groups=array_values(array_unique(array_column($routes,'group')));
 $requested=trim((string)($_GET['route']??''));
 $selected=null;foreach($routes as $route){if($route['method'].' '.$route['path']===$requested){$selected=$route;break;}}
 if(!$selected)$selected=$routes[0];
 docsRenderPage($routes,$groups,$selected,(string)$_SESSION['docs_csrf'],$sources,!isset($_GET['route']));
}
function docsHeader(string $title):void{
 header('Content-Type: text/html; charset=utf-8');
 echo '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
 echo '<meta name="robots" content="noindex,nofollow"><title>'.docsSafe($title).' — A-Alerts Developer</title>';
 echo '<link rel="icon" href="/images/website/aalerts-icon-32.png"><link rel="apple-touch-icon" href="/images/website/aalerts-icon-180.png"><meta name="theme-color" content="#cf0a1e"><link rel="stylesheet" href="/docs/style.css?v=3"><link rel="stylesheet" href="/docs/brand.css?v=5"></head><body>';
}
function docsBrand():void{
 echo '<a class="brand" href="/docs/" aria-label="A-Alerts API-documentatie">';
 echo '<span class="brand-logo"><img src="https://a-alerts.nl/assets/img/aalerts-icon-180.png" width="44" height="44" alt="A-Alerts logo"></span>';
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
function docsRenderPage(array $routes,array $groups,array $selected,string $csrf,array $sources,bool $overview=false):never{
 $routeKey=$selected['method'].' '.$selected['path'];
 docsHeader('API Referentie');
 echo '<div class="app-shell"><aside class="sidebar" id="docs-nav">';
 docsBrand();
 echo '<div class="side-label">API DOCUMENTATIE</div><nav aria-label="API onderdelen"><a class="side-home" href="/docs">Bronnen & overzicht <span>↗</span></a>';
 foreach($groups as $group){
  echo '<div class="nav-group"><h3>'.docsSafe($group).'</h3>';
  foreach($routes as $item){
   if($item['group']!==$group)continue;
   $key=$item['method'].' '.$item['path'];
   $href='/docs?route='.rawurlencode($key);
   $active=$routeKey===$key?' active':'';
   echo '<a class="endpoint-nav'.$active.'" href="'.docsSafe($href).'"><span class="mini-method">'.docsSafe($item['method']).'</span><span>'.docsSafe($item['title']).'</span></a>';
  }
  echo '</div>';
 }
 echo '</nav></aside><main class="main"><div class="topbar"><div class="topbar-leading"><a href="#docs-nav" class="mobile-nav-link" aria-label="Ga naar endpointnavigatie">☰ <span>Endpoints</span></a><span><span class="status-dot"></span> API-v1 <span class="crumb">/ Documentatie</span></span></div>';
 echo '<form method="post" action="/docs/logout"><input type="hidden" name="csrf" value="'.docsSafe($csrf).'"><button class="logout">Uitloggen ↗</button></form></div>';
 echo '<div class="body-content"><p class="eyebrow">A-ALERTS / API REFERENCE</p><h1>Developer Documentation<span class="red-period">.</span></h1>';
 echo '<p class="intro">Ontdek alle endpoints, authenticatie, parameters en responses van de toekomstige zelfstandige A-Alerts API. De status toont wat nu werkt en wat nog migratie vereist.</p>';
 echo '<div class="overview-stats"><div><strong>'.count($routes).'</strong><span>Endpointvarianten</span></div><div><strong>'.count($groups).'</strong><span>API-onderdelen</span></div><div><strong>v1</strong><span>Parallelle API</span></div></div>';
 if($overview)docsRenderOverview($sources);
 echo '<div class="api-divider"></div>';
 echo '<div class="endpoint-head"><span class="verb verb-'.strtolower($selected['method']).'">'.docsSafe($selected['method']).'</span><span class="impl impl-'.docsSafe($selected['status']).'">'.(['supported'=>'Beschikbaar','partial'=>'Deels gebouwd','planned'=>'Nog niet beschikbaar'][$selected['status']]??'Onbekend').'</span></div>';
 echo '<h2>'.docsSafe($selected['title']).'</h2><p class="endpoint-desc">'.docsSafe($selected['description']).'</p>';
 docsRenderEndpointSources($selected,$sources);
 echo '<div class="path-bar"><code>'.docsSafe($selected['method'].' https://api.a-alerts.nl'.$selected['path']).'</code></div>';
 echo '<section class="section"><h3>Authenticatie</h3><p>Alle private endpoints vereisen <code>Authorization: Bearer &lt;API_TOKEN&gt;</code>. Beheerroutes vereisen daarnaast de juiste beheerdersrol. De docs-login is een afzonderlijk account en geeft zelf geen API-toegang.</p></section>';
 if(!empty($selected['query'])){echo '<section class="section"><h3>Queryparameters</h3><table><thead><tr><th>Naam</th><th>Betekenis</th></tr></thead><tbody>';
 foreach($selected['query'] as $key=>$meaning)echo '<tr><td><code>'.docsSafe($key).'</code></td><td>'.docsSafe($meaning).'</td></tr>';
 echo '</tbody></table></section>';}
 $samplePath=preg_replace('/\{[^}]+\}/','voorbeeld-id',$selected['path']);
 $method=$selected['method'];$query=empty($selected['query'])?'':'?limit=20';
 $curl="curl -i -X ".$method." \\\n  -H 'Authorization: Bearer <API_TOKEN>' \\\n  -H 'Accept: application/json' \\\n  'https://api.a-alerts.nl".$samplePath.$query."'";
 echo '<section class="section"><h3>Voorbeeldrequest</h3><pre><code>'.docsSafe($curl).'</code></pre></section>';
 $example=$selected['example']??['error'=>$selected['status']==='planned'?'feature_not_yet_available':'example_response','note'=>'Illustratief; contract nog niet gevalideerd'];
 echo '<section class="section"><h3>Voorbeeldresponse</h3><pre><code>'.docsSafe(json_encode($example,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</code></pre></section>';
 echo '<section class="section"><h3>HTTP-responsen</h3><div class="response-list"><div><code>200</code> Geslaagd — indien geïmplementeerd en ingericht</div><div><code>401</code> Ontbrekend of ongeldig Bearer-token</div><div><code>403</code> Onvoldoende rechten</div><div><code>404</code> Record niet gevonden</div><div><code>503</code> Bron of onderdeel niet beschikbaar</div></div></section>';
 echo '<p class="disclaimer">Let op: de catalogus beschrijft ook bestaande Worker-contracten die nog niet volledig naar PHP/MySQL zijn gemigreerd. Een voorbeeldresponse is geen live-resultaat.</p>';
 echo '</div><footer>© A-Alerts · Private Developer Portal · <a href="https://a-alerts.nl">a-alerts.nl</a></footer></main></div></body></html>';exit;
}

function docsRenderOverview(array $sources):void {
 echo '<section class="source-overview" id="bronnen"><div class="docs-section-heading"><span class="eyebrow">GEGEVENSHERKOMST</span><h2>Alle bronnen en systemen.</h2>';
 echo '<p>De herkomst van alle meldingen, waarschuwingen, nieuwsberichten, luchtvaart, kaarten en notificaties. De vermelde bron is niet automatisch al actief in de eigen PHP-API.</p></div>';
 echo '<div class="source-table-wrap"><table class="source-table"><thead><tr><th>Bron of dienst</th><th>Waarvoor gebruikt A-Alerts deze?</th><th>PHP-migratiestatus</th></tr></thead><tbody>';
 foreach($sources as $item){
  $image=(string)($item['image']??'');
  echo '<tr><td><div class="source-cell">';
  if($image!=='')echo '<img class="source-thumbnail" src="'.docsSafe($image).'" width="42" height="42" loading="lazy" alt="">';
  echo '<div><strong>'.docsSafe((string)$item['name']).'</strong><small>'.docsSafe((string)$item['type']).'</small>';
  if(!empty($item['url']))echo '<a href="'.docsSafe((string)$item['url']).'" target="_blank" rel="noopener noreferrer">Bronwebsite ↗</a>';
  echo '</div></div></td><td>'.docsSafe((string)$item['role']).'</td><td class="source-status">'.docsSafe((string)$item['status']).'</td></tr>';
 }
 echo '</tbody></table></div>';
 echo '<p class="source-note">De bestaande Cloudflare Worker is de productie-referentie; de nieuwe PHP/MySQL-keten is nog niet voor alle bronnen functioneel gelijkwaardig.</p></section>';
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
   echo '<small>'.docsSafe((string)$item['status']).'</small>';
   if(!empty($item['url']))echo '<a href="'.docsSafe((string)$item['url']).'" target="_blank" rel="noopener noreferrer">Externe bron ↗</a>';
   echo '</div></div>';
 }
 echo '</div><p class="source-note">Getoonde bronnen zijn de beoogde gegevensketen of huidige Worker-herkomst; dit is geen bevestiging van live ontvangst in PHP.</p>';
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
