<?php
declare(strict_types=1);
namespace OCA\StratusTransfer\Service;
use OCP\IConfig;
use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
final class Transfer {
    public function __construct(private IConfig $config,private IRootFolder $root,private ReadOnlyStorage $storage,private Receipts $receipts) {}
    public function status(string $uid): array {
        $rate=max(0,min(1,(float)$this->config->getAppValue('stratus_transfer','egress_usd_per_gb','0.09')));
        $summary=$this->receipts->summary($uid);
        return ['uid'=>$uid,'direct_downloads'=>$this->config->getAppValue('stratus_transfer','direct_downloads','0')==='1',
            'direct_uploads'=>false,'uploads_requested'=>$this->config->getAppValue('stratus_transfer','direct_uploads','0')==='1',
            'upload_blocker'=>Policy::UPLOAD_BLOCKER,'ttl_seconds'=>$this->ttl(),'metrics'=>$summary+[
                'assumed_egress_usd_per_gb'=>$rate,'estimated_savings_usd'=>round($summary['direct_bytes']/1e9*$rate,4),
                'estimate_note'=>'Before free allowances and discounts; browser reports are not billing measurements.']];
    }
    private function ttl(): int {return max(30,min(300,(int)$this->config->getAppValue('stratus_transfer','url_ttl_seconds','120')));}
    private function file(string $uid,int $fileId): File {
        if ($fileId<1) {throw new NotFoundException();}
        foreach ($this->root->getUserFolder($uid)->getById($fileId) as $node) {
            if ($node instanceof File && $node->isReadable()) {return $node;}
        }
        throw new NotFoundException();
    }
    public function browse(string $uid,string $path=''): array {
        if (strlen($path)>2000 || str_contains($path,'\\') || str_contains($path,"\0") || preg_match('~(^|/)\.{1,2}(/|$)~',$path)) {throw new \InvalidArgumentException('Invalid folder');}
        $home=$this->root->getUserFolder($uid);$folder=$path==='' ? $home:$home->get($path);
        if (!$folder instanceof Folder || !$folder->isReadable()) {throw new NotFoundException();}
        $nodes=[];$listing=$folder->getDirectoryListing();
        foreach (array_slice($listing,0,1000) as $node) {
            if (!$node->isReadable()) {continue;}
            $nodes[]=['id'=>$node->getId(),'name'=>$node->getName(),'folder'=>$node instanceof Folder,'size'=>$node->getSize(),
                'etag'=>$node->getEtag(),'path'=>ltrim((string)$home->getRelativePath($node->getPath()),'/')];
        }
        return ['files'=>$nodes,'truncated'=>count($listing)>1000];
    }
    public function download(string $uid,int $fileId,string $etag): array {
        $file=$this->file($uid,$fileId);
        if ($file->getEtag()!==$etag) {throw new \RuntimeException('File changed; reload the folder');}
        $data=['file_id'=>$fileId,'etag'=>$etag,'size'=>$file->getSize(),'name'=>$file->getName(),
            'path'=>ltrim((string)$this->root->getUserFolder($uid)->getRelativePath($file->getPath()),'/')];
        $mode='proxy';$reason='Direct downloads are disabled';$signed=[];
        if ($this->config->getAppValue('stratus_transfer','direct_downloads','0')==='1') {
            try {$signed=$this->storage->sign($file,$uid,$this->ttl());$mode='direct';$reason='';}
            catch (\DomainException $error) {$reason=$error->getMessage();}
        }
        $ticket=$this->receipts->create($uid,$data+['mode'=>$mode,'object_etag'=>$signed['object_etag'] ?? null]);
        return $data+['ticket'=>$ticket,'mode'=>$mode,'fallback_reason'=>$reason]+$signed;
    }
    public function refresh(string $uid,string $ticket): array {
        $saved=$this->receipts->get($uid,$ticket);$file=$this->file($uid,$saved['file_id']);
        if ($file->getEtag()!==$saved['etag'] || $file->getSize()!==$saved['size']) {throw new \RuntimeException('File changed; start a new download');}
        if ($saved['mode']!=='direct') {return $saved;}
        if ($this->config->getAppValue('stratus_transfer','direct_downloads','0')!=='1') {throw new \DomainException('Direct downloads have been disabled');}
        $signed=$this->storage->sign($file,$uid,$this->ttl());
        if ($signed['object_etag']!==$saved['object_etag']) {throw new \RuntimeException('File changed; start a new download');}
        return $saved+['ticket'=>$ticket]+$signed;
    }
    public function progress(string $uid,string $ticket,int $position): array {return $this->receipts->progress($uid,$ticket,$position);}
    public function upload(string $uid,string $folder,int $size): array {
        if ($size<0) {throw new \InvalidArgumentException('Invalid file size');}
        if (strlen($folder)>2000 || str_contains($folder,'\\') || str_contains($folder,"\0") || preg_match('~(^|/)\.{1,2}(/|$)~',$folder)) {throw new \InvalidArgumentException('Invalid folder');}
        $home=$this->root->getUserFolder($uid);$target=$folder==='' ? $home:$home->get($folder);
        if (!$target instanceof Folder || $target->getOwner()?->getUID()!==$uid || !$target->isCreatable()) {throw new NotFoundException();}
        $free=$target->getFreeSpace();
        if ($free>=0 && $size>$free) {throw new \LengthException('Not enough storage quota');}
        // No staging object, write URL or finalization token is issued. DAV owns
        // upload locking, quota races, multipart completion, versions and hooks.
        return ['mode'=>'nextcloud','direct_uploads'=>false,'reason'=>Policy::UPLOAD_BLOCKER];
    }
}
