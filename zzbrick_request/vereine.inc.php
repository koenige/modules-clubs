<?php

/**
 * clubs module
 * output of a map with all clubs
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/clubs
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2015-2022 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function mod_clubs_vereine($params) {
	global $zz_setting;
	if (count($params) > 2) return false;

	// divert?
	if (end($params) === 'liste') {
		array_pop($params);
		if (empty($params)) $params[0] = 'dsb';
		if ($params[0] === 'twitter')
			return brick_format('%%% request vereinsliste '.$params[0].' %%%');
		return brick_format('%%% request verbandsliste '.$params[0].' %%%');
	}
	if (count($params) > 1) return false;

	if (isset($_GET['q']) AND $_GET['q'] !== '') {
		$_GET['q'] = trim($_GET['q']);
		if (strlen($_GET['q']) > 64) {
			// extra_ is not created since zzbrick() was not called
			wrap_quit(414, 'Die maximale Länge der Suchbegriffe beträgt 64 Zeichen.');
		}
		if (substr($_GET['q'], -1) === '*') $_GET['q'] = substr($_GET['q'], 0, -1);
	}
	if ($_SERVER['REQUEST_URI'] === '/' AND empty($_GET)) {
		return wrap_redirect('/deutschland', 307);
	}

	// check if lat or lon are both set or not set and if they are numeric values
	// if not numeric, still show output, but send 404 page status
	if (!empty($_GET['lat']) AND empty($_GET['lon'])) return false;
	if (!empty($_GET['lon']) AND empty($_GET['lat'])) return false;
	if (isset($_GET['lat']) AND !is_numeric($_GET['lat'])) {
		$_GET['lat'] = filter_var($_GET['lat'], FILTER_SANITIZE_NUMBER_FLOAT);
		$page['status'] = 404;
	}
	if (isset($_GET['lon']) AND !is_numeric($_GET['lon'])) {
		$_GET['lon'] = filter_var($_GET['lon'], FILTER_SANITIZE_NUMBER_FLOAT);
		$page['status'] = 404;
	}

	$found = false;
	$having = '';
	$extra_field = '';
	$content = 'html';
	$condition_cc = '';
	if ($params AND substr($params[0], -8) === '.geojson') {
		$content = 'geojson';
		$params[0] = substr($params[0], 0, -8);
	}
	if (!$params) {
		$data['geojson'] = 'deutschland';
	} elseif ($params[0] === 'twitter') {
		$extra_field = sprintf(', (SELECT COUNT(*) FROM contactdetails
			WHERE contactdetails.contact_id = organisationen.contact_id
			AND provider_category_id = %d) AS website_username', wrap_category_id('provider/twitter'));
		$condition = 'HAVING website_username > 0';
		$found = true;
		$data['title'] = 'Twitter';
		$data['geojson'] = $params[0];
	} else {
		$data['geojson'] = $params[0];
		$sql = 'SELECT contact_id, contact
			FROM contacts
			LEFT JOIN categories
				ON contacts.contact_category_id = categories.category_id
			WHERE identifier = "%s"
			AND categories.parameters LIKE "%%&organisation=1%%"';
		$sql = sprintf($sql, wrap_db_escape($params[0]));
		$haupt_org = wrap_db_fetch($sql);
		if ($haupt_org) {
			$found = true;
			// Unterorganisationen?
			$contact_ids = wrap_db_children(
				$haupt_org['contact_id'],
				sprintf('SELECT contact_id
				FROM contacts WHERE mother_contact_id IN (%%s)
				AND contact_category_id = %d
				AND ISNULL(end_date)', wrap_category_id('contact/federation'))
			);
			$condition = sprintf('AND organisationen.mother_contact_id IN (%s)', implode(',', $contact_ids));
			$data['title'] = $haupt_org['contact'];
			$data['zoomtofit'] = true;
		} else {
			$categories = mf_clubs_from_category($params[0]);
			if ($categories) {
				$found = true;
				$sql = 'SELECT contact_id FROM contacts
					LEFT JOIN auszeichnungen USING (contact_id)
					WHERE auszeichnung_category_id IN (%s)';
				$sql = sprintf($sql, implode(',', array_keys($categories)));
				$contact_ids = wrap_db_fetch($sql, 'contact_id', 'single value');
				if (!$contact_ids) return false;

				$condition_cc = 'AND contacts_contacts.sequence = 1';
				$condition = sprintf('AND organisationen.contact_id IN (%s)', implode(',', $contact_ids));
				$category = reset($categories);
				$data['title'] = $category['category'];
				$data['zoomtofit'] = false;
				$data['description'] = $category['description'];
				if (count($categories) === 1) {
					$data['links'][] = [
						'url' => '../auszeichnung-und-foerderung/',
						'title' => 'Übersichtskarte: Alle Auszeichnungen und Förderungen'
					];
				} else {
					foreach ($categories as $category) {
						if (empty($category['auszeichnungen'])) continue;
						$data['links'][] = [
							'url' => '../'.$category['path'].'/',
							'title' => $category['category'].' ('.$category['auszeichnungen'].')'
						];
					}
				}
			} else {
				if (empty($_GET['q'])) $_GET['q'] = urldecode($params[0]);
				$page['url_ending'] = 'none';
			}
		}
	}

	$data['noindex'] = false;
	if (!$found) {
		$condition = (isset($_GET['q']) AND $_GET['q'] !== '') ? mod_clubs_vereine_condition($_GET['q']) : '';
		$data['title'] = NULL;
		$page['query_strings'][] = 'q';
		if ($condition) {
			if (!empty($condition[0]['boundingbox'])) {
				$data['boundingbox'] = sprintf(
					'[[%s, %s], [%s, %s]]'
					, $condition[0]['boundingbox'][0], $condition[0]['boundingbox'][2]
					, $condition[0]['boundingbox'][1], $condition[0]['boundingbox'][3]
				);
				$data['maxzoom'] = 12;
			} else {
				$data['zoomtofit'] = true;
			}
			$data['noindex'] = true;
		}
	}
	
	if (!$condition AND !empty($_GET['lat']) AND !empty($_GET['lon'])) {
		$condition = [];
		$condition[] = [
			'lat' => $_GET['lat'], 'lon' => $_GET['lon']
		];
		$data['boundingbox'] = sprintf(
			'[[%s, %s], [%s, %s]]'
			, $_GET['lat'], $_GET['lon']
			, $_GET['lat'], $_GET['lon']
		);
		$data['noindex'] = true;
	}
	if (is_array($condition)) {
		$data['reselect'] = (count($condition) !== 1) ? $condition : [];
		$result = reset($condition);
		if (!empty($result['boundingbox'])) {
			$data['boundingbox'] = sprintf(
				'[[%s, %s], [%s, %s]]'
				, $result['boundingbox'][0], $result['boundingbox'][2]
				, $result['boundingbox'][1], $result['boundingbox'][3]
			);
		}
		$data['maxzoom'] = 13;
		$condition = 'HAVING distance <= %d ORDER BY distance';
		$orte_umkreissuche_km = 5;
		$condition = sprintf($condition, $orte_umkreissuche_km); 
		$having = ', 6371 * (ACOS(SIN(%s*Pi()/180)*SIN(latitude*Pi()/180)+COS(%s*Pi()/180)*COS(latitude*Pi()/180)*COS((%s-longitude)*Pi()/180))) AS distance';
		$having = sprintf($having, $result['lat'], $result['lat'], $result['lon']);
	}

	$sql = 'SELECT organisationen.contact AS title, places.contact AS veranstaltungsort
			, latitude AS x_latitude, longitude AS y_longitude
			, SUBSTRING_INDEX(categories.path, "/", -1) AS category
			, members, members_female AS female, members_u25 AS u25, (YEAR(CURDATE()) - avg_byear) AS avg_age, avg_rating
			, organisationen.identifier
			, (SELECT IFNULL(COUNT(auszeichnung_id), NULL) FROM auszeichnungen
				WHERE auszeichnungen.contact_id = organisationen.contact_id) AS auszeichnungen
			, organisationen.contact_id
			%s %s
		FROM contacts organisationen
		LEFT JOIN vereinsdb_stats USING (contact_id)
		LEFT JOIN contacts_contacts
			ON contacts_contacts.main_contact_id = organisationen.contact_id
			AND contacts_contacts.published = "yes"
			%s
		LEFT JOIN contacts places
			ON contacts_contacts.contact_id = places.contact_id
		JOIN addresses
			ON IFNULL(places.contact_id, organisationen.contact_id) = addresses.contact_id
		JOIN categories
			ON organisationen.contact_category_id = categories.category_id
		WHERE ISNULL(organisationen.end_date)
		AND NOT ISNULL(latitude) AND NOT ISNULL(longitude)
		AND categories.parameters LIKE "%%&organisation=1%%"
		%s
	';
	$csql = sprintf($sql, $extra_field, $having, $condition_cc, $condition);
	$data['coordinates'] = wrap_db_fetch($csql, '_dummy_', 'numeric');
	if (!$data['coordinates']) {
		if ($having) {
			while ($orte_umkreissuche_km < 60) {
				$condition = 'HAVING distance <= %d ORDER BY distance';
				$orte_umkreissuche_km += 5;
				switch ($orte_umkreissuche_km) {
					case 10: $data['maxzoom'] = 12; break;
					case 15: $data['maxzoom'] = 11; break;
					case 30: $data['maxzoom'] = 10; break;
					case 40: $data['maxzoom'] = 9; break;
					case 50: $data['maxzoom'] = 8; break;
				}
				$condition = sprintf($condition, $orte_umkreissuche_km); 
				$csql = sprintf($sql, $extra_field, $having, $condition_cc, $condition);
				$data['coordinates'] = wrap_db_fetch($csql, '_dummy_', 'numeric');
				if ($data['coordinates']) break;
			}
		}
	}

	if ($content === 'geojson') return mod_clubs_vereine_json($data['coordinates'], $data['geojson']);

	if (!$data['coordinates']) {
		if (!empty($_GET['q'])) {
			$qs = explode(' ', wrap_db_escape($_GET['q']));
			// Verein direkt?
			$sql = 'SELECT contact_id, identifier
				FROM contacts
				LEFT JOIN categories
					ON contacts.contact_category_id = categories.category_id
				WHERE contact LIKE "%%%s%%"
				AND categories.parameters LIKE "%%&organisation=1%%"
				AND ISNULL(end_date)';
			$sql = sprintf($sql, implode('%', $qs));
			$verein = wrap_db_fetch($sql);
			if (!$verein) {
				$q = wrap_filename($_GET['q'], '', ['-' => '']);
				$sql = 'SELECT contact_id, identifier
				FROM contacts
				LEFT JOIN categories
					ON contacts.contact_category_id = categories.category_id
				WHERE REPLACE(identifier, "-", "") LIKE "%%%s%%"
				AND categories.parameters LIKE "%%&organisation=1%%"
				AND ISNULL(end_date)';
				$sql = sprintf($sql, wrap_db_escape($q));
				$verein = wrap_db_fetch($sql);
			}
			if (!$verein) {
				$change = false;
				foreach ($qs as $index => $qstring) {
					if (strlen($qstring) > 3) continue;
					unset ($qs[$index]);
					$change = true;
				}
				if ($change) {
					$sql = 'SELECT contact_id, identifier
						FROM contacts
						LEFT JOIN categories
							ON contacts.contact_category_id = categories.category_id
						WHERE contact LIKE "%%%s%%"
						AND categories.parameters LIKE "%%&organisation=1%%"
						AND ISNULL(end_date)';
					$sql = sprintf($sql, implode('%', $qs));
					$verein = wrap_db_fetch($sql);
				}
			}
			if ($verein) {
				return wrap_redirect(sprintf('/%s/', $verein['identifier']));
			}
		}
		if (!empty($haupt_org) AND count($contact_ids) > 1) {
			return wrap_redirect(sprintf('/%s/liste/', $params[0]), 307);
		}
		$page['status'] = 404;
		$data['not_found'] = true;
	}
	
	$data['q'] = isset($_GET['q']) ? $_GET['q'] : false;
	if ($data['q'] === '0') $data['q'] = 0;
	$data['lat'] = isset($_GET['lat']) ? $_GET['lat'] : false;
	$data['lon'] = isset($_GET['lon']) ? $_GET['lon'] : false;
	$data['places'] = count($data['coordinates']);
	if (!$data['title']) {
		$data['verbaende'] = !empty($_GET['q']) ? mod_clubs_vereine_verbaende($_GET['q'], $data['coordinates']) : [];
	}
	
	$sql = 'SELECT COUNT(*) FROM contacts
		WHERE contact_category_id IN (%d, %d) AND ISNULL(end_date)';
	$sql = sprintf($sql
		, wrap_category_id('contact/club')
		, wrap_category_id('contact/chess-department')
	);
	$data['vereine'] = wrap_db_fetch($sql, '', 'single value');

	$page['dont_show_h1'] = true;
	if ($data['title']) {
		$page['title'] = 'Schachvereine: '.$data['title'];
		$page['breadcrumbs'][] = $data['title'];
	} else {
		$page['title'] = 'Schachvereine und Schulschachgruppen';
		if (!empty($params[0])) {
			$page['breadcrumbs'][] = 'Suche: '.wrap_html_escape($params[0]);
		}
	}
	if ($data['q'] OR $data['q'] === '0' OR $data['q'] === 0)
		$page['title'] .= sprintf(': Suche nach »%s«', wrap_html_escape($data['q']));
	if ($data['lat'] AND $data['lon']) $page['title'] .= sprintf(', Koordinaten %s/%s', wrap_latitude($data['lat']), wrap_longitude($data['lon']));
	$page['query_strings'][] = 'lat';
	$page['query_strings'][] = 'lon';
	$page['query_strings'][] = 'embed';
	$page['head'] = wrap_template('clubs-head');
	$page['extra']['body_attributes'] = 'id="map"';
	if ($data['noindex']) {
		$page['meta'][] = [
			'name' => 'robots', 'content' => 'noindex,follow'
		];
	}
	if (!empty($_GET) AND array_key_exists('embed', $_GET)) {
		$data['embed'] = true;
		$page['extra']['body_attributes'] = 'id="map" class="embed"';
	}
	$page['text'] = wrap_template('vereine', $data);
	return $page;
}

/**
 * create condition for SQL query
 *
 * @param string $q
 * @return mixed string: SQL condition, array: list of results
 */
function mod_clubs_vereine_condition($q) {
	if ($q === 'deutschland') $q = '';
	$condition = '';
	if (strstr($q, '%')) return "AND 1=2"; // no % allowed, most of the time hackers

	// replace trailing asterisks
	while (substr($q, -1) === '*') $q = substr($q, 0, -1);

	if (strstr($q, '/')) $q = str_replace('/', ' ', $q);
	if (strstr($q, ' ')) {
		$q = mod_clubs_vereine_condition_parts($q);
	}

	// replace small 'o's which were 0s in the typewriter age
	if (is_string($q) AND strlen($q) < 6 AND preg_match('~^[0-9o]+$~', $q)) {
		$q = str_replace('o', '0', $q);
	} elseif (is_string($q) AND strlen($q) < 6 AND preg_match('~^[0-9O]+$~', $q)) {
		$q = str_replace('O', '0', $q);
	}
	if (is_numeric($q)) {
		if (strlen($q) === 4) {
			$q .= '0'; // just as a help for people who omit a last number
		}
		if (strlen($q) === 5 AND substr($q, -3) === '000') $q = substr($q, 0, 2);
		if (substr($q, 0, 2) === '11') $q = '10'; // 11 is government in Berlin
		if (strlen($q) === 5) {
			$counter = 0;
			$postcode = $q;
			$url = 'postalcode=%s&countrycodes=de&format=jsonv2&accept-language=de&limit=1';
			while (!$condition) {
				// try postcodes nearby, +1, -1 to +8 -8
				$condition = mod_clubs_vereine_geocode($url, $postcode);
				$counter++;
				$postcode = sprintf('%05d', $counter & 1 ? $q - ceil($counter/2) : $q + ceil($counter/2));
				if ($counter > 16) break;
			}
		}
		if (!$condition) {
			while (substr($q, -1) === '0' AND strlen($q) > 2) {
				$q = substr($q, 0, -1);
			}
			$condition = sprintf(' AND addresses.postcode LIKE "%s%%"', $q);
		}
	} elseif (is_array($q)) {
		$condition = [];
		foreach ($q as $postcode) {
			$condition[] .= sprintf('addresses.postcode LIKE "%s%%"', $postcode);
		}
		$condition = sprintf('AND (%s)', implode(' OR ', $condition));
	} else {
		// city= is experimental and does not work with Bremen, München
		// $url = 'http://nominatim.openstreetmap.org/search.php?city=%s&country=de&format=jsonv2';
		$url = 'q=%s&countrycodes=de&format=jsonv2&accept-language=de&limit=50';
		$wanted = [
			'administrative', 'city', 'suburb', 'village', 'hamlet', 'town',
			'neighbourhood', 'county'
		];
		$condition = mod_clubs_vereine_geocode($url, $q, $wanted);
		if (!$condition) {
			// if it has a space in the name, test all parts separately
			// to avoid cases like Bremen Nord != Bremen-Nord
			$condition = '';
			if (str_starts_with($q, '"') AND str_ends_with($q, '"')) {
				$qs[0] = substr($q, 1, -1);
			} else {
				$qs = explode(' ', $q);
			}
			$condition .= ' AND ((';
			foreach ($qs as $index => $q) {
				if ($index) $condition .= ' AND ';
				$condition .= sprintf('organisationen.contact LIKE "%%%s%%"', wrap_db_escape($q));
				// add support for ae = ä etc.
				$condition .= sprintf('OR organisationen.identifier LIKE LOWER(_latin1"%%%s%%")', wrap_db_escape($q));
				$condition .= sprintf('OR (SELECT identification FROM contactdetails
					WHERE contactdetails.contact_id = organisationen.contact_id
					AND provider_category_id = %d LIKE "%%%s%%")', wrap_category_id('provider/website'), wrap_db_escape($q));
			}
			$condition .= ') OR (';
			foreach ($qs as $index => $q) {
				if ($index) $condition .= ' AND ';
				$condition .= sprintf('place LIKE "%%%s%%"', wrap_db_escape($q));
				$condition .= sprintf('OR (SELECT identification FROM contactdetails
					WHERE contactdetails.contact_id = organisationen.contact_id
					AND provider_category_id = %d LIKE "%%%s%%")', wrap_category_id('provider/website'), wrap_db_escape($q));
			}
			$condition .= '))';
		}
	}
	return $condition;
}

/**
 * geocode search string
 * returns places for first result, rest of results will be shown as list
 *
 * @param string $url
 * @param string $q
 * @param array $wanted (optional)
 * @return array
 * @see http://wiki.openstreetmap.org/wiki/Nominatim_usage_policy
 */
function mod_clubs_vereine_geocode($url, $q, $wanted = []) {
	global $zz_setting;
	require_once $zz_setting['core'].'/syndication.inc.php';

	$url = 'https://nominatim.openstreetmap.org/search.php?'.$url;
	$url = sprintf($url, rawurlencode($q));
	wrap_lock_wait('nominatim', 1); // just 1 request per second
	$results = wrap_syndication_get($url);
	wrap_unlock('nominatim');
	unset($results['_']);
	if ($wanted) {
		foreach ($results as $index => $result) {
			if (!in_array($result['type'], $wanted)) {
				unset($results[$index]);
				continue;
			}
		}
	}
	return $results;
}

/**
 * Auswertung einer Suchabfrage mit mehreren Worten
 * Falls fünfstellige Zahl dabei: PLZ, alles andere ignorieren
 * Wörter kürzer als drei Zeichen werden ignoriert
 *
 * @param string $q
 * @return mixed string $q or array with postcodes
 */
function mod_clubs_vereine_condition_parts($q) {
	$search = explode(' ', $q);
	foreach ($search as $value) {
		if (!is_numeric($value)) continue;
		if (strlen($value) !== 5) continue;
		// Postleitzahl, vergiss den Rest
		return $value;
	}
	foreach ($search as $index => $part) {
		if (mb_strlen($part) <= 2) unset($search[$index]);
	}
	if (!$search) {
		// oops, we need something
		$search = explode(' ', $q);
		$all_numeric = true;
		foreach ($search as $index => $part) {
			// postcode?
			if (!is_numeric($part)) $all_numeric = false;
		}
		if ($all_numeric) return $search;
		return $q; // unchanged
	}
	$q = implode(' ', $search);
	return $q;
}

/**
 * Ausgabe der Vereine als GeoJSON-Datei
 *
 * @param array $coordinates Liste der Vereine mit Koordinaten
 * @return array $page
 */
function mod_clubs_vereine_json($coordinates, $geojson) {
	if (!$coordinates) return false;

	$page['content_type'] = 'geojson';
	$page['query_strings'][] = 'q';
	$page['ending'] = 'none';
	$page['headers']['filename'] = sprintf('%s.geojson', $geojson);

	$conditional_properties = [
		'members', 'u25', 'female', 'avg_age', 'avg_rating'
	];
	$data = [];
	$data['type'] = 'FeatureCollection';
	foreach ($coordinates as $index => $coordinate) {
		$properties = [
			'org' => $coordinate['title'],
			'identifier' => $coordinate['identifier'],
			'category' => $coordinate['category'],
			'awards' => intval($coordinate['auszeichnungen']),
		];
		foreach ($conditional_properties as $prop) {
			if (!$coordinate[$prop]) continue;
			$properties[$prop] = intval($coordinate[$prop]);
		}
		$data['features'][] = [
			'type' => 'Feature',
			'id' => $index,
			'properties' => $properties,
			'geometry' => [
				'type' => 'Point',
				'coordinates' => [
					floatval($coordinate['y_longitude']),
					floatval($coordinate['x_latitude'])
				]
			]
		];
	}
	$page['text'] = json_encode($data);
	return $page;
}

/**
 * Suche nach Verbänden
 *
 * @param string $q
 * @param array $coordinates
 * @return array
 */
function mod_clubs_vereine_verbaende($q, $coordinates) {
	$sql = 'SELECT o.contact_id, o.identifier, o.contact
				, h.contact AS main_contact
				, o.contact_category_id
				, (SELECT COUNT(*) FROM contacts WHERE mother_contact_id = o.contact_id) AS rang
		FROM contacts o
		LEFT JOIN categories
			ON o.contact_category_id = categories.category_id
		LEFT JOIN contacts h
			ON o.mother_contact_id = h.contact_id
		WHERE o.contact LIKE "%%%s%%"
		AND categories.parameters LIKE "%%&organisation=1%%"
		AND ISNULL(o.end_date)
		ORDER BY rang DESC, o.identifier
	';
	$sql = sprintf($sql,
		wrap_db_escape($q)
	);
	$verbaende = wrap_db_fetch($sql, 'contact_id');
	foreach ($coordinates as $spielort) {
		if (in_array($spielort['contact_id'], array_keys($verbaende))) {
			// sind schon auf Karte
			unset($verbaende[$spielort['contact_id']]);
		}
	}
	// zuviele? dann nur Verbände anzeigen
	if (count($verbaende) > 5) {
		foreach ($verbaende as $id => $verband) {
			if ($verband['contact_category_id'] !== wrap_category_id('contact/federation'))
				unset($verbaende[$id]);
		}
	}
	
	return $verbaende;
}
