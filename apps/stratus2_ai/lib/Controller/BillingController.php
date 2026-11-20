<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Controller;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCA\Stratus2AI\Service\StripeGateway;
use OCA\Stratus2AI\Service\BillingPolicy;
use OCA\Stratus2AI\Service\BillingAccount;
final class BillingController extends Controller {
    public function __construct(IRequest $request, private IUserSession $session, private StripeGateway $stripe,
        private BillingAccount $account, private ILockingProvider $locks) {parent::__construct('stratus2_ai',$request);}
    private function uid(): string {return $this->session->getUser()->getUID();}
    private function run(callable $fn, bool $requiresPayments=false): JSONResponse {
        if ($requiresPayments && !StripeGateway::paymentsEnabled()) {
            return new JSONResponse(['error'=>'Live billing changes are disabled in staging'],403,['Cache-Control'=>'no-store']);
        }
        try {$response=new JSONResponse($fn());}
        catch (\InvalidArgumentException|\DomainException $e) {$response=new JSONResponse(['error'=>$e->getMessage()],400);}
        catch (\Throwable) {$response=new JSONResponse(['error'=>'Billing is temporarily unavailable. Retry or use Manage subscription.'],503);}
        $response->addHeader('Cache-Control','no-store');return $response;
    }
    private function returnUrl(): string {return $this->account->origin().'/index.php/apps/stratus2_ai/';}
    #[NoAdminRequired] public function status(): JSONResponse {return $this->run(fn()=> $this->account->status($this->uid()));}
    #[NoAdminRequired] public function checkout(int $extraBlocks=0): JSONResponse {return $this->run(function() use ($extraBlocks){
        $lock='stratus2_billing_'.$this->uid();$this->locks->acquireLock($lock,ILockingProvider::LOCK_EXCLUSIVE);
        try {
        BillingPolicy::validateBlocks($extraBlocks);$this->account->validatePrices();$uid=$this->uid();
        $prior=$this->account->subscription($uid);
        if ($prior && !in_array($prior['status'],['canceled','incomplete_expired'],true)) {throw new \DomainException('Use Change storage for your existing subscription.');}
        $customer=$this->account->ensureCustomer($uid,$this->session->getUser()->getEMailAddress() ?: '');
        if ($customer!=='') {
            $subscriptions=$this->stripe->request('GET','subscriptions',['customer'=>$customer,'status'=>'all','limit'=>100]);
            foreach ($subscriptions['data'] as $sub) {
                if ($this->account->matchingBase($sub)!=='' && !in_array($sub['status'],['canceled','incomplete_expired'],true)) {throw new \DomainException('An existing storage subscription must be managed first.');}
            }
        }
        $state=$this->account->checkoutState($uid);
        if ($prior && in_array($prior['status'],['canceled','incomplete_expired'],true)) {$state=[];$this->account->rememberCheckout($uid,[]);}
        if (!empty($state['session'])) {
            $session=$this->stripe->request('GET','checkout/sessions/'.$state['session']);
            if ($session['status']==='open' && ($state['blocks'] ?? -1)===$extraBlocks && ($session['automatic_tax']['enabled'] ?? false)) {return ['url'=>$session['url']];}
            if ($session['status']==='complete') {throw new \DomainException('Your payment is being reconciled. Refresh your subscription before starting another checkout.');}
            if ($session['status']==='open') {$this->stripe->request('POST','checkout/sessions/'.$session['id'].'/expire');}
            $state=[];
        }
        if (($state['blocks'] ?? -1)!==$extraBlocks || empty($state['nonce'])) {
            $state=['blocks'=>$extraBlocks,'nonce'=>bin2hex(random_bytes(16))];$this->account->rememberCheckout($uid,$state);
        }
        $items=[['price'=>$this->account->basePrice(),'quantity'=>1]];
        if ($extraBlocks>0) {$items[]=['price'=>$this->account->addonPrice(),'quantity'=>$extraBlocks];}
        $hash=hash('sha256',$uid.':'.$extraBlocks.':'.$state['nonce']);
        $data=['mode'=>'subscription','line_items'=>$items,'client_reference_id'=>$uid,
            'automatic_tax'=>['enabled'=>true],'billing_address_collection'=>'required',
            'integration_identifier'=>'stratus2_storage_'.strtr(substr($hash,0,8),'0123456789','abcdefghij'),
            'subscription_data'=>['metadata'=>['nextcloud_uid'=>$uid]],'success_url'=>$this->returnUrl().'?checkout=success','cancel_url'=>$this->returnUrl()];
        if ($customer!=='') {$data['customer']=$customer;$data['customer_update']=['address'=>'auto'];} else {$data['customer_email']=$this->session->getUser()->getEMailAddress();}
        $session=$this->stripe->request('POST','checkout/sessions',$data,'storage-checkout-'.$hash);
        $this->account->rememberCheckout($uid,[...$state,'session'=>$session['id']]);return ['url'=>$session['url']];
        } finally {$this->locks->releaseLock($lock,ILockingProvider::LOCK_EXCLUSIVE);}
    },true);}
    #[NoAdminRequired] public function portal(): JSONResponse {return $this->run(function(){
        $this->account->subscription($this->uid());
        $customer=$this->account->customer($this->uid());if ($customer==='') {throw new \DomainException('No subscription found');}
        $fields=['customer'=>$customer,'return_url'=>$this->returnUrl()];
        if ($configuration=getenv('STRIPE_PORTAL_CONFIGURATION_ID')) {$fields['configuration']=$configuration;}
        return ['url'=>$this->stripe->request('POST','billing_portal/sessions',$fields)['url']];
    });}
    private function changeItems(array $subscription, int $blocks): array {
        BillingPolicy::validateBlocks($blocks);
        $base=$this->account->matchingBase($subscription);
        if ($base==='') {throw new \DomainException('No matching storage subscription');}
        $current=BillingPolicy::capacity($subscription,$base,$this->account->addonPrice());
        if ($current===(1+$blocks)*BillingPolicy::BLOCK_BYTES && $base===$this->account->basePrice()) {throw new \DomainException('This is already your storage allowance.');}
        $items=[];$found=false;
        foreach ($subscription['items']['data'] as $item) {
            if ($item['price']['id']===$base && $base!==$this->account->basePrice()) {
                $items[]=['id'=>$item['id'],'price'=>$this->account->basePrice(),'quantity'=>1];
            }
            if ($item['price']['id']===$this->account->addonPrice()) {
                $found=true;$items[]=$blocks===0 ? ['id'=>$item['id'],'deleted'=>true]:['id'=>$item['id'],'quantity'=>$blocks];
            }
        }
        if (!$found && $blocks>0) {$items[]=['price'=>$this->account->addonPrice(),'quantity'=>$blocks];}
        if (!$items) {throw new \DomainException('This is already your storage allowance.');}
        return $items;
    }
    private function fingerprint(array $subscription, int $blocks, int $date): string {
        $key=getenv('WORKER_HMAC_SECRET');if (!$key) {throw new \RuntimeException('Billing confirmation is not configured');}
        return hash_hmac('sha256',json_encode([$this->uid(),$subscription['id'],$subscription['items']['data'],$subscription['latest_invoice']['id'] ?? '',$blocks,$date],JSON_THROW_ON_ERROR),$key);
    }
    #[NoAdminRequired] public function preview(int $extraBlocks): JSONResponse {return $this->run(function() use ($extraBlocks){
        $this->account->requirePaid($this->uid());$this->account->validatePrices();$sub=$this->account->subscription($this->uid());
        if (!empty($sub['pending_update'])) {throw new \DomainException('Complete your pending payment before changing storage again.');}
        $date=time();$items=$this->changeItems($sub,$extraBlocks);
        $invoice=$this->stripe->request('POST','invoices/create_preview',['customer'=>$sub['customer'],'subscription'=>$sub['id'],
            'subscription_details'=>['items'=>$items,'proration_behavior'=>'always_invoice','proration_date'=>$date]]);
        return ['due_usd'=>($invoice['amount_due'] ?? 0)/100,'monthly_usd'=>BillingPolicy::monthlyCents($extraBlocks)/100,
            'extraBlocks'=>$extraBlocks,'prorationDate'=>$date,'confirmation'=>$this->fingerprint($sub,$extraBlocks,$date)];
    },true);}
    #[NoAdminRequired] public function storage(int $extraBlocks, int $prorationDate, string $confirmation): JSONResponse {return $this->run(function() use ($extraBlocks,$prorationDate,$confirmation){
        $key='stratus2_billing_'.$this->uid();$this->locks->acquireLock($key,ILockingProvider::LOCK_EXCLUSIVE);
        try {
            $this->account->requirePaid($this->uid());$sub=$this->account->subscription($this->uid());
            if (abs(time()-$prorationDate)>300 || !hash_equals($this->fingerprint($sub,$extraBlocks,$prorationDate),$confirmation)) {throw new \DomainException('Your quote expired or the subscription changed. Preview it again.');}
            $items=$this->changeItems($sub,$extraBlocks);
            $updated=$this->stripe->request('POST','subscriptions/'.$sub['id'],['items'=>$items,'proration_date'=>$prorationDate,
                'proration_behavior'=>'always_invoice','payment_behavior'=>'pending_if_incomplete','expand'=>['latest_invoice']],
                'storage-change-'.$confirmation);
            $this->account->sync($updated);
            return ['pending'=>!empty($updated['pending_update']),'payment_url'=>$updated['latest_invoice']['hosted_invoice_url'] ?? null];
        } finally {$this->locks->releaseLock($key,ILockingProvider::LOCK_EXCLUSIVE);}
    },true);}
    #[PublicPage] #[NoAdminRequired] #[NoCSRFRequired] public function webhook(): JSONResponse {
        if (!StripeGateway::paymentsEnabled()) {
            return new JSONResponse(['error'=>'Live webhooks are disabled in staging'],403);
        }
        $body=file_get_contents('php://input');if (strlen($body)>1048576) {return new JSONResponse(['error'=>'Payload too large'],413);}
        try {$event=StripeGateway::verify($body,$this->request->getHeader('Stripe-Signature'),getenv('STRIPE_WEBHOOK_SECRET') ?: '');}
        catch (\Throwable) {return new JSONResponse(['error'=>'Invalid signature'],400);}
        try {
            if (getenv('STRATUS2_ENVIRONMENT')==='staging' && ($event['livemode'] ?? true)) {return new JSONResponse(['error'=>'Live events are not accepted in staging'],400);}
            $object=$event['data']['object'];$subscription=null;
            if (str_starts_with($event['type'],'customer.subscription.')) {$subscription=$object['id'];}
            elseif (in_array($event['type'],['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.async_payment_failed'],true)) {$subscription=$object['subscription'] ?? null;}
            elseif (in_array($event['type'],['invoice.payment_failed','invoice.paid'],true)) {$subscription=$object['subscription'] ?? $object['parent']['subscription_details']['subscription'] ?? null;}
            if (!$subscription || !preg_match('/^sub_[A-Za-z0-9]+$/D',$subscription)) {return new JSONResponse(['received'=>true]);}
            $current=$this->stripe->request('GET','subscriptions/'.rawurlencode($subscription),['expand'=>['latest_invoice']]);
            $this->account->sync($current);
            return new JSONResponse(['received'=>true]);
        } catch (\Throwable) {return new JSONResponse(['error'=>'Retry webhook later'],503);}
    }
}
