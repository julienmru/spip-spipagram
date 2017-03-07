<?php
/*
 * Plugin Spipagram
 * (c) 2016 Julien Tessier
 * Distribue sous licence GPL
 *
 */

if (!defined("_ECRIRE_INC_VERSION")) return;

function spipagram_taches_generales_cron($taches){
	include_spip('inc/config');

	if ($_frequence = intval(lire_config('spipagram/config/frequence'))) {
		$taches['spipagram_import'] = max(300, (int)$_frequence);
	} else {
		$taches['spipagram_import'] = 3600;
	}
	return $taches;
}