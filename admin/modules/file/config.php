<?php
// Are password and PIN required?
define('FILEMANAGER_PASSWORD', 1);
// This is the PIN requested if password is required
define('FILEMANAGER_PIN', '');

// ---------------------------------------------------------------------------
// Upload hardening defaults. These are overridden by the "File Manager
// Settings" plugin group (inc/plugins/FileManager/settings.json) when present.
// ---------------------------------------------------------------------------

// Maximum upload size in megabytes for a single file.
define('FILEMANAGER_MAX_UPLOAD_MB', 10);

// Extensions that can never be uploaded (dangerous executable types).
define('FILEMANAGER_BLOCKED_EXT', 'php,php3,php4,php5,php7,phtml,pht,phar,cgi,pl,py,sh,asp,aspx,jsp');

// Extensions that are the only ones allowed to be uploaded. Empty = allow any
// extension that is not in the blocked list.
define('FILEMANAGER_ALLOWED_EXT', '');