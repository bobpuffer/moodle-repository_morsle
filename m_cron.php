<?php
require_once('../../config.php');
require_once("$CFG->dirroot/repository/morsle/lib.php");
$morsle = new repository_morsle();
$status = $morsle->cron();
?>