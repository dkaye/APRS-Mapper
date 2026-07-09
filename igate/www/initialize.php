<?php
/**
 * initialize.php — K6DRK iGate v5.0 bootstrap and configuration loader
 *
 * Loaded first by every iGate dashboard page. Reads config.php, sets up
 * global variables, initializes the database connection, and defines
 * shared constants used throughout the dashboard.
 * Based on the Direwolf APRS Web Dashboard (PC7MM / PD3RFR / IZ7BOJ).
 */
// This file is part of the Direwolf APRS Web Dashboard as available at https://github.com/PC7MM/Direwolf-APRS-Web-Dashboard
// Developed by Michael PC7MM and Richard PD3RFR as an extension of https://github.com/IZ7BOJ/direwolf_webstat and https://github.com/IZ7BOJ/APRS_dashboard as developed by Alfredo IZ7BOJ
// See config.php for adjustable parameters and see https://www.youtube.com/watch?v=7bMf7rWCfnE for more information
// Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
// ©2025 Doug Kaye, K6DRK <doug@rds.com>

session_start();
include 'config.php';
// Build $stationsquery and $sysops from igate-stations.json (written by auto-update.sh)
$stationsquery = '';
$sysops = '';
$_igateFile = __DIR__ . '/igate-stations.json';
if (file_exists($_igateFile)) {
    $_igates = json_decode(file_get_contents($_igateFile), true) ?? [];
    $stationsquery = implode(',', array_column($_igates, 'host'));
    $sysops        = implode(',', array_column($_igates, 'name'));
}
unset($_igateFile, $_igates);
include 'functions.php';

if (str_contains($_SERVER['PHP_SELF'],"traffic.php")) $_SESSION['daysback']=0; // go to logfile of today if traffic monitor is to be loaded

if (!isset($_SESSION['daysback'])) $_SESSION['daysback']=0;

if (!isset($_SESSION['showedbufferwarning'])) $_SESSION['showedbufferwarning']=0;

if(!isset($_SESSION['if'])) {
	if ($static_if==1) {
 		$_SESSION['if']=$static_if_index;
	} else {
		$_SESSION['if']=0;
		header('Refresh: 0; url=chgif.php'); // show change interface page if static interface is disabled
		die();
	}
}
$if = $_SESSION['if'];

// substr and strip_tags are used to get rid of possible malicious input

if (isset($_GET['time']) and ($_GET['time'] !== "")) $_SESSION['timevalue'] = strip_tags(substr($_GET['time'],0,2));

if (isset($_GET['daysback']) and ($_GET['daysback'] !== "")) $_SESSION['daysback'] = strip_tags(substr($_GET['daysback'],0,2)); // else if (!isset($_GET['ajax'])) $_SESSION['daysback']=0;

if (isset($_GET['getcall'])) $_SESSION['callsign'] = strip_tags(substr($_GET['getcall'],0,9));

if ($fixedlogname!="") {
		$newlogname = $fixedlogname." (fixed)";
                $log=$logpath.$fixedlogname;
        } else {
                $daysback = date("d") - $_SESSION['daysback'];
                $newlogname=date("Y-m-d",mktime(0,0,0,NULL,NULL+$daysback,NULL)).'.log';
                $log=$logpath.$newlogname;
	}

if (is_file($log)) $logfile = file($log); else $logfile = [];

foreach($ajaxupdatehtml as $file) { if (str_contains($_SERVER['SCRIPT_NAME'],$file)) { $ajaxupdatetype="html"; } } // for replacing dynamic content on page

foreach($ajaxupdateappend as $file) { if (str_contains($_SERVER['SCRIPT_NAME'],$file)) { $ajaxupdatetype="append"; } } // for appending dynamic content on page

if (!isset($_GET['ajax']) and !isset($_GET['if'])) {
	include 'header.php';
	include 'ajaxupdate.php';
}
?>
