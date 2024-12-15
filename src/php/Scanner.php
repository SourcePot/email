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

require_once('../../vendor/autoload.php');

final class Scanner{

    public const DB_TIMEZONE='UTC';

    private $rawMsg='';
    private $prefixArr=array();
    private $suffixArr=array();
    private $parts=array();
    private $msgHash='';

    private $transferHeader=array();
    private $boundaries=array();
    
    function __construct(string $msg='')
    {
        if ($msg){
            $this->load($msg);
        }
    }

    public function load(string $msg)
    {
        $this->transferHeader=$this->parts=$this->prefixArr=$this->suffixArr=$this->boundaries=array();
        $this->rawMsg=$msg;
        if (stripos($msg,'Delivery-date:')===FALSE && stripos($msg,'Received:')===FALSE){
            // process ole message, e.g. *.msg (Outlook)
            $msg=$this->processOleMsg($msg);
        } else {
            // process standard message, e.g. *.eml (Thunderbird)
            $this->processStdMeg($msg);
        }
    }
    
    public function getRawMsg():string
    {
        return $this->rawMsg;
    }

    public function getHeader():array
    {
        return $this->transferHeader;
    }

    public function getParts():array
    {
        return $this->parts;
    }

    private function setHeader(array $header)
    {
        $this->transferHeader=$header;
        if (empty($this->transferHeader['message-id'])){
            $hashStr=json_encode($header);
        } else {
            $hashStr=$this->transferHeader['message-id'];
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
        $header=array('boundaries'=>$boundaries,'content-type'=>array(0=>'text/plain','charset'=>'UTF-8'),'content-transfer-encoding'=>'7bit');
        if (isset($message->properties()->body)){
            $this->parts=$this->addPart($this->parts,$header,$message->properties()->body);
        }
        $header=array('boundaries'=>$boundaries,'content-type'=>array(0=>'text/html','charset'=>'UTF-8'),'content-transfer-encoding'=>'7bit');
        if (isset($message->properties()->body_html)){
            $this->parts=$this->addPart($this->parts,$header,$message->properties()->body_html);
        }
        // add attachments
        foreach($message->getAttachments() as $attachment){
            $fileName=$attachment->getFilename();
            $idHash=$attachment->getContentId()??$fileName;
            $attachmentEnvelope='--'.md5($idHash);
            $boundaries[$attachmentEnvelope]=TRUE;
            $header=array('boundaries'=>$boundaries,'content-type'=>array('0'=>$attachment->getMimeType(),'name'=>$fileName),'content-disposition'=>array(0=>'attachment','filename'=>$fileName));
            $this->parts=$this->addPart($this->parts,$header,$attachment->getData());
            $boundaries[$attachmentEnvelope]=FALSE;
        }
        $boundaries[$envelope]=FALSE;
    }

    private function processStdMeg(string $msg,array $header=array())
    {
        if (empty($header)){
            // initial method call - transfer header will be set
            $msgSections=$this->separateHeaderBody($msg,TRUE,FALSE);
            $this->transferHeader=$msgSections['header'];
            $this->processStdMeg($msgSections['body'],$this->transferHeader);
        } else {
            if ($header['isMultipart']){
                // mutipart message needs further separation
                if (empty($header['boundary'])){
                    throw new \Exception('Multipart message "'.$this->transferHeader['subject'].'" but boundary not found.'); 
                } else {
                    $this->boundaries[]=$header['boundary'];
                    $startBoundary="--".$header['boundary']."\r\n";
                    $endBoundary="\r\n--".$header['boundary']."--\r\n";
                    $msgChunks=explode($endBoundary,$msg);
                    // get any content afte the end boundary
                    $msgSuffix=array_pop($msgChunks);
                    // get any content before the first start boundary
                    $msgSections['body']=array_shift($msgChunks);
                    $msgParts=explode($startBoundary,$msgSections['body']);
                    $msgPrefix=array_shift($msgParts);
                    if (!empty($msgPrefix)){$this->prefixArr[]=$msgPrefix;}
                    if (!empty($msgSuffix)){$this->suffixArr[]=$msgSuffix;}
                    // loop through parts within the current boundary
                    foreach($msgParts as $msgPart){
                        $msgPartSections=$this->separateHeaderBody($msgPart,FALSE,TRUE);
                        $this->processStdMeg($msgPartSections['body'],$msgPartSections['header']);
                    }
                }
            } else {
                // no multipart message - final content
                $header=$this->getContentHeader($header);
                $header['boundaries']=$this->boundaries;
                $this->parts=$this->addPart($this->parts,$header,$msg);
            }
        }
    }

    private function separateHeaderBody(string $msg,bool $strict=FALSE,bool $separateKeyValuePairs=FALSE):array
    {
        $arr=array('header'=>array(),'body'=>'');
        $separator="\r\n\r\n";
        $separatorPos=mb_strpos($msg,$separator)+mb_strlen($separator);
        if ($separatorPos===FALSE && $strict){
            throw new \Exception('Faild to dived message into sections, separator missing.'); 
        } else {
            // seperate header
            $msgHeader=mb_substr($msg,0,$separatorPos);
            $arr['header']=$this->processHeader($msgHeader,array(),$separateKeyValuePairs);
            // separate body
            $arr['body']=mb_substr($msg,$separatorPos);
        }
        return $arr;
    }

    private function processHeader(string $msgHeader,array $header=array(),bool $separateKeyValuePairs=FALSE):array
    {
        $header['MIME-type']=$header['MIME-type']??'';
        $header['isMultipart']=FALSE;
        $header['boundary']=$header['boundary']??'';
        // unfold header fields
        $msgHeader=preg_replace('/\r\n\s+/',' ',$msgHeader);
        // get header fields and loop through these fields
        preg_match_all('/([^:]+):([^\r\n]+)\r\n/',$msgHeader,$fields);
        $lines=explode("\r\n",$msgHeader);
        $fieldSep=': ';
        foreach($lines as $line){
            $nameValueSepPos=mb_strpos($line,$fieldSep);
            if ($nameValueSepPos===FALSE){continue;}
            $fieldName=mb_substr($line,0,$nameValueSepPos);
            $fieldName=mb_strtolower($fieldName);
            $fieldBody=mb_substr($line,$nameValueSepPos+mb_strlen($fieldSep));
            // mime decode
            if (strpos($fieldBody,'=?')!==FALSE && strpos($fieldBody,'?=')!==FALSE){
                $fieldBody=iconv_mime_decode($fieldBody,0,"utf-8");
            }
            // get boundary and content type
            if ($fieldName=="content-type"){
                preg_match('/boundary="{0,1}([^"]+)"{0,1}/',$fieldBody,$boundaryMatch);
                if (!empty($boundaryMatch[1])){$header['boundary']=$boundaryMatch[1];}
                preg_match('/\w+\/\w+/',$fieldBody,$mimeMatch);
                if (!empty($boundaryMatch[0])){$header['MIME-type']=$mimeMatch[0];}
                if (mb_strpos($fieldBody,'multipart/')!==FALSE){$header['isMultipart']=TRUE;}
            }
            // add date time object
            $dateTimeObj=$this->getDateTimeObj($fieldBody);
            if ($dateTimeObj){
                $header[$fieldName.' dateTimeObj']=$dateTimeObj;
            }
            // add mailboxes
            $mailboxes=$this->getMailboxes($fieldBody);
            if (!empty($mailboxes)){
                $header[$fieldName.' mailboxes']=$mailboxes;
            }
            // seperate into fieldBody comps
            $fieldBodyComps=preg_split('/;\s*/',$fieldBody);
            foreach($fieldBodyComps as $fieldBodyCompIndex=>$fieldBodyComp){
                $fieldBodyComp=trim($fieldBodyComp);
                // decode values
                $eqSign=strpos($fieldBodyComp,'=');
                if ($eqSign===FALSE || !$separateKeyValuePairs){
                    // no equal sign detected
                    if (count($fieldBodyComps)>1){
                        if (!isset($header[$fieldName])){
                            // field not yet set
                            $header[$fieldName][$fieldBodyCompIndex]=$fieldBodyComp;
                        } else if(is_array($header[$fieldName])){
                            // field set and it is of type array
                            $header[$fieldName][$fieldBodyCompIndex]=$fieldBodyComp;
                        } else {
                            // existing field is not of type array -> needs to be conevrted to array
                            $header[$fieldName]=array('org'=>$header[$fieldName],$fieldBodyCompIndex=>$fieldBodyComp);
                        }
                    } else {
                        $header[$fieldName]=$fieldBodyComp;
                    }
                } else {
                    // equal sign detected
                    $subKey=substr($fieldBodyComp,0,$eqSign);
                    $subValue=substr($fieldBodyComp,$eqSign+1);
                    $header[$fieldName][$subKey]=trim($subValue,'"');
                }
            }
        }
        return $header;
    }

    private function getContentHeader(array $header):array{
        $contentHeader=array();
        foreach($header as $key=>$value){
            if (mb_strpos($key,'content-')===0 || ($key==='boundary' && !empty($value)) || $key==='MIME-type'){
                $contentHeader[$key]=$value;
            }
        }
        return $contentHeader;
    }

    private function addPart(array $part,array $header,string $content):array
    {
        if (empty(trim($content))){return $part;}
        // decode content
        if (!empty($header['content-transfer-encoding'])){
            $content=$this->decodeContent($content,$header['content-transfer-encoding']);
        }
        $key=$this->keyFromHeaderArr($header);
        $header['data']['size']=strlen($content);
        $part[$key]=array('header'=>$header,'data'=>$content);
        return $part;
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
        preg_match('/[A-Za-z]{3},\s+\d{1,2}\s[A-Z-a-z]{3}\s\d{1,4}\s+\d{2}:\d{2}:\d{2}\s[0-9+]{0,5}/',$str,$dateTimeArr);
        if (empty($dateTimeArr[0])){
            return FALSE;
        } else {
            return \DateTime::createFromFormat(\DATE_RFC2822,$dateTimeArr[0]);
        }
    }

    private function getMailboxes(string $str):array{
        $mailboxes=array();
        if (mb_strpos($str,'@')>0 && mb_strpos($str,'.')>0){
            $mailboxes=preg_split('/,\s("|<)/',$str);
            if (count($mailboxes)===1){
                $mailboxes=preg_split('/> </',$str);
            }
            foreach($mailboxes as $index=>$mailbox){
                if (mb_strpos($mailbox,'<')!==FALSE && mb_strpos($mailbox,'>')>4){
                    // type: ...<a@b.com>
                    $mailboxComps=explode(' <',$mailbox);
                    $mailboxAddr=array_pop($mailboxComps);
                    $mailboxAddr=trim($mailboxAddr,' <>');
                    $mailboxValue=(empty($mailboxComps))?'':(array_shift($mailboxComps));
                    $mailboxValue=trim($mailboxValue,'"\'');
                    $mailboxes[$index]=array('email'=>$mailboxAddr,'name'=>$mailboxValue);
                } else {
                    // a@b.com
                    preg_match('/[^\s@=:;]+@[^\s@\.]+\.[a-zA-Z]+/',$mailbox,$match);
                    if (isset($match[0])){
                        $mailboxAddr=trim($match[0],'<>');
                        $mailboxes[$index]=array('email'=>$mailboxAddr,'name'=>'');
                    }
                }
            }
        }
        return $mailboxes;
    }

    private function keyFromHeaderArr(array $header):string
    {
        $key='';
        foreach($header['boundaries'] as $boundary=>$isActive){
            if (!$isActive){continue;}
            if (!empty($key)){$key.='|';}
            $key.=$this->shortHash($boundary.$this->msgHash);
        }
        if (!empty($key)){
            $key='('.$key.')';
        }
        if (isset($header['content-id'])){
            $key.='{'.$this->shortHash($header['content-id']).'}';
        }
        if (isset($header['content-type'][0])){
            $key.='['.$header['content-type'][0].']';
        }
        if (isset($this->transferHeader['subject'])){
            if (is_array($this->transferHeader['subject'])){
                $this->transferHeader['subject']=implode('; ',$this->transferHeader['subject']);
            }
            $suffix=(mb_strlen($this->transferHeader['subject'])>40)?'... ':' ';
            $key=mb_substr($this->transferHeader['subject'],0,40).$suffix.$key;
        }
        return $key;
    }

    private function shortHash(string $str):string
    {
        $hash=md5($str);
        $hash=base_convert($hash,16,32);
        $hash=str_replace('0','',$hash);
        $hash=str_replace('|','x',$hash);
        return $hash;
    }

}
?>