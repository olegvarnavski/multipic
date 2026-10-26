<?php
if (!isset($object) || !$object->xpdo) { return false; }
$modx=$object->xpdo;
if ($options[xPDOTransport::PACKAGE_ACTION] === xPDOTransport::ACTION_UNINSTALL) { return true; }
require_once MODX_CORE_PATH.'components/multipic/model/Service.php';
try {
    (new \MultiPic\Service($modx))->install();
    // Pixel quota was removed; memory estimation remains independent of image dimensions quotas.
    $setting=$modx->getObject('modSystemSetting','multipic.max_pixels');
    if($setting && !$setting->remove())throw new RuntimeException('Не удалось удалить устаревшую настройку пикселей');
    return true;
}
catch (Throwable $e) { $modx->log(modX::LOG_LEVEL_ERROR,'[MultiPic install] '.$e->getMessage()); return false; }
