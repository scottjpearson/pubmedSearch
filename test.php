<?php

require_once(dirname(__FILE__)."/PubmedSearchExternalModule.php");

$obj = new Vanderbilt\PubmedSearchExternalModule\PubmedSearchExternalModule();
$obj->pubmed(85820);
