<?php
require_once $modx->getOption('core_path').'components/multipic/model/Gallery.php';
try {
    $value=$scriptProperties['value'] ?? null;
    if ($value === null) {
        $id=(int)($scriptProperties['resource'] ?? ($modx->resource ? $modx->resource->get('id') : 0));
        $resource=$modx->getObject('modResource',$id);
        if (!$resource || !$resource->checkPolicy('view')) { return ''; }
        $value=$resource->getTVValue((string)($scriptProperties['tv'] ?? ''));
    }
    if (!$value) { return ($scriptProperties['output'] ?? 'html') === 'array' ? [] : ''; }
    $items=\MultiPic\Gallery::select($value,(string)($scriptProperties['parity'] ?? 'all'),(int)($scriptProperties['offset'] ?? 0),(int)($scriptProperties['limit'] ?? 0));
    if (($scriptProperties['output'] ?? 'html') === 'array') { return $items; }
    $render=function(string $tpl,array $data) use ($modx): string {
        if (strpos($tpl,'@FILE ') === 0) {
            $configured=$modx->getOption('multipic.chunks_path',null,$modx->getOption('core_path').'elements/chunks/');
            $base=realpath(str_replace('{core_path}',$modx->getOption('core_path'),$configured));
            $relative=trim(substr($tpl,6));
            $file=$base ? realpath($base.DIRECTORY_SEPARATOR.$relative) : false;
            if (!$file || strpos($file,$base.DIRECTORY_SEPARATOR)!==0 || !is_file($file)) { throw new RuntimeException('Шаблон @FILE вне разрешённой папки'); }
            $chunk=$modx->newObject('modChunk');
            $chunk->setContent(file_get_contents($file)); $chunk->setCacheable(false);
            return (string)$chunk->process($data);
        }
        return (string)$modx->getChunk($tpl,$data);
    };
    $output='';
    foreach($items as $item) {
        // Encode brackets too: editor text must never become executable MODX tags.
        foreach(['url','title','alt'] as $key) { $item[$key]=str_replace(['[',']'],['&#91;','&#93;'],htmlspecialchars((string)($item[$key] ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')); }
        $output.=$render((string)($scriptProperties['tpl'] ?? 'multipicItem'),$item);
    }
    if ($output!=='' && !empty($scriptProperties['tplWrapper'])) { $output=$render($scriptProperties['tplWrapper'],['output'=>$output,'count'=>count($items)]); }
    if (!empty($scriptProperties['toPlaceholder'])) { $modx->setPlaceholder($scriptProperties['toPlaceholder'],$output);return ''; }
    return $output;
} catch (Throwable $e) {
    $modx->log(modX::LOG_LEVEL_ERROR,'[MultiPic output] '.$e->getMessage());
    return ($scriptProperties['output'] ?? 'html') === 'array' ? [] : '';
}
