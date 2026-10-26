<?php
namespace MultiPic;
final class Storage
{
    private $s;private $modx;private $p;
    public function __construct(Service $s,$modx,string $prefix){$this->s=$s;$this->modx=$modx;$this->p=$prefix;}
    public function matches(array $file,array $item): bool
    {
        if(($item['url'] ?? null)===$file['url']&&($item['thumb'] ?? null)===$file['thumb_url'])return true;
        $json=$this->s->query("SELECT old_row FROM {$this->p}moves WHERE file=?",[$file['id']])->fetchColumn();
        if(!$json)return false;$old=json_decode($json,true);
        return ($item['url'] ?? null)===$old['url']&&($item['thumb'] ?? null)===$old['thumb_url'];
    }
    public function promoteResource(int $resource): void
    {
        $rows=$this->s->query("SELECT DISTINCT f.* FROM {$this->p}files f JOIN {$this->p}refs r ON r.file=f.id WHERE r.resource=? AND f.layout=2 AND f.owner_resource=0",[$resource])->fetchAll(\PDO::FETCH_ASSOC);
        foreach($rows as $row)$this->move($row,$resource);
        $this->rewrite($resource);
    }
    public function plan(int $limit=100): array
    {
        $rows=$this->s->query("SELECT f.id,f.path,MIN(r.resource) AS resource FROM {$this->p}files f JOIN {$this->p}refs r ON r.file=f.id WHERE f.layout=1 GROUP BY f.id ORDER BY f.id LIMIT ".max(1,min(500,$limit)))->fetchAll(\PDO::FETCH_ASSOC);
        foreach($rows as &$row){try{$new=$this->destination($this->s->file($row['id']),(int)$row['resource']);$row['new_path']=$new['path'];$row['new_url']=$new['url'];$row['exists']=is_file($row['path']);}catch(\Throwable $e){$row['problem']=$e->getMessage();}}unset($row);return $rows;
    }
    public function migrate(int $limit=100): int
    {
        return $this->s->locked(function()use($limit){
            $limit=max(1,min(500,$limit));$n=0;
            $pending=$this->s->query("SELECT file,new_row FROM {$this->p}moves WHERE state='planned' ORDER BY created LIMIT ".$limit)->fetchAll(\PDO::FETCH_ASSOC);
            foreach($pending as $move){$file=$this->s->file($move['file']);if(!$file)throw new \RuntimeException('Журнал ссылается на отсутствующий файл');$new=json_decode($move['new_row'],true);$this->move($file,(int)$new['owner_resource']);$n++;}
            if($n<$limit)foreach($this->plan($limit-$n) as $row){$this->move($this->s->file($row['id']),(int)$row['resource']);$n++;}return $n;
        });
    }
    private function destination(array $old,int $owner): array
    {
        Paths::master($old,$this->s->storageBases());$new=$old;
        if((int)$old['layout']===1){
            $suffix='/files/'.substr($old['id'],0,2).'/'.substr($old['id'],2,2).'/'.basename($old['path']);$root=substr($old['path'],0,-strlen($suffix));$found=false;
            foreach($this->modx->getCollection(\MultiPic\Compat::name($this->modx,'sources.modMediaSource')) as $source){
                if(stripos(get_class($source),'FileMediaSource')===false)continue;
                $source->initialize();$base=realpath($source->getBasePath());if(!$base)continue;$base=rtrim(str_replace('\\','/',$base),'/').'/';
                if(strpos($root.'/',$base)!==0)continue;$folder=Gallery::folder(substr($root.'/',strlen($base)));
                if(hash('sha256',$source->get('id').':'.$base.':'.$folder)!==$old['bucket'])continue;
                $new['source_id']=(int)$source->get('id');$new['storage_base']=$base;$new['storage_folder']=$folder;$found=true;break;
            }
            if(!$found)throw new \RuntimeException('Не найден исходный источник файла '.$old['id']);
        }
        $new['layout']=2;$new['owner_resource']=$owner;$name=basename($old['path']);
        $new['path']=$new['storage_base'].$new['storage_folder'].$owner.'/'.$name;
        $oldPart=(int)$old['layout']===1?'files/'.substr($old['id'],0,2).'/'.substr($old['id'],2,2).'/':'_incoming/';
        $new['url']=substr($old['url'],0,-strlen($oldPart.$name)).$owner.'/'.$name;
        $new['thumb_url']='';$new['thumb']=$this->s->cachePath($new);
        Paths::pair($new,$this->s->storageBases(),$this->s->cacheRoot());return $new;
    }
    private function move(array $row,int $owner): void
    {
        $journal=$this->s->query("SELECT * FROM {$this->p}moves WHERE file=?",[$row['id']])->fetch(\PDO::FETCH_ASSOC);
        if($journal){if($journal['state']==='committed')return;$old=json_decode($journal['old_row'],true);$new=json_decode($journal['new_row'],true);}
        else{$old=$row;$new=$this->destination($old,$owner);$this->s->query("INSERT INTO {$this->p}moves(file,old_row,new_row,state,created) VALUES(?,?,?,'planned',?)",[$row['id'],json_encode($old,JSON_THROW_ON_ERROR),json_encode($new,JSON_THROW_ON_ERROR),time()]);}
        Paths::master($old,$this->s->storageBases());Paths::pair($new,$this->s->storageBases(),$this->s->cacheRoot());
        if(!is_file($old['path']))throw new \RuntimeException('Исходный файл отсутствует: '.$old['id']);
        $dir=dirname($new['path']);if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))throw new \RuntimeException('Не удалось создать папку ресурса');
        Paths::inside($new['path'],$new['storage_base']);
        // Copy into a temporary file, then publish under the storage lock. Existing targets are never overwritten.
        if(!file_exists($new['path'])){
            $temp=tempnam($dir,'.multipic-move-');if(!$temp)throw new \RuntimeException('Не удалось создать временный файл');
            try{if(!copy($old['path'],$temp)||hash_file('sha256',$old['path'])!==hash_file('sha256',$temp))throw new \RuntimeException('Ошибка копирования');
                if(file_exists($new['path'])||!link($temp,$new['path']))throw new \RuntimeException('Не удалось опубликовать копию');chmod($new['path'],0644);
            }finally{@unlink($temp);}
        }
        if(hash_file('sha256',$old['path'])!==hash_file('sha256',$new['path']))throw new \RuntimeException('Контрольная сумма копии не совпала: '.$old['id']);
        $this->modx->beginTransaction();
        try{
            $keys=['path','thumb','url','thumb_url','source_id','storage_base','storage_folder','owner_resource','layout'];$args=[];foreach($keys as $key)$args[]=$new[$key];$args[]=$new['id'];
            $this->s->query("UPDATE {$this->p}files SET ".implode(',',array_map(function($key){return $key.'=?';},$keys)).' WHERE id=?',$args);
            $this->rewrite(null,$new);$this->s->query("UPDATE {$this->p}moves SET state='committed',created=? WHERE file=?",[time(),$new['id']]);$this->modx->commit();
        }catch(\Throwable $e){$this->modx->rollBack();throw $e;}
    }
    private function rewrite(?int $resource=null,?array $only=null): void
    {
        $table=$this->modx->getTableName(\MultiPic\Compat::name($this->modx,'modTemplateVarResource'));
        $q=$this->s->query("SELECT contentid,tmplvarid,value FROM {$table} WHERE ".($resource!==null?'contentid=?':'value LIKE ?'),[$resource!==null?$resource:'%'.$only['id'].'%']);
        while($row=$q->fetch(\PDO::FETCH_ASSOC)){
            $value=Gallery::rewrite($row['value'],function($item)use($only){
                if($only&&($item['file'] ?? '')!==$only['id'])return $item;$file=$only ?? $this->s->file((string)($item['file'] ?? ''));if(!$file)return $item;
                foreach(['url'=>'url','thumb'=>'thumb_url','width'=>'width','height'=>'height'] as $key=>$field)$item[$key]=in_array($key,['width','height'],true)?(int)$file[$field]:$file[$field];return $item;
            });
            if($value!==$row['value'])$this->s->query("UPDATE {$table} SET value=? WHERE contentid=? AND tmplvarid=?",[$value,$row['contentid'],$row['tmplvarid']]);
        }
    }
    public function cleanAliases(int $limit): void
    {
        if($this->s->query("SELECT 1 FROM {$this->p}editors WHERE touched>? LIMIT 1",[time()-86400])->fetchColumn())return;
        $q=$this->s->query("SELECT * FROM {$this->p}moves WHERE state='committed' AND created<? ORDER BY created LIMIT ".max(1,min(500,$limit)),[time()-86400]);
        while($move=$q->fetch(\PDO::FETCH_ASSOC))if(Paths::remove(json_decode($move['old_row'],true),$this->s->storageBases(),$this->s->cacheRoot()))$this->s->query("DELETE FROM {$this->p}moves WHERE file=?",[$move['file']]);
    }
}
