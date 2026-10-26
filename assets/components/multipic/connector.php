<?php
require_once dirname(__DIR__,3).'/config.core.php';
require_once MODX_CORE_PATH.'config/'.MODX_CONFIG_KEY.'.inc.php';
require_once MODX_CONNECTORS_PATH.'index.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$modx->user->hasSessionContext('mgr')) { throw new RuntimeException('Требуется авторизация менеджера'); }
    $auth=$_POST['HTTP_MODAUTH'] ?? ($_SERVER['HTTP_MODAUTH'] ?? '');
    if (!hash_equals((string)$modx->user->getUserToken('mgr'),(string)$auth)) { throw new RuntimeException('Сессия истекла. Откройте ресурс заново.'); }
    require_once MODX_CORE_PATH.'components/multipic/model/Service.php';
    $service=new \MultiPic\Service($modx);
    $token=(string)($_POST['token'] ?? '');
    $cap=$service->editor($token);
    if (!$cap) { throw new RuntimeException('Сессия галереи истекла. Откройте ресурс заново.'); }
    if (!$modx->hasPermission('save_document')) { throw new RuntimeException('Нет права сохранения ресурса'); }
    if ($cap['resource']) {
        $res=$modx->getObject(\MultiPic\Compat::name($modx,'modResource'),$cap['resource']);
        if (!$res || !$res->checkPolicy('save')) { throw new RuntimeException('Нет доступа к ресурсу'); }
    } elseif (!$modx->hasPermission('new_document')) { throw new RuntimeException('Нет права создания ресурса'); }
    $tv=$modx->getObject(\MultiPic\Compat::name($modx,'modTemplateVar'),$cap['tv']);
    if (!$tv || !$tv->checkPolicy('view')) { throw new RuntimeException('Нет доступа к TV'); }
    $cfg=$service->config($tv,$cap['context']);
    $service->touchEditor($token);
    $action=$_POST['action'] ?? '';
    if ($action === 'heartbeat') {
        $service->query('UPDATE '.$modx->getOption('table_prefix').'multipic_leases SET touched=? WHERE token=?',[time(),$token]);
        $result=['files'=>[]];
        $ids=json_decode((string)($_POST['files'] ?? '[]'),true);
        if(is_array($ids))foreach(array_slice($ids,0,500) as $id){if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||!$service->canUse($cap,$id))continue;$file=$service->file($id);if($file)$result['files'][$id]=['url'=>$file['url'],'thumb'=>$file['thumb_url'],'width'=>(int)$file['width'],'height'=>(int)$file['height']];}
    } elseif ($action === 'thumbnail') {
        $id=(string)($_POST['file'] ?? '');
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!$service->canUse($cap,$id))throw new RuntimeException('Нет доступа к изображению');
        $file=$service->file($id);if(!$file)throw new RuntimeException('Файл не найден');
        $path=$service->thumbnail($file);$info=getimagesize($path);
        header('Content-Type: '.$info['mime']);header('X-Content-Type-Options: nosniff');readfile($path);exit;
    } elseif ($action === 'upload' || $action === 'import') {
        if(!$modx->hasPermission('file_upload'))throw new RuntimeException('Нет права загрузки файлов (file_upload)');
        $fallback=isset($res) ? (string)$res->get('pagetitle') : 'gallery';
        if ($action === 'upload') {
            $file=$_FILES['file'] ?? null;
            if($file && in_array($file['error'],[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true))throw new RuntimeException('Превышен размер загрузки PHP',1003);
            if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) { throw new RuntimeException('Файл не получен. Проверьте ограничения PHP.'); }
            $result=$service->upload($file['tmp_name'],$file['name'],$cfg,$token,$fallback,(int)$cap['resource']);
        } else {
            $relative=(string)($_POST['path'] ?? '');
            $path=realpath($cfg['base'].ltrim($relative,'/'));
            if (!$path || strpos($path,$cfg['base']) !== 0 || !is_file($path)) { throw new RuntimeException('Файл вне разрешённого источника'); }
            $result=$service->import($path,$cfg,$token,$fallback,(int)$cap['resource']);
        }
    } else { throw new RuntimeException('Неизвестная команда'); }
    echo json_encode(['success'=>true,'object'=>$result],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $requestId=bin2hex(random_bytes(6));
    $context=['request'=>$requestId,'action'=>is_string($_POST['action'] ?? null)?substr($_POST['action'],0,30):'invalid','user'=>(int)$modx->user->get('id'),'tv'=>(int)($cap['tv'] ?? 0),'resource'=>(int)($cap['resource'] ?? 0),'error'=>get_class($e),'reason'=>$e instanceof RuntimeException?$e->getMessage():'internal_error'];
    if(isset($service))$service->log('connector',$context);
    else $modx->log(1,'[MultiPic connector] '.json_encode($context));
    http_response_code(400);
    $message=in_array($e->getCode(),[1001,1003],true)?'Файл слишком большого размера. Выберите другой.':($e instanceof RuntimeException?$e->getMessage():'Не удалось выполнить запрос. Проверьте журнал MODX.');
    echo json_encode(['success'=>false,'message'=>$message,'code'=>$e->getCode()===1001?'multipic_memory':($e->getCode()===1003?'multipic_size':'multipic_request_failed'),'request'=>$requestId],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
