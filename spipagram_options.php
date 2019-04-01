<?php
/*
 * Plugin Spipagram
 * (c) 2016 Julien Tessier
 * Distribue sous licence GPL
 *
 */

if (!defined("_ECRIRE_INC_VERSION")) return;


function spipagram_scraper() {
	require __DIR__ . '/lib/vendor/autoload.php';

	include_spip('inc/distant');
	$instagram = new \InstagramScraper\Instagram();
	if($proxy = need_proxy('www.instagram.com')) {
		$proxy = parse_url($proxy);
		$conf = [
		    'address' => $proxy['host'],
		    'port'    => $proxy['port'],
		    'tunnel'  => true,
		    'timeout' => 30,
		];
		if (isset($proxy['user']) && isset($proxy['user'])) {
			$conf['auth'] = [
				'user' => $proxy['user'],
				'method' => CURLAUTH_BASIC
			];
			if (isset($proxy['pass']) && isset($proxy['pass'])) {
				$conf['auth']['pass'] = $proxy['pass'];
			} else {
				$conf['auth']['pass'] = '';
			}
		}
		$instagram->setProxy($conf);
	}
	return $instagram;
}

function check_instagram_connectivity($_login = '', $_pass = '') {
	if (empty($_login)) {
		return '<strong style="color:#f00">Missing login</strong>';
	} elseif (empty($_pass)) {
		return '<strong style="color:#f00">Missing password</strong>';
	} else {
		try {
			$instagram = spipagram_scraper();
			$instagram->withCredentials($_login, $_pass, _NOM_TEMPORAIRES_INACCESSIBLES)->login();
			$medias = $instagram->getMediasByTag('test', 20);
		} catch(Exception $e) {
			return '<strong style="color:#f00">'.$e->getMessage().'</strong>';
		}
		return 'OK';
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
	$_login = lire_config('spipagram/config/login');
	$_pass = lire_config('spipagram/config/password');


	if (!$_rubrique || !$_auteur || !count($_hashtags) || !$_statut || !$_login || !$_pass) {
		spip_log('Plugin non configuré', 'spipagram'._LOG_DEBUG);
		return FALSE;
	}

	try {
		$instagram = spipagram_scraper();
		$instagram->withCredentials($_login, $_pass, _NOM_TEMPORAIRES_INACCESSIBLES)->login();

		include_spip('action/editer_article');
		include_spip('action/editer_liens');
			
		include_spip('inc/distant');

		foreach($_hashtags as $_hashtag) {


			// on règle l'ID auteur pour qu’il puisse créer des article		
			if (isset($GLOBALS['visiteur_session']) && isset($GLOBALS['visiteur_session']['id_auteur'])) $old_id_auteur = $GLOBALS['visiteur_session']['id_auteur'];
			$GLOBALS['visiteur_session']['id_auteur'] = lire_meta('spipagram/config/auteur');

			if (substr($_hashtag, 0, 1) == '#') $_hashtag = substr($_hashtag, 1);
			if (substr($_hashtag, 0, 1) == '@') {
				$_user = substr($_hashtag, 1);
				spip_log('Récupération du flux du user '.$_user, 'spipagram'._LOG_INFO);
				$_items = $instagram->getMedias(rawurldecode($_user), 25);
			} else {
				$_user = FALSE;
				spip_log('Récupération du flux du hashtag '.$_hashtag, 'spipagram'._LOG_INFO);
				$_items = $instagram->getMediasByTag(rawurldecode($_hashtag), 20);
			}

			foreach($_items as $_item) {

				$article = array();
				if ($_user) {
					$article['titre'] = $_user;
				} else {
					$article['titre'] = $_item->getOwner()->getUsername();
				}
				$article['date'] = date('Y-m-d H:i:s', $_item->getCreatedTime());
				$article['texte'] = $_item->getCaption();
				$article['id_rubrique'] = $_rubrique;
				$article['url_site'] = $_item->getLink();

				$article_logo = $_item->getImageHighResolutionUrl();

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
				if ($article_logo && !is_file(_DIR_RACINE.'IMG/arton'.$id_article.'.jpg')) {
					spip_log('Màj du logo pour l’article '.$id_article.' depuis '.$article_logo, 'spipagram'._LOG_INFO);
					copie_locale($article_logo, 'auto', './IMG/arton'.$id_article.'.jpg');
					if (!is_file(_DIR_RACINE.'IMG/arton'.$id_article.'.jpg')) {
						spip_log('Impossible de copier le logo', 'spipagram'._LOG_AVERTISSEMENT);
					}
				}
				if ($_item->getType() == 'video' && sql_countsel('spip_documents_liens', 'objet = "article" AND id_objet = '.$id_article) == 0) {
					spip_log('Ajout de la vidéo pour l’article '.$id_article.' depuis '.$_item->getVideoStandardResolutionUrl(), 'spipagram'._LOG_INFO);
					$ajouter_documents = charger_fonction('ajouter_documents', 'action');
					$ajouter_un_document = charger_fonction('ajouter_un_document', 'action');
					$file = [
						'name' => basename($_item->getVideoStandardResolutionUrl()),
						'tmp_name' => $_item->getVideoStandardResolutionUrl(),
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

		}

	} catch (Exception $e) {

		spip_log('Erreur rencontrée lors de l\'import: '.$e, 'spipagram'._LOG_CRITIQUE);

	}

}