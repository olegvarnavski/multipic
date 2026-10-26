<?php
require __DIR__.'/../core/components/multipic/model/ImageMemory.php';
use MultiPic\ImageMemory;
function memoryCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
$estimate=ImageMemory::estimate(6000,4000,2560,false);
memoryCheck($estimate<170*1048576,'24MP sequential resize should not reserve 400MB');
memoryCheck(ImageMemory::estimate(6000,4000,2560,true)>$estimate,'EXIF rotation needs a second source buffer');
memoryCheck(ImageMemory::estimate(6000,4000,0,false)>$estimate,'Unscaled target needs more memory');
memoryCheck(ImageMemory::limit('256M')===268435456 && ImageMemory::limit('128m')===134217728 && ImageMemory::limit('-1')===-1,'PHP memory units');
echo "PASS: dimension-based GD peak estimate, EXIF buffer and memory_limit units\n";
