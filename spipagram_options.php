<?php
/*
 * Plugin Spipagram
 * (c) 2016 Julien Tessier
 * Distribue sous licence GPL
 *
 */

if (!defined("_ECRIRE_INC_VERSION")) return;

function check_instagram_connectivity($_token = '') {
	if (empty($_token)) {
		return '<strong style="color:#f00">Missing token</strong>';
	} else {
		$opts = array('http' => array('ignore_errors' => true));
		$context = stream_context_create($opts);
		if ($_remote_data = @file_get_contents('https://api.instagram.com/v1/tags/test/media/recent?access_token=' . $_token, false, $context)) {
			if ($_parsed_data = json_decode($_remote_data)) {
				if ($_parsed_data->meta->code != 200) {
					if (isset($_parsed_data->meta->error_message)) return '<strong style="color:#f00">' . $_parsed_data->meta->error_message . '</strong>';
					else return '<strong style="color:#f00">Instagram error #' . $_parsed_data->meta->code . '</strong>';
				} else {
					return 'OK';
				}
			} else {
				return '<strong style="color:#f00">JSON decoding error</strong>';
			}
		} else {
			$error = error_get_last();
			return '<strong style="color:#f00">Transport error (' . $error['message'] . ')</strong>';
		}
	}
}

function spipagram_import(){
	include_spip('inc/config');

	if ($_config_rubrique = lire_config('spipagram/config/rubrique')) {
		$_rubrique = explode('|', $_config_rubrique[0]);
		$_rubrique = intval($_rubrique[1]);
	} else {
		$_rubrique = FALSE;
	}

	$_auteur = intval(lire_config('spipagram/config/auteur'));
	$_hashtags = explode(',', lire_config('spipagram/config/hashtag'));
	array_walk($_hashtags, function (&$val) { $val = ltrim(trim($val), '#'); }); 
	$_statut = lire_config('spipagram/config/statut');
	$_mots = lire_config('spipagram/config/mots');
	$_token = lire_config('spipagram/config/token');

	if (!$_rubrique || !$_auteur || !count($_hashtags) || !$_statut || !$_token) {
		spip_log('Plugin non configuré', 'spipagram'._LOG_DEBUG);
		return FALSE;
	}


	include_spip('action/editer_article');
	include_spip('action/editer_liens');
		
	include_spip('inc/distant');

	foreach($_hashtags as $_hashtag) {

		if ($_result = json_decode(file_get_contents('https://api.instagram.com/v1/tags/'.rawurldecode($_hashtag).'/media/recent?access_token='.$_token))) {

			// on règle l'ID auteur pour qu’il puisse créer des article		
			if (isset($GLOBALS['visiteur_session']) && isset($GLOBALS['visiteur_session']['id_auteur'])) $old_id_auteur = $GLOBALS['visiteur_session']['id_auteur'];
			$GLOBALS['visiteur_session']['id_auteur'] = lire_meta('spipagram/config/auteur');

			$_items = $_result->data;

			foreach($_items as $_item) {

				$article = array();
				$article['titre'] = $_item->user->username;
				$article['date'] = date('Y-m-d H:i:s', $_item->created_time);
				$article['texte'] = $_item->caption->text;
				$article['id_rubrique'] = $_rubrique;
				$article['url_site'] = $_item->link;
				$article['statut'] = $_statut;

				$article_logo = $_item->images->standard_resolution->url;

				if ($row = sql_fetsel('id_article', 'spip_articles', 'id_rubrique = '.$_rubrique.' AND url_site = '.sql_quote($article['url_site']))) {
					$id_article = $row['id_article'];
					spip_log('Article trouvé pour '.$article['url_site'].' => '.$id_article, 'spipagram'._LOG_INFO);
					spip_log('Màj des données pour l’article '.$id_article, 'spipagram'._LOG_INFO);
					sql_updateq('spip_articles', $article, "id_article = $id_article");
				} else {
					$article['statut'] = $_statut;
					$id_article = article_inserer($article['id_rubrique']);
					article_instituer($id_article, array('statut' => $article['statut']), true);
					if ($id_article) {
						spip_log('Article créé pour '.$article['url_site'].' => '.$id_article, 'spipagram'._LOG_INFO);
						spip_log('Màj des données pour l’article '.$id_article, 'spipagram'._LOG_INFO);
						sql_updateq('spip_articles', $article, "id_article = $id_article");
					} else {
						spip_log('Impossible de créer l’article pour '.$article['url_site'], 'spipagram'._LOG_CRITIQUE);
					}
					if ($_mots) {
						spip_log('Association des mots-clés pour l’article '.$id_article, 'spipagram'._LOG_INFO);
						foreach($_mots as $id_mot) objet_associer(array('mot' => $id_mot), array('article' => $id_article));
					}
				}
				if ($article_logo && !is_file('./IMG/arton'.$id_article.'.jpg')) {
					spip_log('Màj du logo pour l’article '.$id_article.' depuis '.$article_logo, 'spipagram'._LOG_INFO);
					copie_locale($article_logo, 'auto', './IMG/arton'.$id_article.'.jpg');
					if (!is_file('./IMG/arton'.$id_article.'.jpg')) {
						spip_log('Impossible de copier le logo', 'spipagram'._LOG_AVERTISSEMENT);
					}
				}
				if (isset($_item->videos->standard_resolution->url) && sql_countsel('spip_documents_liens', 'objet = "article" AND id_objet = '.$id_article) == 0) {
					spip_log('Ajout de la vidéo pour l’article '.$id_article.' depuis '.$_item->videos->standard_resolution->url, 'spipagram'._LOG_INFO);
					$ajouter_documents = charger_fonction('ajouter_documents', 'action');
					$ajouter_un_document = charger_fonction('ajouter_un_document', 'action');
					$file = [
						'name' => basename($_item->videos->standard_resolution->url),
						'tmp_name' => $_item->videos->standard_resolution->url,
						'distant' => TRUE,
						'mode '=> 'document',
					];

					if (($id_document = $ajouter_un_document('new', $file, 'article', $id_article, 'document'))) {
						// if we have a document which couldn't be linked by SPIP for some reason, force the link
						if (sql_countsel('spip_documents_liens', 'objet = "article" AND id_objet = '.$id_article) == 0) {
							spip_log("Liaison du document $id_document avec l’article $id_article", 'spipagram'._LOG_INFO);
							sql_insertq('spip_documents_liens', array('objet' => 'article', 'id_objet' => $id_article, 'id_document' => $id_document));
						}
					} else {
						spip_log('Impossible de copier la vidéo', 'spipagram'._LOG_AVERTISSEMENT);
					}
				}
			}

			if (isset($old_id_auteur)) $GLOBALS['visiteur_session']['id_auteur'] = $old_id_auteur;
			else unset($GLOBALS['visiteur_session']['id_auteur']);

		} else {

			spip_log("Impossible de recupérer les données Instagram", 'spipagram'._LOG_CRITIQUE);

			return FALSE;

		}

	}

}