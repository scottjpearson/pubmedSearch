<?php
namespace Vanderbilt\PubmedSearchExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

require_once(dirname(__FILE__)."/getPubMedByName.php");

class PubmedSearchExternalModule extends AbstractExternalModule
{
	public function pubmed($project_id) {
		# field names
		$firstName = $this->getProjectSetting("first_name", $pid);
		$lastName = $this->getProjectSetting("last_name", $pid);
		$citations = $this->getProjectSetting("citations", $pid);
		$helper = $this->getProjectSetting("helper", $pid);
		$recordId = $this->getProjectSetting("record_id", $pid);
		$institutions = $this->getProjectSetting("institution", $pid);

		# get the first name, last name, citations, and helper
		$fields = array();
		array_push($fields, $firstName);
		array_push($fields, $lastName);
		array_push($fields, $citations);
		array_push($fields, $helper);
		array_push($fields, $recordId);

		$json = \REDCap::getData($project_id, "json", NULL, $fields);
		$data = json_decode($json, true);

		# organize the data
		$dataRows = array();
		foreach ($data as $row) {
			if (!isset($dataRows[$row[$recordId]])) {
				$dataRows[$row[$recordId]] = array();
			}
			array_push($dataRows[$row[$recordId]], $row);
		}

		# read in the data into data structures
		$names = array();
		$citationsForm = array("form" => "", "instance" => "");
		$helperForm = array("form" => "", "instance" => "");
		foreach ($dataRows as $id => $rows) {
			# these are default fields that might be blank to begin with
			# record_id, last_name, and first_name must be filled in
			$ary = array($citations => "", $helper => "[]");
			foreach ($rows as $row) {
				foreach ($fields as $field) {
					if ($row[$field]) {
						$ary[$field] = $row[$field];

						if ($field == $helper) {
							$helperForm = array("form" => $row['redcap_repeat_instrument'], "instance" => $row['redcap_repeat_instance']);
						} else if ($field == $citations) {
							$citationsForm = array("form" => $row['redcap_repeat_instrument'], "instance" => $row['redcap_repeat_instance']);
						}
					}
				}
			}
			if (count($ary) == count($fields)) {
				array_push($names, $ary);
			}
		}

		# process the data
		$uploadHelper = array();
		$uploadCitations = array();
		foreach ($names as $values) {
			$row = getPubMed(	$values[$firstName],
						$values[$lastName],
						$values[$institutions],
						json_decode($values[$helper]),
						$values[$citations],
						$citations,
						$helper);

			# process these separately because could be on different instruments
			if ($row && $row[$helper]) {
				array_push($uploadHelper,  array(	"recordId" => $values[$recordId],
									"redcap_repeat_instrument" => $helperForm['form'],
									"redcap_repeat_instance" => $helperForm['instance'],
									$helper => $row[$helper],
								));
			}
			if ($row && $row[$citations]) {
				array_push($uploadCitations,  array(	"recordId" => $values[$recordId],
									"redcap_repeat_instrument" => $citationsForm['form'],
									"redcap_repeat_instance" => $citationsForm['instance'],
									$citations => $row[$citations],
								));
			}
		}

		if (!empty($uploadCitations)) {
			$feedback = \REDCap::saveData($project_id, "json", json_encode($uploadCitations));
		}
		if (!empty($uploadHelper)) {
			$feedback = \REDCap::saveData($project_id, "json", json_encode($uploadHelper));
		}
	}
}
