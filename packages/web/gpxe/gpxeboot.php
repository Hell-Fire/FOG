<?php


/*

gPXE can either be directed to this file direct, or to gpxeboot.php/gpxe.cfg/${mac} (slightly faster)

*/

header("Cache-Control: no-cache");
header("Content-Type: text/plain");

require_once("../commons/config.php");
require_once("../commons/functions.include.php");

$server = "http://" . $_SERVER['SERVER_ADDR'] . "/fog/gpxe/gpxeboot.php";
$args = substr($_SERVER['REQUEST_URI'], strlen($_SERVER['SCRIPT_NAME'])+1); // Want everything but the script name and the trailing slash

function gpxeDie($msg = "") {
	// Try to output a message for gPXE to fall back to local boot
	$out = "#!gpxe\n";
	if ($msg) {
		$out .= "echo gPXE FOG Error: " . $msg . "\n";
	}
	$out .= "exit\n";
	echo $out;
	exit();
}

function fileNotFound() {
	header('HTTP/1.1 404 Not Found');
	exit();
}

function sendFile($filename, $cache=false) {
	if (file_exists($filename)) {
		if ($cache) {
			header('Cache-Control: '); // Allow files to be cached
		}
		header('Content-Type: application/x-octet-stream'); // We don't know what kind of file it is, just send it
		header('Content-Disposition: filename="' . basename($filename) . '";');
		if (true) {
			// TODO: Add some kind of check for X-Sendfile?
			// Note, XSendFile in default config only allows file paths under the script path
			// XSendFile is a lot more efficient than readfile (has implementation under LigHTTPD too)
			header('X-SENDFILE: ' . $filename);
		} else {
			readfile($filename);
		}
		exit();
	} else {
		fileNotFound();
	}
}



// MySQL Connection
$conn = mysql_connect(MYSQL_HOST, MYSQL_USERNAME, MYSQL_PASSWORD);
if ($conn) {
	if (!mysql_select_db(MYSQL_DATABASE, $conn)) gpxeDie(_("Couldn't select FOG database"));
} else {
	gpxeDie(_("Couldn't connect to FOG database"));
}



// gPXE bootstrap
if (!$args) {
	/* First hop, tell gpxe to load up instructions
	// This code gets skipped if the filename in DHCP
	// is set to the same, but if users have problems
	// with special characters in their DHCP config
	// this is a nice fallback :)
	*/ 
	echo <<< GPXEOUT
#!gpxe
chain $server/gpxe.cfg/\${mac}
GPXEOUT;
	exit();
}



// Task output
$args = explode('/', $args);
if ($args[0] == "gpxe.cfg") {
	// our callback
	$mac = mysql_real_escape_string(urldecode($args[1]));
	if (!isValidMACAddress($mac) || !getCountOfActiveTasksWithMAC($conn, $mac)) {
		// TODO: Add check to see if we should even bother with a menu, or just exit
		//       adds flexibility to cut down boot times
		/*
		echo <<< GPXEOUT
#!gpxe
exit
GPXEOUT;
		exit();
		*/
		echo <<< GPXEOUT
#!gpxe
set 210 $server/
# gPXE doesn't understand localboot, chain PXELinux to do the menu instead
#chain $server/vesamenu.c32 $server/default
chain $server/pxelinux.0
GPXEOUT;
		exit();
	}

	$sql = "SELECT taskType, taskParams, hostOS, imageID, imagePath, imageDD, ngmHostname, ngmRootPath

		FROM nfsGroupMembers RIGHT OUTER JOIN 
			(tasks INNER JOIN 
				(hosts LEFT OUTER JOIN 
					(images) 
				ON (hosts.hostImage = images.imageID))
			ON (tasks.taskHostID = hosts.hostID))
		ON (tasks.taskNFSMemberID = nfsGroupMembers.ngmID)

		WHERE hosts.hostMAC = '$mac' AND tasks.taskState IN (0,1)";

	$result = mysql_query($sql, $conn);
	if (!$result) gpxeDie(_("We had an active task but don't know what it is?"));
	$result = mysql_fetch_assoc($result);

	$taskType = $result['taskType'];
	$taskParams = $result['taskParams'];
	$hostOS = $result['hostOS'];
	$imageID = $result['imageID'];
	$imagePath = $result['imagePath'];
	$imageDD = $result['imageDD'];
	$storage = $result['ngmHostname'] . ':' . $result['ngmRootPath'];
	
	if ($imageDD == 0) { // IMAGE_TYPE_SINGLE_PARTITION_NTFS
		$imageDD = "imgType=n";
	} else if ($imageDD == 2) { // IMAGE_TYPE_MULTIPARTITION_SINGLE_DISK
		$imageDD = "imgType=mps";
	} else if ($imageDD == 3) { // IMAGE_TYPE_MULTIPARTITION_MULTIDISK
		$imageDD = "imgType=mpa";
	} else {
		$imageDD = ""; // image type falls back to IMAGE_TYPE_DD
	}

	$kernel = "fog/kernel/bzImage";
	$initrd = "fog/images/init.gz";
	$args = "root=/dev/ram0 rw ramdisk_size=127000 ip=dhcp dns= mac=$mac web=${_SERVER['SERVER_ADDR']}/fog/ consoleblank=0 loglevel=4 osid=$hostOS $taskParams ";

	if ($taskType == 'M') { // Memtest
		// TODO: replace memtest zImage with ELF
		// TODO: add SQL query to mark the memtest task as completed
		$kernel = "fog/memtest/memtest";
		$initrd = "";
		$args = "";

	} else if ($taskType == 'W') { // Wipe
		$args .= "mode=wipe";

	} else if ($taskType == 'J') { // Reset password
		$args .= "mode=winpassreset winuser=Administrator"; // TODO: set winuser properly

	} else if ($taskType == 'T') { // Surface/Test disk
		$args .= ""; // Don't add anything, mode=badblocks or mode=checkdisk is done in taskParams

	} else if ($taskType == 'I') { // Inventory
		$args .= "mode=autoreg mac_deployed=$mac deployed=1"; // why the mac= change here? :/

	} else if ($taskType == 'D') { // Deploy
		$args .= "type=down img=$imagePath storage=$storage $imageDD";

	} else if ($taskType == 'U') { // Upload
		$args .= "type=up img=$imagePath imgid=$imageID storage=$storage/dev $imageDD";
	} else if ($taskType == 'X') { // Debug
		$args .= "mode=onlydebug";
	}
	// TODO: add support for antivirus

	$out = "#!gpxe\n";
	if ($kernel) {
		$out .= "kernel $server/$kernel $args\n";
	}
	if ($initrd) {
		$out .= "initrd $server/$initrd\n";
	}
	$out .= "boot\n";
	echo $out;
	exit();



// Little bit of code to support PXELinux
} else if ($args[0] == "pxelinux.cfg") {
	// pxelinux is calling
	sendFile('default', false); // Doesn't matter what machine, this is only used for menu anyway




// File sending
} else if ($args[0] != "gpxeboot.php") { // Refuse to send ourselves :P
	// See if we have the file in the gpxe store
	sendFile(implode('/', $args));
}



fileNotFound(); // Couldn't handle it, not going to.

