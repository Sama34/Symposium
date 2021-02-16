<?php

// Installation
$l['symposium'] = "Symposium";
$l['symposium_pluginlibrary_missing'] = "<a href=\"http://mods.mybb.com/view/pluginlibrary\">PluginLibrary</a> is missing. Please install it before doing anything else with Flyover.";

// Settings
$l['setting_group_symposium'] = "Symposium Settings";
$l['setting_group_symposium_desc'] = "Manage Symposium settings, such as maximum number of conversations, messages per conversation, permissions and so forth.";
$l['setting_symposium_move_to_trash'] = "Move to trash when deleting a message/conversation";
$l['setting_symposium_move_to_trash_desc'] = "If this option is enabled, messages will be moved to the trash folder when users delete them (or they delete entire conversations). If disabled (default), messages will be deleted permanently. <b>Note: in the current version, the Trash is NOT available. Messages will disappear, acting as they were deleted permanently. They will be visible in future updates, or if you uninstall Symposium.</b>";
$l['setting_symposium_group_conversations'] = "Group conversations";
$l['setting_symposium_group_conversations_desc'] = "Enable conversations with multiple recipients at once. The maximum recipients allowed per conversation is inherited from each group's permissions panel ('maxpmrecipients'). <b>BEWARE: one message is saved in the database FOR EVERY RECIPIENT. Big groups inevitably fill up your database with lots of database entries, which may result in a unnecessarily large dataset and slow page loads. This feature is suitable for small private chats; for 10+ recipients, it might be better to set up a private forum instead.</b>";
