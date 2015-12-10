<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Morsle Plugin
 *
 * @since 2.2
 * @package    repository
 * @subpackage googledocs
 * @copyright  since 2011 Bob Puffer puffro01@luther.edu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->libdir.'/googleapi.php');
require_once("$CFG->dirroot/repository/morsle/locallib.php");
require_once("$CFG->dirroot/repository/lib.php");
require_once("$CFG->dirroot/google/gauth.php");
require_once("$CFG->dirroot/google/lib.php");
require_once "$CFG->dirroot/google/google-api-php-client/src/Google/Service.php";
require_once "$CFG->dirroot/google/google-api-php-client/src/Google/Service/Resource.php";
require_once "$CFG->dirroot/google/google-api-php-client/src/Google/Service/Calendar.php";
require_once "$CFG->dirroot/google/google-api-php-client/src/Google/Service/Directory.php";
require_once "$CFG->dirroot/google/google-api-php-client/src/Google/Service/Drive.php";
require_once "$CFG->dirroot/google/google-api-php-client/src/Google/Client.php";


class repository_morsle extends repository {
    private $subauthtoken = '';

    public function __construct($repositoryid = 9, 
            $service_name = 'calendar', 
            $useremail = 'admin-moogle@luther.edu', 
            $shortname = '',
            $context = SYSCONTEXTID, $options = array()) {
        global $USER, $COURSE, $DB;
        $options = array('id' => 9, 
            'sortorder' => 1, 
            'type' => 'morsle', 
            'typeid' => 9, 
            'visible' => 1);
        if ( !$this->domain = get_config('morsle','consumer_key')) {
        	throw new moodle_exception('Consumer key not set up');
        }
        parent::__construct($repositoryid, $context, $options);
        // days past last enrollment day that morsle resources are retained
        $this->expires = get_config('morsle','morsle_expiration') == 0 ? 600 * 24 * 60 * 60: get_config('blocks/morsle','morsle_expiration') * 24 * 60 * 60;

        $this->curtime = time();
        $this->shortname = strtolower($shortname);
        $this->useremail = strtolower($useremail);
        // set basefeeds
        $this->user_auth = "https://www.googleapis.com/auth/admin.directory.user";
        $this->site_feed = "https://sites.google.com/feeds/site/$this->domain";
        $this->drive_auth = 'https://www.googleapis.com/auth/drive ';
        $this->file_auth = 'https://www.googleapis.com/auth/drive.file ';
        $this->alias_feed = "https://apps-apis.google.com/a/feeds/alias/2.0/$this->domain/?start=aaaarnold@luther.edu";
        $this->group_auth = 'https://www.googleapis.com/auth/admin.directory.group';
        $this->id_feed = 'https://docs.google.com/feeds/id/';
        $this->cal_auth = ' https://www.googleapis.com/auth/calendar';
	$this->owncalendars_feed = 'https://www.google.com/calendar/feeds/default/owncalendars/full';
        $this->drivefile_feed = 'https://www.googleapis.com/auth/drive.file';

        $this->disregard = "'Past Term','DELETED'"; // skip marked for delete, unapproved and past term
//        $this->setup_client();
    }

    public function setup($username, $service_name = 'drive') {
//        $key = get_config('morsle','privatekey');
//        $this->privatekey = json_decode($key);
//        var_dump($key);
//        die;
        $this->client = new Google_Client();
	$this->client_id = '1016393342084-dqql4goj9s0l402sbf4dtnoq2tsk0hp8.apps.googleusercontent.com';
        $this->service_account_name = '1016393342084-dqql4goj9s0l402sbf4dtnoq2tsk0hp8@developer.gserviceaccount.com';
        $this->client->setScopes(array($this->cal_auth, 
            $this->drive_auth, 
            $this->file_auth, 
            $this->user_auth, 
            $this->group_auth,
            $this->drivefile_feed));

        switch($service_name) {
            case 'calendar': 
                // oauth for calendar v.3
                $this->service = new Google_Service_Calendar($this->client);
                $auth = $this->cal_auth;
                break;
            case 'drive':
                $this->service = new Google_Service_Drive($this->client);
                $auth = $this->drive_auth;
                break;
            case 'files':
                $this->service = new Google_Service_Drive_FileList($this->client);
                $auth = $this->drive_auth;
                break;
            case 'user':
                $this->service = new Google_Service_Directory($this->client);
                $this->user = new Google_Service_Directory_User();
                $this->name = new Google_Service_Directory_UserName();
                $auth = $this->user_auth;
                break;
            case 'group_members':
                $this->service = new Google_Service_Directory($this->client);
                $this->group = new Google_Service_Directory_Group();
                $auth = $this->group_auth;
                break;
            case 'group':
                $this->service = new Google_Service_Directory($this->client);
                $this->group = new Google_Service_Directory_Group();
                $auth = $this->group_auth;
                break;
        }
//        $this->auth = service_account($this->client, $this->client_id, $this->service_account_name, $username);
        $this->revoke_token();
        $this->auth = service_account($this->client, $this->client_id, $this->service_account_name, $username, $auth);
    }
    
    function revoke_token() {
        $this->client->revokeToken($this->auth->access_token);
        unset($this->client->auth);
        unset($_SESSION['service_token']);
    }
/*
    function get_token($service_name) {
        switch($service_name) {
            case 'calendar': 
                // oauth for calendar v.3
                $this->service = new Google_Service_Calendar($this->client);
                $auth = $this->cal_auth;
                break;
            case 'drive':
                $this->service = new Google_Service_Drive($this->client);
                $auth = $this->drive_auth;
                break;
            case 'files':
                $this->service = new Google_Service_Drive_FileList($this->client);
                $auth = $this->drive_auth;
                break;
            case 'user':
                $this->service = new Google_Service_Directory($this->client);
                $this->user = new Google_Service_Directory_User();
                $this->name = new Google_Service_Directory_UserName();
                $auth = $this->user_auth;
                break;
            case 'group_members':
                $this->service = new Google_Service_Directory($this->client);
                $this->group = new Google_Service_Directory_Group();
                $auth = $this->group_auth;
                break;
            case 'group':
                $this->service = new Google_Service_Directory($this->client);
                $this->group = new Google_Service_Directory_Group();
                $auth = $this->group_auth;
                break;
        }
        $this->auth = service_account($this->client, 
                $this->client_id, 
                $this->service_account_name, 
                $this->useremail, $auth);
        
    }

    function setup_client() {
        global $CFG;
        $key_file_location = "$CFG->dirroot/google/key.p12"; //key.p12
        $key = file_get_contents($key_file_location);
        $privatekey = json_decode($key);
        $this->client_id = $privatekey->client_id;
        $this->service_account_name = $privatekey->client_email;
        $this->client = new Google_Client();
        $this->client->setScopes(array($this->cal_auth, 
            $this->drive_auth, 
            $this->file_auth, 
            $this->user_auth, 
            $this->group_auth));
    }
*/        

    /**
     * Defines operations that happen occasionally on cron
     * @return boolean
     */
    public function cron() {
        global $CFG;
    /// We are going to measure execution times

        // set this up so it runs at your desired interval
	    srand ((double) microtime() * 10000000);
	    $random100 = rand(0,100);
	    if ($random100 < 10) {     // Approximately every hour.
	        mtrace(date(DATE_ATOM) . " - Running m_cook...");
			$status = $this->m_cook();
	        mtrace(date(DATE_ATOM) . " - Done m_cook: $status");
	    }
	    if ($random100 > 45 && $random100 < 55) {     // Approximately every hour
	        mtrace(date(DATE_ATOM) . " - Running m_maintain...");
			$status = $this->m_maintain();
	        mtrace(date(DATE_ATOM) . " - Done m_maintain: $status");
	    }

	    if ($random100 > 90) {     // Approximately every hour.
	        mtrace(date(DATE_ATOM) . " -Running m_calendar...");
			$status = $this->m_calendar();
	        mtrace(date(DATE_ATOM) . " -Done m_calendar: $status");
	    }
   }

   public function supported_returntypes() {
//        return FILE_REFERENCE;
//        return (FILE_INTERNAL | FILE_EXTERNAL);
        return FILE_EXTERNAL;
    }
    
	/*
	 * Processes all current morsle_active records, creating or deleting components as necessary
	 * Any course that is current (mdl_course.startdate > now and is visible gets resources created (if not already done)
	 * Any course whose mdl_course.startdate + config.morsle_expiration (converted to seconds) < now gets its resources deleted
	 * Any course falling inbetween is ignored (we don't update courses beyond their startdate)
	 */
	function m_cook() {

	    // get all the morsle records that should be deleted
	    global $CFG, $DB;
	    $deletion_clause = " m.status NOT IN($this->disregard)
		    AND c.startdate + $this->expires < $this->curtime";
	    $sql = "SELECT m.*, c.startdate + $this->expires AS expire, c.id AS coursepresent from " . $CFG->prefix . "morsle_active m
		    LEFT JOIN " . $CFG->prefix . "course c on m.courseid = c.id
		    WHERE $deletion_clause
		    AND (m.readfolderid IS NOT NULL
		    OR m.writefolderid IS NOT NULL
		    OR m.groupname IS NOT NULL
		    OR m.password IS NOT NULL
		    OR m.siteid IS NOT NULL) "; // don't care to consider any records who've already been deleted
	    $this->deleterecs = $DB->get_records_sql($sql);
	    foreach ($this->deleterecs as $record) {
		    $this->user= $record->shortname . '@' . $this->domain;
		    $this->params = array('xoauth_requestor_id' => $this->user, 'delete' => 'true');
		    if ($status = $this->m_barf($record)) {
			    $record->status = 'DELETED';
			    $deleted = $DB->update_record(morsle_active, $record);
			    if (!$deleted) {
				    $this->log('RECORD NOT DELETED - FAILURE', $record->courseid);
			    } else {
				    $this->log('RECORD DELETED -  SUCCESS', $record->courseid);
			    }
		    }
	    }

	    // CREATE OR COMPLETE CREATION OF CURRENT COURSES
	    /* sql criteria:
	    //  course visible OR INVISIBLE
	    //	c.enrolenddate > $curtime (for creation)
	    //	one of the fields - password, readfolderid, writefolderid, groupname, siteid must be NULL (creation)
	    */
	    $creation_clause = " m.status NOT IN($this->disregard)
	    		AND c.startdate > $this->curtime - $this->expires
	       		AND (m.readfolderid IS NULL OR m.writefolderid IS NULL OR m.groupname IS NULL OR m.siteid IS NULL)";
   		$sql = 'SELECT m.* from ' . $CFG->prefix . 'morsle_active m
	    		JOIN ' . $CFG->prefix . 'course c on m.courseid = c.id
	    		WHERE ' . $creation_clause;
   		$todigest = $DB->get_records_sql($sql);
			// process each record
		foreach ($todigest as $record) {
			$this->user= $record->shortname . '@' . $this->domain;
			$this->params = array('xoauth_requestor_id' => $this->user);
			$status = $this->m_digest($record);
	    }
    }

	/*
	* Add components to google for morsle_active record
	* Checks condition of each item field in record and creates if not present
	*/
	public function m_digest($record) {
	    global $success, $CFG, $DB;
            $this->shortname = strtolower($record->shortname);
            $this->courseid = $record->courseid;
	    $groupname = $this->shortname . '-group';
	    $groupfullname = $groupname . '@' . $this->domain;
            $this->sitename = urlencode('Course Site for ' . strtoupper($this->shortname));
	    $stats = array(
			'password' => $record->password,
			'groupname' => $record->groupname,
			'readfolderid' => $record->readfolderid,
			'writefolderid' => $record->writefolderid,
			'siteid' => $record->siteid
			);
            foreach ($stats as $key=>$stat) {
                if (is_null($stat)) {
                    switch ($key) {
                        case 'password': // create user account
                                $this->revoke_token();
                                $this->get_token('user');
                                $returnval = $this->useradd(); // either password coming back or $response->response
                                break;
                        case 'groupname': // add group
                                $this->revoke_token();
                                $this->get_token('group');
                                $returnval = $this->groupadd($groupname);
                                break;
                        case 'readfolderid': // create readonly folder
                                $this->useremail = $this->shortname . '@luther.edu';
                                $this->revoke_token();
                                $this->get_token('drive');
                                $returnval = createcollection($this, $this->shortname . '-read');
                                break;
                        case 'writefolderid': // create writeable folder
                                $this->useremail = $this->shortname . '@luther.edu';
                                $this->revoke_token();
                                $this->get_token('drive');
                                $returnval = createcollection($this, $this->shortname . '-write');
                                break;
                        case 'siteid': // create site
//                                $returnval = $this->createsite();
                                break;
                    }
                    if ($returnval !== null) {
                        $this->log('added ' . $key . " SUCCESS", $record->courseid, null, s($returnval));
                        $record->$key = s($returnval);
                        $updaterec = $DB->update_record('morsle_active', $record);
                    } else {
                        $this->log('added ' . $key . " FAILURE", $record->courseid, null, s($returnval));
                        if ($key == 'password') {
//                            break;
                        }
                    }
                }
            }
            return $returnval;
	}


	// checks condition of each item field in record (stats) and deletes if present
	public function m_barf($record) {
		global $success, $DB;

		// we're deleting because the course has expired
	    $stats = array(
	    	'user' => $record->password,
		    'group' => $record->groupname,
		    'readonly folder' => $record->readfolderid,
		    'writeable folder' => $record->writefolderid
		    );
            $stats = array_reverse($stats, true);
	    foreach ($stats as $key=>$stat) {
                if (!is_null($stat)) {
                    $status = $key;
                    switch ($key) {
                        // sites not deleteable through API yet
                        case 'site': // create site
//				$siteid = sitedelete($shortname, $record, $user, $this->domain);
//				$record->siteid = null;
//					$writeassigned = sitepermissions($shortname, $record, $groupfullname, $courseid, $this->domain);
                                break;
                        case 'writeable folder': // delete writeable folder
                            $this->revoke_token();
                            $this->get_token('drive');
                            $return = $this->service->files->delete($stat);
                            $record->writefolderid = null;
                            break;
                        case 'readonly folder': // delete readonly folder
                            $this->revoke_token();
                            $this->get_token('drive');
                            $return = $this->service->files->delete($stat);
                            $record->readfolderid = null;
                            break;
                        case 'group': // delete group
                            $this->revoke_token();
                            $this->get_token('group');
                            $return = $this->service->groups->delete($stat);
                            $record->groupname = null;
                            break;
                        case 'user': // delete user account
                            $this->revoke_token();
                            $this->get_token('user');
                            $primaryEmail = $this->shortname . '@luther.edu';
                            $return = $this->service->users->delete($primaryEmail);
                            $record->password = null;
                            break;
                    }
                    if ($success) {
                        $updaterec = $DB->update_record('morsle_active', $record);
                        $this->log($key . ' DELETED', $record->courseid, null, s($response->response));
                    } else {
                        $this->log($key . ' DELETE FAILED', $record->courseid, null, null);
                        return false;
                    }
                }
            }
            return true;
	}

	/********** PROVISIONING - ACCOUNTS - GROUPS - ****************/
	/*
	 * creates a new user on google domain for the course
	* important to note that if the account is never accessed by anything other than the API it will
	*    never initiate the first challenge typical of a new account.  This additionally allows people who've
	*    editor rights to the calendar to add and delete events
	*    NOTE: ALL OF THESE FUNCTIONS LOG FROM THEIR CALLING PROGRAM
	* TODO: uses clientlogin
	*/
	function useradd() {
            global $CFG, $success;
            $password = genrandom_password(12);
            $familyName = $this->shortname;
            $givenName = 'm';
            $primaryEmail = $this->shortname . '@luther.edu';
            $this->name->setFamilyName($familyName);
            $this->name->setGivenName($givenName);

            $this->user->setName($this->name);
            $this->user->setPrimaryEmail($primaryEmail);
            $this->user->setPassword($password);

            $return = $this->service->users->insert($this->user);
            if (isset($return->id)) {
                return $password;
            }
	}

        /*
	 * creates a new group on google domain for the course
	* @param $this->headers - precreated clientlogin headers for authentication
	* @param $groupname - name of group to be created
	* @param $morslerec entire morsle record for this course NOT NEEDED
	* @param $this->domain - constant representing domain name
	* TODO: uses Clientlogin
	*/
	function groupadd($groupname) {
            $this->group->setName($groupname);
            $this->group->setEmail($groupname . '@luther.edu');
            $return = $this->service->groups->insert($this->group);
            return $groupname;
	}

	/**********  SITE FUNCTIONS ***********************/
	// TODO: should be able to combine these two functions
	/*
	 * creates base site for course name
	*/
	function createsite() {
		// form the xml for the post
		$sitecreate =
		'<entry xmlns="http://www.w3.org/2005/Atom" xmlns:sites="http://schemas.google.com/sites/2008">
		<title>' . $this->sitename . '</title>
		<summary>Morsle course site for collaboration</summary>
		<sites:theme>microblueprint</sites:theme>
		</entry>';
		// Make the request
		$response  = twolegged($this->site_feed, $this->params, 'POST', $sitecreate, '1.4');
		if ($success ) {
			$feed = simplexml_load_string($response->response);
			return get_href_noentry($feed, 'alternate');
		} else {
			return $response->response;
		}
	}

	/*
	 * creates a portfolio site for a student based on the HPE portfolio template
         * NOT GOING TO BE USED ANYMORE
	*/
	function createportfoliosite($title) {
		global $success;

		// form the xml for the post
		$sitecreate =
		'<entry xmlns="http://www.w3.org/2005/Atom" xmlns:sites="http://schemas.google.com/sites/2008">
		<title>' . $title . '</title>
		<link rel="http://schemas.google.com/sites/2008#source" type="application/atom+xml"
		href="https://sites.google.com/feeds/site/luther.edu/puffer-s-temp-hpe2"/>
		<summary>HPE ePortfolio Site For Assessment</summary>
		</entry>';

		// Make the request
		$response  = twolegged($this->site_feed, $this->params, 'POST', $sitecreate, '1.4');
		if ($success ) {
			$feed = simplexml_load_string($response->response);
			return get_href_noentry($feed, 'alternate');
		} else {
			return $response->response;
		}
	}

	/*
	 * gets a list of portfolios sites to which a particular user (usually a teacher) has access -- not used all that much
         * NOT GOING TO BE USED ANYMORE
	* mostly for troubleshooting
	*/
	function getportfoliosites() {
		global $success;
		$portfolio = array();

		// Make the request
		$response  = twolegged($this->site_feed, $this->params, 'GET', null, '1.4');
		if ($success ) {
			$feed = simplexml_load_string($response->response);
			foreach ($feed->entry as $entry) {
				if(strpos($entry->title,$this->portfoliobase) === 0) {
					$portfolio[] = trim(substr($entry->title, strlen($portfolioname),70));
				}
			}
			return $portfolio;
		} else {
			return false;
		}
	}

	/************** PERMISSION FUNCTIONS ******************/
	/*
	 * adds or deletes members and owners to a group on google domain for the course
	* this maintains "membership" and thereby deletes owners if they're no longer in the course
	* as well as plain members
	*/

	function m_maintain($course = null) {
		global $CFG, $DB;
//		$this->get_aliases();

                $courseclause = $course != null ? " AND m.shortname = '" .  strtolower($course) .  "'": null;
                $sql = 'SELECT m.*, c.visible as visible, c.category as category from ' . $CFG->prefix . 'morsle_active m
			JOIN ' . $CFG->prefix . 'course c on m.courseid = c.id
			WHERE m.status NOT IN(' . $this->disregard . ')
			AND c.startdate + ' . $this->expires . ' > ' . $this->curtime
                        . $courseclause;

		$chewon = $DB->get_records_sql($sql);
		$random = rand(0,9);
		foreach ($chewon as $record) {
			$this->shortname = $record->shortname;
			$this->courseid = $record->courseid;
			$this->user = strtolower($this->shortname . '@' . $this->domain);
			$this->urluser = urlencode($this->user);
//			$this->params = array('xoauth_requestor_id' => $this->user);
			$this->groupname = $this->shortname . '-group';
			$this->groupfullname= $this->groupname . '@' . $this->domain;
			$this->visible = $record->visible;
			$this->sitename = str_replace(' ', '-',strtolower('Course Site for ' . $this->shortname));
			$this->site_acl_feed = "https://sites.google.com/feeds/acl/site/$this->domain/$this->sitename";
			$this->cal_acl_feed = "https://www.googleapis.com/calendar/v3/calendars/";
			$this->term = $DB->get_field('course_categories', 'name', array('id' => $record->category));

			// determine rosters for everything else based on visibility of course, removing students if not visible
			$rosters = $this->get_roster(); // if course is invisible we don't give students permission or they could get at resources from the google side
			// maintain members and owners for group

                        $this->setup($this->user, 'group');
                        if (!is_null($this->groupname)) {
                                $this->useremail = 'admin-moogle@luther.edu';
                                $this->revoke_token();
                		$this->get_token('group');
				$garray = array('editingteacher' => 'owner','teacher' => 'owner','student' => 'member');
				// if full resources have been switched off, this will remove all permissions but the calendar
				$allusers = $rosters;
				array_walk($allusers,'set_googlerole', $garray);
//				$this->membersmaintain($allusers);
			}


			// now we need to substitute real email for alias because folders use real (groups use alias)
			foreach ($rosters as $key=>$value) {
				if (isset($this->aliases[$key])) {
					$rosters[$this->aliases[$key]] = $value;
					unset($rosters[$key]);
				}
			}

                        $this->useremail = $this->user;
			// Calendar permissions
                        
                        $this->setup($this->user, 'calendar');
			if (!is_null($record->password)) {
                                $this->revoke_token();
                		$this->get_token('calendar');
				$garray = array('editingteacher' => 'writer','teacher' => 'writer','student' => 'reader','guest' => 'reader');
				$allusers = $rosters;
				array_walk($allusers,'set_googlerole', $garray);
				$this->gmembers = $this->set_calendarpermissions($allusers, $record);
                        }
                        $this->setup($this->user, 'drive');

			// read-only folder permissions
			if (!is_null($record->readfolderid)) {
                                $this->revoke_token();
                		$this->get_token('drive');
				$garray = array('editingteacher' => 'writer','teacher' => 'writer','student' => 'reader');
				// if full resources have been switched off, this will remove all permissions but the calendar
				$allusers = $rosters;
//				$allusers = array();
				array_walk($allusers,'set_googlerole', $garray);
				$readassigned = $this->set_folderpermissions('reader', $allusers, $record->readfolderid);
			}

			// writeable folder permissions (everyone writes)
			if (!is_null($record->writefolderid)) {
//                                $this->revoke_token();
//                		$this->get_token('drive');
				$garray = array('editingteacher' => 'writer','teacher' => 'writer','student' => 'writer');
				// if full resources have been switched off, this will remove all permissions but the calendar
				$allusers = $rosters;
				array_walk($allusers,'set_googlerole', $garray);
				$writeassigned = $this->set_folderpermissions('writer', $allusers, $record->writefolderid);
			}
                        $this->revoke_token();

                        $this->setup($this->user, 'site');
			// Site permissions
			if (!is_null($record->siteid)) {
                                $this->revoke_token();
                		$this->get_token('site');
				$garray = array('editingteacher' => 'owner','teacher' => 'owner','student' => 'writer');
				// if full resources have been switched off, this will remove all permissions but the calendar
				$allusers = $rosters;
				array_walk($allusers,'set_googlerole', $garray);
				$writeassigned = $this->set_sitepermissions($allusers);
			}

		}
	}
        
        function membersmaintain($members) {
		global $success;
                $return = $this->service->members->listMembers($this->groupfullname);
//                $gmembers = get_group_permissions($this->groupname, $this->domain, $this->authstring);
                $gmembers = array();
                foreach($return as $key => $value) {
                    $gmembers[$value->email] = strtolower($value->role);
                }
                
		// check differences
		$deleted = array_diff_assoc($gmembers, $members);
		$added = sizeof($members) > 200 ? array() : array_diff_assoc($members, $gmembers);

                /************************************************
                  To actually make the batch call we need to 
                  enable batching on the client - this will apply 
                  globally until we set it to false. This causes
                  call to the service methods to return the query
                  rather than immediately executing.
                 ************************************************/
                $this->client->setUseBatch(true);

                $batch = new Google_Http_Batch($this->client);

		// delete first due to aliases
		foreach($deleted as $email=>$role) {
                        $return = $this->service->members->delete($this->groupfullname, $email);
                        $batch->add($return, $emailAddress);
			if ($success == null) {
				$this->log("$member delete SUCCESS", $this->courseid, null, $permission . ':' . s($response->response));
			} else {
				$this->log("$member delete FAILED", $this->courseid, null, $permission . ':');
			}
		}

		// then add
		foreach($added as $email => $role) {
                        $enrollee = new Google_Service_Directory_Member();
                        $enrollee->setEmail($email);
                        $enrollee->setRole(strtoupper($role));
                        $return = $this->service->members->insert($this->groupfullname, $enrollee);
                        $batch->add($return, $emailAddress);
			if ($success == null) {
				$this->log("$member add SUCCESS", $this->courseid, null, $permission . ':' . s($response->response));
			} else {
				$this->log("$member ADD FAILED ", $this->courseid, null, $permission . ':');
			}
		}
                /************************************************
                  Executing the batch will send all requests off
                  at once.
                 ************************************************/
                $results = $batch->execute();
                $this->client->setUseBatch(false);
	}

	function set_folderpermissions($readwrite, $members, $folderid) {
		// what does google say we have for permissions on this folder
                $return = $this->service->permissions->listPermissions($folderid);
                $gmembers = array();
                foreach($return as $key => $value) {
                    if($value === "") {
                        continue;
                    }
                    $gmembers[$value->emailAddress] = strtolower($value->role); 
                    $permissonId[$value->emailAddress] = $value->id;
                }
                $deleted = array_diff_assoc($gmembers, $members);
                unset($deleted[$this->useremail]);
                $added =  array_diff_assoc($members, $gmembers);

                /************************************************
                  To actually make the batch call we need to 
                  enable batching on the client - this will apply 
                  globally until we set it to false. This causes
                  call to the service methods to return the query
                  rather than immediately executing.
                 ************************************************/
                $this->client->setUseBatch(true);

                $batch = new Google_Http_Batch($this->client);

                // add new members first in case we need an owner
                foreach($added as $emailAddress=>$role) {
                    $permission = new Google_Service_Drive_Permission();
                    $permission->setValue($emailAddress);
                    $permission->setType('user');
                    $permission->setRole($role);
                    $return = $this->service->permissions->insert($folderid, $permission);
                    $batch->add($return, $emailAddress);
                }

                if (count($deleted) + count($added) == 0) {
                    return true;
                }

                // delete
                foreach($deleted as $emailAddress=>$role) {
                    $return = $this->service->permissions->delete($folderid, $permissonId[$emailAddress]);
                    $batch->add($return, $emailAddress);
                }
                /************************************************
                  Executing the batch will send all requests off
                  at once.
                 ************************************************/
                $results = $batch->execute();
                $this->client->setUseBatch(false);
	}

	function set_sitepermissions($members) {
		$this->base_feed = $this->site_acl_feed;

		// what does google say we currently have for permissions?
		if ($gmembers = $this->get_sitepermissions()) {

			// don't process the group acl or the course owner acl
			unset($gmembers[$this->user]);
//			unset($gmembers[$this->group]);

			$deleted = array_diff_assoc($gmembers, $members);
			$added =  array_diff_assoc($members, $gmembers);

			// delete first because we may be dealing with aliases
			foreach($deleted as $member=>$permission) {
				$delete_base_feed = $this->base_feed . '/user%3A' . $member;
				$response = twolegged($delete_base_feed, $this->params, 'DELETE', null, '1.4');
				if ($response->info['http_code'] == 200) {
					$this->log("$member Deleted $this->sitename", $this->courseid, null, $this->sitename . ':' . s($response->response));
				} else {
					$this->log("DELETE FAILED $member $this->sitename", $this->courseid, null, $this->sitename . ':');
				}
			}
			foreach($added as $member=>$permission) {
				$siteacldata = acl_post($member, $permission, 'user');
				$response = twolegged($this->base_feed, $this->params, 'POST', $siteacldata, '1.4');
				if ($response->info['http_code'] == 201) {
					$this->log("$member added $this->sitename", $this->courseid, null, $this->sitename . ':' . s($response->response));
				} else {
					$this->log("ADD FAILED $member $this->sitename", $this->courseid, null, $this->sitename . ':');
				}
			}
			return true;
		}
		return false;
	}

	function get_sitepermissions() {
		$role = array();
		$response = twolegged($this->base_feed, $this->params, 'GET',null,'1.4');
		$permissions = $response->response;
		preg_match_all("/<gAcl:role[^>]+>/", $permissions, $roles);
		preg_match_all("/<gAcl:scope[^>]+>/", $permissions, $scopes);
		$rolestring = 'value=';
		$scopestring = 'value=';
		foreach ($scopes[0] as $key=>$value) {
			$scope = substr($value,strpos($value,$scopestring) + strlen($scopestring) + 1, -3);
			if (!empty($scope)) {
				$role[$scope] = substr($roles[0][$key],strpos($roles[0][$key],$rolestring) + strlen($rolestring) + 1,-3);
			}
		}
		return $role;
	}


	function set_calendarpermissions($members = null, $record = null) {
            if (!$acl = $this->get_calendarpermissions()) {
                return;
            }

            $deleted = array_diff_assoc($this->gmembers, $members);
            $added =  array_diff_assoc($members, $this->gmembers);
            if (count($deleted) + count($added) == 0) {
                return true;
            }

            /************************************************
              To actually make the batch call we need to 
              enable batching on the client - this will apply 
              globally until we set it to false. This causes
              call to the service methods to return the query
              rather than immediately executing.
             ************************************************/
            $this->client->setUseBatch(true);

            $batch = new Google_Http_Batch($this->client);

            // delete first because we may be dealing with aliases
            foreach($deleted as $member=>$permission) {
                try {
                    $return = $this->service->acl->delete($this->user, 'user:' . $member);
                    $batch->add($return, $emailAddress);
                    $this->log("Deleted $member calendar", $this->courseid, null, $this->user . ':');
                } catch (Exception $e) {
                    $this->log("DELETE FAILED $member calendar", $this->courseid, null,  $this->user . ':');
                }
            }
            // add new permissions
            foreach($added as $member=>$permission) {
                try {
                    $rule = new Google_Service_Calendar_AclRule();
                    $scope = new Google_Service_Calendar_AclRuleScope();

                    $scope->setType("user");
                    $scope->setValue($member);
                    $rule->setScope($scope);
                    $rule->setRole($permission);

                    $return = $this->service->acl->insert('primary', $rule);
                    $batch->add($return, $emailAddress);
                    $this->log("Added $member calendar", $this->courseid, null,  $this->user . ':');
                    echo $createdRule->getId();
                } catch (Exception $ex) {
                    $this->log("ADD FAILED $member calendar", $this->courseid, null,  $this->user . ':');
                }
            }
            $results = $batch->execute();
            $this->client->setUseBatch(false);
            return true;
	}

	// calendar currently needs clientauth because redirects always go to a clientauth site
	function get_calendarpermissions() {
		$owner = $this->user;
//		$owner = str_replace('@', '%40', $this->user);
//                $owner = 'puffro01@luther.edu';
                try {
                    $acl = $this->service->acl->listAcl('primary');
                } catch (Exception $ex) {
                    return null;
                }
                $gmembers = array();
                foreach ($acl->getItems() as $rule) {
                    $name = preg_replace('/^[userdomain]{4,6}[^:]?./','',$rule->getId());
                    $this->gmembers[$name] = $rule->getRole();
                  
                }
                unset($this->gmembers['default']);
                unset($this->gmembers[$this->domain]);
        	unset($this->gmembers[$owner]);
                return $acl;
	}


	/**************** ROSTER FUNCTIONS ***************/
	/*
	 * gets all participants in a moodle course with optional role filtering
	* returns array of user->email keys and role values
	*/
	function get_roster($onlyrole=null) {
		$coursecontext = get_context_instance(CONTEXT_COURSE, $this->courseid);
		$allroles = get_roles_used_in_context($coursecontext);
		arsort($allroles);
		if (!$this->visible) {
			foreach ($allroles as $key=>$role) {
				if ($role->shortname <> 'editingteacher') {
					unset($allroles[$key]);
				}
			}
		}
		$roles = array_keys($allroles);

		// can't used canned function as its likely to return a student role when the user has both a student and a teacher role
		// so this bit will allow the lower roleid (higher value role) to overwrite the lower one
		foreach ($roles as $role) {
			$temp = get_role_users($role,$coursecontext, false, '', null, false);
			if ($temp !== false && sizeof($temp) !== 0) {
				if (isset($course->users)) {
					$course->users = array_merge($course->users,$temp);
				} else {
					$course = new stdclass;
					$course->users = $temp;
				}
			}
		}
		$members = array();
                $suspended = get_suspended_userids($coursecontext);
		foreach ($course->users as $cuser) {
                    if (array_key_exists($cuser->id, $suspended)) {
                        unset($course->users[$cuser->id]);
                    } else if ($onlyrole === null || $onlyrole == $allroles[$cuser->roleid]->shortname) {
                        $members[strtolower($cuser->email)] = $allroles[$cuser->roleid]->shortname;
                    }
		}
		return $members;
	}
	/*
	 * Get aliases so we can avoid constantly adding and deleting them in the activity
	*/
	function get_aliases() {
		global $CFG, $success;
		require_once($CFG->dirroot.'/google/gauth.php');
		$this->aliases = array();
		// Make the request
		$response = send_request('GET', $this->alias_feed, $this->authstring, null, null, '2.0');
		$this->aliases = array_merge($this->aliases,$this->process_aliases($response->response));
		while (($newalias_feed = get_href_noentry(simplexml_load_string($response->response), 'next')) !== false) {
			$response = send_request('GET', $newalias_feed, $this->authstring, null, null, '2.0');
			$this->aliases = array_merge($this->aliases,$this->process_aliases($response->response));
		}
	}

	function process_aliases($response) {
		$a_namepattern = "#apps:property name='alias[^/.]+\.?[^/.]*\.[^/.]*#";
		$u_namepattern = "#apps:property name='user[^/.]+\.?[^/.]*\.[^/.]*#";
		//		$a_namepattern = "#apps:property name='alias[^/.]+\.[^/.]+#";
		//		$u_namepattern = "#apps:property name='user[^/.]+\.[^/.]+#";
		preg_match_all($a_namepattern, $response, $a_names);
		preg_match_all($u_namepattern, $response, $u_names);
		for ($i=0;$i<sizeof($a_names[0]);$i++) {
			$split = explode("'",$a_names[0][$i]);
			$a_names[0][$i] = $split[3];
			$split = explode("'",$u_names[0][$i]);
			$u_names[0][$i] = $split[3];
		}
		return array_combine($a_names[0], $u_names[0]);
	}

	/************ CALENDAR FUNCTIONS ******************/
	/*
	 * Author: Bob Puffer
	 * Process calendar events from mdl_event to mdl_morsle_event
	 * and, in turn, from mdl_morsle_event to Google calendar
	 */
	function m_calendar() {
		global $CFG, $DB, $success;
//		require_once('../../../config.php');
		require_once($CFG->dirroot.'/google/lib.php');
		require_once($CFG->dirroot.'/google/gauth.php');
//        	require_once "$CFG->dirroot/google/google-api-php-client/src/Google/Service.php";
//        	require_once "$CFG->dirroot/google/google-api-php-client/src/Google/Service/Resource.php";
//        	require_once "$CFG->dirroot/google/google-api-php-client/src/Google/Service/Calendar.php";
//        	require_once "$CFG->dirroot/google/google-api-php-client/src/Google/Client.php";
		//$chewon = $DB->get_records('morsle_active');
//                $client = new Google_Client();
//                $service = new Google_Service_Calendar($client);
//                $this->auth = service_account($client, $this->client_id, $this->service_account_name);
//                $this->calauthstring = "Authorization: OAuth " . $this->auth->access_token;
//                $class = $client->config->getAuthClass();
//                $client->auth = new $class($client);
//                $client->auth->token = $this->access_token;
//                $calendarService = new Google_Service_Calendar($client);
//                $events = $calendarService->events;
//                $service = 'Google_Service_Calendar';
//                $serviceName = 'Google_Service_Calendar_Events_Resource';
//                $resourceName = 'insert';
//                $resource = 'event';
//		$service = 'cl';
		// TODO: this is unlikely to fly and needs to be adjusted for two-legged

		//TODO: where in this are morsle_event records deleted that belong to a course that has expired?
		// here's the code, when does it get turned on?
		// first delete all morsle_event records past expiration
		// don't worry about deleting the corresponding Google records as they will leave when the calendar gets deleted
		// (which has the same expiration time)
		//$select = "timestart + GREATEST(900,timeduration) + $this->expires < $this->curtime";
		//$success = delete_records_select('morsle_event',$select);



		// get all the records from morsle_events that need to be deleted from Google
		// morsle_active record not in disregard list
		// and
		// no longer in event
		// or event visible
		$eventsql = 'SELECT me.*, ma.shortname as shortname, ma.password as password FROM ' . $CFG->prefix . 'morsle_event me
			JOIN ' . $CFG->prefix . 'course c on me.courseid = c.id
			JOIN ' . $CFG->prefix . 'morsle_active ma on ma.courseid = c.id
			LEFT JOIN ' . $CFG->prefix . 'event e on me.eventid = e.id
			WHERE ma.status NOT IN(' . $this->disregard . ')
			AND c.id = 2909
                        AND ( ISNULL(e.id)
			OR e.visible = 0
			OR e.timemodified <> me.timemodified)
			ORDER BY me.courseid ASC';
		$deleted = $DB->get_records_sql($eventsql);

		// get unique list of courses involved in this action (delete)
		$coursesql = 'SELECT DISTINCT me.courseid as courseid, ma.shortname as shortname, ma.password as password FROM ' . $CFG->prefix . 'morsle_event me
			JOIN ' . $CFG->prefix . 'course c on me.courseid = c.id
			JOIN ' . $CFG->prefix . 'morsle_active ma on ma.courseid = c.id
			LEFT JOIN ' . $CFG->prefix . 'event e on me.eventid = e.id
			WHERE ma.status NOT IN(' . $this->disregard . ')
			AND c.id = 2909
			AND ( ISNULL(e.id)
			OR e.visible = 0
			OR e.timemodified <> me.timemodified
			OR e.timestart + GREATEST(900,e.timeduration) + ' . $this->expires . ' < ' . $this->curtime
			. ') ORDER BY me.courseid ASC';
		$courseids = $DB->get_records_sql($coursesql);

		// cycle through each course that has records to be deleted
		foreach($courseids as $coursekey=>$courseid) {
			$owner = $courseid->shortname . '@' . $this->domain;
			$calowner = $owner;
			foreach ($deleted as $event) {
				if ($event->courseid == $coursekey) {
                                    try {
                                        $this->service->events->delete($calowner, $event->googleid);

                                        // only deleted from morsle_event if successfully deleted from google
                                        $success = $DB->delete_records('morsle_event',array('eventid' => $event->eventid));
                                        $this->log("$event->name deleted", $coursekey, null, s($response->response));
                                    } catch (Exception $e) {
                                        $this->log("$event->name NOT DELETED", $coursekey, null, null);
                                    }
				}
			}
		}
//                $failure = $this->service->events->delete('puffro01@luther.edu', '01ftdi0hcfl18m6hsbie7g8qpg');

		// this query should get all records in event that are
		// not yet in morsle_event
		// course visible or invisible (only instructors see calendars of invisible courses)
		// course startdate plus expiration time is greater than current time
		// event visible
		// event is in the future
		// morsle_active record not in disregard list
		// get all the records from events that need to be added to morsle_events
		$sql = 'SELECT e.*, ma.shortname as shortname, ma.password as password FROM ' . $CFG->prefix . 'event e
			JOIN ' . $CFG->prefix . 'course c on e.courseid = c.id
			JOIN ' . $CFG->prefix . 'morsle_active ma on ma.courseid = c.id
			LEFT JOIN ' . $CFG->prefix . 'morsle_event me on me.eventid = e.id
			WHERE ISNULL(me.id)
			AND c.startdate + ' . $this->expires . ' > ' . $this->curtime
			. ' AND e.visible = 1
			AND e.timestart + GREATEST(900,e.timeduration) > ' . $this->curtime
			. ' AND ma.status NOT IN(' . $this->disregard . ')
			ORDER BY e.courseid ASC';
//			AND c.id = 2909
		$added = $DB->get_records_sql($sql);
		$sql = 'SELECT DISTINCT ma.courseid as courseid, ma.shortname as shortname, ma.password as password FROM ' . $CFG->prefix . 'event e
			JOIN ' . $CFG->prefix . 'course c on e.courseid = c.id
			JOIN ' . $CFG->prefix . 'morsle_active ma on ma.courseid = c.id
			LEFT JOIN ' . $CFG->prefix . 'morsle_event me on me.eventid = e.id
			WHERE ISNULL(me.id)
			AND c.startdate + ' . $this->expires . ' > ' . $this->curtime
			. ' AND e.visible = 1
			AND e.timestart + GREATEST(900,e.timeduration) > ' . $this->curtime
			. ' AND ma.status NOT IN(' . $this->disregard . ')
			ORDER BY e.courseid ASC';
//			AND c.id = 2909
		$courseids = $DB->get_records_sql($sql);

		// cycle through each course that has records to be added
		foreach($courseids as $coursekey=>$courseid) {
			$owner = $courseid->shortname . '@' . $this->domain;
			$calowner = $owner;
//                        $calowner = 'puffro01@luther.edu';

			// single processing
			foreach ($added as $key=>$event) {
				if ($event->courseid == $coursekey) {
                                        $calevent = get_cal_event_post($event);
                                        $base_feed = "https://www.googleapis.com/calendar/v3/calendars/$calowner/events"; // V.3
                                        try {
                                            $success = caloauth('POST', $base_feed, $this->auth->access_token, $calevent);
                                            unset($event->id);
                                            $event->description = addslashes($event->description);
                                            $event->name = addslashes($event->name);
                                            $event->eventid = $key;
                                            $event->googleid = $success->id;
                                            $eventtime = date(DATE_ATOM,$event->timestart);
                                            $success = $DB->insert_record('morsle_event',$event);
                                            $this->log('added ' . $key . " SUCCESS", $event->courseid, null, s($response->response));
                                        } catch (Exception $e) {
                                            $this->log('added ' . $key . " FAILURE", $event->courseid, null, null);
					}
					unset($added[$key]);
				}
			}
		}
	}

	function calmassdelete() {
		global $CFG, $DB, $success;
		//		require_once('../../../config.php');
		require_once($CFG->dirroot.'/google/lib.php');
		require_once($CFG->dirroot.'/google/gauth.php');
		//$chewon = $DB->get_records('morsle_active');

		// get course record from which events are to be deleted
		$coursesql = 'SELECT ma.* FROM mdl_morsle_active ma
						JOIN mdl_course c on c.id = ma.courseid
						WHERE c.id = 692';
		$courseid = $DB->get_record_sql($coursesql);
		// authenticate
		$service = 'cl';
		$owner = $courseid->shortname . '@' . $this->domain;
		$calowner = str_replace('@','%40',$owner);
		$password = morsle_decode($courseid->password);
		$this->authstring = "Authorization: GoogleLogin auth=" . clientauth($owner, $password, $service);
//		$password = rc4decrypt($courseid->password);


		// set up get of feed
		$base_feed = $this->cal_feed;
		$counter = 0;
		while ($counter < 100) {
			$response = send_request('GET', $base_feed, $this->authstring, null, null, '2.0');
			if ($success) {
				$feed = simplexml_load_string($response->response);
				if (!isset($feed->entry)) {
					$counter = 101;
				} else {
					$counter++;
					foreach ($feed->entry as $entry) {
						$event->googleid = substr($entry->id,strpos($entry->id,'events/') + 7,50);
						$delete_feed = "https://www.google.com/calendar/feeds/default/private/full/$event->googleid";
						$response = send_request('DELETE', $delete_feed, $this->authstring, null, null, '2.0');
						if ($success) {
							echo $entry->title . ' DELETED <br />';
						}
					}
				}
			}
		}
	}


	function log($message, $course, $url=null, $info=null) {
//		$success = add_to_log($course, 'morsle', $message, $url, $info);
	}
}
/*
 * generates a twelve character password for new (course) accounts only using characters acceptable to google
* @param $length optional length, defaults to 12 characters long
*/
function genrandom_password($length=12) {
	$str='abcdefghijkmnopqrstuvwxyzABCDEFGHIJKLMNPQRSTUVWXYZ-_$^*#:(){}[]23456789';
	$max=strlen($str);
	$length=@round($length);
	if(empty($length)) {
		$length=rand(8,12);
	}
	$password='';
	for($i=0; $i<$length; $i++){
		$password.=$str{rand(0,$max-1)};
	}
	return $password;
}

/*
 * callback function for array_walk
* @param $var passed by reference, role to be set
* @param $key TODO: why do we need this?
* @param $garray - contains google roles that will be returned based on value of $var
*/
function set_googlerole(&$var,$key, $garray) {
	$var = array_key_exists($var, $garray) ? $garray[$var] : $garray['student'];

}

/**
 * Morsle plugin cron task
 */
function repository_morsle_cron() {
    $instances = repository::get_instances(array('type'=>'morsle'));
    foreach ($instances as $instance) {
        $instance->cron();
    }
}