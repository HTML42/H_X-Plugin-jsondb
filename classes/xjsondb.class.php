<?php

class Xjsondb {
    
    public static $dir = PROJECT_ROOT . '_xjsondb/';
    
    public static function startup() {
        //Ensure JSON-DB-Directory
        if(Xjsondb::$dir != PROJECT_ROOT . '_xjsondb/') {
            Utilities::ensure_structure(Xjsondb::$dir);
        } else if(!is_dir(Xjsondb::$dir)) {
            File::_create_folder(Xjsondb::$dir);
        }
        //
    }
    
}
