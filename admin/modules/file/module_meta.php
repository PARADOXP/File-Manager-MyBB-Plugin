<?php
if(!defined("IN_MYBB")){
	die("Direct initialization of this file is not allowed.");
}

require_once dirname(__FILE__)."/function.php";

function file_meta(){
    global $page, $lang, $plugins, $admin_session;

    // The module only exists when the File Manager plugin is installed, active and enabled.
    if(!filemanager_is_available()){
        return false;
    }

    $lang->load("file_manager");
    
    $menu_list = array();
	$menu_list['10'] = array("id" => "file", "title" => $lang->file_title, "link" => "index.php?module=file");
    if (isset($admin_session['data']['filemanager']) && !empty($admin_session['data']['filemanager'])) {
        $menu_list['20'] = array('id' => 'logout', 'title' => '<span style="color: #ff0000">'.$lang->file_logout.'</span>', 'link' => 'index.php?module=file&action=logout');
    }
    
    $page->add_menu_item($lang->file_title, "file", "index.php?module=file", 100, $menu_list);

    return true;
}

function file_action_handler($action){
    global $page;
    
    $page->active_module = "file";
	$page->active_action = "file";
    
	return "file_manager.php";
}

function file_admin_permissions()
{
	global $lang;

	// Only register this permission while the plugin is available.
	if(!filemanager_is_available())
	{
		return false;
	}

	$lang->load("file_manager");

	$admin_permissions = array(
		"file"             => $lang->can_access_file_manager,
		"file_actions"     => $lang->can_access_file_actions,
		"file_restricted"  => $lang->can_access_file_restricted,
	);

	return array("name" => $lang->file_title, "permissions" => $admin_permissions, "disporder" => 100);
}
