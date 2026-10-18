<?php
if ($options[xPDOTransport::PACKAGE_ACTION] === xPDOTransport::ACTION_UNINSTALL) return true;
$modx=$object->xpdo;
$version=$modx->getVersionData();
if(version_compare(PHP_VERSION,'7.4','<') || version_compare($version['full_version'],'2.8.6','<') || version_compare($version['full_version'],'3.0','>=')){
    $modx->log(modX::LOG_LEVEL_ERROR,'MultiPic: нужен MODX 2.8.6–2.x и PHP 7.4+. MODX 3 пока не поддерживается этим пакетом.');return false;
}
foreach(['json','pdo_mysql','gd','fileinfo','exif'] as $extension)if(!extension_loaded($extension)){
    $modx->log(modX::LOG_LEVEL_ERROR,'MultiPic: отсутствует расширение PHP '.$extension);return false;
}
return true;
