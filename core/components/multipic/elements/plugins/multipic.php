<?php
$base = $modx->getOption('core_path') . 'components/multipic/';
$event = $modx->event->name;
$modx->lexicon->load('multipic:default');
if (in_array($event, ['OnTVInputRenderList','OnTVInputPropertiesList'], true)) {
    $modx->event->output($base . 'elements/tv/input/' . ($event === 'OnTVInputPropertiesList' ? 'options/' : ''));
    return;
}
if($event==='OnDocFormPrerender') {
    if($modx->getCount('modTemplateVar',['type'=>'multipic'])) {
        $assets=$modx->getOption('assets_url').'components/multipic/';
        $modx->regClientCSS($assets.'css/multipic.css?multipic=014');
        $modx->regClientStartupScript($assets.'js/multipic.js?multipic=014');
    }
    return;
}
require_once $base . 'model/Service.php';
try {
    $service = isset($modx->multipicService) ? $modx->multipicService : ($modx->multipicService = new \MultiPic\Service($modx));
    if(in_array($event,['OnBeforeDocFormSave','OnDocFormSave'],true)) {
        $rid=(int)$resource->get('id');
        $relevant=strpos(json_encode($_POST),'multipic')!==false || $service->query('SELECT 1 FROM '.$modx->getOption('table_prefix').'multipic_refs WHERE resource=? LIMIT 1',[$rid])->fetchColumn();
        if(!$relevant)return;
    }
    if ($event === 'OnBeforeDocFormSave') {
        $service->lockSave((int)$resource->get('id'));
        $service->validatePosted($_POST,(int)$resource->get('id'));
    } elseif ($event === 'OnDocFormSave') {
        $service->syncResource((int)$resource->get('id'));
        // Only leases explicitly submitted by this resource editor can be released.
        $tokens = $service->tokens($_POST);
        $baseline=$service->fingerprint((int)$resource->get('id'));
        foreach (is_array($tokens) ? $tokens : [] as $token) {
            $cap = $service->editor($token);
            if ($cap && ((int)$cap['resource'] === (int)$resource->get('id') || (!$cap['resource'] && ($mode ?? '') === 'new')) && (int)$cap['user'] === (int)$modx->user->get('id')) {
                $service->savedEditor($token,(int)$resource->get('id'),$baseline);
                $service->query('DELETE FROM ' . $modx->getOption('table_prefix') . 'multipic_leases WHERE token=?', [$token]);
            }
        }
        $service->cleanup();
        // Alpha uses global resource-cache invalidation for cross-resource galleries.
        $modx->cacheManager->refresh(['resource' => []]);
        $service->release();
    } elseif ($event === 'OnResourceDuplicate' && isset($newResource)) {
        $service->syncResource((int)$newResource->get('id'));
    } elseif ($event === 'OnResourceDelete') {
        // This event can also represent soft delete; verify the resource is really absent.
        $rid = isset($resource) ? (int)$resource->get('id') : (int)($id ?? 0);
        if ($rid && !$modx->getObject('modResource',$rid)) { $service->deleteResource($rid); $service->cleanup(); }
    } elseif ($event === 'OnEmptyTrash') {
        // Reconcile only IDs that no longer exist, preserving resources still in trash.
        $table=$modx->getTableName('modResource');
        $p=$modx->getOption('table_prefix').'multipic_refs';
        $ids=$service->query("SELECT DISTINCT r.resource FROM {$p} r LEFT JOIN {$table} s ON s.id=r.resource WHERE s.id IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        foreach($ids as $rid) { $service->deleteResource((int)$rid); }
        $service->cleanup();
    }
} catch (Throwable $e) {
    $modx->log(modX::LOG_LEVEL_ERROR,'[MultiPic] '.$e->getMessage());
    if($event==='OnBeforeDocFormSave') { if(isset($service))$service->release(); $modx->event->output($e->getMessage()); }
    elseif($event==='OnDocFormSave' && isset($service))$service->release();
    // Do not run cleanup after an unsuccessful reconciliation.
}
