#!/usr/bin/env php
<?php
/**
 * ©2025 Doug Kaye. All Rights Reserved.
 */

//defaults
$debugging=false;
$udpSocket=1235;
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
			case "socket":
				$udpSocket=$value;
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
	global $meshtasticStreamFilename, $udpSocket;
	echo($message);
	echo("Command line syntax:\n");
	echo("  StatsFromSocket.php [socket=<udp_socket>] [debug]\n");
	echo("Defaults\n");
	echo("  socket=$udpSocket\n");
	exit();
}

//Create a UDP socket
if(!($sock = socket_create(AF_INET, SOCK_DGRAM, 0))) {
	$errorcode = socket_last_error();
	$errormsg = socket_strerror($errorcode);
	fatal("Couldn't create socket: [$errorcode] $errormsg \n");
}
debug("Socket created");

// Bind the source address
if( !socket_bind($sock, "0.0.0.0" , $udpSocket) ) {
	$errorcode = socket_last_error();
	$errormsg = socket_strerror($errorcode);
	fatal("Could not bind socket : [$errorcode] $errormsg \n");
}
debug("Bound to socket $udpSocket");

while(TRUE) {
	//Receive some data
	$r = socket_recvfrom($sock, $buf, 512, 0, $remote_ip, $remote_port);
	if ($r===FALSE) fatal("Error receiving from socket");
//	$now=date("h:i:sa");
//	$remote_ip=str_pad($remote_ip,15);
	echo("$buf\n");
}
?>
