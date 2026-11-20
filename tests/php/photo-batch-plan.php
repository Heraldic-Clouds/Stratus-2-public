<?php
declare(strict_types=1);
require __DIR__.'/../../apps/stratus2_ai/lib/Service/Planner.php';
require __DIR__.'/../../apps/stratus2_ai/lib/Service/PhotoEnhancement.php';
use OCA\Stratus2AI\Service\Planner;
function checkPlan(bool $value,string $message): void {if (!$value) {throw new RuntimeException($message);}}
$file=['id'=>3,'path'=>'Family/black.jpg','mime'=>'image/jpeg','size'=>100,'etag'=>'a'];
$result=['sha256'=>str_repeat('a',64),'category'=>'Photos','filename'=>'photo','description'=>'black and white',
 'phash'=>'','blur_score'=>200,'destination'=>'Colorized','original_destination'=>'Original','edit_prompt'=>'Colorize naturally',
 'rename'=>false,'duplicates_folder'=>'','blurry_folder'=>'','flag_similar'=>false,'action'=>'edit'];
$plan=Planner::buildBatch([$file],['3'=>$result],'Family');
checkPlan($plan[0]['action']==='edit' && $plan[0]['to']==='Family/Colorized/black__enhanced__3.png','Edited destination incorrect');
checkPlan($plan[0]['original_to']==='Family/Original/black__3.jpg','Original destination incorrect');
$result['action']='keep';
checkPlan(Planner::buildBatch([$file],['3'=>$result],'Family')[0]['from']===Planner::buildBatch([$file],['3'=>$result],'Family')[0]['to'],'Unmatched file changed');
$result['action']='edit';$result['destination']='../../outside';
try {Planner::buildBatch([$file],['3'=>$result],'Family');throw new RuntimeException('Traversal accepted');} catch (InvalidArgumentException) {}
$other=$file;$other['id']=4;$other['path']='Family/copy.jpg';
$result['destination']='Colorized';$result['duplicates_folder']='to_delete/Duplicates';
$plan=Planner::buildBatch([$file,$other],['3'=>$result,'4'=>$result],'Family');
checkPlan($plan[1]['action']==='move' && str_contains($plan[1]['to'],'to_delete/Duplicates'),'Exact duplicate was not grouped');
echo "PASS: selective image edits, requested folders, originals, traversal and duplicate handling\n";
