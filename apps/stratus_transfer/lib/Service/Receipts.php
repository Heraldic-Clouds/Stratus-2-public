<?php
declare(strict_types=1);
namespace OCA\StratusTransfer\Service;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
final class Receipts {
    public function __construct(private IAppData $appData,private ILockingProvider $locks) {}
    private function change(string $uid,callable $fn,bool $write=false): mixed {
        $name='user_'.hash('sha256',$uid);$key='stratus_transfer_'.$name;
        // Nextcloud's locking provider fails immediately on contention. Separate
        // tabs and progress requests need a bounded wait for the same receipt.
        $deadline=microtime(true)+5;
        while (true) {
            try {$this->locks->acquireLock($key,ILockingProvider::LOCK_EXCLUSIVE);break;}
            catch (LockedException $error) {
                if (microtime(true)>=$deadline) {throw $error;}
                usleep(random_int(50000,100000));
            }
        }
        try {
            try {$folder=$this->appData->getFolder('receipts');} catch (NotFoundException) {$folder=$this->appData->newFolder('receipts');}
            try {$file=$folder->getFile($name);$state=json_decode($file->getContent(),true,512,JSON_THROW_ON_ERROR);}
            catch (NotFoundException) {$file=$folder->newFile($name);$state=['direct_bytes'=>0,'proxy_bytes'=>0,'tickets'=>[]];$write=true;}
            foreach ($state['tickets'] as $id=>$ticket) {if ($ticket['created']<time()-172800) {unset($state['tickets'][$id]);}}
            $result=$fn($state);
            if ($write) {$file->putContent(json_encode($state,JSON_THROW_ON_ERROR));}
            return $result;
        } finally {$this->locks->releaseLock($key,ILockingProvider::LOCK_EXCLUSIVE);}
    }
    public function create(string $uid,array $data): string {
        return $this->change($uid,function (&$state) use ($data) {
            if (count($state['tickets'])>=100) {throw new \DomainException('Too many recent downloads; use Nextcloud');}
            $id=bin2hex(random_bytes(16));$state['tickets'][$id]=$data+['position'=>0,'created'=>time()];return $id;
        },true);
    }
    public function get(string $uid,string $id): array {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) {throw new NotFoundException();}
        return $this->change($uid,function (&$state) use ($id) {return $state['tickets'][$id] ?? throw new NotFoundException();});
    }
    public function progress(string $uid,string $id,int $position): array {
        return $this->change($uid,function (&$state) use ($id,$position) {
            if (!isset($state['tickets'][$id])) {throw new NotFoundException();}
            $ticket=&$state['tickets'][$id];$next=Policy::progress($ticket['position'],$position,$ticket['size']);
            $counter=$ticket['mode']==='direct' ? 'direct_bytes':'proxy_bytes';
            $state[$counter]+=$next-$ticket['position'];$ticket['position']=$next;
            return ['position'=>$next];
        },true);
    }
    public function summary(string $uid): array {
        return $this->change($uid,fn(&$state)=>['direct_bytes'=>$state['direct_bytes'],'proxy_bytes'=>$state['proxy_bytes'],
            'measurement'=>'browser_reported_saved_bytes','scope'=>'StratusTransfer downloads only; excludes other Nextcloud/WebDAV traffic']);
    }
}
