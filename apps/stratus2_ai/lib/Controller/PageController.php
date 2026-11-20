<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Controller;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\AppFramework\Http\ContentSecurityPolicy;
final class PageController extends Controller {
    public function __construct(IRequest $request, private IURLGenerator $urls, private IConfig $config) {parent::__construct('stratus2_ai',$request);}
    #[NoAdminRequired] #[NoCSRFRequired] public function index(): TemplateResponse {return new TemplateResponse('stratus2_ai','index');}
    #[PublicPage] #[NoAdminRequired] #[NoCSRFRequired] public function auth(): TemplateResponse|RedirectResponse {
        if (getenv('STRATUS2_LOCAL_AUTH')==='1' && getenv('STRATUS2_ENVIRONMENT')==='staging') {
            return new RedirectResponse($this->urls->getAbsoluteURL('/login?direct=1'));
        }
        $slogan=trim($this->config->getAppValue('theming','slogan','Your files. A clearer sky.'));
        if ($slogan==='') {$slogan='Your files. A clearer sky.';}
        $response=new TemplateResponse('stratus2_ai','auth',['slogan'=>$slogan],TemplateResponse::RENDER_AS_GUEST);
        $policy=new ContentSecurityPolicy();
        $url=getenv('SUPABASE_URL') ?: '';
        if (preg_match('~^https://[a-z0-9-]+\.supabase\.co$~D',$url)) {$policy->addAllowedConnectDomain($url);}
        $response->setContentSecurityPolicy($policy);return $response;
    }
    #[PublicPage] #[NoAdminRequired] #[NoCSRFRequired] public function logout(): RedirectResponse {
        // user_oidc appends its own query string. Consume it at a query-free
        // endpoint and generate our own destination; never trust a return URL.
        $response=new RedirectResponse($this->urls->linkToRoute('stratus2_ai.page.auth',['mode'=>'logout']));
        $response->addHeader('Cache-Control','no-store');
        $response->addHeader('Referrer-Policy','no-referrer');
        return $response;
    }
    #[PublicPage] #[NoAdminRequired] #[NoCSRFRequired] public function authConfig(): JSONResponse {
        return new JSONResponse(['url'=>getenv('SUPABASE_URL') ?: '', 'publishableKey'=>getenv('SUPABASE_PUBLISHABLE_KEY') ?: '',
            'clientId'=>getenv('SUPABASE_OIDC_CLIENT_ID') ?: '']);
    }
}
