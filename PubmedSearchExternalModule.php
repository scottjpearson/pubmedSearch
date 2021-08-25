<?php
namespace Vanderbilt\PubmedSearchExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

use \REDCap as REDCap;
require_once("emLoggerTrait.php");

define('SLEEP_TIME', 1);
define('MAX_RETRIES', 5);

class PubmedSearchExternalModule extends AbstractExternalModule
{
	public function getPids() {
		$sql="SELECT DISTINCT(s.project_id) AS project_id FROM redcap_external_modules m, redcap_external_module_settings s INNER JOIN redcap_projects AS p ON p.project_id = s.project_id WHERE p.date_deleted IS NULL AND m.external_module_id = s.external_module_id AND s.value = 'true' AND m.directory_prefix = 'pubmed_search' AND s.`key` = 'enabled'";
		$q = db_query($sql);

		if ($error = db_error()) {
			error_log("PubmedSearchExternalModule ERROR: $error");
		}

		$pids = array();
		while ($row = db_fetch_assoc($q)) {
			$pids[] = $row['project_id'];
		}
		return $pids;
	}

	public function pubmed() {
		// check if cron is enabled in settings
		if( ! $this->settings['enable-cron']){
			return;
		}
		$pids = $this->getPids();
		error_log("PubmedSearchExternalModule::pubmed with ".json_encode($pids));
		foreach ($pids as $pid) {
			$this->pubmedRun($pid);
		}
	}

	public function pubmedRun($pid, $specific_record=null) {
		error_log("PubmedSearchExternalModule::pubmedRun with $pid");
		# field names
		$firstName = $this->getProjectSetting("first_name", $pid);
		$lastName = $this->getProjectSetting("last_name", $pid);
		$citations = $this->getProjectSetting("citations", $pid);
		$helper = $this->getProjectSetting("helper", $pid);
		$recordId = $this->getProjectSetting("record_id", $pid);
		$defaultInstitution = $this->getProjectSetting("institution", $pid);
		$institutionFields = $this->getProjectSetting("institution_fields", $pid);
		$citationsCount = $this->getProjectSetting("count", $pid);

		# get the first name, last name, citations, and helper
		$fields = array();
		array_push($fields, $firstName);
		array_push($fields, $lastName);
		array_push($fields, $citations);
		array_push($fields, $helper);
		array_push($fields, $recordId);
		foreach ($institutionFields as $institutionField) {
			if ($institutionField) {
				array_push($fields, $institutionField);
			}
		}
		if (is_null($specific_record)){
			$json = \REDCap::getData($pid, "json", NULL, $fields);
		}else{
			$json = \REDCap::getData($pid, "json", [$specific_record['record_id']], $fields);
		}
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
			$institutions[$id] = preg_split("/\s*[,\n]\s*/", $defaultInstitution);

			foreach ($rows as $row) {
				foreach ($fields as $field) {
					if ($row[$field]) {
						if (in_array($field, $institutionFields)) {
							$myInstitutions = preg_split("/\s*[,\n]\s*/", $row[$field]);
							foreach ($myInstitutions as $institution) {
								array_push($institutions[$id], $institution);
							}
						} else {
							$ary[$field] = $row[$field];
						}
					}
				}
			}
			if (count($ary) > 2) {
				array_push($names, $ary);
			}
		}
		error_log("PubmedSearchExternalModule: ".count($names)." names");

		# process the data
		$upload = array();
		foreach ($names as $values) {
			if (is_null($specific_record)){
				$row = self::getPubMed(	$values[$firstName],
				$values[$lastName],
				$institutions[$values[$recordId]],
				json_decode($values[$helper]),
				$values[$citations],
				$citations,
				$helper);
			}else{
				$inst = $institutions[$values[$recordId]];
				if (! in_array($specific_record['institution'], $inst)){
					$inst[] = $specific_record['institution'];
				}
				$row = self::getPubMed(
					$specific_record['first'],
					$specific_record['last'],
					$inst,
					json_decode($values[$helper]),
					$values[$citations],
					$citations,
					$helper, true);
				}

				if ($row && $row[$helper] && $row[$citations]) {
					array_push($upload, array(	$recordId => $values[$recordId],
					$helper => $row[$helper],
					$citations => $row[$citations],
					$citationsCount => count(explode("\n", $row[$citations])),
				));
			}
		}
		error_log("PubmedSearchExternalModule upload: ".count($upload)." rows");

		// if it's a brand new redcap record, recordid will be null, so will $names
		if(!is_null($specific_record) && is_null($specific_record['record_id'])){
			$row = self::getPubMed(
				$specific_record['first'],
				$specific_record['last'],
				$specific_record['institution'],
				"",
				"",
				$citations,
				$helper,
				true);
				if ($row && $row[$helper] && $row[$citations]) {
					array_push($upload, array(	$recordId => $values[$recordId],
					$helper => $row[$helper],
					$citations => $row[$citations],
					$citationsCount => count(explode("\n", $row[$citations])),
				));
			}
		}

		if (!empty($upload)) {
			if (is_null($specific_record) && $this->settings['enable-cron']){
				$feedback = \REDCap::saveData($pid, "json", json_encode($upload));
				error_log("PubmedSearchExternalModule upload: ".json_encode($feedback));
			}
		}
		return json_encode($upload);
	}

	public static function getPubMed($firstName, $lastName, $institutions, $prevCitations, $citationsStr, $citationField, $citationIdField, $deltas=false) {
		$cs = explode("\n", $citationsStr);
		$citations = array();
		foreach ($cs as $c) {
			if ($c) {
				array_push($citations, $c);
			}
		}

		$upload = array();
		$total = 0;
		$totalNew = 0;
		$lastNamesIntermediate = preg_split("/\s*[\s\-]\s*/", strtolower($lastName));
		$lastNames = array(strtolower($lastName));
		foreach($lastNamesIntermediate as $thisLastName) {
			$thisLastName = preg_replace("/^\(/", "", $thisLastName);
			$thisLastName = preg_replace("/\)$/", "", $thisLastName);
			$thisLastName = strtolower($thisLastName);
			if (!in_array($thisLastName, $lastNames)) {
				$lastNames[] = strtolower($thisLastName);
			}
		}

		if (preg_match("/\s\(/", strtolower($firstName))) {
			# nickname in parentheses
			$namesWithFormatting = preg_split("/\s\(/", strtolower($firstName));
			$firstNames = array();
			foreach ($namesWithFormatting as $formattedFirstName) {
				$firstName = preg_replace("/\)$/", "", $formattedFirstName);
				$firstName = preg_replace("/\s+/", "+", $firstName);
				array_push($firstNames, $firstName);
			}
		} else {
			# specified full name => search as group
			$firstNames = array(preg_replace("/\s+/", "+", $firstName));
		}

		$pmids = array();
		foreach ($lastNames as $lastName) {
			foreach ($firstNames as $firstName) {
				foreach ($institutions as $institution) {
					$institution = preg_replace("/\s+/", "+", $institution);
					$url = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retmax=100000&retmode=json&term=".$firstName."+".$lastName."+%5Bau%5D+AND+".strtolower($institution)."%5Bad%5D";
					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, $url);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
					curl_setopt($ch, CURLOPT_VERBOSE, 0);
					curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
					curl_setopt($ch, CURLOPT_AUTOREFERER, true);
					curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
					curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
					$output = curl_exec($ch);
					curl_close($ch);
					sleep(SLEEP_TIME);

					$pmData = json_decode($output, true);
					if ($pmData['esearchresult'] && $pmData['esearchresult']['idlist']) {
						# if the errorlist is not blank, it might search for simplified
						# it might search for simplified names and produce bad results
						if (!isset($pmData['esearchresult']['errorlist'])
						|| !$pmData['esearchresult']['errorlist']
						|| !$pmData['esearchresult']['errorlist']['phrasesnotfound']
						|| empty($pmData['esearchresult']['errorlist']['phrasesnotfound'])) {
							foreach ($pmData['esearchresult']['idlist'] as $pmid) {
								if (!in_array($pmid, $pmids)) {
									$pmids[] = $pmid;
								}
							}
						}
					}
				}
			}
		}
		error_log("PubmedSearchExternalModule Got ".count($pmids)." pmids for ".json_encode($lastNames).", ".json_encode($firstNames)." at ".json_encode($institutions));

		$pmidsUnique = array();
		foreach ($pmids as $pmid) {
			if (!in_array($pmid, $prevCitations)) {
				$pmidsUnique[] = $pmid;
			}
		}
		$total += count($pmids);
		$totalNew += count($pmidsUnique);

		$citations = array();
		if (!empty($pmidsUnique)) {
			$pullSize = 10;
			for ($i = 0; $i < count($pmidsUnique); $i += $pullSize) {
				$pmidsUniqueForPull = array();
				for ($j = $i; $j < count($pmidsUnique) && $j < $i + $pullSize; $j++) {
					array_push($pmidsUniqueForPull, $pmidsUnique[$j]);
				}

				$url = "https://www.ncbi.nlm.nih.gov/pmc/utils/idconv/v1.0/?format=json&ids=".implode(",", $pmidsUniqueForPull);
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($ch, CURLOPT_VERBOSE, 0);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($ch, CURLOPT_AUTOREFERER, true);
				curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
				curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
				$output = curl_exec($ch);
				curl_close($ch);
				sleep(SLEEP_TIME);
				$data = json_decode($output, true);

				# indexed by PMID
				$pmcids = array();
				foreach ($data['records'] as $record) {
					if ($record['pmid'] && $record['pmcid']) {
						$pmcids[$record['pmid']] = $record['pmcid'];
					}
				}

				$retry = 0;
				do {
					$url = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&retmode=xml&id=".implode(",", $pmidsUniqueForPull);
					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, $url);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
					curl_setopt($ch, CURLOPT_VERBOSE, 0);
					curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
					curl_setopt($ch, CURLOPT_AUTOREFERER, true);
					curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
					curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
					$output = curl_exec($ch);
					curl_close($ch);
					$xml = simplexml_load_string(utf8_encode($output));
					sleep(SLEEP_TIME);
					$retry++;
				} while (!$xml && ($retry < MAX_RETRIES));
				if (!$xml) {
					throw new \Exception("Error: After ".MAX_RETRIES." retries, cannot create object (".json_encode($output).") from ".$url);
				}
				foreach ($xml->PubmedArticle as $medlineCitation) {
					$article = $medlineCitation->MedlineCitation->Article;
					$authors = array();
					if ($article->AuthorList->Author) {
						foreach ($article->AuthorList->Author as $authorXML) {
							$author = $authorXML->LastName." ".$authorXML->Initials;
							# " " is if no LastName and no Initials in the line above
							if ($author != " ") {
								$authors[] = $author;
							} else {
								$authors[] = $authorXML->CollectiveName;
							}
						}
					}
					$title = preg_replace("/\.$/", "", $article->ArticleTitle);
					$journal = preg_replace("/\.$/", "", $article->Journal->ISOAbbreviation);
					$issue = $article->Journal->JournalIssue;
					$date = $issue->PubDate->Year." ".$issue->PubDate->Month;
					if ($issue->PubDate->Day) {
						$date = $date." ".$issue->PubDate->Day;
					}
					$journalIssue = $issue->Volume."(".$issue->Issue."):".$article->Journal->ISSN;
					$pmid = $medlineCitation->MedlineCitation->PMID;
					$pubmed = "PubMed PMID: ".$pmid;
					$pmc = "";
					if (isset($pmcids["$pmid"])) {
						$pmc = $pmcids["$pmid"].". ";
					}
					$citation = implode(",", $authors);
					if ($citation) {
						$citation .= ". ";
					}
					$citation .= $title.". ".$journal.". ".$date.";".$journalIssue.". ".$pmc.$pubmed.".";
					$citations[] = $citation;
				}
			}
		}
		//
		if (! $deltas){
			$newCitationIds = array_merge($prevCitations, $pmidsUnique);
			$uploadRow = array(
				$citationIdField => self::json_encode_with_spaces($newCitationIds),
				$citationField => implode("\n", $citations),
			);
		}else{
			$uploadRow = array(
				$citationIdField => json_encode($pmidsUnique),
				$citationField => json_encode($citations),
			);
		}
		return $uploadRow;
	}

	private static function json_encode_with_spaces($data) {
		$str = json_encode($data);
		$str = preg_replace("/,/", ", ", $str);
		return $str;
	}

	// THESE FUNCTIONS BORROWED FROM LOOKUP ASSISTANT
	public $errors = array();
	public $settings = array();

	private function validateJson($contents) {
		// // Verify file exists
		// if (file_exists($path)) {
		//     $contents = file_get_contents($path);

		// Verify contents are json
		$obj = json_decode($contents);

		if (json_last_error() == JSON_ERROR_NONE) {
			// All good
			return $contents;
		} else {
			$this->emError("Error decoding JSON", json_last_error_msg());
			return false;
			// $this->errors[] = "Error decoding path $path: " . json_last_error_msg();
		}

		// } else {
		//     $this->errors[] = "Unable to locate file $path";
		// }
		// return false;
	}


	// Take the project settings and convert them into a more friendly settings object to pass to javascript
	public function loadSettings($instrument) {
		$set = $this->getProjectSettings();
		$set['module-path'] = $this->getPostURL();
		$set['test-path'] = $this->getTestURL();
		$set['record_id'] = $this->getRecordId();
		$this->settings[] = $set;
	}

	function injectLookup($instrument)
	{
		$this->loadSettings($instrument);
		if (!empty($this->errors)) {
			$this->emDebug($this->errors);
			return false;
		}

		// Skip out if there is nothing to do
		if (empty($this->settings)) return false;

		// DUMP CONTENTS TO JAVASCRIPT
		// Append the select2 controls
		$this->insertSelect2();

		echo "<style type='text/css'>" . $this->dumpResource("css/lookup-assistant.css") . "</style>";

		// // Insert our custom JS
		echo "<script type='text/javascript'>" . $this->dumpResource("js/lookupAssistant.js") . "</script>";

		// Insert our custom JS
		echo "<script type='text/javascript'>lookupAssistant.settings = " . json_encode($this->settings) . "</script>";
		echo "<script type='text/javascript'>lookupAssistant.jsDebug = " . json_encode($this->getProjectSetting('enable-js-debug')) . "</script>";
		?>
		<style>
		.lookupIcon {
			position: absolute;
		}
		.lookupIcon > span {
			position: relative;
			left: -20px;
			cursor: pointer;
		}
		</style>
		<?php
	}


	function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id)
	{
		if( ! $this->settings['enable-cron']){
			$this->injectLookup($instrument);
		}
	}


	function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id)
	{
		if( ! $this->settings['enable-cron']){
			$this->injectLookup($instrument);
		}
	}


	public function insertSelect2()
	{
		?>
		<script type="text/javascript"><?php echo $this->dumpResource('js/select2.full.min.js'); ?></script>
		<style><?php echo $this->dumpResource('css/select2.min.css'); ?></style>
		<style><?php echo $this->dumpResource('css/select2-bootstrap.min.css'); ?></style>
		<?php
	}


	public function dumpResource($name)
	{
		$file = $this->getModulePath() . $name;
		if (file_exists($file)) {
			$contents = file_get_contents($file);
			return $contents;
		} else {
			$this->emError("Unable to find $file");
		}
	}

	public function getPostURL(){
		return $this->getUrl("ajax.php", false, false);
		// return $this->getUrl("PubmedSearchExternalModule.php", false, false);
		// return $this->getModulePath();
	}
	public function getTestURL(){
		return $this->getUrl("test.xml", false, false);
		// return $this->getUrl("PubmedSearchExternalModule.php", false, false);
		// return $this->getModulePath();
	}
}
