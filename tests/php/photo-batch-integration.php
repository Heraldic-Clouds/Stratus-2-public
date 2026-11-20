<?php
declare(strict_types=1);
// Run only in the isolated Nextcloud integration container.
chdir('/var/www/html');require 'lib/base.php';
if (getenv('STRATUS2_TEST_MODE')!=='1') {throw new RuntimeException('Isolated test only');}
function batchCheck(bool $value,string $message): void {if (!$value) {throw new RuntimeException($message);}}
$users=\OCP\Server::get(\OCP\IUserManager::class);$root=\OCP\Server::get(\OCP\Files\IRootFolder::class);$config=\OCP\Server::get(\OCP\IConfig::class);
$app=new \OCA\Stratus2AI\AppInfo\Application();
$organizer=$app->getContainer()->get(\OCA\Stratus2AI\Service\Organizer::class);
$photos=$app->getContainer()->get(\OCA\Stratus2AI\Service\PhotoEnhancement::class);
$uid='batch_'.bin2hex(random_bytes(5));$user=$users->createUser($uid,bin2hex(random_bytes(32)));
$prior=[];foreach (['STRATUS2_ENVIRONMENT','PHOTO_ENHANCEMENT_ENABLED'] as $key) {$prior[$key]=getenv($key);}
putenv('STRATUS2_ENVIRONMENT=staging');putenv('PHOTO_ENHANCEMENT_ENABLED=1');
try {
    $config->setUserValue($uid,'stratus2_ai','staging_premium','1');
    $user->setQuota('100000000000');$home=$root->getUserFolder($uid);$dir=$home->newFolder('Family');
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $black=$dir->newFile('black.png',$png);$color=$dir->newFile('color.png',$png);
    $sidecar=$dir->newFile('black.peoplewelcome.json','{"tag":"family"}');
    $job=$organizer->create($uid,'Family','Find black and white photos, colorize them, save results in Colorized and originals in Original',true,false,true);
    $base=['sha256'=>hash('sha256',$png),'category'=>'Photos','filename'=>'photo','description'=>'photo',
        'phash'=>'','blur_score'=>1000,'destination'=>'Colorized','original_destination'=>'Original',
        'edit_prompt'=>'Colorize naturally','rename'=>false,'duplicates_folder'=>'','blurry_folder'=>'','flag_similar'=>false];
    $organizer->accept($job['id'],$black->getId(),$base+['action'=>'edit']);
    $organizer->accept($job['id'],$color->getId(),$base+['action'=>'keep']);
    $job=$organizer->execute($job['id'],$uid);
    batchCheck($job['state']==='batch_running','Batch did not queue the edit');
    $store=$app->getContainer()->get(\OCA\Stratus2AI\Service\JobStore::class);
    batchCheck(count($store->list($uid,false))===1 && count($store->list($uid,true))===1,'Parent and child histories were not filtered separately');
    $child=substr(hash('sha256','stratus2-photo-batch:'.$job['id'].':'.$black->getId()),0,32);
    batchCheck($photos->claim($child)['claimed'],'Edit was not claimable');
    $photos->accept($child,$png,'fixture');
    $job=$organizer->execute($job['id'],$uid);
    batchCheck($job['state']==='batch_done','Batch did not complete');
    batchCheck($home->nodeExists('Family/Original/black__'.$black->getId().'.png'),'Original was not moved');
    batchCheck($home->nodeExists('Family/Original/black__'.$black->getId().'.peoplewelcome.json'),'Companion did not move');
    batchCheck($home->nodeExists('Family/Colorized/black__enhanced__'.$black->getId().'.png'),'Edited file not saved');
    batchCheck($home->nodeExists('Family/color.png'),'Nonmatching image was changed');
    $organizer->execute($job['id'],$uid,true);
    batchCheck($home->nodeExists('Family/black.png') && $home->nodeExists('Family/black.peoplewelcome.json'),'Undo did not restore the original and companion');
    batchCheck($home->nodeExists('Family/Colorized/black__enhanced__'.$black->getId().'.png'),'Undo removed the generated copy');
    echo "PASS: directory selection, selective editing, destination folders, companion, preserved original and undo\n";
} finally {
    foreach ($prior as $key=>$value) {putenv($value===false ? $key:$key.'='.$value);}
    $user->delete();
}
