<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Service;
use OCP\IConfig;
use OCP\IUserManager;
final class BillingAccount {
    public function __construct(private IConfig $config, private IUserManager $users, private StripeGateway $stripe) {}
    public function basePrice(): string {return getenv('STRIPE_PRICE_ID') ?: '';}
    public function addonPrice(): string {return getenv('STRIPE_ADDON_PRICE_ID') ?: '';}
    public function origin(): string {
        $origin=getenv('STRATUS2_ORIGIN') ?: 'https://stratus.example.com';
        if (!preg_match('~^https://[a-z0-9.-]+$~D',$origin)) {throw new \RuntimeException('Invalid application origin');}
        return $origin;
    }
    public function matchingBase(array $subscription): string {
        foreach (array_filter([$this->basePrice(),getenv('STRIPE_LEGACY_PRICE_ID') ?: '']) as $price) {
            if (BillingPolicy::usesPrice($subscription,$price)) {return $price;}
        }
        return '';
    }
    public function customer(string $uid): string {return $this->config->getUserValue($uid,'stratus2_ai','stripe_customer','');}
    public function ensureCustomer(string $uid,string $email): string {
        $customer=$this->customer($uid);if ($customer!=='') {return $customer;}
        $fields=['metadata'=>['nextcloud_uid'=>$uid]];if ($email!=='') {$fields['email']=$email;}
        $created=$this->stripe->request('POST','customers',$fields,'stratus2-customer-'.hash('sha256',$uid));
        $this->config->setUserValue($uid,'stratus2_ai','stripe_customer',$created['id']);return $created['id'];
    }
    public function checkoutState(string $uid): array {return json_decode($this->config->getUserValue($uid,'stratus2_ai','stripe_checkout','{}'),true,512,JSON_THROW_ON_ERROR);}
    public function rememberCheckout(string $uid,array $state): void {$this->config->setUserValue($uid,'stratus2_ai','stripe_checkout',json_encode($state,JSON_THROW_ON_ERROR));}
    private function liveStagingSubscription(string $uid): ?array {
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/D',$uid)) {throw new \RuntimeException('Invalid account identity');}
        $matches=[];$page=null;
        do {
            $fields=['query'=>"metadata['nextcloud_uid']:'".$uid."'",'limit'=>100];
            if ($page!==null) {$fields['page']=$page;}
            $result=$this->stripe->request('GET','subscriptions/search',$fields);
            foreach ($result['data'] ?? [] as $sub) {
                if (($sub['metadata']['nextcloud_uid'] ?? '')===$uid && $this->matchingBase($sub)!==''
                    && !in_array($sub['status'],['canceled','incomplete_expired'],true)) {$matches[]=$sub;}
            }
            $page=($result['has_more'] ?? false) ? ($result['next_page'] ?? null):null;
            if (($result['has_more'] ?? false) && !$page) {throw new \RuntimeException('Incomplete subscription search');}
        } while ($page!==null);
        if (count($matches)>1) {throw new \RuntimeException('Multiple storage subscriptions require reconciliation');}
        return $matches[0] ?? null;
    }
    public function subscription(string $uid): ?array {
        if (getenv('STRATUS2_ENVIRONMENT')==='staging' && !StripeGateway::paymentsEnabled()) {
            $match=$this->liveStagingSubscription($uid);
            if ($match) {
                $customer=$match['customer'] ?? '';$subscription=$match['id'] ?? '';
                if (!is_string($customer) || !preg_match('/^cus_[A-Za-z0-9]+$/D',$customer) || !preg_match('/^sub_[A-Za-z0-9]+$/D',$subscription)) {throw new \RuntimeException('Invalid live subscription');}
                $this->config->setUserValue($uid,'stratus2_ai','stripe_customer',$customer);
                $this->config->setUserValue($uid,'stratus2_ai','stripe_subscription',$subscription);
            }
        }
        $id=$this->config->getUserValue($uid,'stratus2_ai','stripe_subscription','');
        if ($id==='') {
            $customer=$this->customer($uid);if ($customer==='') {return null;}
            // Existing $7 accounts predate the stored subscription reference.
            $list=$this->stripe->request('GET','subscriptions',['customer'=>$customer,'status'=>'all','limit'=>100]);
            $matches=array_values(array_filter($list['data'],fn($sub)=>$this->matchingBase($sub)!=='' && ($sub['metadata']['nextcloud_uid'] ?? '')===$uid && !in_array($sub['status'],['canceled','incomplete_expired'],true)));
            if (!$matches) {return null;}
            if (count($matches)!==1) {throw new \RuntimeException('Multiple storage subscriptions require reconciliation');}
            $id=$matches[0]['id'];$this->config->setUserValue($uid,'stratus2_ai','stripe_subscription',$id);
        }
        $subscription=$this->stripe->request('GET','subscriptions/'.rawurlencode($id),['expand'=>['latest_invoice']]);
        if (($subscription['customer'] ?? '')!==$this->customer($uid) || ($subscription['metadata']['nextcloud_uid'] ?? '')!==$uid) {
            throw new \RuntimeException('Subscription ownership could not be verified');
        }
        return $subscription;
    }
    private function stagingPremium(string $uid): bool {
        return getenv('STRATUS2_ENVIRONMENT')==='staging'
            && $this->config->getUserValue($uid,'stratus2_ai','staging_premium','')==='1';
    }
    public function paid(string $uid): bool {
        if ($this->stagingPremium($uid)) {return true;}
        $subscription=$this->subscription($uid);
        if (!$subscription) {return false;}
        $base=$this->matchingBase($subscription);
        if ($base==='' || !$this->entitled($uid,$subscription,$base)) {return false;}
        BillingPolicy::capacity($subscription,$base,$this->addonPrice());
        return true;
    }
    public function requirePaid(string $uid): void {
        if (!$this->paid($uid)) {throw new \DomainException('Photo improvement requires an active paid storage subscription.');}
    }
    private function entitled(string $uid,array $subscription,string $base): bool {
        if (BillingPolicy::entitled($subscription,$base)) {return true;}
        // A failed mid-cycle upgrade leaves the previously purchased items in
        // place. Keep only that already-paid allowance until its paid-through date.
        if (($subscription['status'] ?? '')!=='active' || (empty($subscription['pending_update']) && ($subscription['latest_invoice']['billing_reason'] ?? '')!=='subscription_update')) {return false;}
        $until=(int)$this->config->getUserValue($uid,'stratus2_ai','stripe_paid_until','0');
        $bytes=(int)$this->config->getUserValue($uid,'stratus2_ai','stripe_paid_bytes','0');
        return $until>time() && $bytes===BillingPolicy::capacity($subscription,$base,$this->addonPrice());
    }
    public function sync(array $subscription): void {
        $base=$this->matchingBase($subscription);
        if ($base==='') {return;}
        $uid=$subscription['metadata']['nextcloud_uid'] ?? '';$user=$this->users->get($uid);
        if (!$user) {throw new \DomainException('Unknown subscriber');}
        $customer=$subscription['customer'] ?? '';$known=$this->customer($uid);
        if (!is_string($customer) || $customer==='' || ($known!=='' && $known!==$customer)) {throw new \DomainException('Customer mismatch');}
        $existing=$this->config->getUserValue($uid,'stratus2_ai','stripe_subscription','');
        // A different subscription must never revoke or overwrite the account's active entitlement.
        if ($existing!=='' && $existing!==$subscription['id']) {
            $prior=$this->subscription($uid);
            if ($prior && !in_array($prior['status'],['canceled','incomplete_expired'],true)) {throw new \DomainException('Another storage subscription already exists');}
        }
        $bytes=BillingPolicy::capacity($subscription,$base,$this->addonPrice());
        $paid=$this->entitled($uid,$subscription,$base);
        $quota=$base===$this->basePrice() ? (string)$bytes : (getenv('PAID_QUOTA') ?: '100 GB');
        $user->setQuota($paid ? $quota : (getenv('FREE_QUOTA') ?: '1 GB'));
        if (BillingPolicy::entitled($subscription,$base)) {
            $until=(int)($subscription['current_period_end'] ?? 0);
            foreach ($subscription['items']['data'] as $item) {$until=max($until,(int)($item['current_period_end'] ?? 0));}
            $this->config->setUserValue($uid,'stratus2_ai','stripe_paid_until',(string)$until);
            $this->config->setUserValue($uid,'stratus2_ai','stripe_paid_bytes',(string)$bytes);
        }
        $this->config->setUserValue($uid,'stratus2_ai','stripe_customer',$customer);
        $this->config->setUserValue($uid,'stratus2_ai','stripe_subscription',$subscription['id']);
    }
    public function status(string $uid): array {
        if ($this->stagingPremium($uid)) {
            return ['paid'=>true,'extra_blocks'=>0,'capacity_gb'=>100,'monthly_usd'=>10,
                'enhancement_enabled'=>getenv('PHOTO_ENHANCEMENT_ENABLED')==='1',
                'payments_enabled'=>StripeGateway::paymentsEnabled(),'portal_enabled'=>$this->customer($uid)!=='','legacy'=>false];
        }
        $subscription=$this->subscription($uid);$paid=false;$blocks=0;
        if ($subscription && ($base=$this->matchingBase($subscription))!=='') {
            $paid=$this->entitled($uid,$subscription,$base);
            $blocks=intdiv(BillingPolicy::capacity($subscription,$base,$this->addonPrice()),BillingPolicy::BLOCK_BYTES)-1;
            $this->sync($subscription);
        }
        return ['paid'=>$paid,'extra_blocks'=>$blocks,'capacity_gb'=>$paid ? 100*(1+$blocks):1,
            'monthly_usd'=>BillingPolicy::monthlyCents($blocks)/100,'enhancement_enabled'=>getenv('PHOTO_ENHANCEMENT_ENABLED')==='1',
            'payments_enabled'=>StripeGateway::paymentsEnabled(),'portal_enabled'=>$this->customer($uid)!=='',
            'legacy'=>$subscription && $this->matchingBase($subscription)!==$this->basePrice()];
    }
    public function validatePrices(): void {
        foreach ([[$this->basePrice(),1000],[$this->addonPrice(),200]] as [$id,$amount]) {
            if ($id==='') {throw new \RuntimeException('Storage prices are not configured');}
            $price=$this->stripe->request('GET','prices/'.rawurlencode($id));
            if (!($price['active'] ?? false) || ($price['currency'] ?? '')!=='usd' || ($price['unit_amount'] ?? 0)!==$amount
                || ($price['recurring']['interval'] ?? '')!=='month' || ($price['recurring']['interval_count'] ?? 0)!==1
                || ($price['recurring']['usage_type'] ?? '')!=='licensed') {throw new \RuntimeException('Storage price configuration does not match the published monthly plan');}
            if (getenv('STRATUS2_ENVIRONMENT')==='staging' && ($price['livemode'] ?? true)) {throw new \RuntimeException('Staging must use sandbox prices');}
        }
    }
}
