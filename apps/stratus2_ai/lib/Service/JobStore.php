<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Service;
use OCP\IDBConnection;
final class JobStore {
    public function __construct(private IDBConnection $db) {}
    public function create(string $userId, array $payload, string $state='queued', ?string $id=null): string {
        $id ??= bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) {throw new \InvalidArgumentException('Invalid job ID');}
        $q = $this->db->getQueryBuilder();
        $q->insert('stratus2_jobs')->values([
            'id'=>$q->createNamedParameter($id), 'user_id'=>$q->createNamedParameter($userId),
            'state'=>$q->createNamedParameter($state), 'payload'=>$q->createNamedParameter(json_encode($payload, JSON_THROW_ON_ERROR)),
            'revision'=>$q->createNamedParameter(0), 'created_at'=>$q->createNamedParameter(time()), 'updated_at'=>$q->createNamedParameter(time()),
        ])->executeStatement();
        return $id;
    }
    public function get(string $id, ?string $userId = null): array {
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('stratus2_jobs')->where($q->expr()->eq('id', $q->createNamedParameter($id)));
        if ($userId !== null) { $q->andWhere($q->expr()->eq('user_id', $q->createNamedParameter($userId))); }
        $result = $q->executeQuery(); $row = $result->fetchAssociative(); $result->closeCursor();
        if (!$row) { throw new \OCP\AppFramework\Db\DoesNotExistException('Job not found'); }
        $row['data'] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['payload']);
        return $row;
    }
    public function update(array $job): bool {
        $q = $this->db->getQueryBuilder();
        $changed = $q->update('stratus2_jobs')->set('state', $q->createNamedParameter($job['state']))
            ->set('payload', $q->createNamedParameter(json_encode($job['data'], JSON_THROW_ON_ERROR)))
            ->set('revision', $q->createNamedParameter((int)$job['revision'] + 1))->set('updated_at', $q->createNamedParameter(time()))
            ->where($q->expr()->eq('id', $q->createNamedParameter($job['id'])))
            ->andWhere($q->expr()->eq('revision', $q->createNamedParameter((int)$job['revision'])))->executeStatement();
        return $changed === 1;
    }
    public function mutate(string $id, callable $callback): array {
        for ($i=0; $i<12; $i++) {
            $job = $this->get($id); $callback($job);
            if ($this->update($job)) { return $this->get($id); }
            usleep(random_int(1000, 20000));
        }
        throw new \RuntimeException('Job busy; retry');
    }
    public function list(string $userId, ?bool $photos=null): array {
        $q = $this->db->getQueryBuilder();
        $q->select('id','state','created_at','updated_at')->from('stratus2_jobs')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($userId)));
        if ($photos!==null) {
            $pattern=$q->createNamedParameter('photo%');
            $q->andWhere($photos ? $q->expr()->like('state',$pattern):$q->expr()->notLike('state',$pattern));
        }
        $r=$q->orderBy('created_at','DESC')->setMaxResults(50)->executeQuery();
        $rows = $r->fetchAllAssociative(); $r->closeCursor(); return $rows;
    }
    public function pending(): array {
        $q = $this->db->getQueryBuilder();
        $r = $q->select('id')->from('stratus2_jobs')->where($q->expr()->in('state', $q->createNamedParameter(
            ['queued','analyzing','applying','undoing','batch_running','batch_undoing','photo_queued','photo_processing','photo_saving'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
        )))->setMaxResults(100)->executeQuery();
        $rows = $r->fetchAllAssociative(); $r->closeCursor(); return array_column($rows,'id');
    }
    public function expiredPhotos(): array {
        $q=$this->db->getQueryBuilder();
        $r=$q->select('id')->from('stratus2_jobs')->where($q->expr()->in('state',$q->createNamedParameter(
            ['photo_queued','photo_processing','photo_ready','photo_saving','photo_saved','photo_failed','photo_ambiguous','photo_cancelled'],\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($q->expr()->lt('created_at',$q->createNamedParameter(time()-86400)))->setMaxResults(100)->executeQuery();
        $rows=$r->fetchAllAssociative();$r->closeCursor();return array_column($rows,'id');
    }
}
