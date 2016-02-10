<?php
/*
 * Plugin Spipagram
 * (c) 2016 Julien Tessier
 * Distribue sous licence GPL
 *
 */

if (!defined("_ECRIRE_INC_VERSION")) return;

function spipagram_taches_generales_cron($taches){
	$taches['spipagram_import'] = 3600;
	return $taches;
}