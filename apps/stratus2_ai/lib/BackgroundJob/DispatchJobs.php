<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\BackgroundJob;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Stratus2AI\Service\JobStore;
use OCA\Stratus2AI\Service\Organizer;
use OCA\Stratus2AI\Service\PhotoEnhancement;
final class DispatchJobs extends TimedJob {
    public function __construct(ITimeFactory $time, private JobStore $store, private Organizer $organizer, private PhotoEnhancement $photos) {parent::__construct($time);$this->setInterval(300);}
    protected function run($argument): void {
        foreach ($this->store->pending() as $id) {
            try {
                $job=$this->store->get($id);
                if (($job['data']['kind'] ?? '')==='photo') {$this->photos->dispatch($id);}
                elseif (in_array($job['state'],['applying','undoing','batch_running','batch_undoing'],true)) {$this->organizer->execute($id,$job['user_id'],in_array($job['state'],['undoing','batch_undoing'],true));}
                else {$this->organizer->dispatch($id);}
            } catch (\Throwable) { /* Keep durable progress; the next cron run retries. */ }
        }
        foreach ($this->store->expiredPhotos() as $id) {try {$this->photos->expire($id);} catch (\Throwable) {}}
    }
}
