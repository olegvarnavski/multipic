<?php
namespace MultiPic;
/** Ownership checks shared by normal cleanup, failed uploads and diagnostics. */
final class Paths
{
    public static function pair(array $row, array $bases): array
    {
        $id=$row['id'] ?? '';
        if(!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id))throw new \RuntimeException('Некорректный ID файла');
        $sub=substr($id,0,2).'/'.substr($id,2,2).'/';
        $path=str_replace('\\','/',(string)($row['path'] ?? ''));
        $thumb=str_replace('\\','/',(string)($row['thumb'] ?? ''));
        $pattern='~^(.+)/files/'.preg_quote($sub,'~').'([a-z0-9-]+-'.preg_quote($id,'~').'\.(?:jpg|png|webp))$~D';
        if(!preg_match($pattern,$path,$m) || $thumb!==$m[1].'/thumbs/'.$sub.$m[2])throw new \RuntimeException('Путь не принадлежит файлу MultiPic');
        foreach($bases as $base) {
            $base=rtrim(str_replace('\\','/',$base),'/').'/';
            if(strpos($m[1].'/',$base)!==0)continue;
            $relative=substr($m[1].'/',strlen($base));
            try { Gallery::folder($relative); } catch(\Throwable $e) { continue; }
            foreach([$path,$thumb] as $target) {
                $current=rtrim($base,'/');
                foreach(explode('/',substr($target,strlen($base))) as $part) {
                    $current.='/'.$part;
                    if(is_link($current))throw new \RuntimeException('Символическая ссылка в хранилище MultiPic');
                }
                if(is_file($target) && realpath($target)!==$target)throw new \RuntimeException('Изменился путь хранилища MultiPic');
            }
            return [$path,$thumb];
        }
        throw new \RuntimeException('Хранилище недоступно или перемещено. Удаление заблокировано.');
    }
    public static function remove(array $row, array $bases): bool
    {
        $paths=self::pair($row,$bases); // Validate both BEFORE deleting either.
        $ok=true;
        foreach($paths as $path)if(file_exists($path) && !unlink($path))$ok=false;
        return $ok;
    }
}
