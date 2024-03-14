<?php if (!defined('PmWiki')) exit();
/* 
  Send mail with Curl via SMTP for PmWiki
  Copyright 2018-2024 Petko Yotov pmwiki.org/petko
  
  This file is free Software, you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published
  by the Free Software Foundation; either version 3 of the License, or
  (at your option) any later version.  
*/

$RecipeInfo['SMTPMail']['Version'] = '20240314';
SDV($MailFunction, "MailSMTP");

SDVA($LinkFunctions, array('cid:'=>'LinkIMap'));
SDVA($IMap, array('cid:'=>'cid:$1'));
if(isset($LinkPattern)) $LinkPattern .= "|cid:";

SDVA($SMTPMail, array(
  'curl' => 'curl',
  'server' => 'smtps://smtp.gmail.com:465',
  'options' => ' --ssl -k --anyauth',
  'from' => 'user@example.com',
  'userpass' => '',
  'bcc' => '',
  'wordwrap'=>998,
));

function MailSMTP($to, $subject='', $message='', $headers='') {
  global $WorkDir, $SMTPMail, $Now, $FmtV;
  static $x;
  
  if(is_array($to) && isset($to['server'])) {
    $x = $to;
    return;
  }
  elseif(is_callable('SMTPMailOpt')) $x = SMTPMailOpt();
  
  SDVA($x, $SMTPMail);
  extract($x);
  
  $to = preg_replace('/\\s+/', ' ', $to);
  $to = preg_replace('/["!$]+/', '', $to);
  
  $rcpt = "$to,$bcc";
  
  if(preg_match('/^Cc: *(\\S.*)$/mi', $headers, $m)) $rcpt .= ",$m[1]";
  if(preg_match('/^Bcc: *(\\S.*)$/mi', $headers, $m)) {
    $rcpt .= ",$m[1]";
    $headers = preg_replace("/^Bcc: .*$/mi", '', $headers);
  }
  
  $mailto = array();
  $tos = preg_split('/[,\\s]+/', $rcpt);
  foreach($tos as $e) if($e) $mailto[$e] = " --mail-rcpt " . escapeshellarg($e);
  if(isset($CountFunction)) {
    $CountFunction(count($mailto));
  }  
  $emailto = implode(' ', $mailto);
  
  $subject = trim(preg_replace('/\\s+/', ' ', $subject));
  if(preg_match('/<(\\S+@\\S+)>/', $from, $m)) $mailfrom = $m[1];
  else $mailfrom = $from;
  $efrom = escapeshellarg($mailfrom);
  
  $message = str_replace("\r", '', $message);
  $message = wordwrap($message, $SMTPMail['wordwrap'], "\n");

  
  $headers = trim(str_replace("\r", '', $headers));
  if($headers) $headers .= "\n";
  $date = date('r');
  $mid = mt_rand(100000, 999999) . ".$Now.$mailfrom";
  
  if(!preg_match('/^From:/m', $headers)) $headers .= "From: $from\n";
  $headers .= "To: $to\n";
  $headers .= "Subject: $subject\n";
  $headers .= "Date: $date\n";
  $headers .= "Message-ID: <$mid>\n";

  $headers = preg_replace("/\n\n+/", "\n", $headers);
  
  $envelope = trim($headers) . "\n\n" . ltrim($message, "\n");
  
  $envelope = str_replace("\n", "\r\n", $envelope);
    
  $temp = tempnam($WorkDir,"mail");
  if ($fp = fopen($temp,"w")) {
    fputs($fp,$envelope);
    fclose($fp);
  }
  else {
    $SMTPMail['debug'] = "Cannot create temp file '$temp'.";
    return false;
  }
  $etemp = escapeshellarg($temp);
  
  if(@$userpass) $userpass = '-u '. escapeshellarg($userpass);
  $command = "$curl $server -vv $userpass --mail-from $efrom  $emailto --upload-file $etemp 2>&1 ";
  
  ob_start();
    passthru($command);
  $FmtV['$CurlOutput'] = $ret = ob_get_clean();
  
  
  if(@$x['imapsentfolder']) {
    if(!isset($x['imapuserpass'])) $x['imapuserpass'] = $x['userpass'];
    $iuserpass = '-u '. escapeshellarg($x['imapuserpass']);
    $ifolder = escapeshellarg($x['imapsentfolder']);
    
    $icommand = "$curl $ifolder -vv $iuserpass --upload-file $etemp 2>&1 ";
    ob_start();
      passthru($icommand);
    $FmtV['$CurlImapOutput'] = $iret = ob_get_clean();
  }
  
  @unlink($temp);
  
  if(preg_match('/We are completely uploaded and fine/i', $ret)) return true;
  return false;
}

# This helper function combines a multuipart message with plain text,
# wiki markup (converted to html), html, embedded pictures and attached files.
# It returns the combined message and an additional multipart/mixed header.
function MultipartMailSMTP($a, $pn=null) {
  global $UploadExts, $pagename, $LinkFunctions, $LinkPattern, $IMap, $SMTPMailStylesFmt;
  
  if(is_null($pn)) $pn = $pagename;
  $message = '';
  
  $mf = $LinkFunctions['mailto:'];
  $LinkFunctions['mailto:'] = 'LinkIMap';
  
  $boundary = "MULTIPART-MIXED-BOUNDARY";
  
  foreach($a as $k=>$v) {
    $j = 'text'; $content = $v; $fname = $cte = $cid = $ct = '';
    
    if(preg_match('/^(cid|file|markup|html|content):(.*)$/s', $v, $m)) {
      $j = $m[1]; $content = $m[2];
    }
    if($j=='content') { # array key is filename
      $fname = $k;
    }
    elseif($j=='cid'||$j=='file') {
      $fname = preg_replace('!^.*/!s', '', $m[2]);
      $content = file_get_contents($m[2]);
    }
    elseif($j=='markup') {
      $content = MarkupToHTML($pn, $content);
    }
    
    if($j=='text') {
      $ct = 'text/plain; charset=utf-8';
    }
    elseif($j=='markup'||$j=='html') {
      $ct = 'text/html; charset=utf-8';
      
      $styles = '';
      if(is_string($SMTPMailStylesFmt)) {
        $styles = $SMTPMailStylesFmt;
      }
      elseif(is_array($SMTPMailStylesFmt)) {
        $styles = implode("\n", $SMTPMailStylesFmt);
      }
      
      $content = "<!doctype html><html><head><meta charset=\"utf-8\">
<style>.vspace{margin-top:1.5rem;} .indent{margin-left:40px;} .right{text-align:right;}$styles</style>
</head><body>$content</body></html> ";
    }
    else {
      $ext = strtolower(preg_replace('!^.*\\.!', '', $fname));
      if(isset($UploadExts[$ext])) $ct = $UploadExts[$ext];
      else $ct = 'application/octet-stream';
    }
    if($fname) $ct .= "; name=\"$fname\"";
    
    $message .= "--$boundary\n";
    $message .= "Content-Type: $ct\n";
    if($j=='cid') {
      $message .= "Content-ID: <$fname>\n";
      $message .= "X-Attachment-Id: $fname\n";
    }
       
    if($j=='cid'||$j=='markup'||$j=='text'||$j=='html') { #
      $cd = 'inline';
    }
    else $cd = "attachment";
    
    if($fname) $cd .= "; filename=\"$fname\"";
    
    $message .= "Content-Disposition: $cd\n";
    
    if($j!='text' && $j!='markup' && $j!='html') {
      $content = chunk_split(base64_encode($content));
      $message .= "Content-Transfer-Encoding: base64\n";
    }
    
    $message .= "\n$content\n\n";
  }
  $LinkFunctions['mailto:'] = $mf;
  
  
  $message = str_replace("\n", "\r\n", $message);
  $header = "Content-Type: multipart/mixed; boundary=\"$boundary\"";
  
  
  return [$message, $header];
}

