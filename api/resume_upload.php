<?php
$max=5*1024*1024;
$allowed=['pdf','doc','docx','jpg','jpeg','png'];

if(!isset($_FILES['resume'])){
 echo json_encode(['success'=>false]);
 exit;
}

$f=$_FILES['resume'];
$ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));

if($f['size']>$max){ echo json_encode(['success'=>false]); exit; }
if(!in_array($ext,$allowed)){ echo json_encode(['success'=>false]); exit; }

$name='resume_'.time().'_'.$ext;
$path=__DIR__.'/../uploads/resumes/'.$name;

if(move_uploaded_file($f['tmp_name'],$path)){
 echo json_encode(['success'=>true,'path'=>'uploads/resumes/'.$name]);
}else{
 echo json_encode(['success'=>false]);
}
?>