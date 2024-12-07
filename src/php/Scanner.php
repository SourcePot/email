<?php
/*
* This class is an email scanner
* @package email
* @author Carsten Wallenhauer <admin@datapool.info>
* @copyright 2024 to today Carsten Wallenhauer
* @license https://opensource.org/license/mit MIT
*/

declare(strict_types=1);

namespace SourcePot\Email;

require_once '../../vendor/autoload.php';

final class Scanner{

    public const DB_TIMEZONE='UTC';

    private $rawMsg='';
    private $header=array();
    private $body=array();
    private $msgHash='';
    
    function __construct(string $msg='')
    {
        if ($msg){
            $this->load($msg);
        }
    }

    public function load(string $msg)
    {
        $this->header=$this->body=array();
        $this->rawMsg=$msg;
        if (stripos($msg,'Delivery-date:')===FALSE && stripos($msg,'Received:')===FALSE){
            // process ole message, e.g. *.msg (Outlook)
            $msg=$this->processOleMsg($msg);
        } else {
            // process standard message, e.g. *.eml (Thunderbird)
            $this->processRawMsfg($msg);
        }
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

    private function setHeader(array $header)
    {
        $this->header=$header;
        if (empty($this->header['message-id'])){
            $hashStr=json_encode($header);
        } else {
            $hashStr=$this->header['message-id'];
        }
        $this->msgHash=sha1($hashStr);
    }

    private function processOleMsg(string $msg)
    {
        // create message object
        $messageFactory = new \Hfig\MAPI\MapiMessageFactory();
        $documentFactory = new \Hfig\MAPI\OLE\Pear\DocumentFactory(); 
        $stream=fopen('data://text/plain;base64,'.base64_encode($msg),'r');
        $ole=$documentFactory->createFromStream($stream);
        $message=$messageFactory->parseMessage($ole);
        // get header
        $header=$this->processHeader($message->properties()->transport_message_headers);
        $this->setHeader($header);
        // create body
        $envelope='--'.md5($message->properties()->body);
        $boundaries=array($envelope=>TRUE);
        $headerArr=array('boundaries'=>$boundaries,'content-type'=>array(0=>'text/plain','charset'=>'UTF-8'),'content-transfer-encoding'=>'7bit');
        $this->body=$this->addBodyPart($this->body,$headerArr,$message->properties()->body);
        $headerArr=array('boundaries'=>$boundaries,'content-type'=>array(0=>'text/html','charset'=>'UTF-8'),'content-transfer-encoding'=>'7bit');
        $this->body=$this->addBodyPart($this->body,$headerArr,$message->properties()->body_html);
        // add attachments
        foreach($message->getAttachments() as $attachment){
            $fileName=$attachment->getFilename();
            $idHash=$attachment->getContentId()??$fileName;
            $attachmentEnvelope='--'.md5($idHash);
            $boundaries[$attachmentEnvelope]=TRUE;
            $headerArr=array('boundaries'=>$boundaries,'content-type'=>array('0'=>$attachment->getMimeType(),'name'=>$fileName),'content-disposition'=>array(0=>'attachment','filename'=>$fileName));
            $this->body=$this->addBodyPart($this->body,$headerArr,$attachment->getData());
            $boundaries[$attachmentEnvelope]=FALSE;
        }
        $boundaries[$envelope]=FALSE;
    }

    private function processRawMsfg($msg)
    {
        $separatorPos=mb_strpos($msg,"\r\n\r\n");
        if ($separatorPos===FALSE){
            throw new \Exception("Header to content separator not found."); 
        }
        // seperate header
        $msgHeader=mb_substr($msg,0,$separatorPos);
        $header=$this->processHeader($msgHeader);
        $this->setHeader($header);
        // separate body
        $msgBody=mb_substr($msg,$separatorPos);
        $this->body=$this->processBody($msgBody);
    }

    private function processHeader(string $msgHeader,array $header=array(),bool $separateKeyValuePairs=FALSE):array
    {
        // unfold header fields
        $msgHeader=preg_replace('/\r\n\s+/',' ',$msgHeader);
        // get header fields and loop through these fields
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
                // get date time object
                $dateTimeObj=$this->getDateTimeObj($fieldBodyComp);
                if ($dateTimeObj){
                    $header[$fieldName.' dateTimeObj']=$dateTimeObj;
                }
                // decode values
                $eqSign=strpos($fieldBodyComp,'=');
                if ($eqSign===FALSE || !$separateKeyValuePairs){
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
                $headerArr=$this->processHeader($header,array('boundaries'=>$boundaries),TRUE);
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

    private function getDateTimeObj(string $str)
    {
        // if date time string -> create dateTime object
        preg_match('/[A-Za-z]{3},\s\d{1,2}\s[A-Z-a-z]{3}\s\d{1,4}\s\d{2}:\d{2}:\d{2}\s[0-9+]{0,5}/',$str,$dateTimeArr);
        if (empty($dateTimeArr[0])){
            return FALSE;
        } else {
            return \DateTime::createFromFormat(\DATE_RFC2822,$dateTimeArr[0]);
        }
    }

    private function keyFromHeaderArr(array $headerArr):string
    {
        $key='';
        foreach($headerArr['boundaries'] as $boundary=>$isActive){
            if (!$isActive){continue;}
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