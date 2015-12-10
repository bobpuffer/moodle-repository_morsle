<?php
/*
 * Processes all current morsle_active records, creating or deleting components as necessary
 * Any course that is current (mdl_course.enrolenddate > now and is visible gets resources created (if not already done)
 * Any course whose mdl_course.enrolenddate + config.morsle_expiration (converted to seconds) < now gets its resources deleted
 * Any course falling inbetween is ignored (we don't update courses beyond their enrolenddate)
 */
	//TODO: convert to objective php

	require_once('../../config.php');
    require_once($CFG->dirroot.'/google/lib.php');
    require_once($CFG->dirroot.'/google/gauth.php');
    require_once($CFG->dirroot.'/blocks/morsle/morslelib.php');
    global $DB;
    if ( !$CONSUMER_KEY = get_config('blocks/morsle','consumer_key')) {
        exit;
    }
    define("COURSEID",1196);
//    define("SHORTNAME",'NEW-HPE-PORTFOLIO');
//    define("OWNER",'wrightja@luther.edu');
//    define("OWNER",'sullivre@luther.edu');

	// establish authorization for gapps data
	$auth = clientauth();
	$morsle = new morsle();
	$morsle->get_aliases();

	// setup other variables used by libraries
	$morsle->shortname = 'HPE-PORTFOLIO';
	$morsle->courseid = COURSEID;
    $morsle->portfoliobase = 'HPE Portfolio Site for ';
    $morsle->user= 'wrightja@luther.edu';
    $morsle->params = array('xoauth_requestor_id' => $morsle->user, 'max-results' => 500);
    $morsle->groupname = $morsle->shortname . '-group';
    $morsle->groupfullname= $morsle->groupname . '@' . $morsle->domain;
    $morsle->visible = 1;
    $record = $DB->get_record('morsle_active', array('courseid'=>COURSEID));
    $morsle->sitename = substr($record->siteid,strpos($record->siteid,$morsle->domain),100);

	// get all information for all members of the course
    $rosters = $morsle->get_full_roster($morsle->courseid, 1); // returns an object of info about the user

    // now we need to substitute real email for alias because folders use real (groups use alias)
	foreach ($rosters as $key=>$value) {
		if (isset($aliases[$key])) {
			$rosters[$aliases[$key]] = $value;
			unset($rosters[$key]);
		}
	}

    // determine which members are editingteachers
    $teachers = array_filter($rosters,"full_is_owner");

	// get the list of everyone who's not an owner
	$students = array_diff_key($rosters,$teachers);

    // maintain owners for group
    foreach ($teachers as $teacher) {
    	$morsle->owners[$teacher->email] = $teacher->role;
    }

	// get all the HPE portfolio sites available
	$hpe_sites = $morsle->getportfoliosites();
//	$target = array(9049);
	// cycle through students presently in course
	foreach ($students as $key=>$student) {
		// check to see if the site already exists for this student
		$student->studentname = $student->firstname . ' ' . $student->lastname;
		$morsle->portfolioname = str_replace('.','',$morsle->portfoliobase . $student->studentname);

		if (!in_array($student->studentname, $hpe_sites)) {
			// if doesn't exist create a site for this student
			if (!$siteid = $morsle->createportfoliosite($morsle->portfolioname)) {
				echo ' big booboo!';
			}
		}

		// add permissions to the site
		$morsle->portfolioname = str_replace(' ', '-', strtolower($morsle->portfolioname));
//			$writeassigned = portfoliositepermissions($sitename, $studentname, $student->email, array('puffro01@luther.edu'=>'editor'), OWNER, $CONSUMER_KEY);
		$writeassigned = $morsle->set_portfoliositepermissions($student);
	}
?>