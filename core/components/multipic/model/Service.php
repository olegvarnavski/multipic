<?php
namespace MultiPic;
require_once __DIR__ . '/Gallery.php';
final class Service
{
    private $modx;
    private $db;
    private $prefix;
    private $lockDepth = 0;
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
        if (!$q || !$q->execute($args)) { throw new \RuntimeException('Ошибка базы MultiPic'); }
        return $q;
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
        $source = $tv->getSource($context);
        if (!$source) { throw new \RuntimeException('Не выбран медиаисточник TV'); }
        $source->initialize();
        if (!$source->checkPolicy('view') || !$source->checkPolicy('create')) { throw new \RuntimeException('Нет доступа к источнику'); }
        $class = get_class($source);
        if (stripos($class, 'FileMediaSource') === false) { throw new \RuntimeException('В alpha поддержан только файловый медиаисточник'); }
        $base = realpath($source->getBasePath());
        if (!$base || !is_dir($base)) { throw new \RuntimeException('Не найдена папка источника'); }
        return ['source' => (int)$source->get('id'), 'base' => rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
            'base_url' => rtrim($source->getBaseUrl(), '/') . '/',
            'folder' => Gallery::folder(!empty($props['folder']) ? $props['folder'] : $this->modx->getOption('multipic.folder', null, 'multipic/')),
            'max' => max(1, min(1000, (int)(!empty($props['maxFiles']) ? $props['maxFiles'] : $this->modx->getOption('multipic.max_files', null, 30)))),
            'edge' => max(0, (int)$this->modx->getOption('multipic.max_edge', null, 2560)),
            'quality' => max(1, min(100, (int)$this->modx->getOption('multipic.quality', null, 95))),
            'max_bytes' => (int)$this->modx->getOption('multipic.max_bytes', null, 20971520),
            'max_pixels' => (int)$this->modx->getOption('multipic.max_pixels', null, 24000000)];
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
        if (!is_file($input) || filesize($input) > $cfg['max_bytes']) { throw new \RuntimeException('Превышен размер файла'); }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($input);
        $types = ['image/jpeg' => ['jpg','imagecreatefromjpeg','imagejpeg'], 'image/png' => ['png','imagecreatefrompng','imagepng'], 'image/webp' => ['webp','imagecreatefromwebp','imagewebp']];
        if (!isset($types[$mime])) { throw new \RuntimeException('Допустимы JPG, PNG и WebP'); }
        [$ext, $read, $write] = $types[$mime];
        if ($ext==='jpg' && !function_exists('exif_read_data')) { throw new \RuntimeException('Для JPEG требуется PHP EXIF'); }
        if (!function_exists($read) || !function_exists($write)) { throw new \RuntimeException('Формат не поддерживается библиотекой GD'); }
        $info = @getimagesize($input);
        if (!$info || $info[0] * $info[1] > $cfg['max_pixels']) { throw new \RuntimeException('Изображение слишком большое по числу пикселей'); }
        // GD cannot preserve animation: reject rather than silently flatten it.
        $bytes = file_get_contents($input);
        if (($ext === 'png' && strpos($bytes, 'acTL') !== false) || ($ext === 'webp' && strpos($bytes, 'ANIM') !== false)) { throw new \RuntimeException('Анимация пока не поддерживается'); }
        unset($bytes);
        $memory=ini_get('memory_limit');
        if($memory!=='-1') {
            $unit=strtolower(substr($memory,-1));$allowed=(int)$memory;
            if($unit==='g')$allowed*=1073741824;elseif($unit==='m')$allowed*=1048576;elseif($unit==='k')$allowed*=1024;
            if($allowed>0 && memory_get_usage(true)+$info[0]*$info[1]*18+8388608>$allowed)throw new \RuntimeException('Недостаточно памяти PHP для обработки. Загрузите изображение меньшего размера.');
        }
        $hash = hash_file('sha256', $input);
        $bucket = hash('sha256', $cfg['source'] . ':' . $cfg['base'] . ':' . $cfg['folder']);
        $profile = hash('sha256', json_encode([$cfg['edge'], $cfg['quality'], 'gd-v1-exif-strip']));
        return $this->locked(function () use ($input,$name,$cfg,$token,$fallback,$ext,$read,$write,$hash,$bucket,$profile) {
            $existing = $this->query("SELECT * FROM {$this->prefix}files WHERE bucket=? AND original_hash=? AND profile=?", [$bucket,$hash,$profile])->fetch(\PDO::FETCH_ASSOC);
            if ($existing && is_file($existing['path']) && is_file($existing['thumb'])) { $this->lease($token,$existing['id']); return $this->item($existing); }
            if ($existing) { throw new \RuntimeException('Файл реестра отсутствует. Требуется восстановление хранилища.'); }
            $image = @$read($input);
            if (!$image) { throw new \RuntimeException('Не удалось прочитать изображение'); }
            $paths = [];
            try {
                if ($ext === 'jpg' && function_exists('exif_read_data')) {
                    $exif = @exif_read_data($input);
                    $o = (int)($exif['Orientation'] ?? 1);
                    if (in_array($o,[2,4,5,7],true)) { imageflip($image, in_array($o,[2,5,7],true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL); }
                    $angle = [3=>180,5=>90,6=>-90,7=>-90,8=>90][$o] ?? 0;
                    if ($angle) { $rotated=imagerotate($image,$angle,0); imagedestroy($image); $image=$rotated; }
                }
                $id = bin2hex(random_bytes(16));
                $slug = $this->slug($name,$fallback);
                $sub = substr($id,0,2) . '/' . substr($id,2,2) . '/';
                $filename = $slug . '-' . $id . '.' . $ext;
                $dir = $this->safeDirectory($cfg['base'], $cfg['folder'] . 'files/' . $sub);
                $thumbDir = $this->safeDirectory($cfg['base'], $cfg['folder'] . 'thumbs/' . $sub);
                $main = $this->resize($image,$cfg['edge']);
                $thumb = $this->resize($main,240);
                $path = $dir . $filename; $thumbPath = $thumbDir . $filename;
                $paths = [$path,$thumbPath];
                foreach ([[$main,$path],[$thumb,$thumbPath]] as $pair) {
                    $quality = $ext === 'png' ? 6 : ($pair[1] === $thumbPath ? min(85,$cfg['quality']) : $cfg['quality']);
                    if (!$write($pair[0],$pair[1],$quality)) { throw new \RuntimeException('Не удалось записать изображение'); }
                    chmod($pair[1],0644);
                }
                $row = ['id'=>$id,'bucket'=>$bucket,'original_hash'=>$hash,'profile'=>$profile,'path'=>$path,'thumb'=>$thumbPath,
                    'url'=>$cfg['base_url'].$cfg['folder'].'files/'.$sub.$filename,'thumb_url'=>$cfg['base_url'].$cfg['folder'].'thumbs/'.$sub.$filename,
                    'width'=>imagesx($main),'height'=>imagesy($main),'bytes'=>filesize($path),'created'=>time()];
                imagedestroy($main); imagedestroy($thumb);
                $keys=array_keys($row);
                $this->db->beginTransaction();
                try {
                    $this->query('INSERT INTO '.$this->prefix.'files ('.implode(',',$keys).') VALUES('.implode(',',array_fill(0,count($keys),'?')).')',array_values($row));
                    $this->lease($token,$id); $this->db->commit();
                } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
                return $this->item($row);
            } catch (\Throwable $e) {
                foreach ($paths as $path) { if (is_file($path)) { @unlink($path); } }
                throw $e;
            } finally { imagedestroy($image); }
        });
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
        $caps=[];
        foreach(is_array($tokens)?$tokens:[] as $token) {
            $cap=$_SESSION['multipic'][$token] ?? null;
            if($cap && (int)$cap['user']===(int)$this->modx->user->get('id') && ((int)$cap['resource']===$resource || (!$resource && !$cap['resource']))) {
                if($resource && $cap['baseline']!==$this->fingerprint($resource)) { throw new \RuntimeException('Галерея уже изменена в другом окне. Обновите ресурс перед сохранением.'); }
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
                    if(!$file || !is_file($file['path']))throw new \RuntimeException('Изображение не найдено');
                    $bucket=hash('sha256',$cfg['source'].':'.$cfg['base'].':'.$cfg['folder']);
                    // Existing references may belong to a previous folder configuration.
                    $already=$this->query("SELECT 1 FROM {$this->prefix}refs WHERE resource=? AND file=? LIMIT 1",[$resource,$id])->fetchColumn();
                    $leased=false;
                    foreach($caps as $token=>$cap) {
                        if((int)$cap['tv']===(int)$tv->get('id') && $this->query("SELECT 1 FROM {$this->prefix}leases WHERE token=? AND file=?",[$token,$id])->fetchColumn())$leased=true;
                    }
                    if(!$already && (!$leased || $file['bucket']!==$bucket))throw new \RuntimeException('Нет привязки к загруженному изображению');
                    if(($item['thumb'] ?? '')!==$file['thumb_url'] || ($item['url'] ?? '')!==$file['url'] || (int)($item['width'] ?? 0)!==(int)$file['width'] || (int)($item['height'] ?? 0)!==(int)$file['height'])throw new \RuntimeException('Изменены служебные данные изображения');
                    foreach(['title','alt'] as $text)if(!is_string($item[$text] ?? null)||strlen($item[$text])>4000)throw new \RuntimeException('Некорректная подпись изображения');
                }
            }
        }
    }
    public function cleanup(): array
    {
        return $this->locked(function () {
            $this->query("DELETE FROM {$this->prefix}leases WHERE touched<?",[time()-86400]);
            $pending=$this->query("SELECT 1 FROM {$this->prefix}files f LEFT JOIN {$this->prefix}refs r ON r.file=f.id LEFT JOIN {$this->prefix}leases l ON l.file=f.id WHERE r.file IS NULL AND l.file IS NULL LIMIT 1")->fetchColumn();
            if(!$pending)return ['removed'=>0,'failed'=>0];
            // Alpha correctness guard: rebuild known fields before any physical deletion.
            // The scan is intentionally conservative until dependency indexing is verified.
            $table=$this->modx->getTableName('modTemplateVarResource');
            $rows=$this->query("SELECT contentid,tmplvarid,value FROM {$table} WHERE value LIKE ?",['%multipic%'])->fetchAll(\PDO::FETCH_ASSOC);
            $rebuilt=[];
            foreach($rows as $row) {
                foreach(Gallery::collect($row['value']) as $g)foreach($g['items'] as $item) {
                    $id=$item['file'] ?? '';
                    if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!$this->file($id))throw new \RuntimeException('Очистка остановлена: неизвестный файл в сохранённой галерее');
                    $rebuilt[(int)$row['contentid']][(int)$row['tmplvarid']][$id]=true;
                }
            }
            $this->db->beginTransaction();
            try {
                $this->query("DELETE FROM {$this->prefix}refs");
                foreach($rebuilt as $rid=>$tvs)foreach($tvs as $tv=>$ids)foreach($ids as $id=>$_)$this->query("INSERT INTO {$this->prefix}refs(resource,tv,file) VALUES(?,?,?)",[$rid,$tv,$id]);
                $this->db->commit();
            } catch(\Throwable $e){$this->db->rollBack();throw $e;}
            $this->query("DELETE FROM {$this->prefix}leases WHERE touched<?",[time()-86400]);
            $rows=$this->query("SELECT f.* FROM {$this->prefix}files f LEFT JOIN {$this->prefix}refs r ON r.file=f.id LEFT JOIN {$this->prefix}leases l ON l.file=f.id WHERE r.file IS NULL AND l.file IS NULL")->fetchAll(\PDO::FETCH_ASSOC);
            $removed=0; $failed=0;
            foreach($rows as $r) {
                $ok=true;
                foreach(['path','thumb'] as $key) { if (is_file($r[$key]) && !unlink($r[$key])) { $ok=false; } }
                if ($ok) { $this->query("DELETE FROM {$this->prefix}files WHERE id=?",[$r['id']]); $removed++; } else { $failed++; }
            }
            return compact('removed','failed');
        });
    }
}
