<?php
namespace MultiPic;
/** Validate legacy and resource-owned files independently from disposable admin cache. */
final class Paths
{
    public static function master(array $row,array $bases): string
    {
        $id=$row['id'] ?? '';
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \RuntimeException('Некорректный ID файла');
        $path=str_replace('\\','/',(string)($row['path'] ?? ''));
        $name='[a-z0-9-]+-'.preg_quote($id,'~').'\.(?:jpg|png|webp)';
        if((int)($row['layout'] ?? 1)===2){
            $owner=(int)($row['owner_resource'] ?? 0);$part=$owner>0?(string)$owner:'_incoming';
            $pattern='~^(.+)/'.preg_quote($part,'~').'/('.$name.')$~D';
        }else{
            $sub=substr($id,0,2).'/'.substr($id,2,2).'/';
            $pattern='~^(.+)/files/'.preg_quote($sub,'~').'('.$name.')$~D';
        }
        if(!preg_match($pattern,$path,$m))throw new \RuntimeException('Путь не принадлежит файлу MultiPic');
        foreach($bases as $base){
            $base=rtrim(str_replace('\\','/',$base),'/').'/';
            if(strpos($m[1].'/',$base)!==0)continue;
            try{Gallery::folder(substr($m[1].'/',strlen($base)));}catch(\Throwable $e){continue;}
            self::inside($path,$base);return $path;
        }
        throw new \RuntimeException('Хранилище недоступно или перемещено. Удаление заблокировано.');
    }
    public static function inside(string $path,string $base): void
    {
        $base=rtrim(str_replace('\\','/',$base),'/').'/';$path=str_replace('\\','/',$path);
        if(strpos($path,$base)!==0)throw new \RuntimeException('Путь вне хранилища');
        $current=rtrim($base,'/');
        if(is_link($current))throw new \RuntimeException('Символическая ссылка в хранилище');
        foreach(explode('/',substr($path,strlen($base))) as $part){
            if($part===''||$part==='.'||$part==='..')throw new \RuntimeException('Некорректный путь');
            $current.='/'.$part;if(is_link($current))throw new \RuntimeException('Символическая ссылка в хранилище');
        }
        if(is_file($path)&&str_replace('\\','/',realpath($path))!==$path)throw new \RuntimeException('Изменился путь хранилища');
    }
    public static function pair(array $row,array $bases,?string $cacheRoot=null): array
    {
        $path=self::master($row,$bases);$thumb=str_replace('\\','/',(string)($row['thumb'] ?? ''));
        if((int)($row['layout'] ?? 1)===2){
            if(!$cacheRoot)throw new \RuntimeException('Не задан корень кеша');
            $expected=rtrim($cacheRoot,'/').'/thumbs/'.max(0,(int)$row['owner_resource']).'/'.$row['id'].'-240-v1.'.pathinfo($path,PATHINFO_EXTENSION);
            if($thumb!==$expected)throw new \RuntimeException('Некорректный путь миниатюры');
            self::inside($thumb,$cacheRoot);
        }else{
            $sub=substr($row['id'],0,2).'/'.substr($row['id'],2,2).'/';
            $root=substr($path,0,strrpos($path,'/files/'.$sub));
            if($thumb!==$root.'/thumbs/'.$sub.basename($path))throw new \RuntimeException('Некорректная пара файлов');
            foreach($bases as $base)if(strpos($thumb,rtrim($base,'/').'/')===0){self::inside($thumb,$base);break;}
        }
        return [$path,$thumb];
    }
    public static function remove(array $row,array $bases,?string $cacheRoot=null): bool
    {
        $paths=self::pair($row,$bases,$cacheRoot);$ok=true;
        foreach($paths as $path)if(file_exists($path)&&!@unlink($path))$ok=false;
        if($ok)foreach($paths as $path){@rmdir(dirname($path));if((int)($row['layout'] ?? 1)===1)@rmdir(dirname(dirname($path)));}
        return $ok;
    }
}
