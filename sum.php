<?php

require_once(dirname(__FILE__)."/../../redcap_connect.php");
$pid = 85820;

$json = \REDCap::getData($pid, "json", NULL, array("record_id", "citations"));
$data = json_decode($json);

$upload = array();
foreach ($data as $row) {
	$citations = explode("\n", $row['citations']);
	$upload[] = array("record_id" => $row['record_id'], 'citations_count' => count($citations));
}

\REDCap::saveData($pid, "json", json_encode($upload));
