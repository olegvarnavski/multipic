<?php
if (!isset($object) || !$object->xpdo) { return false; }
$modx=$object->xpdo;
if ($options[xPDOTransport::PACKAGE_ACTION] === xPDOTransport::ACTION_UNINSTALL) { return true; }
require_once MODX_CORE_PATH.'components/multipic/model/Service.php';
try {
    (new \MultiPic\Service($modx))->install();
    // Raise the former package default on upgrade; preserve other custom limits.
    $setting=$modx->getObject('modSystemSetting','multipic.max_pixels');
    if($setting && (int)$setting->get('value')===24000000){
        $setting->set('value',32000000);
        if(!$setting->save())throw new RuntimeException('Не удалось обновить лимит изображения');
        $modx->setOption('multipic.max_pixels',32000000);
    }
    return true;
}
catch (Throwable $e) { $modx->log(modX::LOG_LEVEL_ERROR,'[MultiPic install] '.$e->getMessage()); return false; }
