<?php
/*
* This file is part of the MsgToFiles package.
* @package MsgToFiles
* @author Carsten Wallenhauer <admin@datapool.info>
* @copyright 2024 to today Carsten Wallenhauer
* @license https://opensource.org/license/mit MIT
*/

declare(strict_types=1);

namespace SourcePot\Email;

final class Scanner{

    public const DB_TIMEZONE='UTC';

    private $rawMsg='';
    private $header=array();
    private $body=array();
    private $msgHash='';
    
    function __construct(string $msg)
    {
        $this->rawMsg=$msg;
        $this->msgHash=sha1($msg);
        $this->processRawMsfg($msg);
    }
    
    public function getRawMsg():string
    {
        return $this->rawMsg;
    }

    public function getHeader():array
    {
        return $this->header;
    }

    public function getBody():array
    {
        return $this->body;
    }

    private function processRawMsfg($msg)
    {
        $separatorPos=mb_strpos($msg,"\r\n\r\n");
        if ($separatorPos===FALSE){
            throw new \Exception("Header to content separator not found."); 
        }
        $msgHeader=mb_substr($msg,0,$separatorPos);
        $this->header=$this->processHeader($msgHeader);
        $msgBody=mb_substr($msg,$separatorPos);
        $this->body=$this->processBody($msgBody);
    }

    private function processHeader(string $msgHeader,array $header=array()):array
    {
        // unfold header fields
        $msgHeader=preg_replace('/\r\n\s+/',' ',$msgHeader);
        // get fields
        preg_match_all('/([^:]+):([^\r\n]+)\r\n/',$msgHeader,$fields);
        foreach($fields[1] as $index=>$fieldName){
            $fieldName=strtolower($fieldName);
            $fieldBody=$fields[2][$index];
            $fieldBodyComps=explode('||',preg_replace('/([^"])(;)([^"])/','$1||$3',$fieldBody));
            foreach($fieldBodyComps as $fieldBodyCompIndex=>$fieldBodyComp){
                $fieldBodyComp=trim($fieldBodyComp);
                // mime decode
                if (strpos($fieldBodyComp,'=?')!==FALSE && strpos($fieldBodyComp,'?=')!==FALSE){
                    $fieldBodyComp=iconv_mime_decode($fieldBodyComp,0,"UTF-8");
                }
                // decode values
                $eqSign=strpos($fieldBodyComp,'=');
                if ($eqSign===FALSE){
                    // no equal sign detected
                    if (count($fieldBodyComps)>1){
                        $header[$fieldName][$fieldBodyCompIndex]=$fieldBodyComp;
                    } else {
                        $header[$fieldName]=$fieldBodyComp;
                    }
                } else {
                    // equal sign detected
                    $subKey=substr($fieldBodyComp,0,$eqSign);
                    $subValue=substr($fieldBodyComp,$eqSign+1);
                    $header[$fieldName][$subKey]=$subValue;
                }
            }
        }
        return $header;
    }

    private function processBody($msgBody):array
    {
        $body=array();
        // scan body
        $isHeader=FALSE;
        $header='';
        $content='';
        $boundaries=array();
        $msgBodyLines=explode("\r\n",$msgBody);
        foreach($msgBodyLines as $lineIndex=>$line){
            $line.="\r\n";
            // an empty line separates header from content - update header string
            if (empty(trim($line,"\r\n"))){
                $isHeader=FALSE;
                continue;
            }
            if ($isHeader){$header.=$line;}
            // detect boundaries
            if (strpos($line,'--')===0 && strpos($line,'-->')===FALSE){
                $isHeader=TRUE;
                $headerArr=$this->processHeader($header,array('boundaries'=>$boundaries));
                // update boundary
                $boundaryA=trim($line);
                $boundaryA=substr($boundaryA,2);
                $bounderyB=rtrim($boundaryA,'-');
                if ($boundaryA===$bounderyB){
                    $boundaries[$boundaryA]=TRUE;
                } else {
                    $boundaries[$bounderyB]=FALSE;
                }
                // update body
                $body=$this->addBodyPart($body,$headerArr,$content);
                $content=$header='';
            }
            // update content string
            if (!$isHeader){$content.=$line;}
        
        }
        return $body;
    }

    private function addBodyPart(array $body,array $headerArr,string $content):array
    {
        if (empty(trim($content))){return $body;}
        // decode content
        if (!empty($headerArr['content-transfer-encoding'])){
            $content=$this->decodeContent($content,$headerArr['content-transfer-encoding']);
        }
        /*
        if (!empty($headerArr['content-disposition']['filename'])){
            $file='../tmp/'.trim($headerArr['content-disposition']['filename'],'"');
            file_put_contents($file,$content);
        }
        */
        $key=$this->keyFromHeaderArr($headerArr);
        $body[$key]=array('header'=>$headerArr,'content'=>$content);
        return $body;
    }

    private function decodeContent(string $content,string $encoding)
    {
        switch($encoding){
            case 'base64':
                $content=base64_decode($content);
                break;
            case 'quoted-printable':
                $content=quoted_printable_decode($content);
                break;
            default:
                $content=$content;
        }
        return $content;
    }

    private function keyFromHeaderArr(array $headerArr):string
    {
        $key='';
        foreach($headerArr['boundaries'] as $boundary=>$isActive){
            if (!empty($key)){$key.=',';}
            $hash=md5($boundary.$this->msgHash);
            $hash=base_convert($hash,16,32);
            $hash=str_replace('0','',$hash);
            $hash=str_replace('|','x',$hash);
            $key.=$hash;
        }
        if (!empty($key)){
            $key='('.$key.')';
        }
        if (isset($headerArr['content-type'][0])){
            $key.='['.$headerArr['content-type'][0].']';
        }
        if (isset($this->header['subject'])){
            $suffix=(mb_strlen($this->header['subject'])>40)?'... ':' ';
            $key=mb_substr($this->header['subject'],0,40).$suffix.$key;
        }
        return $key;
    }

}
?>