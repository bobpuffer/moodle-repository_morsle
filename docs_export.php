<?php
/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */
    global $SESSION, $COURSE;
    require_once('../../config.php');
    require_once($CFG->dirroot.'/google/gauth.php');
    require_once($CFG->dirroot . '/lib/filelib.php');
    $exportlink =  required_param('exportlink', PARAM_URL);
    $shortname =  required_param('shortname', PARAM_ALPHANUMEXT);
    $e =  optional_param('e', null, PARAM_ALPHANUMEXT);
    $gd =  optional_param('gd', null, PARAM_ALPHANUMEXT);
    $title =  required_param('title', PARAM_ALPHANUMEXT);

    if (strpos($exportlink,'download/documents') > 0) {
        $exformat = 'doc';
        $exportlink .= '&exportFormat=' . $exformat . '&format=' . $exformat;
        $service = 'wise';
    } elseif (strpos($exportlink,'download/spreadsheets') > 0) {
        $exformat = 'xls';
        $exportlink .= '&exportFormat=' . $exformat . '&format=' . $exformat;
        $service = 'wise';
    } elseif (strpos($exportlink,'download/presentations') > 0) {
        $exformat = 'ppt';
        $exportlink .= '&exportFormat=' . $exformat . '&format=' . $exformat;
        $service = 'wise';
    } else {
    	if (isset($e) && isset($gd)) {
            $service = 'writely';
            $exportlink .= '&e=' . $e . '&gd=' . $gd;
    	} else {
            echo "Unable to export file at this time";
            exit;
        }
    }
	$title .= strpos($title,'.') ? '' : '.' . $exformat;
    $morslerec = get_record('morsle_active','shortname',$shortname);
    $userpassword = rc4decrypt($morslerec->password);
    if ( !$CONSUMER_KEY = get_config('blocks/morsle','consumer_key')) {
    	exit;
    }
    $username = $shortname . '@' . $CONSUMER_KEY;
    // get client authorization
    $auth = clientauth($username, $userpassword, $service);
    $headers = "Authorization: GoogleLogin auth=" . $auth;
    $base_feed = $exportlink;
    $response = send_request('GET', $base_feed, $headers, null, null, '3.0');
    send_file($response->response, $title, 'default' , 1, true, false, $response->info['content_type']);
?>