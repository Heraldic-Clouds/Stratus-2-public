<?php
declare(strict_types=1);
chdir('/var/www/html');require 'lib/base.php';
if (getenv('STRATUS2_TEST_MODE')!=='1') {throw new RuntimeException('Isolated test only');}
$users=\OCP\Server::get(\OCP\IUserManager::class);$config=\OCP\Server::get(\OCP\IConfig::class);
$app=new \OCA\Stratus2AI\AppInfo\Application();
$uid='subscriber_'.bin2hex(random_bytes(5));$user=$users->createUser($uid,bin2hex(random_bytes(32)));
$old=[];foreach (['STRATUS2_ENVIRONMENT','STRATUS2_STRIPE_LIVE_READ_ONLY','STRIPE_SECRET_KEY','STRIPE_LEGACY_PRICE_ID','STRIPE_ADDON_PRICE_ID'] as $key) {$old[$key]=getenv($key);}
putenv('STRATUS2_ENVIRONMENT=staging');putenv('STRATUS2_STRIPE_LIVE_READ_ONLY=1');putenv('STRIPE_SECRET_KEY=rk_live_fixture');
putenv('STRIPE_LEGACY_PRICE_ID=price_legacy');putenv('STRIPE_ADDON_PRICE_ID=price_addon');
$transport=new class($uid) implements \Stripe\HttpClient\ClientInterface {
    public array $calls=[];
    public function __construct(private string $uid) {}
    public function request($method,$url,$headers,$params,$hasFile,$apiMode='v1',$maxNetworkRetries=null): array {
        $this->calls[]=[$method,$url,$params];
        if ($method!=='get') {throw new RuntimeException('Unexpected live billing change');}
        $sub=['object'=>'subscription','id'=>'sub_fixture','customer'=>'cus_fixture','status'=>'active','metadata'=>['nextcloud_uid'=>$this->uid],
            'items'=>['data'=>[['price'=>['id'=>'price_legacy'],'quantity'=>1]]],'latest_invoice'=>['status'=>'paid']];
        if (str_ends_with($url,'/subscriptions/search')) {
            if (($params['query'] ?? '')!=="metadata['nextcloud_uid']:'".$this->uid."'") {throw new RuntimeException('Wrong identity query');}
            return [json_encode(['object'=>'search_result','data'=>[$sub],'has_more'=>false]),200,[]];
        }
        if (str_ends_with($url,'/subscriptions/sub_fixture')) {return [json_encode($sub),200,[]];}
        throw new RuntimeException('Unexpected provider path');
    }
};
\Stripe\ApiRequestor::setHttpClient($transport);
try {
    $billing=$app->getContainer()->get(\OCA\Stratus2AI\Service\BillingAccount::class);
    if (!$billing->paid($uid)) {throw new RuntimeException('Existing live subscriber not recognized');}
    if ($config->getUserValue($uid,'stratus2_ai','stripe_customer','')!=='cus_fixture') {throw new RuntimeException('Customer was not linked');}
    if (count($transport->calls)!==2) {throw new RuntimeException('Unexpected Stripe requests');}
    echo "PASS: production subscription identified for the same staging user with live reads only\n";
} finally {
    \Stripe\ApiRequestor::setHttpClient(null);
    foreach ($old as $key=>$value) {putenv($value===false ? $key:$key.'='.$value);}
    $user->delete();
}
