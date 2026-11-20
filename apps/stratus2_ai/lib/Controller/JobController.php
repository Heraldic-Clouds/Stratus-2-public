<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Controller;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\Stratus2AI\Service\JobStore;
use OCA\Stratus2AI\Service\Organizer;
final class JobController extends Controller {
    public function __construct(IRequest $request, private IUserSession $users, private JobStore $store, private Organizer $organizer) {parent::__construct('stratus2_ai',$request);}
    private function uid(): string {return $this->users->getUser()->getUID();}
    private function run(callable $fn): JSONResponse {
        try {return new JSONResponse($fn());}
        catch (\OCP\AppFramework\Db\DoesNotExistException|\OCP\Files\NotFoundException) {return new JSONResponse(['error'=>'Not found'],404);}
        catch (\InvalidArgumentException|\DomainException $e) {return new JSONResponse(['error'=>$e->getMessage()],400);}
        catch (\Throwable) {return new JSONResponse(['error'=>'Operation interrupted. It is safe to retry; existing files were not overwritten.'],409);}
    }
    #[NoAdminRequired] public function list(): JSONResponse {return $this->run(fn()=> $this->store->list($this->uid(),false));}
    #[NoAdminRequired] public function estimate(string $folder=''): JSONResponse {return $this->run(fn()=> $this->organizer->estimate($this->uid(),$folder));}
    #[NoAdminRequired] public function create(string $folder, string $prompt, bool $consent=false, bool $batch=false): JSONResponse {return $this->run(fn()=> $this->organizer->create($this->uid(),$folder,$prompt,$consent,true,$batch));}
    #[NoAdminRequired] public function show(string $id): JSONResponse {return $this->run(function() use ($id){
        $job=$this->store->get($id,$this->uid());
        if ($job['state']==='batch_running') {return $this->organizer->executeBatch($id,$this->uid());}
        return $this->organizer->publicJob($job);
    });}
    #[NoAdminRequired] public function apply(string $id): JSONResponse {return $this->run(fn()=> $this->organizer->execute($id,$this->uid()));}
    #[NoAdminRequired] public function undo(string $id): JSONResponse {return $this->run(fn()=> $this->organizer->execute($id,$this->uid(),true));}
    #[NoAdminRequired] public function cancel(string $id): JSONResponse {return $this->run(function() use ($id){
        $this->store->get($id,$this->uid());
        $job=$this->store->mutate($id,function(&$j){
            if (!in_array($j['state'],['queued','analyzing','ready'],true)) {throw new \DomainException('This job cannot be cancelled');}
            $j['state']='cancelled';
        });return $this->organizer->publicJob($job);
    });}
}
