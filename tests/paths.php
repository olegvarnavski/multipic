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
 echo "PASS: owned path pair, source boundary, moved roots, symlinks and safe deletion\n";
} finally {
 foreach(['files','thumbs'] as $type){$dir=$base.'/multipic/'.$type.'/aa/aa';foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);rmdir(dirname($dir));rmdir(dirname(dirname($dir)));}
 rmdir($base.'/multipic');rmdir($base);
}
