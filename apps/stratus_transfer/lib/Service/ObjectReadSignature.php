<?php
declare(strict_types=1);
namespace OCA\StratusTransfer\Service;
// The SDK normally excludes conditional headers. Bind the object revision to
// this read capability; changing/removing If-Match must invalidate its signature.
final class ObjectReadSignature extends \Aws\Signature\S3SignatureV4 {
    protected function getHeaderBlacklist() {
        $headers=parent::getHeaderBlacklist();unset($headers['if-match']);return $headers;
    }
}
