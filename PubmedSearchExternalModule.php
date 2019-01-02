<?php
namespace Vanderbilt\PubmedSearchExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class PubmedSearchExternalModule extends AbstractExternalModule
{
	public function getPids() {
	       $sql="SELECT DISTINCT(s.project_id) AS project_id FROM redcap_external_modules m, redcap_external_module_settings s INNER JOIN redcap_projects AS p ON p.project_id = s.project_id WHERE p.date_deleted IS NULL AND m.external_module_id = s.external_module_id AND s.value = 'true' AND m.directory_prefix = 'pubmedSearch' AND s.`key` = 'enabled'";
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
		$pids = $this->getPids();
		error_log("PubmedSearchExternalModule::pubmed with ".json_encode($pids));
		foreach ($pids as $pid) {
			$this->pubmedRun($pid);
		}
	}

	public function pubmedRun($pid) {
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

		$json = \REDCap::getData($pid, "json", NULL, $fields);
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
							array_push($institutions[$id], $row[$field]);
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
			$row = self::getPubMed(	$values[$firstName],
						$values[$lastName],
						$institutions[$id],
						json_decode($values[$helper]),
						$values[$citations],
						$citations,
						$helper);

			if ($row && $row[$helper] && $row[$citations]) {
				array_push($upload, array(	$recordId => $values[$recordId],
								$helper => $row[$helper],
								$citations => $row[$citations],
								$citationsCount => count(explode("\n", $row[$citations])),
							));
			}
		}
		error_log("PubmedSearchExternalModule upload: ".count($upload)." rows");

		if (!empty($upload)) {
			$feedback = \REDCap::saveData($pid, "json", json_encode($upload));
			error_log("PubmedSearchExternalModule upload: ".json_encode($feedback));
		}
	}

	public static function getPubMed($firstName, $lastName, $institutions, $prevCitations, $citationsStr, $citationField, $citationIdField) {
		$redcapData = json_decode($output, true);
	
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
		$lastNames = preg_split("/\s*[\s\-]\s*/", strtolower($lastName));
		if (count($lastNames) > 1) {
			$lastNames[] = strtolower($lastName);
		}

		$firstNames = preg_split("/[\s\-]+/", strtolower($firstName));
		if (!in_array($firstName, $firstNames)) {
			array_push($firstNames, $firstName);
		}
		$firstInitials = array();
		$i = 0;
		foreach ($firstNames as $firstName) {
			$firstName = preg_replace("/^\(/", "", $firstName);
			$firstName = preg_replace("/\)$/", "", $firstName);
			$firstNames[$i] = $firstName;
			$firstInitials[] = substr($firstName, 0, 1);
			$i++;
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
			$pullSize = 1;
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
				$data = json_decode($output, true);
	
				# indexed by PMID
				$pmcids = array();
				foreach ($data['records'] as $record) {
					if ($record['pmid'] && $record['pmcid']) {
						$pmcids[$record['pmid']] = $record['pmcid'];
					}
				}
				
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
				$xml = simplexml_load_string(utf8_encode($output)) or die("Error: Cannot create object (".count($output)." bytes) from ".$url);
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
		$newCitationIds = array_merge($prevCitations, $pmidsUnique);
		$uploadRow = array(
					$citationIdField => self::json_encode_with_spaces($newCitationIds),
					$citationField => implode("\n", $citations),
				);
		return $uploadRow;
	}

	private static function json_encode_with_spaces($data) {
		$str = json_encode($data);
		$str = preg_replace("/,/", ", ", $str);
		return $str;
	}
}
