<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Controller;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCP\IPreview;
use OCA\Stratus2AI\Service\Organizer;
final class WorkerController extends Controller {
    public function __construct(IRequest $request, private Organizer $organizer, private IPreview $previews) {parent::__construct('stratus2_ai',$request);}
    private function run(string $id, int $fileId, callable $fn) {
        try {
            [$job,$file]=$this->organizer->workerJob($id,$fileId,$this->request->getHeader('Authorization'));
            return $fn($job,$file);
        } catch (\DomainException) {return new JSONResponse(['error'=>'Job inactive'],410);}
        catch (\OCP\AppFramework\Db\DoesNotExistException|\OCP\Files\NotFoundException) {return new JSONResponse(['error'=>'Not found'],404);}
    }
    #[PublicPage] #[NoAdminRequired] #[NoCSRFRequired] public function metadata(string $id, int $fileId) {return $this->run($id,$fileId,fn($j,$f)=>new JSONResponse([
        'mime'=>$f['mime'],'size'=>$f['size'],'path'=>$f['path'],'batch'=>($j['data']['kind'] ?? '')==='photo_batch','prompt'=>$j['data']['prompt'],'consent'=>$j['data']['consent'],'complete'=>isset($j['data']['results'][(string)$fileId]),
    ]));}
    #[PublicPage] #[NoAdminRequired] #[NoCSRFRequired] public function content(string $id, int $fileId) {return $this->run($id,$fileId,function($j,$f) use ($fileId){
        $file=$this->organizer->file($j,$fileId);
        if ($file->getEtag()!==$f['etag']) {return new JSONResponse(['error'=>'File changed'],409);}
        return new StreamResponse($file->fopen('rb'),200,['Content-Type'=>'application/octet-stream','Cache-Control'=>'no-store']);
    });}
    #[PublicPage] #[NoAdminRequired] #[NoCSRFRequired] public function preview(string $id, int $fileId) {return $this->run($id,$fileId,function($j) use ($fileId){
        $preview=$this->previews->getPreview($this->organizer->file($j,$fileId),768,768);
        return new DataDisplayResponse($preview->getContent(),200,['Content-Type'=>$preview->getMimeType(),'Cache-Control'=>'no-store']);
    });}
    #[PublicPage] #[NoAdminRequired] #[NoCSRFRequired] public function cache(string $id, int $fileId, string $key) {return $this->run($id,$fileId,fn($j)=>new JSONResponse(['result'=>$this->organizer->cached($j['user_id'],$key)]));}
    #[PublicPage] #[NoAdminRequired] #[NoCSRFRequired] public function result(string $id, int $fileId, array $result) {return $this->run($id,$fileId,function() use ($id,$fileId,$result){
        $this->organizer->accept($id,$fileId,$result);return new JSONResponse(['accepted'=>true]);
    });}
}
