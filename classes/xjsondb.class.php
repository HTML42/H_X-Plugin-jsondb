<?php

define('NOW', time());

class Xjsondb {

    public static $dir = PROJECT_ROOT . '_xjsondb/';
    //
    public static $dir_cache;
    public static $dir_tables;
    public static $dir_meta;
    public static $dir_logs;
    //
    public static $initiate_file;
    public static $initiated = null;
    public static $config_tables = array();
    public static $config_connections = array();
    //
    public static $CACHE = array('meta' => array());

    public static function startup() {
        //Ensure JSON-DB-Directory
        if (self::$dir != PROJECT_ROOT . '_xjsondb/') {
            Utilities::ensure_structure(self::$dir);
        } else if (!is_dir(self::$dir)) {
            File::_create_folder(self::$dir);
        }
        //
        self::$dir_cache = self::$dir . 'cache/';
        self::$dir_tables = self::$dir . 'tables/';
        self::$dir_meta = self::$dir . 'meta/';
        self::$dir_logs = self::$dir . 'logs/';
        self::$initiate_file = self::$dir . 'initiated';
        self::$initiated = is_file(self::$initiate_file);
        //
        if (!self::$initiated) {
            self::initiate();
        }
    }

    public static function initiate() {
        Utilities::ensure_structure(self::$dir_cache);
        Utilities::ensure_structure(self::$dir_tables);
        Utilities::ensure_structure(self::$dir_meta);
        Utilities::ensure_structure(self::$dir_logs);
        //
        foreach (self::$config_tables as $table_name => $table_data) {
            $table_name = trim($table_name);
            $table_file = self::$dir_tables . $table_name . '.json';
            if (!is_file($table_file)) {
                File::_save_file($table_file, '[]');
                self::set_meta('table', $table_name, array(
                    'id' => 0,
                    'amount' => 0,
                    'insert_date' => NOW,
                    'update_date' => null,
                ));
            } else {
                //Todo: Check Table-META and update if needed
            }
        }
        //
        if (!is_file(self::$dir_logs . 'errors.log')) {
            File::_save_file(self::$dir_logs . 'errors.log', '[]');
        }
        //
        File::_save_file(self::$initiate_file, time());
    }

    public static function set_meta($type, $table_name, $data) {
        $meta_filepath = self::$dir_meta . $type . '.json';
        $meta = self::get_meta($type);
        if (!isset($meta[$table_name])) {
            $meta[$table_name] = $data;
        } else {
            foreach ($data as $key => $value) {
                $meta[$table_name][$key] = $value;
            }
        }
        File::_save_file($meta_filepath, json_encode($meta));
        self::$CACHE['meta'][$type] = $meta;
    }

    public static function get_meta($type, $subkey = null) {
        $meta_filepath = self::$dir_meta . $type . '.json';
        if (isset(self::$CACHE['meta'][$type]) && !is_null(self::$CACHE['meta'][$type])) {
            $meta = self::$CACHE['meta'][$type];
        } else {
            $meta = (array) File::instance($meta_filepath)->get_json();
        }
        if (is_string($subkey)) {
            $meta = (isset($meta[$subkey]) ? $meta[$subkey] : null);
        }
        return $meta;
    }

    public static function _log($data, $type = 'error') {
        $type = strtolower($type);
        $log_file = null;
        if ($type == 'error' || $type == 'errors') {
            $log_file = self::$dir_logs . 'errors.log';
        }
        $File_log = File::instance($log_file);
        //
        if ($File_log->exists) {
            $logs = $File_log->get_json();
            array_push($logs, $data);
            File::_save_file($File_log->path, json_encode($logs));
        }
    }

    public static function _log_error($process, $data) {
        self::_log(array(
            'process' => $process,
            'data' => $data,
            'time' => NOW,
            'datetime' => date('d.m.Y H:i', NOW),
        ));
    }

    //

    public static function insert($table_name, $data) {
        $table_filepath = self::$dir_tables . $table_name . '.json';
        if (is_file($table_filepath)) {
            $table_content = File::instance($table_filepath)->get_json();
            $insert_data = self::_data($table_name, $data);
            array_push($table_content, $insert_data);
            File::_save_file($table_filepath, json_encode($table_content));
            return $insert_data['id'];
        } else {
            self::_log_error('insert', array($table_name, $data));
        }
        return null;
    }

    public static function select($table_name, $conditions = null, $config = null, $with_connections = true) {
        $table_filepath = self::$dir_tables . $table_name . '.json';
        $return = null;
        //
        if (is_null($config)) {
            $config = array('limit' => null, 'sortby' => 'id');
        }
        //
        if (is_file($table_filepath)) {
            if (is_numeric($conditions) || is_array($conditions) || is_null($conditions)) {
                if (is_numeric($conditions)) {
                    $conditions = array('id' => intval($conditions));
                }
                $table_content = File::instance($table_filepath)->get_json();
                if (is_array($conditions)) {
                    $return = array();
                    foreach ($table_content as $item) {
                        $match_all = true;
                        foreach ($conditions as $key => $value) {
                            if (!isset($item[$key]) || $item[$key] != $value) {
                                $match_all = false;
                                break;
                            }
                        }
                        if ($match_all) {
                            if ($with_connections && isset(self::$config_connections[$table_name]) && !empty(self::$config_connections[$table_name])) {
                                foreach (self::$config_connections[$table_name] as $connection_name => $connection_data) {
                                    $connection_limit = 5000;
                                    $counter = 0;
                                    foreach ($connection_data as $_key => $_val) {
                                        switch ($counter) {
                                            case 0:
                                                $connection_start = $_key;
                                                $connection_end = $_val;
                                                break;
                                            case 1:
                                                $connection_limit = $_key;
                                                break;
                                        }
                                        //
                                        $counter++;
                                    }
                                    $item[$connection_name] = self::select($connection_end[0], array($connection_end[1] => $item[$connection_start]), null, false);
                                }
                            }
                            array_push($return, $item);
                        }
                        if (is_numeric($config['limit']) && count($return) >= $config['limit']) {
                            break;
                        }
                    }
                } else {
                    $return = $table_content;
                }
            } else {
                self::_log_error('select', array($table_name, $data, 'step2'));
            }
        } else {
            self::_log_error('select', array($table_name, $data));
        }
        return $return;
    }

    //

    public static function _data($table_name, $data) {
        $table_config = (isset(self::$config_tables[$table_name]) ? self::$config_tables[$table_name] : array());
        //
        foreach (array('id', 'insert_date', 'update_date', 'delete_date') as $forbidden_key) {
            if (isset($data[$forbidden_key])) {
                unset($data[$forbidden_key]);
            }
        }
        //
        $data['insert_date'] = NOW;
        $data['update_date'] = null;
        $data['delete_date'] = null;
        //
        $meta = self::get_meta('table', $table_name);
        $data['id'] = $meta['id'] + 1;
        self::set_meta('table', $table_name, array(
            'update_date' => NOW,
            'id' => $data['id'],
            'amount' => $meta['amount'] + 1
        ));
        //
        foreach ($table_config as $fieldname => $default) {
            if (!isset($data[$fieldname])) {
                if (is_callable($default)) {
                    $data[$fieldname] = call_user_func($default, $data);
                } else {
                    $data[$fieldname] = $default;
                }
            }
        }
        //
        return $data;
    }

}
