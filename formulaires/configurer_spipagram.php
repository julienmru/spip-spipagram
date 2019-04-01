<?php
/*
 * Plugin Spipagram
 * (c) 2016 Julien Tessier
 * Distribue sous licence GPL
 *
 */

function formulaires_configurer_spipagram_verifier_dist(){
        $erreurs = array();
        // check that mandatory fields are indeed filled out:
        foreach(array('hashtag','rubrique', 'statut', 'auteur', 'login', 'password') as $obligatoire)
                if (!_request($obligatoire)) $erreurs[$obligatoire] = _T('saisies:option_obligatoire_label');
       
        return $erreurs;
}
