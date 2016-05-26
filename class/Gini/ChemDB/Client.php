<?php

namespace Gini\ChemDB;

class Client
{
    private static $_chemDBRPC;
    public static $cacheTimeout = 86400;
    public static function getRPC()
    {
        if (self::$_chemDBRPC) {
            return self::$_chemDBRPC;
        }
        $conf = \Gini\Config::get('chem-db.rpc');
        $url = $conf['url'];
        $rpc = \Gini\IoC::construct('\Gini\RPC', $url);
        self::$_chemDBRPC = $rpc;

        return $rpc;
    }

    public static function getChemicalInfo($casNO)
    {
        $cacheKey = "chemical[{$casNO}]";
        $info = self::cache($cacheKey);
        if ($info) {
            return $info;
        }

        $info = self::getRPC()->chemdb->getChemical($casNO);
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
        foreach ($types as $type) {
            $data[$type] = [
                'cas_no' => $info['cas_no'],
                'name' => $info['name'],
                'type' => $type,
                'state' => $info['state'],
                'type_title' => $info['titles'][$type],
            ];
        }

        return $data;
    }

    public static function getOneTypes($casNO)
    {
        $cacheKey = "chemical[{$casNO}]types";
        $data = self::cache($cacheKey);
        if (is_array($data)) {
            return $data;
        }

        $data = self::getRPC()->chemDB->getChemicalTypes($casNO);
        if (is_array($data)) {
            self::cache($cacheKey, $data);
        }

        return $data;
    }

    public static function getTypes($casNOs)
    {
        if (!is_array($casNOs)) {
            return self::getOneTypes($casNOs);
        }

        $data = [];
        $needFetches = [];
        foreach ($casNOs as $casNO) {
            $cacheKey = "chemical[{$casNO}]types";
            $type = self::cache($cacheKey);
            if (!is_array($type)) {
                $needFetches[] = $casNO;
                continue;
            }
            $data = array_merge($data, $type);
        }

        if (!empty($needFetches)) {
            $types = (array) self::getRPC()->chemDB->getChemicalTypes($needFetches);
            foreach ($types as $k => $ts) {
                if (!is_array($ts)) {
                    continue;
                }
                $cacheKey = "chemical[{$k}]types";
                self::cache($cacheKey, [$k => $ts]);
                $data[$k] = $ts;
            }
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
