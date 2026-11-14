# File Manager — MyBB Plugin

Activates and protects the built-in File Manager admin module. Only works after install and activation.

MyBB ships a File Manager module (`admin/modules/file/`) that is not registered by default. This plugin is the master switch: it registers the module, adds permission-aware settings, and keeps the module hidden and unusable until the plugin is installed **and** activated.

## Features

- Registers the built-in ACP File Manager module and exposes it in the Admin CP.
- Master switch setting: when disabled, the module is hidden and refuses to run.
- Upload guard rails: per-file size limit plus allow-list and block-list of file extensions (PHP/CGI/Perl/Python/ASP/JSP blocked by default).
- Settings stored in `settings.json` and loaded through PluginLibrary, so install/uninstall is fully reversible.
- Declares its non-standard files to the Backup Plugin (`backup_plugin_extra_files_filemanager`), so a plugin backup includes the admin module and ACP language file automatically.
- Clean uninstall: removes the settings group and leaves no template edits behind.

## Requirements

| Requirement | Version / note |
| --- | --- |
| MyBB | 1.8.x (`compatibility: 18*`) |
| PHP | 7.4+ (works on PHP 8.x) |
| PluginLibrary | Required — `inc/plugins/pluginlibrary.php` |

## Installation

1. Upload the files, preserving the directory structure below, into your MyBB root.
2. Go to **Admin CP → Configuration → Plugins**.
3. Find **File Manager** and click **Install**, then **Activate**.
4. Configure it at **Admin CP → Configuration → Settings → File Manager Settings**.

To remove: **Deactivate**, then **Uninstall**. No database tables are created, so nothing else needs cleaning up.

## Plugin structure

The repository mirrors the MyBB install paths. The main plugin file sits at the repository root; every other file keeps its real path inside the forum.

```
filemanager.php                                  # plugin bootstrap (copy to inc/plugins/)
inc/plugins/FileManager/install.php              # install / activate / deactivate / uninstall
inc/plugins/FileManager/settings.json            # settings group + settings (PluginLibrary)
inc/plugins/FileManager/Assets/upload.css        # ACP upload styles
inc/plugins/FileManager/Assets/upload.js         # ACP upload behaviour
inc/languages/english/admin/file_manager.lang.php # ACP phrases
admin/extension/css.png                          # file-type icons used by the file listing
admin/extension/folder.png
admin/extension/html.png
admin/extension/js.png
admin/extension/other.png
admin/extension/php.png
admin/extension/rar.png
admin/extension/return.png
admin/extension/xml.png
admin/extension/zip.png
admin/modules/file/config.php                    # File Manager module
admin/modules/file/function.php                  # module helpers
admin/modules/file/file_manager.php              # module controller
admin/modules/file/module_meta.php               # ACP menu registration
admin/modules/file/quick_save.php                # inline save endpoint
admin/modules/file/rename.php                    # rename endpoint
```

`filemanager.php` is the only file that moves: it belongs in `inc/plugins/`. Everything under `inc/`, `admin/` and the icon set is uploaded exactly as-is.

The icons in `admin/extension/` are resolved at runtime from the file extension (`admin/extension/<ext>.png`, falling back to `other.png`), so the whole folder must be present.

The ACP file editor loads CodeMirror from `jscripts/codemirror/` — that is a stock MyBB Admin CP asset shipped with your forum, **not** part of this plugin and not included here. Make sure it exists in your install, otherwise the editor pane renders without syntax highlighting.

## Settings

| Setting | Default | Description |
| --- | --- | --- |
| Enable File Manager | `1` | When `0`, the module is hidden from the Admin CP and refuses to run. |
| Maximum upload size (MB) | `10` | Per-file limit. PHP's own `upload_max_filesize` and `post_max_size` still apply. |
| Allowed upload extensions | *(empty)* | Comma separated allow-list. Empty means any extension not in the block-list. |
| Blocked upload extensions | `php,php3,php4,php5,php7,phtml,pht,phar,cgi,pl,py,sh,asp,aspx,jsp` | Executable types that can never be uploaded. |

## Notes

- This is an **admin-only** plugin: it has no front-end output and no templates, so it ships only the ACP language file (`inc/languages/english/admin/file_manager.lang.php`). There is no `inc/languages/english/filemanager.lang.php` because there are no front-end phrases.
- The plugin does not modify MyBB core files. The module under `admin/modules/file/` is registered at runtime by the plugin, not patched in.
- Keep `pluginlibrary.php` in `inc/plugins/` before activating; the activation routine returns early without it.

## Author

**PARAD0X** — [MyBB Community profile](https://community.mybb.com/user-134311.html)

- Website: https://mybb.epizy.com
- Version: 1.0.0

## License

GPL-3.0 — see [LICENSE](LICENSE).
