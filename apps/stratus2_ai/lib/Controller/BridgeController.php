<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;

final class BridgeController extends Controller {
    public function __construct(IRequest $request) {parent::__construct('stratus2_ai',$request);}

    private function origin(): string {
        $origin=getenv('SUPABASE_URL') ?: '';
        if (getenv('STRATUS2_ENVIRONMENT')!=='staging'
            || getenv('STRATUS2_ORIGIN')!=='https://staging.example.com'
            || !preg_match('~^https://[a-z0-9-]+\.supabase\.co$~D',$origin)) {
            throw new \RuntimeException('Staging sign-in is not configured');
        }
        return $origin;
    }

    private function requestProvider(string $url): array {
        $headers=[];
        $handle=curl_init($url);
        curl_setopt_array($handle,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>15,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION=>static function($handle,string $line) use (&$headers): int {
                if (stripos($line,'location:')===0) {$headers['location']=trim(substr($line,9));}
                return strlen($line);
            },
        ]);
        try {
            $body=curl_exec($handle);
            $status=curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            if (!is_string($body) || strlen($body)>100000) {throw new \RuntimeException('Provider unavailable');}
            return [$status,$headers,$body];
        } finally {curl_close($handle);}
    }

    #[PublicPage] #[NoAdminRequired] #[NoCSRFRequired]
    public function discovery(): JSONResponse {
        try {
            $origin=$this->origin();
            [$status,,$body]=$this->requestProvider($origin.'/auth/v1/.well-known/openid-configuration');
            $metadata=json_decode($body,true,512,JSON_THROW_ON_ERROR);
            if ($status!==200 || ($metadata['issuer'] ?? '')!==$origin.'/auth/v1'
                || ($metadata['authorization_endpoint'] ?? '')!==$origin.'/auth/v1/oauth/authorize'
                || ($metadata['token_endpoint'] ?? '')!==$origin.'/auth/v1/oauth/token'
                || ($metadata['jwks_uri'] ?? '')!==$origin.'/auth/v1/.well-known/jwks.json') {
                throw new \RuntimeException('Unexpected provider metadata');
            }
            $metadata['authorization_endpoint']='https://staging.example.com/index.php/apps/stratus2_ai/auth/oidc/authorize';
            return new JSONResponse($metadata,200,['Cache-Control'=>'no-store']);
        } catch (\Throwable) {return new JSONResponse(['error'=>'Sign-in discovery unavailable'],503);}
    }

    #[PublicPage] #[NoAdminRequired] #[NoCSRFRequired]
    public function authorize(): JSONResponse|RedirectResponse {
        try {
            $origin=$this->origin();
            $params=[];
            foreach (['response_type','client_id','redirect_uri','scope','state','nonce','code_challenge','code_challenge_method'] as $key) {
                $value=$this->request->getParam($key);
                if (!is_string($value) || $value==='' || strlen($value)>2048) {throw new \InvalidArgumentException('Invalid authorization request');}
                $params[$key]=$value;
            }
            foreach (['claims','prompt','login_hint'] as $key) {
                $value=$this->request->getParam($key);
                if ($value===null || $value==='') {continue;}
                if (!is_string($value) || strlen($value)>2048) {throw new \InvalidArgumentException('Invalid authorization request');}
                $params[$key]=$value;
            }
            if ($params['response_type']!=='code' || $params['client_id']!==getenv('SUPABASE_OIDC_CLIENT_ID')
                || !in_array($params['redirect_uri'],['https://staging.example.com/apps/user_oidc/code','https://staging.example.com/index.php/apps/user_oidc/code'],true)
                || $params['code_challenge_method']!=='S256'
                || !preg_match('/^[A-Za-z0-9_-]{43,128}$/D',$params['code_challenge'])) {
                throw new \InvalidArgumentException('Invalid authorization request');
            }
            [$status,$headers]=$this->requestProvider($origin.'/auth/v1/oauth/authorize?'.http_build_query($params,'','&',PHP_QUERY_RFC3986));
            $target=parse_url($headers['location'] ?? '');
            parse_str($target['query'] ?? '',$query);
            if ($status!==302 || ($target['scheme'] ?? '')!=='https' || ($target['host'] ?? '')!=='stratus.example.com'
                || ($target['path'] ?? '')!=='/index.php/apps/stratus2_ai/auth'
                || !is_string($query['authorization_id'] ?? null)
                || !preg_match('/^[A-Za-z0-9_-]{1,256}$/D',$query['authorization_id'])) {
                throw new \RuntimeException('Authorization unavailable');
            }
            $response=new RedirectResponse('https://staging.example.com/index.php/apps/stratus2_ai/auth?authorization_id='.rawurlencode($query['authorization_id']));
            $response->addHeader('Cache-Control','no-store');
            $response->addHeader('Referrer-Policy','no-referrer');
            return $response;
        } catch (\Throwable) {return new JSONResponse(['error'=>'Staging authorization unavailable'],400,['Cache-Control'=>'no-store']);}
    }
}
