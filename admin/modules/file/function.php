<?php
/**
 * Returns true only when the File Manager plugin is installed, active and
 * enabled. The whole module is gated behind this function.
 *
 * @return bool
 */
function filemanager_is_available()
{
	if(!defined('FILEMANAGER_PLUGIN_ACTIVE') || FILEMANAGER_PLUGIN_ACTIVE !== true)
	{
		return false;
	}

	global $mybb;

	if(isset($mybb->settings['filemanager_enabled']) && (int)$mybb->settings['filemanager_enabled'] != 1)
	{
		return false;
	}

	return true;
}

/**
 * Validates an admin session for the standalone AJAX endpoints. The session
 * must exist and belong to a user that is (still) an administrator.
 *
 * @param array $admin_session
 * @return bool
 */
function filemanager_admin_session_valid($admin_session)
{
	if(!filemanager_is_available())
	{
		return false;
	}

	if(empty($admin_session['sid']) || !isset($admin_session['uid']))
	{
		return false;
	}

	if(is_super_admin((int)$admin_session['uid']))
	{
		return true;
	}

	global $db;

	// A non-super administrator must have a personal adminoptions entry, or
	// rely on the default permission set (uid=0).
	$query = $db->simple_select("adminoptions", "uid", "uid='".(int)$admin_session['uid']."' OR uid='0'", array('limit' => 1));

	return ($db->num_rows($query) > 0);
}

/**
 * Normalizes a relative directory path and refuses path traversal.
 * Returns the cleaned path, or null when an invalid segment is found.
 *
 * @param string $input
 * @return string|null
 */
function filemanager_sanitize_dir($input)
{
	$dir = str_replace('\\', '/', (string)$input);

	$parts = array();
	foreach(explode('/', $dir) as $part)
	{
		if($part === '' || $part === '.')
		{
			continue;
		}

		if($part === '..')
		{
			// Path traversal attempt
			return null;
		}

		$parts[] = $part;
	}

	return implode('/', $parts);
}

/**
 * Validates a plain filename (no path separators, no traversal).
 * Returns the cleaned name, or null when invalid.
 *
 * @param string $input
 * @return string|null
 */
function filemanager_sanitize_file($input)
{
	$file = str_replace('\\', '/', (string)$input);

	if($file !== basename($file))
	{
		// A filename reaching outside the current directory is not allowed
		return null;
	}

	if($file === '' || $file === '.' || $file === '..')
	{
		return null;
	}

	if(strpos($file, "\0") !== false || preg_match('/[\x00-\x1F]/', $file))
	{
		// Null bytes and control characters must never reach the filesystem
		return null;
	}

	return $file;
}

/**
 * Normalizes a relative upload path ("folder/sub/file.txt", as sent by the
 * drag & drop / folder upload UI) into an array of safe segments.
 * Returns null when any segment is invalid or attempts traversal.
 *
 * @param string $input
 * @return array|null
 */
function filemanager_sanitize_upload_path($input)
{
	$path = str_replace('\\', '/', (string)$input);

	if(strpos($path, "\0") !== false || strlen($path) > 4096)
	{
		return null;
	}

	$segments = array();
	foreach(explode('/', $path) as $segment)
	{
		if($segment === '' || $segment === '.')
		{
			continue;
		}

		if($segment === '..')
		{
			return null;
		}

		$clean = filemanager_sanitize_file($segment);
		if($clean === null || strlen($clean) > 255)
		{
			return null;
		}

		$segments[] = $clean;
	}

	if(empty($segments))
	{
		return null;
	}

	return $segments;
}

/**
 * Human readable message for a PHP upload error code.
 *
 * @param int $code
 * @param string $name
 * @return string
 */
function filemanager_upload_error_text($code, $name)
{
	global $lang;

	switch((int)$code)
	{
		case UPLOAD_ERR_INI_SIZE:
			return $lang->sprintf($lang->file_upload_err_ini, $name);
		case UPLOAD_ERR_FORM_SIZE:
			return $lang->sprintf($lang->file_upload_err_form, $name);
		case UPLOAD_ERR_PARTIAL:
			return $lang->sprintf($lang->file_upload_err_partial, $name);
		case UPLOAD_ERR_NO_FILE:
			return $lang->sprintf($lang->file_upload_err_no_file, $name);
		case UPLOAD_ERR_NO_TMP_DIR:
			return $lang->sprintf($lang->file_upload_err_no_tmp, $name);
		case UPLOAD_ERR_CANT_WRITE:
			return $lang->sprintf($lang->file_upload_err_write, $name);
		case UPLOAD_ERR_EXTENSION:
			return $lang->sprintf($lang->file_upload_err_extension, $name);
		default:
			return $lang->sprintf($lang->file_upload_err_unknown, $name);
	}
}

/**
 * Moves one uploaded file into the target directory, creating any missing
 * sub folders described by the relative path. Used by both the classic form
 * post and the AJAX (drag & drop / folder) endpoint.
 *
 * @param array $file One entry of $_FILES (name, tmp_name, error, size, type)
 * @param string $base_dir Sanitized relative directory the user is browsing
 * @param string $rel_path Relative path of the file inside $base_dir
 * @param bool $overwrite Replace existing files when true
 * @param bool $unzip Extract the archive when the uploaded file is a zip
 * @return array success, name, path, message, skipped, extracted
 */
function filemanager_process_upload($file, $base_dir, $rel_path, $overwrite = false, $unzip = false)
{
	global $lang;

	$result = array(
		'success' => false,
		'name' => '',
		'path' => '',
		'message' => '',
		'skipped' => false,
		'extracted' => 0,
	);

	if(!is_array($file) || !isset($file['name'], $file['tmp_name'], $file['error'], $file['size']))
	{
		$result['message'] = $lang->file_upload_nofile;
		return $result;
	}

	$result['name'] = (string)$file['name'];

	if($file['error'] !== UPLOAD_ERR_OK)
	{
		$result['message'] = filemanager_upload_error_text($file['error'], $result['name']);
		return $result;
	}

	if((string)$rel_path === '')
	{
		$rel_path = $result['name'];
	}

	$segments = filemanager_sanitize_upload_path($rel_path);
	if($segments === null)
	{
		$result['message'] = $lang->sprintf($lang->file_upload_invalid_name, $result['name']);
		return $result;
	}

	$name = array_pop($segments);
	$result['name'] = $name;
	$result['path'] = ltrim(rtrim($base_dir, '/').'/'.implode('/', array_merge($segments, array($name))), '/');

	if(!filemanager_upload_is_allowed($name))
	{
		$result['message'] = $lang->sprintf($lang->file_upload_blocked, $result['path']);
		return $result;
	}

	if((int)$file['size'] > filemanager_max_upload_bytes())
	{
		$result['message'] = $lang->sprintf($lang->file_upload_too_large, $result['path']);
		return $result;
	}

	$relative_dir = ltrim(rtrim($base_dir, '/').'/'.implode('/', $segments), '/');

	if(filemanager_dir_is_restricted($relative_dir) && !filemanager_admin_can('file_restricted'))
	{
		$result['message'] = $lang->file_error_permission_denied;
		return $result;
	}

	$target_dir = rtrim(MYBB_ROOT, '/\\');
	if($relative_dir !== '')
	{
		$target_dir .= '/'.$relative_dir;
	}

	if(!is_dir($target_dir))
	{
		if(!@mkdir($target_dir, 0755, true))
		{
			$result['message'] = $lang->sprintf($lang->file_upload_dir_failed, $relative_dir);
			return $result;
		}
	}

	// Defense in depth: never write outside of MYBB_ROOT, even through symlinks.
	$real_root = realpath(MYBB_ROOT);
	$real_dir = realpath($target_dir);
	if($real_root === false || $real_dir === false || strpos(rtrim($real_dir, '/\\').'/', rtrim($real_root, '/\\').'/') !== 0)
	{
		$result['message'] = $lang->file_error_path_traversal;
		return $result;
	}

	$target = rtrim($real_dir, '/\\').'/'.$name;

	if(file_exists($target))
	{
		if(is_dir($target))
		{
			$result['message'] = $lang->sprintf($lang->file_upload_is_dir, $result['path']);
			return $result;
		}

		if(!$overwrite)
		{
			$result['skipped'] = true;
			$result['message'] = $lang->sprintf($lang->file_upload_exists_skipped, $result['path']);
			return $result;
		}
	}

	if(!is_uploaded_file($file['tmp_name']))
	{
		$result['message'] = $lang->sprintf($lang->file_upload_err_unknown, $result['path']);
		return $result;
	}

	if(!@move_uploaded_file($file['tmp_name'], $target))
	{
		$result['message'] = $lang->sprintf($lang->file_upload_err_write, $result['path']);
		return $result;
	}

	$result['success'] = true;
	$result['message'] = $lang->sprintf($lang->file_upload_success_one, $result['path']);

	if($unzip && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip')
	{
		$extract = filemanager_extract_zip($target, dirname($target), $overwrite);

		if($extract['success'])
		{
			$result['extracted'] = $extract['count'];
			$result['message'] = $lang->sprintf($lang->file_upload_success_unzipped, $result['path'], $extract['count']);
		}
		else
		{
			$result['message'] = $lang->sprintf($lang->file_upload_unzip_failed, $result['path']);
		}
	}

	return $result;
}

/**
 * Safely extracts a zip archive into a directory. Every entry is validated
 * against the same path rules used for uploads, so archives containing "../"
 * or absolute paths cannot escape the destination (Zip Slip protection).
 *
 * @param string $zip_path Absolute path of the archive
 * @param string $dest_dir Absolute path of the destination directory
 * @param bool $overwrite Replace files that already exist when true
 * @return array success, count
 */
function filemanager_extract_zip($zip_path, $dest_dir, $overwrite = false)
{
	if(!class_exists('ZipArchive'))
	{
		return array('success' => false, 'count' => 0);
	}

	$zip = new ZipArchive();
	if($zip->open($zip_path) !== true)
	{
		return array('success' => false, 'count' => 0);
	}

	$dest_real = realpath($dest_dir);
	if($dest_real === false)
	{
		$zip->close();
		return array('success' => false, 'count' => 0);
	}

	$root = rtrim($dest_real, '/\\');
	$count = 0;
	$total = $zip->numFiles;

	for($i = 0; $i < $total; $i++)
	{
		$name = $zip->getNameIndex($i);
		if($name === false)
		{
			continue;
		}

		$name = str_replace('\\', '/', $name);
		$is_dir = (substr($name, -1) === '/');
		$segments = filemanager_sanitize_upload_path(rtrim($name, '/'));

		if($segments === null)
		{
			continue;
		}

		$relative = implode('/', $segments);

		if(filemanager_dir_is_restricted($relative) && !filemanager_admin_can('file_restricted'))
		{
			continue;
		}

		$target = $root.'/'.$relative;

		if($is_dir)
		{
			if(!is_dir($target))
			{
				@mkdir($target, 0755, true);
			}
			continue;
		}

		$parent = dirname($target);
		if(!is_dir($parent))
		{
			@mkdir($parent, 0755, true);
		}

		// Defense in depth: the resolved parent must stay inside the destination.
		$real_parent = realpath($parent);
		if($real_parent === false || strpos(rtrim($real_parent, '/\\').'/', $root.'/') !== 0)
		{
			continue;
		}

		if(is_dir($target))
		{
			continue;
		}

		if(file_exists($target) && !$overwrite)
		{
			continue;
		}

		$stream = $zip->getStream($name);
		if($stream === false)
		{
			continue;
		}

		$out = @fopen($target, 'wb');
		if($out !== false)
		{
			stream_copy_to_stream($stream, $out);
			fclose($out);
			$count++;
		}
		fclose($stream);
	}

	$zip->close();

	return array('success' => true, 'count' => $count);
}

/**
 * Maximum allowed upload size in bytes (setting value, or config default).
 *
 * @return int
 */
function filemanager_max_upload_bytes()
{
	global $mybb;

	$mb = defined('FILEMANAGER_MAX_UPLOAD_MB') ? (float)FILEMANAGER_MAX_UPLOAD_MB : 10;

	if(isset($mybb->settings['filemanager_max_upload']) && $mybb->settings['filemanager_max_upload'] > 0)
	{
		$mb = (float)$mybb->settings['filemanager_max_upload'];
	}

	return (int)($mb * 1024 * 1024);
}

/**
 * Formats a byte count for display (e.g. "10 MB").
 *
 * @param int $bytes
 * @return string
 */
function filemanager_format_bytes($bytes)
{
	$bytes = (int)$bytes;
	$units = array('B', 'KB', 'MB', 'GB', 'TB');

	$i = 0;
	while($bytes >= 1024 && $i < count($units) - 1)
	{
		$bytes /= 1024;
		$i++;
	}

	return round($bytes, ($i > 1 ? 2 : 0)).' '.$units[$i];
}

/**
 * Comma separated extension list -> array of lower case extensions.
 *
 * @param string $list
 * @return array
 */
function filemanager_ext_to_array($list)
{
	$exts = array();

	foreach(explode(',', (string)$list) as $ext)
	{
		$ext = strtolower(trim($ext));
		$ext = ltrim($ext, '.');
		if($ext !== '')
		{
			$exts[] = $ext;
		}
	}

	return $exts;
}

/**
 * @return array
 */
function filemanager_allowed_ext_list()
{
	global $mybb;

	$list = defined('FILEMANAGER_ALLOWED_EXT') ? (string)FILEMANAGER_ALLOWED_EXT : '';

	if(isset($mybb->settings['filemanager_allowed_ext']))
	{
		$list = (string)$mybb->settings['filemanager_allowed_ext'];
	}

	return filemanager_ext_to_array($list);
}

/**
 * @return array
 */
function filemanager_blocked_ext_list()
{
	global $mybb;

	$list = defined('FILEMANAGER_BLOCKED_EXT') ? (string)FILEMANAGER_BLOCKED_EXT : 'php,php3,php4,php5,php7,phtml,pht,phar,cgi,pl,py,sh,asp,aspx,jsp';

	if(isset($mybb->settings['filemanager_blocked_ext']))
	{
		$list = (string)$mybb->settings['filemanager_blocked_ext'];
	}

	return filemanager_ext_to_array($list);
}

/**
 * Checks whether a file name may be uploaded (allowed / blocked extensions).
 *
 * @param string $filename
 * @return bool
 */
function filemanager_upload_is_allowed($filename)
{
	$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

	if(in_array($ext, filemanager_blocked_ext_list(), true))
	{
		return false;
	}

	$allowed = filemanager_allowed_ext_list();
	if(!empty($allowed) && !in_array($ext, $allowed, true))
	{
		return false;
	}

	return true;
}

/*
 * ---------------------------------------------------------------------------
 * Per-administrator restrictions (admin_permissions table / ACP
 * "user-admin_permissions" screens). Three yes/no permissions:
 *   file            -> module access
 *   file_actions    -> delete / zip / unzip / edit / rename actions
 *   file_restricted -> navigating & writing into restricted directories
 * ---------------------------------------------------------------------------
 */

/**
 * Directories that are considered sensitive. Relative to MYBB_ROOT.
 *
 * @return array
 */
function filemanager_restricted_dirs()
{
	return array(
		'admin',
		'uploads',
		'inc/plugins',
		'Backup Plugins',
	);
}

/**
 * Returns true when a (sanitized) directory is inside a restricted folder.
 *
 * @param string $dir Relative path, already passed through filemanager_sanitize_dir()
 * @return bool
 */
function filemanager_dir_is_restricted($dir)
{
	if(empty($dir))
	{
		return false;
	}

	$dir = rtrim($dir, '/') . '';

	foreach(filemanager_restricted_dirs() as $restricted)
	{
		if($dir == $restricted || my_strpos($dir, $restricted . '/') === 0)
		{
			return true;
		}
	}

	return false;
}

/**
 * Resolves the adminoptions permission blob for a given user, mirroring the
 * fallback chain of MyBB's get_admin_permissions():
 *   - personal permissions (uid > 0) win
 *   - otherwise the merged permissions of all groups the user belongs to
 *   - otherwise the default permissions (uid = 0)
 * Super admins always get full access.
 *
 * @param int $uid
 * @return array
 */
function filemanager_admin_permissions($uid)
{
	global $db;

	$uid = (int)$uid;

	if(!$uid)
	{
		return array();
	}

	if(is_super_admin($uid))
	{
		return array('file' => array('file' => '1', 'file_actions' => '1', 'file_restricted' => '1'));
	}

	// Primary + additional usergroups of the user (as negative ids)
	$gids = array();
	$user = get_user($uid);
	if($user)
	{
		$gids[] = (-1) * (int)$user['usergroup'];
		if($user['additionalgroups'])
		{
			foreach(explode(',', $user['additionalgroups']) as $gid)
			{
				$gid = (int)$gid;
				if($gid > 0)
				{
					$gids[] = (-1) * $gid;
				}
			}
		}
	}

	$group_sql = '';
	foreach($gids as $gid)
	{
		$group_sql .= " OR uid='{$gid}'";
	}

	$query = $db->simple_select("adminoptions", "permissions, uid", "(uid='{$uid}'{$group_sql} OR uid='0') AND permissions != ''", array("order_by" => "uid", "order_dir" => "DESC"));

	$perms_group = array();
	while($perm = $db->fetch_array($query))
	{
		$perm['permissions'] = my_unserialize($perm['permissions']);

		if($perm['uid'] > 0)
		{
			// Personal permissions always win
			return $perm['permissions'];
		}
		elseif($perm['uid'] < 0)
		{
			$perms_group[] = $perm['permissions'];
		}
		else
		{
			$perms_default = $perm['permissions'];
		}
	}

	// Merge group permissions: any group granting the key grants it.
	$merged = array();
	foreach($perms_group as $gperms)
	{
		if(!is_array($gperms))
		{
			continue;
		}

		foreach($gperms as $module => $actions)
		{
			if(!is_array($actions))
			{
				continue;
			}

			if(!isset($merged[$module]))
			{
				$merged[$module] = array();
			}

			foreach($actions as $key => $value)
			{
				if($value == '1')
				{
					$merged[$module][$key] = '1';
				}
			}
		}
	}

	if(!empty($merged))
	{
		return $merged;
	}

	if(isset($perms_default) && is_array($perms_default))
	{
		return $perms_default;
	}

	return array();
}

/**
 * Checks whether the current (or given) administrator has a single File
 * Manager permission key. Works both inside the Admin CP page flow and in the
 * standalone AJAX endpoints.
 *
 * @param string $key One of: file, file_actions, file_restricted
 * @param int $uid Optional uid to check (default: current logged in user)
 * @return bool
 */
function filemanager_admin_can($key, $uid = 0)
{
	global $mybb;

	if(empty($uid))
	{
		$uid = !empty($mybb->user['uid']) ? (int)$mybb->user['uid'] : 0;
	}
	else
	{
		$uid = (int)$uid;
	}

	if(!$uid)
	{
		return false;
	}

	if(is_super_admin($uid))
	{
		return true;
	}

	static $permissions_cache = array();

	if(!isset($permissions_cache[$uid]))
	{
		// Inside the Admin CP the resolved permissions are already available.
		if($uid == (int)$mybb->user['uid'] && !empty($mybb->admin['permissions']) && is_array($mybb->admin['permissions']))
		{
			$permissions_cache[$uid] = $mybb->admin['permissions'];
		}
		else
		{
			$permissions_cache[$uid] = filemanager_admin_permissions($uid);
		}
	}

	$permissions = $permissions_cache[$uid];

	if(!is_array($permissions) || !isset($permissions['file'][$key]))
	{
		return false;
	}

	return ($permissions['file'][$key] == '1');
}

function get_file_extension($ext)
{
	switch ($ext) {
		case "html":
		case "htm":
			return "html";
		case "php":
			return "php";
		case "css":
			return "css";
		case "js":
			return "js";
		case "xml":
			return "xml";
		case "rar":
			return "rar";
		case "zip":
			return "zip";
        case 'ico': case 'gif': case 'jpg': case 'jpeg': case 'png': case 'bmp':
            return 'image';
		default:
			return "other";
	}
}

function get_file_icon($file)
{
    global $mybb, $dir;
    if (is_dir(MYBB_ROOT.$dir.'/'.$file)) {
        return 'extension/folder.png';
    }
    
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $ext = get_file_extension($ext);
    if ($ext == 'image') {
        // Don't leak the image content to admins that may not use the view action.
        if (filemanager_admin_can('file_actions')) {
            $ext = $mybb->settings['bburl'].'/'.$dir.'/'.$file;
        } else {
            $ext = 'extension/other.png';
        }
    } else {
        $ext = 'extension/'.$ext.'.png';
    }
    return $ext;
}

function is_editable($file)
{
	$ext = pathinfo($file, PATHINFO_EXTENSION);
	if (in_array($ext, array("php", "html", "htm", "xml", "htaccess", "js", "css", "txt", ""))) {
		return true;
	}

	return false;
}

function get_codemirror_type($ext)
{
	switch ($ext) {
		case "php":
			return "application/x-httpd-php";
		case "css":
			return "text/css";
		case "js":
			return "text/javascript";
		default:
			return "text/html";
	}
}

function removedir($directory)
{
	foreach (@scandir($directory) as $f) {
		if (in_array($f, array(".", ".."))) continue;
		$path = $directory.'/'.$f;
		if (is_dir($path) && !is_link($path)) {
			@removedir($path);
		} else {
			@unlink($path);
		}
	}
	return rmdir($directory);
}

function file_list_destination($files)
{
    global $dir, $lang, $form;
    
    echo <<<EOT
<script type="text/javascript">
    var dirs = [];
    dirs.push("/");
    $(document).ready(function () {
        $('.select_folder').prop('disabled', false);
    })
    .change('.select_folder', function () {
        var folder = $('.select_folder').val();
        
        $('.select_folder').prop('disabled', true);
        $('.select_folder option[value=""]').remove();
        
        if (dirs.indexOf(folder) === -1) {
            $.ajax({
                type: 'post',
                url: 'index.php',
                data: 'module=file&action=get_folders&folder='+encodeURIComponent(folder),
                complete: function (data) {
                    data = JSON.parse(data.responseText);
                    dirs.push(folder);
                    for (var f in data.folders) {
                        $('.select_folder option[value="'+folder+'"]').after('<option value="'+f+'">'+f+'</option>');
                        folder = f;
                    }
                    $('.select_folder').prop('disabled', false);
                }
            });
        } else {
            $('.select_folder').prop('disabled', false);
        }
    });
</script>
EOT;
    
    $table = new Table;
        
    $table->construct_header($lang->file_list_files);
    $table->construct_header($lang->file_list_dir);
    $table->construct_header($lang->file_list_dest);

    $file_list = '<ul>';
    foreach ($files as $file) {
        $file_list .= '<li style="margin-bottom: 8px"><input type="hidden" name="files[]" value="'.$file.'">'.$file.'</li>';
    }
    $file_list .= '</ul>';
    
    $folders = array();
    $folders[''] = $lang->file_list_dest;
    $folders = array_merge($folders, get_folders());
    
    $table->construct_cell($file_list);
    $table->construct_cell('/'.$dir, array('style' => 'vertical-align:top'));
    $table->construct_cell($form->generate_select_box('dest', $folders, array(), array('class' => 'select_folder')), array('style' => 'vertical-align:top'));
    $table->construct_row();

    return $table->output(false, false, false, true);
}

function get_folders($dir='')
{
    $dir = filemanager_sanitize_dir($dir);
    if ($dir === null) {
        return array();
    }

    $open = new DirectoryIterator(MYBB_ROOT.$dir);
    
    $folders = array();
    
    if ($dir == '') {
        $folders['/'] = '/';
    }
    
	foreach($open as $file){
        if(in_array($file->getfilename(), array(".", ".."))) continue;
        
        if($file->isDir()){
            $name = $dir.'/'.$file->getfilename();
            // Don't offer restricted destination folders if the admin may not access them.
            if(filemanager_dir_is_restricted($name) && !filemanager_admin_can('file_restricted'))
            {
                continue;
            }
            $folders[$name] = $name;
        }
    }
    asort($folders);
    
    return $folders;
}

function build_file_list($files) {
    $list = '<ul>';
    foreach($files as $file) {
        $list .= '<li>'.$file.'</li>';
    }
    $list .= '</ul>';
    return $list;
}

function add_zip($zip, $file, $root) {
    $path = $root.'/'.$file;
    if (is_dir($path)) {
        $dir = new DirectoryIterator($path);
        $zip->addEmptyDir($file);
        foreach ($dir as $item) {
            if(in_array($item->getfilename(), array(".", ".."))) continue;
            add_zip($zip, $file.'/'.$item->getfilename(), $root);
        }
    } else {
        $zip->addFile($path, $file);
    }
}