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

const TMP_DIR='../tmp/';

if (!is_dir(TMP_DIR)){mkdir(TMP_DIR);}
// temparary firectory clean-up
$files=scandir(TMP_DIR);
foreach($files as $file){
    if (is_file(TMP_DIR.$file)){unlink(TMP_DIR.$file);}
}
// form pürocessing
$headerArr=array();
$bodyArr=array();
if (isset($_POST['process'])){
    if (is_file($_FILES['msg']['tmp_name'])){
        move_uploaded_file($_FILES['msg']['tmp_name'],TMP_DIR.'test.msg');
        $msgContent=file_get_contents(TMP_DIR.'test.msg');
        $msgObj=new Scanner($msgContent);
        $headerArr=$msgObj->getHeader();
        $bodyArr=$msgObj->getParts();
    }
}
// compile html
$html='<!DOCTYPE html>
        <html xmlns="http://www.w3.org/1999/xhtml" lang="en">
        <head>
        <meta charset="utf-8">
        <title>E-mail</title>
        <style>
            *{font-family: system-ui;font-size:12px;}
            h1{font-size:18px;}
            h2{font-size:16px;}
            tr:hover{background-color:#ccc;}
            td{border-left:1px dotted #444;padding:2px;}
            p{float:left;clear:both;}
            embed{float:left;clear:both;max-width:30vw;}
            div{float:left;clear:both;width:95vw;padding:0.25em 1em;border:1px solid #000;background-color:antiquewhite;}
            table{float:left;clear:none;margin:0.8rem 0.8rem 0.8rem 0;border:1px solid #aaa;box-shadow:3px 3px 10px #777;}
            caption{font-size:1.25rem;font-weight:bold;}
            input[type=file]{background-color:white;}
            input{cursor:pointer;}
        </style>
        </head>
        <body><form name="892d183ba51083fc2a0b3d4d6453e20b" id="892d183ba51083fc2a0b3d4d6453e20b" method="post" enctype="multipart/form-data">';
$html.='<h1>E-mail scanner test page<br/>This page is intended for testing on a localhost, i.e. on a PC only. It must not be accessible from a public network!</h1>';
$html.='<div><label for="msg-file-upload">Test file upload</label><input type="file" name="msg" id="msg-file-upload" style="margin:0.25em;"/><input type="submit" name="process" id="msg-file-process" style="margin:0.25em;" value="Process"/></div>';
$html.='</form>';
if (!empty($headerArr)){
    $html.='<table>';
    $html.='<caption>E-mail Transfer Header</caption>';
    foreach($headerArr as $key=>$valueArr){
        if (!is_array($valueArr)){$valueArr=array(''=>$valueArr);}
        foreach($valueArr as $subKey=>$value){
            if (is_object($value)){
                $value=$value->format('Y-m-d H:i:s');
            } else if (is_array($value)){
                $value=json_encode($value);
            }
            $html.='<tr>';
            $html.='<td style="">'.$key.'</td><td style="">'.$subKey.'</td><td style="">'.htmlentities(strval($value)).'</td>';
            $html.='</tr>';
        }
    }
    $html.='</table>';
}

if (!empty($bodyArr)){
    $oldKey=$oldSubKey='';
    foreach($bodyArr as $key=>$valueArrArr){
        $data=$valueArrArr['data'];
        $valueArrArr=$valueArrArr['header'];
        $html.='<table>';
        $html.='<caption>Part: '.$key.'</caption>';
        if (!is_array($valueArrArr)){$valueArrArr=array(''=>$valueArrArr);}
        foreach($valueArrArr as $subKey=>$valueArr){
            if (!is_array($valueArr)){$valueArr=array(''=>$valueArr);}
            foreach($valueArr as $subSubKey=>$value){
                if (is_object($value)){
                    $value=$value->format('Y-m-d H:i:s');
                } else if (is_array($value)){
                    $value=json_encode($value);
                }
                // compile value html
                $valueHtml='<p>'.htmlentities(strval($value)).'</p>';
                if ($subKey==='content-disposition' && $subSubKey==='filename'){
                    $fileNameParts=pathinfo($value);
                    $fileName=TMP_DIR.$value;
                    file_put_contents($fileName,$data);
                    $valueHtml.='<embed src="'.$fileName.'" type="'.$valueArrArr['content-type'][0].'"/>';
                }
                // build table row
                $html.='<tr>';
                $html.='<td style="">'.(($subKey===$oldSubKey)?'':$subKey).'</td><td style="">'.$subSubKey.'</td><td style="">'.$valueHtml.'</td>';
                $html.='</tr>';
                $oldKey=$key;
                $oldSubKey=$subKey;
            }
        }
        $html.='</table>';
    }
}
$html.='</body></html>';
echo $html;

?>