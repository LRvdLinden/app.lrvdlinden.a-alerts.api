<?php
declare(strict_types=1);
// One-time schema bootstrap: requires ephemeral token stored outside document root.
// Route can be removed after verification. No SQL accepted from HTTP requests.
function docsInstallHome(): string {
 $root=(string)($_SERVER['DOCUMENT_ROOT']??'');
 return preg_match('~^(/home[^/]*/[^/]+)(?:/|$)~',$root,$m)?$m[1]:'';
}
function setupSchemaRoute(string $path): void {
 if($path!=='/v1/beheer/install')return;
 if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')privateReply(405,['error'=>'method_not_allowed']);
 $home=docsInstallHome();
 $file=$home!==''?$home.'/aalerts-private/schema-once.php':'';
 $permit=$file!==''&&is_file($file)?require $file:[];
 $header=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
 if(!is_array($permit)||empty($permit['hash'])||((int)($permit['expires']??0))<time()
    ||!preg_match('/^Bearer ([a-f0-9]{64})$/',$header,$m)
    ||!hash_equals((string)$permit['hash'],hash('sha256',$m[1])))privateReply(403,['error'=>'forbidden']);
 try {
  $db=privateDb();
  $sql=file_get_contents(__DIR__.'/schema-setup.sql');
  if(!is_string($sql)||strlen($sql)>30000)throw new RuntimeException('schema_not_available');
  $segments=array_filter(array_map('trim',preg_split('/;\s*(?=\r?\n|$)/',$sql)));
  $count=0;
  foreach($segments as $statement){
   $statement=preg_replace('/^\s*(?:--[^\n]*\n\s*)+/m','',$statement);
   if(!preg_match('/^CREATE TABLE IF NOT EXISTS api_[a-z_]+\s*\(/i',$statement))throw new RuntimeException('invalid_schema_statement');
   $db->exec($statement);$count++;
  }
  privateReply(200,['ok'=>true,'created_or_existing_tables'=>$count]);
 }catch(Throwable $e){error_log('AAlerts schema install: '.get_class($e));privateReply(503,['error'=>'schema_install_failed']);}
}
