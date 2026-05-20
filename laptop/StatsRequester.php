#!/usr/bin/env php
<?php
/**
 * ©2025 Doug Kaye. All Rights Reserved.
 */

//defaults
$debugging=false;
$destinationIP=false;
$destinationPort=1235;
$repeatSeconds=60;
$requestMessage="return stats";
$addressesFilename="addresses.cfg";

$delimiters=[" ",",","\t"];

function msg($message) {
        echo("$message\r\n");
}

function debug($message) {
        global $debugging;
        if ($debugging) {
                msg("$message");
        }
}

function fatal($message) {
        msg("$message");
        exit();
}

if ($argc>1) {
        parse_str(implode('&',array_slice($argv, 1)), $_GET);
        foreach ($_GET as $key=>$value) {
                switch ($key) {
                        case "ip":
                                $destinationIP=$value;;
                                break;
                        case "port":
                                $destinationPort=$value;
                                break;
                        case "repeat":
                                $repeatSeconds=$value;
                                break;
						case "addressfile":
		                        $addressesFilename=$value;
		                        break;
				        case "short":
				                $requestMessage.=' short';
				                break;
                        case "debug":
                                $debugging=true;
                                break;
                        default:
                                commandLine ("Unknown command line argument: $key");
                }
        }
}

function commandLine ($message) {
	global $destinationIP,$destinationPort;
        msg($message);
        msg("Command line syntax:");
        msg("  StatsRequester.php [ip=<destination_ip_address>] [port=<destination_port>] [short] [repeat=<seconds>] [debug]");

        msg("Defaults");
		if ($destinationIP) {
			msg("Using file");
		}
		else {
			msg("  ip=$destinationIP");
		}
        msg("  port=$destinationPort");
        exit();
}

function sendRequest($ip,$port,$comment) {
	global $sock,$requestMessage;
	//debug("Requesting stats from $ip:$port");
	if( ! @socket_sendto($sock, $requestMessage, strlen($requestMessage) , 0 , $ip, $port)) {
		$errorcode = socket_last_error();
		$errormsg = socket_strerror($errorcode);
		if ($comment!='') {
			msg("Unable to reach $comment at $ip:$port");
		}
		else {
			msg("Unable to reach $ip:$port");
		}
		//fatal("Could not send data: [$errorcode] $errormsg ");
	}
	else {
		debug("Sent request to $ip:$port");
	}
}

msg("----------");
if ($destinationIP===false) {
	msg("Using ip file: $addressesFilename");
}
else {
	msg("ip=$destinationIP");
}
msg("Using port $destinationPort");
msg("Repeat every $repeatSeconds seconds");
msg("Request message='$requestMessage'");
msg("----------");

if(!($sock = socket_create(AF_INET, SOCK_DGRAM, 0))) {
	$errorcode = socket_last_error();
	$errormsg = socket_strerror($errorcode);
	fatal("Couldn't create socket: [$errorcode] $errormsg ");
}

date_default_timezone_set("America/Los_Angeles");

while (true) {
	if ($destinationIP===false) {
		# no single ip address specified -- read them from a file
		try {
			$lines=file($addressesFilename,FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES);
		} catch (Exception $ex) {
		        fatal ("Can't read $addressesFilename");
		}
		debug('');
		foreach ($lines as $line) {
			$line=trim($line);
			if (($line != '') && ($line[0] != '#')) {	# skip blank lines and comment
				#anything after $delimiters is a comment
				$line=str_replace($delimiters,',',$line);
				$parts=explode(",",$line);
				$ip=$parts[0];
				$comment=substr($line,strlen($ip)+1);
				sendRequest($parts[0],$destinationPort,$comment);
			}
		}
	}
	else {
		sendRequest($destinationIP,$destinationPort);
	}
	msg('');
	sleep($repeatSeconds);
}
?>