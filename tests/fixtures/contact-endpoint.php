<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'message'=>'Invalid request method.']);
    exit;
}

$contentLength=(int)($_SERVER['CONTENT_LENGTH']??0);
if($contentLength>32768){
    http_response_code(413);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'message'=>'Request too large.']);
    exit;
}

$origin=$_SERVER['HTTP_ORIGIN']??'';
$referer=$_SERVER['HTTP_REFERER']??'';
$allowed='https://junctiontools.com';
if(($origin!==''&&rtrim($origin,'/')!==$allowed)&&($referer!==''&&!str_starts_with($referer,$allowed.'/'))){
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'message'=>'Invalid request origin.']);
    exit;
}

$name=trim((string)($_POST['name']??''));
$email=trim((string)($_POST['email']??''));
$message=trim((string)($_POST['message']??''));
$honeypot=trim((string)($_POST['website']??''));
if($honeypot!==''){
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'message'=>'Invalid submission.']);
    exit;
}
if($name===''||$message===''||!filter_var($email,FILTER_VALIDATE_EMAIL)){
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'message'=>'Please provide valid contact details.']);
    exit;
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success'=>true,'message'=>'Contact request accepted by test fixture.']);
