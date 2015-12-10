<?php
	require_once("../../config.php");
	require_once("$CFG->libdir/filelib.php");
	require_once("$CFG->dirroot/google/lib.php");
    require_once($CFG->dirroot . '/google/gauth.php');
    require_once($CFG->dirroot . '/blocks/morsle/constants.php');
    require_once("$CFG->blocks/morsle/morslelib.php");

    global $CFG, $COURSE;
	global $USER;
	$user = $USER;

	$id      = required_param('id', PARAM_INT);
//	$returnurl = required_param('return', PARAM_URL);
	$action = optional_param('action', null, PARAM_ALPHA);
	$space = optional_param('space', null, PARAM_NUMBER);
	$checkaccounts = optional_param('checkaccounts', null, PARAM_BOOL);

	// get course record
	if (! $course = get_record("course", "id", $id) ) {
		error("That's an invalid course id");
	}

	require_login($id);

	if ( !$CONSUMER_KEY = get_config('blocks/morsle','consumer_key')) {
		exit;
	}

	require_capability('moodle/course:managefiles', get_context_instance(CONTEXT_COURSE, $course->id));

    // determine departmental account name and if exists
    // constants here are defined in blocks/morsle/constants.php
	if (is_numeric(substr($courseaccount,0,5))) {
	    if (defined('DEPTLEADINGSKIP')) {
	    	$shortname = substr($course->shortname, DEPTLEADINGSKIP);
	    }
	    // SPLIT INTO DEPARTMENTAL CODES
	    $dept = explode(DEPTDELIMITER,$shortname);
	    $deptaccount = strtolower(CONSTANT($dept[0]) . '-Departmental-Morsle-Account');
	    $deptemail = strtolower($deptaccount . '@' . $CONSUMER_KEY);
		if (!$checkaccounts) {
	    	$deptfeed = get_doc_feed($deptemail);
		}
    }

	// identify various accounts
    $strcourseuser = get_string("strcourseuser", "block_morsle", $course->shortname);
    $strdepartmentuser = get_string("strdepartmentuser", 'block_morsle', $deptaccount);
    $strownuser = get_string("strownuser", 'block_morsle', $user->email);
    $struploadtargetchoice = get_string('struploadtarget', 'block_morsle');

    // find out if a course morsle account exists for this course
	$courseaccount = strtolower($course->shortname);
    $courseemail = $courseaccount . '@' . $CONSUMER_KEY;
	if (!$checkaccounts) {
    	$coursefeed = get_doc_feed($courseemail);
	}

    // check to see if any file resources exist for course, if not, exit with message
	// establish base dataroot path for course resources
    $currdir = $CFG->dataroot . '/' . $id;

    if (!file_exists($currdir)) {
    	echo '<div class="notifyproblem" align="center">You Have No File Resources to Migrate</div>'."<br />\n";
        return false;
    }


    // get term (or category) of course
    // TODO: see if this query actually works
    $sql = "SELECT path
    			 	 FROM {$CFG->prefix}course_categories cat
    				 WHERE cat.id = $course->category";
    $path = get_field_sql($sql);
    $paths = explode('/', $path);
    $path = is_null($paths[2]) ? $paths[1] : $paths[2];
    $sql = "SELECT cc.name FROM {$CFG->prefix}course_categories cc
    			 WHERE cc.id = $path";
    $term = get_field_sql($sql);
	if ($action === null) {
        html_header($course, 'Migrating Files', 'Morsle File Migration');
        $space = number_format(get_directory_size("$CFG->dataroot/$course->id/") / 1024 / 1024,2);
		echo '<div align="center">';
        echo "<div font-color=\"#FF0000\">$space MB Will Be Migrated to Google Docs<br />Make Sure You Have Enough Space Before You Proceed</div><br />";
	    echo "<form action=\"morsle_migrate.php?id=$id\" method=\"post\" id=\"dirform\">";
	    echo '<input type="hidden" name="checkaccounts" value=1>';
	    echo '<input type="hidden" name="space" value=' . $space . '>';
	    $options = array (
	    		"own" => "$strownuser"
	    );
	    if (isset($deptfeed->id)) {
	    	$options['department'] = $strdepartmentuser;
	    }
	    if (isset($coursefeed->id)) {
	    	$options['course'] = $strcourseuser;
	    }
/*
		$options = array (
	                   "course" => "$strcourseuser",
	                   "department" => "$strdepartmentuser",
	                   "own" => "$strownuser"
	    );*/
	    choose_from_menu ($options, "action", "", "$struploadtargetchoice...", "javascript:getElementById('dirform').submit()");
/*
	    echo '<br />Once you have made your selection <br />you should close this browser tab.  <br />';
		echo 'You may shut down your computer <br />or otherwise continue working.';
		echo 'The migration <br />doesn\'t need your computer in order to continue.<br />';
		echo 'You will receive an email when the migration <br />for this course\'s resources has completed';
*/
	    echo '</form></div>';
	    html_footer();
	} else {
		// establish collection name
		echo '';
		switch ($action) {
			case 'course':
				$useremail = $courseemail;
				// get read-only collectionid for course
				$readcollectionid = get_collection($courseaccount . '-read', $courseemail);
				break;
			case 'department':
				$useremail = $deptemail;
				// get read-only collectionid for course
				$readcollectionid = get_collection($deptaccount . '-read', $deptemail);
				break;
			case 'own':
				$useremail = $USER->email;
				$readcollectionid = null;
				break;
		}
		// check to see if collection exists for course, if not create collection on google user's account named after the shortname + term of course
	    $rootcollectionname = 'Migration from ' . strtolower($course->shortname);
		if (!$collectionid = get_collection($rootcollectionname, $useremail, $readcollectionid)) {
			// create collection
			$collectionid = createcollection($rootcollectionname, $useremail, $readcollectionid);
	    }
/*
	    // recursive function for uploading folder contents (and subfolders) to collection (and subcollections)
		$rel = 'http://schemas.google.com/g/2005#resumable-create-media';
		if ($feed = get_doc_feed($useremail, $collectionid,1)) {
			$links = explode('?',get_href_noentry($feed, $rel));
			$res_med_link = $links[0];
		}
*/
		html_header($course, 'Migrating Files', 'Morsle File Migration');
		echo '<div align="center" font-size="18px" font-color="#ff0000"><image src="' . $CFG->wwwroot
			. '/blocks/morsle/images/spinner.gif" /><br />Files Uploading To Norse Docs<br />This May Take Some Time<br />You Should
			Close This Tab on Your Browser, or <br />you may shut down this computer (files will continue to upload)</div>';
        html_footer();
//		flush();
//		ob_flush();
//		$currenturl = curPageURL();
		$shortname = $course->shortname;
		redirect("$CFG->wwwroot/blocks/morsle/morsle_migrate_execute.php?currcourse=$course->id&collectionid=$collectionid&useremail=$useremail&shortname=$shortname&space=$space");
/*
		send_foldercontents_togoogle($currdir,$collectionid, $useremail);
//		$to_ar = $USER->email;
		$user = 'puffro01@luther.edu';
		$from = 'no-reply@luther.edu';
		$subject = "Migration of course resources for $course->fullname has been completed";
		$messagetext = "This migration has sent $space MB of course resources to the account belonging to $useremail";
		email_to_user($user, $from, $subject, $messagetext);
		echo '<br />You should close this browser tab.  <br />';
		echo 'The migration process is complete.';
*/
/*
		// if we've not wandered from this page then go back to the course page from whence we came
		if (curPageURL() === $currenturl) {
		}
*/
	}

function curPageURL() {
	$pageURL = 'http';
	if ($_SERVER["HTTPS"] == "on") {$pageURL .= "s";}
	$pageURL .= "://";
	if ($_SERVER["SERVER_PORT"] != "80") {
		$pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
	} else {
		$pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
	}
	return $pageURL;
}

/*
function html_footer() {
	global $COURSE, $choose;

	echo '</td></tr></table>';

	print_footer($COURSE);
}

function html_header($course, $wdir, $pagetitle, $formfield=""){
	global $CFG, $ME, $choose;

	$navlinks = array();
	// $navlinks[] = array('name' => $course->shortname, 'link' => "../course/view.php?id=$course->id", 'type' => 'misc');

	$strfiles = get_string('morslefiles', 'block_morsle');

	if ($wdir == "/") {
		$navlinks[] = array('name' => $strfiles, 'link' => null, 'type' => 'misc');
	} else {
		$dirs = explode("/", $wdir);
		$numdirs = count($dirs);
		$link = "";
		$navlinks[] = array('name' => $strfiles,
							'link' => $ME."?id=$course->id&amp;wdir=/&amp;choose=$choose",
							'type' => 'misc');

		for ($i=1; $i<$numdirs-1; $i++) {
			$link .= "/".urlencode($dirs[$i]);
			$navlinks[] = array('name' => $dirs[$i],
								'link' => $ME."?id=$course->id&amp;wdir=$link&amp;choose=$choose",
								'type' => 'misc');
		}
		$navlinks[] = array('name' => $dirs[$numdirs-1], 'link' => null, 'type' => 'misc');
	}
	$navigation = build_navigation($navlinks);

	if ($choose) {
		print_header();

		$chooseparts = explode('.', $choose);
		if (count($chooseparts)==2){
		?>
		<script type="text/javascript">
		//<![CDATA[
		function set_value(txt) {
			opener.document.forms['<?php echo $chooseparts[0]."'].".$chooseparts[1] ?>.value = txt;
			window.close();
		}
		//]]>
		</script>

		<?php
		} elseif (count($chooseparts)==1){
		?>
		<script type="text/javascript">
		//<![CDATA[
		function set_value(txt) {
			opener.document.getElementById('<?php echo $chooseparts[0] ?>').value = txt;
			window.close();
		}
		//]]>
		</script>

		<?php

		}
		$fullnav = '';
		$i = 0;
		foreach ($navlinks as $navlink) {
			// If this is the last link do not link
			if ($i == count($navlinks) - 1) {
				$fullnav .= $navlink['name'];
			} else {
				$fullnav .= '<a href="'.$navlink['link'].'">'.$navlink['name'].'</a>';
			}
			$fullnav .= ' -> ';
			$i++;
		}
		$fullnav = substr($fullnav, 0, -4);
		$fullnav = str_replace('->', '&raquo;', format_string($course->shortname) . " -> " . $fullnav);
		echo '<div id="nav-bar">'.$fullnav.'</div>';

		if ($course->id == SITEID and $wdir != "/backupdata") {
			print_heading(get_string("publicsitefileswarning3"), "center", 2);
		}

	} else {

		if ($course->id == SITEID) {

			if ($wdir == "/backupdata") {
				admin_externalpage_setup('frontpagerestore');
				admin_externalpage_print_header();
			} else {
				admin_externalpage_setup('sitefiles');
				admin_externalpage_print_header();

				print_heading(get_string("publicsitefileswarning3"), "center", 2);

			}

		} else {
			print_header($pagetitle, $course->fullname, $navigation,  $formfield);
		}
	}


	echo "<table border=\"0\" style=\"margin-left:auto;margin-right:auto;min-width:100%\" cellspacing=\"3\" cellpadding=\"3\" >";
	echo "<tr>";
	echo "<td colspan=\"2\">";

}
*/

?>