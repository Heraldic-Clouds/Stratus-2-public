<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Controller;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IPreview;
use OCA\Stratus2AI\Service\PhotoEnhancement;
use OCA\Stratus2AI\Service\JobStore;
final class PhotoController extends Controller {
    public function __construct(IRequest $request, private IUserSession $session, private PhotoEnhancement $photos,
        private JobStore $store, private IPreview $previews) {parent::__construct('stratus2_ai',$request);}
    private function uid(): string {return $this->session->getUser()->getUID();}
    private function run(callable $fn) {
        try {$result=$fn();$response=is_array($result) ? new JSONResponse($result):$result;}
        catch (\OCP\AppFramework\Db\DoesNotExistException|\OCP\Files\NotFoundException) {$response=new JSONResponse(['error'=>'Not found'],404);}
        catch (\DomainException $e) {$response=new JSONResponse(['error'=>$e->getMessage()],403);}
        catch (\InvalidArgumentException $e) {$response=new JSONResponse(['error'=>$e->getMessage()],400);}
        catch (\Throwable) {$response=new JSONResponse(['error'=>'The operation was interrupted. Refresh the job before trying again.'],503);}
        $response->addHeader('Cache-Control','no-store');$response->addHeader('X-Content-Type-Options','nosniff');return $response;
    }
    private function worker(string $id): array {return $this->photos->workerJob($id,$this->request->getHeader('Authorization'));}
    #[NoAdminRequired] public function list(): JSONResponse {return $this->run(fn()=> $this->store->list($this->uid(),true));}
    #[NoAdminRequired] public function create(string $path, string $prompt, bool $consent=false): JSONResponse {return $this->run(fn()=> $this->photos->create($this->uid(),$path,$prompt,$consent));}
    #[NoAdminRequired] public function show(string $id): JSONResponse {return $this->run(fn()=> $this->photos->publicJob($this->photos->get($id,$this->uid())));}
    #[NoAdminRequired] public function save(string $id): JSONResponse {return $this->run(fn()=> $this->photos->save($id,$this->uid()));}
    #[NoAdminRequired] public function cancel(string $id): JSONResponse {return $this->run(fn()=> $this->photos->cancel($id,$this->uid()));}
    #[NoAdminRequired] #[NoCSRFRequired] public function result(string $id) {return $this->run(fn()=>new DataDisplayResponse($this->photos->result($id,$this->uid()),200,['Content-Type'=>'image/png']));}
    #[NoAdminRequired] #[NoCSRFRequired] public function original(string $id) {return $this->run(function() use ($id){
        $file=$this->photos->source($this->photos->get($id,$this->uid()));$preview=$this->previews->getPreview($file,1536,1536);
        return new DataDisplayResponse($preview->getContent(),200,['Content-Type'=>$preview->getMimeType()]);
    });}
    #[PublicPage] #[NoAdminRequired] #[NoCSRFRequired] public function claim(string $id): JSONResponse {return $this->run(function() use ($id){$this->worker($id);return $this->photos->claim($id);});}
    #[PublicPage] #[NoAdminRequired] #[NoCSRFRequired] public function content(string $id) {return $this->run(function() use ($id){
        $job=$this->worker($id);$file=$this->photos->source($job);
        if ($job['state']!=='photo_processing') {throw new \DomainException('Job inactive');}
        return new StreamResponse($file->fopen('rb'),200,['Content-Type'=>'application/octet-stream']);
    });}
    #[PublicPage] #[NoAdminRequired] #[NoCSRFRequired] public function complete(string $id, string $image, string $model): JSONResponse {return $this->run(function() use ($id,$image,$model){
        $this->worker($id);
        if (strlen($image)>PhotoEnhancement::MAX_BYTES*4/3+4) {throw new \InvalidArgumentException('Result exceeds the image size limit');}
        $bytes=base64_decode($image,true);if ($bytes===false) {throw new \InvalidArgumentException('Invalid image encoding');}
        $this->photos->accept($id,$bytes,$model);return ['accepted'=>true];
    });}
    #[PublicPage] #[NoAdminRequired] #[NoCSRFRequired] public function failure(string $id, string $reason='failed'): JSONResponse {return $this->run(function() use ($id,$reason){
        $this->worker($id);$this->photos->fail($id,$reason==='ambiguous' ? 'ambiguous':'failed');return ['accepted'=>true];
    });}
}
