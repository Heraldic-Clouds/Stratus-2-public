<?php
declare(strict_types=1);
namespace Stratus2\Bootstrap;

final class PrimaryStorageGuard {
    public static function check(string $marker, string $endpoint, string $bucket, bool $installed, string $objectPrefix=''): void {
        $identity=hash('sha256',strtolower(rtrim($endpoint,'/'))."\n".$bucket.($objectPrefix==='' ? '' : "\n".$objectPrefix));
        if (is_file($marker)) {
            if (!hash_equals(trim(file_get_contents($marker)),$identity)) {
                throw new \RuntimeException('Primary storage identity changed. A verified migration is required before changing this configuration.');
            }
            return;
        }
        if ($installed) {
            throw new \RuntimeException('Existing installation has no primary-storage guard. Verify its storage configuration and migration before adoption.');
        }
        $handle=fopen($marker,'x');
        if ($handle===false) {throw new \RuntimeException('Cannot initialize primary-storage guard');}
        try {if (fwrite($handle,$identity."\n")!==65) {throw new \RuntimeException('Cannot persist primary-storage guard');}}
        finally {fclose($handle);}
        chmod($marker,0600);
    }
}
