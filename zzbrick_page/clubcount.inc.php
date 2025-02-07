<?php

/**
 * clubs module
 * page element: show number of clubs
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/clubs
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2020-2021, 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function page_clubcount($params) {
	$sql = 'SELECT COUNT(*) FROM contacts
		WHERE contact_category_id IN (/*_ID categories contact/club _*/, /*_ID categories contact/chess-department _*/)
		AND ISNULL(end_date)';
	$clubs = wrap_db_fetch($sql, '', 'single value');
	return $clubs;
}
