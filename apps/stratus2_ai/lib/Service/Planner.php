<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Service;
final class Planner {
    public const CATEGORIES = ['Photos','Videos','Documents','Graphics','Memes','Other'];
    public static function safePath(string $path): string {
        $path = trim($path, '/');
        if (str_contains($path, '\\') || str_contains($path, "\0") || preg_match('~(^|/)\.{1,2}(/|$)~', $path)) {
            throw new \InvalidArgumentException('Invalid folder');
        }
        return $path;
    }
    public static function stem(string $value): string {
        $value = preg_replace('/[^a-z0-9]+/', '_', strtolower($value));
        return substr(trim($value, '_'), 0, 100) ?: 'file';
    }
    private static function batchFolder(string $root, string $folder): string {
        if (strlen($folder)>500 || str_starts_with($folder,'/')) {throw new \InvalidArgumentException('Invalid destination folder');}
        $folder=self::safePath($folder);
        return implode('/',array_filter([$root,$folder],fn($part)=>$part!==''));
    }
    public static function buildBatch(array $files, array $results, string $root): array {
        $plan=[];$seen=[];$hashes=[];
        foreach ($files as $file) {
            $id=(string)$file['id'];$result=$results[$id];$action=$result['action'] ?? 'keep';
            $reason=$result['description'] ?? '';$duplicate=isset($seen[$result['sha256']]);$seen[$result['sha256']]=true;
            $similar=null;
            if (($result['phash'] ?? '')!=='') {
                foreach ($hashes as $otherId=>$hash) {
                    if (strlen($hash)===strlen($result['phash']) && self::distance($hash,$result['phash'])<=6) {$similar=$otherId;break;}
                }
                $hashes[$id]=$result['phash'];
            }
            if ($duplicate && ($result['duplicates_folder'] ?? '')!=='') {
                $action='move';$result['destination']=$result['duplicates_folder'];$reason='exact duplicate';
            } elseif (($result['blur_score'] ?? null)!==null && (float)$result['blur_score']<80 && ($result['blurry_folder'] ?? '')!=='') {
                $action='move';$result['destination']=$result['blurry_folder'];$reason='possible blur; review before applying';
            }
            if (!in_array($action,['keep','move','edit'],true)) {throw new \InvalidArgumentException('Invalid requested action');}
            if ($action==='edit' && (!in_array($file['mime'],['image/jpeg','image/png','image/webp'],true) || $file['size']>PhotoEnhancement::MAX_BYTES)) {
                $action='keep';$result['description']='This image format or size cannot be edited.';
            }
            $from=$file['path'];$extension=pathinfo($from,PATHINFO_EXTENSION);
            $name=!empty($result['rename']) ? self::stem($result['filename'] ?? '').'__'.$id.($extension!=='' ? '.'.$extension:'') : basename($from);
            $folder=self::batchFolder($root,(string)($result['destination'] ?? ''));
            $to=$action==='keep' ? $from : ($folder!=='' ? $folder.'/':'').$name;
            $original=$from;$output='';
            if ($action==='edit') {
                if (trim((string)($result['edit_prompt'] ?? ''))==='') {throw new \InvalidArgumentException('Missing photo instructions');}
                $output=($folder!=='' ? $folder.'/':'').pathinfo($name,PATHINFO_FILENAME).'__enhanced__'.$id.'.png';
                if (($result['original_destination'] ?? '')!=='') {
                    $originalFolder=self::batchFolder($root,$result['original_destination']);
                    $original=($originalFolder!=='' ? $originalFolder.'/':'').pathinfo($name,PATHINFO_FILENAME).'__'.$id.($extension!=='' ? '.'.$extension:'');
                }
                $to=$output;
            }
            $plan[]=['file_id'=>$file['id'],'from'=>$from,'to'=>$to,'etag'=>$file['etag'],'reason'=>$reason,'similar_to'=>!empty($result['flag_similar']) ? $similar:null,
                'action'=>$action,'original_to'=>$original,'edit_prompt'=>$result['edit_prompt'] ?? '', 'phase'=>$action==='keep' ? 'skipped':'pending'];
        }
        return $plan;
    }
    public static function build(array $files, array $results, string $root): array {
        $seen = []; $hashes = []; $plan = [];
        foreach ($files as $file) {
            $id = (string)$file['id']; $result = $results[$id]; $reason = 'category';
            $category = in_array($result['category'], self::CATEGORIES, true) ? $result['category'] : 'Other';
            if (isset($seen[$result['sha256']])) { $category = 'to_delete/Duplicates'; $reason = 'exact duplicate'; }
            elseif ($result['blur_score'] !== null && (float)$result['blur_score'] < 80 && str_starts_with($file['mime'], 'image/')) {
                $category = 'to_delete/Blurry'; $reason = 'possible blur; review before applying';
            }
            $seen[$result['sha256']] = true;
            $similar = null;
            if (($result['phash'] ?? '') !== '') {
                foreach ($hashes as $otherId=>$hash) {
                    if (strlen($hash) === strlen($result['phash']) && self::distance($hash, $result['phash']) <= 6) { $similar = $otherId; break; }
                }
                $hashes[$id] = $result['phash'];
            }
            $extension = pathinfo($file['path'], PATHINFO_EXTENSION);
            $filename = $result['filename'] !== '' ? self::stem($result['filename']) . ($extension !== '' ? '.' . $extension : '') : basename($file['path']);
            // Stable file-ID suffix prevents collisions without overwriting any existing file.
            $stem = pathinfo($filename, PATHINFO_FILENAME);
            $filename = $stem . '__' . $id . ($extension !== '' ? '.' . $extension : '');
            $target = ($root !== '' ? $root . '/' : '') . $category . '/' . $filename;
            $plan[] = ['file_id'=>$file['id'],'from'=>$file['path'],'to'=>$target,'etag'=>$file['etag'],
                'reason'=>$reason,'similar_to'=>$similar,'description'=>$result['description'] ?? '','phase'=>'pending'];
        }
        return $plan;
    }
    private static function distance(string $a, string $b): int {
        $distance = 0;
        foreach (str_split($a) as $i=>$value) { $distance += substr_count(decbin(hexdec($value) ^ hexdec($b[$i])), '1'); }
        return $distance;
    }
}
