<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/** File contains Google Service Authentications.
 *  Uses gauth.php
 *  Can be used for authentication of other services as well, such as drive, file, etc 
 *  (see 'get_google_service' function below).
 *  Functions contained in this file:
 *  @function get_google_service: gets google service authentication for the service passed as parameter, 
 *  for example 'calendar' calls the Google_Service_Calendar object.
 *  @function revoke_token: revokes google access token.
 *  @function insert_calendar_event: inserts new event into Google Calendar.
 *  @function delete_calendar_event: deletes existing calendar event from Google Calendar. 
 *  To be called when an event from course calendar has been deleted.
 *  @function delete_all_calendar_events: deletes all events in given Calendar.
 *  @function add_user_permission: adds Calendar Acl permissions for single user.
 *  @function batch_add_user_permissions: adds Calendar Acl permissions using batch requests.
 *  @function get_user_permissions: gets single user permission for given calendar.
 *  @function delete_user_permission: deletes Calendar Acl permissions for single user.
 *  @function batch_delete_user_permissions: deletes Calendar Acl permissions in batch execution form.
 *  @function list_acl_rule: gets all acl rules associated with given calendar.
 *  Above functions can be seen in use in file lib.php
 * 
 */

require_once($CFG->dirroot.'/config.php');
require_once($CFG->dirroot.'/google/google-api-php-client/src/Google/Client.php');
require_once($CFG->dirroot.'/google/google-api-php-client/src/Google/Http/Batch.php');
require_once($CFG->dirroot.'/google/google-api-php-client/src/Google/Service/Calendar.php');
require_once($CFG->dirroot.'/google/gauth.php');
class morsle_google_auth {

    //creates google service object for user based on service specified
    public function __construct($user_name, $service_name) {
	$this->user_name = $user_name;
	$this->service_name = $service_name;
        $this->client = new Google_Client();

	//authentication credentials
	$this->client_id = '1016393342084-dqql4goj9s0l402sbf4dtnoq2tsk0hp8.apps.googleusercontent.com';
	$this->service_account_name = '1016393342084-dqql4goj9s0l402sbf4dtnoq2tsk0hp8@developer.gserviceaccount.com';
	//authentication scopes
	$this->client->addScope('https://www.googleapis.com/auth/calendar');
        $this->client->addScope('https://www.googleapis.com/auth/drive');
        $this->client->addScope("https://www.googleapis.com/auth/drive.file");
        $this->client->addScope('https://spreadsheets.google.com/feeds');
	$this->client->addScope('https://www.googleapis.com/auth/admin.directory.user');
        $this->client->addScope('https://www.googleapis.com/auth/admin.directory.group');

	//create Google_Service_ object (handle authentication for $service_name given)
	$this->get_google_service($this->service_name);
    }
	
	 /** Creates Google_Service object based on the kind of service specified 
	 *   and performs authentication.
	 *   @param $service : service for which we want Authentication 
	 *   Returns token which can be used to make service-oriented calls to google
	 */
    function get_google_service($service) {
	switch($service) {
	    case 'calendar':
		$execute_service = "https://www.googleapis.com/auth/calendar";
		$this->service = new Google_Service_Calendar($this->client);
       		$this->auth = service_account($this->client, $this->client_id, $this->service_account_name, $this->user_name, $execute_service);
       		$this->revoke_token();
       		$this->auth = service_account($this->client, $this->client_id, $this->service_account_name, $this->user_name, $execute_service);
		$this->update_timezone();
  	    break;
 	    case 'drive':
		$execute_service = "https://www.googleapis.com/auth/drive";
		$this->service = new Google_Service_Drive($this->client);
      		$this->auth = service_account($this->client, $this->client_id, $this->service_account_name, $this->user_name, $execute_service);
       		$this->revoke_token();
	        $this->auth = service_account($this->client, $this->client_id, $this->service_account_name, $this->user_name, $execute_service);
	    break;
	    case 'file':
		$execute_service = "https://www.googleapis.com/auth/drive.file";
		$this->service = new Google_Service_Drive_DriveFile($this->client);
//       		$this->auth = service_account($this->client, $this->client_id, $this->service_account_name, $this->user_name, $execute_service);
       		$this->revoke_token();
	        $this->auth = service_account($this->client, $this->client_id, $this->service_account_name, $this->user_name, $execute_service);
	    break;
	    case 'user':
		$execute_service = "https://www.googleapis.com/auth/directory.user";
		$this->service = new Google_Service_Directory($this->client);
		$this->user = new Google_Service_Directory_User();
                $this->name = new Google_Service_Directory_UserName();
                $this->auth = service_account($this->client, $this->client_id, $this->service_account_name, $this->user_name, $execute_service);
                $this->revoke_token();
                $this->auth = service_account($this->client, $this->client_id, $this->service_account_name, $this->user_name, $execute_service);
	    break;
	    case 'group':
		$execute_service = "https://www.googleapis.com/auth/directory.group";
		$this->service = new Google_Service_Directory($this->client);
		$this->group = new Google_Service_Directory_Group();
                $this->auth = service_account($this->client, $this->client_id, $this->service_account_name, $this->user_name, $execute_service);
                $this->revoke_token();
                $this->auth = service_account($this->client, $this->client_id, $this->service_account_name, $this->user_name, $execute_service);
	    break;
		//default:

	}
    }

    /** Revokes Google access token (mostly called before getting new token for new call to 
     *  Google API in order to avoid having more than one token)
     *
     */
    function revoke_token() {
	$this->client->revokeToken($this->auth->access_token);
      	unset($this->client->auth);
      	unset($_SESSION['service_token']);
    }

		/* Google Calendar functions*/


		/** Overrides google calendar default timezone settings. 
		  * Need this to synchronize event and calendar timezones.
		  */
	function update_timezone(){
	$calendar = $this->service->calendars->get($this->user_name);
	$calendar->setTimeZone('America/Chicago');
	return $this->service->calendars->update($this->user_name,$calendar);
    }

	/** Inserts Course Calendar event into Google Calendar.
	 *  @param $event : should be transformed into Google_Calendar-friendly format
	 *  before calling function.
	 *  Note: course members will never be added as Event attendees. 
	 *  This means users will never receive email notifications about upcoming events.
	 */

    function insert_calendar_event($calendarId, $event) { 
	$insert_calendar_event = $this->service->events->insert($calendarId, $event);
	return $insert_calendar_event;
    }

	/** Deletes google calendar events 
	 *  @param $calendarId : Google Calendar id
	 *  @param $eventId : id of the Google Calendar Event to be deleted.
	 *  Note : $eventId should be obtained before calling this function
	 * 
	 */
    function delete_calendar_event($calendarId, $eventId) {
	try {
	    $this->service->events->delete($calendarId, $eventId);
	} catch(Exception $e){
	    print "An Error occured: " . $e->getMessage();
	}
    }

	//deletes all events in calendar (probably most useful in testing, not )	
    function delete_all_calendar_events($calendarId){
		$events = $this->service->calendars->clear($calendarId);
    }

	/** Sets Google Calendar permission (Acl rule) for single user based on the user's role in course
	 *  @param $calendarId: Calendar to which we want to add Acl rules
	 *  @param $userEmail: user to add to Calendar's Acl
	 *  @param $role: user's role, e.g 'writer', 'reader' or 'owner'
	 *  @param $type: type of scope, e.g 'user', 'group', etc
	 *  Uses google's Access Control List (Acl)
	 * 
	 */	
    function add_user_permission($calendarId, $userEmail, $role, $type="user") {
	$rule = new Google_Service_Calendar_AclRule();
	$scope = new Google_Service_Calendar_AclRuleScope();

	$scope->setType($type);
	$scope->setValue($userEmail);
	$rule->setScope($scope);
	$rule->setRole($role);
	return $this->service->acl->insert($calendarId, $rule);
    }

	/** Batch version of function to add user calendar permissions based on user's role in course
	 *  Uses Batch Execution in addition to google's Access Control List (Acl)
	 *  @param $calendarId: calendar to which we want to add batch user permissions
	 *  @param $value: should be an array of user emails and their access levels e.g ('student'=>'reader')
	 * 
	 */
    function batch_add_user_permissions($calendarId, $value=array()) {
	$this->client->setUseBatch(true); //enable batch use
	$batch = new Google_Http_Batch($this->client); //Http batch object
	foreach ($value as $user=>$sharePermissions) {
		try {
		    $createdRule = $this->add_user_permission($calendarId, $user, $role = $sharePermissions);
		    $batch->add($createdRule, $user);
		} catch (Exception $e) {
		    print "An error occurred: " . $e->getMessage();
		} 
	}
	$batch->execute();
	$this->client->setUseBatch(false);
	return true;	
    }

	/** sets up generic batch execution, 
	 *  might never actually need to use this
	 *  @param $createdRule: 
	 *  @param $user:
	 * 
	 */
    function set_up_batch($createdRule, $user) {
	$this->client->setUseBatch(true);
	$batch = new Google_Http_Batch($this->client);
	$batch->add($createdRule, $user);
	$batch->execute();
	$this->client->setUseBatch(false);
    }

	/** lists all acl rules associated with given $calendarId.
	 *  Probably more useful for debugging purposes than production.
	 *  @param $calendarId: calendar whose Acl we would like to access
	 * 
	 */
    function list_acl_rules($calendarId) {
	$acl = $this->service->acl->listAcl($calendarId);
	return $acl;
    }

	/** Gets user permission (calendar Acl rule) based on calendarId and ruleId
	 *  @param $calendarId: Calendar whose Acl rule we're trying to obtain
	 *  @param $ruleId: Acl rule we would like to acccess, has the form 'user:some-email@some-domain.com'.
	 *  returns user acl rule
	 */
    function get_user_permissions($calendarId, $ruleId) {
	$rule = $this->service->acl->get($calendarId, $ruleId);		
	return $rule;
    }

	/** Deletes single user's access rights (Acl rule) from Access Control List(Acl)
	 *  @param $calendarId: the calendar that the user currently has access to 
	 *  @param $email_to_delete: user email whose access to calendar we want to delete
	 * 
	 */
    function delete_user_permission($calendarId, $email_to_delete) {
	try {
	    $createdRule = $this->service->acl->delete($calendarId, 'user:'.$email_to_delete);
	} catch (Exception $e) {
	    echo $e->getMessage();
	}
    }
   
        /** Batch version of function to delete user calendar permissions
         *  Uses Batch Execution in addition to google's Access Control List (Acl)
         *  @param $calendarId: calendar that the users currently have access to
         *  @param $ruleIds: should be an array of user emails and their access levels, 
	 *  for example 'some-email@domain.com'=>'reader'. We want to delete these permissions.
         *  Calls 'delete_user_permission' function
         */ 
    function batch_delete_user_permissions($calendarId, $ruleIds=array()) {
	$this->client->setUseBatch(true); //enable batch use
        $batch = new Google_Http_Batch($this->client); //Http batch object
	foreach ($ruleIds as $user=>$sharePermissions) {
	    try {
		$createdRule = $this->delete_user_permission($calendarId, $user);
		$batch->add($createdRule, $user);
	    } catch (Exception $e){
		print "An error occurred: " . $e->getMessage();
	    }
	}
	$batch->execute();
	$this->client->setUseBatch(false);
	return true;
    }

}
?>

