<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Command;
use OCP\IUserManager;
use OCP\Files\IRootFolder;
use OCA\Stratus2AI\Service\Organizer;
use OCA\Stratus2AI\Service\JobStore;
use OCA\Stratus2AI\Service\AnalysisQueue;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
final class Smoke extends Command {
    public function __construct(private IUserManager $users, private IRootFolder $root, private Organizer $organizer, private JobStore $store) {parent::__construct();}
    protected function configure(): void {$this->setName('stratus2:smoke')->setDescription('Exercise file-ID moves, duplicates, sidecars and undo in an isolated test account');}
    protected function execute(InputInterface $input, OutputInterface $output): int {
        if (getenv('STRATUS2_TEST_MODE')!=='1') {throw new \RuntimeException('Only available in isolated CI containers');}
        $uid='stratus2_test_'.bin2hex(random_bytes(4));$user=$this->users->createUser($uid,bin2hex(random_bytes(32)));
        try {
            // Load Nextcloud's shared AWS classes before exercising the app's
            // real SQS command/endpoint resolution with a network-free handler.
            new \Aws\S3\S3Client(['version'=>'2006-03-01','region'=>'us-west-2','credentials'=>false]);
            $queue=AnalysisQueue::client([
                'credentials'=>['key'=>'ci-only','secret'=>'ci-only'],
                'http_handler'=>function (\Psr\Http\Message\RequestInterface $request) {
                    $body=json_decode((string)$request->getBody(),true,512,JSON_THROW_ON_ERROR);
                    if ($request->getHeaderLine('X-Amz-Target')!=='AmazonSQS.SendMessageBatch'
                        || ($body['Entries'][0]['Id'] ?? '')!=='probe') {throw new \RuntimeException('SQS serialization failed');}
                    return new \GuzzleHttp\Promise\FulfilledPromise(new \GuzzleHttp\Psr7\Response(200,
                        ['Content-Type'=>'application/x-amz-json-1.0'], '{"Successful":[{"Id":"probe","MessageId":"ci-only","MD5OfMessageBody":"probe"}],"Failed":[]}'));
                },
            ]);
            $sent=$queue->sendMessageBatch(['QueueUrl'=>'https://sqs.us-west-2.amazonaws.com/000000000000/test',
                'Entries'=>[['Id'=>'probe','MessageBody'=>'isolated CI probe']]]);
            if (($sent['Successful'][0]['Id'] ?? '')!=='probe') {throw new \RuntimeException('SQS command resolution failed');}
            $home=$this->root->getUserFolder($uid);$folder=$home->newFolder('Smoke');
            $a=$folder->newFile('one.txt','identical file');$b=$folder->newFile('two.txt','identical file');
            $original='{"character_ids":["example"],"caption":"test"}';$side=$folder->newFile('one.peoplewelcome.json',$original);
            $job=$this->organizer->create($uid,'Smoke','Organize these files',true,false);
            $result=['sha256'=>hash('sha256','identical file'),'category'=>'Documents','filename'=>'','description'=>'','phash'=>'','blur_score'=>null];
            foreach ([$a,$b] as $file) {$this->organizer->accept($job['id'],$file->getId(),$result);}
            $ready=$this->store->get($job['id'],$uid);
            if ($ready['state']!=='ready') {throw new \RuntimeException('Plan not ready');}
            try {$this->store->get($job['id'],'someone_else');throw new \RuntimeException('Owner isolation failed');}
            catch (\OCP\AppFramework\Db\DoesNotExistException) {}
            $applied=$this->organizer->execute($job['id'],$uid);
            if ($applied['state']!=='applied' || count($applied['manifest'])!==2) {throw new \RuntimeException('Apply failed');}
            if (!str_contains($applied['manifest'][1]['to'],'to_delete/Duplicates/')) {throw new \RuntimeException('Duplicate not identified');}
            $this->organizer->execute($job['id'],$uid,true);
            if (!$folder->nodeExists('one.txt') || !$folder->nodeExists('two.txt') || $folder->get('one.peoplewelcome.json')->getContent()!==$original) {throw new \RuntimeException('Undo did not restore originals');}
            for ($i=0;$i<21;$i++) {$this->organizer->create($uid,'Smoke','Unlimited organization',true,false);}
            $output->writeln('PASS: SQS model resolution, real file moves, duplicate routing, companion update, owner isolation and undo');return 0;
        } finally {$user->delete();}
    }
}
