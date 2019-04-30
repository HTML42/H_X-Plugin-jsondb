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
    public static $use_cache_variable = true;
    //
    public static $logs = array(
        'counter' => array(
            'inserts' => 0,
            'selects' => 0,
            'updates' => 0,
            'requests' => 0
        ),
        'history' => array()
    );
    //
    public static $CACHE = array('meta' => array(), 'selects' => array());

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
        self::$logs['counter']['inserts'] ++;
        array_push(self::$logs['history'], array('insert', $table_name, $data));
        //
        $table_filepath = self::$dir_tables . $table_name . '.json';
        if (is_file($table_filepath)) {
            $table_content = File::instance($table_filepath)->get_json();
            $insert_data = self::_data($table_name, $data);
            array_push($table_content, $insert_data);
            File::_save_file($table_filepath, json_encode($table_content));
            self::$logs['counter']['requests'] ++;
            //
            $meta = self::get_meta('table', $table_name);
            self::set_meta('table', $table_name, array(
                'update_date' => NOW,
                'id' => $insert_data['id'],
                'amount' => $meta['amount'] + 1
            ));
            //
            return $insert_data['id'];
        } else {
            self::_log_error('insert', array($table_name, $data));
        }
        return null;
    }

    public static function select($table_name, $conditions = null, $config = null, $with_connections = true) {
        self::$logs['counter']['selects'] ++;
        array_push(self::$logs['history'], array('select', $table_name, $conditions, $config, $with_connections));
        //
        $table_filepath = self::$dir_tables . $table_name . '.json';
        $return = null;
        //
        if (is_null($config)) {
            $config = array('limit' => null, 'sortby' => 'id');
        } else {
            $config = array('limit' => null, 'sortby' => 'id') + $config;
        }
        //
        $cache_key = self::$use_cache_variable ? md5(json_encode(array($table_name, $conditions, $config, $with_connections))) : null;
        if (is_file($table_filepath)) {
            if ($cache_key && isset(self::$CACHE['selects'][$cache_key]) && is_array(self::$CACHE['selects'][$cache_key])) {
                return self::$CACHE['selects'][$cache_key];
            }
            //
            if (is_numeric($conditions) || is_array($conditions) || is_null($conditions)) {
                if (is_numeric($conditions)) {
                    $conditions = array('id' => intval($conditions));
                }
                $table_content = File::instance($table_filepath)->get_json();
                self::$logs['counter']['requests'] ++;
                $return = array();
                foreach ($table_content as $item) {
                    $match_all = true;
                    if (is_array($conditions)) {
                        foreach ($conditions as $key => $value) {
                            if (is_callable($value)) {
                                if (!$value($item)) {
                                    $match_all = false;
                                    break;
                                }
                            } else if ((!isset($item[$key]) && !is_null($item[$key])) || $item[$key] != $value) {
                                $match_all = false;
                                break;
                            }
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
                self::_log_error('select', array($table_name, isset($data) ? $data : null, 'step2'));
            }
        } else {
            self::_log_error('select', array($table_name, isset($data) ? $data : null));
        }
        if ($cache_key) {
            self::$CACHE['selects'][$cache_key] = $return;
        }
        return $return;
    }

    public static function select_first($table_name, $conditions = null, $with_connections = true) {
        $select = self::select($table_name, $conditions, array('limit' => 1), $with_connections);
        return (is_array($select) && isset($select[0]) ? $select[0] : array());
    }

    public static function update($table_name, $conditions, $data) {
        self::$logs['counter']['updates'] ++;
        array_push(self::$logs['history'], array('update', $table_name, $conditions, $data));
        //
        $table_filepath = self::$dir_tables . $table_name . '.json';
        if (is_file($table_filepath)) {
            if (is_numeric($conditions) || is_array($conditions) || is_null($conditions)) {
                if (is_numeric($conditions)) {
                    $conditions = array('id' => intval($conditions));
                }
                $table_content = File::instance($table_filepath)->get_json();
                foreach ($table_content as &$item) {
                    $match_all = true;
                    if (is_array($conditions)) {
                        foreach ($conditions as $key => $value) {
                            if (!isset($item[$key]) || $item[$key] != $value) {
                                $match_all = false;
                                break;
                            }
                        }
                    }
                    if ($match_all) {
                        foreach (self::_data($table_name, $data, false) as $data_key => $data_value) {
                            $item[$data_key] = $data_value;
                        }
                    }
                }
            }
            File::_save_file($table_filepath, json_encode($table_content));
            self::$logs['counter']['requests'] ++;
            //
            $meta = self::get_meta('table', $table_name);
            self::set_meta('table', $table_name, array(
                'update_date' => NOW,
            ));
            return true;
        } else {
            return false;
        }
    }

    public static function search($table_name, $term, $fields = null, $exact_match = false, $quick = true) {
        $table_filepath = self::$dir_tables . $table_name . '.json';
        $cache_key = self::$use_cache_variable ? md5(json_encode(array($table_name, $term, $fields, $exact_match, $quick))) : null;
        if (is_file($table_filepath)) {
            $return = array();
            $table_content = file_get_contents($table_filepath);
            if ($quick) {
                $table_items = explode('},{', substr($table_content, 2, strlen($table_content) - 4));
                $matches = array_filter($table_items, function($item) use ($term, $fields, $exact_match) {
                    return __compare($item, $term, $exact_match);
                });
                if (count($matches) == count($table_items)) {
                    $quick = false;
                } else {
                    if (empty($matches)) {
                        $return = array();
                    } else {
                        $return = json_decode('[{' . implode('},{', $matches) . '}]', true);
                    }
                }
            }
            //
            if (!$quick) {
                $return = array_filter(json_decode($table_content, true), function($item) use ($term, $fields, $exact_match) {
                    if (!is_array($fields)) {
                        return __compare_recursive($item, $term, $exact_match);
                    } else {
                        return false;
                    }
                });
            }
            //
            return $return;
        } else {
            return null;
        }
    }

    //

    public static function _data($table_name, $data, $insert = true) {
        $table_config = (isset(self::$config_tables[$table_name]) ? self::$config_tables[$table_name] : array());
        //
        $forbidden = array('id');
        if ($insert) {
            $forbidden = array_merge($forbidden, array('insert_date', 'update_date', 'delete_date'));
        }
        foreach ($forbidden as $forbidden_key) {
            if (isset($data[$forbidden_key])) {
                unset($data[$forbidden_key]);
            }
        }
        //
        if ($insert) {
            $data['insert_date'] = NOW;
            $data['update_date'] = null;
            $data['delete_date'] = null;
        } else {
            $data['update_date'] = NOW;
        }
        //
        $meta = self::get_meta('table', $table_name);
        //
        if ($insert) {
            $data['id'] = $meta['id'] + 1;
            foreach ($table_config as $fieldname => $default) {
                if (!isset($data[$fieldname])) {
                    if (is_callable($default)) {
                        $data[$fieldname] = call_user_func($default, $data);
                    } else {
                        $data[$fieldname] = $default;
                    }
                }
            }
        }
        //
        return $data;
    }

}

function __compare($string, $term, $exact_match = false) {
    if ($exact_match) {
        return strstr($string, $term);
    } else {
        return strstr(strtolower($string), strtolower($term));
    }
}

function __compare_recursive($item, $term, $exact_match = false) {
    if (is_string($item)) {
        return __compare($item, $term, $exact_match);
    } else if (is_array($item)) {
        $match = false;
        foreach ($item as $item_sub) {
            if (__compare_recursive($item_sub, $term, $exact_match)) {
                $match = true;
                break;
            }
        }
        return $match;
    }
}
