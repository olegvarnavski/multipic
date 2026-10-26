<?php
namespace MultiPic;
/** Peak estimate for sequential GD decode/rotate/resize, not a multiple of original area for every buffer. */
final class ImageMemory
{
    public static function estimate(int $width, int $height, int $edge, bool $rotate): int
    {
        $scale=$edge>0?min(1,$edge/max($width,$height)):1;
        $out=max(1,(int)round($width*$scale))*max(1,(int)round($height*$scale));
        $thumbScale=min(1,240/max(1,$width*$scale,$height*$scale));
        $thumb=max(1,(int)round($width*$scale*$thumbScale))*max(1,(int)round($height*$scale*$thumbScale));
        // Includes allocation overhead, codec workspace and a fixed safety reserve.
        return (int)ceil(max($width*$height*5+$out*6,$rotate?$width*$height*10:0,$out*6+$thumb*6)+8*1048576);
    }
    public static function limit(string $value): int
    {
        $value=trim($value);if($value==='-1')return -1;
        if(!preg_match('/^([0-9]+)\s*([gmk]?)$/iD',$value,$m))return 0;
        return (int)$m[1]*(['g'=>1073741824,'m'=>1048576,'k'=>1024,''=>1][strtolower($m[2])]);
    }
}
