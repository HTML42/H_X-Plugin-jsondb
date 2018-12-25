<?php

#Variables
define('X_JSONDB_PATH', PROJECT_ROOT . 'plugins/jsondb/');

#Files
foreach (array('classes/xjsondb.class.php') as $plugin_file_path) {
    if (is_file(X_JSONDB_PATH . $plugin_file_path)) {
        include X_JSONDB_PATH . $plugin_file_path;
    }
}

