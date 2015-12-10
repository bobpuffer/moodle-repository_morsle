<?php
/* 
 * Author: Bob Puffer 
 * Process calendar events from mdl_event to mdl_morsle_event
 * and, in turn, from mdl_morsle_event to Google calendar
 */

// wherever you choose to place these two libraries -- I didn't break them out
require_once($CFG->dirroot.'/google/lib.php'); 
require_once($CFG->dirroot.'/google/gauth.php');

$service = 'cl';
// need to store the $CONSUMER_KEY (domain) and $CONSUMER_SECRET (our domain API key, absolutely secret) somewhere for retrieval
if ( !$CONSUMER_KEY = get_config('blocks/morsle','consumer_key')) {
    exit;
}
$curtime = time();

// at this point you need to query to get all the events that have been deleted from Reason and need to be deleted from Google
// your record in Reason must store the Google calendar event id (noted below as $event->googleid)
$eventsql = '<SQL STATEMEMT>';
$deleted = get_records_sql($eventsql); // probably don't have this function but something similar

// ACTUALLY BECAUSE OF THE WAY THIS IS HANDLED YOU DON'T NEED AN ADMIN ACCOUNT, JUST THE CREDENTIALS FOR THE USER WHOSE ACCOUNT THE CALENDAR IS TIED TO
// THIS ALL ASSUMES THE CALENDAR IS THE PRIMARY CALENDAR FOR THE USER SPECICIFIED
$owner = '<AN ADMINISTRATIVE ACCOUNT EMAIL ADDRESS>';
$calowner = str_replace('@','%40',$owner); // url encoded
$password = '<AN ADMINISTRATIVE ACCOUNT PASSWORD>';
$auth = clientauth($owner,$password,$service);
$authstring = "Authorization: GoogleLogin auth=" . $auth;
$headers = array($authstring, "GData-Version: 2.0");
foreach ($deleted as $event) {
	if ($event->courseid == $coursekey) {
		// TODO: this needs to be the edit link for the event
		$base_feed = "https://www.google.com/calendar/feeds/$calowner/private/full/$event->googleid";
		$response = send_request('DELETE', $base_feed, $authstring, null, null, '2.0');
		// only deleted from morsle_event if successfully deleted from google
		if ($response->info['http_code'] == 200) {
			$feed = simplexml_load_string($response->response);
			// delete your records from REASON
			$success = delete_records('morsle_event','eventid', $event->eventid);
			// log the action
			add_to_log($coursekey, 'Morsle', "Added", null, "$event->name added to calendar eventtime = $eventtime");
		}
	}
}	

// get all the records from events that need to be added to google
$sql = 'SQL STATEMENT>';
$added = get_records_sql($sql); // probably don't have this function but something similar

foreach ($added as $event) {
	if ($event->courseid == $coursekey) {
		$event->name = str_replace('&','and',$event->name);
		$event->description = str_replace('&','and',strip_tags($event->description));
		$caleventdata = get_cal_event_post($event);
		$base_feed = "https://www.google.com/calendar/feeds/$calowner/private/full";
		$response = send_request('POST', $base_feed, $authstring, null, $caleventdata, '2');
		if ($response->info['http_code'] == 201) {
			$feed = simplexml_load_string($response->response);
			unset($event->id);
			$event->description = addslashes($event->description);
			$event->name = addslashes($event->name);
			$event->eventid = $key;
			$event->googleid = substr($feed->id,strpos($feed->id,'events/') + 7,50);	
			$eventtime = date(DATE_ATOM,$event->timestart);
			$success = insert_record('morsle_event',$event);
			if ($success) {
				add_to_log($coursekey, 'Morsle', "Added", null, "$event->name added to calendar eventtime = $eventtime");
			} else {
				add_to_log($coursekey, 'Morsle', "NOT ADDED", null, "$event->name NOT ADDED to calendar eventtime = $eventtime");
			}
		}
		unset($added[$key]);
	}
}			

// get all the records from events that need to be changed on google (determined from timestamp and last update time)
$sql = 'SQL STATEMENT>';
$changed = get_records_sql($sql); // probably don't have this function but something similar

foreach ($changed as $event) {
	if ($event->courseid == $coursekey) {
		$event->name = str_replace('&','and',$event->name);
		$event->description = str_replace('&','and',strip_tags($event->description));
		$caleventdata = get_cal_event_post($event);
		$base_feed = "https://www.google.com/calendar/feeds/$calowner/private/full/events/$event->googleid";
		$response = send_request('PUT', $base_feed, $authstring, null, $caleventdata, '2');
		if ($response->info['http_code'] == 201) {
			$feed = simplexml_load_string($response->response);
			unset($event->id);
			$event->description = addslashes($event->description);
			$event->name = addslashes($event->name);
			$event->eventid = $key;
			$event->googleid = substr($feed->id,strpos($feed->id,'events/') + 7,50);	
			$eventtime = date(DATE_ATOM,$event->timestart);
			$success = insert_record('morsle_event',$event);
			if ($success) {
				add_to_log($coursekey, 'Morsle', "Added", null, "$event->name added to calendar eventtime = $eventtime");
			} else {
				add_to_log($coursekey, 'Morsle', "NOT ADDED", null, "$event->name NOT ADDED to calendar eventtime = $eventtime");
			}
		}
		unset($added[$key]);
	}
}			
?>
