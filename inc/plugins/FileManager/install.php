<?php
/**
 * File Manager Plugin - install / activate / deactivate / uninstall runs
 *
 * The admin module itself ships with MyBB (admin/modules/file/). This plugin
 * only manages the settings group used as the master switch for the module.
 */
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.");
}

function filemanager_install_run()
{
    // Settings are created during activation (matches the universal template).
    return true;
}

function filemanager_activate_run()
{
    global $db, $PL, $mybb;

    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
    }

    filemanager_add_settings();

    if(function_exists('rebuild_settings'))
    {
        rebuild_settings();

        // Make sure this request already sees the freshly created setting.
        if(is_object($mybb))
        {
            $mybb->settings['filemanager_enabled'] = (string)$db->fetch_field(
                $db->simple_select('settings', 'value', "name='filemanager_enabled'", array('limit' => 1)),
                'value'
            );
        }
    }
}

function filemanager_deactivate_run()
{
    // The admin module is self-contained; nothing to revert in templates.
    return true;
}

function filemanager_uninstall_run()
{
    filemanager_remove_settings();
}

function filemanager_add_settings()
{
    global $PL;

    if(!file_exists(PLUGINLIBRARY))
    {
        return;
    }

    require_once PLUGINLIBRARY;
    $PL = new PluginLibrary();

    $settings_file = FILEMANAGER_ROOT . 'settings.json';
    if(file_exists($settings_file))
    {
        $settings_data = @json_decode(@file_get_contents($settings_file), true);
        if($settings_data && isset($settings_data['group'], $settings_data['settings']))
        {
            // PluginLibrary expects setting names as array keys, without the group prefix.
            $named_settings = array();
            foreach($settings_data['settings'] as $setting)
            {
                if(isset($setting['name']))
                {
                    $name = str_replace($settings_data['group']['name'] . '_', '', $setting['name']);
                    $named_settings[$name] = $setting;
                }
            }

            $PL->settings(
                $settings_data['group']['name'],
                $settings_data['group']['title'],
                $settings_data['group']['description'],
                $named_settings
            );
        }
    }
}

function filemanager_remove_settings()
{
    global $PL;

    if(!file_exists(PLUGINLIBRARY))
    {
        return;
    }

    require_once PLUGINLIBRARY;
    $PL = new PluginLibrary();

    $PL->settings_delete('filemanager', true);
}