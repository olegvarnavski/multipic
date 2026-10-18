<?php
require __DIR__.'/../core/components/multipic/model/Service.php';
function ensure($v,$message){if(!$v)throw new RuntimeException($message);}
$class=new ReflectionClass(MultiPic\Service::class);$service=$class->newInstanceWithoutConstructor();
$slug=$class->getMethod('slug');$slug->setAccessible(true);
ensure($slug->invoke($service,'001.jpg','Ход строительства октябрь')==='khod-stroitelstva-oktyabr','SEO fallback transliteration');
ensure($slug->invoke($service,'Фасад корпуса.jpg','Ignored')==='fasad-korpusa','meaningful name');
$resize=$class->getMethod('resize');$resize->setAccessible(true);
$input=imagecreatetruecolor(400,200);imagealphablending($input,false);imagesavealpha($input,true);
imagefill($input,0,0,imagecolorallocatealpha($input,12,34,56,127));
$out=$resize->invoke($service,$input,100);
ensure(imagesx($out)===100 && imagesy($out)===50,'aspect ratio');
ensure((imagecolorat($out,0,0)>>24 & 0x7f)===127,'PNG alpha');
$small=$resize->invoke($service,$out,1000);ensure(imagesx($small)===100,'no upscale');
imagedestroy($input);imagedestroy($out);imagedestroy($small);
echo "PASS: SEO names, aspect ratio, transparency, no upscale\n";
