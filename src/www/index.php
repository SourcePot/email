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
$headerArr=array();
$bodyArr=array();
if (isset($_POST['process'])){
    if (is_file($_FILES['msg']['tmp_name'])){
        if (!is_dir('../tmp/')){mkdir('../tmp/');}
        move_uploaded_file($_FILES['msg']['tmp_name'],'../tmp/test.msg');
        $msgContent=file_get_contents('../tmp/test.msg');
        $msgObj=new Scanner($msgContent);
        $headerArr=$msgObj->getHeader();
        $bodyArr=$msgObj->getBody();
    }
}
// compile html
$html='<!DOCTYPE html>
        <html xmlns="http://www.w3.org/1999/xhtml" lang="en">
        <head>
        <meta charset="utf-8">
        <title>E-mail</title>
        <style>
            tr:hover{background-color:#ccc;}
            td{border-left:1px dotted #444;padding:2px;}
        </style>
        </head>
        <body><form name="892d183ba51083fc2a0b3d4d6453e20b" id="892d183ba51083fc2a0b3d4d6453e20b" method="post" enctype="multipart/form-data">';
$html.='<h1 style="float:left;clear:both;margin:1em 0em 0.5em">E-mail scanner test page</h1>';
$html.='<div style="float:left;clear:both;padding:0.25em 1em;border:1px solid #000;"><label for="msg-file-upload">Upload your test message file</label><input type="file" name="msg" id="msg-file-upload" style="margin:0.25em;"/><input type="submit" name="process" id="msg-file-process" style="margin:0.25em;" value="Process"/></div>';
$html.='</form>';
$html.='<p style="float:left;clear:both;display:block;white-space:pre;word-break:break-all;word-wrap:anywhere;width:95vw;"><br/>';
if (!empty($headerArr)){
    $html.='<table style="float:left;clear:none;margin:1rem 0.5rem;box-shadow:5px 5px 10px #000;">';
    $html.='<caption style="font-size:1.5rem;font-weight:bold;">E-mail Transfer Header</caption>';
    foreach($headerArr as $key=>$valueArr){
        if (!is_array($valueArr)){$valueArr=array(''=>$valueArr);}
        foreach($valueArr as $subKey=>$value){
            if (is_object($value)){
                $value=$value->format('Y-m-d H:i:s');
            } else if (is_array($value)){
                $value=json_encode($value);
            }
            $html.='<tr>';
            $html.='<td style="">'.$key.'</td><td style="">'.$subKey.'</td><td style="">'.wordwrap(htmlentities($value),60,'<br/>',TRUE).'</td>';
            $html.='</tr>';
        }
    }
    $html.='</table>';
}

if (!empty($bodyArr)){
    $oldKey=$oldSubKey='';
    $html.='<table style="float:left;clear:none;margin:1rem 0.5rem;box-shadow:5px 5px 10px #000;">';
    $html.='<caption style="font-size:1.5rem;font-weight:bold;">E-mail Parts Header</caption>';
    foreach($bodyArr as $key=>$valueArrArr){
        $valueArrArr=$valueArrArr['header'];
        if (!is_array($valueArrArr)){$valueArrArr=array(''=>$valueArrArr);}
        foreach($valueArrArr as $subKey=>$valueArr){
            if (!is_array($valueArr)){$valueArr=array(''=>$valueArr);}
            foreach($valueArr as $subSubKey=>$value){
                if (is_object($value)){
                    $value=$value->format('Y-m-d H:i:s');
                } else if (is_array($value)){
                    $value=json_encode($value);
                }
                $html.='<tr>';
                $html.='<td style="">'.(($key===$oldKey)?'':$key).'</td><td style="">'.(($subKey===$oldSubKey)?'':$subKey).'</td><td style="">'.$subSubKey.'</td><td style="">'.wordwrap(htmlentities(strval($value)),40,'<br/>',TRUE).'</td>';
                $html.='</tr>';
                $oldKey=$key;
                $oldSubKey=$subKey;
            }
        }
    }
    $html.='</table>';
}

$html.='</body></html>';

echo $html;
?>