<?php
define("IN_MYBB", 1);
define("IN_ADMINCP", 1);
require_once "../../../inc/init.php";
require_once 'config.php';
require_once 'function.php';

if($mybb->request_method != "post") {
	die("Direct initialization of this file is not allowed.");
}

// The module only works while the plugin is installed and active.
if(!filemanager_is_available()) {
	die('Plugin inactive.');
	exit;
}

if (isset($mybb->cookies['adminsid'])) {
    $query = $db->simple_select('adminsessions', '*', 'sid="'.$db->escape_string($mybb->cookies['adminsid']).'"');
    $admin_session = $db->fetch_array($query);
    if ($admin_session['sid']) {
        $admin_session['data'] = my_unserialize($admin_session['data']);
    }
}

if (!$admin_session || (FILEMANAGER_PASSWORD && (!isset($admin_session['data']['filemanager']) || empty($admin_session['data']['filemanager'])))) {
    die('Session expired.');
    exit;
}

if(!filemanager_admin_session_valid($admin_session)) {
    die('Session expired.');
    exit;
}

// Standalone endpoints do not go through admin/index.php, so the current user
// must be resolved from the admin session. Without this, generate_post_check()
// uses the front-end session (often a guest) and every AJAX post check fails.
$mybb->user = get_user((int)$admin_session['uid']);

if(!isset($cp_language))
{
	if(!file_exists(MYBB_ROOT."inc/languages/".$mybb->settings['cplanguage']."/admin/file_manager.lang.php"))
	{
		$mybb->settings['cplanguage'] = "english";
	}
	$lang->set_language($mybb->settings['cplanguage'], "admin");
}

$lang->load("file_manager");

// The module access permission must be granted.
if(!filemanager_admin_can('file', (int)$admin_session['uid'])) {
	$data['type'] = 'error';
	$data['msg'] = $lang->file_error_permission_denied;
	echo json_encode($data);
	exit;
}

$data = array();

// CSRF protection: the AJAX call must carry a valid admin post code.
if(!verify_post_check($mybb->get_input('my_post_key'), true)) {
    $data['type'] = 'error';
    $data['msg'] = $lang->file_error_invalid_post;
    echo json_encode($data);
    exit;
}

$dir_raw = '';
if(isset($mybb->input['directory'])) {
	$dir_raw = filemanager_sanitize_dir($mybb->input['directory']);
	if($dir_raw === null) {
		$data['type'] = 'error';
		$data['msg'] = $lang->file_error_path_traversal;
		echo json_encode($data);
		exit;
	}
	$dir = MYBB_ROOT.$dir_raw;
} else {
	$dir = MYBB_ROOT;
}

// Editing files is a modifying action: requires the "file actions" permission.
if(!filemanager_admin_can('file_actions', (int)$admin_session['uid'])) {
	$data['type'] = 'error';
	$data['msg'] = $lang->file_error_permission_denied;
	echo json_encode($data);
	exit;
}

// Editing inside restricted directories requires the dedicated permission.
if(filemanager_dir_is_restricted($dir_raw) && !filemanager_admin_can('file_restricted', (int)$admin_session['uid'])) {
	$data['type'] = 'error';
	$data['msg'] = $lang->file_error_permission_denied;
	echo json_encode($data);
	exit;
}

if(isset($mybb->input['file']) && !empty($mybb->input['file'])) {
	$file = filemanager_sanitize_file($mybb->input['file']);
	if($file === null) {
		$data['type'] = 'error';
		$data['msg'] = $lang->file_error_path_traversal;
		echo json_encode($data);
		exit;
	}
	$file_path = $dir."/".$file;
    
	if(file_exists($file_path) && !is_dir($file_path)){
		if(isset($mybb->input['file_content'])) {
			$content = trim($mybb->input['file_content']);
		} else {
			$content = "";	
		}

		$fopen = fopen($file_path, "w");
		fwrite($fopen, $content);
		fclose($fopen);

		$data['type'] = 'success';
		$data['msg'] = $lang->file_success_edit;
    } else {
        $data['type'] = 'error';
        $data['msg'] = $lang->file_error_exists;
    }
} else {
    $data['type'] = 'error';
    $data['msg'] = $lang->file_error_edit_file;
}

echo json_encode($data);