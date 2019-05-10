<?php
header('Content-Type: text/plain');
$timestart=microtime(true);
/**
 * @author Stéphane Rochat <contact@stephanerochat.ch>
 * @version 2.1
 * @license http://creativecommons.org/licenses/by-nc/4.0/
 */

/******* Documentations *******
API CloudFlare : https://api.cloudflare.com/
Clickatell : https://www.clickatell.com/apis-scripts/apis/http-s/
*/

// User defined var

/************* CloudFlare **********************/
// $CFtoken = 'TOKEN';	 							// This is the API key made available on your Account page.
// $CFemail = 'EMAIL';								// The e-mail address associated with the API key
// $CFdomain = 'DOMAIN.TLD';						// The target domain

/**************** Mail *************************/
// $mailTo = 'EMAIL';								// Receiver of the mail
// $mailFrom = 'EMAIL';								// From mail

/************* Clickatell **********************/
// $CATusername = 'USERNAME';						// Clickatell username
// $CATpwd = 'PASSWORD';							// Clickatell password
// $CATapi = 'API';									// Clickatell API key
// $CATTo ='PHONENUM';								// Receiver number phone
// End of User defined var

// Global var
$userAgent = 'curl';
$curl_headers = array(
	'X-Auth-Email: '.$CFemail,
	'X-Auth-Key: '.$CFtoken,
	'Content-Type: application/json');
// End of Global var

// Get actual IP
$contents = file_get_contents('http://api.ipify.org/?format=json');
$ipifyJson = json_decode($contents, true);
unset($contents);
if(!filter_var($ipifyJson['ip'], FILTER_VALIDATE_IP)) {
	exit('IP not valid !');
}

// Get zone info (we need the ID)
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, 'https://api.cloudflare.com/client/v4/zones?name='.$CFdomain);
curl_setopt($curl, CURLOPT_COOKIESESSION, true);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_HTTPHEADER, $curl_headers);
curl_setopt($curl, CURLOPT_USERAGENT, 'curl');
$CFZones = json_decode(curl_exec($curl), true);
curl_close($curl);

if($CFZones['success'] != 1) {
	echo $CFZones['errors'][0]['error_chain'][0]['message']."\n";
	exit('Error getting zone from CloudFlare');
}

// We have the ID, let's get "A" Records of the domain
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, 'https://api.cloudflare.com/client/v4/zones/'.$CFZones['result']['0']['id'].'/dns_records?type=A&?name='.$CFdomain);
curl_setopt($curl, CURLOPT_COOKIESESSION, true);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_HTTPHEADER, $curl_headers);
curl_setopt($curl, CURLOPT_USERAGENT, 'curl');
$CFDNSRecords = json_decode(curl_exec($curl), true);
curl_close($curl);

$qtyRecords = $CFDNSRecords['result_info']['total_count'];
$errCloudFlare = FALSE;
$ipUpdated = FALSE;
for ($i = 0; $i < $qtyRecords; $i++) {
	if($CFDNSRecords['result'][$i]['content'] <> $ipifyJson['ip'])
	{
		// Recreate values
		$data = array(
			'type' => $CFDNSRecords['result'][$i]['type'],
			'name' => $CFDNSRecords['result'][$i]['name'],
			'content' => $ipifyJson['ip']
		);

		// Update it
		
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, 'https://api.cloudflare.com/client/v4/zones/'.$CFZones['result']['0']['id'].'/dns_records/'.$CFDNSRecords['result'][$i]['id']);
		curl_setopt($curl, CURLOPT_COOKIESESSION, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $curl_headers);
		curl_setopt($curl, CURLOPT_USERAGENT, 'curl');
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
		curl_setopt($curl, CURLOPT_POSTFIELDS,json_encode($data));
		$CFUpdateRecord = json_decode(curl_exec($curl), true);
		curl_close($curl);
		
		// Error handling
		if($CFUpdateRecord['success'] == '1')
		{
			$ipUpdated[] = $CFDNSRecords['result'][$i]['name'];
		}
		else
		{
			$errCloudFlare[] = array('name' => $CFDNSRecords['result'][$i]['name'], 'error' => $CFUpdateRecord['errors'][0]['error_chain'][0]['message']);
		}
	}
}

// Errors or IP updated ? Send a mail & SMS
if($errCloudFlare || $ipUpdated)
{
	// Gen text for errors
	if(is_array($errCloudFlare))
	{
		$subject = 'IP Different - Error with CloudFlare';
		$messageSMS = 'IP+changed,+new+is+:+'.$ipifyJson['ip'].'+-+Error+with+CloudFlare';
		
		$messageMail = 'Errors :'."\n";
		foreach ($errCloudFlare as &$value)
		{
			$messageMail .= 'Name : '. $value['name'] . ' / Error : '.$value['error']."\n";
		}
	}
	// Gen text for success
	elseif(is_array($ipUpdated))
	{
		$subject = 'IP Different';
		$messageSMS = 'IP+changed,+new+is+:+'.$ipifyJson['ip'];
		
		$messageMail = 'Updated with new IP ('.$ipifyJson['ip'].') :'."\n";
		foreach ($ipUpdated as &$value)
		{
			$messageMail .= $value."\n";
		}
	}
	
	// Send Mail
	$headers = 'From: '.$mailFrom."\n";
	$headers .= 'Content-type: text/plain'."\n";
	$headers .= "X-Priority: 1 (Highest)\n";
	$headers .= "X-MSMail-Priority: High\n";
	$headers .= "Importance: High\n";
	
	$page_load_time = microtime(true)-$timestart;
	$timeToExecute = "Script executed in " . $page_load_time . " sec";
	
	$mailer = mail($mailTo, utf8_decode(stripslashes($subject)), utf8_decode(stripslashes($messageMail.$timeToExecute)), $headers);
	
	// Send SMS
	file_get_contents('http://api.clickatell.com/http/sendmsg?user='.$CATusername.'&password='.$CATpwd.'&api_id='.$CATapi.'&to='.$CATTo.'&text='.$messageSMS);
	
	exit('Executed, see mail or SMS for details');
}
else
{
	exit('No Change');
}
?>
