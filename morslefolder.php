<?php
/* 
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */
    require_once('../../config.php');
    require_once($CFG->dirroot.'/google/gauth.php');
    require_once($CFG->dirroot.'/lib/filelib.php');
    require_login();
    $shortname =  required_param('shortname');
    $readwrite =  required_param('readwrite');
    $folderid =  required_param('folderid');
    $COURSE->shortname = $shortname;
    $COURSE->id = required_param('courseid');
    $parentfolderid =  optional_param('parentfolderid');
//    $shortname = 'MP3-TEST--1';
  // Establish an OAuth consumer based on our admin 'credentials'
    if ( !$CONSUMER_KEY = get_config('blocks/morsle','consumer_key')) {
        return NULL;
    }

    $user= $shortname . '@' . $CONSUMER_KEY;
    $base_feed = 'https://docs.google.com/feeds/default/private/full/folder%3A' . $folderid . '/contents';
    //  $base_feed = 'http://docs.google.com/feeds/documents/private/full';
    $params = array('xoauth_requestor_id' => $user, 'max-results' => '400');
    
    //    echo $content;
//    echo $content;
    $pagetitle = strip_tags($shortname.': '. name_folder($readwrite));

    $update = null;
/*
    if (has_capability('moodle/course:managefiles', get_context_instance(CONTEXT_COURSE, $course->id))) {
        $options = array('id'=>$course->id, 'wdir'=>'/'.$resource->reference.$subdir);
        $editfiles = print_single_button("$CFG->wwwroot/files/index.php", $options, get_string("editfiles"), 'get', '', true);
        $update = $editfiles.$update;
    }
 * 
 */
//    $navigation = build_navigation($this->navlinks, $cm);
    $navigation = build_navigation('Google docs folders');
    print_header($pagetitle, $pagetitle, $navigation,"", "", true, $update, null);
    print_heading($pagetitle);

    // go get the docs if any
    $docs = twolegged($base_feed, $params, 'GET');
    $files = simplexml_load_string($docs->response);
/*
    $content = '';
    foreach($feed->entry as $file) {
        $content .= '- <a title="'.$file->title.' target="_blank"';
        foreach ($file->link as $link) {
            if ($link['rel'] == 'alternate') {
                $href = $link['href'];
                break;
            }
        }
        $content .= '" href="'. s($href) . '">' . substr($file->title,0,30).'</a> <br />';
//        $content .= '" href="'.(string) $file->link[1]['href'] . '">' . substr($file->title,0,30).'</a> <br />';
//        $content .= '" href="'.(string) $file->content['src'].'">'.substr($file->title,0,30).'</a> <br />';
    }
//    $files = get_directory_list("$CFG->dataroot/$relativepath", array($CFG->moddata, 'backupdata'), false, true, true);

*/
    if (!$files) {
        print_heading(get_string("nofilesyet"));
        print_footer($course);
        exit;
    }

    print_simple_box_start("center", "", "", '0' );

    $strftime = get_string('strftimedatetime');
    $strname = get_string("name");
    $strsize = get_string("size");
    $strfolder = get_string("folder");
    $strfile = get_string("file");

    //TODO: add these to language file
    $strmodified = 'Last<br />Modified';
    $stropengoogle = 'Open in <br /> Norse Docs';
    $strdownload = 'Download to <br />desktop application';
    $stropenfolder = 'Open this folder';
    $strparentfolder = 'Parent Folder';

    echo '<table align="center" cellpadding="4" cellspacing="1" class="files" summary="">';
    echo "<tr><th class=\"header name\" scope=\"col\">$strdownload</th>".
//         "<th align=\"right\" colspan=\"2\" class=\"header size\" scope=\"col\">$strsize</th>".
         "<th align=\"center\" colspan=\"2\" class=\"header date\" scope=\"col\">$strmodified</th>".
         "<th align=\"center\" colspan=\"2\" class=\"header date\" scope=\"col\">$stropengoogle</th>".
//         "<th align=\"center\" class=\"header date\" scope=\"col\">$strdownload</th>".
         "</tr>";

    // print out parent folder links if appropriate
    if ($parentfolderid <> null) {
        $icon = "folder.gif";
        echo '<tr class="folder">';
        echo '<td class="name">';
        echo '<a title="' . strip_tags($stropenfolder) . ': ' . $strparentfolder . '" href="' . $CFG->wwwroot . '/blocks/morsle/morslefolder.php?shortname='
                . $shortname . '&readwrite=' . $readwrite . '&folderid=' . $parentfolderid . '&courseid=' . $COURSE->id . '">';
        echo "<img src=\"$CFG->pixpath/f/$icon\" class=\"icon\" alt=\"$strfolder\" />&nbsp;$strparentfolder</a>";
        echo '</td>';
        echo '</tr>';
    }
    $iconchoices = array('excel'=>'download/spreadsheets','powerpoint'=>'download/presentations','word'=>'download/documents');
	$sortedfiles = array();
    foreach ($files->entry as $key => $file) {
    	$sortedfiles[s($file->title)] = $file;
    }
	ksort($sortedfiles);
    foreach ($sortedfiles as $file) {
//	foreach ($files->entry as $file) {
        $icon = (is_folder($file)) ? "folder.gif" : mimeinfo("icon", $file->title);
//            $relativeurl = "/view.php?blah";
//            $filesize = display_size(get_directory_size("$CFG->dataroot/$relativepath/$file"));

        // get the link to google open of file
        foreach ($file->link as $link) {
            if ($link['rel'] == 'alternate') {
                $href = $link['href'];
//            } elseif ($link['rel'] == 'edit') {
//                $exportlink = $link['href'];
            }
        }

        if ($icon == 'folder.gif') {
            $subfolderid = substr($file->id,strpos($file->id,'folder%3A') + 9);
            echo '<tr class="folder">';
            echo '<td class="name">';
            echo '<a title="' . strip_tags($stropenfolder) . ': ' . $file->title . '" href="' . $CFG->wwwroot . '/blocks/morsle/morslefolder.php?shortname='
                    . $shortname . '&readwrite=' . $readwrite . '&folderid=' . $subfolderid . '&parentfolderid=' . $folderid . '&courseid=' . $COURSE->id . '">';
            echo "<img src=\"$CFG->pixpath/f/$icon\" class=\"icon\" alt=\"$strfolder\" />&nbsp;$file->title</a>";
            echo '</td>';
            echo '<td>&nbsp;</td>';
            echo '<td align="center" class="date">';
            echo docdate($file);
            echo '</td>';
            echo '<td>&nbsp;</td>';
            echo '<td class="name">';
            echo '<a title="' . strip_tags($stropengoogle) . ': ' . $file->title . '" href="' . s($href) . '">';
            echo $file->title . "</a>";
        } else {

            // create export link for file
//            $export_format = 'doc';
//            $exportlink = $file->content['src'] .'&exportFormat='.$export_format;
//            $exporlink = '';
            $exportlink = $file->content['src'];
//            $exportlink = str_replace("?","&",$exportlink);
			if ($icon == 'unknown.gif') {
				foreach ($iconchoices as $key=>$value) {
					if (strpos($exportlink,$value)) {
						$icon = $key . '.gif';
						break;
					}
				}				
			}
            echo '<tr class="file">';
            echo '<td class="name">';
//            echo '<a title="' . strip_tags($strdownload) . ': ' . $file->title . '" href="' . s($exportlink) . '">';
            echo '<a title="' . strip_tags($strdownload) . ': ' . $file->title . '" href="' .$CFG->wwwroot
                    . '/blocks/morsle/docs_export.php?exportlink=' . s($exportlink) . '&user=' . $user . '&title=' . $file->title . '" target="_blank">';
            echo "<img src=\"$CFG->pixpath/f/$icon\" class=\"icon\" alt=\"$strfolder\" />&nbsp;$file->title</a>";
            echo '<td>&nbsp;</td>';
            echo '<td align="center" class="date">';
            echo docdate($file);
            echo '</td>';
            echo '<td>&nbsp;</td>';
            echo '<td class="name">';
            echo '<a title="' . strip_tags($stropengoogle) . ': ' . $file->title . '" href="' . s($href) . '" target="_blank">';
            echo "$file->title</a>";
//            echo '</td>';
//            link_to_popup_window($relativeurl, "resourcedirectory{$resource->id}", "<img src=\"$CFG->pixpath/f/$icon\" class=\"icon\" alt=\"$strfile\" />&nbsp;$file", 450, 600, '');
        }
        echo '</td>';
        /*
        echo '<td>&nbsp;</td>';
        echo '<td align="right" class="size">';
        echo $filesize;
        echo '</td>';
         * 
        echo '<td align="center" class="date">';
        echo docdate($file);
        echo '</td>';
         */
        echo '</tr>';
    }
    echo '</table>';

    print_simple_box_end();

    print_footer($course);

function docdate($file) {
    return substr($file->updated,5,2) . '/' . substr($file->updated,8,2) . '/' . substr($file->updated,0,4) . ' - ' . substr($file->updated,11,8);
}

function is_folder($file) {
    return strpos($file->id,'folder');
}

function name_folder($readwrite) {
    return ($readwrite == 'read') ? 'Student-read-only folder' : 'Student-writeable folder';
}

function display() {
    global $CFG;

/// Set up generic stuff first, including checking for access
    parent::display();

/// Set up some shorthand variables
    $cm = $this->cm;
    $course = $this->course;
    $resource = $this->resource;

    require_once($CFG->libdir.'/filelib.php');

    $subdir = optional_param('subdir','', PARAM_PATH);
    $resource->reference = clean_param($resource->reference, PARAM_PATH);

    $formatoptions = new object();
    $formatoptions->noclean = true;
    $formatoptions->para = false; // MDL-12061, <p> in html editor breaks xhtml strict

    add_to_log($course->id, "resource", "view", "view.php?id={$cm->id}", $resource->id, $cm->id);

    if ($resource->reference) {
        $relativepath = "{$course->id}/{$resource->reference}";
    } else {
        $relativepath = "{$course->id}";
    }

    if ($subdir) {
        $relativepath = "$relativepath$subdir";
        if (stripos($relativepath, 'backupdata') !== FALSE or stripos($relativepath, $CFG->moddata) !== FALSE) {
            error("Access not allowed!");
        }

        $subs = explode('/', $subdir);
        array_shift($subs);
        $countsubs = count($subs);
        $count = 0;
        $backsub = '';

        foreach ($subs as $sub) {
            $count++;
            if ($count < $countsubs) {
                $backsub .= "/$sub";

                $this->navlinks[] = array('name' => $sub, 'link' => "view.php?id={$cm->id}", 'type' => 'title');
            } else {
                $this->navlinks[] = array('name' => $sub, 'link' => '', 'type' => 'title');
            }
        }
    }

    $pagetitle = strip_tags($course->shortname.': '.format_string($resource->name));

    $update = update_module_button($cm->id, $course->id, $this->strresource);
    if (has_capability('moodle/course:managefiles', get_context_instance(CONTEXT_COURSE, $course->id))) {
        $options = array('id'=>$course->id, 'wdir'=>'/'.$resource->reference.$subdir);
        $editfiles = print_single_button("$CFG->wwwroot/files/index.php", $options, get_string("editfiles"), 'get', '', true);
        $update = $editfiles.$update;
    }
    $navigation = build_navigation($this->navlinks, $cm);
    print_header($pagetitle, $course->fullname, $navigation,
            "", "", true, $update,
            navmenu($course, $cm));


    if (trim(strip_tags($resource->summary))) {
        print_simple_box(format_text($resource->summary, FORMAT_MOODLE, $formatoptions, $course->id), "center");
        print_spacer(10,10);
    }

    $files = get_directory_list("$CFG->dataroot/$relativepath", array($CFG->moddata, 'backupdata'), false, true, true);


    if (!$files) {
        print_heading(get_string("nofilesyet"));
        print_footer($course);
        exit;
    }

    print_simple_box_start("center", "", "", '0' );

    $strftime = get_string('strftimedatetime');
    $strname = get_string("name");
    $strsize = get_string("size");
    $strmodified = get_string("modified");
    $strfolder = get_string("folder");
    $strfile = get_string("file");

    echo '<table cellpadding="4" cellspacing="1" class="files" summary="">';
    echo "<tr><th class=\"header name\" scope=\"col\">$strname</th>".
         "<th align=\"right\" colspan=\"2\" class=\"header size\" scope=\"col\">$strsize</th>".
         "<th align=\"right\" class=\"header date\" scope=\"col\">$strmodified</th>".
         "</tr>";
    foreach ($files as $file) {
        if (is_dir("$CFG->dataroot/$relativepath/$file")) {          // Must be a directory
            $icon = "folder.gif";
            $relativeurl = "/view.php?blah";
            $filesize = display_size(get_directory_size("$CFG->dataroot/$relativepath/$file"));

        } else {
            $icon = mimeinfo("icon", $file);
            $relativeurl = get_file_url("$relativepath/$file");
            $filesize = display_size(filesize("$CFG->dataroot/$relativepath/$file"));
        }

        if ($icon == 'folder.gif') {
            echo '<tr class="folder">';
            echo '<td class="name">';
            echo "<a href=\"view.php?id={$cm->id}&amp;subdir=$subdir/$file\">";
            echo "<img src=\"$CFG->pixpath/f/$icon\" class=\"icon\" alt=\"$strfolder\" />&nbsp;$file</a>";
        } else {
            echo '<tr class="file">';
            echo '<td class="name">';
            link_to_popup_window($relativeurl, "resourcedirectory{$resource->id}", "<img src=\"$CFG->pixpath/f/$icon\" class=\"icon\" alt=\"$strfile\" />&nbsp;$file", 450, 600, '');
        }
        echo '</td>';
        echo '<td>&nbsp;</td>';
        echo '<td align="right" class="size">';
        echo $filesize;
        echo '</td>';
        echo '<td align="right" class="date">';
        echo userdate(filemtime("$CFG->dataroot/$relativepath/$file"), $strftime);
        echo '</td>';
        echo '</tr>';
    }
    echo '</table>';

    print_simple_box_end();

    print_footer($course);

}
?>
