<?php
declare(strict_types=1);
namespace OCA\StratusTransfer\Controller;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCA\StratusTransfer\Service\ReadOnlyStorage;
final class PageController extends Controller {
    public function __construct(IRequest $request,private ReadOnlyStorage $storage) {parent::__construct('stratus_transfer',$request);}
    #[NoAdminRequired] #[NoCSRFRequired] public function index(): TemplateResponse {
        $response=new TemplateResponse('stratus_transfer','index');$policy=new ContentSecurityPolicy();
        try {$policy->addAllowedConnectDomain($this->storage->origin());} catch (\DomainException) {}
        $response->setContentSecurityPolicy($policy);$response->addHeader('Referrer-Policy','no-referrer');$response->addHeader('Cache-Control','private, no-store');return $response;
    }
}
