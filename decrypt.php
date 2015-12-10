<?php
require_once('../../config.php');
require_once("$CFG->dirroot/google/gauth.php");
global $DB;
$sql = " shortname LIKE '%search%' ";
//$records = $DB->get_records_select('morsle_active', $sql);
//foreach ($records as $record) {
//	if (substr($record, 0, 1) == '%' && substr($record, 3, 1) == '%') {
		$password = '%FE%DBJ%14%AAX%F40%A4%1E%DA%0C';
		echo rc4decrypt($password, null) . '<br />';
		echo morsle_encode(rc4decrypt($password, null));
//	}
//}
?>