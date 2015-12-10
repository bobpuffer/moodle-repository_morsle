<?php // $Id$

//  Manage all uploaded files in a course file area

//  All the Moodle-specific stuff is in this top section
//  Configuration and access control occurs here.
//  Must define:  USER, basedir, baseweb, html_header and html_footer
//  USER is a persistent variable using sessions

	global $COURSE, $USER, $DB, $OUTPUT;
    require('../../config.php');
    require_once($CFG->libdir . '/filelib.php');
    require_once($CFG->libdir . '/adminlib.php');
    require_once($CFG->dirroot.'/google/gauth.php');
    require_once($CFG->dirroot.'/google/lib.php');
    require_once($CFG->dirroot.'/mod/resource/lib.php');
    require_once($CFG->dirroot.'/blocks/morsle/morslelib.php');
    ?>
<script type="text/javascript">
//<![CDATA[
    function mycheckall() {
      var el = document.getElementsByTagName('input');
      for(var i=0; i<el.length; i++) {
        if(el[i].type == 'checkbox') {
          el[i].checked = exby.checked? true:false;
        }
      }
    }
//]]>
</script>

<?php
    $courseid      = required_param('courseid', PARAM_INT);
    $file    = optional_param('file', '', PARAM_PATH);
    $action  = optional_param('action', '', PARAM_ACTION);
    $name    = optional_param('name', '', PARAM_FILE);
    $oldname = optional_param('oldname', '', PARAM_FILE);
    $choose  = optional_param('choose', '', PARAM_FILE); //in fact it is always 'formname.inputname'
    $userfile= optional_param('userfile','',PARAM_FILE);
    $save    = optional_param('save', 0, PARAM_BOOL);
    $savelink    = optional_param('savelink', 0, PARAM_BOOL);
    $text    = optional_param('text', '', PARAM_RAW);
    $confirm = optional_param('confirm', 0, PARAM_BOOL);
    $type    = optional_param('type', '', PARAM_ALPHA);
    //    $shortname =  required_param('shortname');
//    $COURSE->shortname = $shortname;
//    $COURSE->id = required_param('courseid');
    $parentfolderid =  optional_param('parentfolderid', '', PARAM_ALPHANUMEXT);

    $wdir    = optional_param('wdir', '/', PARAM_PATH);

    $context= get_context_instance(CONTEXT_COURSE, $courseid);
    $morsle = new morsle();
    if (! $course = $DB->get_record("course", array('id' => $courseid))) {
        error("That's an invalid course id");
    }
    require_login($course);

    $returnurl = new moodle_url("$CFG->wwwroot/blocks/morsle/morslefiles.php", array('courseid' => $courseid, 'wdir' => $wdir, 'file' => $file, 'action' => $action, 'choose' => $choose));
    $morslefilestr = get_string('morslefiles', "block_morsle");
    $PAGE->set_context($context);
	$PAGE->set_course($course);
    $PAGE->set_pagelayout('report');

    $PAGE->set_url("$CFG->wwwroot/blocks/morsle/morslefiles.php", array('courseid' => $courseid, 'wdir' => $wdir, 'file' => $file, 'action' => $action, 'choose' => $choose));

    $PAGE->set_title($course->shortname.': '. $morslefilestr);

    $PAGE->set_heading($course->fullname);
    $navitems = $PAGE->navbar->get_items();



    $courseowner = strtolower($COURSE->shortname . '@' . $morsle->domain); // constant for the course owner account

    //    $owner = $courseowner; // defaults to course ownership, could be changed by morsle_get_files()

    $userstr = get_string('useraccountstring','block_morsle') . $USER->email;

    $deptstr = get_string('departmentaccountstring', 'block_morsle');

    $owner = $morsle->get_docs_owner($wdir);

    // determine the folder id needed for all queries

    if ($wdir == '/') {

    	$collectionid = 'folder%3Aroot';

    } else {

    	$collections = explode('/',$wdir);

    	$basecollectionid = null;

    	foreach ($collections as $collection) { // cycle through the path so our ultimate collection is a subcollection of its parent

    		if ($collection !== '') {

    			$collectionid = get_collection($collection, $owner, $basecollectionid);

    			$basecollectionid = $collectionid; // just for cycling through the collections, not used again

    		}

    	}

    }


    if ($wdir === '/') {
	    $PAGE->navbar->add($morslefilestr, $returnurl);
    } else {
    	$PAGE->navbar->add($collection, $returnurl);
    }

    echo $OUTPUT->header();


    // get read-only folderid because we'll use this a lot and its easier than trying to keep getting it from Google
    $readfolderid = 'folder%3A' . $DB->get_field('morsle_active', 'readfolderid', array('courseid' => $COURSE->id));

	$pagetitle = strip_tags($course->shortname.': '. $wdir);
	$base_feed = $morsle->docs_feed;
//	$base_feed = 'https://docs.google.com/feeds/default/private/full';
    $baseweb = $CFG->wwwroot;

//  End of configuration and access control

    switch ($action) {
    	case 'upload and link':
        case "upload":
//            html_header($course, $wdir);
            require_once($CFG->dirroot.'/google/googleuploadlib.php');

            if (($save || $savelink) && confirm_sesskey()) { // either upload or upload and link
		    	$today = date(DATE_RFC3339,time()-(60*10));
            	$course->maxbytes = 0;  // We are ignoring course limits
                $um = new upload_manager('userfile',false,false,$course,false,0);
				$res_med_link = get_feed_edit_link($owner);
				if ($um->process_file_uploads($wdir)) {

                	// send the file contents up to google
                	$filecontents = file_get_contents($_FILES['userfile']['tmp_name']);
			        $filetype = mimeinfo('type', strtolower($_FILES['userfile']['name']));
                	$success = send_file_togoogle($_FILES['userfile']['name'], $filecontents, $owner, $filetype, $res_med_link, $collectionid);
					notify(get_string('uploadedfile'));
                }
                // um will take care of error reporting.

            }
            if ($save) { // only upload
                // get ready to display the list of resources again
			    $files = morsle_get_files($wdir, $collectionid, $owner);
				displaydir($wdir, $files);
//	            html_footer();
	            break;
            }
        case "link":
			if ($savelink) {
				$_POST['file1'] = $_FILES['userfile']['name'];

			}
        	if (empty($_POST)) {
	        	$_POST['file1'] = $file;
        	}
		    $files = morsle_get_files($wdir, $collectionid, $owner);
        	setfilelist($_POST, $wdir, $owner, $files);
//        	html_header($course, $wdir);
			if (!empty($USER->filelist)) {
				foreach ($USER->filelist as $name=>$value) {
					if (!link_to_gdoc($name, $value->link, $value->type)) {
						print_error("gdocslinkerror","error");
					} elseif (strpos($wdir, $deptstr) !== false || strpos($wdir, $userstr) !== false) {
						// need to share resource with course user account
						$acl_base_feed = 'https://docs.google.com/feeds/default/private/full/' . $value->id . '/acl';
						assign_file_permissions($courseowner, 'writer', $owner, $acl_base_feed);
						// need to place anything from departmental or instructors resources into the read-only collection so students can see them
						add_file_tocollection($base_feed, $readfolderid, $value->id, $courseowner);
					}
				}
			}
			notify(get_string('linkedfile', 'block_morsle'));


			// get ready to display the list of resources again
			clearfilelist();
		    $files = morsle_get_files($wdir, $collectionid, $owner);
			displaydir($wdir, $files);
//			html_footer();
        	break;
        case "makedir":
            if (($name != '') && confirm_sesskey()) {
            	$collections = explode("\r\n",$_POST['name']);
            	foreach ($collections as $name) {
					if ($name !== '') {
	            		createcollection($name,$owner, $collectionid);
					}
            	}

            	// go get folder contents from Google and display
//	            html_header($course, $wdir);
		    	$files = morsle_get_files($wdir, $collectionid, $owner);
	            displaydir($wdir, $files);
            } else {
            	// display the input form for the new collection name
                $strcreate = get_string("create");
                $strcancel = get_string("cancel");
                $strcreatefolder = get_string("createfolder", "block_morsle", $wdir);
//                html_header($course, $wdir, "form.name");
                echo "<p>$strcreatefolder:</p>";

                //TODO: replace with mform
                echo "<table><tr><td>";
                echo "<form action=\"morslefiles.php\" method=\"post\">";
                echo "<fieldset class=\"invisiblefieldset\">";
                echo ' <input type="hidden" name="choose" value="'.$choose.'" />';
                echo " <input type=\"hidden\" name=\"courseid\" value=\"$courseid\" />";
                echo " <input type=\"hidden\" name=\"wdir\" value=\"$wdir\" />";
                echo " <input type=\"hidden\" name=\"action\" value=\"makedir\" />";
                echo " <textarea cols=60 rows=10 name=\"name\"></textarea>";
                echo " <input type=\"hidden\" name=\"sesskey\" value=\"$USER->sesskey\" />";
                echo " <input type=\"submit\" value=\"$strcreate\" />";
                echo "</fieldset>";
                echo "</form>";
                echo "</td><td>";
                echo "<form action=\"morslefiles.php\" method=\"get\">";
                echo "<div>";
                echo ' <input type="hidden" name="choose" value="'.$choose.'" />';
                echo " <input type=\"hidden\" name=\"courseid\" value=\"$courseid\" />";
                echo " <input type=\"hidden\" name=\"wdir\" value=\"$wdir\" />";
                echo " <input type=\"hidden\" name=\"action\" value=\"cancel\" />";
                echo " <input type=\"submit\" value=\"$strcancel\" />";
                echo "</div>";
                echo "</form>";
                echo "</td></tr></table>";
            }
//            html_footer();
            break;
    	default:
 //           html_header($course, $wdir, $pagetitle);
		    $files = morsle_get_files($wdir, $collectionid, $owner);
            displaydir($wdir, $files);
//            html_footer();
            break;
}
echo $OUTPUT->footer();

/*
        case "delete":
            if ($confirm and confirm_sesskey()) {
                html_header($course, $wdir);
                if (!empty($USER->filelist)) {
                    foreach ($USER->filelist as $file) {
                        $fullfile = $basedir.'/'.$file;
                        if (! fulldelete($fullfile)) {
                            echo "<br />Error: Could not delete: $fullfile";
                        }
                    }
                }
                clearfilelist();
                displaydir($wdir);
                html_footer();

            } else {
                html_header($course, $wdir);

                if (setfilelist($_POST)) {
                    notify(get_string('deletecheckwarning').':');
                    print_simple_box_start("center");
                    printfilelist($USER->filelist);
                    print_simple_box_end();
                    echo "<br />";

                    require_once($CFG->dirroot.'/mod/resource/lib.php');
                    $block = resource_delete_warning($course, $USER->filelist);

                    if (empty($CFG->resource_blockdeletingfile) or $block == '') {
                        $optionsyes = array('courseid'=>$courseid, 'wdir'=>$wdir, 'action'=>'delete', 'confirm'=>1, 'sesskey'=>sesskey(), 'choose'=>$choose);
                        $optionsno  = array('courseid'=>$courseid, 'wdir'=>$wdir, 'action'=>'cancel', 'choose'=>$choose);
                        notice_yesno (get_string('deletecheckfiles'), 'morslefiles.php', 'morslefiles.php', $optionsyes, $optionsno, 'post', 'get');
                    } else {

                        notify(get_string('warningblockingdelete', 'resource'));
                        $options  = array('courseid'=>$courseid, 'wdir'=>$wdir, 'action'=>'cancel', 'choose'=>$choose);
                        print_continue("morslefiles.php?courseid=$courseid&amp;wdir=$wdir&amp;action=cancel&amp;choose=$choose");
                    }
                } else {
                    displaydir($wdir);
                }
                html_footer();
            }
            break;

        case "move":
            html_header($course, $wdir);
            if (($count = setfilelist($_POST)) and confirm_sesskey()) {
                $USER->fileop     = $action;
                $USER->filesource = $wdir;
                echo "<p class=\"centerpara\">";
                print_string("selectednowmove", "moodle", $count);
                echo "</p>";
            }
            displaydir($wdir);
            html_footer();
            break;

        case "paste":
            html_header($course, $wdir);
            if (isset($USER->fileop) and ($USER->fileop == "move") and confirm_sesskey()) {
                foreach ($USER->filelist as $file) {
                    $shortfile = basename($file);
                    $oldfile = $basedir.'/'.$file;
                    $newfile = $basedir.$wdir."/".$shortfile;
                    if (!rename($oldfile, $newfile)) {
                        echo "<p>Error: $shortfile not moved</p>";
                    }
                }
            }
            clearfilelist();
            displaydir($wdir);
            html_footer();
            break;

        case "rename":
            if (($name != '') and confirm_sesskey()) {
                html_header($course, $wdir);
                $name = clean_filename($name);
                if (file_exists($basedir.$wdir."/".$name)) {
                    echo "<center>Error: $name already exists!</center>";
                } else if (!rename($basedir.$wdir."/".$oldname, $basedir.$wdir."/".$name)) {
                    echo "<p align=\"center\">Error: could not rename $oldname to $name</p>";
                } else {
                    //file was renamed now update resources if needed
                    require_once($CFG->dirroot.'/mod/resource/lib.php');
                    resource_renamefiles($course, $wdir, $oldname, $name);
                }
                displaydir($wdir);

            } else {
                $strrename = get_string("rename");
                $strcancel = get_string("cancel");
                $strrenamefileto = get_string("renamefileto", "moodle", $file);
                html_header($course, $wdir, "form.name");
                echo "<p>$strrenamefileto:</p>";
                echo "<table><tr><td>";
                echo "<form action=\"morslefiles.php\" method=\"post\">";
                echo "<fieldset class=\"invisiblefieldset\">";
                echo ' <input type="hidden" name="choose" value="'.$choose.'" />';
                echo " <input type=\"hidden\" name=\"courseid\" value=\"$courseid\" />";
                echo " <input type=\"hidden\" name=\"wdir\" value=\"$wdir\" />";
                echo " <input type=\"hidden\" name=\"action\" value=\"rename\" />";
                echo " <input type=\"hidden\" name=\"oldname\" value=\"$file\" />";
                echo " <input type=\"hidden\" name=\"sesskey\" value=\"$USER->sesskey\" />";
                echo " <input type=\"text\" name=\"name\" size=\"35\" value=\"$file\" />";
                echo " <input type=\"submit\" value=\"$strrename\" />";
                echo "</fieldset>";
                echo "</form>";
                echo "</td><td>";
                echo "<form action=\"morslefiles.php\" method=\"get\">";
                echo "<div>";
                echo ' <input type="hidden" name="choose" value="'.$choose.'" />';
                echo " <input type=\"hidden\" name=\"courseid\" value=\"$courseid\" />";
                echo " <input type=\"hidden\" name=\"wdir\" value=\"$wdir\" />";
                echo " <input type=\"hidden\" name=\"action\" value=\"cancel\" />";
                echo " <input type=\"submit\" value=\"$strcancel\" />";
                echo "</div>";
                echo "</form>";
                echo "</td></tr></table>";
            }
            html_footer();
            break;
            case "edit":
            html_header($course, $wdir);
            if (($text != '') and confirm_sesskey()) {
                $fileptr = fopen($basedir.'/'.$file,"w");
                $text = preg_replace('/\x0D/', '', $text);  // http://moodle.org/mod/forum/discuss.php?d=38860
                fputs($fileptr, stripslashes($text));
                fclose($fileptr);
                displaydir($wdir);

            } else {
                $streditfile = get_string("edit", "", "<b>$file</b>");
                $fileptr  = fopen($basedir.'/'.$file, "r");
                $contents = fread($fileptr, filesize($basedir.'/'.$file));
                fclose($fileptr);

                if (mimeinfo("type", $file) == "text/html") {
                    $usehtmleditor = can_use_html_editor();
                } else {
                    $usehtmleditor = false;
                }
                $usehtmleditor = false;    // Always keep it off for now

                print_heading("$streditfile");

                echo "<table><tr><td colspan=\"2\">";
                echo "<form action=\"morslefiles.php\" method=\"post\">";
                echo "<div>";
                echo ' <input type="hidden" name="choose" value="'.$choose.'" />';
                echo " <input type=\"hidden\" name=\"courseid\" value=\"$courseid\" />";
                echo " <input type=\"hidden\" name=\"wdir\" value=\"$wdir\" />";
                echo " <input type=\"hidden\" name=\"file\" value=\"$file\" />";
                echo " <input type=\"hidden\" name=\"action\" value=\"edit\" />";
                echo " <input type=\"hidden\" name=\"sesskey\" value=\"$USER->sesskey\" />";
                print_textarea($usehtmleditor, 25, 80, 680, 400, "text", $contents);
                echo "</td></tr><tr><td>";
                echo " <input type=\"submit\" value=\"".get_string("savechanges")."\" />";
                echo "</div>";
                echo "</form>";
                echo "</td><td>";
                echo "<form action=\"morslefiles.php\" method=\"get\">";
                echo "<div>";
                echo ' <input type="hidden" name="choose" value="'.$choose.'" />';
                echo " <input type=\"hidden\" name=\"courseid\" value=\"$courseid\" />";
                echo " <input type=\"hidden\" name=\"wdir\" value=\"$wdir\" />";
                echo " <input type=\"hidden\" name=\"action\" value=\"cancel\" />";
                echo " <input type=\"submit\" value=\"".get_string("cancel")."\" />";
                echo "</div>";
                echo "</form>";
                echo "</td></tr></table>";

                if ($usehtmleditor) {
                    use_html_editor();
                }


            }
            html_footer();
            break;

        case "zip":
            if (($name != '') and confirm_sesskey()) {
                html_header($course, $wdir);
                $name = clean_filename($name);

                $files = array();
                foreach ($USER->filelist as $file) {
                   $files[] = "$basedir/$file";
                }

                if (!zip_files($files,"$basedir$wdir/$name")) {
                    print_error("zipfileserror","error");
                }

                clearfilelist();
                displaydir($wdir);

            } else {
                html_header($course, $wdir, "form.name");

                if (setfilelist($_POST)) {
                    echo "<p align=\"center\">".get_string("youareabouttocreatezip").":</p>";
                    print_simple_box_start("center");
                    printfilelist($USER->filelist);
                    print_simple_box_end();
                    echo "<br />";
                    echo "<p align=\"center\">".get_string("whattocallzip")."</p>";
                    echo "<table><tr><td>";
                    echo "<form action=\"morslefiles.php\" method=\"post\">";
                    echo "<fieldset class=\"invisiblefieldset\">";
                    echo ' <input type="hidden" name="choose" value="'.$choose.'" />';
                    echo " <input type=\"hidden\" name=\"courseid\" value=\"$courseid\" />";
                    echo " <input type=\"hidden\" name=\"wdir\" value=\"$wdir\" />";
                    echo " <input type=\"hidden\" name=\"action\" value=\"zip\" />";
                    echo " <input type=\"text\" name=\"name\" size=\"35\" value=\"new.zip\" />";
                    echo " <input type=\"hidden\" name=\"sesskey\" value=\"$USER->sesskey\" />";
                    echo " <input type=\"submit\" value=\"".get_string("createziparchive")."\" />";
                    echo "<fieldset>";
                    echo "</form>";
                    echo "</td><td>";
                    echo "<form action=\"morslefiles.php\" method=\"get\">";
                    echo "<div>";
                    echo ' <input type="hidden" name="choose" value="'.$choose.'" />';
                    echo " <input type=\"hidden\" name=\"courseid\" value=\"$courseid\" />";
                    echo " <input type=\"hidden\" name=\"wdir\" value=\"$wdir\" />";
                    echo " <input type=\"hidden\" name=\"action\" value=\"cancel\" />";
                    echo " <input type=\"submit\" value=\"".get_string("cancel")."\" />";
                    echo "</div>";
                    echo "</form>";
                    echo "</td></tr></table>";
                } else {
                    displaydir($wdir);
                    clearfilelist();
                }
            }
            html_footer();
            break;

        case "unzip":
            html_header($course, $wdir);
            if (($file != '') and confirm_sesskey()) {
                $strok = get_string("ok");
                $strunpacking = get_string("unpacking", "", $file);

                echo "<p align=\"center\">$strunpacking:</p>";

                $file = basename($file);

                if (!unzip_file("$basedir$wdir/$file")) {
                    print_error("unzipfileserror","error");
                }

                echo "<div style=\"text-align:center\"><form action=\"morslefiles.php\" method=\"get\">";
                echo "<div>";
                echo ' <input type="hidden" name="choose" value="'.$choose.'" />';
                echo " <input type=\"hidden\" name=\"courseid\" value=\"$courseid\" />";
                echo " <input type=\"hidden\" name=\"wdir\" value=\"$wdir\" />";
                echo " <input type=\"hidden\" name=\"action\" value=\"cancel\" />";
                echo " <input type=\"submit\" value=\"$strok\" />";
                echo "</div>";
                echo "</form>";
                echo "</div>";
            } else {
                displaydir($wdir);
            }
            html_footer();
            break;

        case "listzip":
            html_header($course, $wdir);
            if (($file != '') and confirm_sesskey()) {
                $strname = get_string("name");
                $strsize = get_string("size");
                $strmodified = get_string("modified");
                $strok = get_string("ok");
                $strlistfiles = get_string("listfiles", "", $file);

                echo "<p align=\"center\">$strlistfiles:</p>";
                $file = basename($file);

                include_once("$CFG->libdir/pclzip/pclzip.lib.php");
                $archive = new PclZip(cleardoubleslashes("$basedir$wdir/$file"));
                if (!$list = $archive->listContent(cleardoubleslashes("$basedir$wdir"))) {
                    notify($archive->errorInfo(true));

                } else {
                    echo "<table cellpadding=\"4\" cellspacing=\"2\" border=\"0\" style=\"min-width:100%;margin-left:auto;margin-right:auto\" class=\"files\">";
                    //echo "<tr class=\"file\"><th align=\"left\" class=\"header name\" scope=\"col\">$strname</th><th align=\"right\" class=\"header size\" scope=\"col\">$strsize</th><th align=\"right\" class=\"header date\" scope=\"col\">$strmodified</th></tr>";

				    echo "<th class=\"header name\" scope=\"col\"><a href=\"" . qualified_me(). "&sort={$sortvalues[0]}\">$strname</a></th>";
				    echo "<th class=\"header size\" scope=\"col\"><a href=\"" . qualified_me(). "&sort={$sortvalues[1]}\">$strsize</a></th>";
				    echo "<th class=\"header date\" scope=\"col\"><a href=\"" . qualified_me(). "&sort={$sortvalues[2]}\">$strmodified</a></th></tr>";

                    foreach ($list as $item) {
                        echo "<tr>";
                        print_cell("left", s($item['filename']), 'name');
                        if (! $item['folder']) {
                            print_cell("right", display_size($item['size']), 'size');
                        } else {
                            echo "<td>&nbsp;</td>";
                        }
                        $filedate  = userdate($item['mtime'], get_string("strftimedatetime"));
                        print_cell("right", $filedate, 'date');
                        echo "</tr>";
                    }
                    echo "</table>";
                }
                echo "<br /><center><form action=\"morslefiles.php\" method=\"get\">";
                echo "<div>";
                echo ' <input type="hidden" name="choose" value="'.$choose.'" />';
                echo " <input type=\"hidden\" name=\"courseid\" value=\"$courseid\" />";
                echo " <input type=\"hidden\" name=\"wdir\" value=\"$wdir\" />";
                echo " <input type=\"hidden\" name=\"action\" value=\"cancel\" />";
                echo " <input type=\"submit\" value=\"$strok\" />";
                echo "</div>";
                echo "</form>";
                echo "</center>";
            } else {
                displaydir($wdir);
            }
            html_footer();
            break;

        case "restore":
            html_header($course, $wdir);
            if (($file != '') and confirm_sesskey()) {
                echo "<p align=\"center\">".get_string("youaregoingtorestorefrom").":</p>";
                print_simple_box_start("center");
                echo $file;
                print_simple_box_end();
                echo "<br />";
                echo "<p align=\"center\">".get_string("areyousuretorestorethisinfo")."</p>";
                $restore_path = "$CFG->wwwroot/backup/restore.php";
                notice_yesno (get_string("areyousuretorestorethis"),
                                $restore_path."?courseid=".$courseid."&amp;file=".cleardoubleslashes($courseid.$wdir."/".$file)."&amp;method=manual",
                                "morslefiles.php?courseid=$courseid&amp;wdir=$wdir&amp;action=cancel");
            } else {
                displaydir($wdir);
            }
            html_footer();
            break;

        case "cancel":
            clearfilelist();
        case "copy":
//            if ($confirm and confirm_sesskey()) {
            setfilelist($_POST, $wdir, $owner, $files);
        	html_header($course, $wdir);
			if (!empty($USER->filelist)) {
				foreach ($USER->filelist as $name=>$link) {
					if (!link_to_gdoc($name, $link)) {
						print_error("gdocslinkerror","error");
					}
				}
			}
			clearfilelist();
			$collectionid = get_collection($wdir, $owner);
			// go get folder contents from Google
			$files = get_doc_feed($owner, $collectionid);
			displaydir($wdir, $files);
			html_footer();
        	break;
*/
?>