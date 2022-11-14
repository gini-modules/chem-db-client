<?php

namespace Gini\ChemDB\Driver;

class Database
{

    private static function getDB()
    {
        return \Gini\Database::db('chem-db-client-local-db');
    }

    private static $_chemical_info_keys = ['cas_no', 'name', 'state', 'en_name', 'inchi', 'smiles', 'inchi_key'];
    private static $_stash_cheminfo = [];
    public static function getChemicalInfo($casNO)
    {
        if (isset(self::$_stash_cheminfo[$casNO])) return self::$_stash_cheminfo[$casNO];
        $db = self::getDB();
        $qCasNOs = $db->quote($casNO);
        $keys = self::$_chemical_info_keys;
        $keysString = implode(',', $keys);
        $query = self::getDB()->query("select {$keysString} from chemical_info where cas_no={$qCasNOs}");
        if (!$query) return [];
        $row = $query->row(\PDO::FETCH_ASSOC);
        $row['types'] = self::getOneTypes($casNO)[$casNO];
        $row['msds'] = !!($db->query("select 1 from chemical_msds where cas_no={$qCasNOs}")->value());
        self::$_stash_cheminfo[$casNO] = $row;
        return $row;
    }

    private static $_stash_msds = [];
    public static function getMSDS($casNO)
    {
        if (isset(self::$_stash_msds[$casNO])) return self::$_stash_msds[$casNO];
        $db = self::getDB();
        $qCasNOs = $db->quote($casNO);
        $data = $db->query("select msds from chemical_msds where cas_no={$qCasNOs}")->value();
        $data = $data ? json_decode($data, true) : [];
        self::$_stash_msds[$casNO] = $data;
        return $data;
    }

    private static $_stash_onetypes = [];
    public static function getOneTypes($casNO)
    {
        if (isset(self::$_stash_onetypes[$casNO])) return self::$_stash_onetypes[$casNO];
        $db = self::getDB();
        $qCasNOs = $db->quote($casNO);
        $query = self::getDB()->query("select group_concat(name) as names from chemical_type where cas_no={$qCasNOs} group by cas_no");
        if (!$query) return [];

        $names = $query->value();
        if (!$names) {
            $result = [$casNO=> ['normal']];
        } else {
            $data = array_unique(explode(',', $names));
            $result = [$casNO=> $data];
        }

        self::$_stash_onetypes[$casNO] = $result;

        return $result;
    }

    public static function getTypes($casNOs)
    {
        if (!is_array($casNOs)) return self::getOneTypes($casNOs);

        $db = self::getDB();
        $qCasNOs = $db->quote($casNOs);
        $query = self::getDB()->query("select cas_no,group_concat(name) as names from chemical_type where cas_no in ({$qCasNOs}) group by cas_no");
        if (!$query) return [];

        $rows = $query->rows();
        if (!count($rows)) return [];

        $data = [];
        foreach ($rows as $row) {
            $data[$row->cas_no] = array_unique(explode(',', $row->names));
        }
        foreach ($casNOs as $casNO) {
            if (!isset($data[$casNO])) {
                $data[$casNO] = ['normal'];
            }
        }

        return $data;
    }

    // 该方法是为了支持之前仅有RPC功能时，searchChemicals和getChemicals的机制
    // 如果改动功能代码，会有太多的更新，所以，就在底层实现了这个功能
    // 虽然有点扯淡，但是，成本最低
    // 功能替代代码开始
    private static $_driver_handler = null;
    public static function getRPC()
    {
        if (self::$_driver_handler) return self::$_driver_handler;
        self::$_driver_handler = \Gini\IoC::construct('\Gini\ChemDB\Driver\Database');
        return self::$_driver_handler;
    }

    private $_path = null;
    public function __get($name)
    {
        $this->_path = $this->_path ? $this->_path . '/' . $name : $name;
        return $this;
    }

    public function __call($method, $params)
    {
        if ($method === __FUNCTION__) {
            return;
        }

        return $this->call($method, $params);
    }

    public function call($method, $params)
    {
        array_unshift($params, $this->_path);
        return call_user_func_array([self, '_rpc_'.strtolower($method)], $params);
    }

    private static $_tmp_search_cond = [];
    private static function _rpc_searchchemicals($path, $criteria)
    {
        $token = md5(J($criteria));
        if (isset(self::$_tmp_search_cond[$token])) return self::$_tmp_search_cond[$token];
        $keyword = $criteria['keyword'];
        $criteriaTypes = $criteria['type'];
        $criteriaTypes = self::_getCriteriaTypes($criteriaTypes);
        list($countSQL, $sql) = self::_prepareSQL($criteriaTypes, $keyword);
        $db = self::getDB();
        $db->query('SET max_statement_time=1');
        $query = $db->query($countSQL);
        $count = 3000;
        if ($query) {
            $count = $query->value();
        }
        self::$_tmp_search_cond[$token] = [
            'token'=> $token,
            'count'=> $count,
            'sql'=> $sql
        ];
        return self::$_tmp_search_cond[$token];
    }

    private static function _rpc_getchemicals($path, $token, $start=0, $perpage=25)
    {
        $start = intval($start);
        $perpage= min(max(intval($perpage), 0), 500);

        $data = self::$_tmp_search_cond[$token];
        $sql = $data['sql'];
        if (!$sql) return [];
        $sql = "{$sql} LIMIT {$start},{$perpage}";

        $db = self::getDB();
        $query = $db->query($sql);
        if (!$query) return [];
        $rows = $query->rows(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $casNO = $row['cas_no'];
            $qCasNOs = $db->quote($casNO);
            $result[$casNO] = array_merge($row, [
                'types'=> (array) self::getOneTypes($casNO)[$casNO],
                'msds'=> !!($db->query("select 1 from chemical_msds where cas_no={$qCasNOs}")->value())
            ]);
        }

        return $result;
    }

    private static $_tmp_available_types = null;
    private static function _rpc_getavailabletypes($path=null)
    {
        if (!is_null(self::$_tmp_available_types)) return self::$_tmp_available_types;
        $db = self::getDB();
        $rows = $db->query('select name,title from lm_product_chemical_type')->rows();
        $data = [];
        $abbrs = ['易制毒'=> '毒', '易制爆'=> '爆'];
        foreach ($rows as $row) {
            $key = substr($row->name, 13);
            $title = $row->title;
            $abbr = isset($abbrs[$row->title]) ? $abbrs[$row->title] : mb_substr($row->title, 0, 1);
            $data[$key] = ['title'=>$title, 'abbr'=> $abbr];
        }
        self::$_tmp_available_types = $data;
        return $data;
    }

    private static function _getCriteriaTypes($criteriaTypes)
    {
        if (empty($criteriaTypes)) return 'all-haz';
        if (!is_array($criteriaTypes)) return $criteriaTypes;
        if (count($criteriaTypes)) return current($criteriaTypes);

        $availableTypes = self::_rpc_getavailabletypes();
        $allTypes = array_keys($availableTypes);

        sort($criteriaTypes);
        sort($allTypes);
        if ($allTypes==$criteriaTypes) return 'all';

        $hazTypes = array_diff($allTypes, ['normal']);
        sort($hazTypes);
        if ($hazTypes==$criteriaTypes) return 'all-haz';

        return $criteriaTypes;
    }

    private static function _prepareSQL($types, $keyword=null)
    {
        if (is_array($types)) {
            if (!in_array('normal', $types)) return self::_prepareSQLHaz($types, $keyword);
            return self::_prepareSQLNoH($types, $keyword);
        } else {
            switch ($types) {
                case 'all':
                    return self::_prepareSQLAll($keyword);
                case 'all-haz':
                    return self::_prepareSQLAllHaz($keyword);
                case 'normal':
                    return self::_prepareSQLNormal($keyword);
                default:
                    return self::_prepareSQLHaz($types, $keyword);
            }
        }
    }

    private static function _isCASNO($keyword)
    {
        $pattern = '/^(\d{2,7})(-(\d{2})?(-(\d)?)?)$/';
        if (preg_match($pattern, $keyword)) {
            return true;
        }
        return false;
    }

    private static function _prepareSQLAll($keyword=null)
    {
        $keys = self::$_chemical_info_keys;
        if ($keyword) {
            $keys[] = "if(name ='{$keyword}',2,if(name like '{$keyword}%',1,0)) as score";
        }

        $keysString = implode(',', $keys);
        $db = self::getDB();
        if (!is_null($keyword) || $keyword!=='') {
            if (self::_isCASNO($keyword)) {
                $sql = strtr("SELECT {$keysString} FROM chemical_info WHERE cas_no LIKE :cas_no", [
                    ':cas_no'=> $db->quote("{$keyword}%"),
                ]);
                $count = strtr('SELECT COUNT(*) FROM chemical_info WHERE cas_no LIKE :cas_no', [
                    ':cas_no'=> $db->quote("{$keyword}%"),
                ]);
            } else {
                $sql = strtr("SELECT {$keysString} FROM chemical_info WHERE name LIKE :name", [
                    ':name'=> $db->quote("%{$keyword}%"),
                ]);
                $count = strtr('SELECT /*+ MAX_EXECUTION_TIME(1000) */ COUNT(*) FROM chemical_info WHERE name LIKE :name', [
                    ':name'=> $db->quote("%{$keyword}%"),
                ]);
            }
            $sql .= " order by score desc";
        } else {
            $sql = "SELECT {$keysString} FROM chemical_info";
            $count = 'SELECT COUNT(*) FROM chemical_info';
        }
        return [$count, $sql];
    }

    private static function _prepareSQLAllHaz($keyword=null)
    {
        $keys = self::$_chemical_info_keys;
        $keys = array_map(function($v) { return 'chemical.'.$v.' as '.$v; }, $keys);
        $keys[] = "if(chemical.name ='{$keyword}',2,if(chemical.name like '{$keyword}%',1,0)) as score";
        $keysString = implode(',', $keys);
        $db = self::getDB();
        if (!is_null($keyword) || $keyword!=='') {
            if (self::_isCASNO($keyword)) {
                $sql = strtr("SELECT {$keysString} FROM chemical_type LEFT JOIN chemical_info as chemical ON chemical_type.cas_no=chemical.cas_no WHERE chemical.cas_no LIKE :cas_no group by chemical.cas_no", [
                    ':cas_no'=> $db->quote("{$keyword}%"),
                ]);
                $count = strtr('SELECT COUNT(chemical.cas_no) FROM chemical_type LEFT JOIN chemical_info as chemical ON chemical_type.cas_no=chemical.cas_no WHERE chemical.cas_no LIKE :cas_no group by chemical.cas_no', [
                    ':cas_no'=> $db->quote("{$keyword}%"),
                ]);
            } else {
                $sql = strtr("SELECT {$keysString} FROM chemical_type LEFT JOIN chemical_info as chemical ON chemical_type.cas_no=chemical.cas_no WHERE chemical.name LIKE :name group by chemical.cas_no", [
                    ':name'=> $db->quote("%{$keyword}%"),
                ]);
                $count = strtr('SELECT /*+ MAX_EXECUTION_TIME(1000) */ COUNT(chemical.cas_no) FROM chemical_type LEFT JOIN chemical_info as chemical ON chemical_type.cas_no=chemical.cas_no WHERE chemical.name LIKE :name group by chemical.cas_no', [
                    ':name'=> $db->quote("%{$keyword}%"),
                ]);
            }
            $sql .= " order by score desc";
        } else {
            $sql = 'SELECT {$keysString} FROM chemical_type LEFT JOIN chemical_info as chemical ON chemical_type.cas_no=chemical.cas_no group by chemical.cas_no';
            $count = 'SELECT COUNT(chemical.cas_no) FROM chemical_type LEFT JOIN chemical_info as chemical ON chemical_type.cas_no=chemical.cas_no group by chemical.cas_no';
        }
        return [$count, $sql];
    }

    private static function _prepareSQLNormal($keyword=null)
    {
        $keys = self::$_chemical_info_keys;
        $keys = array_map(function($v) { return 'chemical.'.$v.' as '.$v; }, $keys);
        $keys[] = "if(chemical.name ='{$keyword}',2,if(chemical.name like '{$keyword}%',1,0)) as score";
        $keysString = implode(',', $keys);
        $db = self::getDB();
        if (!is_null($keyword) || $keyword!=='') {
            if (self::_isCASNO($keyword)) {
                $sql = strtr("SELECT {$keysString} FROM chemical_info as chemical LEFT JOIN chemical_type ON chemical_type.cas_no=chemical.cas_no WHERE chemical.cas_no LIKE :cas_no and chemical_type.name is null group by chemical.cas_no", [
                    ':cas_no'=> $db->quote("{$keyword}%"),
                ]);
                $count = strtr('SELECT COUNT(chemical.cas_no) FROM chemical_info as chemical LEFT JOIN chemical_type ON chemical_type.cas_no=chemical.cas_no WHERE chemical.cas_no LIKE :cas_no and chemical_type.name is null group by chemical.cas_no', [
                    ':cas_no'=> $db->quote("{$keyword}%"),
                ]);
            } else {
                $sql = strtr("SELECT {$keysString} FROM chemical_info as chemical LEFT JOIN chemical_type ON chemical_type.cas_no=chemical.cas_no WHERE chemical.name LIKE :name and chemical_type.name is null group by chemical.cas_no", [
                    ':name'=> $db->quote("%{$keyword}%"),
                ]);
                $count = strtr('SELECT /*+ MAX_EXECUTION_TIME(1000) */ COUNT(chemical.cas_no) FROM chemical_info as chemical LEFT JOIN chemical_type ON chemical_type.cas_no=chemical.cas_no WHERE chemical.name LIKE :name and chemical_type.name is null group by chemical.cas_no', [
                    ':name'=> $db->quote("%{$keyword}%"),
                ]);
            }
            $sql .= " order by score desc";
        } else {
            $sql = 'SELECT {$keysString} FROM chemical_info as chemical LEFT JOIN chemical_type ON chemical_type.cas_no=chemical.cas_no where chemical_type.name is null group by chemical.cas_no';
            $count = 'SELECT COUNT(chemical.cas_no) FROM chemical_info as chemical LEFT JOIN chemical_type ON chemical_type.cas_no=chemical.cas_no where chemical_type.name is null group by chemical.cas_no';
        }
        return [$count, $sql];
    }

    private static function _prepareSQLHaz($types, $keyword=null)
    {
        $keys = self::$_chemical_info_keys;
        $keys = array_map(function($v) { return 'chemical.'.$v.' as '.$v; }, $keys);
        $keys[] = "if(chemical.name ='{$keyword}',2,if(chemical.name like '{$keyword}%',1,0)) as score";
        $keysString = implode(',', $keys);
        $db = self::getDB();
        if (!is_null($keyword) || $keyword!=='') {
            if (self::_isCASNO($keyword)) {
                $sql = strtr("SELECT {$keysString} FROM chemical_type LEFT JOIN chemical_info as chemical ON chemical_type.cas_no=chemical.cas_no WHERE chemical_type.name in (:types) AND (chemical.cas_no LIKE :cas_no) group by chemical.cas_no", [
                    ':types'=> $db->quote($types),
                    ':cas_no'=> $db->quote("{$keyword}%"),
                ]);
                $count = strtr('SELECT COUNT(chemical.cas_no) FROM chemical_type LEFT JOIN chemical_info as chemical ON chemical_type.cas_no=chemical.cas_no WHERE chemical_type.name in (:types) AND (chemical.cas_no LIKE :cas_no) group by chemical.cas_no', [
                    ':types'=> $db->quote($types),
                    ':cas_no'=> $db->quote("{$keyword}%"),
                ]);
            } else {
                $sql = strtr("SELECT {$keysString} FROM chemical_type LEFT JOIN chemical_info as chemical ON chemical_type.cas_no=chemical.cas_no WHERE chemical_type.name in (:types) AND (chemical.name LIKE :name) group by chemical.cas_no", [
                    ':types'=> $db->quote($types),
                    ':name'=> $db->quote("%{$keyword}%"),
                ]);
                $count = strtr('SELECT /*+ MAX_EXECUTION_TIME(1000) */ COUNT(chemical.cas_no) FROM chemical_type LEFT JOIN chemical_info as chemical ON chemical_type.cas_no=chemical.cas_no WHERE chemical_type.name in (:types) AND (chemical.name LIKE :name) group by chemical.cas_no', [
                    ':types'=> $db->quote($types),
                    ':name'=> $db->quote("%{$keyword}%"),
                ]);
            }
            $sql .= " order by score desc";
        } else {
            $sql = strtr("SELECT {$keysString} FROM chemical_type LEFT JOIN chemical_info as chemical ON chemical_type.cas_no=chemical.cas_no WHERE chemical_type.name in (:types) group by chemical.cas_no", [
                ':types'=> $db->quote($types),
            ]);
            $count = strtr('SELECT /*+ MAX_EXECUTION_TIME(1000) */ COUNT(chemical.cas_no) FROM chemical_type LEFT JOIN chemical_info as chemical ON chemical_type.cas_no=chemical.cas_no WHERE chemical_type.name in (:types) group by chemical.cas_no', [
                ':types'=> $db->quote($types),
            ]);
        }
        return [$count, $sql];
    }

    private static function _prepareSQLNoH($types, $keyword=null)
    {
        $types = array_filter($types, function($value) {
            return $value!='normal';
        });
        $keys = self::$_chemical_info_keys;
        $keys = array_map(function($v) { return 'chemical.'.$v.' as '.$v; }, $keys);
        $keys[] = "if(chemical.name ='{$keyword}',2,if(chemical.name like '{$keyword}%',1,0)) as score";
        $keysString = implode(',', $keys);
        $db = self::getDB();
        if (!is_null($keyword) || $keyword!=='') {
            if (self::_isCASNO($keyword)) {
                $sql = strtr("SELECT {$keysString} FROM chemical_info as chemical LEFT JOIN chemical_type ON chemical_type.cas_no=chemical.cas_no WHERE (chemical.cas_no LIKE :cas_no) AND (chemical_type.name in (:types) OR chemical_type.name is null) group by chemical.cas_no ", [
                    ':types'=> $db->quote($types),
                    ':cas_no'=> $db->quote("{$keyword}%"),
                ]);
                $count = strtr('SELECT count(checmical.cas_no) FROM chemical_info as chemical LEFT JOIN chemical_type ON chemical_type.cas_no=chemical.cas_no WHERE (chemical.cas_no LIKE :cas_no) AND (chemical_type.name in (:types) OR chemical_type.name is null) group by chemical.cas_no ', [
                    ':types'=> $db->quote($types),
                    ':cas_no'=> $db->quote("{$keyword}%"),
                ]);
            } else {
                $sql = strtr("SELECT {$keysString} FROM chemical_info as chemical LEFT JOIN chemical_type ON chemical_type.cas_no=chemical.cas_no WHERE (chemical.name LIKE :name) AND (chemical_type.name in (:types) OR chemical_type.name is null) group by chemical.cas_no ", [
                    ':types'=> $db->quote($types),
                    ':name'=> $db->quote("%{$keyword}%"),
                ]);
                $count = strtr('SELECT /*+ MAX_EXECUTION_TIME(1000) */ count(chemical.cas_no) FROM chemical_info as chemical LEFT JOIN chemical_type ON chemical_type.cas_no=chemical.cas_no WHERE (chemical.name LIKE :name) AND (chemical_type.name in (:types) OR chemical_type.name is null) group by chemical.cas_no ', [
                    ':types'=> $db->quote($types),
                    ':name'=> $db->quote("%{$keyword}%"),
                ]);
            }
            $sql .= " order by score desc";
        } else {
            $sql = strtr("SELECT {$keysString} FROM chemical_info as chemical LEFT JOIN chemical_type ON chemical_type.cas_no=chemical.cas_no WHERE (chemical_type.name in (:types) OR chemical_type.name is null) group by chemical.cas_no ", [
                ':types'=> $db->quote($types),
            ]);
            $count = strtr('SELECT /*+ MAX_EXECUTION_TIME(1000) */ count(chemical.cas_no) FROM chemical_info as chemical LEFT JOIN chemical_type ON chemical_type.cas_no=chemical.cas_no WHERE (chemical_type.name in (:types) OR chemical_type.name is null) group by chemical.cas_no ', [
                ':types'=> $db->quote($types),
            ]);
        }
        return [$count, $sql];
    }

    // 功能替代代码结束
}



