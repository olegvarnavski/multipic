<?php
$transportClass=class_exists(\xPDO\Transport\xPDOTransport::class)?\xPDO\Transport\xPDOTransport::class:\xPDOTransport::class;
if ($options[$transportClass::PACKAGE_ACTION] === $transportClass::ACTION_UNINSTALL) return true;
$modx=$object->xpdo;
$version=$modx->getVersionData();
if(version_compare(PHP_VERSION,'7.4','<') || version_compare($version['full_version'],'2.6.0','<') || !in_array((int)$version['version'],[2,3],true)){
    $modx->log(1,'MultiPic: нужен MODX 2.6+ или MODX 3.x и PHP 7.4+ (PHP также должен соответствовать требованиям ядра MODX).');return false;
}
foreach(['json','pdo_mysql','gd','fileinfo','exif'] as $extension)if(!extension_loaded($extension)){
    $modx->log(1,'MultiPic: отсутствует расширение PHP '.$extension);return false;
}
return true;
