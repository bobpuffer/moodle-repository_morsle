<?php
	global $USER;
	require_once("../../config.php");
	require_once("$CFG->dirroot/google/lib.php");
	$currcourse = required_param('currcourse');
	$shortname = required_param('shortname');
	$space = required_param('space');
	$collectionid = required_param('collectionid');
	$useremail = required_param('useremail');
	$currdir = "$CFG->dataroot/$currcourse";
	send_foldercontents_togoogle($currdir,$collectionid, $useremail);
	$user = $USER->email;
//	$user = 'puffro01@luther.edu';
	$from = 'noreply@luther.edu';
	$subject = "Migration of course resources for $course->fullname has been completed";
	$messagetext = "This migration has sent $space MB of course resources to the account belonging to $useremail";
	email_to_user($user, $from, $subject, $messagetext);
	echo '<br />You should close this browser tab.  <br />';
	echo 'The migration process is complete.';

?>