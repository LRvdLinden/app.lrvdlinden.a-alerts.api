<?php
declare(strict_types=1);
/**
 * CLI-only, idempotent schema bootstrap for dedicated A-Alerts database.
 * The cPanel DB user is restricted to its own database. Never runs via HTTP.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require_once __DIR__.'/private-api.php';
function aalertsEnsureSchema(PDO $db): int {
  $sql=@file_get_contents(__DIR__.'/schema-setup.sql');
  if(!is_string($sql)||strlen($sql)>50000)throw new RuntimeException('schema_missing');
  // Only the CREATE TABLE IF NOT EXISTS statements committed with this app.
  $pieces=preg_split('/;\s*(?=\r?\n|$)/',$sql);
  if(!is_array($pieces))throw new RuntimeException('schema_split_failed');
  $count=0;
  foreach($pieces as $piece){
    $statement=trim((string)preg_replace('/^\s*--[^\n]*(?:\n|$)/m','',$piece));
    if($statement==='')continue;
    if(!preg_match('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+api_[a-z_]+\s*\(/i',$statement))
      throw new RuntimeException('invalid_schema_statement');
    $db->exec($statement);
    $count++;
  }
  if($count!==8)throw new RuntimeException('unexpected_schema_table_count');
  return $count;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===realpath(__FILE__)){
  try {
    $count=aalertsEnsureSchema(privateDb());
    echo "SCHEMA_OK tables=".$count."\n";
  }catch(Throwable $e){
    error_log('A-Alerts schema: '.get_class($e).' '.$e->getMessage());
    fwrite(STDERR,"SCHEMA_FAILED\n");
    exit(1);
  }
}
