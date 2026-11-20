<?php
declare(strict_types=1);
namespace OCA\StratusTransfer\Service;
use OCP\IConfig;
use OCP\Files\File;
use OC\Files\Storage\Wrapper\Wrapper;
use OC\Files\ObjectStore\ObjectStoreStorage;
use OC\Files\ObjectStore\HomeObjectStoreStorage;
use OC\Files\ObjectStore\S3;
final class ReadOnlyStorage {
    public function __construct(private IConfig $config) {}
    public function origin(): string {
        $args=$this->config->getSystemValue('objectstore',[])['arguments'] ?? [];
        $test=getenv('STRATUS2_TRANSFER_TEST_MODE')==='1' && getenv('STRATUS2_TEST_MODE')==='1';
        $host=$args['hostname'] ?? '';
        return Policy::origin(($test ? 'http':'https').'://'.$host.(isset($args['port']) ? ':'.$args['port']:''),$test);
    }
    public function sign(File $file, string $uid, int $ttl): array {
        if (array_slice(\OCP\Util::getVersion(),0,3)!==[35,0,2]) {throw new \DomainException('This Nextcloud release needs a storage compatibility review');}
        if (!$file->isReadable() || $file->getOwner()?->getUID()!==$uid || $file->isEncrypted()
            || $this->config->getAppValue('encryption','enabled','no')!=='no'
            || $this->config->getAppValue('end_to_end_encryption','enabled','no')!=='no') {
            throw new \DomainException('Use Nextcloud for shared or encrypted files');
        }
        $storage=$file->getStorage();$depth=0;
        while ($storage instanceof Wrapper) {
            $class=get_class($storage);
            $hash=$class==='OCA\\FilesLock\\Storage\\LockWrapper' ? hash_file('sha256',(new \ReflectionClass($storage))->getFileName()):null;
            if (++$depth>12 || !Policy::transparentWrapper($class,$hash ?: null)) {throw new \DomainException('Use Nextcloud for this storage policy');}
            $storage=$storage->getWrapperStorage();
        }
        if (!in_array(get_class($storage),[ObjectStoreStorage::class,HomeObjectStoreStorage::class],true)) {
            throw new \DomainException('Use Nextcloud for this storage type');
        }
        $objectStore=$storage->getObjectStore();
        if (get_class($objectStore)!==S3::class) {throw new \DomainException('Unsupported object store');}
        $args=$this->config->getSystemValue('objectstore',[])['arguments'] ?? [];
        foreach (['sse_c_key','sse_c_key_file','sse_key','sse_key_file','SSECustomerKey'] as $option) {
            if (!empty($args[$option])) {throw new \DomainException('Use Nextcloud for encrypted objects');}
        }
        if (($args['bucket'] ?? '')!==$objectStore->getBucket()) {throw new \DomainException('Storage identity mismatch');}
        $origin=$this->origin();$s3=$objectStore->getConnection();
        // Only core resolves the object key. No client key, bucket or destination is accepted.
        $key=$storage->getURN((int)$file->getId());
        $head=$s3->headObject(['Bucket'=>$objectStore->getBucket(),'Key'=>$key]);
        if ((int)$head['ContentLength']!==$file->getSize()) {throw new \RuntimeException('Storage metadata changed; reload the file list');}
        $etag=(string)$head['ETag'];
        if ($etag==='' || str_contains($etag,"\r") || str_contains($etag,"\n")) {throw new \RuntimeException('Invalid storage response');}
        $command=$s3->getCommand('GetObject',['Bucket'=>$objectStore->getBucket(),'Key'=>$key,'IfMatch'=>$etag]);
        $expires=time()+max(30,min(300,$ttl));
        $command->getHandlerList()->remove('signer');
        $request=(new ObjectReadSignature('s3',$s3->getRegion()))->presign(
            \Aws\serialize($command),$s3->getCredentials()->wait(),new \DateTimeImmutable('@'.$expires)
        );
        $url=(string)$request->getUri();
        $urlParts=parse_url($url);$expected=parse_url($origin);
        if (($urlParts['host'] ?? '')!==$expected['host'] || ($urlParts['scheme'] ?? '')!==$expected['scheme']
            || ($urlParts['port'] ?? 443)!==($expected['port'] ?? 443)) {throw new \RuntimeException('Unexpected signed destination');}
        return ['url'=>$url,'expires_at'=>$expires,'headers'=>['If-Match'=>$etag],'object_etag'=>$etag];
    }
}
