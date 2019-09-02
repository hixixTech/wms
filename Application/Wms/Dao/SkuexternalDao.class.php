<?php

namespace Wms\Dao;

use Common\Common\BaseDao;
use Common\Common\BaseDaoInterface;

/**
 * SKU数据
 * Class SkuDao
 * @package Wms\Dao
 */
class SkuexternalDao extends BaseDao implements BaseDaoInterface
{
    /**
     * @var string
     */
    private $dbname = "";

    /**
     * SkuDao constructor.
     */
    function __construct()
    {
        $this->dbname = C("WMS_MASTER_DBNAME");
    }
    //查询

    /**
     * @param $data
     * @return mixed
     */
    public function insert($data)
    {
//        $code = venus_unique_code("SK");
//        $data['sku_code'] = $code;
        $data = M("skuexternal")->add($data);
        return $data;
    }

    /**
     * @param $cond
     * @return mixed
     */
    public function queryCountByCondition($cond)
    {
        $condition = array();
        $skujoinconds = array();
        if (isset($cond["%name%"])) {
            $skuname = str_replace(array("'", "\""), "", $cond["%name%"]);
            array_push($skujoinconds, "spu.spu_name LIKE '%{$skuname}%'");
        }
        if (isset($cond["name"])) {
            array_push($skujoinconds, "spu.spu_name = '" . $cond["name"] . "'");
        }
        if (isset($cond["war_Code"])) {
            array_push($skujoinconds, "sku.war_code = '" . $cond["war_Code"] . "'");
        }
        if (isset($cond["abname"])) {
            $spuabname = str_replace(array("'", "\""), "", $cond["abname"]);
            array_push($skujoinconds, "spu.spu_abname LIKE '%#{$spuabname}%'");
        }
        if (isset($cond["type"])) {
            array_push($skujoinconds, "spu.spu_type = " . $cond["type"]);
        }
        if (isset($cond["subtype"])) {
            array_push($skujoinconds, "spu.spu_subtype = " . $cond["subtype"]);
        }
        if (isset($cond["status"])) {
            array_push($skujoinconds, "sku.sku_status = " . $cond["status"]);
        }
        $skujoinconds = empty($skujoinconds) ? "" : " AND " . implode(" AND ", $skujoinconds);

        return M("skuexternal")->alias('sku')->field('*,spu.spu_code,sku.sku_code')
            ->join("JOIN wms_spu spu ON spu.spu_code = sku.spu_code {$skujoinconds}")
            ->where($condition)->order('sku.sku_code desc')->fetchSql(false)->count();

        /* if (isset($cond["exwarcode"])) {
             $exwarcode = str_replace(array("'", "\""), "", $cond["exwarcode"]);
             array_push($projoinconds, "pro.exwar_code = '{$exwarcode}'");
             $projoinconds = empty($projoinconds) ? "" : " AND " . implode(" AND ", $projoinconds);
             return M("sku")->alias('sku')->field('*,spu.spu_code,sku.sku_code')
                 ->join("JOIN wms_spu spu ON spu.spu_code = sku.spu_code {$skujoinconds}")
                 ->join("JOIN wms_profit pro ON pro.spu_code = spu.spu_code {$projoinconds}")
                 ->where($condition)->order('sku.sku_code desc')->fetchSql(false)->count();
         } else {
             return M("sku")->alias('sku')->field('*,spu.spu_code,sku.sku_code')
                 ->join("JOIN wms_spu spu ON spu.spu_code = sku.spu_code {$skujoinconds}")
                 ->where($condition)->order('sku.sku_code desc')->fetchSql(false)->count();
         }*/

    }
    //查询

    /**
     * @param $cond
     * @param int $page
     * @param int $count
     * @return mixed
     */
    public function queryListByCondition($cond, $page = 0, $count = 100)
    {
        $condition = array();
        $skujoinconds = array();
        if (isset($cond["%name%"])) {
            $skuname = str_replace(array("'", "\""), "", $cond["%name%"]);
            array_push($skujoinconds, "spu.spu_name LIKE '%{$skuname}%'");
        }
        if (isset($cond["name"])) {
            array_push($skujoinconds, "spu.spu_name = " . $cond["name"]);
        }
        if (isset($cond["war_Code"])) {
            array_push($skujoinconds, "sku.war_code = '" . $cond["war_Code"] . "'");
        }
        if (isset($cond["abname"])) {
            $spuabname = str_replace(array("'", "\""), "", $cond["abname"]);
            array_push($skujoinconds, "spu.spu_abname LIKE '%#{$spuabname}%'");
        }

        if (isset($cond["type"])) {
            array_push($skujoinconds, "spu.spu_type = " . $cond["type"]);
        }
        if (isset($cond["subtype"])) {
            array_push($skujoinconds, "spu.spu_subtype = " . $cond["subtype"]);
        }
        if (isset($cond["status"])) {
            array_push($skujoinconds, "sku.sku_status = " . $cond["status"]);
        }
        $skujoinconds = empty($skujoinconds) ? "" : " AND " . implode(" AND ", $skujoinconds);


//        return M("sku")->alias('sku')->field('*,spu.spu_code,sku.sku_code')
//            ->join("JOIN wms_spu spu ON spu.spu_code = sku.spu_code {$skujoinconds}")
//            ->where($condition)->order('spu_subtype asc')->limit("{$page},{$count}")->fetchSql(true)->select();


        if (isset($cond["exwarcode"])) {
            $exwarcode = str_replace(array("'", "\""), "", $cond["exwarcode"]);
            array_push($projoinconds, "pro.exwar_code = '{$exwarcode}'");
            $projoinconds = empty($projoinconds) ? "" : " AND " . implode(" AND ", $projoinconds);
            return M("skuexternal")->alias('sku')->field('*,spu.spu_code,sku.sku_code')
                ->join("JOIN wms_spu spu ON spu.spu_code = sku.spu_code {$skujoinconds}")
                ->join("JOIN wms_profit pro ON pro.spu_code = spu.spu_code {$projoinconds}")
//                ->where($condition)->order('sku.sku_code desc')->limit("{$page},{$count}")->fetchSql(false)->select();
                ->where($condition)->order('spu_subtype,spu.spu_code asc')->limit("{$page},{$count}")->fetchSql(false)->select();
        } else {
            return M("skuexternal")->alias('sku')->field('*,spu.spu_code,sku.sku_code')
                ->join("JOIN wms_spu spu ON spu.spu_code = sku.spu_code {$skujoinconds}")
//                ->where($condition)->order('sku.sku_code desc')->limit("{$page},{$count}")->fetchSql(false)->select();
                ->where($condition)->order('spu_subtype,spu.spu_code asc')->limit("{$page},{$count}")->fetchSql(false)->select();
        }


    }

    //根据客户查询显示数据
    public function queryByWarCode($project, $condition = '', $page = 0, $count = 100)
    {
        $condition['a.war_code'] = $project;
        $page2 = $page * $count;
        $data['list'] = M('skuexternal')->alias('a')
            ->field('*,')
            ->join('wms_sku c where a.sku_code = c.sku_code', 'LEFT')
            ->join('wms_spu b where a.spu_code = b.spu_code', 'LEFT')
            ->where($condition)
            ->limit($page2, $count)
            ->select();
        $data['total'] = M('skuexternal')->where($condition)->count();
        return $data;
    }

    //
    public function skueMessage($project)
    {
        $condition['a.war_code'] = $project;
        $data = M('skuexternal')->alias("a")
            ->field("a.sku_code, a.spu_code, a.spu_eprice, b.spu_name, b.spu_brand,b.spu_type,b.spu_subtype,a.supcode")
            ->where($condition)
            ->join("wms_spu b on a.spu_code = b.spu_code", "LEFT")
            ->select();
        return $data;

    }

    //
    public function skueHad($project, $skuCode)
    {
        $condition['war_code'] = $project;
        $condition['sku_code'] = $skuCode;
        $data = M('skuexternal')->where($condition)->find();
        return $data;
    }

    //更新状态
    public function updateStatusCodeByCode($project, $code, $skuStatus)
    {
        $condition['sku_code'] = array('in', $code);
        return M("skuexternal")
            ->where(array("war_code" => $project, $condition))
            ->save(array("timestamp" => venus_current_datetime(), "sku_status" => $skuStatus));
    }

    //删除数据信息
    public function skueDel($project, $skuCode)
    {
        $condition['war_code'] = $project;
        $condition['sku_code'] = $skuCode;
        $data = M('skuexternal')->where($condition)->delete();
        return $data;
    }

    //
    public function delByProject($project)
    {
        $condition['war_code'] = $project;
        $data = M('skuexternal')->where($condition)->delete();
        return $data;
    }

    //查询外部货品字典销售价 
    public function queryListBySkCode($cond)
    {
        $condition['sku_code'] = $cond['skCode'];
        $condition['war_code'] = $cond['warCode'];
        return M("skuexternal")->alias('sku')->field('sku.spu_eprice,sku.supcode')
            ->where($condition)->fetchSql(false)->select();
    }

    public function queryXfdSkuListByCondition($cond, $page = 0, $count = 100)
    {
        $condition = array();
        if (isset($cond["reTime"])) {
            $condition["release_time"] = $cond["reTime"];
        }

        return M("xinfadisku")
            ->where($condition)->order('sku_code desc')->limit("{$page},{$count}")->fetchSql(false)->select();
    }

    //总数

    /**
     * @param $cond
     * @return mixed
     */
    public function queryCountByXfdskuCondition($cond)
    {
        $condition = array();
        if (isset($cond["reTime"])) {
            $condition["release_time"] = $cond["reTime"];
        }

        return M("xinfadisku")
            ->where($condition)->order('spu_code asc')->fetchSql(false)->count();
    }

    public function queryAllList()
    {
        return M("xinfadisku")->fetchSql(false)->select();
    }

    public function queryByExternalSkuCode($code, $warcode)
    {
        $condition = array("a.spu_code" => $code, "a.war_code" => $warcode);
        return M("skuexternal")->alias("a")->field('*,a.spu_code,a.sku_norm,a.sku_unit,a.spu_eprice')
            ->join("JOIN wms_sku sku ON sku.spu_code = a.spu_code")
            ->join("JOIN wms_spu spu ON spu.spu_code = sku.spu_code")
            ->where($condition)->fetchSql(false)->find();
    }
}