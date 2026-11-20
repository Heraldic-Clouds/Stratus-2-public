<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Service;
use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Lock\ILockingProvider;
final class PhotoEnhancement {
    public const MAX_BYTES=40*1024*1024;
    public const ACTIVE=['photo_queued','photo_processing'];
    public function __construct(private JobStore $store, private IRootFolder $root, private IAppData $appData,
        private ILockingProvider $locks, private BillingAccount $billing) {}
    private function enabled(): void {
        if (getenv('PHOTO_ENHANCEMENT_ENABLED')!=='1') {throw new \DomainException('Photo improvement is not enabled in this environment.');}
    }
    private function withLock(string $id, callable $fn): mixed {
        $key='stratus2_photo_'.$id;$this->locks->acquireLock($key,ILockingProvider::LOCK_EXCLUSIVE);
        try {return $fn();} finally {$this->locks->releaseLock($key,ILockingProvider::LOCK_EXCLUSIVE);}
    }
    public function get(string $id, ?string $uid=null): array {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) {throw new \OCP\Files\NotFoundException('Not found');}
        $job=$this->store->get($id,$uid);
        if (($job['data']['kind'] ?? '')!=='photo') {throw new \OCP\Files\NotFoundException('Not found');}
        return $job;
    }
    public function source(array $job): File {
        $files=$this->root->getUserFolder($job['user_id'])->getById((int)$job['data']['file_id']);$file=$files[0] ?? null;
        if (!$file instanceof File || $file->getOwner()?->getUID()!==$job['user_id'] || !$file->isReadable()) {throw new \OCP\Files\NotFoundException('Photo unavailable');}
        if ($file->getEtag()!==$job['data']['etag']) {throw new \DomainException('The source photo changed. Start a new improvement.');}
        return $file;
    }
    public function create(string $uid, string $path, string $prompt, bool $consent, ?string $jobId=null, ?string $outputPath=null): array {
        $this->enabled();$this->billing->requirePaid($uid);
        if (!$consent) {throw new \InvalidArgumentException('Consent to send this photo and instructions is required.');}
        if (trim($prompt)==='' || strlen($prompt)>4000) {throw new \InvalidArgumentException('Enter instructions under 4000 characters.');}
        $path=Planner::safePath($path);$home=$this->root->getUserFolder($uid);$file=$home->get($path);
        if (!$file instanceof File || $file->getOwner()?->getUID()!==$uid || !$file->isReadable()) {throw new \OCP\Files\NotFoundException('Photo unavailable');}
        if (!in_array($file->getMimeType(),['image/jpeg','image/png','image/webp'],true) || $file->getSize()>self::MAX_BYTES) {
            throw new \InvalidArgumentException('Choose a JPEG, PNG or WebP photo up to 40 MiB.');
        }
        if ($outputPath!==null) {$outputPath=Planner::safePath($outputPath);}
        $id=$this->store->create($uid,['kind'=>'photo','schema_version'=>1,'file_id'=>$file->getId(),'etag'=>$file->getEtag(),
            'path'=>$path,'output_path'=>$outputPath,'prompt'=>$prompt,'consent'=>true,'last_dispatch'=>0,'release'=>getenv('STRATUS2_RELEASE') ?: 'development'], 'photo_queued',$jobId);
        // Cron retries dispatch if SQS is temporarily unavailable; the durable job is already recorded.
        try {$this->dispatch($id);} catch (\Throwable) {}
        return $this->publicJob($this->get($id,$uid));
    }
    private function token(array $job): string {
        $key=getenv('WORKER_HMAC_SECRET');if (!$key || strlen($key)<32) {throw new \RuntimeException('Worker authentication unavailable');}
        return hash_hmac('sha256','photo:'.$job['id'].':'.$job['user_id'].':'.$job['created_at'],$key);
    }
    public function workerJob(string $id, string $authorization): array {
        $job=$this->get($id);
        if (!hash_equals('Bearer '.$this->token($job),$authorization)) {throw new \OCP\Files\NotFoundException('Not found');}
        if ((int)$job['created_at']<time()-86400 || !in_array($job['state'],['photo_queued','photo_processing','photo_ready'],true)) {throw new \DomainException('Job inactive');}
        return $job;
    }
    public function dispatch(string $id): void {
        $job=$this->get($id);
        if ((int)$job['created_at']<time()-86400) {$this->expire($id);return;}
        if ($job['state']==='photo_processing') {
            if (($job['data']['started_at'] ?? 0)<time()-1200) {$this->fail($id,'ambiguous');}
            return;
        }
        if ($job['state']==='photo_saving') {$this->save($id,$job['user_id']);return;}
        if ($job['state']!=='photo_queued' || $job['data']['last_dispatch']>time()-600) {return;}
        $this->enabled();$this->billing->requirePaid($job['user_id']);
        $queue=getenv('PHOTO_QUEUE_URL');if (!$queue) {throw new \RuntimeException('Photo queue is not configured');}
        AnalysisQueue::client()->sendMessage(['QueueUrl'=>$queue,'MessageBody'=>json_encode(['job_id'=>$id,'token'=>$this->token($job),'schema_version'=>1],JSON_THROW_ON_ERROR)]);
        $this->store->mutate($id,function(&$j){$j['data']['last_dispatch']=time();});
    }
    public function claim(string $id): array {return $this->withLock($id,function() use ($id){
        $job=$this->get($id);
        if ($job['state']!=='photo_queued') {return ['claimed'=>false];}
        $this->enabled();$this->billing->requirePaid($job['user_id']);$file=$this->source($job);
        return $this->claimSource($job,$file);
    });}
    private function claimSource(array $job, File $file): array {
        $stream=$file->fopen('rb');$hash=hash_init('sha256');try {hash_update_stream($hash,$stream);} finally {fclose($stream);}
        $sha=hash_final($hash);
        $this->store->mutate($job['id'],function(&$j) use ($sha){$j['state']='photo_processing';$j['data']['started_at']=time();$j['data']['source_sha256']=$sha;});
        return ['claimed'=>true,'prompt'=>$job['data']['prompt'],'sha256'=>$sha,'consent'=>true,'mime'=>$file->getMimeType()];
    }
    private function folder(array $job): \OCP\Files\SimpleFS\ISimpleFolder {
        $name='photo_'.hash('sha256',$job['user_id']);
        try {return $this->appData->getFolder($name);} catch (\OCP\Files\NotFoundException) {return $this->appData->newFolder($name);}
    }
    private function outputParent(\OCP\Files\Folder $home, string $path): \OCP\Files\Folder {
        $parent=$home;
        foreach (explode('/',Planner::safePath($path)) as $part) {
            if ($part==='' || $part==='.') {continue;}
            $parent=$parent->nodeExists($part) ? $parent->get($part) : $parent->newFolder($part);
            if (!$parent instanceof \OCP\Files\Folder) {throw new \DomainException('Destination folder is not a folder');}
        }
        return $parent;
    }
    public function accept(string $id, string $png, string $model): void {$this->withLock($id,function() use ($id,$png,$model){
        $job=$this->get($id);
        $sha=hash('sha256',$png);
        if ($job['state']==='photo_ready' && ($job['data']['result_sha256'] ?? '')===$sha) {return;}
        if ($job['state']!=='photo_processing') {throw new \DomainException('Job inactive');}
        if (strlen($png)>self::MAX_BYTES || !str_starts_with($png,"\x89PNG\r\n\x1a\n")) {throw new \InvalidArgumentException('Invalid generated image');}
        $info=@getimagesizefromstring($png);
        if (!$info || $info[2]!==IMAGETYPE_PNG || $info[0]*$info[1]>40000000) {throw new \InvalidArgumentException('Invalid generated image dimensions');}
        if (!preg_match('/^[a-zA-Z0-9._-]{1,100}$/D',$model)) {throw new \InvalidArgumentException('Invalid model identifier');}
        $this->source($job);$folder=$this->folder($job);
        try {$file=$folder->getFile($id.'.png');} catch (\OCP\Files\NotFoundException) {$file=$folder->newFile($id.'.png');}
        $file->putContent($png);
        $this->store->mutate($id,function(&$j) use ($sha,$model){$j['state']='photo_ready';$j['data']['result_sha256']=$sha;$j['data']['model']=$model;});
    });}
    public function result(string $id, string $uid): string {
        $job=$this->get($id,$uid);
        if (!in_array($job['state'],['photo_ready','photo_saving','photo_saved'],true) || $job['created_at']<time()-86400) {throw new \DomainException('Preview expired or unavailable');}
        return $this->folder($job)->getFile($id.'.png')->getContent();
    }
    public function save(string $id, string $uid): array {return $this->withLock($id,function() use ($id,$uid){
        $job=$this->get($id,$uid);if ($job['state']==='photo_saved') {return $this->publicJob($job);}
        if (!in_array($job['state'],['photo_ready','photo_saving'],true)) {throw new \DomainException('The result is not ready to save');}
        $file=$this->source($job);$home=$this->root->getUserFolder($uid);$target=$job['data']['output_path'] ?? null;
        $parent=$target!==null ? $this->outputParent($home,dirname($target)) : $file->getParent();
        if (!$parent->isCreatable()) {throw new \DomainException('The source folder is not writable');}
        $name=$job['data']['output_name'] ?? ($target!==null ? basename($target) : (mb_strcut(pathinfo($file->getName(),PATHINFO_FILENAME),0,100,'UTF-8').'__enhanced__'.$id.'.png'));
        $bytes=$this->result($id,$uid);
        $this->store->mutate($id,function(&$j) use ($name){$j['state']='photo_saving';$j['data']['output_name']=$name;});
        if ($parent->nodeExists($name)) {
            $output=$parent->get($name);
            if (!$output instanceof File || hash('sha256',$output->getContent())!==$job['data']['result_sha256']) {throw new \DomainException('The output name is occupied; no file was overwritten.');}
        } else {
            $free=$home->getFreeSpace();if ($free>=0 && $free<strlen($bytes)) {throw new \DomainException('Not enough storage. Increase your allowance or free space.');}
            $output=$parent->newFile($name,$bytes);
        }
        $this->store->mutate($id,function(&$j) use ($output,$home){$j['state']='photo_saved';$j['data']['saved_path']=$home->getRelativePath($output->getPath());$j['data']['saved_file_id']=$output->getId();});
        return $this->publicJob($this->get($id,$uid));
    });}
    public function cancel(string $id, string $uid): array {return $this->withLock($id,function() use ($id,$uid){
        $job=$this->get($id,$uid);
        if (in_array($job['state'],['photo_saved','photo_saving'],true)) {throw new \DomainException('A saved copy remains in Files.');}
        $this->store->mutate($id,function(&$j){$j['state']='photo_cancelled';});
        $this->deleteTemporary($job);return $this->publicJob($this->get($id,$uid));
    });}
    private function deleteTemporary(array $job): void {
        try {$this->folder($job)->getFile($job['id'].'.png')->delete();} catch (\OCP\Files\NotFoundException) {}
    }
    public function expire(string $id): void {
        $job=$this->get($id);$this->deleteTemporary($job);
        $this->store->mutate($id,function(&$j){$j['state']=isset($j['data']['saved_path']) ? 'photo_archived':'photo_expired';});
    }
    public function fail(string $id, string $reason): void {
        $this->store->mutate($id,function(&$j) use ($reason){
            if (!in_array($j['state'],self::ACTIVE,true)) {return;}
            $j['state']=$reason==='ambiguous' ? 'photo_ambiguous':'photo_failed';
            $j['data']['error']=$reason==='ambiguous' ? 'Generation was interrupted. It was not automatically repeated. You can start another improvement.':'The photo could not be improved. Try different instructions or another photo.';
        });
    }
    public function publicJob(array $job): array {
        $data=$job['data'];return ['id'=>$job['id'],'state'=>$job['state'],'path'=>$data['path'],'prompt'=>$data['prompt'],
            'saved_path'=>$data['saved_path'] ?? null,'error'=>$data['error'] ?? null,'model'=>$data['model'] ?? null,
            'preview_available'=>in_array($job['state'],['photo_ready','photo_saving','photo_saved'],true) && $job['created_at']>=time()-86400];
    }
}
