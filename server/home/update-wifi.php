#!/usr/bin/env php
<?php
/**
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * ©2025 Doug Kaye. All Rights Reserved.
 */

//defaults
$debugging=false;
$ssidFilename="wifi.conf";
$connectionsToKeep=['preconfigured','lo','wt0','wlan0','eth0'];	//don't delete these connections

function debug($message) {
	global $debugging;
	if ($debugging) {
		echo("$message\n");
	}
}

function fatal($message) {
	echo("FATAL: $message\n");
	exit();
}

if($argc>1) {
	parse_str(implode('&',array_slice($argv, 1)), $_GET);
	foreach ($_GET as $key=>$value) {
		switch ($key) {
			case "ssids":
				$ssidFilename=$value;
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
	global $ssidFilename;
	echo($message);
	echo("Command line syntax:\n");
	echo("  php update-wifi.php [ssids=<ssid definition file>] [debug]\n");
	echo("Defaults\n");
	echo("  ssids=$ssidFilename\n");
	echo("ssid file syntax:\n");
	echo('  <name>,<ssid>,<password>' . PHP_EOL);	//e.g., "Franklin Hotspot 01,Franklin T9a FB10,guacamole"
	exit();
}

function deleteConnection($name) {
	echo("Deleting connection: $name\n");
	$cmd="sudo nmcli connection delete \"$name\"";
	$result=exec($cmd);
}

function setPriority($name,$priority) {
	$cmd="sudo nmcli connection modify \"$name\" connection.autoconnect-priority $priority";
	$result=exec($cmd);
}

function addConnection($line,$priority) {
	echo("Adding $line, priority=$priority\n");
	$element=explode(",",$line);
	$name=$element[0];
	$ssid=$element[1];
	$password=$element[2];
	$cmd="sudo nmcli connection add type wifi con-name \"$name\" ifname wlan0 ssid \"$ssid\" -- wifi-sec.key-mgmt wpa-psk wifi-sec.psk \"$password\"";
	$result=exec($cmd);
	setPriority($name,$priority);
}

function deleteOldConnections() {
	global $connectionsToKeep;
	$lastLine=exec("sudo nmcli --terse connection show",$connections);
	foreach ($connections as $connection) {
		$element=explode(':',$connection);
		$name=$element[0];
		$device=$element[3];
		if (!in_array($name,$connectionsToKeep) && !in_array($device,$connectionsToKeep)) {
			deleteConnection($name);	//delete all non-default connections
		}
	}
}

#====================================================

echo "----------\n";
echo "ssids=$ssidFilename\n";
if (!file_exists($ssidFilename)) {
	fatal("SSID FILE DOESN'T EXIST!!");
}

# get the name of the current wifi connection
$result=exec("nmcli -f NAME,DEVICE c s --active | grep wlan0");
$deviceInUse=trim(str_replace('wlan0','',$result) );
echo ("Current connection: $deviceInUse\n");
echo "----------\n\n";

deleteOldConnections();
$lines=file($ssidFilename);
$priority=999;

foreach ($lines as $line) {
	$line=trim($line);
	if (strlen($line)==0) {continue;}			//skip blank lines
	if ($line[0]=='#') {continue;}				//skip comment lines
	$element=explode(",",$line);
	$name=trim($element[0]);
	if ($name==$deviceInUse) {
		echo ("\nFound $name. Setting priority to $priority\n");
		setPriority($name,$priority);
	}
	else {
		addConnection($line,$priority);			//add connection, starting with highest priority
	}
	$priority--;
}
?>