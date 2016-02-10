<?php
/*
 * Plugin Spipagram
 * (c) 2016 Julien Tessier
 * Distribue sous licence GPL
 *
 */

if (!defined("_ECRIRE_INC_VERSION")) return;

function spipagram_import(){
	include_spip('ecrire/action/editer_article');
	include_spip('inc/config');

	$_rubrique = lire_config('spipagram/config/rubrique');
	$_auteur = lire_config('spipagram/config/auteur');
	$_hashtag = ltrim(lire_config('spipagram/config/hashtag'), '#');
	$_statut = lire_config('spipagram/config/statut');

	if (!$_rubrique || !$_auteur || !$_hashtag || !$_statut) return FALSE;

	$_rss = 'http://iconosquare.com/tagFeed/'.rawurlencode($_hashtag);
	
	include_spip('ecrire/iterateur/data');
	include_spip('inc/distant');

	$_distant = recuperer_page($_rss, true);

	if ($_items = inc_rss_to_array_dist($_distant)) {

		// on règle l'ID auteur pour qu’il puisse créer des article		
		$old_id_auteur = $GLOBALS['visiteur_session']['id_auteur'];
		$GLOBALS['visiteur_session']['id_auteur'] = lire_meta('spipagram/config/auteur');

		foreach($_items as $_item) {

			$article = array();
			$article['titre'] = $_item['lesauteurs'];
			$article['date'] = date('Y-m-d H:i:s', strtotime($item['pubdate']));
			$article['texte'] = $_item['titre'];
			$article['id_rubrique'] = $_rubrique;
			$article['url_site'] = $_item['url'];
			$article['statut'] = $_statut;

			$article_logo = extraire_attribut(extraire_balise($_item['descriptif'], 'img'), 'src');

			if (!sql_fetsel('id_article,id_rubrique,statut', 'spip_articles', 'url_site = '.sql_quote($article['url_site']))) {
				spip_log('statigram', 'Insituer article pour '.$article['url_site']);
				$id_article = article_inserer($article['id_rubrique']);
				article_instituer($id_article, array('statut' => $article['statut']), true);
				if ($id_article) {
					spip_log('statigram', 'Màj des données pour '.$article['url_site']);
					sql_updateq('spip_articles', $article, "id_article = $id_article");
				}
			}
			if ($article_logo && !is_file('./IMG/arton'.$id_article.'.jpg')) {
				spip_log('statigram', 'Màj du logo pour '.$article['url_site']);
				recuperer_url($article_logo, array('file' => './IMG/arton'.$id_article.'.jpg'));
			}
		}

		$GLOBALS['visiteur_session']['id_auteur'] = $old_id_auteur;

	} else {

		spip_log("Fichier $_rss non parsable", 'spipagram'._LOG_CRITIQUE);

		return FALSE;

	}


}