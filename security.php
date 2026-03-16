<?php
session_start();
function csrf_token(){
 if(empty($_SESSION['csrf_token'])){
  $_SESSION['csrf_token']=bin2hex(random_bytes(32));
 }
 return $_SESSION['csrf_token'];
}
function verify_csrf(){
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!isset($_POST['csrf_token']) || $_POST['csrf_token']!==$_SESSION['csrf_token']){
   http_response_code(403);
   echo json_encode(['error'=>'Invalid CSRF token']);
   exit;
  }
 }
}
?>