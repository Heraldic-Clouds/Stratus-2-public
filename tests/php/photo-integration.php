<?php
declare(strict_types=1);
// Executed inside the actual isolated Nextcloud integration container, never production.
chdir('/var/www/html');require 'lib/base.php';
if (getenv('STRATUS2_TEST_MODE')!=='1') {throw new RuntimeException('Only available in isolated CI containers');}
function photoCheck(bool $value,string $message): void {if (!$value) {throw new RuntimeException($message);}}
$users=\OCP\Server::get(\OCP\IUserManager::class);$root=\OCP\Server::get(\OCP\Files\IRootFolder::class);$config=\OCP\Server::get(\OCP\IConfig::class);
$app=new \OCA\Stratus2AI\AppInfo\Application();$photos=$app->getContainer()->get(\OCA\Stratus2AI\Service\PhotoEnhancement::class);
$uid='photo_paid_'.bin2hex(random_bytes(4));$other='photo_free_'.bin2hex(random_bytes(4));
$paid=$users->createUser($uid,bin2hex(random_bytes(32)));$free=$users->createUser($other,bin2hex(random_bytes(32)));
$prior=[];foreach (['PHOTO_ENHANCEMENT_ENABLED','STRIPE_SECRET_KEY','STRIPE_PRICE_ID','STRIPE_ADDON_PRICE_ID'] as $name) {$prior[$name]=getenv($name);}
putenv('PHOTO_ENHANCEMENT_ENABLED=1');putenv('STRIPE_SECRET_KEY=ci-transport-only');putenv('STRIPE_PRICE_ID=price_ci_base');putenv('STRIPE_ADDON_PRICE_ID=price_ci_extra');
$transport=new class($uid) implements \Stripe\HttpClient\ClientInterface {
    public function __construct(private string $uid) {}
    public function request($method,$absUrl,$headers,$params,$hasFile,$apiMode='v1',$maxNetworkRetries=null): array {
        if (!str_ends_with($absUrl,'/subscriptions/sub_ci_photo')) {throw new RuntimeException('Unexpected Stripe operation in isolated test');}
        return [json_encode(['id'=>'sub_ci_photo','customer'=>'cus_ci_photo','status'=>'active','metadata'=>['nextcloud_uid'=>$this->uid],
            'items'=>['data'=>[['price'=>['id'=>'price_ci_base'],'quantity'=>1]]],'latest_invoice'=>['id'=>'in_ci_photo','status'=>'paid']],JSON_THROW_ON_ERROR),200,[]];
    }
};
\Stripe\ApiRequestor::setHttpClient($transport);
try {
    $config->setUserValue($uid,'stratus2_ai','stripe_customer','cus_ci_photo');$config->setUserValue($uid,'stratus2_ai','stripe_subscription','sub_ci_photo');
    $paid->setQuota('100000000000');
    $free->setQuota('100000000000'); // A manually increased quota is not a paid entitlement.
    $folder=$root->getUserFolder($uid)->newFolder('Photo test');
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $source=$folder->newFile('original.png',$png);$originalId=$source->getId();
    try {$photos->create($other,'anything.png','Colorize',true);throw new RuntimeException('Free account generated an edit');} catch (DomainException) {}
    try {$photos->create($uid,'Photo test/original.png','Colorize',false);throw new RuntimeException('Missing consent accepted');} catch (InvalidArgumentException) {}
    $job=$photos->create($uid,'Photo test/original.png','Colorize',true);
    try {$photos->workerJob($job['id'],'Bearer forged');throw new RuntimeException('Forged worker token accepted');} catch (\OCP\Files\NotFoundException) {}
    $claimed=$photos->claim($job['id']);photoCheck($claimed['claimed'] && $claimed['sha256']===hash('sha256',$png),'Source claim checksum mismatch');
    photoCheck(!$photos->claim($job['id'])['claimed'],'Duplicate delivery repeated generation');
    $photos->accept($job['id'],$png,'gpt-image-2');$photos->accept($job['id'],$png,'gpt-image-2');
    try {$photos->result($job['id'],$other);throw new RuntimeException('Another account accessed the image');} catch (\OCP\AppFramework\Db\DoesNotExistException) {}
    $saved=$photos->save($job['id'],$uid);$again=$photos->save($job['id'],$uid);
    photoCheck($saved['saved_path']===$again['saved_path'] && count($folder->getDirectoryListing())===2,'Saving was not idempotent');
    photoCheck($source->getId()===$originalId && $source->getContent()===$png,'Original was modified');
    $cancelled=$photos->create($uid,'Photo test/original.png','Stylize',true);$photos->cancel($cancelled['id'],$uid);
    photoCheck(!$photos->claim($cancelled['id'])['claimed'],'Cancelled job was claimed');
    $changed=$photos->create($uid,'Photo test/original.png','Stylize',true);$source->putContent($png.'changed');
    try {$photos->claim($changed['id']);throw new RuntimeException('Changed source was accepted');} catch (DomainException) {}
    $source->putContent($png);
    for ($i=0;$i<21;$i++) {$photos->create($uid,'Photo test/original.png','Unlimited improvements',true);}
    echo "PASS: real Nextcloud paid gating, consent, source hash, worker token, duplicate claim, owner isolation, original preservation, save retries, cancellation and changed-source rejection\n";
} finally {
    \Stripe\ApiRequestor::setHttpClient(null);foreach ($prior as $name=>$value) {putenv($value===false ? $name:$name.'='.$value);}
    $paid->delete();$free->delete();
}
