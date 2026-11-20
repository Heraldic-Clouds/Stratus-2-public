<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Service;
final class BillingPolicy {
    public const BLOCK_BYTES = 100000000000;
    public const MAX_EXTRA_BLOCKS = 99;
    public static function validateBlocks(int $blocks): int {
        if ($blocks<0 || $blocks>self::MAX_EXTRA_BLOCKS) {throw new \InvalidArgumentException('Choose 0 to 99 additional 100 GB blocks (10 TB maximum).');}
        return $blocks;
    }
    public static function monthlyCents(int $blocks): int {return 1000+200*self::validateBlocks($blocks);}
    public static function capacity(array $subscription, string $base, string $addon): int {
        $baseCount=0;$extra=0;$addonCount=0;
        foreach ($subscription['items']['data'] ?? [] as $item) {
            $id=$item['price']['id'] ?? '';$quantity=$item['quantity'] ?? 1;
            if (!is_int($quantity) || $quantity<0) {throw new \DomainException('Invalid storage quantity');}
            if ($id===$base) {$baseCount++;if ($quantity!==1) {throw new \DomainException('One base subscription is required');}}
            elseif ($addon!=='' && $id===$addon) {$addonCount++;$extra+=$quantity;}
            else {throw new \DomainException('Unexpected subscription item');}
        }
        if ($baseCount!==1 || $addonCount>1 || $extra>self::MAX_EXTRA_BLOCKS) {throw new \DomainException('Invalid storage subscription');}
        return (1+$extra)*self::BLOCK_BYTES;
    }
    public static function usesPrice(array $subscription, string $price): bool {
        if ($price==='') {return false;}
        foreach ($subscription['items']['data'] ?? [] as $item) {
            if (($item['price']['id'] ?? null)===$price) {return true;}
        }
        return false;
    }
    public static function entitled(array $subscription, string $price): bool {
        if (!self::usesPrice($subscription,$price)) {return false;}
        // An active subscription can still have a pending asynchronous payment.
        return ($subscription['status'] ?? '')==='active'
            && is_array($subscription['latest_invoice'] ?? null)
            && ($subscription['latest_invoice']['status'] ?? '')==='paid';
    }
}
