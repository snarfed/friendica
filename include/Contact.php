<?php

use Friendica\App;
use Friendica\Core\System;
use Friendica\Network\Probe;

// Included here for completeness, but this is a very dangerous operation.
// It is the caller's responsibility to confirm the requestor's intent and
// authorisation to do this.

function user_remove($uid) {
	if (!$uid) {
		return;
	}

	logger('Removing user: ' . $uid);

	$r = dba::select('user', array(), array('uid' => $uid), array("limit" => 1));

	call_hooks('remove_user',$r);

	// save username (actually the nickname as it is guaranteed
	// unique), so it cannot be re-registered in the future.

	dba::insert('userd', array('username' => $r['nickname']));

	// The user and related data will be deleted in "cron_expire_and_remove_users" (cronjobs.php)
	q("UPDATE `user` SET `account_removed` = 1, `account_expires_on` = UTC_TIMESTAMP() WHERE `uid` = %d", intval($uid));
	proc_run(PRIORITY_HIGH, "include/notifier.php", "removeme", $uid);

	// Send an update to the directory
	proc_run(PRIORITY_LOW, "include/directory.php", $r['url']);

	if($uid == local_user()) {
		unset($_SESSION['authenticated']);
		unset($_SESSION['uid']);
		goaway(System::baseUrl());
	}
}


function contact_remove($id) {

	// We want just to make sure that we don't delete our "self" contact
	$r = q("SELECT `uid` FROM `contact` WHERE `id` = %d AND NOT `self` LIMIT 1",
		intval($id)
	);
	if (!dbm::is_result($r) || !intval($r[0]['uid'])) {
		return;
	}

	$archive = get_pconfig($r[0]['uid'], 'system','archive_removed_contacts');
	if ($archive) {
		q("update contact set `archive` = 1, `network` = 'none', `writable` = 0 where id = %d",
			intval($id)
		);
		return;
	}

	dba::delete('contact', array('id' => $id));

	// Delete the rest in the background
	proc_run(PRIORITY_LOW, 'include/remove_contact.php', $id);
}


// sends an unfriend message. Does not remove the contact

function terminate_friendship($user,$self,$contact) {

	/// @TODO Get rid of this, include/datetime.php should care about it by itself
	$a = get_app();

	require_once 'include/datetime.php';

	if ($contact['network'] === NETWORK_OSTATUS) {

		require_once 'include/ostatus.php';

		// create an unfollow slap
		$item = array();
		$item['verb'] = NAMESPACE_OSTATUS."/unfollow";
		$item['follow'] = $contact["url"];
		$slap = ostatus::salmon($item, $user);

		if ((x($contact,'notify')) && (strlen($contact['notify']))) {
			require_once 'include/salmon.php';
			slapper($user,$contact['notify'],$slap);
		}
	} elseif ($contact['network'] === NETWORK_DIASPORA) {
		require_once 'include/diaspora.php';
		Diaspora::send_unshare($user,$contact);
	} elseif ($contact['network'] === NETWORK_DFRN) {
		require_once 'include/dfrn.php';
		dfrn::deliver($user,$contact,'placeholder', 1);
	}

}


// Contact has refused to recognise us as a friend. We will start a countdown.
// If they still don't recognise us in 32 days, the relationship is over,
// and we won't waste any more time trying to communicate with them.
// This provides for the possibility that their database is temporarily messed
// up or some other transient event and that there's a possibility we could recover from it.

function mark_for_death($contact) {

	if($contact['archive'])
		return;

	if ($contact['term-date'] <= NULL_DATE) {
		q("UPDATE `contact` SET `term-date` = '%s' WHERE `id` = %d",
				dbesc(datetime_convert()),
				intval($contact['id'])
		);

		if ($contact['url'] != '') {
			q("UPDATE `contact` SET `term-date` = '%s'
				WHERE `nurl` = '%s' AND `term-date` <= '1000-00-00'",
					dbesc(datetime_convert()),
					dbesc(normalise_link($contact['url']))
			);
		}
	} else {

		/// @todo
		/// We really should send a notification to the owner after 2-3 weeks
		/// so they won't be surprised when the contact vanishes and can take
		/// remedial action if this was a serious mistake or glitch

		/// @todo
		/// Check for contact vitality via probing

		$expiry = $contact['term-date'] . ' + 32 days ';
		if(datetime_convert() > datetime_convert('UTC','UTC',$expiry)) {

			// relationship is really truly dead.
			// archive them rather than delete
			// though if the owner tries to unarchive them we'll start the whole process over again

			q("UPDATE `contact` SET `archive` = 1 WHERE `id` = %d",
				intval($contact['id'])
			);

			if ($contact['url'] != '') {
				q("UPDATE `contact` SET `archive` = 1 WHERE `nurl` = '%s'",
					dbesc(normalise_link($contact['url']))
				);
			}
		}
	}

}

function unmark_for_death($contact) {

	$r = q("SELECT `term-date` FROM `contact` WHERE `id` = %d AND `term-date` > '%s'",
		intval($contact['id']),
		dbesc('1000-00-00 00:00:00')
	);

	// We don't need to update, we never marked this contact as dead
	if (!dbm::is_result($r)) {
		return;
	}

	// It's a miracle. Our dead contact has inexplicably come back to life.
	q("UPDATE `contact` SET `term-date` = '%s' WHERE `id` = %d",
		dbesc(NULL_DATE),
		intval($contact['id'])
	);

	if ($contact['url'] != '') {
		q("UPDATE `contact` SET `term-date` = '%s' WHERE `nurl` = '%s'",
			dbesc(NULL_DATE),
			dbesc(normalise_link($contact['url']))
		);
	}
}

/**
 * @brief Get contact data for a given profile link
 *
 * The function looks at several places (contact table and gcontact table) for the contact
 * It caches its result for the same script execution to prevent duplicate calls
 *
 * @param string $url The profile link
 * @param int $uid User id
 * @param array $default If not data was found take this data as default value
 *
 * @return array Contact data
 */
function get_contact_details_by_url($url, $uid = -1, $default = array()) {
	static $cache = array();

	if ($url == '') {
		return $default;
	}

	if ($uid == -1) {
		$uid = local_user();
	}

	if (isset($cache[$url][$uid])) {
		return $cache[$url][$uid];
	}

	$ssl_url = str_replace('http://', 'https://', $url);

	// Fetch contact data from the contact table for the given user
	$s = dba::p("SELECT `id`, `id` AS `cid`, 0 AS `gid`, 0 AS `zid`, `uid`, `url`, `nurl`, `alias`, `network`, `name`, `nick`, `addr`, `location`, `about`, `xmpp`,
			`keywords`, `gender`, `photo`, `thumb`, `micro`, `forum`, `prv`, (`forum` | `prv`) AS `community`, `contact-type`, `bd` AS `birthday`, `self`
		FROM `contact` WHERE `nurl` = ? AND `uid` = ?",
			normalise_link($url), $uid);
	$r = dba::inArray($s);

	// Fetch contact data from the contact table for the given user, checking with the alias
	if (!dbm::is_result($r)) {
		$s = dba::p("SELECT `id`, `id` AS `cid`, 0 AS `gid`, 0 AS `zid`, `uid`, `url`, `nurl`, `alias`, `network`, `name`, `nick`, `addr`, `location`, `about`, `xmpp`,
				`keywords`, `gender`, `photo`, `thumb`, `micro`, `forum`, `prv`, (`forum` | `prv`) AS `community`, `contact-type`, `bd` AS `birthday`, `self`
			FROM `contact` WHERE `alias` IN (?, ?, ?) AND `uid` = ?",
				normalise_link($url), $url, $ssl_url, $uid);
		$r = dba::inArray($s);
	}

	// Fetch the data from the contact table with "uid=0" (which is filled automatically)
	if (!dbm::is_result($r)) {
		$s = dba::p("SELECT `id`, 0 AS `cid`, `id` AS `zid`, 0 AS `gid`, `uid`, `url`, `nurl`, `alias`, `network`, `name`, `nick`, `addr`, `location`, `about`, `xmpp`,
			`keywords`, `gender`, `photo`, `thumb`, `micro`, `forum`, `prv`, (`forum` | `prv`) AS `community`, `contact-type`, `bd` AS `birthday`, 0 AS `self`
			FROM `contact` WHERE `nurl` = ? AND `uid` = 0",
				normalise_link($url));
		$r = dba::inArray($s);
	}

	// Fetch the data from the contact table with "uid=0" (which is filled automatically) - checked with the alias
	if (!dbm::is_result($r)) {
		$s = dba::p("SELECT `id`, 0 AS `cid`, `id` AS `zid`, 0 AS `gid`, `uid`, `url`, `nurl`, `alias`, `network`, `name`, `nick`, `addr`, `location`, `about`, `xmpp`,
			`keywords`, `gender`, `photo`, `thumb`, `micro`, `forum`, `prv`, (`forum` | `prv`) AS `community`, `contact-type`, `bd` AS `birthday`, 0 AS `self`
			FROM `contact` WHERE `alias` IN (?, ?, ?) AND `uid` = 0",
				normalise_link($url), $url, $ssl_url);
		$r = dba::inArray($s);
	}

	// Fetch the data from the gcontact table
	if (!dbm::is_result($r)) {
		$s = dba::p("SELECT 0 AS `id`, 0 AS `cid`, `id` AS `gid`, 0 AS `zid`, 0 AS `uid`, `url`, `nurl`, `alias`, `network`, `name`, `nick`, `addr`, `location`, `about`, '' AS `xmpp`,
			`keywords`, `gender`, `photo`, `photo` AS `thumb`, `photo` AS `micro`, `community` AS `forum`, 0 AS `prv`, `community`, `contact-type`, `birthday`, 0 AS `self`
			FROM `gcontact` WHERE `nurl` = ?",
				normalise_link($url));
		$r = dba::inArray($s);
	}

	if (dbm::is_result($r)) {
		// If there is more than one entry we filter out the connector networks
		if (count($r) > 1) {
			foreach ($r AS $id => $result) {
				if ($result["network"] == NETWORK_STATUSNET) {
					unset($r[$id]);
				}
			}
		}

		$profile = array_shift($r);

		// "bd" always contains the upcoming birthday of a contact.
		// "birthday" might contain the birthday including the year of birth.
		if ($profile["birthday"] > '0001-01-01') {
			$bd_timestamp = strtotime($profile["birthday"]);
			$month = date("m", $bd_timestamp);
			$day = date("d", $bd_timestamp);

			$current_timestamp = time();
			$current_year = date("Y", $current_timestamp);
			$current_month = date("m", $current_timestamp);
			$current_day = date("d", $current_timestamp);

			$profile["bd"] = $current_year."-".$month."-".$day;
			$current = $current_year."-".$current_month."-".$current_day;

			if ($profile["bd"] < $current) {
				$profile["bd"] = (++$current_year)."-".$month."-".$day;
			}
		} else {
			$profile["bd"] = '0001-01-01';
		}
	} else {
		$profile = $default;
	}

	if (($profile["photo"] == "") && isset($default["photo"])) {
		$profile["photo"] = $default["photo"];
	}

	if (($profile["name"] == "") && isset($default["name"])) {
		$profile["name"] = $default["name"];
	}

	if (($profile["network"] == "") && isset($default["network"])) {
		$profile["network"] = $default["network"];
	}

	if (($profile["thumb"] == "") && isset($profile["photo"])) {
		$profile["thumb"] = $profile["photo"];
	}

	if (($profile["micro"] == "") && isset($profile["thumb"])) {
		$profile["micro"] = $profile["thumb"];
	}

	if ((($profile["addr"] == "") || ($profile["name"] == "")) && ($profile["gid"] != 0) &&
		in_array($profile["network"], array(NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS))) {
		proc_run(PRIORITY_LOW, "include/update_gcontact.php", $profile["gid"]);
	}

	// Show contact details of Diaspora contacts only if connected
	if (($profile["cid"] == 0) && ($profile["network"] == NETWORK_DIASPORA)) {
		$profile["location"] = "";
		$profile["about"] = "";
		$profile["gender"] = "";
		$profile["birthday"] = '0001-01-01';
	}

	$cache[$url][$uid] = $profile;

	return $profile;
}

/**
 * @brief Get contact data for a given address
 *
 * The function looks at several places (contact table and gcontact table) for the contact
 *
 * @param string $addr The profile link
 * @param int $uid User id
 *
 * @return array Contact data
 */
function get_contact_details_by_addr($addr, $uid = -1) {
	static $cache = array();

	if ($addr == '') {
		return array();
	}

	if ($uid == -1) {
		$uid = local_user();
	}

	// Fetch contact data from the contact table for the given user
	$r = q("SELECT `id`, `id` AS `cid`, 0 AS `gid`, 0 AS `zid`, `uid`, `url`, `nurl`, `alias`, `network`, `name`, `nick`, `addr`, `location`, `about`, `xmpp`,
			`keywords`, `gender`, `photo`, `thumb`, `micro`, `forum`, `prv`, (`forum` | `prv`) AS `community`, `contact-type`, `bd` AS `birthday`, `self`
		FROM `contact` WHERE `addr` = '%s' AND `uid` = %d",
			dbesc($addr), intval($uid));

	// Fetch the data from the contact table with "uid=0" (which is filled automatically)
	if (!dbm::is_result($r))
		$r = q("SELECT `id`, 0 AS `cid`, `id` AS `zid`, 0 AS `gid`, `uid`, `url`, `nurl`, `alias`, `network`, `name`, `nick`, `addr`, `location`, `about`, `xmpp`,
			`keywords`, `gender`, `photo`, `thumb`, `micro`, `forum`, `prv`, (`forum` | `prv`) AS `community`, `contact-type`, `bd` AS `birthday`, 0 AS `self`
			FROM `contact` WHERE `addr` = '%s' AND `uid` = 0",
				dbesc($addr));

	// Fetch the data from the gcontact table
	if (!dbm::is_result($r))
		$r = q("SELECT 0 AS `id`, 0 AS `cid`, `id` AS `gid`, 0 AS `zid`, 0 AS `uid`, `url`, `nurl`, `alias`, `network`, `name`, `nick`, `addr`, `location`, `about`, '' AS `xmpp`,
			`keywords`, `gender`, `photo`, `photo` AS `thumb`, `photo` AS `micro`, `community` AS `forum`, 0 AS `prv`, `community`, `contact-type`, `birthday`, 0 AS `self`
			FROM `gcontact` WHERE `addr` = '%s'",
				dbesc($addr));

	if (!dbm::is_result($r)) {
		$data = Probe::uri($addr);

		$profile = get_contact_details_by_url($data['url'], $uid);
	} else {
		$profile = $r[0];
	}

	return $profile;
}

if (! function_exists('contact_photo_menu')) {
function contact_photo_menu($contact, $uid = 0)
{
	$a = get_app();

	$contact_url = '';
	$pm_url = '';
	$status_link = '';
	$photos_link = '';
	$posts_link = '';
	$contact_drop_link = '';
	$poke_link = '';

	if ($uid == 0) {
		$uid = local_user();
	}

	if ($contact['uid'] != $uid) {
		if ($uid == 0) {
			$profile_link = zrl($contact['url']);
			$menu = Array('profile' => array(t('View Profile'), $profile_link, true));

			return $menu;
		}

		$r = q("SELECT * FROM `contact` WHERE `nurl` = '%s' AND `network` = '%s' AND `uid` = %d",
			dbesc($contact['nurl']), dbesc($contact['network']), intval($uid));
		if ($r) {
			return contact_photo_menu($r[0], $uid);
		} else {
			$profile_link = zrl($contact['url']);
			$connlnk = 'follow/?url='.$contact['url'];
			$menu = array(
				'profile' => array(t('View Profile'), $profile_link, true),
				'follow' => array(t('Connect/Follow'), $connlnk, true)
			);

			return $menu;
		}
	}

	$sparkle = false;
	if ($contact['network'] === NETWORK_DFRN) {
		$sparkle = true;
		$profile_link = System::baseUrl() . '/redir/' . $contact['id'];
	} else {
		$profile_link = $contact['url'];
	}

	if ($profile_link === 'mailbox') {
		$profile_link = '';
	}

	if ($sparkle) {
		$status_link = $profile_link . '?url=status';
		$photos_link = $profile_link . '?url=photos';
		$profile_link = $profile_link . '?url=profile';
	}

	if (in_array($contact['network'], array(NETWORK_DFRN, NETWORK_DIASPORA))) {
		$pm_url = System::baseUrl() . '/message/new/' . $contact['id'];
	}

	if ($contact['network'] == NETWORK_DFRN) {
		$poke_link = System::baseUrl() . '/poke/?f=&c=' . $contact['id'];
	}

	$contact_url = System::baseUrl() . '/contacts/' . $contact['id'];

	$posts_link = System::baseUrl() . '/contacts/' . $contact['id'] . '/posts';
	$contact_drop_link = System::baseUrl() . '/contacts/' . $contact['id'] . '/drop?confirm=1';

	/**
	 * menu array:
	 * "name" => [ "Label", "link", (bool)Should the link opened in a new tab? ]
	 */
	$menu = array(
		'status' => array(t("View Status"), $status_link, true),
		'profile' => array(t("View Profile"), $profile_link, true),
		'photos' => array(t("View Photos"), $photos_link, true),
		'network' => array(t("Network Posts"), $posts_link, false),
		'edit' => array(t("View Contact"), $contact_url, false),
		'drop' => array(t("Drop Contact"), $contact_drop_link, false),
		'pm' => array(t("Send PM"), $pm_url, false),
		'poke' => array(t("Poke"), $poke_link, false),
	);


	$args = array('contact' => $contact, 'menu' => &$menu);

	call_hooks('contact_photo_menu', $args);

	$menucondensed = array();

	foreach ($menu AS $menuname => $menuitem) {
		if ($menuitem[1] != '') {
			$menucondensed[$menuname] = $menuitem;
		}
	}

	return $menucondensed;
}}


function random_profile() {
	$r = q("SELECT `url` FROM `gcontact` WHERE `network` = '%s'
				AND `last_contact` >= `last_failure`
				AND `updated` > UTC_TIMESTAMP - INTERVAL 1 MONTH
			ORDER BY rand() LIMIT 1",
		dbesc(NETWORK_DFRN));

	if (dbm::is_result($r))
		return dirname($r[0]['url']);
	return '';
}


function contacts_not_grouped($uid,$start = 0,$count = 0) {

	if(! $count) {
		$r = q("select count(*) as total from contact where uid = %d and self = 0 and id not in (select distinct(`contact-id`) from group_member where uid = %d) ",
			intval($uid),
			intval($uid)
		);

		return $r;


	}

	$r = q("select * from contact where uid = %d and self = 0 and id not in (select distinct(`contact-id`) from group_member where uid = %d) and blocked = 0 and pending = 0 limit %d, %d",
		intval($uid),
		intval($uid),
		intval($start),
		intval($count)
	);

	return $r;
}

/**
 * @brief Fetch the contact id for a given url and user
 *
 * First lookup in the contact table to find a record matching either `url`, `nurl`,
 * `addr` or `alias`.
 *
 * If there's no record and we aren't looking for a public contact, we quit.
 * If there's one, we check that it isn't time to update the picture else we
 * directly return the found contact id.
 *
 * Second, we probe the provided $url wether it's http://server.tld/profile or
 * nick@server.tld. We quit if we can't get any info back.
 *
 * Third, we create the contact record if it doesn't exist
 *
 * Fourth, we update the existing record with the new data (avatar, alias, nick)
 * if there's any updates
 *
 * @param string $url Contact URL
 * @param integer $uid The user id for the contact (0 = public contact)
 * @param boolean $no_update Don't update the contact
 *
 * @return integer Contact ID
 */
function get_contact($url, $uid = 0, $no_update = false) {
	logger("Get contact data for url ".$url." and user ".$uid." - ".System::callstack(), LOGGER_DEBUG);

	$data = array();
	$contact_id = 0;

	if ($url == '') {
		return 0;
	}

	// We first try the nurl (http://server.tld/nick), most common case
	$contact = dba::select('contact', array('id', 'avatar-date'), array('nurl' => normalise_link($url), 'uid' => $uid), array('limit' => 1));

	// Then the addr (nick@server.tld)
	if (!dbm::is_result($contact)) {
		$contact = dba::select('contact', array('id', 'avatar-date'), array('addr' => $url, 'uid' => $uid), array('limit' => 1));
	}

	// Then the alias (which could be anything)
	if (!dbm::is_result($contact)) {
		// The link could be provided as http although we stored it as https
		$ssl_url = str_replace('http://', 'https://', $url);
		$r = dba::p("SELECT `id`, `avatar-date` FROM `contact` WHERE `alias` IN (?, ?, ?) AND `uid` = ? LIMIT 1",
				$url, normalise_link($url), $ssl_url, $uid);
		$contact = dba::fetch($r);
		dba::close($r);
	}

	if (dbm::is_result($contact)) {
		$contact_id = $contact["id"];

		// Update the contact every 7 days
		$update_contact = ($contact['avatar-date'] < datetime_convert('','','now -7 days'));

		if (!$update_contact || $no_update) {
			return $contact_id;
		}
	} elseif ($uid != 0) {
		// Non-existing user-specific contact, exiting
		return 0;
	}

	$data = Probe::uri($url);

	// Last try in gcontact for unsupported networks
	if (!in_array($data["network"], array(NETWORK_DFRN, NETWORK_OSTATUS, NETWORK_DIASPORA, NETWORK_PUMPIO))) {
		if ($uid != 0) {
			return 0;
		}

		// Get data from the gcontact table
		$gcontacts = dba::select('gcontact', array('name', 'nick', 'url', 'photo', 'addr', 'alias', 'network'),
						array('nurl' => normalise_link($url)), array('limit' => 1));
		if (!dbm::is_result($gcontacts)) {
			return 0;
		}

		$data = array_merge($data, $gcontacts);
	}

	$url = $data["url"];
	if (!$contact_id) {
		dba::insert('contact', array('uid' => $uid, 'created' => datetime_convert(), 'url' => $data["url"],
					'nurl' => normalise_link($data["url"]), 'addr' => $data["addr"],
					'alias' => $data["alias"], 'notify' => $data["notify"], 'poll' => $data["poll"],
					'name' => $data["name"], 'nick' => $data["nick"], 'photo' => $data["photo"],
					'keywords' => $data["keywords"], 'location' => $data["location"], 'about' => $data["about"],
					'network' => $data["network"], 'pubkey' => $data["pubkey"],
					'rel' => CONTACT_IS_SHARING, 'priority' => $data["priority"],
					'batch' => $data["batch"], 'request' => $data["request"],
					'confirm' => $data["confirm"], 'poco' => $data["poco"],
					'name-date' => datetime_convert(), 'uri-date' => datetime_convert(),
					'avatar-date' => datetime_convert(), 'writable' => 1, 'blocked' => 0,
					'readonly' => 0, 'pending' => 0));

		$contacts = q("SELECT `id` FROM `contact` WHERE `nurl` = '%s' AND `uid` = %d ORDER BY `id` LIMIT 2",
				dbesc(normalise_link($data["url"])),
				intval($uid));
		if (!dbm::is_result($contacts)) {
			return 0;
		}

		$contact_id = $contacts[0]["id"];

		// Update the newly created contact from data in the gcontact table
		$gcontact = dba::select('gcontact', array('location', 'about', 'keywords', 'gender'),
					array('nurl' => normalise_link($data["url"])), array('limit' => 1));
		if (dbm::is_result($gcontact)) {
			// Only use the information when the probing hadn't fetched these values
			if ($data['keywords'] != '') {
				unset($gcontact['keywords']);
			}
			if ($data['location'] != '') {
				unset($gcontact['location']);
			}
			if ($data['about'] != '') {
				unset($gcontact['about']);
			}
			dba::update('contact', $gcontact, array('id' => $contact_id));
		}

		if (count($contacts) > 1 && $uid == 0 && $contact_id != 0 && $data["url"] != "") {
			dba::delete('contact', array("`nurl` = ? AND `uid` = 0 AND `id` != ? AND NOT `self`",
				normalise_link($data["url"]), $contact_id));
		}
	}

	require_once "Photo.php";

	update_contact_avatar($data["photo"], $uid, $contact_id);

	$contact = dba::select('contact', array('addr', 'alias', 'name', 'nick', 'keywords', 'location', 'about', 'avatar-date'),
				array('id' => $contact_id), array('limit' => 1));

	// This condition should always be true
	if (!dbm::is_result($contact)) {
		return $contact_id;
	}

	$updated = array('addr' => $data['addr'],
			'alias' => $data['alias'],
			'name' => $data['name'],
			'nick' => $data['nick']);

	if ($data['keywords'] != '') {
		$updated['keywords'] = $data['keywords'];
	}
	if ($data['location'] != '') {
		$updated['location'] = $data['location'];
	}
	if ($data['about'] != '') {
		$updated['about'] = $data['about'];
	}

	if (($data["addr"] != $contact["addr"]) || ($data["alias"] != $contact["alias"])) {
		$updated['uri-date'] = datetime_convert();
	}
	if (($data["name"] != $contact["name"]) || ($data["nick"] != $contact["nick"])) {
		$updated['name-date'] = datetime_convert();
	}

	$updated['avatar-date'] = datetime_convert();

	dba::update('contact', $updated, array('id' => $contact_id), $contact);

	return $contact_id;
}

/**
 * @brief Returns posts from a given gcontact
 *
 * @param App $a argv application class
 * @param int $gcontact_id Global contact
 *
 * @return string posts in HTML
 */
function posts_from_gcontact(App $a, $gcontact_id) {

	require_once 'include/conversation.php';

	// There are no posts with "uid = 0" with connector networks
	// This speeds up the query a lot
	$r = q("SELECT `network` FROM `gcontact` WHERE `id` = %d", dbesc($gcontact_id));
	if (in_array($r[0]["network"], array(NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS, "")))
		$sql = "(`item`.`uid` = 0 OR  (`item`.`uid` = %d AND `item`.`private`))";
	else
		$sql = "`item`.`uid` = %d";

	$r = q("SELECT `item`.`uri`, `item`.*, `item`.`id` AS `item_id`,
			`author-name` AS `name`, `owner-avatar` AS `photo`,
			`owner-link` AS `url`, `owner-avatar` AS `thumb`
		FROM `item`
		WHERE `gcontact-id` = %d AND $sql AND
			NOT `deleted` AND NOT `moderated` AND `visible`
		ORDER BY `item`.`created` DESC LIMIT %d, %d",
		intval($gcontact_id),
		intval(local_user()),
		intval($a->pager['start']),
		intval($a->pager['itemspage'])
	);

	$o = conversation($a, $r, 'community', false);

	$o .= alt_pager($a, count($r));

	return $o;
}
/**
 * @brief Returns posts from a given contact url
 *
 * @param App $a argv application class
 * @param string $contact_url Contact URL
 *
 * @return string posts in HTML
 */
function posts_from_contact_url(App $a, $contact_url) {

	require_once 'include/conversation.php';

	// There are no posts with "uid = 0" with connector networks
	// This speeds up the query a lot
	$r = q("SELECT `network`, `id` AS `author-id` FROM `contact`
		WHERE `contact`.`nurl` = '%s' AND `contact`.`uid` = 0",
		dbesc(normalise_link($contact_url)));
	if (in_array($r[0]["network"], array(NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS, ""))) {
		$sql = "(`item`.`uid` = 0 OR (`item`.`uid` = %d AND `item`.`private`))";
	} else {
		$sql = "`item`.`uid` = %d";
	}

	if (!dbm::is_result($r)) {
		return '';
	}

	$author_id = intval($r[0]["author-id"]);

	$r = q(item_query()." AND `item`.`author-id` = %d AND ".$sql.
		" ORDER BY `item`.`created` DESC LIMIT %d, %d",
		intval($author_id),
		intval(local_user()),
		intval($a->pager['start']),
		intval($a->pager['itemspage'])
	);

	$o = conversation($a, $r, 'community', false);

	$o .= alt_pager($a, count($r));

	return $o;
}

/**
 * @brief Returns a formatted location string from the given profile array
 *
 * @param array $profile Profile array (Generated from the "profile" table)
 *
 * @return string Location string
 */
function formatted_location($profile) {
	$location = '';

	if($profile['locality'])
		$location .= $profile['locality'];

	if($profile['region'] && ($profile['locality'] != $profile['region'])) {
		if($location)
			$location .= ', ';

		$location .= $profile['region'];
	}

	if($profile['country-name']) {
		if($location)
			$location .= ', ';

		$location .= $profile['country-name'];
	}

	return $location;
}

/**
 * @brief Returns the account type name
 *
 * The function can be called with either the user or the contact array
 *
 * @param array $contact contact or user array
 */
function account_type($contact) {

	// There are several fields that indicate that the contact or user is a forum
	// "page-flags" is a field in the user table,
	// "forum" and "prv" are used in the contact table. They stand for PAGE_COMMUNITY and PAGE_PRVGROUP.
	// "community" is used in the gcontact table and is true if the contact is PAGE_COMMUNITY or PAGE_PRVGROUP.
	if((isset($contact['page-flags']) && (intval($contact['page-flags']) == PAGE_COMMUNITY))
		|| (isset($contact['page-flags']) && (intval($contact['page-flags']) == PAGE_PRVGROUP))
		|| (isset($contact['forum']) && intval($contact['forum']))
		|| (isset($contact['prv']) && intval($contact['prv']))
		|| (isset($contact['community']) && intval($contact['community'])))
		$type = ACCOUNT_TYPE_COMMUNITY;
	else
		$type = ACCOUNT_TYPE_PERSON;

	// The "contact-type" (contact table) and "account-type" (user table) are more general then the chaos from above.
	if (isset($contact["contact-type"]))
		$type = $contact["contact-type"];
	if (isset($contact["account-type"]))
		$type = $contact["account-type"];

	switch($type) {
		case ACCOUNT_TYPE_ORGANISATION:
			$account_type = t("Organisation");
			break;
		case ACCOUNT_TYPE_NEWS:
			$account_type = t('News');
			break;
		case ACCOUNT_TYPE_COMMUNITY:
			$account_type = t("Forum");
			break;
		default:
			$account_type = "";
			break;
	}

	return $account_type;
}
