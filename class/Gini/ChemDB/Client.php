<?php

namespace Gini\ChemDB;

class Client
{
    public static $cacheTimeout = 86400;
    public static $fullCacheKey = 'chemical[allcached]';
    public static $titles = [
        'drug_precursor' => '易制毒',
        'hazardous' => '危险品',
        'highly_toxic' => '剧毒品',
        'explosive' => '易制爆'
    ];
    public static function getRPC()
    {
        return \Gini\RPC::of('chemdb');
    }

    public static function getChemicalInfo($casNO)
    {
        $cacheKey = "chemical[{$casNO}]";
        $info = self::cache($cacheKey);
        if ($info) {
            return $info;
        }

        if (self::cache(self::$fullCacheKey)) {
            return [];
        }

        $info = self::getRPC()->ChemDB->getChemical($casNO);
        self::cache($cacheKey, $info);

        return $info;
    }

    public static function getMSDS($casNO)
    {
        $cacheKey = "msds[{$casNO}]";
        $msds = self::cache($cacheKey);
        if ($msds) {
            return $msds;
        }

        $msds = self::getRPC()->ChemDB->getMSDS($casNO);
        self::cache($cacheKey, $msds);

        return $msds;
    }

    public static function getProduct($casNO)
    {
        $info = self::getChemicalInfo($casNO);
        if (!$info) {
            return;
        }

        $types = (array) $info['types'];
        if (empty($types)) {
            return;
        }

        $data = [];
        $titles = self::$titles;
        foreach ($types as $type) {
            $data[$type] = [
                'cas_no' => $info['cas_no'],
                'name' => $info['name'],
                'type' => $type,
                'state' => $info['state'],
                'type_title' => $titles[$type]?:$type,
            ];
        }

        return $data;
    }

    public static function getOneTypes($casNO)
    {
        $info = self::getChemicalInfo($casNO);
        if (empty($info)) return [];

        $types = $info['types'];
        if (empty($types)) return [];

        return [
            $casNO=> $types
        ];
    }

    public static function getTypes($casNOs)
    {
        if (!is_array($casNOs)) {
            return self::getOneTypes($casNOs);
        }

        $data = [];
        foreach ($casNOs as $casNO) {
            $type = self::getOneTypes($casNO);
            $data = array_merge($data, $type);
        }

        return $data;
    }

    private static function cache($key, $value = null)
    {
        $cacher = \Gini\Cache::of('chemdb');
        if (is_null($value)) {
            return $cacher->get($key);
        }
        $cacher->set($key, $value, self::$cacheTimeout ?: 60);
    }
}
