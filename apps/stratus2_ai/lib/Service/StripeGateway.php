<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Service;
final class StripeGateway {
    public static function paymentsEnabled(): bool {
        return getenv('STRATUS2_ENVIRONMENT')!=='staging' || getenv('STRATUS2_STRIPE_LIVE_READ_ONLY')!=='1';
    }
    public function request(string $method, string $path, array $fields=[], ?string $idempotencyKey=null): array {
        $secret=getenv('STRIPE_SECRET_KEY');
        if (!$secret) {throw new \RuntimeException('Billing is not configured');}
        if (getenv('STRATUS2_ENVIRONMENT')==='staging') {
            if (!self::paymentsEnabled()) {
                $read=strtoupper($method)==='GET' && preg_match('~^(?:prices/price_[A-Za-z0-9]+|subscriptions(?:/sub_[A-Za-z0-9]+|/search)?)$~D',$path);
                $portal=strtoupper($method)==='POST' && $path==='billing_portal/sessions';
                if (!preg_match('/^[sr]k_live_/',$secret) || (!$read && !$portal)) {
                    throw new \DomainException('Live billing changes are disabled in staging');
                }
            } elseif (!preg_match('/^[sr]k_test_/',$secret)) {
                throw new \RuntimeException('Staging requires a sandbox key or explicit live read-only mode');
            }
        }
        $client=new \Stripe\StripeClient(['api_key'=>$secret,'stripe_version'=>'2026-09-30.endive','max_network_retries'=>2]);
        try {return $client->request(strtolower($method),'/v1/'.$path,$fields,$idempotencyKey ? ['idempotency_key'=>$idempotencyKey]:[])->toArray();}
        catch (\Throwable) {throw new \RuntimeException('Billing provider request failed');}
    }
    public static function verify(string $body, string $signature, string $secret, ?int $now=null): array {
        if ($secret==='') {throw new \RuntimeException('Webhook is not configured');}
        $timestamp=null;$signatures=[];
        foreach (explode(',',$signature) as $part) {
            $pair=explode('=',$part,2);
            if (count($pair)!==2) {continue;}
            if ($pair[0]==='t' && ctype_digit($pair[1])) {$timestamp=(int)$pair[1];}
            if ($pair[0]==='v1') {$signatures[]=$pair[1];}
        }
        if ($timestamp===null || abs(($now ?? time())-$timestamp)>300) {throw new \InvalidArgumentException('Webhook expired');}
        $expected=hash_hmac('sha256',$timestamp.'.'.$body,$secret);
        if (!array_filter($signatures,fn($s)=>hash_equals($expected,$s))) {throw new \InvalidArgumentException('Invalid webhook signature');}
        return json_decode($body,true,512,JSON_THROW_ON_ERROR);
    }
}
