<?php
namespace Vanderbilt\PubmedSearchExternalModule;
global $module;
/**
* @var $module \Vanderbilt\PubmedSearchExternalModule
*/
include_once('PubmedSearchExternalModule.php');

$post_data = json_decode( file_get_contents( 'php://input' ), true );

$pid = $module->framework->getProjectId();
$json_pubmeds = $module->pubmedRun($pid, $post_data);

print_r($json_pubmeds);
?>
