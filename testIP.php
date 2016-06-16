<?php
header('Content-Type: text/plain');
$timestart=microtime(true);
/**
 * @author Stéphane Rochat <contact@stephanerochat.ch>
 * @version 2.0
 * @license http://creativecommons.org/licenses/by-nc/4.0/
 */

/******* Documentations *******
API CloudFlare : https://api.cloudflare.com/
Clickatell : https://www.clickatell.com/apis-scripts/apis/http-s/
*/

// User defined var

/************* CloudFlare **********************/
$CFtoken = 'TOKEN';	 							// This is the API key made available on your Account page.
$CFemail = 'EMAIL';								// The e-mail address associated with the API key
$CFdomain = 'DOMAIN.TLD';						// The target domain

/**************** Mail *************************/
$mailTo = 'EMAIL';								// Receiver of the mail
$mailFrom = 'EMAIL';								// From mail

/************* Clickatell **********************/
$CATusername = 'USERNAME';						// Clickatell username
$CATpwd = 'PASSWORD';							// Clickatell password
$CATapi = 'API';									// Clickatell API key
$CATTo ='PHONENUM';								// Receiver number phone
// End of User defined var

// Get actual IP
$contents = file_get_contents('http://api.ipify.org/?format=json');
$ipifyJson = json_decode($contents, true);
unset($contents);
if(!filter_var($ipifyJson['ip'], FILTER_VALIDATE_IP)) {
	exit('IP not valid !');
}

// Get zone info (we need the ID)
$ch = curl_init('https://api.cloudflare.com/client/v4/zones?name='.$CFdomain);
curl_setopt(
    $ch, 
    CURLOPT_HTTPHEADER,
    array(
        'X-Auth-Email: '.$CFemail,
        'X-Auth-Key: '.$CFtoken,
        'Content-Type: application/json'
    )
);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$CFZones = json_decode(curl_exec($ch), true);
curl_close($ch);

if($CFZones['success'] != 1) {
	echo $CFZones['errors'][0]['error_chain'][0]['message']."\n";
	exit('Error getting zone from CloudFlare');
}

// We have the ID, let's get "A" Records of the domain
$ch = curl_init('https://api.cloudflare.com/client/v4/zones/'.$CFZones['result']['0']['id'].'/dns_records?type=A&?name='.$CFdomain);
curl_setopt(
    $ch, 
    CURLOPT_HTTPHEADER,
    array(
        'X-Auth-Email: '.$CFemail,
        'X-Auth-Key: '.$CFtoken,
        'Content-Type: application/json'
    )
);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$CFDNSRecords = json_decode(curl_exec($ch), true);
curl_close($ch);


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
		$ch = curl_init('https://api.cloudflare.com/client/v4/zones/'.$CFZones['result']['0']['id'].'/dns_records/'.$CFDNSRecords['result'][$i]['id']);
		curl_setopt(
			$ch, 
			CURLOPT_HTTPHEADER,
			array(
				'X-Auth-Email: '.$CFemail,
				'X-Auth-Key: '.$CFtoken,
				'Content-Type: application/json'
			)
		);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
		curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($data));
		$CFUpdateRecord = json_decode(curl_exec($ch), true);
		curl_close($ch);
		
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
		$subject = '[NAS] IP Different - Error with CloudFlare';
		$messageSMS = 'IP+has+change,+new+is+:+'.$ipifyJson['ip'].'+-+Error+with+CloudFlare';
		
		$messageMail = 'Errors :'."\n";
		foreach ($errCloudFlare as &$value)
		{
			$messageMail .= 'Name : '. $value['name'] . ' / Error : '.$value['error']."\n";
		}
	}
	// Gen text for success
	elseif(is_array($ipUpdated))
	{
		$subject = '[NAS] IP Different';
		$messageSMS = 'IP+has+change,+new+is+:+'.$ipifyJson['ip'];
		
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
	$timeToExecute = "Script execute en " . $page_load_time . " sec";
	
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