<?php
declare(strict_types=1);
$test=getenv('STRATUS2_TEST_MODE')==='1';
$origin=getenv('STRATUS2_ORIGIN') ?: 'https://stratus.example.com';
if (!preg_match('~^https://[a-z0-9.-]+$~D',$origin)) {throw new \RuntimeException('Invalid application origin');}
$domain=$test ? 'localhost' : parse_url($origin,PHP_URL_HOST);
$CONFIG=[
    'trusted_domains'=>$test ? ['localhost','127.0.0.1','nextcloud']:[$domain,'localhost'],
    'overwrite.cli.url'=>$test ? 'http://localhost:8080':$origin,
    'overwriteprotocol'=>$test ? 'http':'https',
    'trusted_proxies'=>['10.42.0.0/24','10.42.1.0/24'],
    'forwarded_for_headers'=>['HTTP_X_FORWARDED_FOR'],
    // Set additional production admin ranges in the persistent private config directory.
    'allowed_admin_ranges'=>($test || getenv('STRATUS2_ENVIRONMENT')==='staging') ? [] : ['127.0.0.1/32'],
    'memcache.local'=>'\\OC\\Memcache\\APCu','memcache.locking'=>'\\OC\\Memcache\\Redis',
    'redis'=>['host'=>'redis','port'=>6379,'password'=>getenv('REDIS_PASSWORD') ?: ''],
    'default_quota'=>getenv('FREE_QUOTA') ?: '1 GB',
    'maintenance_window_start'=>9,'default_phone_region'=>'US',
    'upgrade.disable-web'=>true,'loglevel'=>2,'log_rotate_size'=>10485760,
    'auth.bruteforce.protection.enabled'=>true,
    'user_oidc'=>['auto_provision'=>true,'soft_auto_provision'=>false,'enable_default_claims'=>false,'use_pkce'=>true,
        'httpclient.allowselfsigned'=>false,'validate_jwk_strength'=>true,'send_userinfo_claims'=>false],
];
if (getenv('STORJ_ENDPOINT')) {
    require_once __DIR__.'/primary-storage-guard.php';
    $prior=[];
    if (is_file(__DIR__.'/config.php')) {
        $prior=(static function(string $path): array {$CONFIG=[];include $path;return $CONFIG;})(__DIR__.'/config.php');
    }
    $objectPrefix=getenv('STORJ_OBJECT_PREFIX') ?: '';
    if ($objectPrefix!=='' && (getenv('STRATUS2_ENVIRONMENT')!=='staging' || $objectPrefix!=='stratus2-staging/urn:oid:')) {
        throw new \RuntimeException('Invalid staging object prefix');
    }
    \Stratus2\Bootstrap\PrimaryStorageGuard::check(__DIR__.'/stratus2-primary.sha256',getenv('STORJ_ENDPOINT'),getenv('STORJ_BUCKET') ?: '',!empty($prior['installed']),$objectPrefix);
    $endpoint=parse_url(getenv('STORJ_ENDPOINT'));
    if (!$test && ($endpoint['scheme'] ?? '')!=='https') {throw new \RuntimeException('Storj requires HTTPS');}
    $CONFIG['objectstore']=['class'=>'\\OC\\Files\\ObjectStore\\S3','arguments'=>[
        'bucket'=>getenv('STORJ_BUCKET'),'key'=>getenv('STORJ_ACCESS_KEY'),'secret'=>getenv('STORJ_SECRET_KEY'),
        'hostname'=>$endpoint['host'],'port'=>$endpoint['port'] ?? 443,'use_ssl'=>($endpoint['scheme']==='https'),
        'region'=>getenv('STORJ_REGION') ?: 'us-east-1','use_path_style'=>true,'autocreate'=>false,
    ]];
    if ($objectPrefix!=='') {$CONFIG['objectstore']['arguments']['objectPrefix']=$objectPrefix;}
} elseif (!$test) {throw new \RuntimeException('Configure primary object storage before first installation');}
