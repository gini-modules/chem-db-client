<?php

namespace Gini\ChemDB;

class Client
{
    private static $_chemDBRPC;
    private static $_cacheTime = 86400;
    public static function getRPC()
    {
        if (self::$_chemDBRPC) return self::$_chemDBRPC;
        $conf = \Gini\Config::get('chem-db.rpc');
        $url = $conf['url'];
        $rpc = \Gini\IoC::construct('\Gini\RPC', $url);
        self::$_chemDBRPC = $rpc;
        return $rpc;
    }

    public static function getChemicalInfo($casNO)
    {
        $cacheKey = "chemdb#{$casNO}#chemical#info";
        $info = self::cache($cacheKey);
        if ($info) return $info;
        
        $info = self::getRPC()->chemdb->getChemical($casNO);
        self::cache($cacheKey, $info);

        return $info;
    }

    public static function getProduct($casNO)
    {
        $cacheKey = "chemdb#{$casNO}#product#info";
        $info = self::cache($cacheKey);
        if ($info) return $info;

        $info = self::getRPC()->product->chem->getProduct($casNO);
        self::cache($cacheKey, $info);

        return $info;
    }

    public static function getOneTypes($casNO)
    {
        $cacheKey = "chemdb#{$casNO}#types";
        $data = self::cache($cacheKey);
        if (is_array($data)) return $data;

        $data = self::getRPC()->product->chem->getTypes($casNO);
        if (is_array($data)) {
            self::cache($cacheKey, $data);
        }

        return $data;
    }

    public static function getTypes($casNOs)
    {
        if (!is_array($casNOs)) return self::getOneTypes($casNOs);

        $data = [];
        $needFetches = [];
        foreach ($casNOs as $casNO) {
            $cacheKey = "chemdb#{$casNO}#types";
            $type = self::cache($cacheKey);
            if (!is_array($type)) {
                $needFetches[] = $casNO;
                continue;
            }
            $data = array_merge($data, $type);
        }

        if (!empty($needFetches)) {
            $types = (array)self::getRPC()->product->chem->getTypes($needFetches);
            foreach ($types as $k=>$ts) {
                $cacheKey = "chemdb#{$k}#types";
                self::cache($cacheKey, $ts);
                $data[$k] = $ts;
            }
        }

        return $data;
    }

    private static function cache($key, $value=null)
    {
        $cacher = \Gini\Cache::of('gapper');
        if (is_null($value)) {
            return $cacher->get($key);
        }
        $cacher->set($key, $value, self::$_cacheTime ?: 60);
    }
}
