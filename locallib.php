<?php
global $CFG, $COURSE;
if ($COURSE->id <> 1) {
	$_SESSION['MORSLE_COURSE'] = $COURSE;
}
require_once($CFG->libdir.'/googleapi.php');
require_once("$CFG->dirroot/google/constants.php");
require_once("$CFG->dirroot/google/lib.php");
require_once("$CFG->dirroot/google/oauth.php");
require_once("$CFG->dirroot/google/gauth.php");

class morsle_docs extends google_docs{

    private $google_curl = null;

    public function __construct($google_curl){
        if(is_a($google_curl, 'morsle_oauth_request')){
            $this->google_curl = $google_curl;
            $this->google_curl->add_persistant_header('GData-Version: 3.0');
            $this->path = '';
        }else{
            throw new moodle_exception('Google Curl Request object not given');
        }
    }

    /**
     * Returns a list of files the user has formated for files api
     *
     * @param string $search A search string to do full text search on the documents
     * @return mixed Array of files formated for fileapoi
     */
    #FIXME
    public function get_file_list($search = '', $repo=null){
//		require_once('constants.php');
    	global $CFG, $OUTPUT;
		$url = get_morsle_url($search);
		if (array_key_exists('path', $search)) {
	    	$path = $search['path'] . '/';
	    	unset($search['path']);
	    } else {
	    	$path = null;
	    }
      	foreach ($search as $key=>$param) {
			if ($key === 'q') {
	            $param = urlencode($param);
			}
			$params[$key] = trim($param);
       	}
//       	$url .= '?' . implode_assoc('=', '&', $params);

        $content = twolegged($url, $params, 'GET');
//       	$content = $this->get($url, $params, null);
        $xml = new SimpleXMLElement($content->response);

        $files = array();
        $repolink = "$CFG->wwwroot/repository/repository_ajax.php?action=list&p=";
        foreach($xml->entry as $gdoc){
            $docid  = (string) $gdoc->children('http://schemas.google.com/g/2005')->resourceId;
            list($type, $docid) = explode(':', $docid);
            $title  = (string) $gdoc->title;
            $source = '';
            // FIXME: We're making hard-coded choices about format here.
            // If the repo api can support it, we could let the user
            // chose.
            switch($type){
                case 'folder':
                    break;
            	case 'document':
                    $temptitle = 'temp.doc';
                    break;
                case 'presentation':
                    $temptitle = 'temp.ppt';
                    break;
                case 'spreadsheet':
                    $temptitle = 'temp.xls';
                    break;
                case 'pdf':
                    $temptitle  = 'temp.pdf';
                    break;
                default:
                	$temptitle = $gdoc->title;
            }
            $source = (string) get_href_noentry($gdoc, GDOC_ALT_REL);
			// TODO: get this thumbnail working with the display
            $iconlink = '<img src="' . (string) get_href_noentry($gdoc, GDOC_THUMB_REL) . '" />';
            if(!empty($source)){
            	if ($type == 'folder') {
            		echo null;
	                $files[] =  array(
	                	'title' => $title,
	                    'url' => "{$gdoc->link[0]->attributes()->href}",
	                    'source' => $source,
	                    'date'   => usertime(strtotime($gdoc->updated)),
	                    'children' => array(),
	                    'path' => base64_encode($docid . '|' . $path . $title),
//	                    'page' => base64_encode($docid),
	                    'thumbnail' => (string) $OUTPUT->pix_url('f/folder-64')
	                	);
            	} else {
	                $files[] =  array(
	                	'title' => $title,
	                    'url' => $source,
	                    'source' => $source,
//	                	'url' => "{$gdoc->link[0]->attributes()->href}",
//	                    'source' => "{$gdoc->link[0]->attributes()->href}",
	                    'date'   => usertime(strtotime($gdoc->updated)),
	                    'thumbnail' => (string) $OUTPUT->pix_url(file_extension_icon($temptitle, 64))
		            	);
            	}
            }
        }
        return $files;
    }

    	/**
    	 * Makes an HTTP request to the specified URL
    	 * @param string $http_method The HTTP method (GET, POST, PUT, DELETE)
    	 * @param string $url Full URL of the resource to access
    	 * @param string $auth_header (optional) Authorization header
    	 * @param DOM $contactAtom (optional) DOM document coming from an OAuth setup
    	 * @param string $postData (optional) POST/PUT request body
    	 * @param string $version (optional) if not sent will be set to 3.0
    	 * @param string $content_type (optional) what kind of content is being sent
    	 * @param string $slug (optional) used in determining the revision of a document
    	 * @param boolean $batch is this a batch transmission?
    	 * @return string $returnval body from the server
    	 */
    public function get($url, $params = array(), $options = array(), $version = null) {
//    	function send_request($http_method, $url, $auth_header=null, $contactAtom=null, $postData=null, $version=null, $content_type = null, $slug=null, $batch=null) {\n    		global $success;\n    		$curl = curl_init($url);\n    		$version = $version == null ? 'Gdata-Version: 3.0' : 'Gdata-Version: ' . $version;
/*
    		if (is_null($content_type)) {
    			$content_type = 'Content-Type: application/atom+xml';
    		} else {
    			$content_type = 'Content-Type: ' . $content_type;
    		}
    		$postarray = array($content_type, $auth_header, $version);
    		// change this to be an array of values
    		if(!is_null($postData)) {
    			$length = strlen($postData);
    			$postarray[] = 'Content-Length: ' . s($length);
    		}
    		if (!is_null($slug)) {
    			$postarray[] = 'Slug: ' . $slug;
    		}
*/
    		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    		curl_setopt($curl, CURLOPT_FAILONERROR, false);
    		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
			if ($this->google_curl->header[0]) {
				curl_setopt($curl, CURLOPT_HTTPHEADER, array($this->google_curl->header[0],
					$version));
			}
    		$response = curl_exec($curl);

    		// this usually only happens with calendar and calendar events
/*
    		if (strpos($response, 'gsessionid')) {
    			preg_match("(https://([^\"']+))i",$response,$match);
    			$url = $match[0];
    			curl_close($curl);
    			$response = send_request($http_method, $url, $auth_header, $contactAtom, $postData, '2.0', null, $slug);
    			//	    curl_close($curl);
    			//		$returnval = $response;
    			//	    return $returnval;
    		}
*/
    		$info = curl_getinfo($curl);
    		curl_close($curl);
    		$returnval->response = $response;
    		$returnval->info = $info;
    		if ($returnval->info['http_code'] == 200 || $returnval->info['http_code'] == 201) {
    			$success = true;
    		} else {
    			$success = false;
    		}
    		return $returnval;
    	}
}

class morsle_oauth_request extends google_oauth {
//    protected $token = '';
    private $persistantheaders = array();

    /**
     * Constructor, allows subauth requests using the response from an initial
     * AuthSubRequest or with the subauth long-term token. Note that constructing
     * this object without a valid token will cause an exception to be thrown.
     *
     * @param string $sessiontoken A long-term subauth session token
     * @param string $authtoken A one-time auth token wich is used to upgrade to session token
     * @param mixed  @options Options to pass to the base curl object
     */

    public function __construct($sessiontoken = '', $authtoken = '', $options = array()){
//        $returnurl = new moodle_url('/repository/repository_callback.php',
//            array('callback' => 'yes', 'repo_id' =>$options['repo_id']));
//        unset($options['repo_id']);

	    // Establish an OAuth consumer based on our admin 'credentials'
	    if ( !$CONSUMER_KEY = get_config('morsle','consumer_key')) {
            throw new moodle_exception('Consumer key not set up');
	    }
        if ( !$CONSUMER_SECRET = morsle_decode(get_config('morsle', 'oauthsecretstr'))) {
        	throw new moodle_exception('Consumer secret not set up');

        }
//    	parent::__construct($CONSUMER_KEY, $CONSUMER_SECRET, $returnurl->out(false), morsle_docs::REALM);

	    // set up variables and parameters
	    $type = 'GET';
		$url = get_morsle_url($options);
//		$url = str_replace('default',urlencode($options['xoauth_requestor_id']), $url);
//    	if (array_key_exists('path', $options)) {
//	    	unset($options['path']);
//	    }
	    foreach ($options as $key=>$param) {
    		if ($key === 'q') {
	            $param = urlencode($param);
			}
	    	$params[$key] = $param;
       	}

       	$consumer = new OAuthConsumer($CONSUMER_KEY, $CONSUMER_SECRET, NULL);
	    // Create an Atom entry
//	    $contactAtom = new DOMDocument();
	//    $contactAtom = null;
	    $request = OAuthRequest::from_consumer_and_token($consumer, NULL, $type, $url, $params);
	    // Sign the constructed OAuth request using HMAC-SHA1
	    $request->sign_request(new OAuthSignatureMethod_HMAC_SHA1(), $consumer, NULL);

	    if (!is_null($params)) {
		    $url = $url . '?' . implode_assoc('=', '&', $params);
//		} else {
//			$url = $url;
		}
	    $header_request = $request->to_header();
        $this->setHeader($header_request);
	    return $header_request;

	    // at what point do we actually make the get request from Google -- work backward from there and integrate send_rquest
//	    $response = send_request($request->get_normalized_http_method(), $url, $header_request, $contactAtom, $postdata, $version, $content_type, $slug, $batch);
//	    return $response;
    }

        /**
     * Tests if a subauth token used is valid
     * THIS WHOLE FUNCTION MAY NOT BE USEABLE HERE
     * @return boolean true if token valid
     */
    public function valid_token(){
//        $this->get(google_authsub::VERIFY_TOKEN_URL); // we don't verify an oauth as there's no token

        if($this->info['http_code'] === 200){
            return true;
        }else{
            return false;
        }
    }


    // Must be overridden with the authorization header name
    public static function get_auth_header_name() {
        throw new coding_exception('get_auth_header_name() method needs to be overridden in each subclass of google_auth_request');
    }

    protected function request($url, $options = array()){
//        if($this->token){
//            // Adds authorisation head to a request so that it can be authentcated
//            $this->setHeader('Authorization: '. $this->get_auth_header_name().'"'.$this->token.'"');
//        }

        foreach($this->persistantheaders as $h){
            $this->setHeader($h);
        }

        $ret = parent::request($url, $options);
        // reset headers for next request
        $this->header = array();
        return $ret;
    }

    protected function multi($requests, $options = array()) {
        if($this->token){
            // Adds authorisation head to a request so that it can be authentcated
            $this->setHeader('Authorization: '. $this->get_auth_header_name().'"'.$this->token.'"');
        }

        foreach($this->persistantheaders as $h){
            $this->setHeader($h);
        }

        $ret = parent::multi($requests, $options);
        // reset headers for next request
        $this->header = array();
        return $ret;
    }

    public function get_sessiontoken(){
        return $this->token;
    }

    public function add_persistant_header($header){
        $this->persistantheaders[] = $header;
    }
}

function get_morsle_url(&$search) {
	//TODO: undo this so we can include more search parameters
//	return DOCUMENTFEED_URL;
	if (array_key_exists('q', $search)) {
		if (array_key_exists('folder', $search)) {
			$url = DOCUMENTFEED_URL . '/' . $search['folder'] . '/contents';
			unset($search['folder']);
		} else {
			$url = DOCUMENTFEED_URL;
		}
//	} elseif (array_key_exists('foldersonly', $search)) {
//		$url = FOLDERFEED_URL;
//		unset($search['foldersonly']);
	} elseif (array_key_exists('folder', $search)) {
		$url = DOCUMENTFEED_URL . '/' . $search['folder'] . '/contents';
		unset($search['folder']);
	} else {
		$url = DOCUMENTFEED_URL;
	}
	$url = str_replace('default',urlencode($search['xoauth_requestor_id']), $url);
	return $url;
}

function build_user($user = null) {
	if ( !$CONSUMER_KEY = get_config('morsle','consumer_key')) {
        throw new moodle_exception('Consumer key not set up');
    }
    if ($user === null) {
		return strtolower($_SESSION['MORSLE_COURSE']->shortname) . '@' . $CONSUMER_KEY;
    } else {
    	return strtolower($user . '@' . $CONSUMER_KEY);
    }
}
?>