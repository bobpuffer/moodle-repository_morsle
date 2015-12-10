<?php

require_once('../../config.php'); // comment out when running in cron
require_once($CFG->dirroot.'/google/lib.php');
require_once($CFG->dirroot.'/google/gauth.php');
require_once($CFG->dirroot.'/lib/accesslib.php');

$authstring = "Authorization: GoogleLogin auth=" . clientauth();
$success;


class morsle extends stdClass {

	function __construct() {
		if ( !$this->domain = get_config('morsle','consumer_key')) {
			exit;
		}

		// days past last enrollment day that morsle resources are retained
		$this->expires = is_null(get_config('morsle','morsle_expiration')) ? 600 * 24 * 60 * 60: get_config('morsle','morsle_expiration') * 24 * 60 * 60;

	    $this->curtime = time();

	    // set basefeeds
	    $this->prov_feed = "https://apps-apis.google.com/a/feeds/$this->domain/user/2.0/";
	    $this->site_feed = "https://sites.google.com/feeds/site/$this->domain";
	    $this->docs_feed = 'https://docs.google.com/feeds/default/private/full/';
		$this->alias_feed = "https://apps-apis.google.com/a/feeds/alias/2.0/$this->domain/?start=aaaarnold@luther.edu";
		$this->group_feed = 'https://apps-apis.google.com/a/feeds/group/2.0/' . $this->domain . '/';
		$this->id_feed = 'https://docs.google.com/feeds/id/';
		$this->site_acl_feed = 'https://sites.google.com/feeds/acl/site/';

		// set authorization
	    $auth = clientauth();
		$this->authstring = "Authorization: GoogleLogin auth=" . $auth;
	    $this->headers = array($this->authstring, "GData-Version: 2.0");
	    $this->disregard = "'Past Term','DELETED'"; // skip marked for delete, unapproved and past term

	}

	// checks condition of each item field in record (stats) and deletes if present
	function barf_morsle($morslerec) {
		global $success, $DB;
		$action = 'DELETE';
		$stats = array(
				'password' => $morslerec->password,
				'groupname' => $morslerec->groupname,
				'readfolderid' => $morslerec->readfolderid,
				'writefolderid' => $morslerec->writefolderid
				);
		foreach ($stats as $key=>$stat) {
			if (!is_null($stat)) {
				switch ($key) {
					// sites not deleteable through API yet
					case 'site': // create site
						//				$siteid = sitedelete($shortname, $morslerec, $this->user, $this->domain);
						//				$morslerec->siteid = null;
						//					$writeassigned = sitepermissions($shortname, $morslerec, $groupfullname, $courseid, $this->domain);
						break;
					case 'writefolderid': // delete writeable folder
						$response  = twolegged($this->docs_feed . $morslerec->writefolderid, $this->params, $action);
						break;
					case 'readfolderid': // delete readonly folder
						$response  = twolegged($this->docs_feed . $morslerec->readfolderid, $this->params, $action);
						break;
					case 'groupname': // delete group
						$response = send_request($action, $this->prov_feed . '/' . $morslerec->groupname, $this->headers, null, null, '2.0');
						break;
					case 'password': // delete user account
						$response = send_request($action, $this->prov_feed . '/' . $morslerec->shortname, $this->headers, null, null, '2.0');
						break;
				}
				if ($success) {
					$morslerec->$key = null;
					$updaterec = $DB->update_record('morsle_active', $morslerec);
					$this->log('deleted ' . $key . ' SUCCESS', $morslerec->id);
					return true;
				} else {
					$this->log('deleted ' . $key . ' FAILURE', $morslerec->id);
					return false;
				}
			}
		}
		return true;
	}

	/*
	 * Add components to google for morsle_active record
	* Checks condition of each item field in record and creates if not present
	*/
	function digest_morsle($morslerec) {
		global $success, $DB;
		$groupname = $morslerec->shortname . '-group';
		$groupfullname = $groupname . '@' . $this->domain;
		$sitename = 'Course Site for ' . strtoupper($morslerec->shortname);
		$stats = array(
				'password' => $morslerec->password,
				'groupname' => $morslerec->groupname,
				'readfolderid' => $morslerec->readfolderid,
				'writefolderid' => $morslerec->writefolderid,
				'siteid' => $morslerec->siteid
		);
		foreach ($stats as $key=>$stat) {
			if (is_null($stat)) {
				switch ($key) {
					case 'password': // create user account
						$returnval = $this->useradd($morslerec->shortname);
						break;
					case 'groupname': // add group
						$returnval = $this->groupadd($groupname);
						break;
					case 'readfolderid': // create readonly folder
						$returnval = createcollection($morslerec->shortname . '-read');
						break;
					case 'writefolderid': // create writeable folder
						$returnval = createcollection($morslerec->shortname . '-write');
						break;
					case 'site': // create site
						$returnval = createsite($sitename);
						break;
				}
				if ($success) {
					$morslerec->$key = $returnval;
					$updaterec = $DB->update_record(morsle_active, $morslerec);
					$this->log('added ' . $key . ' SUCCESS', $morslerec->id);
					return true;
				} else {
					$this->log('added ' . $key . ' FAILURE', $morslerec->id);
					return false;
				}
			}
		}
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
	function useradd($shortname) {
		global $CFG, $success;
		require_once($CFG->dirroot.'/google/gauth.php');
		$password = genrandom_password(12);

		// form the xml for the post
		$usercreate =
			'<?xml version="1.0" encoding="UTF-8"?>
			<atom:entry xmlns:atom="http://www.w3.org/2005/Atom"
			xmlns:apps="http://schemas.google.com/apps/2006">
			<atom:category scheme="http://schemas.google.com/g/2005#kind"
			term="http://schemas.google.com/apps/2006#user"/>
			<apps:login userName="' . $shortname . '"
			password="' . $password . '" suspended="false"/>
			<apps:name familyName="' . $shortname . '" givenName="m"/>
			</atom:entry>';
		// Make the request
		$response = send_request('POST', $this->prov_feed, $this->headers, null, $usercreate, '2.0');
		if ($success ) {
			return rc4encrypt($password);
		} else {
			return null;
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
		global $success;
		// form the xml for the post
		$groupcreate =
			'<atom:entry xmlns:atom="http://www.w3.org/2005/Atom" xmlns:apps="http://schemas.google.com/apps/2006" xmlns:gd="http://schemas.google.com/g/2005">
			<apps:property name="groupId" value="' . $groupname . '"></apps:property>
			<apps:property name="groupName" value="' . $groupname . '"></apps:property>
			<apps:property name="description" value="Morsle-created Course Group"></apps:property>
			<apps:property name="emailPermission" value="Member"></apps:property>
			</atom:entry>';

		// Make the request
		$response = send_request('POST', $this->prov_feed, $this->headers, null, $groupcreate, '2.0');
		// check if successful
		if ($success ) {
			return $groupname;
		} else {
			return null;
		}
	}

	/**********  SITE FUNCTIONS ***********************/

	// TODO: should be able to combine these two functions
	/*
	 * creates base site for course name
	*/
	function createsite($title) {
		global $success;
		// form the xml for the post
		$sitecreate =
		'<entry xmlns="http://www.w3.org/2005/Atom" xmlns:sites="http://schemas.google.com/sites/2008">
		<title>' . $title . '</title>
		<summary>Morsle course site for collaboration</summary>
		<sites:theme>microblueprint</sites:theme>
		</entry>';

		// Make the request
		$response  = twolegged($this->site_feed, $this->params, 'POST', $sitecreate, '1.4');
		if ($success ) {
			$feed = simplexml_load_string($response->response);
			return get_href_noentry($feed, 'alternate');
		} else {
			return false;
		}
	}

	/*
	 * creates a portfolio site for a student based on the HPE portfolio template
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
			return null;
		}
	}

	/*
	 * gets a list of portfolios sites to which a particular user (usually a teacher) has access -- not used all that much
	 * mostly for troubleshooting
	*/
	function getportfoliosites() {
		global $success;
		$portfolio = array();

		// Make the request
		$response  = twolegged($this->site_feed, $this->params, 'GET', null, '1.4');
		$portfolionamelen = strlen($this->portfoliobase);
		if ($success ) {
			$feed = simplexml_load_string($response->response);
			foreach ($feed->entry as $entry) {
				if(strpos($entry->title,$this->portfoliobase) === 0) { // means it starts with portfoliobase not a return of false
					$portfolio[] = trim(substr($entry->title, $portfolionamelen,70));
				}
			}
			return $portfolio;
		} else {
			return false;
		}
	}

	/***** SET PERMISSION FUNCTIONS *****/
	/*
	 * adds or deletes members and owners to a group on google domain for the course
	* this maintains "membership" and thereby deletes owners if they're no longer in the course
	* as well as plain members
	* TODO: deal with email aliases
	*/
	function membersmaintain($members) {
		global $success;
		$gmembers = get_group_permissions($this->groupname, $this->domain, $this->authstring);

		// check differences
		$deleted = array_diff_assoc($gmembers, $members);
		$added = sizeof($members) > 200 ? array() : array_diff_key($members, $gmembers);

		// delete first due to aliases
		foreach($deleted as $member=>$permission) {
			$this->base_feed = $this->group_feed . $this->groupname . '/' . $permission . '/' . $member;
			$response = send_request('DELETE', $this->base_feed, $this->authstring, null, null, '2.0');
			if ($success) {
				$this->log("Deleted $member as $permission to $this->groupname", $this->courseid);
			} else {
				$this->log("DELETE FAILED FOR $member as $permission to $this->groupname", $this->courseid);
			}
		}

		// then add
		foreach($added as $member=>$permission) {
			// if owner, make a member first
			if ($permission === 'owner') {
				$permissiondata = group_acl_post($member);
				$this->base_feed = $this->group_feed . $this->groupname . '/member';
				$response = send_request('POST', $this->base_feed, $this->authstring, null, $permissiondata, '2.0');
				if ($success) {
					$this->log("added $member as member to $this->groupname", $this->courseid);
				} else {
					$this->log("ADD FAILED FOR $member as member to $this->groupname", $this->courseid);
				}
			}

			$permissiondata = group_acl_post($member);
			$this->base_feed = $this->group_feed . $this->groupname . '/' . $permission;
			$response = send_request('POST', $this->base_feed, $this->authstring, null, $permissiondata, '2.0');
			if ($success) {
				$this->log("added $member as $permission to $this->groupname", $this->courseid);
			} else {
				$this->log("ADD FAILED FOR $member as $permission to $this->groupname", $this->courseid);
			}
		}
	}


	function set_folderpermissions($readwrite, $members, $folderid) {
		// what does google say we have for permissions on this folder
		if ($gmembers = get_docspermissions($folderid,$this->user)) {
		//        unset($gmembers[$groupfullname]);
		//        $groupadd = true;
			$this->base_feed = $this->id_feed . $folderid . '/acl';
			$batchpermpost = '<?xml version="1.0" encoding="UTF-8"?>
				<feed xmlns="http://www.w3.org/2005/Atom"
			    xmlns:gAcl="http://schemas.google.com/acl/2007"
			    xmlns:batch="http://schemas.google.com/gdata/batch">
				<category scheme="http://schemas.google.com/g/2005#kind"
			    term="http://schemas.google.com/acl/2007#accessRule"/>';
			$foldername = $this->shortname . '-' . $readwrite;

			$deleted = array_diff_assoc($gmembers, $members);
			$added =  array_diff_assoc($members, $gmembers);

			// add new members first in case we need an owner
			foreach($added as $member=>$permission) {
				$batchpermpost .= batchdocpermpost($member, $permission, 'add', $this->base_feed);
			}

			// delete
			foreach($deleted as $member=>$permission) {
				$batchpermpost .= batchdocpermpost($member, $permission, 'delete', $this->base_feed);
			}

			$batchpermpost .= '</feed>';

			// reset base_feed
			$this->base_feed = $this->docs_feed . $folderid . '/acl';
			$response  = twolegged($this->base_feed . '/batch', $this->params, 'POST', $batchpermpost, null, null, null, 'batch');
			$feed = simplexml_load_string($response->response);
			foreach ($feed->entry as $entry) {
				$member = preg_replace("/https(.*?)user%3A/",'',$entry->id);
				if ($entry->title == 'Error') {
					$this->log("ACTION FAILED FOR $member with error: $entry->content for $foldername", $this->courseid);
				} else {
					$this->log("Action succeeded for $member with answer: $entry->title for $foldername", $this->courseid);
				}
			}
/*
 * PREVIOUSLY USED BEFORE BATCH PROCESSING
			// delete first because we may be dealing with aliases
			foreach($deleted as $member=>$permission) {
				$delete_base_feed = $this->base_feed . '/user%3A' . $member;
				$response = twolegged($delete_base_feed, $this->params, 'DELETE');
				if ($response->info['http_code'] == 200) {
					$this->log("Deleted $member from $permission for $foldername", $this->courseid);
				} else {
					$this->log("DELETE FAILED FOR $member as $permission for $foldername", $this->courseid);
				}
			}

			// add new members
			foreach($added as $member=>$permission) {
				// add owners to permissions if they don't already exist
				// TODO: pull this out and combine with other post creation statements
				$permissiondata = acl_post($member, $permission, 'user');
				$response = twolegged($this->base_feed, $this->params, 'POST', $permissiondata);
				if ($response->info['http_code'] == 201) {
					$this->log("Added $member as $permission from $foldername", $this->courseid);
				} else {
					$this->log("ADD FAILED FOR $member as $permission from $foldername", $this->courseid);
				}
			}
	*/
			return true;
		}
		return false;
	}


		/*
		 * write out batch of acls for documents or folders
		 * DON'T USE THIS ANYMORE
		*/
		function batch_doc_acls($permissions) {
			$limit = 50;
			if (sizeof($permissions) > $limit) {
				$chunkperms = array_chunk($permissions, $limit, true);
				for ($i = 0; $i < sizeof($chunkrows); $i++) {
					batch_doc_acls($resourceid, $chunkperms);
				}
			}
			$batchpermpost = '<?xml version="1.0" encoding="UTF-8"?>
				<feed xmlns="http://www.w3.org/2005/Atom"
			    xmlns:gAcl="http://schemas.google.com/acl/2007"
			    xmlns:batch="http://schemas.google.com/gdata/batch">
				<category scheme="http://schemas.google.com/g/2005#kind"
			    term="http://schemas.google.com/acl/2007#accessRule"/>';
			foreach ($permission as $email => $value) {
				$batchpermpost .= batchdocpermpost($email, $value->role, $value->action, $this->base_feed);
			}
			$batchpermpost .= '</feed>';
			$response  = twolegged($this->base_feed . '/batch', $this->params, 'POST', $batchpermpost, null, null, null, 'batch');
		}

	function set_sitepermissions($members) {
		$this->base_feed = $this->site_acl_feed . $this->sitename;

		// what does google say we currently have for permissions?
		if ($gmembers = get_sitepermissions()) {

			// don't process the group acl or the course owner acl
			unset($gmembers[$this->user]);
			unset($gmembers[$this->group]);

			$deleted = array_diff_assoc($gmembers, $members);
			$added =  array_diff_assoc($members, $gmembers);

			// delete first because we may be dealing with aliases
			foreach($deleted as $member=>$permission) {
				$delete_base_feed = $this->base_feed . 'user%3A' . $member;
				$response = twolegged($delete_base_feed, $this->params, 'DELETE', null, '1.4');
				if ($response->info['http_code'] == 200) {
					$this->log("Deleted $member from site for $this->sitename", $this->courseid);
				} else {
					$this->log("DELETE FAILED FOR $member from site for $this->sitename", $this->courseid);
				}
			}
			foreach($added as $member=>$permission) {
				$siteacldata = acl_post($member, $permission, 'user');
				$response = twolegged($this->base_feed, $this->params, 'POST', $siteacldata, '1.4');
				if ($response->info['http_code'] == 201) {
					$this->log("Added $member for site for $this->sitename", $this->courseid);
				} else {
					$this->log("ADD FAILED FOR $member for site for $this->sitename", $this->courseid);
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

	function set_portfoliositepermissions($student) {
		$this->base_feed = $this->site_acl_feed . $this->domain . '/' . $this->portfolioname . '/';
		if ($gmembers = $this->get_sitepermissions()) {

			// temporarily add student to owners to get a proper comparison
//			$this->owners[$student->email] = '';
			$deleted = array_diff_key($gmembers, $this->owners);
			$added =  array_diff_key($this->owners, $gmembers);
//			unset($this->owners[$student->email]);

			// delete first because we may be dealing with aliases
			foreach($deleted as $owner=>$permission) {
				$delete_base_feed = $this->base_feed . 'user%3A' . $owner;
				$response = twolegged($delete_base_feed, $this->params, 'DELETE', null, '1.4');
				if ($response->info['http_code'] == 200) {
					$this->log("Deleted $owner from site for $this->portfolioname", $this->courseid);
				} else {
					$this->log("DELETE FAILED FOR $owner from site for $this->portfolioname", $this->courseid);
				}
			}


			// add owners
			foreach($added as $owner=>$permission) {
				$siteacldata = acl_post($owner, 'owner', 'user');
				$response = twolegged($this->base_feed, $this->params, 'POST', $siteacldata, '1.4');
				if ($response->info['http_code'] == 201) {
					$this->log("Added $owner from site for $student->studentname", $this->courseid);
				} else {
					$this->log("ADD FAILED FOR $owner from site for $student->studentname", $this->courseid);
				}
			}

			// add student as editor
			$siteacldata = acl_post($student->email, 'writer', 'user');
			$response = twolegged($this->base_feed, $this->params, 'POST', $siteacldata, '1.4');
			if ($response->info['http_code'] == 201) {
				$this->log("Added $student->studentname from site for $student->studentname", $this->courseid);
			} else {
				$this->log("ADD FAILED FOR $student->studentname from site for $student->studentname", $this->courseid);
			}
			return true;
		}
		return false;
	}

	function set_calpermissions() {
		// calendar currently needs clientauth because redirects always go to a clientauth site
		$urlowner = str_replace('@', '%40', $owner);
		$service = 'cl';
		$password = rc4decrypt($morslerec->password);
		$auth = clientauth($owner,$password,$service);
		if ($gmembers = get_calendarpermissions($owner,$auth)) {
			$authstring = "Authorization: GoogleLogin auth=" . $auth;
			$this->headers = array($authstring, "GData-Version: 2.0");

			// don't process the domain acl or the course owner acl
			unset($gmembers[$owner]);
			unset($gmembers[$this->domain]);

			$deleted = array_diff_assoc($gmembers, $members);
			$added =  array_diff_assoc($members, $gmembers);

			// delete first because we may be dealing with aliases
			foreach($deleted as $member=>$permission) {
				$urluser = str_replace('@', '%40', $member);
				$base_feed = "https://www.google.com/calendar/feeds/$urlowner/acl/full/user%3A$urluser";
				$response = send_request('DELETE', $base_feed, $authstring, null, null, 2);
				if ($response->info['http_code'] == 201 || $response->info['http_code'] == 200) {
					$this->log("Deleted $member from calendar for $owner", $this->courseid);
				} else {
					$this->log("DELETE FAILED FOR member from calendar for $owner", $this->courseid);
				}
			}
			// add new permissions
			foreach($added as $member=>$permission) {
				$base_feed = "https://www.google.com/calendar/feeds/$urlowner/acl/full";
				$calacldata = cal_acl_post($member, $permission);
				$response = send_request('POST', $base_feed, $authstring, null, $calacldata, 2);
				if ($response->info['http_code'] == 201) {
					$this->log("Added $member for calendar for $owner", $this->courseid);
				} else {
					$this->log("ADD FAILED FOR $member for calendar for $owner", $this->courseid);
				}
			}
			return true;
		}
		return false;
	}

	// calendar currently needs clientauth because redirects always go to a clientauth site
	function get_calendarpermissions() {
		$role = array();
		$owner = str_replace('@', '%40', $owner);
		$base_feed = "https://www.google.com/calendar/feeds/$owner/acl/full";
		$this->headers = array($authstring, "GData-Version: 2.0");
		$response = send_request('GET', $base_feed, $authstring, null, null, 2);
		$permissions = $response->response;
		preg_match_all("/<gAcl:role[^>]+>/", $permissions, $roles);
		preg_match_all("/<gAcl:scope[^>]+>/", $permissions, $scopes);
		$rolestring = 'gCal/2005#';
		$scopestring = 'value=';
		foreach ($scopes[0] as $key=>$value) {
			$scope = substr($value,strpos($value,$scopestring) + strlen($scopestring) + 1, -3);
			if (!empty($scope)) {
				$role[$scope] = substr($roles[0][$key],strpos($roles[0][$key],$rolestring) + strlen($rolestring),-3);
			}
		}
		return $role;
	}

	/*
	 * send back the whole $course->users array
	 * used for hpe_portfolio
	 * TODO: why do we need this to be different than get_roster()?
	 */
	function get_full_roster($courseid, $visible) {
	    $coursecontext = get_context_instance(CONTEXT_COURSE, $courseid);
	    $allroles = get_roles_used_in_context($coursecontext);
		if (!$visible) {
			foreach ($allroles as $key=>$role) {
				if ($role->shortname <> 'editingteacher') {
					unset($allroles[$key]);
				}
			}
		}
	    $roles = array_keys($allroles);
	    $course = new stdClass();
	    $course->users = get_role_users($roles,$coursecontext);
	    foreach ($course->users as $cuser) {
	    	$cuser->role = $allroles[$cuser->roleid]->shortname;
	    }
	    return $course->users;
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

	function get_docs_owner($wdir) {
		global $COURSE, $USER, $deptstr, $userstr;
	    $collections = explode('/',$wdir);
		if (strpos($wdir, $deptstr) !== false) { // departmental account collection
			return strtolower($collections[1] . '@' . $this->domain);
	    } elseif (strpos($wdir, $userstr) !== false) {
			return $USER->email;
	    } else {
	    	return strtolower($COURSE->shortname . '@' . $this->domain);
	    }
	}

	function log($message, $course) {
		add_to_log($course, 'morsle', $message);
	}

}

/*
 * Library parts primarily used by morsle_files
 */


/*
 * @param string wdir - google directory with which we're dealing
 * @param string collectionid - passed by reference which holds the id for the collection to be dealt with
 * @param string owner - passed by reference which holds the account name under which any google actions will take place
 */
function morsle_get_files($wdir, &$collectionid, $owner) {
	global $USER, $COURSE;
	global $userstr, $deptstr;
    $collections = explode('/',$wdir);
	if ($wdir === '/') { // root of morsle files, user and department are prepended in display_dir
	    $files = get_doc_feed($owner, $collectionid); // go get folder contents from Google
	} elseif (strpos($wdir, $deptstr) === false && strpos($wdir, $userstr) === false) { // course collection
		$basecollectionid = sizeof($collections) > 2 ? get_collection($collections[sizeof($collections)-2], $owner) : null; // $basecollectionid = second to last collection in path that is passed
	    $collectionid = get_collection($collections[sizeof($collections)-1], $owner, $basecollectionid); // $collectionid = last collection in path that is passed
		// TODO: send a path to be used to get the doc feed from a nested collection
	    $files = get_doc_feed($owner, $collectionid); // go get folder contents from Google
	} else { // departmental or user account collection
		$basecollectionid = sizeof($collections) > 3 ? get_collection($collections[sizeof($collections)-2], $owner) : null; // $basecollectionid = second to last collection in path that is passed
	    $collectionid = sizeof($collections) > 2 ? get_collection($collections[sizeof($collections)-1], $owner, $basecollectionid) : null; // $collectionid = last collection in path that is passed
		// go get folder contents from Google
	    if ($collectionid == null || $collectionid === '') {
			$collectionid = 'folder%3Aroot';
		}
		// TODO: send a path to be used to get the doc feed from a nested collection
	    $files = get_doc_feed($owner, $collectionid); // go get folder contents from Google
	}
	return $files;
}

function link_to_gdoc($name, $link, $type) {
	global $COURSE, $DB, $CFG, $USER;
	require_once("$CFG->dirroot/mod/url/lib.php");

	//add
	$fromform = new stdClass();
	$newform = new stdClass();
	$mform = new MoodleQuickForm(null, 'POST', 'nothing');
	$module 					= $DB->get_record("modules", array('name' => 'url'));
	$course 					= $COURSE;
    $cw 						= get_course_section(0, $course->id);
	$cm 						= null;

	// fields for mdl_url
	$fromform->course           = $course->id;
	$fromform->name 			= $name;
	$fromform->introformat 		= 0;
	$fromform->introeditor 		= 0;
	$fromform->externalurl      = $link;
	$fromform->display          = 6;
	$fromform->popupwidth		= 1024;
	$fromform->popupheight		= 768;
	$fromform->displayoptions = 'a:2:{s:10:"popupwidth";i:1024;s:11:"popupheight";i:768;}';

	// fields for mdl_course_module
	$fromform->module           = $module->id;
	$fromform->instance 		= '';
	$fromform->section          = 0;  // The section number itself - relative!!! (section column in course_sections)
	$fromform->idnumber 		= null;
	$fromform->score	 		= 0;
	$fromform->indent	 		= 0;
	$fromform->visible	 		= 1;
	$fromform->visibleold 		= 1;
	$fromform->groupmode        = $course->groupmode;
	$fromform->groupingid 		= 0;
	$fromform->groupmembersonly = 0;
	$fromform->completion 		= 0;
	$fromform->completionview	= 0;
	$fromform->completionexpected	= 0;
	$fromform->availablefrom	= 0;
	$fromform->availableuntil	= 0;
	$fromform->showavailability	= 0;
	$fromform->showdescription	= 0;

	// fields for mdl_course_sections
	$fromform->summaryformat	= 0;


	$fromform->modulename 		= clean_param($module->name, PARAM_SAFEDIR);  // For safety
	//	$fromform->add              = 'resource';
//	$fromform->type             = $type == 'dir' ? 'collection' : 'file';
//	$fromform->return           = 0; //must be false if this is an add, go back to course view on cancel
//    $fromform->coursemodule 	= '';
//	$fromform->popup			= 'resizable=1,scrollbars=1,directories=1,location=1,menubar=1,toolbar=1,status=1,width=1024,height=768';

//	require_login($course->id); // needed to setup proper $COURSE

	$context = get_context_instance(CONTEXT_COURSE, $course->id);
	require_capability('moodle/course:manageactivities', $context);

	if (!empty($course->groupmodeforce) or !isset($fromform->groupmode)) {
		$fromform->groupmode = 0; // do not set groupmode
	}

	if (!course_allowed_module($course, $fromform->modulename)) {
		print_error('moduledisable', '', '', $fromform->modulename);
	}

	// first add course_module record because we need the context
	$newcm = new stdClass();
	$newcm->course           = $course->id;
	$newcm->module           = $fromform->module;
	$newcm->instance         = 0; // not known yet, will be updated later (this is similar to restore code)
	$newcm->visible          = $fromform->visible;
	$newcm->groupmode        = $fromform->groupmode;
	$newcm->groupingid       = $fromform->groupingid;
	$newcm->groupmembersonly = $fromform->groupmembersonly;
	$completion = new completion_info($course);
	if ($completion->is_enabled()) {
		$newcm->completion                = $fromform->completion;
		$newcm->completiongradeitemnumber = $fromform->completiongradeitemnumber;
		$newcm->completionview            = $fromform->completionview;
		$newcm->completionexpected        = $fromform->completionexpected;
	}
	if(!empty($CFG->enableavailability)) {
		$newcm->availablefrom             = $fromform->availablefrom;
		$newcm->availableuntil            = $fromform->availableuntil;
		$newcm->showavailability          = $fromform->showavailability;
	}
	if (isset($fromform->showdescription)) {
		$newcm->showdescription = $fromform->showdescription;
	} else {
		$newcm->showdescription = 0;
	}

	if (!$fromform->coursemodule = add_course_module($newcm)) {
		print_error('cannotaddcoursemodule');
	}

	if (plugin_supports('mod', $fromform->modulename, FEATURE_MOD_INTRO, true)) {
		$draftid_editor = file_get_submitted_draft_itemid('introeditor');
		file_prepare_draft_area($draftid_editor, null, null, null, null);
		$fromform->introeditor = array('text'=>'', 'format'=>FORMAT_HTML, 'itemid'=>$draftid_editor); // TODO: add better default
	}

	if (plugin_supports('mod', $fromform->modulename, FEATURE_MOD_INTRO, true)) {
		$introeditor = $fromform->introeditor;
		unset($fromform->introeditor);
		$fromform->intro       = $introeditor['text'];
		$fromform->introformat = $introeditor['format'];
	}


	$addinstancefunction    = $fromform->modulename."_add_instance";
	$updateinstancefunction = $fromform->modulename."_update_instance";

	$returnfromfunc = $addinstancefunction($fromform, $mform);

//	$returnfromfunc = url_add_instance($fromform, $mform);
    if (!$returnfromfunc or !is_number($returnfromfunc)) {
        // undo everything we can
        $modcontext = get_context_instance(CONTEXT_MODULE, $fromform->coursemodule);
        delete_context(CONTEXT_MODULE, $fromform->coursemodule);
        $DB->delete_records('course_modules', array('id'=>$fromform->coursemodule));

        if (!is_number($returnfromfunc)) {
            print_error('invalidfunction', '', course_get_url($course, $cw->section));
        } else {
            print_error('cannotaddnewmodule', '', course_get_url($course, $cw->section), $fromform->modulename);
        }
    }

	$fromform->instance = $returnfromfunc;

    $DB->set_field('course_modules', 'instance', $returnfromfunc, array('id'=>$fromform->coursemodule));


    // update embedded links and save files
    $modcontext = get_context_instance(CONTEXT_MODULE, $fromform->coursemodule);
    if (!empty($introeditor)) {
        $fromform->intro = file_save_draft_area_files($introeditor['itemid'], $modcontext->id,
                                                      'mod_'.$fromform->modulename, 'intro', 0,
                                                      array('subdirs'=>true), $introeditor['text']);
        $DB->set_field($fromform->modulename, 'intro', $fromform->intro, array('id'=>$fromform->instance));
    }

    // course_modules and course_sections each contain a reference
    // to each other, so we have to update one of them twice.
    $sectionid = add_mod_to_section($fromform);

    $DB->set_field('course_modules', 'section', $sectionid, array('id'=>$fromform->coursemodule));

	// make sure visibility is set correctly (in particular in calendar)
	set_coursemodule_visible($fromform->coursemodule, $fromform->visible);

	if (isset($fromform->cmidnumber)) { //label
		// set cm idnumber
		set_coursemodule_idnumber($fromform->coursemodule, $fromform->cmidnumber);
	}


    // Set up conditions
    if ($CFG->enableavailability) {
        condition_info::update_cm_from_form((object)array('id'=>$fromform->coursemodule), $fromform, false);
    }

    $eventname = 'mod_created';

	add_to_log($course->id, "course", "add mod",
			   "../mod/$fromform->modulename/view.php?id=$fromform->coursemodule",
			   "$fromform->modulename $fromform->instance");
	add_to_log($course->id, $fromform->modulename, "add",
			   "view.php?id=$fromform->coursemodule",
			   "$fromform->instance", $fromform->coursemodule);
    // Trigger mod_created/mod_updated event with information about this module.
    $eventdata = new stdClass();
    $eventdata->modulename = $fromform->modulename;
    $eventdata->name       = $fromform->name;
    $eventdata->cmid       = $fromform->coursemodule;
    $eventdata->courseid   = $course->id;
    $eventdata->userid     = $USER->id;
    events_trigger($eventname, $eventdata);

    rebuild_course_cache($course->id);

    return 1;
}

function setfilelist($VARS, $wdir, $owner, $files) {
    global $CFG, $USER, $COURSE;

    $USER->filelist = array();
    $USER->fileop = "";
    $courseid = $COURSE->id;
//	$collection = get_collection($wdir, $owner);
    foreach ($VARS as $key => $val) {
		$USER->filelist[$val] = new stdClass();
    	// pick off the passed parameters that are file params
    	if (substr($key,0,4) == "file") {
    		// look for $val in title of files array members
    		foreach ($files->entry as $file) {
    			if ($file->title == $val) {
    				$ids = explode('/', $file->id);
    				$USER->filelist[$val]->id = $ids[sizeof($ids) - 1];
    				if (is_folder($file)) {
    					$USER->filelist[$val]->link = "$CFG->wwwroot/blocks/morsle/morslefiles.php?courseid=$courseid&wdir=$file->title&id=$COURSE->id&file=$file->title&type=dir";
    					$USER->filelist[$val]->type = 'dir';
    				} else {
			            $USER->filelist[$val]->link = get_href_noentry($file, 'alternate');
    					$USER->filelist[$val]->type = 'file';
    				}
			        break;
    			}
    		}
    	// pick off the passed parameters that are directory params
    	// TODO: don't know what we only deal with directory types here and both types up above?
    	} elseif (substr($key,0,3) == "dir") {
    		foreach ($files->entry as $file) {
    			if ($file->title == $val) {
    				$ids = explode('/', $file->id);
    				$USER->filelist[$val]->id = $ids[sizeof($ids) - 1];
    				$USER->filelist[$val]->link = "$CFG->wwwroot/blocks/morsle/morslefiles.php?courseid=$courseid&wdir=$file->title&id=$COURSE->id&file=$file->title&type=dir";
           			$USER->filelist[$val]->type = 'dir';
   					break;
    			}
    		}
        }
    }
    return;
}


function displaydir ($wdir, $files) {
	//  $wdir == / or /a or /a/b/c/d  etc

//    global $basedir;
    global $courseid, $DB, $OUTPUT;
    global $USER, $CFG, $COURSE;
    global $choose;
    global $deptstr, $userstr;

    $course = $COURSE;
    $user = $USER;

	require_once($CFG->dirroot . '/blocks/morsle/constants.php');

	// Get the sort parameter if there is one
    $sort = optional_param('sort', 1, PARAM_INT);
    $dirlist = array();
    $filelist = array();
    $dirhref = array();
    $filehref = array();
    $courseid = $course->id;
    $coursecontext = get_context_instance(CONTEXT_COURSE, $COURSE->id);

    // always include departmental directory if exists
    // TODO: change to handle cross-listed courses
	$shortname = is_number(substr($course->shortname,0,5)) ? substr($course->shortname, 6) : $course->shortname;
	// SPLIT INTO DEPARTMENTAL CODES
	$dept = explode("-",$shortname);
	$deptpart = defined($dept[0]) ? CONSTANT($dept[0]) : null;
	$deptstr =  $deptpart . $deptstr;
	$deptaccount = strtolower($deptstr);
	// check to see if we even have a departmental account for this department but don't show the departmental collection if we're already in it indicated by $wdir
	// TODO: this needs to change if we eliminate morsle table
	if ($wdir === '/'
//	if (strpos($wdir,$deptstr) === false
//			&& strpos($wdir,$shortname) === false
//			&& strpos($wdir, $userstr) === false
			&& $is_morsle_dept = $DB->get_record('morsle_active',array('shortname' => $deptaccount))
			&& has_capability('moodle/course:update', $coursecontext)) {
		$dirlist['dept'] = new stdClass();
		$dirlist['dept']->title  = $deptstr;
		$dirlist['dept']->updated = date('Y-m-d');
	}

	// only show the user collection if we're in the base folder
	if ($wdir === '/') {
//	if (strpos($wdir, $userstr) === false
//			&& strpos($wdir,$shortname) === false
//			&& strpos($wdir, $deptstr) === false) {
		$dirlist['dir'] = new stdClass();
		$dirlist['dir']->title  = $userstr; // include link to instructor's google docs
		$dirlist['dir']->updated = date('Y-m-d');
	}

	// separate all the files list into directories and files
	foreach ($files->entry as $file) {
	    if (is_folder($file)) {
            $dirlist[] = $file;
	    } else {
            $filelist[] = $file;
	    }
    }

    // setup variables and strings
    $strname = get_string("name", 'block_morsle');
    $strsize = get_string("size");
    $strmodified = get_string("modified");
    $straction = get_string("action");
    $strmakeafolder = get_string("morslemakecollection", 'block_morsle');
    $struploadafile = get_string("uploadafile");
    $strselectall = get_string("selectall");
    $strselectnone = get_string("deselectall");
    $strwithchosenfiles = get_string("withchosenfiles");
    $strmovetoanotherfolder = get_string("movetoanotherfolder");
    $strlinktocourse = get_string("linktocourse", 'block_morsle');
    $strmovefilestohere = get_string("movefilestohere");
    $strdeletefromcollection = get_string("deletefromcollection",'block_morsle');
    $strcreateziparchive = get_string("createziparchive");
    $strrename = get_string("rename");
    $stredit   = get_string("edit");
    $strunzip  = get_string("unzip");
    $strlist   = get_string("list");
    $strrestore= get_string("restore");
    $strchoose = get_string("choose");
    $strfolder = get_string("folder");
    $strfile   = get_string("file");
    $strdownload = get_string("strdownload", 'block_morsle');
    $struploadthisfile = get_string("uploadthisfile");
    $struploadandlinkthisfile = get_string("uploadandlinkthisfile", 'block_morsle');

    $filesize = 'Varies as to type of document';
    $strmaxsize = get_string("maxsize", "", $filesize);
    $strcancel = get_string("cancel");
    $strmodified = get_string("strmodified", 'block_morsle');

    //CLAMP #289 set color and background-color to transparent
	//Kevin Wiliarty 2011-03-08
    $padrename = get_string("rename");
    $padedit   = '<div style="color:transparent; background-color:transparent; display:inline">' . $stredit . '&nbsp;</div>';
    $padunzip  = '<div style="color:transparent; background-color:transparent; display:inline">' . $strunzip . '&nbsp;</div>';
    $padlist   = '<div style="color:transparent; background-color:transparent; display:inline">' . $strlist . '&nbsp;</div>';
    $padrestore= '<div style="color:transparent; background-color:transparent; display:inline">' . $strrestore . '&nbsp;</div>';
    $padchoose = '<div style="color:transparent; background-color:transparent; display:inline">' . $strchoose . '&nbsp;</div>';
    $padfolder = '<div style="color:transparent; background-color:transparent; display:inline">' . $strfolder . '&nbsp;</div>';
    $padfile   = '<div style="color:transparent; background-color:transparent; display:inline">' . $strfile . '&nbsp;</div>';
    $padlink   = '<div style="color:transparent; background-color:transparent; display:inline">' . $strlinktocourse . '&nbsp;</div>';

    // Set sort arguments so that clicking on a column that is already sorted reverses the sort order
    $sortvalues = array(1,2,3);
    foreach ($sortvalues as &$sortvalue) {
	    if ($sortvalue == $sort) {
            $sortvalue = -$sortvalue;
        }
    }

    $upload_max_filesize = get_max_upload_file_size($CFG->maxbytes);

    // beginning of with selected files portion
    echo "<table border=\"0\" cellspacing=\"2\" cellpadding=\"2\" style=\"min-width:900px;margin-left:auto;margin-right:auto\" class=\"files\">";
    echo "<tr>";
    if (!empty($USER->fileop) and ($USER->fileop == "move") and ($USER->filesource <> $wdir)) {
        echo "<td colspan = \"3\" align=\"center\">";

        // move files to other folder form
        echo "<form action=\"morslefiles.php\" method=\"get\">";
        echo ' <input type="hidden" name="choose" value="'.$choose.'" />';
        echo " <input type=\"hidden\" name=\"courseid\" value=\"$courseid\" />";
        echo " <input type=\"hidden\" name=\"wdir\" value=\"$wdir\" />";
        echo " <input type=\"hidden\" name=\"action\" value=\"paste\" />";
        echo " <input type=\"hidden\" name=\"sesskey\" value=\"$USER->sesskey\" />";
        echo " <input align=\"center\" type=\"submit\" value=\"$strmovefilestohere\" />";
        echo "<span> --> <b>$wdir</b></span><br />";
        echo "</td>";
 		echo '<td>';
        echo "</form>";

        // cancel moving form
        echo "<form action=\"morslefiles.php\" method=\"get\" align=\"left\">";
        echo ' <input type="hidden" name="choose" value="'.$choose.'" />';
        echo " <input type=\"hidden\" name=\"courseid\" value=\"$courseid\" />";
        echo " <input type=\"hidden\" name=\"wdir\" value=\"$wdir\" />";
        echo " <input type=\"hidden\" name=\"action\" value=\"cancel\" />";
        echo " <input type=\"submit\" value=\"$strcancel\" style = \"color: red;margin-left:10px\" />";
        echo "</form>";
        echo "</td>";
    } else {
		if (has_capability('moodle/course:update', $coursecontext) || strpos($wdir,'-write')) {
	    	echo '<td colspan = "4"></td>';
	        echo '<td style="background-color:#ffddbb;padding-left:5px" colspan = "1" align="left">';

	        // file upload form
	        // TODO: what if we're in the user or departmental dir?
	        echo "<form enctype=\"multipart/form-data\" method=\"post\" action=\"morslefiles.php\">";
	        echo "<span> $struploadafile ($strmaxsize) --> <b>$wdir</b></span><br />";
	        echo ' <input type="hidden" name="choose" value="'.$choose.'" />';
	        echo " <input type=\"hidden\" name=\"courseid\" value=\"$courseid\" />";
	        echo " <input type=\"hidden\" name=\"wdir\" value=\"$wdir\" />";
	        echo " <input type=\"hidden\" name=\"action\" value=\"upload\" />";
	        echo " <input type=\"hidden\" name=\"sesskey\" value=\"$USER->sesskey\" />";
	        if (!isset($coursebytes)) { $coursebytes = 0; }
	        if (!isset($modbytes)) { $modbytes = 0; }
	        $maxbytes = get_max_upload_file_size($CFG->maxbytes, $coursebytes, $modbytes);
	        $str = '<input type="hidden" name="MAX_FILE_SIZE" value="'. $maxbytes .'" />'."\n";
	        $name = 'userfile';
	        $str .= '<input type="file" size="50" name="'. $name .'" alt="'. $name .'" /><br />'."\n";

	        echo $str;
	        echo " <input type=\"submit\" name=\"save\" value=\"$struploadthisfile\" style = \"color: green;padding-left:5px\" />";
	        echo " <input type=\"submit\" name=\"savelink\" value=\"$struploadandlinkthisfile\" style = \"color: blue;padding-left:5px\" />";
	        echo "</form>";
		    echo '</td>';
	        echo '</tr>';
	        echo '<tr>';
	        echo "<td style = \"max-width:50px; white-space: nowrap\" colspan = \"2\" align=\"left\">";

	        //dummy form - alignment only
	        echo "<form action=\"morslefiles.php\" method=\"get\">";
            echo "<fieldset class=\"invisiblefieldset\">";
            echo " <input type=\"button\" value=\"$strselectall\" onclick=\"checkall();\" style = \"color: green\" />";
            echo " <input type=\"button\" value=\"$strselectnone\" onclick=\"uncheckall();\" style = \"color: red\" />";
            echo "</fieldset>";
            echo "</form>";
	        echo "</td>";
	        echo '<td align="center" colspan = "2">';

	        // makedir form
			// TODO: program to allow this in user and departmental directory
            if (strpos($wdir,$deptstr) === false && strpos($wdir,$userstr) === false) { // not a user or departmental folder
	            echo "<form action=\"morslefiles.php\" method=\"get\">";
		        echo ' <input type="hidden" name="choose" value="'.$choose.'" />';
		        echo " <input type=\"hidden\" name=\"courseid\" value=\"$courseid\" />";
		        echo " <input type=\"hidden\" name=\"wdir\" value=\"$wdir\" />";
		        echo " <input type=\"hidden\" name=\"action\" value=\"makedir\" />";
		        echo " <input type=\"submit\" value=\"$strmakeafolder\" />";
		        echo "</form>";
            }
		    echo '</td>';

		    // cancel button div only if not in root morsle directory
        	echo '<td style="background-color:#ffddbb;padding-left:5px" colspan="1">';
			echo "<form action=\"morslefiles.php\" method=\"get\" align=\"left\">";
	        echo ' <input type="hidden" name="choose" value="'.$choose.'" />';
	        echo " <input type=\"hidden\" name=\"courseid\" value=\"$courseid\" />";
	        echo " <input type=\"hidden\" name=\"wdir\" value=\"$wdir\" />";
	        echo " <input type=\"hidden\" name=\"action\" value=\"cancel\" />";
	        echo " <input type=\"submit\" value=\"$strcancel\" align=\"left\" style = \"color: red\" />";
	        echo "</form>";
		    echo '</td>';
	        echo '</tr>';
		}
	}
    echo "<form action=\"morslefiles.php\" method=\"post\" id=\"dirform\">";
    echo "<div>";
    echo '<input type="hidden" name="choose" value="'.$choose.'" />';
    echo "<tr>";
    echo "<th class=\"header\" scope=\"col\" style = \"max-width : 40px\">";
    echo "<input type=\"hidden\" name=\"courseid\" value=\"$courseid\" />";
    echo '<input type="hidden" name="choose" value="'.$choose.'" />';
    echo "<input type=\"hidden\" name=\"wdir\" value=\"$wdir\" /> ";
    echo "<input type=\"hidden\" name=\"sesskey\" value=\"$USER->sesskey\" />";
//    $options = array ("delete" => "$strdeletefromcollection");
	// only editing teachers can link items to course page
	if (has_capability('moodle/course:update', $coursecontext)) {
        $options['link'] = "$strlinktocourse";
	}
    if (!empty($filelist) || !empty($dirlist)) {

//        echo html_writer::tag('label', "$strwithchosenfiles...", array('for'=>'formactionid'));
//    	echo html_writer::select($options, "$strwithchosenfiles...", '', array(1 => "$strwithchosenfiles..."));
        echo '<div id="noscriptgo" style="display: inline;">';
        echo '<input type="submit" value="'.get_string('go').'" />';
        echo '<script type="text/javascript">'.
               "\n//<![CDATA[\n".
               'document.getElementById("noscriptgo").style.display = "none";'.
               "\n//]]>\n".'</script>';
        echo '</div>';

    }

    echo "</th>";
    echo "<th style=\"padding-left:120px\" class=\"header name\" scope=\"col\"><a href=\"" . qualified_me(). "&sort={$sortvalues[0]}\">$strname</a></th>";
    echo "<th class=\"header date\" scope=\"col\"><a href=\"" . qualified_me(). "&sort={$sortvalues[2]}\">$strmodified</a></th>";
    echo "<th class=\"header commands\" scope=\"col\">$straction</th>";
    echo "</tr>\n";

    // Sort parameter indicates column to sort by, and parity gives the direction
	switch ($sort) {
	    case 1:
	        $sortcmp = 'return strcasecmp($a[0],$b[0]);';
	        break;
	    case -1:
	        $sortcmp = 'return strcasecmp($b[0],$a[0]);';
	        break;
	    case 2:
	        $sortcmp = 'return ($a[1] - $b[1]);';
	        break;
	    case -2:
	        $sortcmp = 'return ($b[1] - $a[1]);';
	        break;
	    case 3:
	        $sortcmp = 'return ($a[2] - $b[2]);';
	        break;
	    case -3:
	        $sortcmp = 'return ($b[2] - $a[2]);';
	        break;
	}

	// Create a 2D array of directories and sort
    $dirdetails = array();
    foreach ($dirlist as $dir) {
        $filename = $dir->title;
        $filedate = docdate($dir);
        $row = array($filename, $filedate);
		array_push($dirdetails, $row);
 		usort($dirdetails, create_function('$a,$b', $sortcmp));
 	}

	// Create a 2D array of files and sort
    $filedetails = array();
    $filetitles = array();
    foreach ($filelist as $key=>$file) {
        $filename = s($file->title);
        $filedate = docdate($file);
		$filetitles[] = $filename;
		$filedetails[$filename] = array($filename, $filedate);
//        $row = array($filename, $filedate);
//		array_push($filedetails, $row);
//		usort($filedetails, create_function('$a,$b', $sortcmp));
	}
	// TODO: fix this hack so we're back to being able to sort
	ksort($filedetails); // sets the locked in sorting to name

	// need this in order to look up the link for the file based on doc title (key)
	if (sizeof($filelist) > 0) {
		$filevalues = array_values($filelist);
		$filelist = array_combine($filetitles, $filevalues);
	}
	$count = 0;
    $countdir = 0;
	$edittext = $padchoose .$padedit . $padunzip . $padlist . $padrestore;

    if (!empty($dirdetails)) {
        foreach ($dirdetails as $dir) {
            echo "<tr class=\"folder\">";

           	$countdir++;
            $filedate = $dir[1];
            $filesafe = rawurlencode($dir[0]);
            // TODO: fix the parent directory
            if ($dir[0] == '..') {
//                $fileurl = rawurlencode(dirname($wdir));
                print_cell();
                // alt attribute intentionally empty to prevent repetition in screen reader
				//CLAMP #289 change padding-left from 10 to 0px
				//Kevin Wiliarty 2011-03-08
                print_cell('left', '<a  style="padding-left:0px" href="morslefiles.php?courseid='.$courseid.'&amp;wdir='.$wdir.'/'.$fileurl.'&amp;choose='.$choose.'"><img src="'.$OUTPUT->pix_url('f/parent.gif').'" class="icon" alt="" />&nbsp;'.get_string('parentfolder').'</a>', 'name');
                print_cell();
                print_cell();
                print_cell();
            } elseif(strpos($dir[0],$deptstr) === false && strpos($dir[0],$userstr) === false) { // not a user or departmental folder
                $filename = $dir[0];
		        foreach ($file->link as $link) {
		            if ($link['rel'] == 'alternate') {
		                $fileurl = $link['href'];
		                break;
		            }
		        }
               	print_cell("center", "<input type=\"checkbox\" name=\"dir$countdir\" value=\"$filename\" />", 'checkbox');
//                print_cell("left", "<a href=\"morslefiles.php?courseid=$courseid&amp;wdir=$wdir/$filesafe&amp;choose=$choose\"><img src=\"$OUTPUT->pix_url('f/folder')\" class=\"icon\" alt=\"$strfolder\" />&nbsp;".$filename."</a>", 'name');
                print_cell('left', '<a href="morslefiles.php?courseid='.$courseid.'&amp;wdir=' . $wdir . '/' . $filesafe .'&amp;choose='.$choose.'"><img src="'.$OUTPUT->pix_url('f/folder').'" class="icon" alt="" />&nbsp;'. $filename .'</a>', 'name');
                print_cell("right", $filedate, 'date');
//                print_cell();
				if (has_capability('moodle/course:update', $coursecontext)) {
                	print_cell("left", "$edittext<a href=\"morslefiles.php?courseid=$courseid&amp;wdir=$wdir&amp;file=$filename&amp;action=link&amp;type=dir&amp;choose=$choose\">$strlinktocourse</a>", 'commands');
				}
            } else { // if departmental account or user collection
            	// TODO: need to determine what $wdir is if we're coming in from one of the course subcollections
//                $fileurl = rawurlencode(dirname($wdir));
				$branchdir = strpos($wdir,'read') !== false || strpos($wdir,'write') !== false  || $wdir === '' ? $filesafe : "$wdir/$filesafe";
            	print_cell("center", "<input type=\"checkbox\" name=\"dir$countdir\" value=\"$filename\" />", 'checkbox');
                // alt attribute intentionally empty to prevent repetition in screen reader
				//CLAMP #289 change padding-left from 10 to 0px
				//Kevin Wiliarty 2011-03-08
                print_cell('left', '<a  style="padding-left:0px" href="morslefiles.php?courseid='.$courseid.'&amp;wdir=' . $branchdir .'&amp;choose='.$choose.'"><img src="'.$OUTPUT->pix_url('f/folder').'" class="icon" alt="" />&nbsp;'. $dir[0] .'</a>', 'name');
                print_cell("right", $filedate, 'date');
//                print_cell();
				if (has_capability('moodle/course:update', $coursecontext)) {
	                print_cell("left", "$edittext<a href=\"morslefiles.php?courseid=$courseid&amp;wdir=$branchdir&amp;file=$filename&amp;action=link&amp;type=dir&amp;choose=$choose\">$strlinktocourse</a>", 'commands');
				}
//              print_cell();
            }

            echo "</tr>";
        }
    }

    $iconchoices = array('excel'=>'download/spreadsheets','powerpoint'=>'download/presentations','word'=>'download/documents',
    		'pdf'=>'application/pdf');
    if (!empty($filedetails)) {
        foreach ($filedetails as $filekey => $file) {

			// positively identify the correct icon regardless of filename extension
        	$exportlink = $filelist[$filekey]->content['src'];
        	$icon = mimeinfo("icon", $filekey);
			if ($icon == 'unknown') {
				foreach ($iconchoices as $key=>$value) {
					if (strpos($exportlink,$value)) {
						$icon = $key;
						break;
					}
				}
			}

            $count++;
            $filename = $filekey;
        	$fileurl = get_href_noentry($filelist[$filekey], 'alternate');
            $fileurlsafe = rawurlencode($fileurl);
            $filedate    = substr(str_replace('Z','',str_replace('T',' ',$filelist[$filekey]->updated)),0,19);
//            $filedate    = date(strtotime($filelist[$filekey]->updated), 'm-d-Y H:M:S');

            $selectfile = trim($fileurl, "/");

            echo "<tr class=\"file\">";

            print_cell("center", "<input type=\"checkbox\" name=\"file$count\" value=\"$filename\" />", 'checkbox');
			//CLAMP #289 change padding-left from 10 to 0px
			//Kevin Wiliarty 2011-03-08
            echo "<td align=\"left\" style=\"white-space:nowrap;padding-left:0px\" class=\"name\">";

            $echovar = '<a href="' . $fileurl . '" target="_blank">
            		<img src="' . $OUTPUT->pix_url("f/$icon") . '" class="icon" alt="' . $strfile . '" />&nbsp;' . htmlspecialchars($filename) . '</a>';
            echo $echovar;
            echo "</td>";

            print_cell("right", $filedate, 'date');
/*
            if ($choose) {
				//CLAMP #289 set background-color to transparent
				//Kevin Wiliarty 2011-03-08
                $edittext = "<strong><a onclick=\"return set_value('$selectfile')\" style=\"background-color:transparent\" href=\"#\">$strchoose</a></strong>&nbsp;";
            } else {
                $edittext =  $padchoose;
            }


            if ($icon == "text.gif" || $icon == "html.gif") {
                $edittext .= "<a href=\"morslefiles.php?courseid=$courseid&amp;wdir=$wdir&amp;file=$fileurl&amp;action=edit&amp;choose=$choose\">$stredit</a>";
            } else {
                $edittext .= $padedit;
            }
            if ($icon == "zip.gif") {
				//CLAMP #289 set background-color to transparent
				//Kevin Wiliarty 2011-03-08
                $edittext .= "<a style=\"background-color:transparent\" href=\"morslefiles.php?courseid=$courseid&amp;wdir=$wdir&amp;file=$fileurl&amp;action=unzip&amp;sesskey=$USER->sesskey&amp;choose=$choose\">$strunzip</a>&nbsp;";
                $edittext .= "<a style=\"background-color:transparent\" href=\"morslefiles.php?courseid=$courseid&amp;wdir=$wdir&amp;file=$fileurl&amp;action=listzip&amp;sesskey=$USER->sesskey&amp;choose=$choose\">$strlist</a> ";
            } else {
                $edittext .= $padunzip;
                $edittext .= $padlist;
            }
			//the first contingency in the test below added by Kevin Wiliarty
			//to address CLAMP #287 2011-03-08
            if ($icon == "zip.gif" and !empty($CFG->backup_version) and has_capability('moodle/site:restore', 		get_context_instance(CONTEXT_COURSE, $courseid))) {
                $edittext .= "<a style=\"background-color:transparent\" href=\"morslefiles.php?courseid=$courseid&amp;wdir=$wdir&amp;file=$filesafe&amp;action=restore&amp;sesskey=$USER->sesskey&amp;choose=$choose\">$strrestore</a> ";
            } else {
                $edittext .= $padrestore;
            }
*/
//            print_cell();
			if (has_capability('moodle/course:update', $coursecontext)) {
	            print_cell("left", "$edittext <a href=\"morslefiles.php?courseid=$courseid&amp;wdir=$wdir&amp;file=$filename&amp;action=link&amp;type=file&amp;choose=$choose\">$strlinktocourse</a>", 'commands');
            }
            print_cell('left', '&nbsp&nbsp<a title="' . strip_tags($strdownload) . ': ' . $filekey . '" href="' .$CFG->wwwroot
                    . '/blocks/morsle/docs_export.php?exportlink=' . s($exportlink) . '&shortname=' . $course->shortname . '&title=' . $filename . '" target="_blank"> Download </a>','commands');
//            print_cell();

            echo "</tr>";
        }
    }
    echo "</div>";
    echo "</form>";
    echo "</table>";
}

function clearfilelist() {
    global $USER;

    $USER->filelist = array ();
    $USER->fileop = "";
}


function docdate($file) {
	// TODO: fix this
	return '';
//    return substr($file->updated,5,2) . '/' . substr($file->updated,8,2) . '/' . substr($file->updated,0,4) . ' - ' . substr($file->updated,11,8);
}

function is_folder($file) {
    return strpos($file->id,'folder');
}

function printfilelist($filelist) {
    global $CFG, $basedir;

    $strfolder = get_string("folder");
    $strfile   = get_string("file");

    foreach ($filelist as $file) {
        if (is_dir($basedir.'/'.$file)) {
            echo '<img src="'. $OUTPUT->pix_url('f/folder') . '" class="icon" alt="'. $strfolder .'" /> '. htmlspecialchars($file) .'<br />';
            $subfilelist = array();
            $currdir = opendir($basedir.'/'.$file);
            while (false !== ($subfile = readdir($currdir))) {
                if ($subfile <> ".." && $subfile <> ".") {
                    $subfilelist[] = $file."/".$subfile;
                }
            }
            printfilelist($subfilelist);

        } else {
            $icon = mimeinfo("icon", $file);
            echo '<img src="'. $OUTPUT->pix_url("f/$icon") .'" class="icon" alt="'. $strfile .'" /> '. htmlspecialchars($file) .'<br />';
        }
    }
}


function print_cell($alignment='center', $text='&nbsp;', $class='') {
    if ($class) {
        $class = ' class="'.$class.'"';
    }
    echo '<td align="'.$alignment.'" style="white-space:nowrap "'.$class.'>'.$text.'</td>';
}

function html_footer() {
	global $COURSE, $OUTPUT;

	echo '</td></tr></table>';

	echo $OUTPUT->footer();
}

function html_header($course, $wdir, $pagetitle="", $formfield=""){
	global $CFG, $ME, $choose, $COURSE, $OUTPUT, $PAGE;

    $coursecontext = get_context_instance(CONTEXT_COURSE, $COURSE->id);
	$navlinks = array();
	// $navlinks[] = array('name' => $course->shortname, 'link' => "../course/view.php?id=$course->id", 'type' => 'misc');

	$strfiles = get_string("morslefiles", 'block_morsle');

	$dirs = explode("/", $wdir);
	$numdirs = count($dirs);
	$link = "";
	if (has_capability('moodle/course:update', $coursecontext)) {
		$navlinks[] = array('name' => $strfiles,
							'link' => $ME."?id=$course->id&amp;wdir=/&amp;choose=$choose",
							'type' => 'misc');
	}

	for ($i=1; $i<$numdirs-1; $i++) {
		$link .= "/".urlencode($dirs[$i]);
		$navlinks[] = array('name' => $dirs[$i],
							'link' => $ME."?id=$course->id&amp;wdir=$link&amp;choose=$choose",
							'type' => 'misc');
	}
	$navlinks[] = array('name' => $dirs[$numdirs-1], 'link' => null, 'type' => 'misc');
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
			echo $OUTPUT->header();
//			print_header($pagetitle, $course->fullname, $navigation,  $formfield);
		}
	}


	echo "<table border=\"0\" style=\"margin-left:auto;margin-right:auto;min-width:100%\" cellspacing=\"3\" cellpadding=\"3\" >";
	echo "<tr>";
	echo "<td colspan=\"2\">";

}

/*
 * generates a twelve character password for new (course) accounts only using characters acceptable to google
* @param $length optional length, defaults to 12 characters long
*/
/*
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
*/
/*
 * @param $var - role to check
* check if role passed is an owner role
*/
function is_owner($var) {
	return in_array($var->role, array('editingteacher','teacher'));
}

/*
 * @param $var - role to check
* check if role passed is an owner role
* TODO: not sure why we need this, what's with sending $var and not the role?
*/
function full_is_owner($var) {
	return in_array($var->role, array('editingteacher','teacher'));
}

/*
 * callback function for array_walk
* @param $var passed by reference, role to be set
* @param $key TODO: why do we need this?
* @param $garray - contains google roles that will be returned based on value of $var
*/
/*
function set_googlerole(&$var,$key, $garray) {
	$var = array_key_exists($var, $garray) ? $garray[$var] : $garray['student'];
}
*/

?>