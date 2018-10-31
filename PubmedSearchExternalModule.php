<?php
namespace Vanderbilt\PubmedSearchExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class PubmedSearchExternalModule extends AbstractExternalModule
{
	public function pubmed($project_id) {
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
		foreach ($dataRows as $id => $rows) {
			# these are default fields that might be blank to begin with
			# record_id, last_name, and first_name must be filled in
			$ary = array($citations => "", $helper => "[]");
			foreach ($rows as $row) {
				foreach ($fields as $field) {
					if ($row[$field]) {
						$ary[$field] = $row[$field];
					}
				}
			}
			if (count($ary) == count($fields)) {
				array_push($names, $ary);
			}
		}

		# process the data
		foreach ($names as $values) {
			$pmids = self::getPubMedEntries($names[$firstName], $names[$lastName]);
		}
	}

	public static function getPubMedEntries($firstName, $lastName) {
	}
}
