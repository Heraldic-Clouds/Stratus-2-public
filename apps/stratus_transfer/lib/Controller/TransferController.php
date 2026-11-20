<?php
declare(strict_types=1);
namespace OCA\StratusTransfer\Controller;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\StratusTransfer\Service\Transfer;
final class TransferController extends Controller {
    public function __construct(IRequest $request,private IUserSession $session,private Transfer $transfer) {parent::__construct('stratus_transfer',$request);}
    private function uid(): string {return $this->session->getUser()->getUID();}
    private function run(callable $fn): JSONResponse {
        try {$response=new JSONResponse($fn());}
        catch (\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException) {$response=new JSONResponse(['error'=>'Not found'],404);}
        catch (\LengthException) {$response=new JSONResponse(['error'=>'Not enough storage quota'],507);}
        catch (\InvalidArgumentException) {$response=new JSONResponse(['error'=>'Invalid transfer request'],400);}
        catch (\Throwable) {$response=new JSONResponse(['error'=>'Transfer unavailable or file changed. Reload the folder, or use Nextcloud.'],409);}
        $response->addHeader('Cache-Control','private, no-store');$response->addHeader('Referrer-Policy','no-referrer');return $response;
    }
    #[NoAdminRequired] public function status(): JSONResponse {return $this->run(fn()=> $this->transfer->status($this->uid()));}
    #[NoAdminRequired] public function browse(string $path=''): JSONResponse {return $this->run(fn()=> $this->transfer->browse($this->uid(),$path));}
    #[NoAdminRequired] #[UserRateLimit(limit:60,period:60)] public function download(int $fileId,string $etag): JSONResponse {return $this->run(fn()=> $this->transfer->download($this->uid(),$fileId,$etag));}
    #[NoAdminRequired] #[UserRateLimit(limit:120,period:60)] public function refresh(string $ticket): JSONResponse {return $this->run(fn()=> $this->transfer->refresh($this->uid(),$ticket));}
    #[NoAdminRequired] #[UserRateLimit(limit:120,period:60)] public function progress(string $ticket,int $position): JSONResponse {return $this->run(fn()=> $this->transfer->progress($this->uid(),$ticket,$position));}
    #[NoAdminRequired] public function upload(string $folder,int $size): JSONResponse {return $this->run(fn()=> $this->transfer->upload($this->uid(),$folder,$size));}
}
