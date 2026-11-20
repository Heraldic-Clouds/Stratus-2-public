<?php
declare(strict_types=1);
namespace OCA\StratusTransfer\Service;
final class Policy {
    public const UPLOAD_BLOCKER = 'Nextcloud 35.0.2 has no supported staging-object adoption API. Use Nextcloud uploads to preserve metadata, quotas and versions.';
    public static function origin(string $url, bool $test = false): string {
        $parts=parse_url($url);
        if (!is_array($parts) || isset($parts['user'],$parts['pass']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])
            || !isset($parts['host']) || ($parts['scheme'] ?? '')!==($test ? 'http':'https')
            || !in_array($parts['path'] ?? '',['','/'],true)) {throw new \DomainException('Unsupported storage endpoint');}
        if (!$test && !preg_match('/^(?:[a-z0-9-]+\.)*(?:storjshare\.io|storj\.io)$/D',strtolower($parts['host']))) {
            throw new \DomainException('Unsupported storage endpoint');
        }
        return $parts['scheme'].'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port']:'');
    }
    public static function transparentWrapper(string $class,?string $sourceHash=null): bool {
        // Files Lock 35.0.1 was inspected: READ always remains allowed. Fail
        // closed if an independent app update changes this non-core wrapper.
        if ($class==='OCA\\FilesLock\\Storage\\LockWrapper') {
            return $sourceHash==='185cc860224f1724691adf56316d4c679060b20472bd0f8f7ae902940487a95d';
        }
        return in_array($class,[
            'OC\\Files\\Storage\\Wrapper\\Quota','OC\\Files\\Storage\\Wrapper\\Availability',
            'OC\\Files\\Storage\\Wrapper\\Jail','OC\\Files\\Storage\\Wrapper\\PermissionsMask',
            'OC\\Files\\Storage\\Wrapper\\DirPermissionsMask','OC\\Files\\Storage\\Wrapper\\KnownMtime',
            'OCA\\Files_Trashbin\\Storage',
        ],true);
    }
    public static function progress(int $previous, int $position, int $size): int {
        if ($position<0 || $position>$size) {throw new \InvalidArgumentException('Invalid download position');}
        return max($previous,$position);
    }
}
