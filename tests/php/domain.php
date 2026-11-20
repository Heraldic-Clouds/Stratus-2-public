<?php
declare(strict_types=1);
require __DIR__.'/../../apps/stratus2_ai/vendor/autoload.php';
require __DIR__.'/../../apps/stratus2_ai/lib/Service/Planner.php';
require __DIR__.'/../../apps/stratus2_ai/lib/Service/StripeGateway.php';
require __DIR__.'/../../apps/stratus2_ai/lib/Service/BillingPolicy.php';
require __DIR__.'/../../nextcloud/primary-storage-guard.php';
use OCA\Stratus2AI\Service\Planner;
use OCA\Stratus2AI\Service\StripeGateway;
use OCA\Stratus2AI\Service\BillingPolicy;
function check(bool $condition, string $message): void {if (!$condition) {throw new RuntimeException($message);}}
$metadata=new DOMDocument();
check($metadata->load(__DIR__.'/../../apps/stratus2_ai/appinfo/info.xml') && $metadata->schemaValidate(__DIR__.'/../fixtures/nextcloud-info.xsd'),'Nextcloud app registration does not match the official schema');
foreach (['../secret','one/../secret','one\\secret',"one\0secret"] as $path) {
    try {Planner::safePath($path);throw new RuntimeException('Traversal accepted');} catch (InvalidArgumentException) {}
}
$files=[['id'=>4,'path'=>'source/photo.JPG','mime'=>'image/jpeg','etag'=>'one'],['id'=>5,'path'=>'source/copy.JPG','mime'=>'image/jpeg','etag'=>'two']];
$a=['sha256'=>str_repeat('a',64),'category'=>'Photos','filename'=>'A dog / ../ Park','description'=>'dog','phash'=>'aaaaaaaaaaaaaaaa','blur_score'=>1000];
$plan=Planner::build($files,['4'=>$a,'5'=>$a],'source');
check($plan[0]['to']==='source/Photos/a_dog_park__4.JPG','Safe name or original extension not preserved');
check(str_contains($plan[1]['to'],'to_delete/Duplicates/'),'Exact duplicate not routed');
$a['blur_score']=0;
check(str_contains(Planner::build([$files[0]],['4'=>$a],'source')[0]['to'],'to_delete/Blurry/'),'Blur not routed');
check($plan[1]['similar_to']==4,'Perceptual similarity not flagged');
$body='{"type":"test"}';$timestamp=1700000000;$secret='local-test-secret';
$signature='t='.$timestamp.',v1='.hash_hmac('sha256',$timestamp.'.'.$body,$secret);
check(StripeGateway::verify($body,$signature,$secret,$timestamp)['type']==='test','Valid signature rejected');
foreach ([[$body.'x',$signature,$timestamp],[$body,$signature,$timestamp+301],[$body,'t=1,v1=wrong',$timestamp]] as [$candidate,$header,$now]) {
    try {StripeGateway::verify($candidate,$header,$secret,$now);throw new RuntimeException('Invalid webhook accepted');} catch (InvalidArgumentException) {}
}
echo "PASS: traversal rejection, collision-safe filenames, extension preservation, duplicate/blur routing, similarity and webhook authentication\n";
$subscription=['status'=>'active','items'=>['data'=>[['price'=>['id'=>'price_storage']]]],'latest_invoice'=>['status'=>'open']];
check(!BillingPolicy::entitled($subscription,'price_storage'),'Unpaid asynchronous subscription received paid storage');
$subscription['latest_invoice']['status']='paid';
check(BillingPolicy::entitled($subscription,'price_storage'),'Paid active subscription rejected');
check(!BillingPolicy::usesPrice($subscription,'price_unrelated'),'Unrelated Stripe subscription was not filtered');
$subscription['status']='past_due';
check(!BillingPolicy::entitled($subscription,'price_storage'),'Past-due subscription retained paid storage');
$subscription['status']='canceled';
check(!BillingPolicy::entitled($subscription,'price_storage'),'Canceled subscription retained paid storage');
$subscription['status']='trialing';
check(!BillingPolicy::entitled($subscription,'price_storage'),'Unpaid trial received paid access');
echo "PASS: paid storage requires a matching price and a paid current invoice; unrelated subscriptions are ignored\n";
$capacity=['items'=>['data'=>[['price'=>['id'=>'base'],'quantity'=>1],['price'=>['id'=>'addon'],'quantity'=>99]]]];
check(BillingPolicy::capacity($capacity,'base','addon')===10000000000000,'Maximum storage is not exactly 10 decimal TB');
check(BillingPolicy::monthlyCents(0)===1000 && BillingPolicy::monthlyCents(99)===20800,'Monthly base/add-on totals are incorrect');
foreach ([-1,100] as $invalid) {try {BillingPolicy::validateBlocks($invalid);throw new RuntimeException('Invalid add-on count accepted');} catch (InvalidArgumentException) {}}
$capacity['items']['data'][1]['quantity']=100;
try {BillingPolicy::capacity($capacity,'base','addon');throw new RuntimeException('Over-limit webhook quantity accepted');} catch (DomainException) {}
$capacity['items']['data'][1]['quantity']=1;$capacity['items']['data'][0]['quantity']=2;
try {BillingPolicy::capacity($capacity,'base','addon');throw new RuntimeException('Multiple base units accepted');} catch (DomainException) {}
echo "PASS: $10 base, $2 add-ons, 10 TB ceiling, malformed quantities and duplicate base rejection\n";
$http=new class implements \Stripe\HttpClient\ClientInterface {
    public array $calls=[];
    public function request($method,$absUrl,$headers,$params,$hasFile,$apiMode='v1',$maxNetworkRetries=null): array {
        $this->calls[]=compact('method','absUrl','headers','params');
        return ['{"object":"subscription","id":"sub_transport","latest_invoice":{"object":"invoice","id":"in_transport","status":"paid"}}',200,[]];
    }
};
$oldKey=getenv('STRIPE_SECRET_KEY');
try {
    putenv('STRIPE_SECRET_KEY=local-transport-only');
    \Stripe\ApiRequestor::setHttpClient($http);
    $loaded=(new StripeGateway())->request('GET','subscriptions/sub_transport',['expand'=>['latest_invoice']]);
    check($loaded['latest_invoice']['status']==='paid','Stripe SDK failed to return the expanded invoice');
    check($http->calls[0]['params']['expand']===['latest_invoice'],'Stripe GET expansion was not transmitted');
    check(in_array('Stripe-Version: 2026-09-30.endive',$http->calls[0]['headers'],true),'Stripe SDK API version differs from the webhook');
    echo "PASS: Stripe SDK transports invoice expansion with the pinned webhook API version\n";
} finally {
    \Stripe\ApiRequestor::setHttpClient(null);
    putenv($oldKey===false ? 'STRIPE_SECRET_KEY' : 'STRIPE_SECRET_KEY='.$oldKey);
}
$previousEnv=[];
foreach (['STRATUS2_ENVIRONMENT','STRATUS2_STRIPE_LIVE_READ_ONLY','STRIPE_SECRET_KEY'] as $name) {
    $previousEnv[$name]=getenv($name);
}
try {
    putenv('STRATUS2_ENVIRONMENT=staging');
    putenv('STRIPE_SECRET_KEY=rk_live_'.'fixture');
    $gateway=new StripeGateway();
    putenv('STRATUS2_STRIPE_LIVE_READ_ONLY=0');
    try {$gateway->request('GET','subscriptions/sub_transport');throw new LogicException('Live key accepted without opt-in');}
    catch (RuntimeException $e) {check(str_contains($e->getMessage(),'sandbox'),'Wrong staging key error');}
    putenv('STRATUS2_STRIPE_LIVE_READ_ONLY=1');
    \Stripe\ApiRequestor::setHttpClient($http);
    $previousCalls=count($http->calls);
    $gateway->request('GET','subscriptions/sub_transport');
    $gateway->request('GET','subscriptions/search',['query'=>"metadata['nextcloud_uid']:'fixture'"]);
    $gateway->request('POST','billing_portal/sessions',['customer'=>'cus_fixture']);
    foreach ([['POST','checkout/sessions'],['POST','subscriptions/sub_transport'],['GET','customers']] as [$method,$path]) {
        try {$gateway->request($method,$path);throw new LogicException('Unapproved live request accepted');}
        catch (DomainException) {}
    }
    check(count($http->calls)===$previousCalls+3,'Unapproved request reached Stripe');
    echo "PASS: staging permits live subscription reads and portal sessions while blocking direct billing changes\n";
} finally {
    \Stripe\ApiRequestor::setHttpClient(null);
    foreach ($previousEnv as $name=>$value) {putenv($value===false ? $name : $name.'='.$value);}
}
$directory=sys_get_temp_dir().'/stratus2_guard_'.bin2hex(random_bytes(8));mkdir($directory);$marker=$directory.'/identity';
try {
    \Stratus2\Bootstrap\PrimaryStorageGuard::check($marker,'https://gateway.example','original',false);
    \Stratus2\Bootstrap\PrimaryStorageGuard::check($marker,'https://gateway.example','original',true);
    try {\Stratus2\Bootstrap\PrimaryStorageGuard::check($marker,'https://gateway.example','different',true);throw new RuntimeException('Unsafe primary backend change allowed');} catch (RuntimeException $e) {check(str_contains($e->getMessage(),'migration'),'Wrong guard failure');}
    unlink($marker);
    try {\Stratus2\Bootstrap\PrimaryStorageGuard::check($marker,'https://gateway.example','original',true);throw new RuntimeException('Existing metadata silently adopted');} catch (RuntimeException $e) {check(str_contains($e->getMessage(),'migration'),'Wrong adoption failure');}
    $prefix='stratus2-staging/urn:oid:';
    \Stratus2\Bootstrap\PrimaryStorageGuard::check($marker,'https://gateway.example','original',false,$prefix);
    \Stratus2\Bootstrap\PrimaryStorageGuard::check($marker,'https://gateway.example','original',true,$prefix);
    try {\Stratus2\Bootstrap\PrimaryStorageGuard::check($marker,'https://gateway.example','original',true);throw new LogicException('Staging prefix removal accepted');}
    catch (RuntimeException $e) {check(str_contains($e->getMessage(),'migration'),'Wrong storage prefix failure');}
    echo "PASS: primary storage changes and existing-data adoption require a verified migration\n";
} finally {if (is_file($marker)) {unlink($marker);}rmdir($directory);}
