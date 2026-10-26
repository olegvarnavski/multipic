<?php
if (PHP_SAPI !== 'cli') { http_response_code(403);exit; }
if (empty($argv[1]) || !is_file($argv[1])) { fwrite(STDERR,"Usage: php cleanup.php /absolute/path/config.core.php\n");exit(1); }
require $argv[1];
require_once __DIR__.'/model/Compat.php';
$modx=\MultiPic\Compat::boot(MODX_CORE_PATH);
$modx->initialize('mgr');
require_once __DIR__.'/model/Service.php';
try {
    $service=new \MultiPic\Service($modx);
    $check=in_array('--check',$argv,true);
    $result=$check?$service->integrity(1000,(string)(($argv[3] ?? '')==='--check'?($argv[4] ?? ''):'')):$service->cleanup();
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
    if(!empty($result['failed']) || !empty($result['issues']))exit(2);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
