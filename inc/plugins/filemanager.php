<?php
/**
 * File Manager Plugin - bootstrap
 *
 * The File Manager admin module (admin/modules/file/) is self-contained
 * and is auto-discovered by MyBB. This plugin is the "master switch":
 * the module refuses to run unless this plugin is installed AND active.
 */
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.");
}

if(!defined("PLUGINLIBRARY"))
{
    define("PLUGINLIBRARY", MYBB_ROOT . "inc/plugins/pluginlibrary.php");
}

if(!defined("FILEMANAGER_ROOT"))
{
    define("FILEMANAGER_ROOT", MYBB_ROOT . "inc/plugins/FileManager/");
}

// Tells the File Manager admin module + AJAX endpoints that the plugin is active.
if(!defined("FILEMANAGER_PLUGIN_ACTIVE"))
{
    define("FILEMANAGER_PLUGIN_ACTIVE", true);
}

if(file_exists(FILEMANAGER_ROOT . "install.php"))
{
    require_once FILEMANAGER_ROOT . "install.php";
}

function filemanager_info()
{
    return array(
        "name"          => "File Manager",
        "description"   => "Activates and protects the built-in File Manager admin module. Only works after install and activation.",
        "website"       => "https://mybb.epizy.com",
        "author"        => "PARAD0X",
        "authorsite"    => "https://community.mybb.com/user-134311.html",
        "version"       => "1.0.0",
        "guid"          => "",
        "compatibility" => "18*",
        "codename"      => "filemanager"
    );
}

function filemanager_is_installed()
{
    global $db;

    static $is_installed = null;

    if($is_installed !== null)
    {
        return $is_installed;
    }

    $is_installed = false;
    $query = $db->simple_select("settinggroups", "gid", "name='filemanager'", array('limit' => 1));
    if($db->num_rows($query) > 0)
    {
        $is_installed = true;
    }

    return $is_installed;
}

function filemanager_install()
{
    require_once FILEMANAGER_ROOT . "install.php";
    filemanager_install_run();
}

function filemanager_activate()
{
    require_once FILEMANAGER_ROOT . "install.php";
    filemanager_activate_run();
}

function filemanager_deactivate()
{
    require_once FILEMANAGER_ROOT . "install.php";
    filemanager_deactivate_run();
}

function filemanager_uninstall()
{
    require_once FILEMANAGER_ROOT . "install.php";
    filemanager_uninstall_run();
}

/**
 * Registers the File Manager plugin's non-standard files for the Backup Plugin.
 * The plugin's logic lives in the MyBB core module admin/modules/file/ and in
 * the admin language file, none of which match the "filemanager" codename
 * naming rules, so they must be listed explicitly.
 */
function backup_plugin_extra_files_filemanager()
{
    $files = array();

    $module_dir = "admin/modules/file";
    $module_path = MYBB_ROOT . $module_dir;
    if(is_dir($module_path))
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($module_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach($iterator as $item)
        {
            if($item->isFile())
            {
                $files[] = $module_dir . "/" . str_replace('\\', '/', $iterator->getSubPathname());
            }
        }
    }

    $lang_file = "inc/languages/english/admin/file_manager.lang.php";
    if(file_exists(MYBB_ROOT . $lang_file))
    {
        $files[] = $lang_file;
    }

    return $files;
}