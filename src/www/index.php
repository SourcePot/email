<?php
/*
* This file creates an HTML page with a form for uploading an e-mail file and converting it into an array.
* @package email
* @author Carsten Wallenhauer <admin@datapool.info>
* @copyright 2024 to today Carsten Wallenhauer
* @license https://opensource.org/license/mit MIT
*/
	
declare(strict_types=1);
	
namespace SourcePot\Email;
	
mb_internal_encoding("UTF-8");

require_once('../php/Scanner.php');

// form pürocessing
$header='';
$body='';
if (isset($_POST['process'])){
    if (is_file($_FILES['msg']['tmp_name'])){
        if (!is_dir('../tmp/')){mkdir('../tmp/');}
        move_uploaded_file($_FILES['msg']['tmp_name'],'../tmp/test.msg');
        $msgContent=file_get_contents('../tmp/test.msg');
        $msgObj=new Scanner($msgContent);
        // present header
        foreach($msgObj->getHeader() as $key=>$value){
            if (is_object($value)){
                $header.=$key.': (obj) '.($value->format('Y-m-d H:i:s'))."\n";
            } else if (is_array($value)){
                foreach($value as $subKey=>$subValue){
                    $header.=$key.' | '.$subKey.': '.$subValue."\n";
                }
            } else {
                $header.=$key.': '.$value."\n";
            }
        }
        // present body
        foreach($msgObj->getBody() as $key=>$value){
            $body.="\n".$key.":\n".json_encode($value['header'])."\n";
        }
    }
}
// compile html
$html='<!DOCTYPE html>
        <html xmlns="http://www.w3.org/1999/xhtml" lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Message processing test">
        <meta name="robots" content="index">
        <title>MsgToFiles</title>
        </head>
        <body><form name="892d183ba51083fc2a0b3d4d6453e20b" id="892d183ba51083fc2a0b3d4d6453e20b" method="post" enctype="multipart/form-data">';
$html.='<h1 style="float:left;clear:both;margin:1em 0em 0.5em">Email Scanner Test</h1>';
$html.='<div style="float:left;clear:both;padding:0.25em 1em;border:1px solid #000;"><label for="msg-file-upload">Upload your test message file</label><input type="file" name="msg" id="msg-file-upload" style="margin:0.25em;"/><input type="submit" name="process" id="msg-file-process" style="margin:0.25em;" value="Process"/></div>';
$html.='</form>';
$html.='<p style="float:left;clear:both;display:block;white-space:pre;word-break:break-all;word-wrap:anywhere;width:95vw;"><br/>';
$html.='============================= HEADER ==========================<br/>';
$html.=htmlentities($header).'<br/>';
$html.='=========================== BODY PARTS ==========================<br/>';
$html.=htmlentities($body).'</p>';
$html.='</body></html>';

echo $html;
?>