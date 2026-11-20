<?php
declare(strict_types=1);
namespace OCA\StratusTransfer\Command;
use OCP\IUserManager;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCA\StratusTransfer\Service\Transfer;
use OCA\StratusTransfer\Service\Receipts;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
final class Smoke extends Command {
    public function __construct(private IUserManager $users,private IRootFolder $root,private IConfig $config,private Transfer $transfer,private Receipts $receipts) {parent::__construct();}
    protected function configure(): void {$this->setName('stratus-transfer:smoke')->setDescription('Exercise transfer isolation and safe upload fallback in CI');}
    protected function execute(InputInterface $input,OutputInterface $output): int {
        if (getenv('STRATUS2_TEST_MODE')!=='1') {throw new \RuntimeException('Only available in isolated CI');}
        $uid='transfer_test_'.bin2hex(random_bytes(5));$other=$uid.'_other';$a=$this->users->createUser($uid,bin2hex(random_bytes(32)));$b=$this->users->createUser($other,bin2hex(random_bytes(32)));
        $flag=$this->config->getAppValue('stratus_transfer','direct_downloads','0');
        try {
            $this->config->setAppValue('stratus_transfer','direct_downloads','0');
            $a->setQuota('1 MB');$folder=$this->root->getUserFolder($uid)->newFolder('Transfer');$file=$folder->newFile('file.txt','transfer fixture');
            $id=$file->getId();$etag=$file->getEtag();$plan=$this->transfer->download($uid,$id,$etag);
            if ($plan['mode']!=='proxy' || isset($plan['url'])) {throw new \RuntimeException('Disabled transfers issued a URL');}
            try {$this->transfer->download($other,$id,$etag);throw new \RuntimeException('File owner isolation failed');} catch (\OCP\Files\NotFoundException) {}
            try {$this->receipts->get($other,$plan['ticket']);throw new \RuntimeException('Ticket owner isolation failed');} catch (\OCP\Files\NotFoundException) {}
            $this->transfer->progress($uid,$plan['ticket'],10);$this->transfer->progress($uid,$plan['ticket'],4);$this->transfer->progress($uid,$plan['ticket'],10);
            if ($this->receipts->summary($uid)['proxy_bytes']!==10) {throw new \RuntimeException('Retry accounting double counted bytes');}
            $this->config->setAppValue('stratus_transfer','direct_downloads','1');
            $direct=$this->transfer->download($uid,$id,$etag);
            if ($direct['mode']!=='direct' || $direct['expires_at']>time()+300) {throw new \RuntimeException('Direct download was not scoped');}
            parse_str(parse_url($direct['url'],PHP_URL_QUERY),$signed);
            if (!str_contains($signed['X-Amz-SignedHeaders'] ?? '','if-match')) {throw new \RuntimeException('Object precondition was not signed');}
            $http=new \GuzzleHttp\Client();
            $range=$http->get($direct['url'],['headers'=>$direct['headers']+['Range'=>'bytes=3-8'],'timeout'=>10]);
            if ($range->getStatusCode()!==206 || (string)$range->getBody()!==substr('transfer fixture',3,6)) {throw new \RuntimeException('Direct range response failed');}
            if ($file->getId()!==$id || $file->getEtag()!==$etag) {throw new \RuntimeException('Download mutated Nextcloud metadata');}
            $this->config->setAppValue('stratus_transfer','direct_uploads','1');
            if ($this->transfer->upload($uid,'Transfer',1)['direct_uploads']!==false) {throw new \RuntimeException('Unsafe direct uploads enabled');}
            try {$this->transfer->upload($uid,'Transfer',2*1024*1024);throw new \RuntimeException('Quota was ignored');} catch (\LengthException) {}
            $file->move($folder->getPath().'/renamed.txt');
            if ($folder->get('renamed.txt')->getId()!==$id || $folder->get('renamed.txt')->getEtag()!==$etag) {throw new \RuntimeException('Transfer changed file metadata');}
            $output->writeln('PASS: direct S3 ranges, signed object preconditions, file/ticket isolation, idempotent metrics, quota enforcement and safe upload fallback');return 0;
        } finally {$this->config->setAppValue('stratus_transfer','direct_downloads',$flag);$this->config->deleteAppValue('stratus_transfer','direct_uploads');$a->delete();$b->delete();}
    }
}
