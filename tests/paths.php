<?php
require __DIR__.'/../core/components/multipic/model/Gallery.php';
require __DIR__.'/../core/components/multipic/model/Paths.php';
use MultiPic\Paths;
$base=sys_get_temp_dir().'/multipic-paths-'.bin2hex(random_bytes(6));
$id=str_repeat('a',32);$suffix='aa/aa/image-'.$id.'.png';
$row=['id'=>$id,'path'=>$base.'/multipic/files/'.$suffix,'thumb'=>$base.'/multipic/thumbs/'.$suffix];
mkdir(dirname($row['path']),0755,true);mkdir(dirname($row['thumb']),0755,true);
file_put_contents($row['path'],'main');file_put_contents($row['thumb'],'thumb');
function blocked($row,$bases){try{Paths::pair($row,$bases);}catch(RuntimeException $e){return;}throw new RuntimeException('Unsafe path accepted');}
try {
 Paths::pair($row,[$base.'/']);
 blocked($row,[$base.'/different/']);
 $bad=$row;$bad['thumb']=$base.'/foreign.png';blocked($bad,[$base.'/']);
 if(!is_file($row['path']))throw new RuntimeException('Validation removed a file');
 $target=$base.'/target';mkdir($target);$bad=$row;
 mkdir($base.'/linked');symlink($target,$base.'/linked/multipic');
 $bad['path']=$base.'/linked/multipic/files/'.$suffix;$bad['thumb']=$base.'/linked/multipic/thumbs/'.$suffix;
 blocked($bad,[$base.'/']);unlink($base.'/linked/multipic');rmdir($base.'/linked');rmdir($target);
 if(!Paths::remove($row,[$base.'/']) || file_exists($row['path']))throw new RuntimeException('Owned deletion failed');
 $cache=$base.'/private-cache/';$new=['id'=>$id,'layout'=>2,'owner_resource'=>123,'path'=>$base.'/multipic/123/image-'.$id.'.png','thumb'=>$cache.'thumbs/123/'.$id.'-240-v1.png'];
 mkdir(dirname($new['path']),0755,true);mkdir(dirname($new['thumb']),0755,true);file_put_contents($new['path'],'main');file_put_contents($new['thumb'],'thumb');
 Paths::pair($new,[$base.'/'],$cache);
 $bad=$new;$bad['owner_resource']=124;try{Paths::pair($bad,[$base.'/'],$cache);throw new Exception('Wrong owner accepted');}catch(RuntimeException $e){}
 $bad=$new;$bad['thumb']=$base.'/foreign.png';try{Paths::pair($bad,[$base.'/'],$cache);throw new Exception('Foreign cache accepted');}catch(RuntimeException $e){}
 if(!Paths::remove($new,[$base.'/'],$cache)||file_exists($new['thumb']))throw new RuntimeException('Resource-owned deletion failed');
 rmdir($cache.'thumbs');rmdir(rtrim($cache,'/'));
 echo "PASS: resource folder, independent private cache and owner boundary\n";
 echo "PASS: owned path pair, source boundary, moved roots, symlinks and safe deletion\n";
} finally {
 foreach(['files','thumbs'] as $type){$dir=$base.'/multipic/'.$type.'/aa/aa';foreach(glob($dir.'/*')?:[] as $f)unlink($f);if(is_dir($dir))rmdir($dir);if(is_dir(dirname($dir)))rmdir(dirname($dir));rmdir(dirname(dirname($dir)));}
 rmdir($base.'/multipic');rmdir($base);
}
