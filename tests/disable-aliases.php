<?php
// Disposable test setup only. Apply to all entry points, including real HTTP requests.
require $argv[1];
$path=MODX_CORE_PATH.'config/'.MODX_CONFIG_KEY.'.inc.php';$code=file_get_contents($path);
$code=preg_replace('/\?>\s*$/','',$code);$code.="\n\$config_options['load_deprecated_global_class_aliases']=false;\n";
file_put_contents($path,$code);
