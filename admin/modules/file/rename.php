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

$data = array();

// CSRF protection: the AJAX call must carry a valid admin post code.
if(!verify_post_check($mybb->get_input('my_post_key'), true)) {
    $data['error'] = true;
    $data['text'] = $lang->file_error_invalid_post;
    echo json_encode($data);
    exit;
}

// The module access permission must be granted.
if(!filemanager_admin_can('file', (int)$admin_session['uid'])) {
	$data['error'] = true;
	$data['text'] = $lang->file_error_permission_denied;
	echo json_encode($data);
	exit;
}

$dir = '';
$dir_raw = '';
if(isset($mybb->input['directory']) && $mybb->input['directory'] !== '') {
	$dir_raw = filemanager_sanitize_dir($mybb->input['directory']);
	if($dir_raw === null) {
		$data['error'] = true;
		$data['text'] = $lang->file_error_path_traversal;
		echo json_encode($data);
		exit;
	}
	$dir = $dir_raw;
}

// Renaming is a modifying action: requires the "file actions" permission.
if(!filemanager_admin_can('file_actions', (int)$admin_session['uid'])) {
	$data['error'] = true;
	$data['text'] = $lang->file_error_permission_denied;
	echo json_encode($data);
	exit;
}

// Renaming inside restricted directories requires the dedicated permission.
if(filemanager_dir_is_restricted($dir_raw) && !filemanager_admin_can('file_restricted', (int)$admin_session['uid'])) {
	$data['error'] = true;
	$data['text'] = $lang->file_error_permission_denied;
	echo json_encode($data);
	exit;
}

if (isset($mybb->input['file']) && !empty($mybb->input['file'])) {
	$file = filemanager_sanitize_file($mybb->input['file']);
	if($file === null) {
		$data['error'] = true;
		$data['text'] = $lang->file_error_path_traversal;
		echo json_encode($data);
		exit;
	}
	if (isset($mybb->input['new_name']) && !empty($mybb->input['new_name'])) {
		$new_name = filemanager_sanitize_file($mybb->input['new_name']);
		if($new_name === null) {
			$data['error'] = true;
			$data['text'] = $lang->file_error_path_traversal;
			echo json_encode($data);
			exit;
		}
		if (file_exists(MYBB_ROOT.$dir."/".$file)) {
			if (!file_exists(MYBB_ROOT.$dir."/".$new_name)) {
				if (@rename(MYBB_ROOT.$dir."/".$file, MYBB_ROOT.$dir."/".$new_name)) {
                    $data['success'] = true;
					if (is_dir(MYBB_ROOT.$dir."/".$new_name)) {
						$data['text'] = $lang->file_success_rename_dir;
					} else {
						$data['text'] = $lang->file_success_rename;
					}
                    $data['icon'] = get_file_icon($new_name);

                    if (is_editable($new_name)) {
                        $edit_query = ($dir !== '' ? '&directory='.urlencode($dir) : '');
                        $data['edit'] = '<span><a href="index.php?module=file&action=edit'.$edit_query.'&file='.urlencode($new_name).'" class="file__actions__edit">'.$lang->file_edit.'</a></span> ';
                    }
				} else {
					$data['error'] = true;
					$data['text'] = $lang->file_error_rename;
				}
			} else {
				$data['error'] = true;
				$data['text'] = $lang->file_error_rename_exists;
			}
		} else {
			$data['error'] = true;
			$data['text'] = $lang->file_error_rename_not_exists;
		}
	} else {
		$data['error'] = true;
		$data['text'] = $lang->file_error_rename_name;
	}
} else {
	$data['error'] = true;
	$data['text'] = $lang->file_error_rename_input;
}

echo json_encode($data);