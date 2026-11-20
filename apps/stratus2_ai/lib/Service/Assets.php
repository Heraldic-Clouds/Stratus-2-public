<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Service;
final class Assets {
    public static function name(string $directory,string $name): string {
        static $manifest=null;
        if ($manifest===null) {
            $file=__DIR__.'/../../asset-manifest.json';
            $manifest=is_file($file) ? json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR):[];
        }
        return $manifest[$directory.'/'.$name.'.'.$directory] ?? $name;
    }
}
