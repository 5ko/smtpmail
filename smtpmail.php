<?php if (!defined('PmWiki')) exit();
/* 
  Send mail with Curl via SMTP for PmWiki (module version)
  Copyright 2018-2023 Petko Yotov pmwiki.org/petko
  
  This file is free Software, you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published
  by the Free Software Foundation; either version 3 of the License, or
  (at your option) any later version.  
*/

$RecipeInfo['SMTPMail']['Version'] = '20230224';
SDV($MailFunction, "MailSMTP");

SDVA($SMTPMail, array(
  'curl' => 'curl',
  'server' => 'smtps://smtp.gmail.com:465 --ssl',
  'options' => ' -k --anyauth',
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
    $headers = str_replace("\r\n\r\n", "\r\n", $headers);
  }
  
  $mailto = array();
  $tos = preg_split('/[,\\s]+/', $rcpt);
  foreach($tos as $e) if($e) $mailto[$e] = " --mail-rcpt " . escapeshellarg($e);
  $mailto = implode(' ', $mailto);
  
  $subject = preg_replace('/\\s+/', ' ', $subject);
  if(preg_match('/<(\\S+@\\S+)>/', $from, $m)) $mailfrom = $m[1];
  else $mailfrom = $from;
  
  $message = str_replace("\r", "", $message);
  $message = str_replace("\n", "\r\n", $message);
  $message = wordwrap($message, $SMTPMail['wordwrap'], "\r\n");
  
  $headers = trim($headers);
  if($headers) $headers .= "\r\n";
  $date = date('r');
  $mid = mt_rand(100000, 999999) . ".$Now.$mailfrom";
  
  if(!preg_match('/^From:/m', $headers)) $headers .= "From: $from\r\n";
  $headers .= "To: $to\r\n";
  $headers .= "Subject: $subject\r\n";
  $headers .= "Date: $date\r\n";
  $headers .= "Message-ID: <$mid>\r\n";

  $envelope = trim($headers) . "\r\n\r\n" . ltrim($message, "\r\n");
  
  $temp = tempnam($WorkDir,"mail");
  if ($fp = fopen($temp,"w")) {
    fputs($fp,$envelope);
    fclose($fp);
  }
  else {
//     Abort("Cannot create temp file.");
    return false;
  }
  
  if($userpass) $userpass = '-u '. escapeshellarg($userpass);
  $command = "$curl $server -vv $userpass --mail-from \"$mailfrom\"  $mailto  -T $temp 2>&1 ";
  
  ob_start();
    passthru($command);
  $FmtV['$CurlOutput'] = $ret = ob_get_clean();
  @unlink($temp);
  
  if(preg_match('/We are completely uploaded and fine/i', $ret)) return true;
  return false;
}

