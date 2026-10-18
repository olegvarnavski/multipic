<?php
if (!isset($object) || !$object->xpdo) { return false; }
$modx=$object->xpdo;
if ($options[xPDOTransport::PACKAGE_ACTION] === xPDOTransport::ACTION_UNINSTALL) { return true; }
require_once MODX_CORE_PATH.'components/multipic/model/Service.php';
try { (new \MultiPic\Service($modx))->install(); return true; }
catch (Throwable $e) { $modx->log(modX::LOG_LEVEL_ERROR,'[MultiPic install] '.$e->getMessage()); return false; }
