<?php
if (!class_exists('modTemplateVarInputRender') && class_exists('MODX\\Revolution\\modTemplateVarInputRender')) {
    class_alias('MODX\\Revolution\\modTemplateVarInputRender','modTemplateVarInputRender');
}
if (!class_exists('MultiPicInputRender')) {
    class MultiPicInputRender extends modTemplateVarInputRender
    {
        public function getTemplate() { return $this->modx->getOption('core_path').'components/multipic/elements/tv/input/tpl/multipic.tpl'; }
        public function process($value,array $params=[])
        {
            $this->setPlaceholder('multipic_error','');
            require_once $this->modx->getOption('core_path').'components/multipic/model/Service.php';
            try {
                // MIGX can substitute the field ID: retrieve the original TV by name.
                $root=!empty($params['inputTVid']) ? $this->modx->getObject('modTemplateVar',(int)$params['inputTVid']) : $this->modx->getObject('modTemplateVar',['name'=>$this->tv->get('name')]);
                if (!$root) { throw new RuntimeException('Исходное TV MultiPic не найдено'); }
                $resource=$this->modx->resource;
                $rid=$resource ? (int)$resource->get('id') : 0;
                $context=$resource ? $resource->get('context_key') : 'web';
                $service=new \MultiPic\Service($this->modx);
                $cfg=$service->config($root,$context);
                $token=$service->createEditor((int)$root->get('id'),$rid,$context);
                $data=\MultiPic\Gallery::input($value);
                $data['tv']=(int)$root->get('id');
                $data['session']=$token;
                // New editor instance: copies never share mutable gallery identifiers.
                $data['gallery']=bin2hex(random_bytes(16));
                $service->locked(function() use (&$data,$service,$token) {
                    foreach($data['items'] as &$item) {
                        $file=$service->file($item['file']);
                        if(!$file){$item['_missing']='Файл отсутствует в реестре. Удалите карточку или восстановите резервную копию.';continue;}
                        $canonical=$service->item($file);
                        $item=array_merge($item,$canonical,['title'=>(string)($item['title'] ?? ''),'alt'=>(string)($item['alt'] ?? ''),'active'=>(bool)($item['active'] ?? true)]);
                        if(!is_file($file['path']))$item['_missing']='Файл отсутствует. Можно удалить карточку или восстановить файл.';
                        elseif(!is_file($file['thumb']))$item['_missingThumb']=true;
                        $service->lease($token,$file['id']);
                    }
                    unset($item);
                });
                $asset=$this->modx->getOption('assets_url').'components/multipic/';
                $this->modx->regClientCSS($asset.'css/multipic.css?multipic=015');
                $this->modx->regClientStartupScript($asset.'js/multipic.js?multipic=015');
                $id='multipic-'.bin2hex(random_bytes(8));
                $json=json_encode(['id'=>$id,'field'=>'tv'.$this->tv->get('id'),'token'=>$token,'data'=>$data,'max'=>$cfg['max'],'edge'=>$cfg['edge'],'quality'=>$cfg['quality'],'source'=>$cfg['source'],'connector'=>$asset.'connector.php','auth'=>$this->modx->user->getUserToken('mgr')],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_THROW_ON_ERROR);
                $this->setPlaceholder('multipic_id',$id);
                $this->setPlaceholder('multipic_assets',$asset);
                $this->setPlaceholder('multipic_config',$json);
                $this->setPlaceholder('multipic_value',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            } catch (Throwable $e) {
                $message=$e instanceof InvalidArgumentException || $e instanceof JsonException ? 'В поле сохранены данные другого формата. Для MultiPic используйте новое поле MIGX или перенесите прежние данные. Содержимое сохранено без изменений.' : $e->getMessage();
                $this->setPlaceholder('multipic_error',$message);
            }
        }
    }
}
return 'MultiPicInputRender';
