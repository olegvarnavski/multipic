<?php
namespace MultiPic;
require_once __DIR__ . '/Gallery.php';
require_once __DIR__ . '/Paths.php';
require_once __DIR__ . '/ImageMemory.php';
final class Service
{
    private $modx;
    private $db;
    private $prefix;
    private $lockDepth = 0;
    private $storageBases;
    public function __construct($modx)
    {
        $this->modx = $modx;
        if (class_exists('MODX\\Revolution\\modX')) {
            foreach(['modX','modResource','modTemplateVar','modTemplateVarResource','modChunk'] as $alias) {
                if(!class_exists($alias))class_alias('MODX\\Revolution\\'.$alias,$alias);
            }
        }
        // MODX 2 returns xPDOConnection here, not PDO. Use the xPDO transaction/prepare API.
        $this->db = $modx;
        $this->prefix = $modx->getOption('table_prefix') . 'multipic_';
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $this->prefix)) { throw new \RuntimeException('Некорректный префикс БД'); }
    }
    public function query(string $sql, array $args = [])
    {
        $q = $this->db->prepare($sql);
        if (!$q || !$q->execute($args)) {
            $this->log('database', ['sqlstate'=>$q ? ($q->errorInfo()[0] ?? '') : 'prepare']);
            throw new \RuntimeException('Ошибка базы MultiPic. Подробности в журнале MODX.');
        }
        return $q;
    }
    public function log(string $action, array $context=[]): void
    {
        // Callers pass IDs and error classes only, never request bodies or auth tokens.
        $this->modx->log(\modX::LOG_LEVEL_ERROR,'[MultiPic '.$action.'] '.json_encode($context,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
    }
    public function storageBases(): array
    {
        if($this->storageBases!==null)return $this->storageBases;
        $bases=[];
        foreach($this->modx->getCollection('sources.modMediaSource') as $source) {
            if(stripos(get_class($source),'FileMediaSource')===false)continue;
            $source->initialize();$base=realpath($source->getBasePath());
            if($base)$bases[]=rtrim(str_replace('\\','/',$base),'/').'/';
        }
        return $this->storageBases=array_values(array_unique($bases));
    }
    public function install(): void
    {
        $this->query("CREATE TABLE IF NOT EXISTS {$this->prefix}files (
            id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, original_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            profile CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, path TEXT NOT NULL, thumb TEXT NOT NULL, url TEXT NOT NULL,
            thumb_url TEXT NOT NULL, width INT NOT NULL, height INT NOT NULL, bytes BIGINT NOT NULL,
            created INT NOT NULL, UNIQUE KEY content (bucket, original_hash, profile)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->query("CREATE TABLE IF NOT EXISTS {$this->prefix}refs (
            resource INT NOT NULL, tv INT NOT NULL, file CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            PRIMARY KEY(resource,tv,file), KEY file_idx(file)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->query("CREATE TABLE IF NOT EXISTS {$this->prefix}leases (
            token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, file CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, touched INT NOT NULL,
            PRIMARY KEY(token,file), KEY file_idx(file)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->query("CREATE TABLE IF NOT EXISTS {$this->prefix}editors (
            token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            user INT NOT NULL, auth CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            tv INT NOT NULL, resource INT NOT NULL, context VARCHAR(100) NOT NULL,
            baseline CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, touched INT NOT NULL,
            KEY touched_idx(touched)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->query("CREATE TABLE IF NOT EXISTS {$this->prefix}pending (
            id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            path TEXT NOT NULL, thumb TEXT NOT NULL, created INT NOT NULL,
            KEY created_idx(created)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->query("CREATE TABLE IF NOT EXISTS {$this->prefix}schema (version INT NOT NULL PRIMARY KEY) ENGINE=InnoDB");
        // Idempotent migration, including databases from 0.1.3 without a version table.
        $index=$this->query("SHOW INDEX FROM {$this->prefix}leases WHERE Key_name='touched_idx'")->fetchColumn();
        if(!$index)$this->query("ALTER TABLE {$this->prefix}leases ADD INDEX touched_idx(touched)");
        $this->query("INSERT IGNORE INTO {$this->prefix}schema(version) VALUES(1)");
    }
    // Separate rows avoid losing new gallery tokens when another manager request
    // writes an older PHP session snapshot, or an extension closes the session early.
    private function editorAuth(): string
    {
        return hash('sha256', (string)$this->modx->user->getUserToken('mgr'));
    }
    public function createEditor(int $tv, int $resource, string $context): string
    {
        $token=bin2hex(random_bytes(32));
        $this->query("INSERT INTO {$this->prefix}editors(token,user,auth,tv,resource,context,baseline,touched) VALUES(?,?,?,?,?,?,?,?)",
            [$token,(int)$this->modx->user->get('id'),$this->editorAuth(),$tv,$resource,$context,$this->fingerprint($resource),time()]);
        return $token;
    }
    public function editor(string $token): ?array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))return null;
        $cap=$this->query("SELECT * FROM {$this->prefix}editors WHERE token=? AND user=? AND auth=? AND touched>=?",
            [$token,(int)$this->modx->user->get('id'),$this->editorAuth(),time()-86400])->fetch(\PDO::FETCH_ASSOC);
        return $cap ?: null;
    }
    public function touchEditor(string $token): void
    {
        $this->query("UPDATE {$this->prefix}editors SET touched=? WHERE token=? AND user=? AND auth=?",
            [time(),$token,(int)$this->modx->user->get('id'),$this->editorAuth()]);
    }
    public function savedEditor(string $token, int $resource, ?string $baseline=null): void
    {
        $this->query("UPDATE {$this->prefix}editors SET resource=?,baseline=?,touched=? WHERE token=? AND user=? AND auth=?",
            [$resource,$baseline ?? $this->fingerprint($resource),time(),$token,(int)$this->modx->user->get('id'),$this->editorAuth()]);
    }
    public function acquire(): void
    {
        if($this->lockDepth++ > 0)return;
        $key='multipic-'.substr(hash('sha256',MODX_CORE_PATH.$this->prefix),0,40);
        if((int)$this->query('SELECT GET_LOCK(?,10)',[$key])->fetchColumn()!==1){$this->lockDepth=0;throw new \RuntimeException('Хранилище занято. Повторите действие.');}
    }
    public function release(): void
    {
        if($this->lockDepth<1 || --$this->lockDepth>0)return;
        $key='multipic-'.substr(hash('sha256',MODX_CORE_PATH.$this->prefix),0,40);
        $this->query('SELECT RELEASE_LOCK(?)',[$key]);
    }
    public function locked(callable $work)
    {
        $this->acquire();
        try{return $work();}finally{$this->release();}
    }
    public function file(string $id): ?array
    {
        $r = $this->query("SELECT * FROM {$this->prefix}files WHERE id=?", [$id])->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }
    public function lease(string $token, string $file): void
    {
        $this->query("INSERT INTO {$this->prefix}leases(token,file,touched) VALUES(?,?,?) ON DUPLICATE KEY UPDATE touched=VALUES(touched)", [$token, $file, time()]);
    }
    public function item(array $file): array
    {
        return ['id' => bin2hex(random_bytes(16)), 'file' => $file['id'], 'url' => $file['url'], 'thumb' => $file['thumb_url'], 'width' => (int)$file['width'], 'height' => (int)$file['height'], 'title' => '', 'alt' => '', 'active' => true];
    }
    public function config($tv, string $context): array
    {
        $props = $tv->get('input_properties');
        if (is_string($props)) { $props = @unserialize($props, ['allowed_classes' => false]); }
        $props = is_array($props) ? $props : [];
        $source = !empty($props['mediaSource']) ? $this->modx->getObject('sources.modMediaSource', (int)$props['mediaSource']) : $tv->getSource($context);
        if (!$source) { throw new \RuntimeException('Не выбран медиаисточник TV'); }
        $source->initialize();
        $sourceProperties=$source->getPropertyList();
        if (!$source->checkPolicy('view') || !$source->checkPolicy('create')) { throw new \RuntimeException('Нет доступа к источнику'); }
        $class = get_class($source);
        if (stripos($class, 'FileMediaSource') === false) { throw new \RuntimeException('В alpha поддержан только файловый медиаисточник'); }
        $base = realpath($source->getBasePath());
        if (!$base || !is_dir($base)) { throw new \RuntimeException('Не найдена папка источника'); }
        return ['source' => (int)$source->get('id'), 'base' => rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
            'base_url' => rtrim($source->getBaseUrl(), '/') . '/',
            'allowed_types' => (string)$source->getOption('allowedFileTypes',$sourceProperties,''),
            'folder' => Gallery::folder(!empty($props['folder']) ? $props['folder'] : $this->modx->getOption('multipic.folder', null, 'multipic/')),
            'max' => max(1, min(1000, (int)(!empty($props['maxFiles']) ? $props['maxFiles'] : $this->modx->getOption('multipic.max_files', null, 30)))),
            'edge' => max(0, (int)$this->modx->getOption('multipic.max_edge', null, 2560)),
            'quality' => max(1, min(100, (int)$this->modx->getOption('multipic.quality', null, 95))),
            'max_bytes' => (int)$this->modx->getOption('multipic.max_bytes', null, 20971520),
            'max_pixels' => (int)$this->modx->getOption('multipic.max_pixels', null, 32000000)];
    }
    public function safeDirectory(string $base, string $relative): string
    {
        $current = rtrim($base, DIRECTORY_SEPARATOR);
        foreach (explode('/', trim($relative, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strpos($part, '\\') !== false) { throw new \RuntimeException('Некорректный путь'); }
            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) { throw new \RuntimeException('Символические ссылки не поддерживаются'); }
            if (!is_dir($current) && !mkdir($current, 0755) && !is_dir($current)) { throw new \RuntimeException('Не удалось создать папку'); }
        }
        $real = realpath($current);
        if (!$real || strpos($real . DIRECTORY_SEPARATOR, $base) !== 0) { throw new \RuntimeException('Путь вне источника'); }
        return $real . DIRECTORY_SEPARATOR;
    }
    public function upload(string $input, string $name, array $cfg, string $token, string $fallback): array
    {
        if (!extension_loaded('gd')) { throw new \RuntimeException('Нужна библиотека PHP GD'); }
        if (!is_file($input) || filesize($input) > $cfg['max_bytes']) { throw new \RuntimeException('Превышен размер файла',1003); }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($input);
        $types = ['image/jpeg' => ['jpg','imagecreatefromjpeg','imagejpeg'], 'image/png' => ['png','imagecreatefrompng','imagepng'], 'image/webp' => ['webp','imagecreatefromwebp','imagewebp']];
        if (!isset($types[$mime])) { throw new \RuntimeException('Допустимы JPG, PNG и WebP'); }
        [$ext, $read, $write] = $types[$mime];
        $allowed=array_filter(array_map('strtolower',preg_split('/[\s,]+/',(string)($cfg['allowed_types'] ?? ''))));
        if($allowed && !in_array($ext,$allowed,true) && !($ext==='jpg' && in_array('jpeg',$allowed,true)))throw new \RuntimeException('Этот формат запрещён настройками источника файлов');
        if ($ext==='jpg' && !function_exists('exif_read_data')) { throw new \RuntimeException('Для JPEG требуется PHP EXIF'); }
        if (!function_exists($read) || !function_exists($write)) { throw new \RuntimeException('Формат не поддерживается библиотекой GD'); }
        $info = @getimagesize($input);
        if (!$info || $info[0] * $info[1] > $cfg['max_pixels']) { throw new \RuntimeException('Изображение слишком большое по числу пикселей',1002); }
        // GD cannot preserve animation: reject rather than silently flatten it.
        $bytes = file_get_contents($input);
        if (($ext === 'png' && strpos($bytes, 'acTL') !== false) || ($ext === 'webp' && strpos($bytes, 'ANIM') !== false)) { throw new \RuntimeException('Анимация пока не поддерживается'); }
        unset($bytes);
        $hash = hash_file('sha256', $input);
        $bucket = hash('sha256', $cfg['source'] . ':' . $cfg['base'] . ':' . $cfg['folder']);
        $profile = hash('sha256', json_encode([$cfg['edge'], $cfg['quality'], 'gd-v1-exif-strip']));
        $lookup=function() use ($bucket,$hash,$profile,$token) {
            $existing=$this->query("SELECT * FROM {$this->prefix}files WHERE bucket=? AND original_hash=? AND profile=?",[$bucket,$hash,$profile])->fetch(\PDO::FETCH_ASSOC);
            if(!$existing)return null;
            if(!is_file($existing['path']) || !is_file($existing['thumb']))throw new \RuntimeException('Файл реестра отсутствует. Проверьте целостность хранилища.');
            $this->lease($token,$existing['id']);return $this->item($existing);
        };
        $existing=$this->locked($lookup);
        if($existing)return $existing;
        $orientation=1;
        if($ext==='jpg'){$exif=@exif_read_data($input);$orientation=(int)($exif['Orientation'] ?? 1);unset($exif);}
        $limit=ImageMemory::limit((string)ini_get('memory_limit'));
        $needed=memory_get_usage(true)+ImageMemory::estimate($info[0],$info[1],$cfg['edge'],in_array($orientation,[3,5,6,7,8],true));
        if($limit>0 && $needed>$limit)throw new \RuntimeException('Недостаточно памяти PHP для обработки. Лимит '.(int)ceil($limit/1048576).' МБ; требуется примерно '.(int)ceil($needed/1048576).' МБ. Загрузите изображение меньшего размера.',1001);
        $id=bin2hex(random_bytes(16));$sub=substr($id,0,2).'/'.substr($id,2,2).'/';
        $filename=$this->slug($name,$fallback).'-'.$id.'.'.$ext;
        $path=$this->safeDirectory($cfg['base'],$cfg['folder'].'files/'.$sub).$filename;
        $thumbPath=$this->safeDirectory($cfg['base'],$cfg['folder'].'thumbs/'.$sub).$filename;
        $intent=['id'=>$id,'path'=>$path,'thumb'=>$thumbPath];
        $this->locked(function() use ($token,$cfg,$id,$path,$thumbPath) {
            $count=(int)$this->query("SELECT COUNT(*) FROM {$this->prefix}leases WHERE token=?",[$token])->fetchColumn();
            if($count>=max(100,$cfg['max']*4))throw new \RuntimeException('Превышен лимит временных загрузок. Сохраните ресурс и откройте его заново.');
            $this->query("INSERT INTO {$this->prefix}pending(id,token,path,thumb,created) VALUES(?,?,?,?,?)",[$id,$token,$path,$thumbPath,time()]);
        });
        $image=null;$main=null;$thumb=null;$registered=false;
        try {
            // GD work and filesystem writes do not hold the shared storage lock.
            $image=@$read($input);
            if(!$image)throw new \RuntimeException('Не удалось прочитать изображение');
            if($ext==='jpg') {
                $o=$orientation;
                if(in_array($o,[2,4,5,7],true))imageflip($image,in_array($o,[2,5,7],true)?IMG_FLIP_HORIZONTAL:IMG_FLIP_VERTICAL);
                $angle=[3=>180,5=>90,6=>-90,7=>-90,8=>90][$o] ?? 0;
                if($angle){$rotated=imagerotate($image,$angle,0);if(!$rotated)throw new \RuntimeException('Не удалось повернуть изображение');imagedestroy($image);$image=$rotated;unset($rotated);}
            }
            $main=$this->resize($image,$cfg['edge']);imagedestroy($image);$image=null;
            $thumb=$this->resize($main,240);
            foreach([[$main,$path],[$thumb,$thumbPath]] as $pair) {
                $quality=$ext==='png'?6:($pair[1]===$thumbPath?min(85,$cfg['quality']):$cfg['quality']);
                if(!$write($pair[0],$pair[1],$quality))throw new \RuntimeException('Не удалось записать изображение');
                chmod($pair[1],0644);
            }
            $row=['id'=>$id,'bucket'=>$bucket,'original_hash'=>$hash,'profile'=>$profile,'path'=>$path,'thumb'=>$thumbPath,
                'url'=>$cfg['base_url'].$cfg['folder'].'files/'.$sub.$filename,'thumb_url'=>$cfg['base_url'].$cfg['folder'].'thumbs/'.$sub.$filename,
                'width'=>imagesx($main),'height'=>imagesy($main),'bytes'=>filesize($path),'created'=>time()];
            return $this->locked(function() use ($lookup,$row,$token,$id,&$registered) {
                // Another request may have published identical bytes while GD was running.
                $existing=$lookup();
                if($existing)return $existing;
                $keys=array_keys($row);$this->db->beginTransaction();
                try {
                    $this->query('INSERT INTO '.$this->prefix.'files ('.implode(',',$keys).') VALUES('.implode(',',array_fill(0,count($keys),'?')).')',array_values($row));
                    $this->lease($token,$id);
                    $this->query("DELETE FROM {$this->prefix}pending WHERE id=?",[$id]);
                    $this->db->commit();$registered=true;
                }catch(\Throwable $e){$this->db->rollBack();throw $e;}
                return $this->item($row);
            });
        } finally {
            foreach([$image,$main,$thumb] as $handle)if(is_resource($handle) || is_object($handle))imagedestroy($handle);
            if(!$registered) {
                // A pending row survives hard process termination and is cleaned after 24h.
                try {
                    if(Paths::remove($intent,[$cfg['base']]))$this->query("DELETE FROM {$this->prefix}pending WHERE id=?",[$id]);
                }catch(\Throwable $e){$this->log('pending',['file'=>$id,'error'=>get_class($e),'reason'=>$e->getMessage()]);}
            }
        }
    }
    private function resize($image, int $edge)
    {
        $w=imagesx($image); $h=imagesy($image); $scale=$edge > 0 ? min(1,$edge/max($w,$h)) : 1;
        $out=imagecreatetruecolor(max(1,(int)round($w*$scale)),max(1,(int)round($h*$scale)));
        imagealphablending($out,false); imagesavealpha($out,true);
        imagecopyresampled($out,$image,0,0,0,0,imagesx($out),imagesy($out),$w,$h);
        return $out;
    }
    private function slug(string $name,string $fallback): string
    {
        $name=pathinfo(basename($name),PATHINFO_FILENAME);
        if (preg_match('/^(?:\d+|IMG[_-]?\d+|DSC[_-]?\d+)$/i',$name)) { $name=$fallback; }
        $ru=preg_split('//u','абвгдеёжзийклмнопрстуфхцчшщъыьэюя',-1,PREG_SPLIT_NO_EMPTY);
        $en=['a','b','v','g','d','e','yo','zh','z','i','y','k','l','m','n','o','p','r','s','t','u','f','kh','ts','ch','sh','sch','','y','','e','yu','ya'];
        $upper=preg_split('//u','АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ',-1,PREG_SPLIT_NO_EMPTY);
        $name=strtr($name,array_combine(array_merge($ru,$upper),array_merge($en,$en)));
        $name=trim(preg_replace('/[^a-z0-9]+/','-',strtolower($name)),'-');
        return substr($name ?: 'image',0,90);
    }
    /** Parse all TV values before changing any references: damaged JSON must prevent deletion. */
    public function syncResource(int $resource): void
    {
        $this->locked(function () use ($resource) {
            $refs=[];
            $table=$this->modx->getTableName('modTemplateVarResource');
            $q=$this->query("SELECT tmplvarid,value FROM {$table} WHERE contentid=?",[$resource]);
            foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $tv) {
                foreach (Gallery::collect($tv['value']) as $g) {
                    foreach ($g['items'] as $item) {
                        $id=$item['file'] ?? '';
                        if (!preg_match('/^[a-f0-9]{32}$/D',$id) || !$this->file($id)) { throw new \RuntimeException('В галерее неизвестный файл'); }
                        $refs[(int)$tv['tmplvarid']][$id]=true;
                    }
                }
            }
            $this->db->beginTransaction();
            try {
                $this->query("DELETE FROM {$this->prefix}refs WHERE resource=?",[$resource]);
                foreach ($refs as $tv=>$ids) { foreach ($ids as $id=>$_) { $this->query("INSERT INTO {$this->prefix}refs(resource,tv,file) VALUES(?,?,?)",[$resource,$tv,$id]); } }
                $this->db->commit();
            } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        });
    }
    public function deleteResource(int $id): void
    {
        $this->locked(function () use ($id) { $this->query("DELETE FROM {$this->prefix}refs WHERE resource=?",[$id]); });
    }
    public function lockSave(int $resource): void
    {
        $this->acquire();
        register_shutdown_function(function(){try{$this->release();}catch(\Throwable $e){}});
    }
    public function fingerprint(int $resource): string
    {
        $table=$this->modx->getTableName('modTemplateVarResource');
        $rows=$this->query("SELECT tmplvarid,value FROM {$table} WHERE contentid=? ORDER BY tmplvarid",[$resource])->fetchAll(\PDO::FETCH_ASSOC);
        return hash('sha256',json_encode($rows));
    }
    public function tokens(array $post): array
    {
        $tokens=json_decode($post['multipic_tokens'] ?? '[]',true);
        $tokens=is_array($tokens)?$tokens:[];
        foreach($post as $key=>$value)if(preg_match('/^tv[0-9]+$/D',(string)$key)) {
            foreach(Gallery::collect($value) as $g)if(!empty($g['session']))$tokens[]=$g['session'];
        }
        return array_values(array_unique(array_filter($tokens,function($t){return is_string($t)&&preg_match('/^[a-f0-9]{64}$/D',$t);})));
    }
    public function validatePosted(array $post, int $resource): void
    {
        $tokens=$this->tokens($post);
        $baseline=$resource?$this->fingerprint($resource):null;
        $caps=[];
        foreach(is_array($tokens)?$tokens:[] as $token) {
            $cap=$this->editor($token);
            if($cap && (int)$cap['user']===(int)$this->modx->user->get('id') && ((int)$cap['resource']===$resource || (!$resource && !$cap['resource']))) {
                if($resource && $cap['baseline']!==$baseline) { throw new \RuntimeException('Галерея уже изменена в другом окне. Обновите ресурс перед сохранением.'); }
                $caps[$token]=$cap;
            }
        }
        foreach($post as $key=>$value) {
            if(!preg_match('/^tv[0-9]+$/D',(string)$key))continue;
            foreach(Gallery::collect($value) as $g) {
                $tv=$this->modx->getObject('modTemplateVar',(int)($g['tv'] ?? 0));
                if(!$tv || $tv->get('type')!=='multipic' || !$tv->checkResourceGroupAccess()) { throw new \RuntimeException('Недоступное TV MultiPic'); }
                $context=$resource ? $this->modx->getObject('modResource',$resource)->get('context_key') : ($post['context_key'] ?? 'web');
                $cfg=$this->config($tv,$context);
                if(count($g['items'])>$cfg['max'])throw new \RuntimeException('Превышен максимум изображений MultiPic');
                $seen=[];
                foreach($g['items'] as $item) {
                    $id=$item['file'] ?? '';
                    if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||!is_bool($item['active'] ?? null))throw new \RuntimeException('Некорректные данные фотографии');
                    if(isset($seen[$id]))throw new \RuntimeException('Повтор изображения в галерее');
                    $seen[$id]=true;
                    $file=$this->file($id);
                    if(!$file)throw new \RuntimeException('Изображение не найдено в реестре');
                    $bucket=hash('sha256',$cfg['source'].':'.$cfg['base'].':'.$cfg['folder']);
                    // Existing references may belong to a previous folder configuration.
                    $already=$this->query("SELECT 1 FROM {$this->prefix}refs WHERE resource=? AND file=? LIMIT 1",[$resource,$id])->fetchColumn();
                    $leased=false;
                    foreach($caps as $token=>$cap) {
                        if((int)$cap['tv']===(int)$tv->get('id') && $this->query("SELECT 1 FROM {$this->prefix}leases WHERE token=? AND file=?",[$token,$id])->fetchColumn())$leased=true;
                    }
                    if(!$already && (!$leased || $file['bucket']!==$bucket))throw new \RuntimeException('Нет привязки к загруженному изображению');
                    if(($item['thumb'] ?? '')!==$file['thumb_url'] || ($item['url'] ?? '')!==$file['url'] || !is_int($item['width'] ?? null) || $item['width']!==(int)$file['width'] || !is_int($item['height'] ?? null) || $item['height']!==(int)$file['height'])throw new \RuntimeException('Изменены служебные данные изображения');
                    foreach(['title','alt'] as $text)if(!is_string($item[$text] ?? null)||strlen($item[$text])>4000)throw new \RuntimeException('Некорректная подпись изображения');
                }
            }
        }
    }
    /** Read-only check: never repairs paths or removes files automatically. */
    public function integrity(int $limit=1000, string $after=''): array
    {
        $limit=max(1,min(10000,$limit));$bases=$this->storageBases();
        $q=$this->query("SELECT * FROM {$this->prefix}files WHERE id>? ORDER BY id LIMIT ".$limit,[$after]);
        $checked=0;$issues=[];$last=$after;
        while($row=$q->fetch(\PDO::FETCH_ASSOC)) {
            $checked++;$last=$row['id'];
            try {
                $pair=Paths::pair($row,$bases);
                foreach($pair as $i=>$path)if(!is_file($path))$issues[]=['file'=>$row['id'],'issue'=>$i?'missing_thumb':'missing_image'];
            }catch(\Throwable $e){$issues[]=['file'=>$row['id'],'issue'=>'unsafe_or_moved_storage'];}
        }
        return ['checked'=>$checked,'issues'=>$issues,'next_after'=>$checked===$limit?$last:null];
    }
    public function cleanup(int $limit=100): array
    {
        $limit=max(1,min(1000,$limit));
        return $this->locked(function () use ($limit) {
            $cutoff=time()-86400;$removed=0;$failed=0;$pendingRemoved=0;
            $this->query("DELETE FROM {$this->prefix}leases WHERE touched<?",[$cutoff]);
            $this->query("DELETE FROM {$this->prefix}editors WHERE touched<?",[$cutoff]);
            $bases=$this->storageBases();
            $q=$this->query("SELECT p.* FROM {$this->prefix}pending p LEFT JOIN {$this->prefix}editors e ON e.token=p.token WHERE p.created<? AND (e.token IS NULL OR e.touched<?) ORDER BY p.created LIMIT ".$limit,[$cutoff,$cutoff]);
            while($row=$q->fetch(\PDO::FETCH_ASSOC)) {
                try {
                    if($this->file($row['id']) || Paths::remove($row,$bases)) {
                        $this->query("DELETE FROM {$this->prefix}pending WHERE id=?",[$row['id']]);$pendingRemoved++;
                    } else $failed++;
                }catch(\Throwable $e){$failed++;$this->log('cleanup_pending',['file'=>$row['id'],'error'=>get_class($e),'reason'=>$e->getMessage()]);}
            }
            $q=$this->query("SELECT f.* FROM {$this->prefix}files f WHERE NOT EXISTS (SELECT 1 FROM {$this->prefix}refs r WHERE r.file=f.id) AND NOT EXISTS (SELECT 1 FROM {$this->prefix}leases l WHERE l.file=f.id) ORDER BY f.id LIMIT ".$limit);
            $candidates=[];while($row=$q->fetch(\PDO::FETCH_ASSOC))$candidates[$row['id']]=$row;
            if(!$candidates)return ['removed'=>0,'failed'=>$failed,'pending_removed'=>$pendingRemoved];
            // Conservative rescan is retained, but only candidate references are repaired.
            // No fetchAll of all TVs, no per-item registry lookup and no full DELETE refs.
            $table=$this->modx->getTableName('modTemplateVarResource');
            $q=$this->query("SELECT contentid,tmplvarid,value FROM {$table} WHERE value LIKE ?",['%multipic%']);
            $used=[];
            while($row=$q->fetch(\PDO::FETCH_ASSOC)) {
                foreach(Gallery::collect($row['value']) as $g)foreach($g['items'] as $item) {
                    $id=$item['file'] ?? '';
                    if(!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id))throw new \RuntimeException('Очистка остановлена: повреждена ссылка на файл');
                    if(isset($candidates[$id]))$used[$id][(int)$row['contentid'].':'.(int)$row['tmplvarid']]=[(int)$row['contentid'],(int)$row['tmplvarid']];
                }
            }
            foreach($used as $id=>$refs)foreach($refs as $ref)$this->query("INSERT IGNORE INTO {$this->prefix}refs(resource,tv,file) VALUES(?,?,?)",[$ref[0],$ref[1],$id]);
            foreach($candidates as $id=>$row) {
                if(isset($used[$id]))continue;
                try {
                    if(Paths::remove($row,$bases)){$this->query("DELETE FROM {$this->prefix}files WHERE id=?",[$id]);$removed++;}
                    else $failed++;
                }catch(\Throwable $e){$failed++;$this->log('cleanup_blocked',['file'=>$id,'error'=>get_class($e),'reason'=>$e->getMessage()]);}
            }
            return ['removed'=>$removed,'failed'=>$failed,'pending_removed'=>$pendingRemoved];
        });
    }
}
