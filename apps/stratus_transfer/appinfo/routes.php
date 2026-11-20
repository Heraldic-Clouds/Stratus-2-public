<?php
declare(strict_types=1);
return ['routes'=>[
    ['name'=>'page#index','url'=>'/','verb'=>'GET'],
    ['name'=>'transfer#status','url'=>'/api/status','verb'=>'POST'],
    ['name'=>'transfer#browse','url'=>'/api/browse','verb'=>'POST'],
    ['name'=>'transfer#download','url'=>'/api/download','verb'=>'POST'],
    ['name'=>'transfer#refresh','url'=>'/api/refresh','verb'=>'POST'],
    ['name'=>'transfer#progress','url'=>'/api/progress','verb'=>'POST'],
    ['name'=>'transfer#upload','url'=>'/api/upload','verb'=>'POST'],
]];
