# Email package

So far the email package only contains the scanner class and a test website.

## Scanner class

The scanner class extracts the content and headers of an e-mail that is read in as a string. The result is available as an array. The e-mail formats of the Internet Text Message Standard (e.g. Thimderbird) or OLE (e.g. Outlook) are currently supported.

## First steps using the Scanner class in your project

Following code shows how an instance of the Scanner class is created and the email loaded into the scanner. The results can be retrieved using the getHeader() and getParts() methods:
```
$scanner = new SourcePot\Email\Scanner();
$scanner->load($email);

$emailTransferHeader = $scanner->getHeader();
$emailParts = $scanner->getParts();
```

The source for an email can be a file upload or an IMAP mailbox folder. In the following example, an IMAP mailbox folder is opened and "today's" emails are looped through. The emails will be represented as character strings. You will need to provide the correct {MAILBOX}, {USER} and {PASSWORD} for the email folder.
```
$scanner = new SourcePot\Email\Scanner();

$mbox=@imap_open({MAILBOX},{USER},{PASSWORD});

$errors=imap_errors();
// add error handling code here

$alerts=imap_alerts();
// add alarm handling code here

if (!empty($mbox)){
    $messages=imap_search($mbox,'SINCE "'.date('d-M-Y').'"');
    if ($messages){
        foreach($messages as $mid){
            $email=imap_fetchbody($mbox,$mid,"");
            $scanner->load($email);
            // add code here to process the resulting $emailParts, $emailTransferHeader
        }
    }
}    
```

## Test website
A test website is part of the package. An e-mail can be uploaded as a file to a temporary directory via the test website and then processed by the scanner class.

To use classes of the package or the test website, you must install the package on your computer (in the web directory of your localhost). After installation, you can open the test website via your browser. The easiest way to install the package is to use Composer. First make sure that Composer is already installed and then use the command prompt to execute the installation command:
```
composer create-project sourcepot/email {... add target web-directory here ...}
```

Let's start with the following test email which contains an attachment (pdf-file):

<kbd><img src="./assets/test_message.png" alt="Test email" style="width:400px;"/></kbd>

The test email is dragged & dropped form Thunderbird into the Windows file explorer:

<kbd><img src="./assets/test_message_upload.png" alt="Test copied to a folder on the computer" style="width:500px;"/></kbd>

The test website is opened on the localhost. Select the email and upload the email, click the "Process" button to process the email. The e-mail transfer header and each e-mail part are displayed in a separate table. The folloing screenshot shows the test result, can you see the preview of attachment?

<kbd><img src="./assets/test_message_test_page.png" alt="Test email uploaded and processed"/></kbd>

