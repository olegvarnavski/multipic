<?php
namespace MultiPic;
/** Explicit core names keep the component independent of MODX 3 legacy aliases. */
final class Compat
{
    public static function modern($modx): bool { return is_a($modx,'MODX\\Revolution\\modX'); }
    public static function name($modx,string $name): string
    {
        if(!self::modern($modx))return $name;
        if(strpos($name,'sources.')===0)return 'MODX\\Revolution\\Sources\\'.substr($name,8);
        return 'MODX\\Revolution\\'.$name;
    }
    public static function boot(string $core)
    {
        if(is_file($core.'vendor/autoload.php'))require_once $core.'vendor/autoload.php';
        if(class_exists('MODX\\Revolution\\modX'))return new \MODX\Revolution\modX();
        require_once $core.'model/modx/modx.class.php';return new \modX();
    }
}
