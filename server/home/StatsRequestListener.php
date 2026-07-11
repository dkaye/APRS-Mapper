#!/usr/bin/env php
<?php
/**
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2025 Doug Kaye. All Rights Reserved.
 */

//defaults
$debugging=false;
$listenerPort="1235";
$destinationPort="1235";
date_default_timezone_set("America/Los_Angeles");

function debug($message) {
        global $debugging;
        if ($debugging) {
                echo("$message\n");
        }
}

function fatal($message) {
        echo("$message\n");
        exit();
}

if ($argc>1) {
        parse_str(implode('&',array_slice($argv, 1)), $_GET);
        foreach ($_GET as $key=>$value) {
                switch ($key) {
                        case "listenerPort":
                                $listenerPort=$value;
                                break;
                        case "destinationPort":
                                $destinationPort=$value;
                                break;
                        case "debug":
                                $debugging=true;
                                break;
                        default:
                                commandLine ("Unknown command line argument: $key\n");
                }
        }
}

function commandLine ($message) {
        echo($message);
        echo("Command line syntax:\n");
        echo("  php StatsRequestListener.php [listenerPort=<ip_listening_port>] [destinationPort=<destination_ip_port>[debug]\n");
        exit();
}

if (!($listenerSocket = socket_create(AF_INET, SOCK_DGRAM, 0))) {
	$errorcode = socket_last_error();
	$errormsg = socket_strerror($errorcode);
	fatal("Couldn't create socket: [$errorcode] $errormsg");
}
debug("Listener socket created");

// Bind the source address
if( !socket_bind($listenerSocket, "0.0.0.0" , $listenerPort) ) {
        $errorcode = socket_last_error();
        $errormsg = socket_strerror($errorcode);
        fatal("Could not bind socket : [$errorcode] $errormsg \n");
}
debug("Bound to listener socket $listenerPort");

if (!($responderSocket = socket_create(AF_INET, SOCK_DGRAM, 0))) {
	$errorcode = socket_last_error();
	$errormsg = socket_strerror($errorcode);
	fatal("Couldn't create socket: [$errorcode] $errormsg");
}
debug("Responder socket created");

while (true) {
        //Receive some data
        $r = socket_recvfrom($listenerSocket, $buf, 100, 0, $remote_ip, $remote_port);
        if ($r===FALSE) fatal("Error receiving from socket");
        debug("Received: $buf");
		$result=array();
		$result_code=exec("hostname",$result);
		$hostname=str_pad($result[0],11);

		$result=array();
		$result_code=exec("uptime",$result);
		$line=$result[0];
		$token=explode(" ",$line);
		if (array_key_exists(13, $token)) {
			$load=str_pad("$token[11]$token[12]$token[13]",14);
		} elseif (array_key_exists(12, $token)) {
			$load=str_pad("$token[11]$token[12]",14);
		}
		else {
			$load=str_pad("$token[11]",14);
        }

		$result=array();
		$result_code=exec("vcgencmd measure_temp",$result);
		$line=$result[0];
		$token=explode("=",$line);
		$temp=$token[1];

		$result=array();
		$result_code=exec("df -h / | awk 'NR==2{print \$4}'",$result);
		$disk=$result[0];

		$result=array();
		$result_code=exec("vcgencmd get_throttled",$result);
		$line=$result[0];
		$token=explode("=",$line);
		$throttled=$token[1];

		$result=array();
		$result_code=exec("nmcli -t -f active,ssid dev wifi | grep yes",$result);
		if ($result_code=="") {
			$ssid="<Ethernet>";
		}
		else {
			$ssid=substr(str_pad($result[0],22),4,26);
		}

		$bits=(int)substr($throttled,2);
		$lowVoltage=($bits & 1) ? "  Low Voltage" : "";
		$highTemp=($bits & 8) ? "  High Temp" : "";
		
		$result=array();
		$result_code=exec("netbird status | grep 'NetBird IP'",$result);
		$ip=substr($result[0],12);
		$ip=substr($ip,0,strlen($ip)-3);
		$ip=str_pad($ip,15);

		if (str_contains($buf,'short')) {
			$line="$hostname Load=$load $temp Throttled=$throttled SSID=$ssid $lowVoltage$highTemp";
		}
		else {
			$line="$hostname  Load=$load  Temp=$temp  Disk=$disk  Throttled=$throttled  $ip  SSID=$ssid  $lowVoltage$highTemp";				
		}
		if ( ! socket_sendto($responderSocket, $line , strlen($line) , 0 , $remote_ip, $destinationPort)) {
			$errorcode = socket_last_error();
			$errormsg = socket_strerror($errorcode);
			fatal("Could not send data: [$errorcode] $errormsg");
		}
		else {
			debug("Sent $line to $remote_ip:$destinationPort");
		}
}
?>
