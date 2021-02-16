<?php

/**
 * Conversation system replacement for email-style private messages.
 *
 * @package Symposium
 * @author  Shade <shad3-@outlook.com>
 * @license Copyrighted ©
 * @version beta 3
 */

if (!defined('IN_MYBB')) {
	die('Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.');
}

if (!defined("PLUGINLIBRARY")) {
	define("PLUGINLIBRARY", MYBB_ROOT . "inc/plugins/pluginlibrary.php");
}

function symposium_info()
{
    symposium_plugin_edit();

    if (symposium_is_installed()) {

        global $PL, $mybb, $cache;

        $PL or require_once PLUGINLIBRARY;

        $pluginInfo = $cache->read("shade_plugins");

        $description = '';
        $count = $pluginInfo['Symposium']['notConvertedCount'];
        if ($count > 0) {

            $convert = $PL->url_append('index.php',
                [
                    'module' => 'config-plugins',
                ]
            );

            $description = "<br><br>You have {$count} private messages to convert. <a href='{$convert}' id='symposiumConversion'>Start conversion.</a>";
            $description .= <<<HTML
<script type="text/javascript">
$(document).ready(() => {

    var total = parseInt('{$count}');
    var iterations = 0,
        totalRate = 0,
        previous = 0;
    function convertPage() {

        var t0 = performance.now();

        return $.ajax({
            type: 'POST',
            url: '{$convert}',
            data: {
                'symposium': 'convert',
                'my_post_key': '{$mybb->post_code}'
            },
            complete: (xhr, status) => {

                var response = parseInt(xhr.responseText);
                var textField = $('#symposiumConversion');

                var seconds = (performance.now() - t0) / 1000;
                iterations++;
                var processedPms = (total - response);
                totalRate += ((processedPms - previous) / seconds);

                var averageRate = (totalRate / iterations);
                var remaining = (response / averageRate).toFixed();

                previous = processedPms;

                var label = ' seconds';
                if (remaining >= (60*60*2)) {
                    label = ' hours';
                }
                else if (remaining >= (60*60)) {
                    label = ' hour';
                }
                else if (remaining >= 120) {
                    label = ' minutes';
                }
                else if (remaining >= 60) {
                    label = ' minute';
                }

                // > 0 = next page
                if (response > 0) {

                    textField.text('Converting... ' + processedPms + '/' + total + ' @' + averageRate.toFixed() + ' pms/s. ETA: ' + remaining + label + '. DO NOT CLOSE THE PAGE!');

                    return convertPage();

                }
                // 0 = finished
                else if (response === 0) {
                    return textField.text('Conversion successful. ' + total + '/' + total + ' private messages have been converted into conversations.');
                }
                else {
                    console.log(xhr.responseText);
                    return textField.text('Conversion failed. Please open your browser console and report the issue at https://www.mybboost.com/forum-symposium.');
                }

            }
        });

    }

    $('#symposiumConversion').on('click', function(e) {

        e.preventDefault();

        $(this).replaceWith($('<span id=' + this.id + '>Converting... 0/' + total + '</span>'));

        return convertPage();

    });

});
</script>
HTML;
        }

        if (symposium_apply_core_edits() !== true) {
            $apply = $PL->url_append('index.php',
                [
                    'module' => 'config-plugins',
                    'symposium' => 'apply',
                    'my_post_key' => $mybb->post_code,
                ]
            );
            $description .= "<br><br>Core edits missing. <a href='{$apply}'>Apply core edits.</a>";
        }
        else {
            $revert = $PL->url_append('index.php',
                [
                    'module' => 'config-plugins',
                    'symposium' => 'revert',
                    'my_post_key' => $mybb->post_code,
                ]
            );
            $description .= "<br><br>Core edits in place. <a href='{$revert}'>Revert core edits.</a>";
        }

    }

	return [
		'name' => 'Symposium',
		'description' => "Conversation system replacement for email-style private messages." . $description,
		'website' => 'https://www.mybboost.com/forum-symposium',
		'author' => 'Shade',
		'authorsite' => 'https://www.mybboost.com',
		'version' => 'beta 3',
		'compatibility' => '18*'
	];
}

function symposium_is_installed()
{
	global $cache;

	$installed = $cache->read("shade_plugins");
	if ($installed['Symposium']) {
		return true;
	}
}

function symposium_install()
{
	global $db, $PL, $lang, $mybb, $cache;

	if (!$lang->symposium) {
		$lang->load('symposium');
	}

	if (!file_exists(PLUGINLIBRARY)) {
		flash_message($lang->symposium_pluginlibrary_missing, "error");
		admin_redirect("index.php?module=config-plugins");
	}

	$PL or require_once PLUGINLIBRARY;

	$settingsToAdd = [
		'group_conversations' => [
			'title' => $lang->setting_symposium_group_conversations,
			'description' => $lang->setting_symposium_group_conversations_desc,
			'value' => 1
		],
		'move_to_trash' => [
			'title' => $lang->setting_symposium_move_to_trash,
			'description' => $lang->setting_symposium_move_to_trash_desc,
			'value' => 0
		]
	];

	$PL->settings('symposium', $lang->setting_group_symposium, $lang->setting_group_symposium_desc, $settingsToAdd);

	// Add templates
	$dir       = new DirectoryIterator(dirname(__FILE__) . '/Symposium/templates');
	$templates = [];
	foreach ($dir as $file) {
		if (!$file->isDot() and !$file->isDir() and pathinfo($file->getFilename(), PATHINFO_EXTENSION) == 'html') {
			$templates[$file->getBasename('.html')] = file_get_contents($file->getPathName());
		}
	}

	$PL->templates('symposium', 'Symposium', $templates);

    symposium_apply_core_edits(true);

	// Add stylesheet
	$stylesheet = file_get_contents(
		dirname(__FILE__) . '/Symposium/stylesheets/symposium.css'
	);
	$PL->stylesheet('symposium.css', $stylesheet, [
    	'private.php' => 0
	]);

	// Create tables and columns
	if (!$db->field_exists('convid', 'privatemessages')) {
        $db->add_column('privatemessages', 'convid', 'varchar(32)');
    }

	if (!$db->field_exists('lastread', 'privatemessages')) {
        $db->add_column('privatemessages', 'lastread', 'TEXT');
    }

	if (!$db->table_exists('symposium_conversations')) {

		$collation = $db->build_create_table_collation();

        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "symposium_conversations (
	        convid VARCHAR(32),
	        uid INT,
	        lastpmid INT,
	        lastuid INT,
	        lastmessage TEXT,
	        lastdateline TEXT,
	        lastread INT,
	        unread INT,
            PRIMARY KEY (convid, uid)
        ) ENGINE=MyISAM{$collation};");

	}

	if (!$db->table_exists('symposium_conversations_metadata')) {

		$collation = $db->build_create_table_collation();

        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "symposium_conversations_metadata (
	        convid VARCHAR(32) PRIMARY KEY,
	        participants TEXT,
	        name TEXT,
            admins TEXT
        ) ENGINE=MyISAM{$collation};");

    }

    // Get total number of pms to convert
    $query = $db->simple_select('privatemessages', 'COUNT(pmid) AS total', 'folder IN (1,2)');
    $count = $db->fetch_field($query, 'total');

	// Create cache
	$info                        = symposium_info();
	$shadePlugins                = $cache->read('shade_plugins');
	$shadePlugins[$info['name']] = [
		'title' => $info['name'],
		'version' => $info['version'],
		'notConvertedCount' => $count
	];

	$cache->update('shade_plugins', $shadePlugins);
}

function symposium_uninstall()
{
	global $db, $PL, $cache, $lang;

	if (!$lang->symposium) {
		$lang->load('symposium');
	}

	if (!file_exists(PLUGINLIBRARY)) {
		flash_message($lang->symposium_pluginlibrary_missing, "error");
		admin_redirect("index.php?module=config-plugins");
	}

	$PL or require_once PLUGINLIBRARY;

	// Drop settings
	$PL->settings_delete('symposium');

    symposium_revert_core_edits(true);

	// Delete cache
	$info         = symposium_info();
	$shadePlugins = $cache->read('shade_plugins');
	unset($shadePlugins[$info['name']]);
	$cache->update('shade_plugins', $shadePlugins);

	// Drop tables
	$db->drop_table('symposium_conversations');
	$db->drop_table('symposium_conversations_metadata');

	if ($db->field_exists('convid', 'privatemessages')) {
        $db->drop_column('privatemessages', 'convid');
    }

	if ($db->field_exists('lastread', 'privatemessages')) {
        $db->drop_column('privatemessages', 'lastread');
    }

	// Delete templates and stylesheets
	$PL->templates_delete('symposium');
	$PL->stylesheet_delete('symposium.css');
}

function symposium_admin_config_plugins_begin()
{
    global $mybb, $db, $cache, $PL;

    if ($mybb->input['my_post_key'] == $mybb->post_code and $mybb->input['symposium'] == 'convert') {

        $pluginInfo = $cache->read('shade_plugins');

        $PL or require_once PLUGINLIBRARY;

        $symposiumCache = (array) $PL->cache_read('symposium_converted');

        $perpage = 250;

        $messages = $insert = $participants = $updateMessages = [];

        // Loop through all messages
        $query = $db->simple_select('privatemessages', '*', 'folder IN (1,2) AND convid IS NULL', [
            'order_by' => 'pmid DESC',
            'limit' => $perpage
        ]);

        while ($message = $db->fetch_array($query)) {

            $recipients = (array) my_unserialize($message['recipients']);

            $recipients = array_filter(array_unique((array) $recipients['to']));
            $hash = get_conversation_id($message['fromid'], $recipients);

            $groupConversation = ($recipients and count($recipients) >= 2);

            $recipient = (int) $message['uid'];
            $other = ($message['uid'] == $message['fromid']) ? (int) $message['toid'] : (int) $message['fromid'];

            $notConverted = !isset($symposiumCache[$hash]);

            // Relationship not processed
            if ($notConverted or (!$notConverted and !in_array($recipient, $symposiumCache[$hash]))) {

                if ($notConverted and $hash) {

                    $symposiumCache[$hash] = [$recipient];

                    if (!in_array($message['fromid'], $recipients)) {
                        $recipients[] = (int) $message['fromid'];
                    }

                    sort($recipients);

                    $participants[$hash] = [
                        'convid' => $hash,
                        'participants' => implode(',', $recipients)
                    ];

                    $participants[$hash]['name'] = ($groupConversation and $message['subject']) ?
                        $db->escape_string($message['subject']) :
                        '';

                }
                else {
                    $symposiumCache[$hash][] = $recipient;
                }

                $insert[$hash][$recipient] = [
                    'convid' => $hash,
                    'uid' => $recipient,
                    'lastpmid' => (int) $message['pmid'],
                    'lastmessage' => $db->escape_string($message['message']),
                    'lastdateline' => (int) $message['dateline'],
                    'lastuid' => (int) $message['fromid'],
                    'lastread' => (int) $message['readtime']
                ];

            }

            // An unique fingerprint is equal to fromid + recipients (the $hash). We create a WHERE statement md5 to save some queries
            $whereStatement = 'fromid = ' . (int) $message['fromid'] . ' AND recipients = \'' . $db->escape_string($message['recipients']) . '\'';
            $fingerprint = md5($whereStatement);
            if (!isset($updateMessages[$hash]) or !$updateMessages[$hash][$fingerprint]) {
                $updateMessages[$hash][$fingerprint] = $whereStatement;
            }

        }

        if ($updateMessages) {

            foreach ($updateMessages as $hash => $statement) {

                foreach ($statement as $where) {
                    $db->update_query('privatemessages', ['convid' => $hash], $where);
                }

            }

        }

        if ($insert) {

            $db->insert_query_multiple('symposium_conversations', array_reduce($insert, 'array_merge', []));

            if ($participants) {
                $db->insert_query_multiple('symposium_conversations_metadata', array_values($participants));
            }

            // Update internal cache
            if ($symposiumCache) {
                $PL->cache_update('symposium_converted', $symposiumCache);
            }

            // Update cache
            $query = $db->simple_select('privatemessages', 'COUNT(pmid) AS total', 'convid IS NULL AND folder IN (1,2)');
            $total = (int) $db->fetch_field($query, 'total');

            $pluginInfo['Symposium']['notConvertedCount'] = $total;
            $cache->update('shade_plugins', $pluginInfo);

            if ($total <= 0) {
                $PL->cache_delete('symposium_converted');
            }

            echo $pluginInfo['Symposium']['notConvertedCount'];
            exit;

        }

        $PL->cache_delete('symposium_converted');

        echo 0;
        exit;

    }
}

function symposium_plugin_edit()
{
    global $mybb;

    if ($mybb->input['my_post_key'] == $mybb->post_code) {

        if ($mybb->input['symposium'] == 'apply') {
            if (symposium_apply_core_edits(true) === true) {
                flash_message('Successfully applied core edits.', 'success');
                admin_redirect('index.php?module=config-plugins');
            }
            else {
                flash_message('There was an error applying core edits.', 'error');
                admin_redirect('index.php?module=config-plugins');
            }

        }

        if ($mybb->input['symposium'] == 'revert') {

            if (symposium_revert_core_edits(true) === true) {
                flash_message('Successfully reverted core edits.', 'success');
                admin_redirect('index.php?module=config-plugins');
            }
            else {
                flash_message('There was an error reverting core edits.', 'error');
                admin_redirect('index.php?module=config-plugins');
            }

        }

    }
}

function symposium_apply_core_edits($apply = false)
{
    global $PL, $mybb;

    $PL or require_once PLUGINLIBRARY;

    $errors = [];

    $edits = [
        [
            'search' => '$remote_avatar_notice = \'\';',
            'before' => '$plugins->run_hooks(\'global_symposium_pm_notice\');'
        ]
    ];

    $result = $PL->edit_core('symposium', 'global.php', $edits, $apply);

    if ($result !== true) {
        $errors[] = $result;
    }

    $edits = [
        [
            'search' => '$plugins->add_hook("usercp_menu", "usercp_menu_messenger", 10);',
            'replace' => '$plugins->add_hook("usercp_menu", "symposium_usercp_menu_messenger", 10);'
        ]
    ];

    $result = $PL->edit_core('symposium', 'inc/functions_user.php', $edits, $apply);

    if ($result !== true) {
        $errors[] = $result;
    }

    if (count($errors) >= 1) {
        return $errors;
    }
    else {
        return true;
    }
}

function symposium_revert_core_edits($apply = false)
{
    global $PL;

    $PL or require_once PLUGINLIBRARY;

    $PL->edit_core('symposium', 'inc/functions_user.php', [], $apply);
    return $PL->edit_core('symposium', 'global.php', [], $apply);
}

global $mybb;

$hooks = [
    'global_start', // Templates cache
    'global_symposium_pm_notice', // Notification overwrite
    'private_inbox', // Conversations list
    'private_read', // Read
    'private_start',
    'private_do_send_end', // Redirect to conversation when creating one
    'datahandler_pm_validate', // Validate pm
    'datahandler_pm_insert', // Reply
    'datahandler_pm_insert_commit',
    'datahandler_pm_insert_savedcopy_commit',
    'private_send_start', // Create conversations
    'private_send_do_send',
    'xmlhttp_get_users_end',
    'admin_user_users_delete_commit', // Delete user
    'admin_config_plugins_begin', // Converter
    'xmlhttp' // Delete messages
];

foreach ($hooks as $hook) {
    $plugins->add_hook($hook, 'symposium_' . $hook);
}

function symposium_global_start()
{
	global $mybb, $lang, $templatelist;

	if ($templatelist) {
		$templatelist = explode(',', $templatelist);
	}
	else {
		$templatelist = [];
	}

	$lang->load('symposium');

	$templatelist[] = 'symposium_pm_notice';

	if (THIS_SCRIPT == 'private.php') {

        $templatelist[] = 'symposium_seen_icon';
        $templatelist[] = 'symposium_unseen_icon';

        if (!$mybb->input['action']) {

            $templatelist[] = 'symposium_conversations';
            $templatelist[] = 'symposium_conversations_empty';
            $templatelist[] = 'symposium_conversations_conversation';
            $templatelist[] = 'symposium_conversations_search_not_found';
            $templatelist[] = 'symposium_unread_count';

        }

        if ($mybb->input['action'] == 'read') {

            $templatelist[] = 'symposium_conversation';
            $templatelist[] = 'symposium_conversation_date_divider';
            $templatelist[] = 'symposium_conversation_message_external';
            $templatelist[] = 'symposium_conversation_message_external_group';
            $templatelist[] = 'symposium_conversation_message_external_group_avatar';
            $templatelist[] = 'symposium_conversation_message_personal';
            $templatelist[] = 'symposium_conversation_posting_area';
            $templatelist[] = 'symposium_conversation_no_messages';

        }


        if ($mybb->input['action'] == 'send') {
            $templatelist[] = 'symposium_create_autocomplete';
            $templatelist[] = 'symposium_create_conversation';
        }

	}

	if (in_array(THIS_SCRIPT, ['usercp.php', 'misc.php', 'private.php'])) {
		$templatelist[] = 'symposium_usercp_menu';
	}

	// Invalidate the standard pm notice, no wasted queries
	$mybb->user['s_pmnotice'] = $mybb->user['pmnotice'];
	unset($mybb->user['pmnotice']);

	$templatelist = implode(',', array_filter($templatelist));
}

function symposium_global_symposium_pm_notice()
{
	global $mybb, $lang, $db, $pm_notice, $templates;

	$prefix = TABLE_PREFIX;
    $query = $db->query(<<<SQL
        SELECT c.convid, c.unread, m.participants, m.name
        FROM {$prefix}symposium_conversations c
        LEFT JOIN {$prefix}symposium_conversations_metadata m ON (c.convid = m.convid)
        WHERE c.uid = {$mybb->user['uid']} AND c.unread > 0
        ORDER BY c.unread DESC
SQL
);

    $conversationsToRead = $conversations = $participants = $users = [];
    $sum = 0;
	while ($conversation = $db->fetch_array($query)) {

    	$localParticipants = (array) explode(',', $conversation['participants']);

    	if (($key = array_search($mybb->user['uid'], $localParticipants)) !== false) {
        	unset($localParticipants[$key]);
    	}

    	if ($localParticipants) {
        	$conversation['recipient'] = reset($localParticipants);
        	$participants += $localParticipants;
    	}

    	$sum += (int) $conversation['unread'];

    	$conversations[] = $conversation;

	}

    if ($conversations) {

    	$participants = array_unique($participants);
    	if ($participants) {

        	$query = $db->simple_select('users', 'uid, username', 'uid IN (' . implode(',', $participants) . ')');
        	while ($user = $db->fetch_array($query)) {
            	$users[$user['uid']] = $user;
        	}

        }

        foreach ($conversations as $conversation) {

            // Group conversation?
            if ($conversation['name']) {
                $name = htmlspecialchars_uni($conversation['name']);
            }
            else if ($conversation['recipient'] and $users[$conversation['recipient']]) {
                $target = $users[$conversation['recipient']];
                $name = format_name($target['username'], $target['usergroup'], $target['displaygroup']);
            }

            if ($name) {
                $conversationsToRead[] = '<a href="private.php?action=read&amp;convid=' . $conversation['convid'] . '">' . $name . ' (' . $conversation['unread'] . ')</a>';
            }

        }

        // Adjust singular/plural wording
        $count = count($conversations);
        $convLabel = ($count > 1) ? $lang->symposium_pm_notice_conversations : $lang->symposium_pm_notice_conversation;
        $messagesLabel = ($sum > 1) ? $lang->symposium_pm_notice_messages : $lang->symposium_pm_notice_message;

    	$text = $lang->sprintf($lang->symposium_pm_notice, $count, $convLabel, $sum, $messagesLabel, implode(', ', $conversationsToRead));

    	eval("\$pm_notice = \"".$templates->get("symposium_pm_notice")."\";");

    }
}

function symposium_private_inbox()
{
    global $mybb, $templates, $header, $headerinclude, $theme, $footer, $db, $usercpnav, $lang;

    require_once MYBB_ROOT."inc/class_parser.php";
    $parser = new postParser;
    $parserOptions = [
        'allow_html' => $mybb->settings['pmsallowhtml'],
    	'allow_mycode' => $mybb->settings['pmsallowmycode'],
    	'allow_smilies' => $mybb->settings['pmsallowsmilies'],
    	'allow_imgcode' => $mybb->settings['pmsallowimgcode'],
    	'allow_videocode' => $mybb->settings['pmsallowvideocode'],
    	'filter_badwords' => 1
    ];

    // Search conversations
    $where = $searchMultipage = '';
    $search = false;
    if ($mybb->input['search']) {

        verify_post_check($mybb->input['my_post_key']);

        $conversationsInSearch = [];
        $keyword = $db->escape_string($mybb->get_input('search'));

        $query = $db->simple_select('users', 'uid', "username LIKE '%{$keyword}%'");
        while ($matchedUid = $db->fetch_field($query, 'uid')) {
            $conversationsInSearch[] = get_conversation_id($mybb->user['uid'], $matchedUid);
        }

        // Account for MyBB Engine
        if (stripos('MyBB Engine', $keyword) !== false) {
            $conversationsInSearch[] = get_conversation_id($mybb->user['uid'], 0);
        }

        // Search group conversations as well
        $query = $db->simple_select('symposium_conversations_metadata', 'convid', "name LIKE '%{$keyword}%'");
        while ($convid = $db->fetch_field($query, 'convid')) {
            $conversationsInSearch[] = $convid;
        }

        $where = ($conversationsInSearch) ?
            " AND convid IN ('" . implode("','", $conversationsInSearch) . "')" :
            " AND 1=0"; // Let it slip away

        $searchMultipage = '?my_post_key=' . $mybb->post_code . '&search=' . $keyword;
        $search = true;

    }

    $query = $db->simple_select('symposium_conversations', 'COUNT(convid) AS total', 'uid = ' . (int) $mybb->user['uid'] . $where);
    $total = $db->fetch_field($query, 'total');

    $perpage = $mybb->settings['threadsperpage'];
	$page = $mybb->get_input('page', MyBB::INPUT_INT);

	if ($page > 0) {

		$start = ($page-1) *$perpage;
		$pages = ceil($total / $perpage);

		if ($page > $pages) {

			$start = 0;
			$page = 1;

		}

	}
	else {

		$start = 0;
		$page = 1;

	}

	$end = $start + $perpage;

	$multipage = multipage($total, $perpage, $page, 'private.php' . $searchMultipage);

    $rawConversations = $convids = $uids = [];
    $prefix = TABLE_PREFIX;
    $query = $db->query(<<<SQL
        SELECT *
        FROM {$prefix}symposium_conversations
        WHERE uid = {$mybb->user['uid']}{$where}
        ORDER BY lastdateline DESC
        LIMIT {$start}, {$perpage}
SQL
);

    while ($conversation = $db->fetch_array($query)) {
        $rawConversations[] = $conversation;
        $convids[] = $conversation['convid'];
    }

    $participants = $conversationNames = [];
    $query = $db->simple_select('symposium_conversations_metadata', 'convid, participants, name', 'convid IN (\'' . implode("','", $convids) . '\')');
    while ($conversationMetadata = $db->fetch_array($query)) {

        $localParticipants = explode(',', $conversationMetadata['participants']);

        if ($localParticipants) {
            $uids[] = $participants[$conversationMetadata['convid']] = $localParticipants;
        }

        // Conversation name? Used for group convos
        if ($conversationMetadata['name']) {
            $conversationNames[$conversationMetadata['convid']] = htmlspecialchars_uni($conversationMetadata['name']);
        }

    }

    $uids = array_reduce($uids, 'array_merge', []);
    $uids = array_filter(array_unique($uids));

    $users = [
        0 => [
            'uid' => 0,
            'username' => 'MyBB Engine',
            'usergroup' => 2,
            'displaygroup' => 2,
            'avatar' => 'images/default_avatar.png'
        ],
        $mybb->user['uid'] => [
            'uid' => $mybb->user['uid'],
            'username' => $mybb->user['username'],
            'usergroup' => $mybb->user['usergroup'],
            'displaygroup' => $mybb->user['displaygroup'],
            'avatar' => $mybb->user['avatar']
        ]
    ];

    if ($uids) {

        $query = $db->simple_select('users', 'uid, username, usergroup, displaygroup, avatar', 'uid IN (' . implode(',', $uids) . ')');
        while ($user = $db->fetch_array($query)) {
            $users[$user['uid']] = $user;
        }

    }

    $selfHash = get_conversation_id($mybb->user['uid'], $mybb->user['uid']);

    $conversations = '';
    if ($rawConversations) {

        foreach ($rawConversations as $k => $conversation) {

            $currentUsers = [];
            $groupConversationSender = '';
            $groupConversation = ($participants[$conversation['convid']] and count($participants[$conversation['convid']]) > 2);

            if ($participants[$conversation['convid']] and $selfHash != $conversation['convid']) {

                foreach ((array) $participants[$conversation['convid']] as $participant) {

                    if ($users[$participant] and $participant != $mybb->user['uid']) {
                        $currentUsers[] = $users[$participant];
                    }

                }

            }

            if ($selfHash == $conversation['convid']) {
                $currentUsers[] = $users[$mybb->user['uid']];
            }

            if (!$currentUsers) {
                $currentUsers[] = [
                    'uid' => 0,
                    'username' => 'Deleted user',
                    'usergroup' => 2,
                    'displaygroup' => 2,
                    'avatar' => 'images/default_avatar.png'
                ];
            }

            // Name of conversation found
            if ($conversationNames[$conversation['convid']]) {
                $convoTitle = $conversationNames[$conversation['convid']];
                $convoAvatar = 'images/default_avatar.png'; // TO-DO: make it customizeable
            }
            // Group conversation, no name
            else if ($participants[$conversation['convid']] and $groupConversation) {
                $convoTitle = implode(', ', array_column($currentUsers, 'username'));
                $convoAvatar = 'images/default_avatar.png'; // TO-DO: make it customizeable
            }
            // Single conversation
            else {
                $convoTitle = format_name($currentUsers[0]['username'], $currentUsers[0]['usergroup'], $currentUsers[0]['displaygroup']);
                $convoAvatar = $currentUsers[0]['avatar'];
            }

            // Add sender if 1) is a group conversation 2) there's a message 3) it's not from ourselves
            if ($groupConversation) {

                if ($conversation['lastmessage'] and $conversation['lastuid'] != $mybb->user['uid'] and $users[$conversation['lastuid']]) {

                    $groupConversationSender = format_name($users[$conversation['lastuid']]['username'], $users[$conversation['lastuid']]['usergroup'], $users[$conversation['lastuid']]['displaygroup']);

                    $groupConversationSender = $lang->sprintf($lang->symposium_group_conversation_sender, $groupConversationSender);

                }

            }

            $date = my_date('relative', $conversation['lastdateline']);

            $lastRead = '';
            if ($conversation['lastuid'] == $mybb->user['uid']) {

                $read = ($conversation['lastread'] >= $conversation['lastdateline']) ? true : false;

                if ($read) {
                    eval("\$lastRead = \"".$templates->get("symposium_seen_icon")."\";");
                }
                else {
                    eval("\$lastRead = \"".$templates->get("symposium_unseen_icon")."\";");
                }

            }

            // Add unread counter and highlight
            $unreadCount = $highlight = '';
            if ($conversation['unread'] > 0) {

                eval("\$unreadCount = \"".$templates->get("symposium_unread_count")."\";");
                $highlight = ' highlight';

            }

            $conversation['lastmessage'] = strip_tags($parser->text_parse_message($conversation['lastmessage'], $parserOptions));

            eval("\$conversations .= \"".$templates->get("symposium_conversations_conversation")."\";");

        }

    }
    else if ($search) {
        eval("\$conversations .= \"".$templates->get("symposium_conversations_search_not_found")."\";");
    }
    else {
        eval("\$conversations .= \"".$templates->get("symposium_conversations_empty")."\";");
    }

    eval("\$page = \"".$templates->get("symposium_conversations")."\";");
	output_page($page);
	exit;
}

function symposium_private_read()
{
    global $mybb, $templates, $header, $headerinclude, $theme, $footer, $db, $usercpnav, $lang, $errors;

    $convid = $db->escape_string($mybb->input['convid']);

    // Validate conversation
    $query = $db->simple_select('symposium_conversations', 'convid', 'convid = "' . $convid . '" AND uid = ' . $mybb->user['uid']);
    if (!$convid or !$db->fetch_field($query, 'convid')) {
        error($lang->symposium_error_conversation_doesnt_exist);
    }

    if ($errors) {
        $errors = inline_error($errors);
    }

    require_once MYBB_ROOT."inc/class_parser.php";
    $parser = new postParser;
    $parserOptions = [
        'allow_html' => $mybb->settings['pmsallowhtml'],
    	'allow_mycode' => $mybb->settings['pmsallowmycode'],
    	'allow_smilies' => $mybb->settings['pmsallowsmilies'],
    	'allow_imgcode' => $mybb->settings['pmsallowimgcode'],
    	'allow_videocode' => $mybb->settings['pmsallowvideocode'],
    	'me_username' => $post['username'],
    	'filter_badwords' => 1
    ];

    $query = $db->simple_select('privatemessages', 'COUNT(convid) AS total', 'convid = "' . $convid . '" AND uid = ' . $mybb->user['uid']);
    $total = $db->fetch_field($query, 'total');

    $perpage = $mybb->settings['threadsperpage'];
	$page = (!isset($mybb->input['page']) and $mybb->input['from'] == 'multipage') ?
        1 :
        $mybb->get_input('page', MyBB::INPUT_INT);

    $pages = ceil($total / $perpage);

	if ($page > 0) {

		if ($page > $pages) {
			$page = $pages;
		}

		$start = ($pages-$page) * $perpage;

	}
	else {

		$start = 0;
		$page = $pages;

	}

	$end = $start + $perpage;

	$multipage = multipage($total, $perpage, $page, 'private.php?action=read&from=multipage&convid=' . $convid);

	$selfHash = get_conversation_id($mybb->user['uid'], $mybb->user['uid']);

	// Conversation metadata
	$query = $db->simple_select('symposium_conversations_metadata', '*', 'convid = "' . $convid . '"');
	$metadata = $db->fetch_array($query);

    $users = $conversationParticipants = [];
    if ($metadata['participants']) {

        $query = $db->simple_select('users', 'uid, username, usergroup, displaygroup, avatar', 'uid IN (' . $metadata['participants'] . ')');
        while ($user = $db->fetch_array($query)) {
            $users[$user['uid']] = $user;
        }

    }

	if ($metadata['participants']) {
    	$conversationParticipants = array_unique(explode(',', $metadata['participants']));
	}

    if (in_array(0, $conversationParticipants)) {

        $users[0] = [
            'uid' => 0,
            'username' => 'MyBB Engine',
            'usergroup' => 2,
            'displaygroup' => 2,
            'avatar' => 'images/default_avatar.png'
        ];

    }

    // Build header
    $groupConversation = ($users and count($users) > 2);
    $participants = $convoTitle = $convoAvatar = '';

    // Multiple users
    if ($groupConversation) {

        $usernames = [];
        foreach ($users as $_user) {

            if ($_user['uid'] == $mybb->user['uid']) {
                continue;
            }

            $t_username = format_name($_user['username'], $_user['usergroup'], $_user['displaygroup']);
            $usernames[] = build_profile_link($t_username, $_user['uid']);

        }

        $convoTitle = htmlspecialchars_uni($metadata['name']);
        $convoAvatar = 'images/default_avatar.png'; // TO-DO: Make it customizeable
        $participants = implode(', ', $usernames);

    }
    // Single user, not self
    else if ($selfHash != $metadata['convid']) {

        unset($users[$mybb->user['uid']]);

        $user = reset($users);

    }
    else {
        $user = reset($users);
    }

    $replyMeta = $users;
    unset($replyMeta[$mybb->user['uid']]);
    $replyUsernames = implode(', ', array_column($replyMeta, 'username'));

    if ($user) {

        $username = format_name($user['username'], $user['usergroup'], $user['displaygroup']);
        $convoTitle = build_profile_link($username, $user['uid']);
        $convoAvatar = $user['avatar'];

    }

    // Default avatar
    $user['avatar'] = $user['avatar'] ?? 'images/default_avatar.png';

    add_breadcrumb($convoTitle);

    // Show messages
    $rawMessages = [];
    $messages = '';
    $query = $db->simple_select('privatemessages', '*', '((folder = 2 AND fromid = ' . $mybb->user['uid'] . ') OR (folder = 1 AND uid = ' . $mybb->user['uid'] . ')) AND convid = "' . $convid . '"', [
        'order_by' => 'pmid DESC',
        'limit_start' => $start,
        'limit' => $perpage
    ]);

    while ($message = $db->fetch_array($query)) {
        $rawMessages[] = $message;
    }

    $rawMessages = array_reverse($rawMessages);
    $previous = [
        'midnight' => 0
    ];
    $updateReadStatus = false;
    $messagesCount = count($rawMessages);

    foreach ($rawMessages as $k => $message) {

        $date = my_date($mybb->settings['dateformat'], $message['dateline']);
        $divider = $lastRead = '';
        if ($message['dateline'] > $previous['midnight']) {
            eval("\$divider = \"".$templates->get("symposium_conversation_date_divider")."\";");
        }

        $message['message'] = $parser->parse_message($message['message'], $parserOptions);

        $time = my_date($mybb->settings['timeformat'], $message['dateline']);

        // Choose the message style
        $mode = ($message['folder'] == 1) ? 'external' : 'personal';
        if ($groupConversation and $message['folder'] == 1) {
            $mode = 'external_group';
        }

        // Set up group conversation specific adjustments (user, avatar, etc)
        if ($groupConversation) {

            $sender = $avatar = '';
            $user = $users[$message['fromid']];

            if (!$lastUid or $lastUid != $user['uid'] or $divider) {

                // Avatar
                eval("\$avatar = \"".$templates->get("symposium_conversation_message_external_group_avatar")."\";");

                // Sender
                $sender = format_name($user['username'], $user['usergroup'], $user['displaygroup']);
                $sender = build_profile_link($sender, $user['uid']);

            }

            $lastUid = $user['uid'];

        }

        // Display the read status, if it's a personal message
        $read = ($message['readtime']) ? true : false;
        if ($mode == 'personal') {

            if ($groupConversation and !is_int($message['lastread'])) {

                $readers = array_filter(array_unique(explode(',', $message['lastread'])));
                $diff = array_diff($conversationParticipants, $readers);

                $read = ($diff and count($diff) == 1) ? true : false;

            }

            if ($read) {
                eval("\$lastRead = \"".$templates->get("symposium_seen_icon")."\";");
            }
            else {
                eval("\$lastRead = \"".$templates->get("symposium_unseen_icon")."\";");
            }

        }

        eval("\$messages .= \"".$templates->get("symposium_conversation_message_{$mode}")."\";");

        $previous = [
            'midnight' => strtotime('0:00', $message['dateline'] + 60*60*24)
        ];

        if ($message['status'] == 0 and ($message['fromid'] != $mybb->user['uid'] or $selfHash == $metadata['convid'])) {
            $updateReadStatus = true;
        }

    }

    // No messages?
    if ($messagesCount == 0) {
        eval("\$messages = \"".$templates->get("symposium_conversation_no_messages")."\";");
    }

    if ($updateReadStatus) {

        $last = end($rawMessages);

        $toUpdate = [
            'status' => 1,
            'readtime' => TIME_NOW
        ];

        $db->update_query('privatemessages', $toUpdate, 'convid = "' . $convid . '" AND readtime = 0 AND toid = ' . $mybb->user['uid']);

        $db->update_query('symposium_conversations', [
            'lastread' => TIME_NOW
        ], 'convid = "' . $convid . '" AND uid = ' . (int) $last['fromid']);

        // If it's a group conversation, we have some extra work to do
        if ($groupConversation) {

            $toUpdate = [
                'lastread' => 'IF (lastread = \'\', ' . $mybb->user['uid'] . ', CONCAT(lastread, \',' . (int) $mybb->user['uid'] . '\'))'
            ];

            $db->update_query('privatemessages', $toUpdate, 'convid = "' . $convid . '" AND toid = 0 AND folder = 2', '', true);

        }

		// Update the unread count - it has now changed
		update_pm_count($mybb->user['uid'], 6);
		update_conversations_counters($mybb->user['uid']);

    }

    // Add editor – if not MyBB Engine, fixes https://www.mybboost.com/thread-mybb-engine-messaging
    $postingArea = '';
    if (!in_array(0, $conversationParticipants)) {

        $codebuttons = '';
        if ($mybb->settings['bbcodeinserter'] != 0 and $mybb->settings['pmsallowmycode'] != 0 and $mybb->user['showcodebuttons'] != 0) {
    		$codebuttons = build_mycode_inserter("message", $mybb->settings['pmsallowsmilies']);
        }

        $message = htmlspecialchars_uni($mybb->input['message']);

        eval("\$postingArea = \"".$templates->get("symposium_conversation_posting_area")."\";");

    }

    eval("\$page = \"".$templates->get("symposium_conversation")."\";");
	output_page($page);
	exit;
}

function symposium_private_start()
{
    global $mybb, $db, $lang, $session, $errors;

    $lang->nav_pms = $lang->symposium_nav_pms; // Overrides breadcrumb

    if (!in_array($mybb->input['action'], ['new_message', 'delete_conversations'])) {
        return false;
    }

    if ($mybb->usergroup['cansendpms'] == 0) {
		error_no_permission();
	}

	verify_post_check($mybb->get_input('my_post_key'));

    if ($mybb->input['action'] == 'new_message') {

    	// Attempt to see if this PM is a duplicate or not
    	$convid = $db->escape_string($mybb->input['convid']);
    	$timeCutoff = TIME_NOW - (5 * 60 * 60);
    	$query = $db->simple_select('privatemessages', 'pmid', "convid = '{$convid}' AND dateline > {$timeCutoff} AND fromid='{$mybb->user['uid']}' AND subject='".$db->escape_string($mybb->get_input('subject'))."' AND message='".$db->escape_string($mybb->get_input('message'))."' AND folder!='3'", [
        	'limit' => 1
    	]);
    	if ($db->fetch_field($query, "pmid")) {
    		error($lang->error_pm_already_submitted);
    	}

    	require_once MYBB_ROOT."inc/datahandlers/pm.php";
        $pmhandler = new PMDataHandler();

        $pm = array(
    		"subject" => $mybb->get_input('subject'),
    		"message" => $mybb->get_input('message'),
    		"fromid" => $mybb->user['uid'],
    		"do" => $mybb->get_input('do'),
    		"pmid" => $mybb->get_input('pmid', MyBB::INPUT_INT),
    		"ipaddress" => $session->packedip,
    		"convid" => $convid
    	);

    	$pm['to'] = array_unique(array_map("trim", explode(",", $mybb->get_input('to'))));

    	$mybb->input['options'] = $mybb->get_input('options', MyBB::INPUT_ARRAY);

    	if (!$mybb->usergroup['cantrackpms']) {
    		$mybb->input['options']['readreceipt'] = false;
    	}

    	$pm['options'] = [];
        $pm['options']['signature'] = ($mybb->input['options']['signature']) ? 1 : 0;
        $pm['options']['savecopy'] = 1; // Force saving a copy
        $pm['options']['disablesmilies'] = ($mybb->input['options']['disablesmilies']) ?? 0;
    	$pm['options']['readreceipt'] = ($mybb->input['options']['readreceipt']) ?? 0;
    	$pm['saveasdraft'] = ($mybb->input['saveasdraft']) ?? 0;

    	$pmhandler->set_data($pm);

    	if (!$pmhandler->validate_pm()) {
    		$errors = $pmhandler->get_friendly_errors();
    		$mybb->input['action'] = "read"; // TO-DO: AJAXify
    	}
    	else {
    		$pminfo = $pmhandler->insert_pm();
    		redirect("private.php?action=read&convid={$convid}", $lang->redirect_pmsent); // TO-DO: AJAXify
    	}

    }

    if ($mybb->input['action'] == 'delete_conversations') {

        $conversationsToDelete = (array) $mybb->input['toDelete'];

        if ($conversationsToDelete) {

            $conversationsToDelete = array_map([$db, 'escape_string'], array_keys($conversationsToDelete));

            $where = 'convid IN ("' . implode('","', $conversationsToDelete) . '") AND uid = ' . (int) $mybb->user['uid'];

            $db->delete_query('symposium_conversations', $where);

            // Move to trash these messages
            if ($mybb->settings['symposium_move_to_trash']) {
                $db->update_query('privatemessages', ['folder' => 4], $where);
            }
            // Delete permanently
            else {
                $db->delete_query('privatemessages', $where);
            }

            $page = ($mybb->input['page']) ? '?page=' . (int) $mybb->input['page'] : '';

            redirect("private.php{$page}", $lang->symposium_success_conversations_deleted); // TO-DO: AJAXify

        }
        else {
            error($lang->symposium_error_no_conversation_to_delete);
        }

    }

}

function symposium_private_do_send_end()
{
    global $lang, $pmhandler;

    if ($pmhandler->data['convid']) {
        redirect("private.php?action=read&convid=" . $pmhandler->data['convid'], $lang->redirect_pmsent);
    }
}

function symposium_datahandler_pm_validate(&$argument)
{
    global $db, $mybb;

    if (!$argument->data['recipients']) {
        return false;
    }

    $recipients = array_keys($argument->data['recipients']);

    $groupConversation = ($recipients and count($recipients) >= 2);

    // Group conversations
    if ($groupConversation) {

        // Disabled
        if (!$mybb->settings['symposium_group_conversations']) {
            $argument->set_error("symposium_group_conversations_disabled");
        }

        // No title
        if (!$mybb->input['conversationTitle'] and $mybb->input['action'] != 'new_message') {
            $argument->set_error("symposium_missing_conversation_title");
        }

    }

    $automaticConversationId = get_conversation_id($argument->data['fromid'], $recipients);

    if ($argument->data['convid'] and $argument->data['convid'] != $automaticConversationId) {
        $argument->set_error("symposium_tampered_data"); // Tampered data
    }

    if (!$argument->data['convid']) {
        $argument->data['convid'] = $automaticConversationId;
    }

    return $argument;
}

function symposium_datahandler_pm_insert(&$argument)
{
    global $db;

    if ($argument->data['convid']) {
        $argument->pm_insert_data['convid'] = $db->escape_string($argument->data['convid']);
    }

    return $argument;
}

function symposium_datahandler_pm_insert_commit($argument)
{
    global $db, $mybb;

    if ($argument->pm_insert_data['convid']) {

        $prefix = TABLE_PREFIX;
        $now = TIME_NOW;
        $lastpm = (int) end($argument->pmid);

        $db->query(<<<SQL
            INSERT INTO {$prefix}symposium_conversations
                (convid, uid, lastdateline, lastpmid, lastuid, lastmessage)
            VALUES
                ('{$argument->pm_insert_data['convid']}', '{$argument->pm_insert_data['uid']}', '{$now}', '{$lastpm}', '{$argument->pm_insert_data['fromid']}', '{$argument->pm_insert_data['message']}')
            ON DUPLICATE KEY UPDATE
                lastdateline = '{$now}',
                lastpmid = '{$lastpm}',
                lastuid = '{$argument->pm_insert_data['fromid']}',
                lastmessage = '{$argument->pm_insert_data['message']}'
SQL
);

        $participants = [
            $argument->pm_insert_data['fromid']
        ];

        // Add recipients
        $recipients = array_keys($argument->data['recipients']);
        $participants = array_merge($participants, $recipients);

        sort($participants);
        $participants = $db->escape_string(implode(',', $participants));

        // Add conversation name
        $conversationTitle = ($mybb->input['conversationTitle']) ?
            $db->escape_string($mybb->input['conversationTitle']) :
            '';

        $db->query(<<<SQL
            INSERT INTO {$prefix}symposium_conversations_metadata
                (convid, participants, name)
            VALUES
                ('{$argument->pm_insert_data['convid']}', '{$participants}', '{$conversationTitle}')
            ON DUPLICATE KEY UPDATE
                participants = '{$participants}'

SQL
);

        update_conversations_counters($argument->pm_insert_data['uid']);

    }
}

function symposium_datahandler_pm_insert_savedcopy_commit($argument)
{
    global $db;

    if ($argument->pm_insert_data['convid']) {

        $prefix = TABLE_PREFIX;
        $now = TIME_NOW;
        $lastpm = (int) $db->insert_id();

        $db->query(<<<SQL
            INSERT INTO {$prefix}symposium_conversations
                (convid, uid, lastdateline, lastpmid, lastuid, lastmessage)
            VALUES
                ('{$argument->pm_insert_data['convid']}', '{$argument->pm_insert_data['uid']}', '{$now}', '{$lastpm}', '{$argument->pm_insert_data['fromid']}', '{$argument->pm_insert_data['message']}')
            ON DUPLICATE KEY UPDATE
                lastdateline = '{$now}',
                lastpmid = '{$lastpm}',
                lastuid = '{$argument->pm_insert_data['fromid']}',
                lastmessage = '{$argument->pm_insert_data['message']}'
SQL
);

    }
}

function symposium_private_send_start()
{
    global $mybb, $templates, $header, $headerinclude, $theme, $footer, $db, $usercpnav, $lang, $send_errors;

    $uid = (int) $mybb->get_input('uid');

    // Redirect to appropriate conversation if existing
    if ($uid) {

        $convid = get_conversation_id($mybb->user['uid'], $uid);

        $query = $db->simple_select('symposium_conversations', 'uid', "uid = {$mybb->user['uid']} AND convid = '" . $db->escape_string($convid) . "'");
        if ($db->fetch_field($query, 'uid')) {
            header('Location: private.php?action=read&convid=' . $convid);
            exit;
        }

    }

    require_once MYBB_ROOT."inc/class_parser.php";
    $parser = new postParser;
    $parserOptions = [
        'allow_html' => $mybb->settings['pmsallowhtml'],
    	'allow_mycode' => $mybb->settings['pmsallowmycode'],
    	'allow_smilies' => $mybb->settings['pmsallowsmilies'],
    	'allow_imgcode' => $mybb->settings['pmsallowimgcode'],
    	'allow_videocode' => $mybb->settings['pmsallowvideocode'],
    	'filter_badwords' => 1
    ];

    if ($send_errors) {

        $errors = $send_errors;
        $to = htmlspecialchars_uni(implode(', ', array_unique(array_map('trim', explode(',', $mybb->get_input('to'))))));

    }

    $message = htmlspecialchars_uni($parser->parse_badwords($mybb->get_input('message')));
    $conversationTitle = htmlspecialchars_uni($mybb->get_input('conversationTitle'));

    // Add editor
    $codebuttons = '';
    if ($mybb->settings['bbcodeinserter'] != 0 and $mybb->settings['pmsallowmycode'] != 0 and $mybb->user['showcodebuttons'] != 0) {
		$codebuttons = build_mycode_inserter("message", $mybb->settings['pmsallowsmilies']);
    }

    // Specific user?
    $to = '';
    if ($uid) {

        $query = $db->simple_select('users', 'username', "uid='" . $uid . "'");
		$to = htmlspecialchars_uni($db->fetch_field($query, 'username')) . ', ';

    }

    $groupConversationsAllowed = (int) ($mybb->settings['symposium_group_conversations'] == 1);

    // Max participants
    $maxParticipantsPerGroup = ($mybb->usergroup['maxpmrecipients']) ? (int) $mybb->usergroup['maxpmrecipients'] : 5;

    eval("\$autocompletejs = \"".$templates->get("symposium_create_autocomplete")."\";");

    eval("\$page = \"".$templates->get("symposium_create_conversation")."\";");
	output_page($page);
	exit;
}

function symposium_private_send_do_send()
{
    global $mybb, $lang;

    // Sanitized automatically in pmHandler
    $mybb->input['subject'] = ($mybb->input['conversationTitle']) ?
        $mybb->input['conversationTitle'] :
        $lang->symposium_conversation_with . ' ' . $mybb->user['username'];
    $mybb->input['options'] = [
        'savecopy' => 1
    ];
}

function symposium_xmlhttp_get_users_end()
{
    global $data, $db, $mybb;

    if (!$data) {
        return false;
    }

    $map = $convids = $conversationMap = [];

    foreach ($data as $key => $user) {

        $map[$user['uid']] = $key;
        $convid = get_conversation_id($mybb->user['uid'], $user['uid']);
        $conversationMap[$convid] = $user['uid'];

        $convids[] = $convid;

    }

    $query = $db->simple_select('symposium_conversations', 'convid', 'convid IN ("' . implode('","', $convids) . '") AND uid = ' . (int) $mybb->user['uid']);
    while ($convid = $db->fetch_field($query, 'convid')) {

        if ($conversationMap[$convid]) {
            $target = $map[$conversationMap[$convid]];
        }

        if ($data[$target]) {
            $data[$target]['convid'] = $convid;
        }

    }
}

function symposium_usercp_menu_messenger()
{
    global $templates, $usercpmenu, $mybb, $lang;

    eval("\$usercpmenu .= \"".$templates->get("symposium_usercp_menu")."\";");
}

function symposium_admin_user_users_delete_commit()
{
	global $db, $user;

	return $db->delete_query("symposium_conversations", "uid = '{$user['uid']}'");
}

function symposium_xmlhttp()
{
    global $db, $mybb, $lang, $charset;

    if ($mybb->request_method != 'post' or $mybb->input['action'] != 'symposium_delete_pms' or !$mybb->input['pmids']) {
        return false;
    }

    if (!$lang->symposium) {
        $lang->load('symposium');
    }

    header("Content-type: application/json; charset={$charset}");

    if (!verify_post_check($mybb->get_input('my_post_key'), true)) {
		xmlhttp_error($lang->invalid_post_code);
	}

    $pmids = array_filter(array_unique(array_map('intval', $mybb->input['pmids'])));

    if ($pmids) {

        $search = implode(',', $pmids);

        $convid = $db->escape_string($mybb->input['convid']);

        // Get the pms and validate their deletion
        $deletepms = [];
        $query = $db->simple_select("privatemessages", "pmid", "pmid IN ($search) AND uid='{$mybb->user['uid']}' AND convid = '$convid'", array('order_by' => 'pmid'));
        while ($delpm = $db->fetch_array($query)) {
            $deletepms[$delpm['pmid']] = $delpm['pmid'];
        }

        if ($deletepms) {

            // Move to trash
            if ($mybb->settings['symposium_move_to_trash']) {

                foreach ($pmids as $k => $v) {

                    if (!$deletepms[$v]) {

                        $db->update_query("privatemessages", ['folder' => 4, 'deletetime' => TIME_NOW], "pmid='".$v."' AND uid='".$mybb->user['uid']."'");

                        unset($deletepms[$v]);

                    }

                }

            }
            // Delete permanently
            else {

                $toDelete = implode(',', $deletepms);

                $db->delete_query("privatemessages", "pmid IN ($toDelete) AND uid='".$mybb->user['uid']."'");

            }

            // Update PM count
            require_once MYBB_ROOT."inc/functions_user.php";
            update_pm_count();
            update_conversations_meta([$convid], $mybb->user['uid']);
            update_conversations_counters($mybb->user['uid']);

            echo json_encode([
                'success' => 1,
                'message' => $lang->symposium_messages_deleted_successfully
            ]);
            exit;

        }

    }
    else {

        echo json_encode([
            'errors' => [
                $lang->symposium_generic_error_deleting_messages
            ]
        ]);
        exit;

    }

}

function get_conversation_id()
{
    $arguments = func_get_args();
    $relationship = [];

    foreach ($arguments as $arg) {

        if (is_array($arg)) {
            $relationship = array_merge($relationship, array_map('intval', $arg));
        }
        else {
            $relationship[] = (int) $arg;
        }

    }

    sort($relationship);

    return md5(serialize($relationship));
}

function update_conversations_counters(int $uid, array $convids = [])
{
    global $db;

    $convids = array_filter(array_unique(array_map([$db, 'escape_string'], $convids)));

    $extraSql = ($convids) ? " AND convid IN ('" . implode("','", $convids) . "')" : '';

    // Reset
    $db->update_query('symposium_conversations', ['unread' => 0], 'uid = ' . $uid);

    $update = [];
    $query = $db->simple_select('privatemessages', 'COUNT(pmid) as unread, convid', 'uid = ' . $uid . ' AND folder = 1 AND status = 0' . $extraSql, [
        'group_by' => 'convid'
    ]);
    while ($conversation = $db->fetch_array($query)) {
        $db->update_query('symposium_conversations', ['unread' => $conversation['unread']], 'uid = ' . $uid . ' AND convid = "' . $conversation['convid'] . '"');
    }
}

// Update conversations metadata (last post, read, etc) for the supplied convids for the specified user
function update_conversations_meta(array $convids = [], int $uid)
{
    global $mybb, $db;

    $convids = array_filter(array_unique(array_map([$db, 'escape_string'], $convids)));

    if ($convids) {

        $query = $db->simple_select('privatemessages', 'MAX(pmid) as lastpmid', "convid IN ('" . implode("','", $convids) . "') AND folder IN (1,2) AND uid = {$uid}", [
            'group_by' => 'uid' // Group by each user
        ]);
        while ($pmid = $db->fetch_field($query, 'lastpmid')) {
            $toUpdate[] = $pmid;
        }

        $processedConvids = [];

        if ($toUpdate) {

            $query = $db->simple_select('privatemessages', 'message, pmid, fromid, dateline, readtime, convid, uid, lastread', 'pmid IN (' . implode(',', $toUpdate) . ')');
            while ($lastPm = $db->fetch_array($query)) {

                // Group conversations have extra caveats. TO-DO: get metadata and compare participants vs readers
                $lastRead = ($lastPm['lastread']) ?
                    0 :
                    $lastPm['readtime'];

                // We don't need to sanitize things as they are directly obtained from the db
                $db->update_query('symposium_conversations', [
                    'lastmessage' => $lastPm['message'],
                    'lastpmid' => $lastPm['lastpmid'],
                    'lastuid' => $lastPm['fromid'],
                    'lastdateline' => $lastPm['dateline'],
                    'lastread' => $lastRead
                ], "convid = '{$lastPm['convid']}' AND uid = {$lastPm['uid']}");

                $processedConvids[] = $lastPm['convid'];

            }

        }

        // Here we compute the difference. The remaining convids have not been processed, because messages are missing. Therefore, we update the meta accordingly
        $diff = array_diff($convids, $processedConvids);
        foreach ($diff as $convid) {

            $db->update_query('symposium_conversations', [
                'lastmessage' => '',
                'lastpmid' => '',
                'lastuid' => '',
                'lastread' => ''
            ], "convid = '{$convid}' AND uid = {$uid}");

        }

    }
}
