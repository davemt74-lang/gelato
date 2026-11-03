<?php
declare(strict_types=1);

/** Retention is an access boundary even before the cleanup worker runs. */
final class GlassesVisionMediaUnavailable extends InvalidArgumentException {}

function glasses_vision_media_retained(array $row,?DateTimeImmutable $now=null): bool
{
    if(($row['status']??null)!=='active'||!array_key_exists('retention_until',$row))return false;
    if($row['retention_until']===null)return true;
    $raw=(string)$row['retention_until'];
    if(!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?$/D',$raw,$parts))return false;
    $normalized=$parts[1].'.'.str_pad($parts[2]??'',6,'0');
    $utc=new DateTimeZone('UTC');
    $until=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$normalized,$utc);
    if(!$until||$until->format('Y-m-d H:i:s.u')!==$normalized)return false;
    return $until>($now??new DateTimeImmutable('now',$utc));
}

/** Server-owned alias only; all predicates use the UTC deadline saved at capture. */
function glasses_vision_media_retained_sql(string $alias=''): string
{
    if($alias!==''&&!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$alias))throw new InvalidArgumentException('Invalid media query alias.');
    $prefix=$alias===''?'':$alias.'.';
    return "{$prefix}status='active' AND ({$prefix}retention_until IS NULL OR {$prefix}retention_until>UTC_TIMESTAMP(6))";
}

function glasses_vision_media_require_retained(array $row): void
{
    if(!glasses_vision_media_retained($row))throw new GlassesVisionMediaUnavailable('Training media is no longer available.');
}

/** A failed cleanup item must also roll back inside a caller-owned transaction. */
function glasses_vision_media_cleanup_transaction(PDO $pdo,callable $work): mixed
{
    $owns=!$pdo->inTransaction();$savepoint='vision_cleanup_'.bin2hex(random_bytes(6));
    if($owns)$pdo->beginTransaction();else $pdo->exec('SAVEPOINT '.$savepoint);
    try{
        $result=$work();
        if($owns)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
        return $result;
    }catch(Throwable $error){
        if($pdo->inTransaction()){
            if($owns)$pdo->rollBack();
            else{$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}
        }
        throw $error;
    }
}

/** Require both listing and traversal before treating a failed child lookup as absence. */
function glasses_vision_media_require_directory(string $path): void
{
    clearstatcache(true,$path);
    $stat=@lstat($path);
    if(!$stat||($stat['mode']&0170000)!==0040000||!@is_readable($path)
        ||(DIRECTORY_SEPARATOR==='/'&&!@is_executable($path)))
        throw new RuntimeException('Private training storage is inaccessible.');
}

/**
 * Resolve a private storage entry without confusing inaccessible bytes with
 * missing bytes. Existing roots need no probes above PHP's open_basedir; a
 * missing root must instead be proved absent from an accessible ancestor.
 */
function glasses_vision_media_storage_resolve(string $root,string $relative): string
{
    $root=str_replace('\\','/',$root);
    if($root===''||str_contains($root,"\0")||!preg_match('~^(?:/|[a-zA-Z]:/)~D',$root)
        ||preg_match('~(^|/)\.\.?(/|$)~',$root))
        throw new RuntimeException('Private training storage root is invalid.');
    $root=rtrim($root,'/');if($root==='')$root='/';
    elseif(preg_match('/^[a-zA-Z]:$/D',$root))$root.='/';
    if(!preg_match('~^[a-zA-Z0-9._-]+(?:/[a-zA-Z0-9._-]+)*$~D',$relative)
        ||preg_match('~(^|/)\.\.?(/|$)~',$relative))
        throw new RuntimeException('Private training storage reference is invalid.');
    $tail=[];$anchor=$root;
    while(true){
        clearstatcache(true,$anchor);$stat=@lstat($anchor);
        if($stat){glasses_vision_media_require_directory($anchor);break;}
        $parent=str_replace('\\','/',dirname($anchor));
        if($parent===$anchor||$parent==='.'||$parent==='')
            throw new RuntimeException('Private training storage ancestry is inaccessible.');
        array_unshift($tail,basename($anchor));$anchor=$parent;
    }
    $parts=array_merge($tail,explode('/',$relative));$path=$anchor;
    foreach($parts as $index=>$part){
        glasses_vision_media_require_directory($path);
        $parent=$path;$path=rtrim($parent,'/').'/'.$part;
        clearstatcache(true,$path);$stat=@lstat($path);
        if(!$stat){
            // lstat/file_exists also fail for EACCES. A successful parent listing
            // which does not contain the name is the required absence evidence.
            glasses_vision_media_require_directory($parent);
            $entries=@scandir($parent);
            if($entries===false||in_array($part,$entries,true))
                throw new RuntimeException('Private training storage entry is inaccessible.');
            $rest=array_slice($parts,$index+1);
            return $path.($rest?'/'.implode('/',$rest):'');
        }
        $type=$stat['mode']&0170000;
        if($type===0120000)throw new RuntimeException('Private training storage cannot contain links.');
        if($type===0040000)glasses_vision_media_require_directory($path);
        elseif($index!==count($parts)-1)throw new RuntimeException('Private training storage parent is not a directory.');
    }
    return $path;
}
