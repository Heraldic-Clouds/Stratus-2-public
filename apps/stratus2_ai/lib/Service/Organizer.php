<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Service;
use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\IPreview;
use OCP\Lock\ILockingProvider;
final class Organizer {
    public function __construct(private JobStore $store, private IRootFolder $root, private IAppData $appData,
        private IPreview $preview, private ILockingProvider $locks, private PhotoEnhancement $photos, private BillingAccount $billing) {}
    public function estimate(string $userId, string $folder): array {
        $folder=Planner::safePath($folder);$home=$this->root->getUserFolder($userId);
        $node=$folder==='' ? $home:$home->get($folder);
        if (!$node instanceof Folder || $node->getOwner()?->getUID()!==$userId) {throw new \InvalidArgumentException('Choose an owned folder');}
        $files=[];$this->scan($node,$home,$userId,$files);
        $images=count(array_filter($files,fn($file)=>str_starts_with($file['mime'],'image/')));
        return ['files'=>count($files),'images'=>$images,'estimated_cost_usd'=>round($images*0.005,3)];
    }
    public function create(string $userId, string $folder, string $prompt, bool $consent, bool $dispatch = true, bool $batch = false): array {
        if (!$consent) { throw new \InvalidArgumentException('Consent is required before analysis'); }
        if ($batch) {
            if (getenv('PHOTO_ENHANCEMENT_ENABLED')!=='1') {throw new \DomainException('Photo improvement is not enabled');}
            $this->billing->requirePaid($userId);
        }
        if (strlen($prompt) > 4000 || trim($prompt) === '') { throw new \InvalidArgumentException('Enter instructions under 4000 characters'); }
        $folder = Planner::safePath($folder); $home = $this->root->getUserFolder($userId);
        $node = $folder === '' ? $home : $home->get($folder);
        if (!$node instanceof Folder || $node->getOwner()?->getUID() !== $userId) { throw new \InvalidArgumentException('Choose an owned folder'); }
        $files = []; $this->scan($node, $home, $userId, $files);
        if (!$files) { throw new \InvalidArgumentException('No supported files in this folder'); }
        usort($files,fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
        $data = ['kind'=>$batch ? 'photo_batch':'organize','folder'=>$folder,'prompt'=>$prompt,'consent'=>true,'files'=>$files,'results'=>[], 'manifest'=>[], 'last_dispatch'=>0,
            'estimated_cost_usd'=>round(count(array_filter($files, fn($f)=>str_starts_with($f['mime'],'image/'))) * 0.005, 3)];
        $id = $this->store->create($userId,$data);
        if ($dispatch) { $this->dispatch($id); }
        return $this->publicJob($this->store->get($id,$userId));
    }
    private function scan(Folder $folder, Folder $home, string $userId, array &$files): void {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node->getOwner()?->getUID() !== $userId || !$node->isUpdateable()) { continue; }
            if ($node instanceof Folder) {
                if ($node->getName() !== 'to_delete') { $this->scan($node,$home,$userId,$files); }
            } elseif ($node instanceof File && !str_ends_with($node->getName(),'.peoplewelcome.json') && $node->getSize() <= 512*1024*1024) {
                $files[] = ['id'=>$node->getId(),'path'=>ltrim((string)$home->getRelativePath($node->getPath()),'/'),'etag'=>$node->getEtag(),'mime'=>$node->getMimetype(),'size'=>$node->getSize()];
            }
        }
    }
    private function token(array $job): string {
        $key = getenv('WORKER_HMAC_SECRET');
        if (!$key || strlen($key)<32) { throw new \RuntimeException('Worker authentication is not configured'); }
        return hash_hmac('sha256',$job['id'].':'.$job['created_at'],$key);
    }
    public function workerJob(string $id, int $fileId, string $authorization): array {
        $job = $this->store->get($id);
        if (!hash_equals('Bearer '.$this->token($job),$authorization)) { throw new \OCP\Files\NotFoundException('Not found'); }
        if ((int)$job['created_at'] < time()-86400 || !in_array($job['state'],['queued','analyzing'],true)) { throw new \DomainException('Job inactive'); }
        foreach ($job['data']['files'] as $file) { if ((int)$file['id'] === $fileId) { return [$job,$file]; } }
        throw new \OCP\Files\NotFoundException('Not found');
    }
    public function file(array $job, int $fileId): File {
        $files = $this->root->getUserFolder($job['user_id'])->getById($fileId);
        $file = $files[0] ?? null;
        if (!$file instanceof File || $file->getOwner()?->getUID() !== $job['user_id'] || !$file->isUpdateable()) { throw new \OCP\Files\NotFoundException('File unavailable'); }
        return $file;
    }
    public function dispatch(string $id): void {
        $job = $this->store->get($id);
        if (!in_array($job['state'],['queued','analyzing'],true)) { return; }
        if ((int)$job['created_at'] < time()-86400) {
            $this->store->mutate($id,function(&$j){ $j['state']='error'; $j['data']['error']='Analysis expired. Start a new job.'; }); return;
        }
        if ((int)$job['data']['last_dispatch'] > time()-600) { return; }
        $queue = getenv('SQS_QUEUE_URL');
        if (!$queue) { throw new \RuntimeException('Analysis queue is not configured'); }
        $sqs = AnalysisQueue::client();
        $token = $this->token($job);
        foreach (array_chunk($job['data']['files'],10) as $batch) {
            $entries = [];
            foreach ($batch as $file) {
                if (isset($job['data']['results'][(string)$file['id']])) { continue; }
                $entries[] = ['Id'=>(string)$file['id'],'MessageBody'=>json_encode(['job_id'=>$id,'file_id'=>$file['id'],'token'=>$token],JSON_THROW_ON_ERROR)];
            }
            if ($entries) {
                $response = $sqs->sendMessageBatch(['QueueUrl'=>$queue,'Entries'=>$entries]);
                if (!empty($response['Failed'])) { throw new \RuntimeException('Queue dispatch requires retry'); }
            }
        }
        $this->store->mutate($id,function(&$j){
            if (in_array($j['state'],['queued','analyzing'],true)) { $j['state']='analyzing'; $j['data']['last_dispatch']=time(); }
        });
    }
    public function accept(string $id, int $fileId, array $result): void {
        if (!preg_match('/^[a-f0-9]{64}$/D',$result['sha256'] ?? '') || !in_array($result['category'] ?? '',Planner::CATEGORIES,true)
            || !is_string($result['filename'] ?? null) || strlen($result['filename'])>200 || !is_string($result['description'] ?? null)
            || strlen($result['description'])>2000 || (($result['phash'] ?? '') !== '' && !preg_match('/^[a-f0-9]{16}$/D',$result['phash']))
            || (!is_null($result['blur_score'] ?? null) && (!is_numeric($result['blur_score']) || !is_finite((float)$result['blur_score'])))) {
            throw new \InvalidArgumentException('Invalid analysis result');
        }
        $this->store->mutate($id,function(&$job) use ($fileId,$result){
            if (!in_array($job['state'],['queued','analyzing'],true)) { return; }
            if (($job['data']['kind'] ?? '')==='photo_batch') {
                if (!in_array($result['action'] ?? '', ['keep','move','edit'],true) || !is_bool($result['rename'] ?? null) || !is_bool($result['flag_similar'] ?? null)) {throw new \InvalidArgumentException('Invalid photo plan');}
                foreach (['destination','original_destination','edit_prompt','duplicates_folder','blurry_folder'] as $field) {
                    if (!is_string($result[$field] ?? null) || strlen($result[$field])>4000) {throw new \InvalidArgumentException('Invalid photo plan');}
                }
            }
            $record = null;
            foreach ($job['data']['files'] as $f) { if ((int)$f['id']===$fileId) { $record=$f; break; } }
            if (!$record) { throw new \OCP\Files\NotFoundException('File not in job'); }
            if ($this->file($job,$fileId)->getEtag() !== $record['etag']) { throw new \RuntimeException('File changed during analysis'); }
            $job['data']['results'][(string)$fileId]=$result;
            if (count($job['data']['results'])===count($job['data']['files'])) {
                $job['data']['manifest']=($job['data']['kind'] ?? '')==='photo_batch'
                    ? Planner::buildBatch($job['data']['files'],$job['data']['results'],$job['data']['folder'])
                    : Planner::build($job['data']['files'],$job['data']['results'],$job['data']['folder']);
                $job['state']='ready';
            }
        });
        if (isset($result['cache_key']) && preg_match('/^[a-f0-9]{64}$/D',$result['cache_key'])) {
            $job = $this->store->get($id); $folder = $this->cacheFolder($job['user_id']);
            try { $file=$folder->getFile($result['cache_key']); } catch (\OCP\Files\NotFoundException) { $file=$folder->newFile($result['cache_key']); }
            $file->putContent(json_encode($result,JSON_THROW_ON_ERROR));
        }
    }
    private function cacheFolder(string $userId): \OCP\Files\SimpleFS\ISimpleFolder {
        $name='cache_'.hash('sha256',$userId);
        try { return $this->appData->getFolder($name); } catch (\OCP\Files\NotFoundException) { return $this->appData->newFolder($name); }
    }
    public function cached(string $userId, string $key): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/D',$key)) { throw new \InvalidArgumentException('Invalid cache key'); }
        try { return json_decode($this->cacheFolder($userId)->getFile($key)->getContent(),true,512,JSON_THROW_ON_ERROR); }
        catch (\OCP\Files\NotFoundException) { return null; }
    }
    private function parent(Folder $home, string $path): Folder {
        $folder=$home;
        foreach (explode('/',Planner::safePath($path)) as $part) {
            if ($part==='') { continue; }
            if (!$folder->nodeExists($part)) { $folder=$folder->newFolder($part); }
            else { $folder=$folder->get($part); }
            if (!$folder instanceof Folder) { throw new \RuntimeException('Target folder is a file'); }
        }
        return $folder;
    }
    public function execute(string $id, string $userId, bool $undo=false): array {
        if (($this->store->get($id,$userId)['data']['kind'] ?? '')==='photo_batch') {return $this->executeBatch($id,$userId,$undo);}
        $key='stratus2_job_'.$id; $this->locks->acquireLock($key,ILockingProvider::LOCK_EXCLUSIVE);
        try {
            $job=$this->store->get($id,$userId); $active=$undo ? 'undoing':'applying';
            $allowed=$undo ? ['applied','applying','undoing']:['ready','applying'];
            if (!in_array($job['state'],$allowed,true)) { throw new \DomainException('Job cannot be changed in its current state'); }
            $this->store->mutate($id,function(&$j) use ($active,$allowed){
                if (!in_array($j['state'],$allowed,true)) {throw new \DomainException('Job state changed');}
                $j['state']=$active;
            });
            $home=$this->root->getUserFolder($userId);
            $operations=$job['data']['manifest'];
            if ($undo) { $operations=array_reverse($operations,true); }
            foreach ($operations as $index=>$entry) {
                if (($entry['phase']==='moved' && !$undo) || ($entry['phase']==='undone' && $undo)) { continue; }
                $file=$this->file($job,(int)$entry['file_id']);
                if ($undo && isset($entry['sidecar'])) {
                    $side=$this->file($job,(int)$entry['sidecar']['id']);
                    $metadata=json_decode($entry['sidecar']['content'],true,512,JSON_THROW_ON_ERROR);
                    $metadata['nextcloud_file_id']=$entry['file_id'];$metadata['path']=$entry['to'];
                    $expected=json_encode($metadata,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
                    if (!in_array($side->getContent(),[$expected,$entry['sidecar']['content']],true)) {throw new \RuntimeException('Companion was edited after organization; preserve it before undo');}
                }
                $from=$undo ? $entry['to']:$entry['from']; $to=$undo ? $entry['from']:$entry['to'];
                $path=ltrim((string)$home->getRelativePath($file->getPath()),'/');
                if ($path!==$to) {
                    if ($path!==$from || (!$undo && $file->getEtag()!==$entry['etag'])) { throw new \RuntimeException('File changed since preview; start a new analysis'); }
                    if ($home->nodeExists($to)) { throw new \RuntimeException('Destination already exists; no file was overwritten'); }
                    $this->parent($home,dirname($to));
                    // Persist companion details before moving either file, making interrupted moves recoverable.
                    if (!$undo && !isset($entry['sidecar'])) {
                        $source=preg_replace('/\.[^.\/]+$/','',$from).'.peoplewelcome.json';
                        if ($home->nodeExists($source)) {
                            $side=$home->get($source);
                            if ($side instanceof File && $side->getSize()<=65536 && $side->getOwner()?->getUID()===$userId) {
                                $sideContent=$side->getContent();
                                if (!is_array(json_decode($sideContent,true,512,JSON_THROW_ON_ERROR))) {throw new \RuntimeException('Companion must contain a JSON object');}
                                $entry['sidecar']=['id'=>$side->getId(),'from'=>$source,'to'=>preg_replace('/\.[^.\/]+$/','',$to).'.peoplewelcome.json','content'=>$sideContent];
                                $this->store->mutate($id,function(&$j) use ($index,$entry){$j['data']['manifest'][$index]=$entry;});
                            }
                        }
                    }
                    $file->move($home->getPath().'/'.$to);
                }
                if (isset($entry['sidecar'])) {
                    $side=$this->file($job,(int)$entry['sidecar']['id']); $target=$undo ? $entry['sidecar']['from']:$entry['sidecar']['to'];
                    if (ltrim((string)$home->getRelativePath($side->getPath()),'/')!==$target) {
                        if ($home->nodeExists($target)) { throw new \RuntimeException('Companion destination already exists'); }
                        $this->parent($home,dirname($target)); $side->move($home->getPath().'/'.$target);
                    }
                    $content=$entry['sidecar']['content'];
                    if (!$undo) {
                        $metadata=json_decode($content,true,512,JSON_THROW_ON_ERROR);
                        if (!is_array($metadata)) { throw new \RuntimeException('Companion must contain a JSON object'); }
                        $metadata['nextcloud_file_id']=$entry['file_id']; $metadata['path']=$to;
                        $content=json_encode($metadata,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
                    }
                    $side->putContent($content);
                }
                $entry['phase']=$undo ? 'undone':'moved';
                $this->store->mutate($id,function(&$j) use ($index,$entry){$j['data']['manifest'][$index]=$entry;});
            }
            $this->store->mutate($id,function(&$j) use ($undo){$j['state']=$undo ? 'undone':'applied';});
            return $this->publicJob($this->store->get($id,$userId));
        } finally { $this->locks->releaseLock($key,ILockingProvider::LOCK_EXCLUSIVE); }
    }
    private function batchMove(Folder $home, array $job, array &$entry, int $index, string $from, string $to, bool $undo=false): void {
        if ($from===$to) {return;}
        $file=$this->file($job,(int)$entry['file_id']);
        $current=ltrim((string)$home->getRelativePath($file->getPath()),'/');
        if ($current!==$to) {
            if ($current!==$from || (!$undo && $file->getEtag()!==$entry['etag'])) {throw new \RuntimeException('Source file changed since preview');}
            if ($home->nodeExists($to)) {throw new \RuntimeException('Destination already exists');}
            $this->parent($home,dirname($to));
            if (!$undo && !isset($entry['sidecar'])) {
                $source=preg_replace('/\.[^.\/]+$/','',$from).'.peoplewelcome.json';
                if ($home->nodeExists($source)) {
                    $side=$home->get($source);
                    if ($side instanceof File && $side->getSize()<=65536 && $side->getOwner()?->getUID()===$job['user_id']) {
                        $content=$side->getContent();
                        if (!is_array(json_decode($content,true,512,JSON_THROW_ON_ERROR))) {throw new \RuntimeException('Invalid companion');}
                        $entry['sidecar']=['id'=>$side->getId(),'from'=>$source,'to'=>preg_replace('/\.[^.\/]+$/','',$to).'.peoplewelcome.json','content'=>$content];
                        $this->store->mutate($job['id'],function(&$j) use ($index,$entry){$j['data']['manifest'][$index]=$entry;});
                    }
                }
            }
        }
        if (isset($entry['sidecar'])) {
            $side=$this->file($job,(int)$entry['sidecar']['id']);
            $target=$undo ? $entry['sidecar']['from']:$entry['sidecar']['to'];
            $metadata=json_decode($entry['sidecar']['content'],true,512,JSON_THROW_ON_ERROR);
            $metadata['nextcloud_file_id']=$entry['file_id'];$metadata['path']=$entry['sidecar']['to'];
            $updated=json_encode($metadata,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
            if ($undo && !in_array($side->getContent(),[$updated,$entry['sidecar']['content']],true)) {throw new \RuntimeException('Companion was edited after organization');}
            if (ltrim((string)$home->getRelativePath($side->getPath()),'/')!==$target && $home->nodeExists($target)) {throw new \RuntimeException('Companion destination already exists');}
        }
        if ($current!==$to) {$file->move($home->getPath().'/'.$to);}
        if (isset($entry['sidecar'])) {
            if (ltrim((string)$home->getRelativePath($side->getPath()),'/')!==$target) {
                $this->parent($home,dirname($target));$side->move($home->getPath().'/'.$target);
            }
            $side->putContent($undo ? $entry['sidecar']['content']:$updated);
        }
    }
    public function executeBatch(string $id,string $uid,bool $undo=false): array {
        $key='stratus2_job_'.$id;$this->locks->acquireLock($key,ILockingProvider::LOCK_EXCLUSIVE);
        try {
            $job=$this->store->get($id,$uid);
            if (($job['data']['kind'] ?? '')!=='photo_batch') {throw new \DomainException('Not a photo directory job');}
            if ($undo) {
                if (!in_array($job['state'],['batch_done','batch_undoing'],true)) {throw new \DomainException('Job cannot be undone yet');}
                $this->store->mutate($id,function(&$j){$j['state']='batch_undoing';});
                $home=$this->root->getUserFolder($uid);
                foreach (array_reverse($job['data']['manifest'],true) as $index=>$entry) {
                    if (!in_array($entry['phase'],['done','failed'],true)) {continue;}
                    $from=$entry['action']==='edit' ? $entry['original_to']:$entry['to'];
                    $this->batchMove($home,$job,$entry,$index,$from,$entry['from'],true);
                    $this->store->mutate($id,function(&$j) use ($index){$j['data']['manifest'][$index]['phase']='undone';});
                }
                $this->store->mutate($id,function(&$j){$j['state']='batch_undone';});
                return $this->publicJob($this->store->get($id,$uid));
            }
            if (!in_array($job['state'],['ready','batch_running'],true)) {throw new \DomainException('Job cannot be started in its current state');}
            $this->billing->requirePaid($uid);
            $this->store->mutate($id,function(&$j){$j['state']='batch_running';});
            $home=$this->root->getUserFolder($uid);$waiting=false;
            foreach ($job['data']['manifest'] as $index=>$entry) {
                if (in_array($entry['phase'],['skipped','done','failed'],true)) {continue;}
                try {
                    if ($entry['action']==='move') {
                        $this->batchMove($home,$job,$entry,$index,$entry['from'],$entry['to']);
                    } elseif ($entry['action']==='edit') {
                        $childId=substr(hash('sha256','stratus2-photo-batch:'.$id.':'.$entry['file_id']),0,32);
                        try {$child=$this->photos->get($childId,$uid);}
                        catch (\OCP\AppFramework\Db\DoesNotExistException) {
                            $file=$this->file($job,(int)$entry['file_id']);
                            if (ltrim((string)$home->getRelativePath($file->getPath()),'/')!==$entry['from'] || $file->getEtag()!==$entry['etag']) {throw new \RuntimeException('Source file changed since preview');}
                            $this->photos->create($uid,$entry['from'],$entry['edit_prompt'],true,$childId,$entry['to']);
                            $child=$this->photos->get($childId,$uid);
                        }
                        if ((int)$child['data']['file_id']!==(int)$entry['file_id'] || ($child['data']['output_path'] ?? '')!==$entry['to']) {throw new \RuntimeException('Child job mismatch');}
                        if (in_array($child['state'],['photo_queued','photo_processing'],true)) {$waiting=true;continue;}
                        if (in_array($child['state'],['photo_ready','photo_saving'],true)) {$this->photos->save($childId,$uid);$child=$this->photos->get($childId,$uid);}
                        if ($child['state']!=='photo_saved') {throw new \RuntimeException('Photo improvement could not be completed');}
                        $this->batchMove($home,$job,$entry,$index,$entry['from'],$entry['original_to']);
                    }
                    $this->store->mutate($id,function(&$j) use ($index){$j['data']['manifest'][$index]['phase']='done';});
                } catch (\Throwable $e) {
                    $this->store->mutate($id,function(&$j) use ($index){$j['data']['manifest'][$index]['phase']='failed';$j['data']['manifest'][$index]['error']='Could not complete this file; the original was kept.';});
                }
            }
            if (!$waiting) {$this->store->mutate($id,function(&$j){$j['state']='batch_done';});}
            return $this->publicJob($this->store->get($id,$uid));
        } finally {$this->locks->releaseLock($key,ILockingProvider::LOCK_EXCLUSIVE);}
    }
    public function publicJob(array $job): array {
        $data=$job['data']; $manifest=$data['manifest'];
        foreach ($manifest as &$entry) { unset($entry['sidecar']); }
        return ['id'=>$job['id'],'state'=>$job['state'],'total'=>count($data['files']),'completed'=>count($data['results']),
            'estimated_cost_usd'=>$data['estimated_cost_usd'],'manifest'=>$manifest,'error'=>$data['error'] ?? null];
    }
}
