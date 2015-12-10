<?php

  /* This is the global search shortcut block - a single query can be entered, and
  * the user will be redirected to the query page where they can enter more
  *  advanced queries, and view the results of their search. When searching from
  *  this block, the broadest possible selection of documents is searched.
  *
  *
  *  Todo: make strings -> get_string()
  *
  * @package search
  * @subpackage search block
  * @author: Michael Champanis (mchampan), reengineered by Valery Fremaux
  * @date: 2006 06 25
  */

  class block_morsle extends block_base {

    function init() {
      $this->title = get_string('pluginname', 'block_morsle');
      $this->cron = 0;
    } //init

    // only one instance of this block is required
    function instance_allow_multiple() {
      return false;
    } //instance_allow_multiple

    // label and button values can be set in admin
    function has_config() {
      return false;
    } //has_config


    function get_content() {
	global $CFG, $COURSE, $USER, $DB, $OUTPUT;
	if ( !$CONSUMER_KEY = get_config('morsle','consumer_key')) {
        $this->content = null;
     	return $this->content;
    }
    $MORSLE_EXPIRES = is_null(get_config('morsle','morsle_expiration')) ? 30 * 24 * 60 * 60: get_config('morsle','morsle_expiration') * 24 * 60 * 60;
  	$curtime = time();
   	$this->content = new stdClass;
   	$morslerec = new stdClass();
   	$mhelp = get_string('morsle_help_string', 'block_morsle');

    if ($COURSE->startdate + $MORSLE_EXPIRES < $curtime || $COURSE->startdate == 0) {
        $this->content = null;
		return $this->content;
    }
    $context = get_context_instance(CONTEXT_COURSE, $COURSE->id);

    // create morslerec if needed EVEN IF course is invisible to students
	// only if user has editingteacher role and not admin
	// admin entering unused course will not create the morsle record
	$conditions = array('courseid' => $COURSE->id);
	if (!$morslerec = $DB->get_record('morsle_active',$conditions)) {
		if(has_capability('moodle/course:update', $context) && !is_siteadmin($USER)) {
			$newrec->created = time();
			$newrec->status = 'Full';
			$newrec->courseid = $COURSE->id;
			$newrec->shortname = strtolower($COURSE->shortname);
			// gotta have some sort of course enrolenddate to know when to remove course -- set 120 days

			/* am commenting out this part cuz I really want to be able to disable the morsle repo and a 0 course startdate would do that
			if ($COURSE->enrolenddate == 0) {
				$COURSE->enrolenddate = time() + (120 * 24 * 60 * 60);
				$success = update_record('course','id',$COURSE->id);
			}
			*/

			if ($morslerec = $DB->insert_record('morsle_active', $newrec)) {
				add_to_log($COURSE->id, 'morsle', "morsle FULL record created for $COURSE->shortname");
			} else {
				add_to_log($COURSE->id, 'morsle', "morsle FULL record NOT CREATED for $COURSE->shortname");
			}
		} else {
			$morslerec = new stdClass();
		}
	}
	$username = $COURSE->shortname . '@' . $CONSUMER_KEY;
	$urlshortname = str_replace('@', '%40', strtolower($username));
	$returnurl = curPageURL2();
	// if a password has been created that means either resources have been requested or an event is active
	// that causes the causes the calendar to be active
	if (!isset($morslerec)) {
		$coursecalendar = '&nbsp&nbspNo Calendar Information Available<br />';
	} elseif(!isset($morslerec->password)){
		$coursecalendar = '&nbsp&nbspMorsle Calendar Not Yet Available<br />';
	} else {
		$coursecalendar = '<object data="https://www.google.com/calendar/b/0/embed?showTitle=0&amp;showNav=0&amp;showDate=0&amp;showPrint=0&amp;showTabs=0&amp;showCalendars=0&amp;showTz=0&amp;mode=AGENDA&amp;height=120&amp;wkst=1&amp;bgcolor=%23ffffff&amp;src='
			. $urlshortname . '&amp;color=%23856508&amp;ctz=America%2FChicago" type="text/html" id="embeddedhtml" style=" border-width:0 " width="195" height="120"></object>';
	}
	$this->content->text .= '<table class="morslefull">';

	// morsle logo
	$this->content->text .= '<tr><td class="morslefiles morsletop" colspan = "2"><img class="logo" src="' . $CFG->wwwroot . '/blocks/morsle/images/morslelogobackground.png" alt=\"Norse Docs for Moodle" />';
	$this->content->text .= '</td></tr>';

	// google calendar for course
	$this->content->text .= '<tr><td colspan = "2" class="calendar">' . $coursecalendar;
	$this->content->text .= '</td></tr>';

	// folders
	if (!isset($morslerec->password)) {
		$this->content->text .= '<tr><td colspan = "2">';
		//		$this->content->text .= $OUTPUT->help_icon('morsle', $this->title, 'block_morsle', true);
		$this->content->text .= 'Norse Apps resources for this course not yet available</td></tr></table>';
	// TODO: should be checking all values to make sure all resources are available
	} else {

		$this->content->text .= '<tr><td class="morslefiles"><a href="' . $CFG->wwwroot . '/blocks/morsle/morslefiles.php?courseid=' . $COURSE->id .
				'&wdir=/">
				<img src="' . $CFG->wwwroot . '/blocks/morsle/images/morslefiles.png" /></a></td>';

		$this->content->text .=  '<td><a target="_blank" href="mailto:' . $morslerec->shortname . '-group@' . $CONSUMER_KEY .'">
				<img src="' . $CFG->wwwroot . '/blocks/morsle/images/mailAllCourseMembersCell.png" /></a></td></tr>';

/*
		$this->content->text .=  '<td><a href="' . $CFG->wwwroot . '/blocks/morsle/morslefiles.php?courseid=' . $COURSE->id .
				'&wdir=/">
				<img src="' . $CFG->wwwroot . '/blocks/morsle/images/studentWriteableFolderCell.png" /></a></td></tr>';
*/

		$this->content->text .= '<tr><td class="morslefiles"><a href="' . $CFG->wwwroot . '/blocks/morsle/lang/help/morsle/morsle.html" target="_blank">
				<img src="' . $CFG->wwwroot . '/blocks/morsle/images/helpWithMorsleCell.png" /></a></td>';

		$this->content->text .=  '<td class="morslebottom"><a href="' . $morslerec->siteid . '">
				<img src="' . $CFG->wwwroot . '/blocks/morsle/images/morsleSitesCell.png" /></a></td></tr>';
//		$this->content->text .=  '<td></td></tr>';

		$this->content->text .= '</table>';
	}
	$this->content->footer = '';

	return $this->content;
	} //get_content

    function specialisation() {
      //empty!
    } //specialisation

	// cron is run from repository library
} //block_morsle


function curPageURL2() {
	$pageURL = 'http';
	if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == "on") {
		$pageURL .= "s";
	}
	$pageURL .= "://";
	if ($_SERVER["SERVER_PORT"] != "80") {
		$pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
	} else {
		$pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
	}
	return $pageURL;
}
?>