<?php
declare(strict_types=1);
require __DIR__.'/../apps/stratus2_ai/vendor/autoload.php';
$options=getopt('',['settings-file:','environment:','confirm-production']);
try {
    $environment=$options['environment'] ?? 'staging';
    if (!in_array($environment,['staging','production'],true)) {throw new RuntimeException('Unknown environment');}
    if ($environment==='production' && !isset($options['confirm-production'])) {throw new RuntimeException('Production requires explicit confirmation');}
    $path=realpath($options['settings-file'] ?? '');$root=realpath(__DIR__.'/..');
    if (!$path || !str_starts_with($path,$root.DIRECTORY_SEPARATOR)) {throw new RuntimeException('Use a private JSON settings file inside this checkout');}
    $settings=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$key=getenv('STRIPE_SETUP_KEY') ?: ($settings['STRIPE_SECRET_KEY'] ?? '');
    if (!preg_match($environment==='staging' ? '/^[sr]k_test_/':'/^[sr]k_live_/',$key)) {throw new RuntimeException('The key does not match the environment');}
    $client=new \Stripe\StripeClient(['api_key'=>$key,'stripe_version'=>'2026-09-30.endive','max_network_retries'=>2]);
    $request=fn($method,$resource,$params=[],$idempotency=null)=>$client->request($method,'/v1/'.$resource,$params,$idempotency ? ['idempotency_key'=>$idempotency]:[])->toArray();
    $prefix='stratus2_'.$environment.'_v2';
    if ($environment==='production' && !empty($settings['STRIPE_PRICE_ID'])) {$settings['STRIPE_LEGACY_PRICE_ID'] ??= $settings['STRIPE_PRICE_ID'];}
    foreach ([['base','Stratus-2 100 GB',1000,'STRIPE_PRICE_ID'],['extra','Stratus-2 additional 100 GB',200,'STRIPE_ADDON_PRICE_ID']] as [$type,$name,$amount,$field]) {
        $lookup=$prefix.'_'.$type.'_monthly';$prices=$request('get','prices',['lookup_keys'=>[$lookup],'active'=>true,'limit'=>2]);
        if (count($prices['data'])>1) {throw new RuntimeException('Duplicate catalog prices require review');}
        if ($prices['data']) {$price=$prices['data'][0];}
        else {
            $product=$request('post','products',['name'=>$name,'metadata'=>['application'=>'stratus2','environment'=>$environment]],$lookup.'_product');
            $price=$request('post','prices',['product'=>$product['id'],'currency'=>'usd','unit_amount'=>$amount,'recurring'=>['interval'=>'month'],'lookup_key'=>$lookup],$lookup.'_price');
        }
        if ($price['unit_amount']!==$amount || $price['currency']!=='usd' || $price['recurring']['interval']!=='month') {throw new RuntimeException('Catalog does not match the published plan');}
        $settings[$field]=$price['id'];
    }
    $origin=$environment==='staging' ? 'https://staging.example.com':'https://stratus.example.com';
    $portal=$request('post','billing_portal/configurations',['business_profile'=>['headline'=>'Manage your Stratus-2 subscription'],
        'default_return_url'=>$origin.'/index.php/apps/stratus2_ai/',
        'features'=>['customer_update'=>['enabled'=>false],'invoice_history'=>['enabled'=>true],'payment_method_update'=>['enabled'=>true],
            'subscription_cancel'=>['enabled'=>true,'mode'=>'at_period_end'],'subscription_update'=>['enabled'=>false]]],$prefix.'_portal');
    $settings['STRIPE_PORTAL_CONFIGURATION_ID']=$portal['id'];
    $events=['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.async_payment_failed',
        'customer.subscription.created','customer.subscription.updated','customer.subscription.deleted',
        'customer.subscription.pending_update_applied','customer.subscription.pending_update_expired','invoice.paid','invoice.payment_failed'];
    $fields=['url'=>$origin.'/index.php/apps/stratus2_ai/billing/webhook','enabled_events'=>$events,'api_version'=>'2026-09-30.endive'];
    if (!empty($settings['STRIPE_WEBHOOK_ENDPOINT_ID'])) {
        $webhook=$request('get','webhook_endpoints/'.$settings['STRIPE_WEBHOOK_ENDPOINT_ID']);
        if ($webhook['url']!==$fields['url'] || empty($settings['STRIPE_WEBHOOK_SECRET'])) {throw new RuntimeException('Verify the existing endpoint and signing secret');}
        $request('post','webhook_endpoints/'.$webhook['id'],['enabled_events'=>$events]);
    } else {
        if ($environment==='production') {throw new RuntimeException('Preserve the existing production endpoint instead of creating a duplicate');}
        $webhook=$request('post','webhook_endpoints',$fields,$prefix.'_webhook');
        $settings['STRIPE_WEBHOOK_ENDPOINT_ID']=$webhook['id'];$settings['STRIPE_WEBHOOK_SECRET']=$webhook['secret'];
    }
    file_put_contents($path,json_encode($settings,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",LOCK_EX);chmod($path,0600);
    echo "Prepared $environment catalog: USD 10/month base and USD 2/month per extra 100 GB. Settings saved privately. Existing subscriptions were not changed.\n";
} catch (Throwable $e) {
    fwrite(STDERR,'Billing setup failed ('.get_class($e)."). Check the private settings, key permissions and environment.\n");exit(1);
}
