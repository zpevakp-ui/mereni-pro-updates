<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();
$config = require __DIR__ . '/config.php';
try {$pdo = new PDO('mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4',$config['db_user'],$config['db_pass'],array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));} catch (Throwable $e) {http_response_code(500);exit('Databázi se nepodařilo připojit.');}
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function csrf_token(){if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function check_csrf(){if(!isset($_POST['csrf'],$_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$_POST['csrf'])){http_response_code(403);exit('Neplatný bezpečnostní token.');}}
function redirect_to($url){header('Location: '.$url);exit;}
function is_admin(){return isset($_SESSION['role'])&&$_SESSION['role']==='admin';}
function require_admin(){if(!is_admin()){http_response_code(403);exit('Přístup je povolen pouze administrátorovi.');}}
$currentPage=basename($_SERVER['SCRIPT_NAME']??'');
$publicPages=array('login.php','activate-account.php');
if(!in_array($currentPage,$publicPages,true)&&empty($_SESSION['user_id'])){header('Location: login.php');exit;}
function report_status_label($s){$a=array('draft'=>'Rozpracovaný','completed'=>'Dokončený','issued'=>'Vydaný');return isset($a[$s])?$a[$s]:$s;}
function status_label($s){$a=array('new'=>'Nová','in_progress'=>'Rozpracovaná','measured'=>'Změřeno','completed'=>'Dokončená');return isset($a[$s])?$a[$s]:$s;}
