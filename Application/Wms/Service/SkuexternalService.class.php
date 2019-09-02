<?php

namespace Wms\Service;

use Common\Service\ExcelService;
use Common\Service\PassportService;
use Wms\Service\SkuService;
use Wms\Dao\SkuexternalDao;
use Wms\Dao\SpuDao;
use Wms\Dao\SkuDao;

class SkuexternalService {

    public $waCode;

    function __construct()
    {
        /*$workerData = PassportService::getInstance()->loginUser();
        if(empty($workerData)){
            venus_throw_exception(110);
        }*/
        $this->waCode = 'WA000001';//$workerData["war_code"];
    }
    //导入客户销售方案
    public function esku_import(){
        $project = $_POST['data'];//;json_decode($_POST['data'], true);
        $datas = ExcelService::getInstance()->upload("file");
        $dicts = array(
            "A" => "skuCode",//sku品类编号
            "B" => "spuCode",//spu品类编号
            "C" => "skuName",//sku名称
            "D" => "brand",//品牌
            "G" => "eprice",//销售价格（客户）
            "H" => "supcode",//销售价格（客户）
        );

        $skueList = array();
        foreach ($datas as $sheetName => $list) {
            unset($list[0]);
            $skueList = array_merge($skueList, $list);
        }
        \Think\Log::write(json_encode($datas),'zk0802-a');
        venus_db_starttrans();//启动事务
        $result = true;
        //清除原来数据
        $truncate = SkuexternalDao::getInstance($this->waCode)->delByProject($project);
        foreach ($skueList as $index => $skueItem) {
            $skueData = array();
            foreach ($dicts as $col => $key) {
                $skueData[$key] = isset($skueItem[$col]) ? $skueItem[$col] : "";
            }

            if (trim($skueData['skuCode']) == '' || trim($skueData['eprice']) == '' || trim($skueData['spuCode']) == '') {
                if (trim($skueData['brand']) == '' && trim($skueData['skuName']) == '') {
                    continue;
                } else {
                    if (trim($skueData['skuCode']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "SKU编号不能为空");
                        return false;
                    }

                    if (trim($skueData['spuCode']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "SPU不能为空");
                        return false;
                    }
                    if (trim($skueData['eprice']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "客户销售价格不能为空");
                        return false;
                    }
                }
            } else {
                $spures = SpuDao::getInstance($this->waCode)->queryByCode($skueData['spuCode']);
                if(!$spures){
                    venus_db_rollback();//回滚事务
                    venus_throw_exception(2, "spu不存在，请检查表格，失败编号：".$skueData['spuCode']);
                    return false;
                }
                //
                if(empty($skueData['supcode'])){
                    $skueData['supcode'] = $spures['sup_code'];
                }

                $skures = SkuDao::getInstance($this->waCode)->queryByCode($skueData['skuCode']);
                if(!$skures){
                    venus_db_rollback();//回滚事务
                    venus_throw_exception(2, "sku不存在，请检查表格，失败编号：".$skueData['spuCode']);
                    return false;
                }
                $skueres = SkuexternalDao::getInstance($this->waCode)->skueHad($project, $skueData['skuCode']);
                
                if(!empty($skueres)){
                    //$res = SkuexternalDao::getInstance($waCode)->skueUpdate();
                    $res = SkuexternalDao::getInstance($this->waCode)->skueDel($project, $skueData['skuCode']);
                    if(!$res){
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(2, "客户修改商品，失败编号：".$skueData['skuCode']);
                        return false;  
                    }
                }
                $data = array(
                    'sku_code' => $skueData['skuCode'],
                    'spu_code' => $skueData['spuCode'],
                    'war_code' => $project,
                    'spu_eprice' => $skueData['eprice'],
                    'timestamp' => date("Y-m-d H:i:s"),
                    'sku_norm' => $spures['spu_norm'],
                    'sku_unit' => $spures['spu_unit'],
                    'spu_count' => $skures['spu_count'],
                    'sku_mark' => $skures['sku_mark'],
                    'supcode'  => $skueData['supcode'],

                );
                $result = $result && SkuexternalDao::getInstance($waCode)->insert($data);
            }
        }
        if ($result) {
            venus_db_commit();
            $this->release_latestsku($project);
            $success = true;
            $message = "导入客户销售方案成功";

        } else {
            venus_db_rollback();
            $success = false;
            $message = "导入客户销售方案失败";
        }
        return array($success, "", $message);
    }

    //导出客户销售方案
    public function esku_export()
    {
        $project = $_POST['data']['warCode'];
        $skueDatas = SkuexternalDao::getInstance($waCode)->skueMessage($project);
        $skueData = array();
        $fname = date("Ymd")."客户销售方案";
        $header = array("sku编号", "spu编号", "品名", "一级名称", "二级名称", "品牌", "客户销售价格","供货商编码");
        foreach ($skueDatas as $index => $skueItem) {
            $skueList = array(
                "skCode" => $skueItem['sku_code'],
                "spCode" => $skueItem['spu_code'],
                "spName" => $skueItem['spu_name'],
                "class_1" => venus_spu_type_name($skueItem['spu_type']),
                "class_2" => venus_spu_catalog_name($skueItem['spu_subtype']),
                "spBrand" => $skueItem['spu_brand'],
                "eprice" => $skueItem['spu_eprice'],
                "supcode" => $skueItem['supcode'],
            );
            
            $skueData[$fname][] = array(
                    $skueList['skCode'],$skueList['spCode'],$skueList['spName'],$skueList['class_1'],$skueList['class_2'],$skueList['spBrand'],$skueList['eprice'],$skueList['supcode']
                );
        }
        $fileName = ExcelService::getInstance()->exportExcel($skueData, $header, "001");
        if ($fileName) {
            $success = true;
            $data = $fileName;
            $message = "导出成功";
            return array($success, $data, $message);
        } else {
            $success = false;
            $data = "";
            $message = "下载失败";
        }
        return array($success, $data, $message);
    }
    //list and search
    public function esku_list(){
        /*$project = $_POST['data']['warCode'];
        $pageCurrent = $_POST['data']['pageCurrent'];//当前页码
        $pageSize = 100;//当前页面总条数
        $list = SkuexternalDao::getInstance($warCode)->queryByWarCode($project, $condition, $pageCurrent, $pageSize);

        return array(true, $list, "客户信息查询成功");*/
        $spName = $_POST['data']['spName'];
        $spType = $_POST['data']['spType'];
        $spSubtype = $_POST['data']['spSubtype'];
        $skStatus = $_POST['data']['skStatus'];
        $pageCurrent = $_POST['data']['pageCurrent'];//当前页码
        $pageSize = 100;//当前页面总条数
        $project = $_POST['data']['warCode'];

        if(empty($project)){
            return array(false, '', '请选择客户进行内容展示');
        }else{
            $condition['war_Code'] = $project;
        }
        if(!empty($spName) && preg_match ("/^[a-z]/i", $spName)){
            $condition['abname'] = $spName;
        }
        if (!empty($spName) && !preg_match ("/^[a-z]/i", $spName)) {//SPU名称
            $condition['%name%'] = $spName;
        }

        if (!empty($spType)) {//一级分类编号
            $condition['type'] = $spType;
        }

        if (!empty($spSubtype)) {//状态（上、下线）
            $condition['subtype'] = $spSubtype;
        }

        if (!empty($skStatus)) {//客户仓库
            $condition['status'] = $skStatus;
        }

        //当前页码
        if (empty($pageCurrent)) {
            $pageCurrent = 0;
        }

        $SkueDao = SkuexternalDao::getInstance($this->waCode);
        $totalCount = $SkueDao->queryCountByCondition($condition);//获取指定条件的总条数
        $pageLimit = pageLimit($totalCount, $pageCurrent);
        $skuDataList = $SkueDao->queryListByCondition($condition, $pageLimit['page'], $pageLimit['pSize']);
        
        if (empty($skuDataList)) {
            $skuList = array(
                "pageCurrent" => 0,
                "pageSize" => 100,
                "totalCount" => 0
            );
            $skuList["list"] = array();
        } else {
            $skuList = array(
                "pageCurrent" => $pageCurrent,
                "pageSize" => $pageSize,
                "totalCount" => $totalCount
            );
            foreach ($skuDataList as $index => $skuItem) {
                if(!empty($skuItem['sku_mark'])){
                  $skMark = "(".$skuItem['sku_mark'].")";
                }
                $skuList["list"][$index] = array(
                        "skCode" => $skuItem['sku_code'],//SKU编号
                        "spCode" => $skuItem['spu_code'],//所属SPU编码
                        "spName" => $skuItem['spu_name'].$skMark,//SPU货品名称
                        "spCount" => $skuItem['spu_count'],//规格数量
                        "skUnit" => $skuItem['sku_unit'],//规格单位
                        "skNorm" => $skuItem['sku_norm'],//规格
                        "skEprice" => $skuItem['spu_eprice'],
                        "skSupcode" => $skuItem['supcode'],
                        "skStatus" => $skuItem['sku_status']//状态（上、下线）
                );
            }
        }
        return array(true, $skuList, "");
    }
    //2.所选sku设为上线
    public function status_online() {

        $skCode = $_POST['data']['skCode'];
        $skStatus = $_POST['data']['skStatus'];
        $project = $_POST['data']['warCode'];
        if (empty($skCode)) {
            venus_throw_exception(1, "货品编号不能为空");
            return false;
        }

        if (empty($skStatus)) {
            venus_throw_exception(1, "货品状态不能为空");
            return false;
        }
        $SkueDao = SkuexternalDao::getInstance($this->waCode);
        $skuStatusUpd = $SkueDao->updateStatusCodeByCode($project, $skCode, $skStatus);
        if ($skuStatusUpd) {
            $this->release_latestsku($project);
            $success = true;
            $message = "所选sku上线成功";
        } else {
            $success = false;
            $message = "所选sku上线失败";
        }
        return array($success, "", $message);
    }
    //3.所选sku设为下线
    public function status_offline() {

        $skCode = $_POST['data']['skCode'];
        $skStatus = $_POST['data']['skStatus'];
        $project = $_POST['data']['warCode'];
        if (empty($project)) {
            venus_throw_exception(1, "客户编号不能为空");
            return false;
        }
        if (empty($skCode)) {
            venus_throw_exception(1, "货品编号不能为空");
            return false;
        }

        if (empty($skStatus)) {
            venus_throw_exception(1, "状态不能为空");
            return false;
        }
        $SkueDao = SkuexternalDao::getInstance($this->waCode);
        $skuStatusUpd = $SkueDao->updateStatusCodeByCode($project, $skCode, $skStatus);
        if ($skuStatusUpd) {
            $this->release_latestsku($project);
            $success = true;
            $message = "所选sku下线成功";
        } else {
            $success = false;
            $message = "所选sku下线失败";
        }
        return array($success, "", $message);
    }


    //释放最新的sku数据
    public function release_latestsku($exwarehousecode){
        //$skuFilePath = "/home/dev/venus/Public/files/sku/latestsku.txt";
        $skuFilePath = C("FILE_SAVE_PATH")."sku/externalsku.{$exwarehousecode}.txt";
        if(file_exists($skuFilePath)){
            unlink($skuFilePath);
            S(C("SKU_VERSION_KEY"),null);
            
        }
    }
    //外部客户信息
    public function esku_customer(){
        $success = true;
        $data['customer'] = C("CUSTOMER");
        $message = "信息展示";
        return array($success, $data, $message);
    }
    //自动上下架，导入导出
    public function auto_import(){
        $type = $_POST['type'];
        $file = $_FILES['file'];
        switch ($type) {
            case 1:
                $fileName = 'Monday.xlsx';
                break;
            case 2:
                $fileName = 'Tuesday.xlsx';
                break;
            case 3:
                $fileName = 'Wednesday.xlsx';
                break;
            case 4:
                $fileName = 'Thursday.xlsx';
                break;
            case 5:
                $fileName = 'Friday.xlsx';
                break;
            default:
                return array(false, '', '参数不对');
                break;
        }
        $time = date('YmdHis');
        $name = strrev($file['name']);
        $arr = explode('.', $name);
        $xlsx = $arr[0];    
        
        if($xlsx = 'xlsx'){        
            if(file_exists(C('FILE_TPLS').$fileName)){
                exec('mv '.C('FILE_TPLS').$fileName.' '.C('FILE_TPLS').$time.$fileName);
            }
           $res =  move_uploaded_file($file['tmp_name'], C('FILE_TPLS').$fileName );
           exec('chmod 777 '.C('FILE_TPLS').$fileName);
           $cc = $_FILES['file']['error'];
           return array(true, '', '导入成功');
        }else{
            array(false, '', '表格格式用 xlsx');
        }
    }
    //自动上下架，导出
    public function auto_explode(){
        $type = $_POST['data']['type'];
        switch ($type) {
            case 1:
                $fileName = 'Monday.xlsx';
                break;
            case 2:
                $fileName = 'Tuesday.xlsx';
                break;
            case 3:
                $fileName = 'Wednesday.xlsx';
                break;
            case 4:
                $fileName = 'Thursday.xlsx';
                break;
            case 5:
                $fileName = 'Friday.xlsx';
                break;
            default:
                return array(false, '', '参数不对');
                break;
        }
        if(!file_exists(C('FILE_TPLS').$fileName)){
            return array(false, '', '文件不存在');
        } 
        $url = 'https://'.$_SERVER['HTTP_HOST'].'/static/tpls/'.$fileName;
        return array(true, $url, '下载路径返回成功');
    }
    
    
    //新发地sku搜索
    public function xinfadi_sku_search()
    {
        $reTime = $_POST['data']['reTime'];//发布时间
        $pageCurrent = $_POST['data']['pageCurrent'];//当前页码
        $pageSize = 100;//当前页面总条数

        if (!empty($reTime)) {//一级分类编号
            $condition['reTime'] = $reTime;
        }

        if (empty($pageCurrent)) {//当前页码
            $pageCurrent = 0;
        }

        $SkuDao = SkuexternalDao::getInstance($this->waCode);
        $totalCount = $SkuDao->queryCountByXfdskuCondition($condition);//获取指定条件的总条数
        $pageLimit = pageLimit($totalCount, $pageCurrent);
        $skuDataList = $SkuDao->queryXfdSkuListByCondition($condition, $pageLimit['page'], $pageLimit['pSize']);

        if (empty($skuDataList)) {
            $skuList = array(
                "pageCurrent" => 0,
                "pageSize" => 100,
                "totalCount" => 0
            );
            $skuList["list"] = array();
        } else {
            $skuList = array(
                "pageCurrent" => $pageCurrent,
                "pageSize" => $pageSize,
                "totalCount" => $totalCount
            );
            foreach ($skuDataList as $index => $skuItem) {

                $skuList["list"][$index] = array(
                    "skCode" => $skuItem['sku_code'],
                    "spCode" => $skuItem['spu_code'],
                    "skName" => $skuItem['sku_name'],
                    "spName" => $skuItem['spu_name'],
                    "mPrice" => $skuItem['minimum_price'],
                    "aPrice" => $skuItem['average_price'],
                    "maPrice" => $skuItem['maximum_price'],
                    "pPercent" => $skuItem['pro_percent'],
                    "skNorm" => $skuItem['sku_norm'],
                    "skUnit" => $skuItem['sku_unit'],
                    "reTime" => $skuItem['release_time'],
                );
            }
        }
        return array(true, $skuList, "");
    }

    public function xinfadi_sku_export()
    {
        $spuDataList = SkuexternalDao::getInstance()->queryAllList();
        $spuBprice = array();
        $fname = "新发地SKU数据";
        $header = array("SKU编号", "SKU名称", "SPU编号", "SPU名称", "最低价", "平均价", "最高价", "利润率", "规格", "单位", "发布时间");
        foreach ($spuDataList as $index => $spuItem) {

            $spuList = array(
                "skCode" => $spuItem['sku_code'],
                "skName" => $spuItem['sku_name'],
                "spCode" => $spuItem['spu_code'],
                "spName" => $spuItem['spu_name'],
                "miPrice" => $spuItem['minimum_price'],
                "aPrice" => $spuItem['average_price'],
                "maPrice" => $spuItem['maximum_price'],
                "pPercent" => $spuItem['pro_percent'],
                "skNorm" => $spuItem['sku_norm'],
                "skUnit" => $spuItem['sku_unit'],
                "rTime" => $spuItem['release_time'],
            );

            $spuBprice[$fname][] = array(
                $spuList['skCode'], $spuList['skName'], $spuList['spCode'], $spuList['spName'],
                $spuList['miPrice'],$spuList['aPrice'],$spuList['maPrice'],$spuList['pPercent'],
                 $spuList['skNorm'], $spuList['skUnit'], $spuList['rTime']
            );
        }

        $fileName = ExcelService::getInstance()->exportExcel($spuBprice, $header, "001");

        if ($fileName) {
            $success = true;
            $data = $fileName;
            $message = "";
            return array($success, $data, $message);
        } else {
            $success = false;
            $data = "";
            $message = "下载失败";
        }
        return array($success, $data, $message);
    }
    
}



