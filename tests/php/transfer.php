<?php
declare(strict_types=1);
require __DIR__.'/../../apps/stratus_transfer/lib/Service/Policy.php';
require __DIR__.'/../../apps/stratus2_ai/vendor/autoload.php';
require __DIR__.'/../../apps/stratus_transfer/lib/Service/ObjectReadSignature.php';
use OCA\StratusTransfer\Service\Policy;
function transferCheck(bool $value): void {if (!$value) {throw new RuntimeException('Transfer policy failed');}}
transferCheck(Policy::origin('https://gateway.storjshare.io:443')==='https://gateway.storjshare.io:443');
foreach (['http://gateway.storjshare.io','https://gateway.storjshare.io.attacker.test','https://user:pass@gateway.storjshare.io',
    'https://gateway.storjshare.io/a','https://gateway.storjshare.io?redirect=bad','https://127.0.0.1'] as $url) {
    try {Policy::origin($url);throw new RuntimeException('Untrusted endpoint accepted');} catch (DomainException) {}
}
transferCheck(Policy::transparentWrapper('OC\Files\Storage\Wrapper\Quota'));
transferCheck(!Policy::transparentWrapper('OCA\FilesLock\Storage\LockWrapper','changed-source'));
transferCheck(Policy::transparentWrapper('OCA\FilesLock\Storage\LockWrapper','185cc860224f1724691adf56316d4c679060b20472bd0f8f7ae902940487a95d'));
foreach (['OC\Files\Storage\Wrapper\Encryption','OCA\Files_Sharing\SharedStorage','OCA\FilesAccessControl\StorageWrapper','OC\Files\Storage\Wrapper\QuotaSubclass'] as $class) {
    transferCheck(!Policy::transparentWrapper($class));
}
transferCheck(Policy::progress(100,50,1000)===100 && Policy::progress(100,150,1000)===150);
foreach ([-1,1001] as $position) {try {Policy::progress(100,$position,1000);throw new RuntimeException('Invalid accounting position accepted');} catch (InvalidArgumentException) {}}
$metadata=new DOMDocument();transferCheck($metadata->load(__DIR__.'/../../apps/stratus_transfer/appinfo/info.xml') && $metadata->schemaValidate(__DIR__.'/../fixtures/nextcloud-info.xsd'));
$signer=new \OCA\StratusTransfer\Service\ObjectReadSignature('s3','us-east-1');
$signed=$signer->presign(new \GuzzleHttp\Psr7\Request('GET','https://gateway.storjshare.io/fixture',['If-Match'=>'"fixture-revision"']),
    new \Aws\Credentials\Credentials('isolated-test','isolated-test'),new DateTimeImmutable('+120 seconds'));
parse_str($signed->getUri()->getQuery(),$query);
transferCheck(in_array('if-match',explode(';',$query['X-Amz-SignedHeaders']),true));
transferCheck((int)$query['X-Amz-Expires']<=120);
echo "PASS: transfer endpoint allowlist, policy-wrapper rejection, bounded idempotent accounting and app schema\n";
