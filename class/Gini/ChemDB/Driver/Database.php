<?php

namespace Gini\ChemDB\Driver;

class Database
{

    private static function getDB()
    {
        return \Gini\Database::db('chem-db-client-local-db');
    }

    // TODO 目前，所有的对chemical的搜索，都是有具体的功能逻辑实现的
    // 之后需要统一管理
    public static function getChemicals($criteria, $start=0, $limit=25)
    {
    }

	private static $_chemical_info_keys = ['cas_no', 'name', 'state', 'en_name', 'inchi', 'smiles', 'inchi_key'];
    public static function getChemicalInfo($casNO)
    {
        $db = self::getDB();
        $qCasNOs = $db->quote($casNO);
        $keys = self::$_chemical_info_keys;
        $keysString = implode(',', $keys);
        $query = self::getDB()->query("select {$keysString} from chemical_info where cas_no={$qCasNOs}");
        if (!$query) return [];
        $row = $query->row(\PDO::FETCH_ASSOC);
        $row['types'] = (array) self::getOneTypes($casNO)[$casNO];
        $row['msds'] = !!($db->query("select 1 from chemical_msds where cas_no={$qCasNOs}")->value());
        return $row;
    }

    public static function getMSDS($casNO)
    {
        $db = self::getDB();
        $qCasNOs = $db->quote($casNO);
        $data = $db->query("select msds from chemical_msds where cas_no={$qCasNOs}")->value();
        return $data ? json_decode($data, true) : [];
    }

    public static function getOneTypes($casNO)
    {
        $db = self::getDB();
        $qCasNOs = $db->quote($casNO);
        $query = self::getDB()->query("select group_concat(name) as names from chemical_type where cas_no={$qCasNOs} group by cas_no");
        if (!$query) return [];

        $names = $query->value();
        if (!$names) return [];

        $data = array_unique(explode(',', $names));
        return [$casNO=> $data];
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

        return $data;
    }
}


