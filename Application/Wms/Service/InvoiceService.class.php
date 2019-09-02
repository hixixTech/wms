<?php
/**
 * Created by PhpStorm.
 * User: lingn
 * Date: 2018/7/27
 * Time: 10:13
 */

namespace Wms\Service;


use Common\Service\ExcelService;
use Common\Service\PassportService;
use Common\Service\TaskService;
use Common\Service\TraceService;
use http\Exception;
use Wms\Dao\GoodsbatchDao;
use Wms\Dao\GoodsDao;
use Wms\Dao\GoodstoredDao;
use Wms\Dao\IgoodsDao;
use Wms\Dao\IgoodsentDao;
use Wms\Dao\InvoiceDao;
use Wms\Dao\SkuDao;
use Wms\Dao\SpuDao;
use Wms\Dao\TaskDao;
use Wms\Dao\WarehouseDao;
use Wms\Dao\WorkerDao;

class InvoiceService
{
    static private $INVOICE_STATUS_FORECAST = "1";//出仓单已预报状态
    static private $INVOICE_STATUS_CREATE = "2";//出仓单已创建状态
    static private $INVOICE_STATUS_PICK = "3";//inspection出仓单已拣货状态
    static private $INVOICE_STATUS_INSPECTION = "4";//inspection出仓单已验货状态
    static private $INVOICE_STATUS_FINISH = "5";//inspection出仓单已出仓状态
    static private $INVOICE_STATUS_RECEIPT = "6";//出仓单已收货状态
    static private $INVOICE_STATUS_CANCEL = "7";//出仓单已取消状态

    static private $GOODSBATCH_STATUS_CREATE = "1";//货品批次创建状态
    static private $GOODSBATCH_STATUS_INSPECTION = "2";//货品批次验货状态
    static private $GOODSBATCH_STATUS_PUTAWAY = "3";//Putaway货品批次上架状态
    static private $GOODSBATCH_STATUS_FINISH = "4";//货品批次使用完状态

    static private $TASK_STATUS_CREATE = "1";//工单创建状态
    static private $TASK_STATUS_UNDERWAY = "2";//underway工单进行中状态
    static private $TASK_STATUS_FINISH = "3";//工单完成状态
    static private $TASK_STATUS_CANCEL = "4";//工单取消状态

    static private $TASK_TYPE_RECEIPT = "1";//工单类型:入仓业务-入仓
    static private $TASK_TYPE_INSPECTION = "2";//工单类型:入仓业务-验货
    static private $TASK_TYPE_PUTAWAY = "3";//工单类型:入仓业务-上架
    static private $TASK_TYPE_UPTPOS = "4";//工单类型:仓内业务-补货移区
    static private $TASK_TYPE_INVPICKORDER = "5";//工单类型:出仓业务-拣货捡单
    static private $TASK_TYPE_INVINSPECTION = "6";//工单类型:出仓业务-验货出仓
    static private $TASK_TYPE_INVUNUAUAL = "7";//工单类型:出仓业务-异常

    static private $INVOICE_CREATE_TYPE_HANDWORK = "1";//手工创建
    static private $INVOICE_CREATE_TYPE_API = "2";//API创建
    static private $INVOICE_CREATE_TYPE_FILE = "3";//文件导入

    public $warCode;
    public $worcode;

    public function __construct()
    {
        //获取登录用户信息
        $workerData = PassportService::getInstance()->loginUser();
        if (empty($workerData)) {
            venus_throw_exception(110);
        }
        $this->warCode = $workerData["war_code"];//仓库编号
        $this->worcode = $workerData["wor_code"];//人员编号
        $this->warAddress = $workerData["war_address"];//仓库地址
        $this->warPostal = $workerData["war_postal"];//仓库邮编
//        $this->warCode = $workerData["war_code"] = "WA000001";
//        $this->worcode = $workerData["wor_code"] = "WO000001";
//        $this->warAddress = $workerData["war_address"] = "北京市朝阳区亮马桥";//仓库地址
//        $this->warPostal = $workerData["war_postal"] = "100013";//仓库邮编
    }

    /**
     * @param $param
     * @return array|bool
     * 创建出仓单／获取sku
     */
    public function invoice_get_sku($param)
    {

        $warCode = $this->warCode;
        if (!isset($param)) {
            $param = $_POST;
        }
        $data = array();
        if (empty($param['data']['sku'])) {
            $message = "sku为空";
            venus_throw_exception(1, $message);
            return false;
        } else {

            $goodsModel = GoodsDao::getInstance($warCode);

            $sku = trim($param['data']['sku']);
            $type = substr($sku, 0, 2);
            if ($type == "SK") {
                $queryGoodsData = $goodsModel->queryBySkuCode($sku);
                $spuCount = intval($queryGoodsData['spu_count']);
                $goodsCount = ($queryGoodsData['goods_count'] == intval($queryGoodsData['goods_count'])) ? intval($queryGoodsData['goods_count']) : $queryGoodsData['goods_count'];
                $spuData = array(
                    "skName" => $queryGoodsData['spu_name'],
                    "skCode" => $queryGoodsData['sku_code'],
                    "skNorm" => $queryGoodsData['sku_norm'],
                    "skUnit" => $queryGoodsData['sku_unit'],
                    "spCode" => $queryGoodsData['spu_code'],
                    "spCount" => $spuCount,
                    "spUnit" => $queryGoodsData['spu_unit'],
                    "spCunit" => $queryGoodsData['spu_cunit'],
                    "goods" => $goodsCount,//前台计算为sku库存
                    "mark" => $queryGoodsData['sku_mark']
                );
                $data['list'][] = $spuData;
            } else {
                //去除由于输入法造成的字符串含单引号问题
                $spName = str_replace("'", "", $sku);
                //用拼音字典搜索spu名字
                if (!empty($spName) && preg_match("/^[a-z]/i", $spName)) {
                    $cond['abname'] = $spName;
                }
                //用中文搜索spu名字
                if (!empty($spName) && !preg_match("/^[a-z]/i", $spName)) {//SPU名称
                    $cond["%name%"] = $spName;
                }
                //获取仓库满足条件的商品列表
                $goodsDataList = $goodsModel->queryListByCondition($cond);
                foreach ($goodsDataList as $goodsData) {
                    $goodsCount = ($goodsData['goods_count'] == intval($goodsData['goods_count'])) ? intval($goodsData['goods_count']) : $goodsData['goods_count'];
                    $spuData = array(
                        "skName" => $goodsData['spu_name'],
                        "skCode" => $goodsData['sku_code'],
                        "skNorm" => $goodsData['sku_norm'],
                        "skUnit" => $goodsData['sku_unit'],
                        "spCode" => $goodsData['spu_code'],
                        "spCount" => $goodsData['spu_count'],
                        "spUnit" => $goodsData['spu_unit'],
                        "spCunit" => $goodsData['spu_cunit'],
                        "goods" => $goodsCount,//前台计算为sku库存
                        "mark" => $goodsData['sku_mark']
                    );
                    $data['list'][] = $spuData;
                }
            }
            $success = true;
            $message = '';
            return array($success, $data, $message);
        }
    }

//    /**
//     * @param $param
//     * @return array|bool
//     * 创建出仓单
//     */
//    public function invoice_create($param)
//    {
//        if (!isset($param)) {
//            $param = $_POST;
//            if (empty($param['data']['type'])) {
//                $type = self::$INVOICE_CREATE_TYPE_HANDWORK;//pc端，手工记账
//            } else {
//                $type = $param['data']['type'];
//            }
//        } else {
//            $type = self::$INVOICE_CREATE_TYPE_API;//小程序端,api
//        }
//
//        $list = $param['data']['list'];//出仓单货品列表
//
//        //送达时间->备注
//        if (!empty($param['data']['pDate'])) {
//            $mark = $param['data']['mark'] . "送达时间：" . $param['data']['pDate'];
//        } else {
//            $mark = $param['data']['mark'];
//        }
//
//        $warCode = $this->warCode;//仓库编号
//        $worCode = $this->worcode;//人员编号
//        $understockArr = array();//库存不足商品列表
//
//        //快速入仓
//        if (!empty($param['data']['isFast'])) {
//            $isFast = $param['data']['isFast'];
//        } else {
//            $isFast = 0;
//        }
//
//        if (empty($param['data']['receiver'])) {
//            $message = "客户名称不能为空";
//            venus_throw_exception(1, $message);
//            return false;
//        } else {
//            $receiver = $param['data']['receiver'];//客户名称
//        }
////        if (empty($param['data']['phone'])) {
////            $message = "客户手机号不能为空";
////            venus_throw_exception(1, $message);
////            return false;
////        } else {
////            if (preg_match("/^1[345678]{1}\d{9}$/", $param['data']['phone'])) {
////                $phone = $param['data']['phone'];//客户手机号
////            } else {
////                venus_throw_exception(4, "手机号格式不正确");
////                return false;
////            }
////        }
////        if (empty($param['data']['address'])) {
////            $message = "客户地址不能为空";
////            venus_throw_exception(1, $message);
////            return false;
////        } else {
////            $address = $param['data']['address'];//客户地址
////        }
////        if (empty($param['data']['postal'])) {
////            $message = "客户邮编不能为空";
////            venus_throw_exception(1, $message);
////            return false;
////        } else {
////            $postal = $param['data']['postal'];//客户邮编
////        }
//
//        $data = array();//返回数据声明
//        //声明所需要用的Model及服务
//        $traceService = TraceService::getInstance();
//        $invModel = InvoiceDao::getInstance($warCode);
//        $taskService = TaskService::getInstance();
//        $goodsModel = GoodsDao::getInstance($warCode);
//        $spuModel = SpuDao::getInstance($warCode);
//        $igoodsModel = IgoodsDao::getInstance($warCode);
//        $goodstoredModel = GoodstoredDao::getInstance($warCode);
//        $goodsbatchModel = GoodsbatchDao::getInstance($warCode);
//        $igoodsentModel = IgoodsentDao::getInstance($warCode);
//
//        venus_db_starttrans();//开启事务
//
//        $traceCode = $traceService->get_trace_code();//获取轨迹编号
//        if (!$traceCode) {
//            venus_db_rollback();
//            $message = '轨迹获取失败';
//            venus_throw_exception(2, $message);
//            return false;
//        }
//
//        //快速出仓出仓单状态为完成，正常出仓出仓单状态为创建
//        if ($isFast != 1) {
//            $invStatus = self::$INVOICE_STATUS_CREATE;
//        } else {
//            $invStatus = self::$INVOICE_STATUS_FINISH;
//        }
//
//        $invAddData = array(
//            "status" => $invStatus,//出仓单状态
//            "receiver" => $receiver,//客户名称
////            "phone" => $phone,//客户手机号
////            "address" => $address,//客户地址
////            "postal" => $postal,//客户邮编
//            "type" => $type,//出仓单类型
//            "mark" => $mark,//出仓单备注
//            "tracecode" => $traceCode,//轨迹编号
//            "worcode" => $worCode,//人员编号
//        );//出仓单新增数据
//        if (!empty($param['data']['ecode'])) {
//            $issetInv = $invModel->queryByEcode($param['data']['ecode']);
//            if (!empty($issetInv)) {
//                $invCode = $issetInv['inv_code'];
//            } else {
//                $invAddData['ecode'] = $param['data']['ecode'];
//                $invCode = $invModel->insert($invAddData);//出仓单创建操作
//                if (empty($invCode)) {
//                    venus_throw_exception(2, "创建出仓单失败");
//                    return false;
//                }
//            }
//        } else {
//            $invCode = $invModel->insert($invAddData);//出仓单创建操作
//            if (empty($invCode)) {
//                venus_throw_exception(2, "创建出仓单失败");
//                return false;
//            }
//        }
//
//        //快速出仓不需要创建工单
//        if ($isFast != 1) {
//            //创建拣货捡单工单
//            $taskType = self::$TASK_TYPE_INVPICKORDER;
//            $taskStatus = self::$TASK_STATUS_CREATE;//工单为创建状态
//            $taskData = array("code" => $invCode);
//            $task = $taskService->task_create($warCode, $taskData, $invCode, $taskType, $taskStatus, $invCode);//创建工单
//
//            if (!$task) {
//                venus_db_rollback();
//                $message = '工单操作失败';
//                venus_throw_exception(2, $message);
//                return false;
//            }
//            //记录轨迹
//            $traceMark = "创建出仓单";
//        } else {
//            //记录轨迹
//            $traceMark = "创建快速出仓单";
//        }
//
//        $trace = $traceService->update_trace_data($warCode, $traceCode, $invCode, $traceMark);//记录轨迹
//        if (!$trace) {
//            venus_db_rollback();
//            $message = '轨迹记录失败';
//            venus_throw_exception(2, $message);
//            return false;
//        }
//        $invSkuDataArr = array();
//        $invSkuData = array();
//        foreach ($list as $spData) {
//            if (empty($spData['spCode'])) {
//                $message = "出仓单spu编号不能为空";
//                venus_throw_exception(1, $message);
//                return false;
//            }
//            if (empty($spData['count'])) {
//                $message = "出仓单货品spu总数量不能为空";
//                venus_throw_exception(1, $message);
//                return false;
//            }
//            if (empty($spData['spCunit'])) {
//                venus_throw_exception(1, "spu最小计量单位不能为空");
//                return false;
//            }
//            if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', $spData['count'])) {
//                venus_throw_exception(4, "spu总数量格式不正确");
//                return false;
//            } else {
//                if (!empty($spData['spCunit']) && $spData['spCunit'] == 1) {
//                    if (floor($spData['count']) != $spData['count']) {
//                        venus_throw_exception(4, "spu总数量格式不正确");
//                        return false;
//                    }
//                }
//            }
//            if (empty($spData['skCode'])) {
//                $message = "出仓单sku编号不能为空";
//                venus_throw_exception(1, $message);
//                return false;
//            }
//            if (empty($spData['skCount'])) {
//                $message = "出仓单货品sku数量不能为空";
//                venus_throw_exception(1, $message);
//                return false;
//            }
//            if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', $spData['skCount'])) {
//                venus_throw_exception(4, "sku数量格式不正确");
//                return false;
//            } else {
//                if (!empty($spData['spCunit']) && $spData['spCunit'] == 1) {
//                    if (floor($spData['skCount']) != $spData['skCount']) {
//                        venus_throw_exception(4, "sku数量格式不正确");
//                        return false;
//                    }
//                }
//            }
//
//            if (!in_array($spData['skCode'], $invSkuDataArr)) {
//                $invSkuDataArr[] = $spData['skCode'];
//                $invSkuData[$spData['skCode']] = $spData;
//            } else {
//                $invSkuData[$spData['skCode']]['count'] += $spData['count'];
//                $invSkuData[$spData['skCode']]['skCount'] += $spData['skCount'];
//            }
//
//        }
//        foreach ($invSkuData as $skCode => $value) {
//            $goodsData = $goodsModel->queryBySkuCode($value['skCode']);//获取sku库存
//            $goodsCount = $goodsData['goods_count'];//spu库存
//            if (!empty($goodsData['spu_sprice'])) {
//                $sprice = $goodsData['spu_sprice'];//spu当前销售价
//            } else {
//                $sprice = 0;
//            }
//            if (!empty($goodsData['pro_price'])) {
//                $pprice = $goodsData['pro_price'];//spu利润价
//            } else {
//                $pprice = 0;
//            }
//            if (!empty($goodsData['pro_percent'])) {
//                $percent = $goodsData['pro_percent'];//spu利润率
//            } else {
//                $percent = 0;
//            }
//            //出仓单货品数量小于等于库存
//            if ($value['count'] <= $goodsCount) {
//                $igoodsAddData = array(
//                    "count" => $value['count'],//spu总数量
//                    "spucode" => $value['spCode'],//spu编号
//                    "sprice" => $sprice,//spu当前销售价
//                    "pprice" => $pprice,//spu当前利润
//                    "goodscode" => $goodsData['goods_code'],//库存编号
//                    "percent" => $percent,//spu当前利润率
//                    "skucode" => $value['skCode'],//sku编号
//                    "skucount" => $value['skCount'],//sku数量
//                    "invcode" => $invCode,//所属出仓单单号
//                );
//
//                $igoodsCode = $igoodsModel->insert($igoodsAddData);//创建发货清单
//
//                if (!$igoodsCode) {
//                    venus_db_rollback();
//                    venus_throw_exception(2, "创建发货清单失败");
//                    return false;
//                }
//                $newCountGoods = bcsub($goodsData['goods_count'], $value['count'], 2);//新库存
//                $newSkuCountGoods = bcsub($goodsData['sku_count'], $value['skCount'], 2);
//
//                $uptGoods = $goodsModel->updateCountByCode($goodsData['goods_code'], $goodsData['goods_count'], $newCountGoods, $newSkuCountGoods);//修改库存
//                if (!$uptGoods) {
//                    venus_db_rollback();
//                    venus_throw_exception(2, "修改库存失败");
//                    return false;
//                }
//
//                //创建状态产生批次库位及库存变化
//                $goodstoredList = $goodstoredModel->queryListBySkuCode($value['skCode']);//指定商品的库存货品批次货位列表数据
//                $igoodsentDataListOne = $this->branch_goodstored($goodstoredList, $value['count'], $igoodsCode, $value['spCode'], $invCode);//调用出仓批次方法
//
//                $igoodsentDataList = array();
//                if ($igoodsentDataListOne["sentNum"] < $value['count']) {
//                    $goodstoredSpuList = $goodstoredModel->queryListNotSkuBySpuCode($value['spCode'], $value['skCode'], 0, 100000);//指定商品的库存货品批次货位列表数据
//                    $igoodsentDataListTwo = $this->branch_goodstored($goodstoredSpuList, $value['count'] - $igoodsentDataListOne["sentNum"], $igoodsCode, $value['spCode'], $invCode);
//                    $igoodsentData = $igoodsentDataListOne + $igoodsentDataListTwo;
//                } else {
//                    $igoodsentData = $igoodsentDataListOne;
//                }
//                $sentCountGoodsData = array();
//                foreach ($igoodsentData as $igoodsentDatum) {
//                    if (is_array($igoodsentDatum)) {
//                        $goodsoredCount = $igoodsentDatum['remaining'];
//                        if ($goodsoredCount == 0) {
//                            $gbCode = $goodstoredModel->queryByCode($igoodsentDatum['gscode'])['gb_code'];
//                            $gbList = $goodstoredModel->queryListByGbCode($gbCode, 0, 1000);
//                            $gsSpuInit = 0;
//                            $gsSpuCount = 0;
//                            $gbCount = 0;
//                            foreach ($gbList as $gb) {
//                                $gsSpuInit += $gb['gs_init'];
//                                $gsSpuCount += $gb['gs_count'];
//                                $gbCount = $gb['gb_count'];
//                            }
//                            if ($gsSpuInit == $gbCount && $gsSpuCount == 0) {
//                                $uptGb = $goodsbatchModel->updateStatusByCode($gbCode, self::$GOODSBATCH_STATUS_FINISH);//此批次库存全发完，批次状态改为已用完
//                                if (!$uptGb) {
//                                    venus_db_rollback();
//                                    venus_throw_exception(2, "修改货品批次状态失败");
//                                    return false;
//                                }
//                            }
//                        }
//                        $uptGsSpuCount = $goodstoredModel->updateByCode($igoodsentDatum['gscode'], $goodsoredCount);//修改发货库存批次剩余数量
//                        $gsSkuCount = $goodstoredModel->queryByCode($igoodsentDatum['gscode'])['sku_count'];
//                        if ($gsSkuCount < $igoodsentDatum['skucount']) {
//                            $spName = $goodsData['spu_name'];
//                            if (!array_key_exists($spName, $understockArr)) {
//                                $understockArr[$spName] = bcsub($igoodsentDatum['skucount'], $gsSkuCount, 2);
//                            } else {
//                                $understockArr[$spName] += $igoodsentDatum['skucount'];
//                            }
//                        } else {
//                            $uptGsSkuCount = $goodstoredModel->updateSkuCountByCode($igoodsentDatum['gscode'], $gsSkuCount - $igoodsentDatum['skucount']);//减少发货库存批次sku数量
//                            $igoodsentCode = $igoodsentModel->insert($igoodsentDatum);//创建发货批次
//                            if (!$uptGsSpuCount || !$uptGsSkuCount) {
//                                venus_db_rollback();
//                                venus_throw_exception(2, "修改库存批次失败");
//                                return false;
//                            }
//                            if (!$igoodsentCode) {
//                                venus_db_rollback();
//                                venus_throw_exception(2, "创建发货批次失败");
//                                return false;
//                            }
//                        }
//                    }
//                }
//            } else {
//                //出仓单货品数量大于库存，计入统计库存不足数组
//                $spName = $goodsData['spu_name'];
//                if (!array_key_exists($spName, $understockArr)) {
//                    $understockArr[$spName] = bcsub($value['count'], $goodsCount, 2);
//                }
//            }
//        }
//        if (!empty($understockArr)) {
//            venus_db_rollback();
//            $message = "库存不足商品列表" . "<br/>";
//            foreach ($understockArr as $spuName => $spuCount) {
//                $message .= $spuName . ":" . $spuCount . "<br/>";
//            }
//            venus_throw_exception(2, $message);
//            return false;
//        } else {
//            venus_db_commit();
//            $success = true;
//            $message = '';
//            return array($success, $data, $message);
//        }
//
//    }

    /**
     * @return array
     * 出仓单管理
     */
    public function invoice_search($param)
    {
        $warCode = $this->warCode;
        if (!isset($param)) {
            $param = $_POST;
        }
        $data = array();
        $stime = $param['data']['stime'];//开始时间
        $etime = $param['data']['etime'];//结束时间
        $status = $param['data']['status'];//状态
        $type = $param['data']['type'];//状态
        $pageCurrent = $param['data']['pageCurrent'];//当前页数
        $invCode = $param['data']['code'];//出仓单单号
        $oCode = $param['data']['ecode'];//出仓单单号

        $clause = array();
        if (empty($pageCurrent)) {
            $pageCurrent = 0;//当前页数
        }
        if (!empty($stime)) {
            $clause['sctime'] = $stime;//开始时间
        }
        if (!empty($etime)) {
            $clause['ectime'] = $etime;//结束时间
        }
        if (!empty($status)) $clause['status'] = $status;//出仓单状态
        if (!empty($invCode)) $clause['code'] = $invCode;//出仓单单号
        if (!empty($type)) $clause['type'] = $type;//出仓单类型
        if (!empty($oCode)) $clause['ecode'] = $oCode;//出仓单类型

        $invModel = InvoiceDao::getInstance($warCode);//出仓单model

        $totalCount = $invModel->queryCountByCondition($clause);//符合条件的出仓单个数
        $pageLimit = pageLimit($totalCount, $pageCurrent);//获取分页信息
        $invDataList = $invModel->queryListByCondition($clause, $pageLimit['page'], $pageLimit['pSize']);//符合条件的出仓单列表

        $data = array(
            "pageCurrent" => $pageCurrent,//当前页数
            "pageSize" => $pageLimit['pageSize'],//每页条数
            "totalCount" => $totalCount,//总条数
        );
        foreach ($invDataList as $value) {
            $data['list'][] = array(
                "invCode" => $value['inv_code'],//所属出仓单单号
                "invCtime" => $value['inv_ctime'],//出仓单创建时间
                "invUcode" => $value['wor_code'],//下单人
                "invUname" => $value['wor_rname'],//下单人名称
                "invMark" => $value['inv_mark'],//备注信息
                "invType" => venus_invoice_type_desc($value['inv_type']),//出仓单类型
                "invStatus" => $value['inv_status'],//出仓单状态
                "invStatMsg" => venus_invoice_status_desc($value['inv_status']),//出仓单状态信息
                "invEcode" => $value["inv_ecode"],
            );
        }

        $success = true;
        $message = '';
        return array($success, $data, $message);
    }

    /**
     * @return array|bool
     *出仓单管理之修改（1）出仓单详情
     */
    public function invoice_detail($param)
    {
        $warCode = $this->warCode;//仓库编号
        if (!isset($param)) {
            $param = $_POST;
        }

        $igoodsModel = IgoodsDao::getInstance($warCode);//出仓单货品清单表model
        $data = array();
        $pageCurrent = $param['data']['pageCurrent'];//当前页数
        if (empty($pageCurrent)) $pageCurrent = 0;//当前页数
        if (empty($param['data']['invCode'])) {
            $message = '出仓单编号不能为空';
            venus_throw_exception(1, $message);
            return false;
        } else {
            $invCode = $param['data']['invCode'];//'出仓单编号
            $totalCount = $igoodsModel->queryCountByInvCode($invCode);//出仓单货品总个数
            $pageLimit = pageLimit($totalCount, $pageCurrent);//获取分页信息
            $igoodsDataList = $igoodsModel->queryListByInvCode($invCode, $pageLimit['page'], $pageLimit['pSize']);//出仓单货品列表

            $data = array(
                "pageCurrent" => $pageCurrent,//当前页数
                "pageSize" => $pageLimit['pageSize'],//每页条数
                "totalCount" => $totalCount,//总条数

            );

            foreach ($igoodsDataList as $value) {
                $data['list'][] = array(
                    "igoCode" => $value['igo_code'],
                    "skName" => $value['spu_name'],
                    "skCode" => $value['sku_code'],
                    "skNorm" => $value['sku_norm'],
                    "skCount" => $value['igo_count'] / $value['spu_count'],
                    "skUnit" => $value['sku_unit'],
                    "spCode" => $value['spu_code'],
                    "count" => $value['igo_count'],
                    "spUnit" => $value['spu_unit'],
                    "spCunit" => $value['spu_cunit'],
                    "spBrand" => $value['spu_brand'],
                    "spNorm" => $value['spu_norm'],
                    "spImg" => $value['spu_img'],
                );
            }

            $success = true;
            $message = '';
            return array($success, $data, $message);

        }
    }


    /**
     * @return array|bool
     * 出仓单管理之修改（2）修改出仓单货品数量
     */
    public function invoice_goods_count_update()
    {
        $warCode = $this->warCode;

        $data = array();

        if (empty($_POST['data']['invCode'])) {
            $message = "出仓单sku编号不能为空";
            venus_throw_exception(1, $message);
            return false;
        }
        if (empty($_POST['data']['igoCode'])) {
            $message = "出仓单货品编号不能为空";
            venus_throw_exception(1, $message);
            return false;
        }
        if (empty($_POST['data']['skCode'])) {
            $message = "出仓单sku编号不能为空";
            venus_throw_exception(1, $message);
            return false;
        }

        if (empty($_POST['data']['skCount'])) {
            $message = "出仓单sku数量不能为空";
            venus_throw_exception(1, $message);
            return false;
        }
        if (empty($_POST['data']['spCunit'])) {
            venus_throw_exception(1, "spu最小计量单位不能为空");
            return false;
        }
        if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', $_POST['data']['skCount'])) {
            venus_throw_exception(4, "sku数量格式不正确");
            return false;
        } else {
            if (!empty($_POST['data']['spCunit']) && $_POST['data']['spCunit'] == 1) {
                if (floor($_POST['data']['skCount']) != $_POST['data']['skCount']) {
                    venus_throw_exception(4, "sku数量格式不正确");
                    return false;
                }
            }
        }

        if (empty($_POST['data']['spCode'])) {
            $message = "出仓单spu编号不能为空";
            venus_throw_exception(1, $message);
            return false;
        }
        if (empty($_POST['data']['count'])) {
            $message = "出仓单货品spu总数量不能为空";
            venus_throw_exception(1, $message);
            return false;
        }
        if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', $_POST['data']['count'])) {
            venus_throw_exception(4, "spu总数量格式不正确");
            return false;
        } else {
            if (!empty($_POST['data']['spCunit']) && $_POST['data']['spCunit'] == 1) {
                if (floor($_POST['data']['count']) != $_POST['data']['count']) {
                    venus_throw_exception(4, "spu总数量格式不正确");
                    return false;
                }
            }
        }

        $invCode = $_POST['data']['invCode'];
        $igoCode = $_POST['data']['igoCode'];
        $spCode = $_POST['data']['spCode'];
        $count = $_POST['data']['count'];

        $invModel = InvoiceDao::getInstance($warCode);
        $goodsModel = GoodsDao::getInstance($warCode);
        $igoodsModel = IgoodsDao::getInstance($warCode);
        $spuModel = SpuDao::getInstance($warCode);

        $isUpt = $invModel->queryByCode($invCode)['inv_status'];

        //订单处于预报状态，可修改
        if ($isUpt == self::$INVOICE_STATUS_FORECAST) {
            $goodsData = $goodsModel->queryBySpuCode($spCode);
            $goodsCount = $goodsData['goods_count'];
            if ($count <= $goodsCount) {
                $uptIgoRes = $igoodsModel->updateByCode($igoCode, $count);
                if (!$uptIgoRes) {
                    $message = "修改发货清单失败";
                    venus_throw_exception(2, $message);
                    return false;
                } else {
                    $data = '';
                    $success = true;
                    $message = '';
                    return array($success, $data, $message);
                }
            } else {
                $spName = $spuModel->queryByCode($spCode)['spu_name'];
                $message = "出仓单货品" . $spName . "库存不足";
                venus_throw_exception(2, $message);
                return false;
            }
        } else {
            venus_throw_exception(2501, '');
            return false;
        }
    }


    /**
     * @return array|bool
     * 出仓单管理之修改（3）增加出仓单货品
     */
    public function invoice_goods_create()
    {
        $warCode = $this->warCode;
        $list = $_POST['data']['list'];
        $data = array();
        if (empty($_POST['data']['invCode'])) {
            $message = '出仓单编号不能为空';
            venus_throw_exception(1, $message);
            return false;
        } else {
            $invCode = $_POST['data']['invCode'];
        }


        $invModel = InvoiceDao::getInstance($warCode);
        $goodsModel = GoodsDao::getInstance($warCode);
        $spuModel = SpuDao::getInstance($warCode);
        $igoodsModel = IgoodsDao::getInstance($warCode);

        $isUpt = $invModel->queryByCode($invCode)['inv_status'];

        //订单处于预报状态，可修改
        if ($isUpt == self::$INVOICE_STATUS_FORECAST) {
            $spArr = array();
            foreach ($list as $value) {

                if (empty($value['skCode'])) {
                    $message = "出仓单sku编号不能为空";
                    venus_throw_exception(1, $message);
                    return false;
                }
                if (empty($value['skCount'])) {
                    $message = "出仓单sku数量不能为空";
                    venus_throw_exception(1, $message);
                    return false;
                }
                if (empty($value['spCode'])) {
                    $message = "出仓单spu编号不能为空";
                    venus_throw_exception(1, $message);
                    return false;
                }
                if (empty($value['count'])) {
                    $message = "出仓单货品spu总数量不能为空";
                    venus_throw_exception(1, $message);
                    return false;
                }
                if (empty($value['spCunit'])) {
                    venus_throw_exception(1, "spu最小计量单位不能为空");
                    return false;
                }
                if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', $value['skCount'])) {
                    venus_throw_exception(4, "sku数量格式不正确");
                    return false;
                } else {
                    if (!empty($value['spCunit']) && $value['spCunit'] == 1) {
                        if (floor($value['skCount']) != $value['skCount']) {
                            venus_throw_exception(4, "sku数量格式不正确");
                            return false;
                        }
                    }
                }
                if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', $value['count'])) {
                    venus_throw_exception(4, "spu总数量格式不正确");
                    return false;
                } else {
                    if (!empty($value['spCunit']) && $value['spCunit'] == 1) {
                        if (floor($value['count']) != $value['count']) {
                            venus_throw_exception(4, "spu总数量格式不正确");
                            return false;
                        }
                    }
                }
                $goodsData = $goodsModel->queryBySpuCode($value['spCode']);
                $spuData = $spuModel->queryByCode($value['spCode']);
                $goodsCount = $goodsData['goods_count'];
                if (empty($spuData['spu_sprice'])) {
                    $sprice = 0;
                } else {
                    $sprice = $spuData['spu_sprice'];
                }
                if (empty($spuData['pro_price'])) {
                    $pprice = 0;
                } else {
                    $pprice = $spuData['pro_price'];
                }

                if (empty($spuData['pro_percent'])) {
                    $percent = 0;
                } else {
                    $percent = $spuData['pro_percent'];
                }
                if ($value['count'] <= $goodsCount) {
                    $igoodsAddData = array(
                        "count" => $value['count'],
                        "spucode" => $value['spCode'],
                        "sprice" => $sprice,
                        "pprice" => $pprice,
                        "goodscode" => $goodsData['goods_code'],
                        "skucode" => $value['skCode'],//sku编号
                        "skucount" => $value['skCount'],//sku数量
                        "percent" => $percent,
                        "invcode" => $invCode,
                    );

                    $igoodsCode = $igoodsModel->insert($igoodsAddData);
                    if (!$igoodsCode) {
                        $message = "创建发货清单失败";
                        venus_throw_exception(2, $message);
                        return false;
                    }
                } else {
                    $spName = $spuModel->queryByCode($value['spCode'])['spu_name'];
                    $spArr[] = $spName;
                }
            }
            if (!empty($spArr)) {
                $spuList = join(",<br>", $spArr);
                $message = "库存不足商品列表" . "<br>" . $spuList;
                venus_throw_exception(2, $message);
                return false;
            } else {
                $success = true;
                $message = '';
                return array($success, $data, $message);
            }
        } else {
            venus_throw_exception(2502, '');
            return false;
        }
    }

    /**
     * @return array|bool
     * 出仓单管理之修改（4）删除出仓单货品
     */
    public function invoice_goods_delete()
    {
        $warCode = $this->warCode;
        $data = array();
        if (empty($_POST['data']['invCode'])) {
            $message = '出仓单编号不能为空';
            venus_throw_exception(1, $message);
            return false;
        }
        if (empty($_POST['data']['igoCode'])) {
            $message = '出仓单货品编号不能为空';
            venus_throw_exception(1, $message);
            return false;
        }
        $invCode = $_POST['data']['invCode'];
        $igoCode = $_POST['data']['igoCode'];

        $invModel = InvoiceDao::getInstance($warCode);
        $igoodsModel = IgoodsDao::getInstance($warCode);

        $isUpt = $invModel->queryByCode($invCode)['inv_status'];
        //订单处于预报状态，可修改
        if ($isUpt == self::$INVOICE_STATUS_FORECAST) {
            $igoodsCode = $igoodsModel->deleteByCode($igoCode, $invCode);

            if (!$igoodsCode) {
                venus_throw_exception(2, "删除发货清单失败");
                return false;
            } else {
                $success = true;
                $message = '';
                return array($success, $data, $message);
            }

        } else {
            venus_throw_exception(2501, '');
            return false;
        }
    }

    /**
     * @return array|bool
     * 出仓单管理/出仓单管理之查看轨迹﻿﻿﻿
     */
    public function invoice_trace_search()
    {
        $warCode = $this->warCode;
        if (empty($_POST['data']['invCode'])) {
            $message = '出仓单编号不能为空';
            venus_throw_exception(1, $message);
            return false;
        }
        $invCode = $_POST['data']['invCode'];
        $traceService = TraceService::getInstance();
        $traceDataList = $traceService->query_data_by_invcode($warCode, $invCode);
        if (!$traceDataList) {
            $message = "无轨迹信息";
            venus_throw_exception(3, $message);
            return false;
        } else {
            $success = true;
            $message = '';
            return array($success, $traceDataList, $message);
        }
    }

    /**
     * @return array|bool
     * 出仓单管理之删除
     */
    public function invoice_delete($param)
    {
        $warCode = $this->warCode;
        if (!isset($param)) {
            $param = $_POST;
        }
        $data = array();
        if (empty($param['data']['invCode'])) {
            $message = '出仓单编号不能为空';
            venus_throw_exception(1, $message);
            return false;
        } else {
            $invCode = $param['data']['invCode'];
        }
        $invModel = InvoiceDao::getInstance($warCode);

        $isUpt = $invModel->queryByCode($invCode)['inv_status'];
        //订单处于预报状态，可修改
        if ($isUpt == self::$INVOICE_STATUS_FORECAST) {
            $invStatus = self::$INVOICE_STATUS_CANCEL;
            $deleteInvData = $invModel->updateStatusByCode($invCode, $invStatus);
            if (!$deleteInvData) {
                $message = "删除出仓单";
                venus_throw_exception(2, $message);
                return false;
            } else {
                $success = true;
                $message = '';
                return array($success, $data, $message);
            }
        } else {
            venus_throw_exception(2501, '');
            return false;
        }

    }

    /**
     * @return array|bool
     * 出仓单管理之确认预报
     */
    public function invoice_confirm()
    {
        $warCode = $this->warCode;
        $data = array();
        $understockArr = array();

        $list = $_POST['data']['list'];

        $traceService = TraceService::getInstance();
        $invModel = InvoiceDao::getInstance($warCode);
        $goodsModel = GoodsDao::getInstance($warCode);
        $spuModel = SpuDao::getInstance($warCode);
        $igoodsModel = IgoodsDao::getInstance($warCode);
        $goodsbatchModel = GoodsbatchDao::getInstance($warCode);
        $goodstoredModel = GoodstoredDao::getInstance($warCode);
        $igoodsentModel = IgoodsentDao::getInstance($warCode);

        venus_db_starttrans();

        foreach ($list as $value) {

            if (empty($value)) {
                $message = '出仓单编号不能为空';
                venus_throw_exception(1, $message);
                return false;
            }

            $invData = $invModel->queryByCode($value);
            //记录轨迹
            $traceMark = "创建出仓单";
            $trace = $traceService->update_trace_data($warCode, $invData['trace_code'], $value, $traceMark);
            if (!$trace) {
                venus_db_rollback();
                $success = false;
                $message = '轨迹记录失败';
                return array($success, $data, $message);
            }

            $invStatus = $invData['inv_status'];
            if ($invStatus == self::$INVOICE_STATUS_FORECAST) {
                $igoodsDataList = $igoodsModel->queryListByInvCode($value, 0, 1000);

                foreach ($igoodsDataList as $igoodsDatum) {
                    $goodsData = $goodsModel->queryBySkuCode($igoodsDatum['sku_code']);
                    if (empty($igoodsDatum['goods_code']) && !empty($goodsData)) {
                        $uptIgoodsToGoodsCode = $igoodsModel->updateGoodsCodeByCode($igoodsDatum['igo_code'], $goodsData['goods_code']);
                        if (!$uptIgoodsToGoodsCode) {
                            venus_db_rollback();
                            venus_throw_exception(2, "修改发货清单数据失败");
                            return false;
                        }
                    }
                    $goodsCount = $goodsData['goods_count'];
                    $igoCount = $igoodsDatum['igo_count'];
                    if ($igoCount <= $goodsCount) {
                        $goodstoredListCount = $goodstoredModel->queryCountBySkuCode($value['skCode']);
                        //创建状态产生批次库位及库存变化
                        $goodstoredList = $goodstoredModel->queryListBySkuCode($value['skCode'],0,$goodstoredListCount);//指定商品的库存货品批次货位列表数据
                        $igoodsentDataListOne = $this->branch_goodstored($goodstoredList, $igoodsDatum['igo_count'], $igoodsDatum['igo_code'], $igoodsDatum['spu_code'], $value);//调用出仓批次方法
                        $igoodsentDataList = array();
                        if ($igoodsentDataListOne["sentNum"] < $igoodsDatum['igo_count']) {
                            $goodstoredSpuList = $goodstoredModel->queryListNotSkuBySpuCode($igoodsDatum['spu_code'], $igoodsDatum['sku_code'], 0, 100000);//指定商品的库存货品批次货位列表数据
                            $igoodsentDataListTwo = $this->branch_goodstored($goodstoredSpuList, $value['count'] - $igoodsentDataListOne["sentNum"], $igoodsDatum['igo_code'], $value['spCode'], $value);
                            $igoodsentData = $igoodsentDataListOne + $igoodsentDataListTwo;
                        } else {
                            $igoodsentData = $igoodsentDataListOne;
                        }
                        $sentCountGoodsData = array();
                        foreach ($igoodsentData as $igoodsentDatum) {
                            $goodsoredCount = $igoodsentDatum['remaining'];
                            if ($goodsoredCount == 0) {
                                $gbCode = $goodstoredModel->queryByCode($igoodsentDatum['gscode'])['gb_code'];
                                $uptGb = $goodsbatchModel->updateStatusByCode($gbCode, self::$GOODSBATCH_STATUS_FINISH);//此批次库存全发完，批次状态改为已用完
                                if (!$uptGb) {
                                    venus_db_rollback();
                                    venus_throw_exception(2, "修改货品批次状态失败");
                                    return false;
                                }
                            }
                            $uptGsSpuCount = $goodstoredModel->updateByCode($igoodsentDatum['gscode'], $goodsoredCount);//修改发货库存批次剩余数量
                            $gsSkuCount = $goodstoredModel->queryByCode($igoodsentDatum['gscode'])['sku_count'];
                            if ($gsSkuCount < $igoodsentDatum['skucount']) {
                                $spName = $spuModel->queryByCode($value['spCode'])['spu_name'];
                                if (!in_array($spName, $understockArr)) {
                                    $understockArr[] = $spName;
                                }
                            } else {
                                if ($gsSkuCount - $igoodsentDatum['skucount'] > 0) {
                                    $uptGsSkuCount = $goodstoredModel->updateSkuCountByCode($igoodsentDatum['gscode'], $gsSkuCount - $igoodsentDatum['skucount']);//减少发货库存批次sku数量
                                    $igoodsentCode = $igoodsentModel->insert($igoodsentDatum);//创建发货批次
                                    if (!$uptGsSpuCount || !$uptGsSkuCount) {
                                        $spName = $spuModel->queryByCode($value['spCode'])['spu_name'];
                                        venus_db_rollback();
                                        venus_throw_exception(2, "修改" . $spName . "库存批次失败");
                                        return false;
                                    }
                                    if (!$igoodsentCode) {
                                        venus_db_rollback();
                                        venus_throw_exception(2, "创建发货批次失败");
                                        return false;
                                    }
                                    $sentCountGoodsData[$igoodsentDatum['skucode']]['count'] += $igoodsentDatum['count'];
                                    $sentCountGoodsData[$igoodsentDatum['skucode']]['skucount'] += $igoodsentDatum['skucount'];
                                } else {
                                    venus_throw_exception(2, "请重新创建出仓单");
                                    return false;
                                }
                            }
                        }
                        //修改库存
                        foreach ($sentCountGoodsData as $skucode => $sentCountGoodsDatum) {
                            $goodsNewData = $goodsModel->queryBySkuCode($skucode);//获取sku库存
                            $newCountGoods = $goodsNewData['goods_count'] - $sentCountGoodsDatum['count'];//新库存
                            $newSkuCountGoods = $goodsNewData['sku_count'] - $sentCountGoodsDatum['skucount'];
                            $uptGoods = $goodsModel->updateCountByCode($goodsNewData['goods_code'], $goodsNewData['goods_count'], $newCountGoods, $newSkuCountGoods);//修改库存
                            if (!$uptGoods) {
                                venus_db_rollback();
                                venus_throw_exception(2, "修改库存失败");
                                return false;
                            }
                        }
                    } else {
                        //出仓单货品数量大于库存，计入统计库存不足数组
                        $spName = $goodsData['spu_name'];
                        if (!in_array($spName, $understockArr)) {
                            $understockArr[] = $spName;
                        }
                        continue;
                    }
                }

                $invStatusNew = self::$INVOICE_STATUS_CREATE;
                $uptInvStatus = $invModel->updateStatusAndCtimeByCode($value, $invStatusNew);
                if (!$uptInvStatus) {
                    venus_db_rollback();
                    venus_throw_exception(2, "修改出仓单状态");
                    return false;
                }
            } else {
                venus_db_rollback();
                venus_throw_exception(2503, '');
                return false;
            }
        }
        if (isset($understockArr) && !empty($understockArr)) {
            venus_db_rollback();
            $spuName = join(",", $understockArr);
            $message = "库存不足:" . $spuName;
            venus_throw_exception(2, $message);
            return false;
        } else {
            venus_db_commit();
            $data = '';
            $success = true;
            $message = '';
            return array($success, $data, $message);
        }
    }

    /**
     * @return array|bool
     * 采购出仓单导入
     */
    public function invoice_import()
    {
        $warCode = $this->warCode;//仓库编号
        $ocodeArr = array();//导入的采购单编号
        //声明所需要用的Model及服务
        $excelService = ExcelService::getInstance();
        $traceService = TraceService::getInstance();
        $invModel = InvoiceDao::getInstance($warCode);
        $goodsModel = GoodsDao::getInstance($warCode);
        $spuModel = SpuDao::getInstance($warCode);
        $igoodsModel = IgoodsDao::getInstance($warCode);
        $skuModel = SkuDao::getInstance($warCode);

        $fileContent = $excelService->upload("file");//导入文件

        $data = array();
        $dicts = array(
            "B" => "skCode",//sku品类编号
            "I" => "skCount",//sku品类数量
            "J" => "ocode",//订单编号
            "K" => "mark",//订单备注
            "L" => "worCode",//收货人编号
            "M" => "receiver",//收货人
            "N" => "phone",//电话
        );//创建字典
        $iGoodsList = array();//发出货品列表
        $projectList = array();//项目数组
        foreach ($fileContent as $sheetName => $list) {
            $sheet = explode("|", $sheetName);
            $project = $sheet[0];//项目组
            unset($list[0]);
            $iGoodsList = array_merge($iGoodsList, $list);//将list重新排序到新数组里面
            foreach ($iGoodsList as $item) {
                $skuData = array();
                foreach ($dicts as $col => $key) {
                    $skuData[$key] = isset($item[$col]) ? $item[$col] : "";//匹配字典，获取需要的出仓单数据
                }

                $skuInfo = $skuModel->queryByCode($skuData['skCode']);//查询sku相关信息
                $skuData['spCode'] = $skuInfo['spu_code'];
                $skuData['count'] = $skuInfo['spu_count'] * $skuData['skCount'];
                $skuData['spCunit'] = $skuInfo['spCunit'];
                if (empty($projectList[$project][$skuData['ocode']])) {
                    if (preg_match("/^1[345678]{1}\d{9}$/", trim($skuData['phone']))) {
                        $phone = " " . $skuData['phone'];
                    } else {
                        venus_throw_exception(4, "手机号格式不正确");
                        return false;
                    }
                    $projectList[$project][$skuData['ocode']]['worCode'] = $skuData['worCode'];//人员编号
                    $projectList[$project][$skuData['ocode']]['receiver'] = $skuData['receiver'];//客户名称
                    $projectList[$project][$skuData['ocode']]['phone'] = $phone;//客户手机号
                    $projectList[$project][$skuData['ocode']]['mark'] = $skuData['mark'];//采购单备注
                }
                unset($skuData['receiver']);
                unset($skuData['phone']);
                unset($skuData['mark']);
                $projectList[$project][$skuData['ocode']]['list'][] = $skuData;//将sku相关信息放入项目列表中
                unset($skuData);
            }
        }

        $warAddress = $this->warAddress;//仓库地址
        $warPostal = $this->warPostal;//仓库邮编
        foreach ($projectList as $key => $pro) {
            foreach ($pro as $ocode => $info) {
                $worCode = $info['worCode'];
                $mark = $info['mark'];
                $traceCode = $traceService->get_trace_code();//获取轨迹编号
                if (!$traceCode) {
                    venus_db_rollback();
                    $message = '轨迹获取失败';
                    venus_throw_exception(1, $message);
                    return false;
                }
                $issetEcode = $invModel->queryByEcode($ocode);//检测采购单是否已创建
                if ($issetEcode) {
                    $ocodeArr[] = $ocode;//已创建的采购单放入数组中
                    continue;
                } else {
                    $invStatus = self::$INVOICE_STATUS_FORECAST;//出仓单状态为已预报
                    $invAddData = array(
                        "status" => $invStatus,//出仓单状态
                        "receiver" => $info['receiver'],//客户名称
                        "phone" => $info['phone'],//客户手机号
                        "address" => $warAddress,//客户地址
                        "postal" => $warPostal,//客户邮编
                        "ecode" => $ocode,//采购单单号
                        "type" => self::$INVOICE_CREATE_TYPE_FILE,//出仓单类型
                        "mark" => $mark,//出仓单备注
                        "tracecode" => $traceCode,//轨迹编号
                        "worcode" => $worCode,//人员编号
                    );
                    $invCode = $invModel->insert($invAddData);//出仓单创建操作

                    //记录轨迹
                    $traceMark = "出仓单预报";
                    $trace = $traceService->update_trace_data($warCode, $traceCode, $invCode, $traceMark);//记录轨迹
                    if (!$trace) {
                        venus_db_rollback();
                        $success = false;
                        $message = '轨迹记录失败';
                        return array($success, $data, $message);
                    }
                    $goodsInfo = $info['list'];
                    foreach ($goodsInfo as $value) {
                        if (empty($value['skCode'])) {
                            $message = "出仓单sku编号不能为空";
                            venus_throw_exception(1, $message);
                            return false;
                        }
                        if (empty($value['skCount'])) {
                            $message = "出仓单sku数量不能为空";
                            venus_throw_exception(1, $message);
                            return false;
                        }
                        if (empty($value['spCode'])) {
                            $message = "出仓单spu编号不能为空";
                            venus_throw_exception(1, $message);
                            return false;
                        }
                        if (empty($value['count'])) {
                            $message = "出仓单货品spu总数量不能为空";
                            venus_throw_exception(1, $message);
                            return false;
                        }
                        if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', $value['skCount'])) {
                            venus_throw_exception(4, "sku数量格式不正确");
                            return false;
                        } else {
                            if (!empty($value['spCunit']) && $value['spCunit'] == 1) {
                                if (floor($value['skCount']) != $value['skCount']) {
                                    venus_throw_exception(4, "spu总数量格式不正确");
                                    return false;
                                }
                            }
                        }
                        if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', $value['count'])) {
                            venus_throw_exception(4, "spu总数量格式不正确");
                            return false;
                        } else {
                            if (!empty($value['spCunit']) && $value['spCunit'] == 1) {
                                if (floor($value['count']) != $value['count']) {
                                    venus_throw_exception(4, "spu总数量格式不正确");
                                    return false;
                                }
                            }
                        }
                        $goodsData = $goodsModel->queryBySpuCode($value['spCode']);//获取spu库存
                        $spuData = $spuModel->queryByCode($value['spCode']);//获取spu商品相关信息
                        if (!empty($spuData['spu_sprice'])) {
                            $sprice = $spuData['spu_sprice'];//spu当前销售价
                        } else {
                            $sprice = 0;
                        }
                        if (!empty($spuData['pro_price'])) {
                            $pprice = $spuData['pro_price'];//spu利润价
                        } else {
                            $pprice = 0;
                        }
                        if (!empty($spuData['pro_percent'])) {
                            $percent = $spuData['pro_percent'];//spu利润率
                        } else {
                            $percent = 0;
                        }

                        $igoodsAddData = array(
                            "count" => $value['count'],//spu总数量
                            "spucode" => $value['spCode'],//spu编号
                            "sprice" => $sprice,//spu当前销售价
                            "pprice" => $pprice,//spu当前利润
                            "goodscode" => $goodsData['goods_code'],//库存编号
                            "skucode" => $value['skCode'],//sku编号
                            "skucount" => $value['skCount'],//sku数量
                            "percent" => $percent,//spu当前利润率
                            "invcode" => $invCode,//所属出仓单单号
                        );

                        $igoodsCode = $igoodsModel->insert($igoodsAddData);//创建发货清单
                        if (!$igoodsCode) {
                            venus_db_rollback();
                            venus_throw_exception(2, "创建发货清单失败");
                            return false;
                        }

                    }
                }
            }
        }
        //已创建出仓单的采购单
        if (!empty($ocodeArr)) {
            venus_db_rollback();
            $message = "<br>" . join(",<br>", $ocodeArr);
            venus_throw_exception(2505, $message);
            return false;
        } else {
            venus_db_commit();
            $success = true;
            $message = '';
            return array($success, $data, $message);
        }

    }

    /**
     * @return array
     * 下载出仓单
     */
    public function inv_export()
    {
        $code = $_POST['data']['code'];
        $warCode = $this->warCode;//仓库编号
        $invModel = InvoiceDao::getInstance($warCode);
        $igoModel = IgoodsDao::getInstance($warCode);
        $invInfo = $invModel->queryByCode($code);
        $data = array();
        $letterCount = 0;
//        if (in_array($invInfo['inv_status'], array(self::$INVOICE_STATUS_INSPECTION, self::$INVOICE_STATUS_FINISH,$INVOICE_STATUS_RECEIPT))) {
        $warInfoRec = WorkerDao::getInstance($warCode)->queryByCode($this->worcode);
        $data['info'] = array(
            "code" => $invInfo['inv_code'],
            "ctime" => $invInfo['inv_ctime'],
            "warName" => $warInfoRec['war_name'],
            "worName" => $warInfoRec['wor_rname'],
        );
        $totalCount = $igoModel->queryCountByInvCode($code);//出仓单货品总个数
        $igoodsData = $igoModel->queryListByInvCode($code, 0, $totalCount);//出仓单货品列表

        foreach ($igoodsData as $igoodsDatum) {
            $skuList = array(
                "name" => $igoodsDatum["spu_name"],
                "norm" => $igoodsDatum["sku_norm"],
                "count" => floatval($igoodsDatum['sku_count']),
                "unit" => $igoodsDatum["sku_unit"],
                "supName" => $igoodsDatum["sup_name"],
            );
            $letterCount = count($skuList);
            $data['list'][] = $skuList;
        }
//        }
        $letters = array();
        for ($letter = 0; $letter < $letterCount; $letter++) {
            $letters[] = chr(65 + $letter);
        }

        $excelData["出仓单详情"] = array(
            "B1" => $data['info']['code'],
            "B2" => $data['info']['worName'],
            "B3" => $data['info']['ctime'],
        );
        $line = array();
        foreach ($data['list'] as $datum) {
            $line[] = array_values($datum);
        }
        $countLineNum = count($line) + 5;
        for ($lineNum = 5; $lineNum < $countLineNum; $lineNum++) {
            for ($rows = 0; $rows < count($letters); $rows++) {
                $num = $letters[$rows] . $lineNum;
                $excelData["出仓单详情"][$num] = $line[$lineNum - 5][$rows];
            }
        }
        $fileName = ExcelService::getInstance()->exportExcelByTemplate($excelData, "005");
        if ($fileName) {
            $success = true;
            $data = $fileName;
            $message = "";
        } else {
            $success = false;
            $data = "";
            $message = "下载失败";
        }
        return array($success, $data, $message);
    }

    //分担准用api
    public function invoice_create_api($param)
    {

        $type = self::$INVOICE_CREATE_TYPE_API;//小程序端,api
        $list = $param['data']['list'];//出仓单货品列表

        //送达时间->备注
        if (!empty($param['data']['pDate'])) {
            $mark = $param['data']['mark'] . "送达时间：" . $param['data']['pDate'];
        } else {
            $mark = $param['data']['mark'];
        }

        $warCode = $this->warCode;//仓库编号
        $worCode = $this->worcode;//人员编号
        $understockArr = array();//库存不足商品列表

        //快速入仓
        if (!empty($param['data']['isFast'])) {
            $isFast = $param['data']['isFast'];
        } else {
            $isFast = 0;
        }

        if (empty($param['data']['receiver'])) {
            $message = "客户名称不能为空";
            venus_throw_exception(1, $message);
            return false;
        } else {
            $receiver = $param['data']['receiver'];//客户名称
        }
        if (empty($param['data']['phone'])) {
            $message = "客户手机号不能为空";
            venus_throw_exception(1, $message);
            return false;
        } else {
            if (preg_match("/^1[345678]{1}\d{9}$/", $param['data']['phone'])) {
                $phone = $param['data']['phone'];//客户手机号
            } else {
                venus_throw_exception(4, "手机号格式不正确");
                return false;
            }
        }
        if (empty($param['data']['address'])) {
            $message = "客户地址不能为空";
            venus_throw_exception(1, $message);
            return false;
        } else {
            $address = $param['data']['address'];//客户地址
        }
        if (empty($param['data']['postal'])) {
            $message = "客户邮编不能为空";
            venus_throw_exception(1, $message);
            return false;
        } else {
            $postal = $param['data']['postal'];//客户邮编
        }

        $data = array();//返回数据声明
        //声明所需要用的Model及服务
        $traceService = TraceService::getInstance();
        $invModel = InvoiceDao::getInstance($warCode);
        $taskService = TaskService::getInstance();
        $goodsModel = GoodsDao::getInstance($warCode);
        $spuModel = SpuDao::getInstance($warCode);
        $igoodsModel = IgoodsDao::getInstance($warCode);
        $goodstoredModel = GoodstoredDao::getInstance($warCode);
        $goodsbatchModel = GoodsbatchDao::getInstance($warCode);
        $igoodsentModel = IgoodsentDao::getInstance($warCode);

        $traceCode = $traceService->get_trace_code();//获取轨迹编号
        if (!$traceCode) {
            $message = '轨迹获取失败';
            $success = false;
            return array($success, $data, $message);
        }

        //快速出仓出仓单状态为完成，正常出仓出仓单状态为创建
        if ($isFast != 1) {
            $invStatus = self::$INVOICE_STATUS_CREATE;
        } else {
            $invStatus = self::$INVOICE_STATUS_FINISH;
        }

        $invAddData = array(
            "status" => $invStatus,//出仓单状态
            "receiver" => $receiver,//客户名称
            "phone" => $phone,//客户手机号
            "address" => $address,//客户地址
            "postal" => $postal,//客户邮编
            "type" => $type,//出仓单类型
            "mark" => $mark,//出仓单备注
            "tracecode" => $traceCode,//轨迹编号
            "worcode" => $worCode,//人员编号
        );//出仓单新增数据
        if (!empty($param['data']['ecode'])) {
            $issetInv = $invModel->queryByEcode($param['data']['ecode']);
            if (!empty($issetInv)) {
                $invCode = $issetInv['inv_code'];
            } else {
                $invAddData['ecode'] = $param['data']['ecode'];
                $invCode = $invModel->insert($invAddData);//出仓单创建操作
                if (empty($invCode)) {
                    $success = false;
                    $message = "创建出仓单失败";
                    return array($success, $data, $message);
                }
            }
        } else {
            $invCode = $invModel->insert($invAddData);//出仓单创建操作
            if (empty($invCode)) {
                $success = false;
                $message = "创建出仓单失败";
                return array($success, $data, $message);
            }
        }

        //快速出仓不需要创建工单
        if ($isFast != 1) {
            //创建拣货捡单工单
            $taskType = self::$TASK_TYPE_INVPICKORDER;
            $taskStatus = self::$TASK_STATUS_CREATE;//工单为创建状态
            $taskData = array("code" => $invCode);
            $task = $taskService->task_create($warCode, $taskData, $invCode, $taskType, $taskStatus, $invCode);//创建工单

            if (!$task) {
                $message = '工单操作失败';
                $success = false;
                return array($success, $data, $message);
            }
            //记录轨迹
            $traceMark = "创建出仓单";
        } else {
            //记录轨迹
            $traceMark = "创建快速出仓单";
        }

        $trace = $traceService->update_trace_data($warCode, $traceCode, $invCode, $traceMark);//记录轨迹
        if (!$trace) {
            $message = '轨迹记录失败';
            $success = false;
            return array($success, $data, $message);
        }
        $invSkuDataArr = array();
        $invSkuData = array();
        foreach ($list as $spData) {
            if (empty($spData['spCode'])) {
                $message = "出仓单spu编号不能为空";
                $success = false;
                return array($success, $data, $message);
            }
            if (empty($spData['count'])) {
                $message = "出仓单货品spu总数量不能为空";
                $success = false;
                return array($success, $data, $message);
            }
            if (empty($spData['spCunit'])) {
                $success = false;
                $message = "spu最小计量单位不能为空";
                return array($success, $data, $message);
            }
            if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', $spData['count'])) {
                $success = false;
                $message = "spu总数量格式不正确";
                return array($success, $data, $message);
            } else {
                if (!empty($spData['spCunit']) && $spData['spCunit'] == 1) {
                    if (floor($spData['count']) != $spData['count']) {
                        $success = false;
                        $message = "spu总数量格式不正确";
                        return array($success, $data, $message);
                    }
                }
            }
            if (empty($spData['skCode'])) {
                $message = "出仓单sku编号不能为空";
                $success = false;
                return array($success, $data, $message);
            }
            if (empty($spData['skCount'])) {
                $message = "出仓单货品sku数量不能为空";
                $success = false;
                return array($success, $data, $message);
            }
            if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', $spData['skCount'])) {
                $success = false;
                $message = "sku数量格式不正确";
                return array($success, $data, $message);
            } else {
                if (!empty($spData['spCunit']) && $spData['spCunit'] == 1) {
                    if (floor($spData['skCount']) != $spData['skCount']) {
                        $success = false;
                        $message = "sku数量格式不正确";
                        return array($success, $data, $message);
                    }
                }
            }

            if (!in_array($spData['skCode'], $invSkuDataArr)) {
                $invSkuDataArr[] = $spData['skCode'];
                $invSkuData[$spData['skCode']] = $spData;
            } else {
                $invSkuData[$spData['skCode']]['count'] += $spData['count'];
                $invSkuData[$spData['skCode']]['skCount'] += $spData['skCount'];
            }

        }
        foreach ($invSkuData as $skCode => $value) {
            $goodsData = $goodsModel->queryBySkuCode($value['skCode']);//获取sku库存
            $goodsCount = $goodsData['goods_count'];//spu库存
            $goodsSkCount = $goodsData['sku_count'];//spu库存
            if (!empty($goodsData['spu_sprice'])) {
                $sprice = $goodsData['spu_sprice'];//spu当前销售价
            } else {
                $sprice = 0;
            }
            if (!empty($goodsData['pro_price'])) {
                $pprice = $goodsData['pro_price'];//spu利润价
            } else {
                $pprice = 0;
            }
            if (!empty($goodsData['pro_percent'])) {
                $percent = $goodsData['pro_percent'];//spu利润率
            } else {
                $percent = 0;
            }
            //出仓单货品数量小于等于库存
            if ($value['count'] <= $goodsCount) {
                $spuCount = $value['count'];
                $skuCount = $value['skCount'];
            } else {
                $spuCount = $goodsCount;
                $skuCount = $goodsSkCount;
                //出仓单货品数量大于库存，计入统计库存不足数组
                $spName = $goodsData['spu_name'];
                if (!array_key_exists($spName, $understockArr)) {
                    $understockArr[$spName] = bcsub($value['count'], $goodsCount, 2);
                }
            }
            $igoodsAddData = array(
                "count" => $spuCount,//spu总数量
                "spucode" => $value['spCode'],//spu编号
                "sprice" => $sprice,//spu当前销售价
                "pprice" => $pprice,//spu当前利润
                "goodscode" => $goodsData['goods_code'],//库存编号
                "percent" => $percent,//spu当前利润率
                "skucode" => $value['skCode'],//sku编号
                "skucount" => $skuCount,//sku数量
                "invcode" => $invCode,//所属出仓单单号
            );

            $igoodsCode = $igoodsModel->insert($igoodsAddData);//创建发货清单

            if (!$igoodsCode) {
                $success = false;
                $message = "创建发货清单失败";
                return array($success, $data, $message);
            }
            $newCountGoods = bcsub($goodsData['goods_count'], $spuCount, 2);//新库存
            $newSkuCountGoods = bcsub($goodsData['sku_count'], $skuCount, 2);
            $uptGoods = $goodsModel->updateCountByCode($goodsData['goods_code'], $goodsData['goods_count'], $newCountGoods, $newSkuCountGoods);//修改库存
            if (!$uptGoods) {
                $success = false;
                $message = "修改库存失败";
                return array($success, $data, $message);
            }
            $goodstoredListCount = $goodstoredModel->queryCountBySkuCode($value['skCode']);
            //创建状态产生批次库位及库存变化
            $goodstoredList = $goodstoredModel->queryListBySkuCode($value['skCode'],0,$goodstoredListCount);//指定商品的库存货品批次货位列表数据
            $igoodsentDataListOne = $this->branch_goodstored($goodstoredList, $spuCount, $igoodsCode, $value['spCode'], $invCode);//调用出仓批次方法

            $igoodsentDataList = array();
            if ($igoodsentDataListOne["sentNum"] < $spuCount) {
                $goodstoredSpuList = $goodstoredModel->queryListNotSkuBySpuCode($value['spCode'], $value['skCode'], 0, 100000);//指定商品的库存货品批次货位列表数据
                $igoodsentDataListTwo = $this->branch_goodstored($goodstoredSpuList, bcsub($spuCount, $igoodsentDataListOne["sentNum"], 2), $igoodsCode, $value['spCode'], $invCode);
                $igoodsentData = $igoodsentDataListOne + $igoodsentDataListTwo;
            } else {
                $igoodsentData = $igoodsentDataListOne;
            }
            foreach ($igoodsentData as $igoodsentDatum) {
                if (is_array($igoodsentDatum)) {
                    $goodsoredCount = $igoodsentDatum['remaining'];
                    if ($goodsoredCount == 0) {
                        $gbCode = $goodstoredModel->queryByCode($igoodsentDatum['gscode'])['gb_code'];
                        $gbList = $goodstoredModel->queryListByGbCode($gbCode, 0, 1000);
                        $gsSpuInit = 0;
                        $gsSpuCount = 0;
                        $gbCount = 0;
                        foreach ($gbList as $gb) {
                            $gsSpuInit += $gb['gs_init'];
                            $gsSpuCount += $gb['gs_count'];
                            $gbCount = $gb['gb_count'];
                        }
                        if ($gsSpuInit == $gbCount && $gsSpuCount == 0) {
                            $uptGb = $goodsbatchModel->updateStatusByCode($gbCode, self::$GOODSBATCH_STATUS_FINISH);//此批次库存全发完，批次状态改为已用完
                            if (!$uptGb) {
                                $success = false;
                                $message = "修改货品批次状态失败";
                                return array($success, $data, $message);
                            }
                        }
                    }
                    $uptGsSpuCount = $goodstoredModel->updateByCode($igoodsentDatum['gscode'], $goodsoredCount);//修改发货库存批次剩余数量
                    $gsSkuCount = $goodstoredModel->queryByCode($igoodsentDatum['gscode'])['sku_count'];
                    if ($gsSkuCount < $igoodsentDatum['skucount']) {
                        $spName = $goodsData['spu_name'];
                        if (!array_key_exists($spName, $understockArr)) {
                            $understockArr[$spName] = bcsub($igoodsentDatum['skucount'], $gsSkuCount, 2);
                        } else {
                            $understockArr[$spName] += $igoodsentDatum['skucount'];
                        }
                    } else {
                        $uptGsSkuCount = $goodstoredModel->updateSkuCountByCode($igoodsentDatum['gscode'], $gsSkuCount - $igoodsentDatum['skucount']);//减少发货库存批次sku数量
                        $igoodsentCode = $igoodsentModel->insert($igoodsentDatum);//创建发货批次
                        if (!$uptGsSpuCount || !$uptGsSkuCount) {
                            $success = false;
                            $message = "修改库存批次失败";
                            return array($success, $data, $message);
                        }
                        if (!$igoodsentCode) {
                            $success = false;
                            $message = "创建发货批次失败";
                            return array($success, $data, $message);
                        }
                    }
                }
            }
        }
        if (!empty($understockArr)) {
            $message = "库存不足商品列表" . "<br/>";
            foreach ($understockArr as $spuName => $spuCount) {
                $message .= $spuName . ":" . $spuCount . "<br/>";
            }
            $success = true;
            return array($success, $data, $message);
        } else {
            $success = true;
            $message = '';
            return array($success, $data, $message);
        }

    }

    /**
     * @param $goodstored array 库存批次货位数据
     * @param $igoCount string 需要发出的货品数量
     * @param $igoCode string 需要发出的igoods编号
     * @param $spuCode string 需要发出的spu编号
     * @param $invcode string 出仓单编号
     * @return mixed
     */
    public function branch_goodstored($goodstored, $igoCount, $igoCode, $spuCode, $invcode)
    {
        $sentNum = 0;
        $igoodsentAddData = array();
        foreach ($goodstored as $item) {
            $skuCode = $item['sku_code'];
            if ($item['gs_count'] > 0) {
                if ($igoCount - $sentNum - $item['gs_count'] >= 0) {
                    $sentNum += $item['gs_count'];
                    $igoodsentAddData[] = array(
                        "count" => $item['gs_count'],
                        "bprice" => $item['gb_bprice'],
                        "spucode" => $spuCode,
                        "gscode" => $item['gs_code'],
                        "igocode" => $igoCode,
                        "skucode" => $skuCode,
                        "skucount" => bcdiv($item['gs_count'], $item['spu_count'], 4),
                        "invcode" => $invcode,
                        "remaining" => 0
                    );
                } else {
                    if ($igoCount - $sentNum != 0) {
                        $gscount = $item['gs_count'] - ($igoCount - $sentNum);
                        $igoodsentCount = $igoCount - $sentNum;
                        $sentNum += $igoodsentCount;
                        $igoodsentAddData[] = array(
                            "count" => $igoodsentCount,
                            "bprice" => $item['gb_bprice'],
                            "spucode" => $spuCode,
                            "gscode" => $item['gs_code'],
                            "igocode" => $igoCode,
                            "skucode" => $skuCode,
                            "skucount" => bcdiv($igoodsentCount, $item['spu_count'], 4),
                            "invcode" => $invcode,
                            "remaining" => $gscount
                        );
                        break;
                    }

                }
            } else {
                continue;
            }
        }
        $igoodsentAddData["sentNum"] += $sentNum;
        return $igoodsentAddData;
    }


    public function invoice_quick_create($param)
    {
        $warcode = $this->warCode;//仓库编号
        $worcode = $this->worcode;//人员编号

        $data = $param["data"];
        $receiver = $data["uname"];
        $phone = $data["phone"];
        $address = $data["address"];
        $postal = $data["postal"];
        $mark = $data["mark"];
        $ecode = $data["ecode"];
        $goodsList = $data["list"];
        $type = $data["type"];//出仓单类型

        //$taskService = TaskService::getInstance();
        //$traceService = TraceService::getInstance();
        $invoiceModel = InvoiceDao::getInstance($warcode);
        $goodsbatchModel = GoodsbatchDao::getInstance($warcode);
        $goodstoredModel = GoodstoredDao::getInstance($warcode);
        $goodsModel = GoodsDao::getInstance($warcode);
        $igoodsModel = IgoodsDao::getInstance($warcode);
        $igoodsentModel = IgoodsentDao::getInstance($warcode);
        $skuModel = SkuDao::getInstance($warcode);
        //出仓货品清单goodsCode对应的spu成本价格
        $exGoodsCode2SpuBPrice = array();
        $messages = array();
        $isSuccess = true;
        //获取出仓单号inv_code
        $invoiceList = $invoiceModel->queryByEcode($ecode);
        if (empty($invoiceList)) {
            $invoiceCode = $invoiceModel->insert(array(
                "ecode" => $ecode, "type" => $type, "mark" => $mark, "status" => "5",/*已经出仓*/
                "receiver" => $receiver, "phone" => $phone, "address" => $address, "postal" => $postal,
                "tracecode" => "", "worcode" => $worcode,
            ));
        } elseif (count($invoiceList) == 1) {
            $invoiceCode = $invoiceList[0]["inv_code"];
        } else {
            return array(false, "系统检测出现重复ecode，请联系IT组解决");
        }
        $isSuccess = $isSuccess && !empty($invoiceCode);


        //库存字典
        $goodsStockDict = array();
        //验证库存，重新更新可出库sku数量到goodsList中，同时记录缺货sku数量及必要的商品信息
        foreach ($goodsList as $index => $goodsItem) {
            $invoiceSkuCount = $goodsItem["skCount"];
            $skuCode = $goodsItem["skCode"];
            $goodsData = $goodsModel->queryBySkuCode($skuCode);
            if (!empty($goodsData)) {
                $goodsSkuCount = floatval($goodsData["sku_count"]);
                $goodsStockDict[$skuCode] = $goodsData;
            } else {
                $goodsSkuCount = 0;
                $goodsData = $skuModel->queryByCode($skuCode);
            }
            if ($invoiceSkuCount <= $goodsSkuCount) {
                $goodsItem["skCount"] = $invoiceSkuCount;
            } else {
                $goodsItem["skCount"] = $goodsSkuCount;
                $goodsItem["lackSkCount"] = floatval(bcsub($invoiceSkuCount, $goodsSkuCount, 2));//缺失数量需写入日志
            }
            $goodsList[$index] = array_merge($goodsItem, array(
                "spu_name" => $goodsData["spu_name"],
                "sku_code" => $goodsData["sku_code"],
                "spu_code" => $goodsData["spu_code"],
                "spu_count" => $goodsData["spu_count"],
                "spu_sprice" => $goodsData["spu_sprice"],
                "spu_bprice" => $goodsData["spu_bprice"],
                "spu_pprice" => $goodsData["profit_price"],
                "sku_unit" => $goodsData["sku_unit"],
                "spu_percent" => 0,
            ));//为每一条货品丰富产品相关信息
        }


        //做出库单，创建相应的出库清单及出库批次单，并减少库存
        foreach ($goodsList as $index => $goodsItem) {
            $skCount = $goodsItem["skCount"];
            if ($skCount == 0) continue;//无库存直接跳过
            $spuCode = $goodsItem["spu_code"];
            $skuCode = $goodsItem["sku_code"];
            $spuBprice = $goodsItem["spu_bprice"];
            $spuCount = $goodsItem["spu_count"];//倍数
            $goodsData = $goodsStockDict[$skuCode];//获取库存数据
            $goodsCode = $goodsData["goods_code"];
            $goodsCount = $goodsData["goods_count"];
            $updatedSkuCount = bcsub($goodsData["sku_count"], $skCount, 2);//更新后的SKU
            $updatedGoodsCount = bcmul($spuCount, $updatedSkuCount, 2);

            //更新库存
            $isSuccess = $isSuccess &&
                $goodsModel->updateCountByCode($goodsCode, $goodsCount, $updatedGoodsCount, $updatedSkuCount);

            //创建出仓单
            $igoCode = $igoodsModel->insert(array(
                "goodscode" => $goodsCode,
                "count" => bcmul($spuCount, $skCount, 2),//要出仓的spu数量
                "skucount" => $skCount,//规格上对应的sku数量
                "spucode" => $spuCode,//要出仓的sku编号
                "sprice" => $goodsItem["spu_sprice"],//货品的销售价
                "pprice" => $goodsItem["spu_pprice"],//货品的利润价
                "percent" => $goodsItem["spu_percent"],//货品的利润点
                "skucode" => $skuCode,//规格上所属的sku编号
                "invcode" => $invoiceCode,//所属的出仓单编号
            ));
            $isSuccess = $isSuccess && $igoCode;


            //创建订单时外部订单货品列表中的goodscode!
            $exGoodsCode = $goodsItem["goods_code"];
            $exGoodsCount = 0;//可出仓总数量
            $exGoodsCostPrice = 0;//总成本


            //创建出仓批次数据
            $count = $goodstoredModel->queryCountBySkuCode($skuCode);
            $goodstoredList = $goodstoredModel->queryListBySkuCode($skuCode, 0, $count);
            foreach ($goodstoredList as $goodstoredItem) {
                $storedSkuCount = $goodstoredItem["sku_count"];
                $storedSpuBprice = $goodstoredItem["gb_bprice"];//批次成本价
                if ($storedSkuCount == 0) continue;
                if ($skCount == 0) break;
                $gsCode = $goodstoredItem["gs_code"];
                $readySkuCount = 0;
                if ($skCount <= $storedSkuCount) {
                    $updatedSkuCount = bcsub($storedSkuCount, $skCount, 2);
                    $updatedGoodsCount = bcmul($spuCount, $updatedSkuCount, 2);
                    $readySkuCount = $skCount;
                } else {
                    $updatedSkuCount = 0;
                    $updatedGoodsCount = bcmul($spuCount, $updatedSkuCount, 2);
                    $readySkuCount = $storedSkuCount;
                }
                $isSuccess = $isSuccess &&
                    $goodstoredModel->updateCountAndSkuCountByCode($gsCode, $updatedGoodsCount, $updatedSkuCount);

                $igsCode = $igoodsentModel->insert(array(
                    "count" => bcmul($spuCount, $readySkuCount, 2),
                    "bprice" => $storedSpuBprice,//批次成本价
                    "spucode" => $spuCode,
                    "gscode" => $gsCode,
                    "igocode" => $igoCode,
                    "skucode" => $skuCode,
                    "skucount" => $readySkuCount,
                    "invcode" => $invoiceCode,
                ));
                $isSuccess = $isSuccess && $igsCode;

                $exGoodsCostPrice += bcmul($storedSpuBprice, bcmul($spuCount, $readySkuCount, 2), 2);
                $exGoodsCount += $readySkuCount;

                $skCount = bcsub($skCount, $readySkuCount);
                //$messages[] = "{$isSuccess}_{$igsCode}_{$skCount}";
            }
            $isSuccess = $isSuccess && ($skCount == 0);

            //记录出仓货品的均价bprice
            if ($exGoodsCount > 0) {
                $exGoodsCode2SpuBPrice[$exGoodsCode] = bcdiv($exGoodsCostPrice, bcmul($spuCount, $exGoodsCount, 2), 2);
            } else {
                //从字典中拿到默认采购价格
                $exGoodsCode2SpuBPrice[$exGoodsCode] = $spuBprice;
            }
        }

        //检测出仓单是否为空
        $igoodsCount = $igoodsModel->queryCountByInvCode($invoiceCode);
        $igoodsentCount = $igoodsentModel->queryCountByInvCode($invoiceCode);
        if ($igoodsCount == 0 && $igoodsentCount == 0) {
            $invoiceModel->deleteByCode($invoiceCode);
        }

        //输出缺货商品信息
        //$messages = array();
        foreach ($goodsList as $index => $goodsItem) {
            if (array_key_exists("lackSkCount", $goodsItem)) {
                $count = $goodsItem["lackSkCount"];
                $spuname = $goodsItem["spu_name"];
                $skuunit = $goodsItem["sku_unit"];
                $messages[] = "{$spuname}: {$count} {$skuunit}";
            }
        }

        //在出现实际备货数量大于库存数量的时候，记录出现该问题的货品数据。
        if (!empty($messages)) {
            $fileData = "#订单[ {$ecode} ]缺货信息如下：<br>\n" . implode("<br>\n", $messages) . "<br>\n";
            $oosFilePath = C("FILE_SAVE_PATH") . C("FILE_TYPE_NAME.WAREHOUSE_OUT_OF_STOCK") . "/" . date("Y-m-d", time()) . ".log";
            file_put_contents($oosFilePath, $fileData, FILE_APPEND);
        }

        $message = empty($messages) ? "" : ("<br># 订单[ {$ecode} ]缺货信息如下：<br>" . implode(",", $messages));
        return array($isSuccess, $message, $exGoodsCode2SpuBPrice);
    }

    /**
     * @param $param
     * @return array|bool
     * 创建出仓单
     */
    public function invoice_create($param)
    {
        if (!isset($param)) {
            $param = $_POST;
            if (empty($param['data']['type'])) {
                $type = self::$INVOICE_CREATE_TYPE_HANDWORK;//pc端，手工记账
            } else {
                $type = $param['data']['type'];
            }
        } else {
            $type = self::$INVOICE_CREATE_TYPE_API;//小程序端,api
        }

        $list = $param['data']['list'];//出仓单货品列表

        //送达时间->备注
        if (!empty($param['data']['pDate'])) {
            $mark = $param['data']['mark'] . "送达时间：" . $param['data']['pDate'];
        } else {
            $mark = $param['data']['mark'];
        }

        $warCode = $this->warCode;//仓库编号
        $worCode = $this->worcode;//人员编号
        $understockArr = array();//库存不足商品列表

        //快速入仓
        if (!empty($param['data']['isFast'])) {
            $isFast = $param['data']['isFast'];
        } else {
            $isFast = 0;
        }

        if (empty($param['data']['receiver'])) {
            $message = "客户名称不能为空";
            venus_throw_exception(1, $message);
            return false;
        } else {
            $receiver = $param['data']['receiver'];//客户名称
        }

        $data = array();//返回数据声明
        //声明所需要用的Model及服务
        $traceService = TraceService::getInstance();
        $invModel = InvoiceDao::getInstance($warCode);
        $taskService = TaskService::getInstance();
        $goodsModel = GoodsDao::getInstance($warCode);
        $spuModel = SpuDao::getInstance($warCode);
        $igoodsModel = IgoodsDao::getInstance($warCode);
        $goodstoredModel = GoodstoredDao::getInstance($warCode);
        $igoodsentModel = IgoodsentDao::getInstance($warCode);
        $goodsbatchModel=GoodsbatchDao::getInstance($warCode);

        venus_db_starttrans();//开启事务
        $traceCode = $traceService->get_trace_code();//获取轨迹编号
        if (!$traceCode) {
            venus_db_rollback();
            $message = '轨迹获取失败';
            venus_throw_exception(2, $message);
            return false;
        }

        //快速出仓出仓单状态为完成，正常出仓出仓单状态为创建
        if ($isFast != 1) {
            $invStatus = self::$INVOICE_STATUS_CREATE;
        } else {
            $invStatus = self::$INVOICE_STATUS_FINISH;
        }

        $invAddData = array(
            "status" => $invStatus,//出仓单状态
            "receiver" => $receiver,//客户名称
            "type" => $type,//出仓单类型
            "mark" => $mark,//出仓单备注
            "tracecode" => $traceCode,//轨迹编号
            "worcode" => $worCode,//人员编号
        );//出仓单新增数据
        if (!empty($param['data']['ecode'])) {
            $issetInv = $invModel->queryByEcode($param['data']['ecode']);
            if (!empty($issetInv)) {
                $invCode = $issetInv['inv_code'];
            } else {
                $invAddData['ecode'] = $param['data']['ecode'];
                $invCode = $invModel->insert($invAddData);//出仓单创建操作
                if (empty($invCode)) {
                    venus_throw_exception(2, "创建出仓单失败");
                    return false;
                }
            }
        } else {
            $invCode = $invModel->insert($invAddData);//出仓单创建操作
            if (empty($invCode)) {
                venus_throw_exception(2, "创建出仓单失败");
                return false;
            }
        }

        //快速出仓不需要创建工单
        if ($isFast != 1) {
            //创建拣货捡单工单
            $taskType = self::$TASK_TYPE_INVPICKORDER;
            $taskStatus = self::$TASK_STATUS_CREATE;//工单为创建状态
            $taskData = array("code" => $invCode);
            $task = $taskService->task_create($warCode, $taskData, $invCode, $taskType, $taskStatus, $invCode);//创建工单

            if (!$task) {
                venus_db_rollback();
                $message = '工单操作失败';
                venus_throw_exception(2, $message);
                return false;
            }
            //记录轨迹
            $traceMark = "创建出仓单";
        } else {
            //记录轨迹
            $traceMark = "创建快速出仓单";
        }

        $trace = $traceService->update_trace_data($warCode, $traceCode, $invCode, $traceMark);//记录轨迹
        if (!$trace) {
            venus_db_rollback();
            $message = '轨迹记录失败';
            venus_throw_exception(2, $message);
            return false;
        }
        $invSkuData = array();
        foreach ($list as $spData) {
            if (empty($spData['spCode'])) {
                $message = "出仓单spu编号不能为空";
                venus_throw_exception(1, $message);
                return false;
            }
            if (empty($spData['count'])) {
                $message = "出仓单货品spu总数量不能为空";
                venus_throw_exception(1, $message);
                return false;
            }
            if (empty($spData['spCunit'])) {
                venus_throw_exception(1, "spu最小计量单位不能为空");
                return false;
            }
            if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', $spData['count'])) {
                venus_throw_exception(4, "spu总数量格式不正确");
                return false;
            } else {
                if (!empty($spData['spCunit']) && $spData['spCunit'] == 1) {
                    if (floor($spData['count']) != $spData['count']) {
                        venus_throw_exception(4, "spu总数量格式不正确");
                        return false;
                    }
                }
            }
            if (empty($spData['skCode'])) {
                $message = "出仓单sku编号不能为空";
                venus_throw_exception(1, $message);
                return false;
            }
            if (empty($spData['skCount'])) {
                $message = "出仓单货品sku数量不能为空";
                venus_throw_exception(1, $message);
                return false;
            }
            if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', $spData['skCount'])) {
                venus_throw_exception(4, "sku数量格式不正确");
                return false;
            } else {
                if (!empty($spData['spCunit']) && $spData['spCunit'] == 1) {
                    if (floor($spData['skCount']) != $spData['skCount']) {
                        venus_throw_exception(4, "sku数量格式不正确");
                        return false;
                    }
                }
            }

            if (!array_key_exists($spData['skCode'], $invSkuData)) {
                $invSkuData[$spData['skCode']] = $spData;
            } else {
                $invSkuData[$spData['skCode']]['count'] = bcadd($invSkuData[$spData['skCode']]['count'], $spData['count'], 2);
                $invSkuData[$spData['skCode']]['skCount'] = bcadd($invSkuData[$spData['skCode']]['skCount'], $spData['skCount'], 2);
            }

        }
        $isSuccess = true;
        foreach ($invSkuData as $skCode => $value) {
            $skuCode = $value['skCode'];
            $skuCount = $value['skCount'];
            $spuCode = $value['spCode'];
            $goodsData = $goodsModel->queryBySkuCode($skuCode);//获取sku库存
            if (empty($goodsData)) $count = 0;
            $goodsCount = $goodsData['goods_count'];//spu库存
            $goodsSkuCount = $goodsData['sku_count'];//spu库存
            $spuCount = $goodsData['spu_count'];
            $goodsCode = $goodsData['goods_code'];
            $count = bcmul($skuCount, $spuCount, 2);
            if (!empty($goodsData['spu_sprice'])) {
                $sprice = $goodsData['spu_sprice'];//spu当前销售价
            } else {
                $sprice = 0;
            }
            if (!empty($goodsData['pro_price'])) {
                $pprice = $goodsData['pro_price'];//spu利润价
            } else {
                $pprice = 0;
            }
            if (!empty($goodsData['pro_percent'])) {
                $percent = $goodsData['pro_percent'];//spu利润率
            } else {
                $percent = 0;
            }
            //出仓单货品数量小于等于库存
            if ($count <= $goodsCount) {
                $igoodsAddData = array(
                    "count" => $count,//spu总数量
                    "spucode" => $spuCode,//spu编号
                    "sprice" => $sprice,//spu当前销售价
                    "pprice" => $pprice,//spu当前利润
                    "goodscode" => $goodsCode,//库存编号
                    "percent" => $percent,//spu当前利润率
                    "skucode" => $skuCode,//sku编号
                    "skucount" => $skuCount,//sku数量
                    "invcode" => $invCode,//所属出仓单单号
                );

                $igoodsCode = $igoodsModel->insert($igoodsAddData);//创建发货清单
                $isSuccess = $isSuccess && !empty($igoodsCode);
                $newCountGoods = bcsub($goodsCount, $count, 2);//新库存
                $newSkuCountGoods = bcsub($goodsSkuCount, $skuCount, 2);

                $uptGoods = $goodsModel->updateCountByCode($goodsCode, $goodsCount, $newCountGoods, $newSkuCountGoods);//修改库存
                $isSuccess = $isSuccess && $uptGoods;
                $goodstoredListCount = $goodstoredModel->queryCountBySkuCode($value['skCode']);
                //创建状态产生批次库位及库存变化
                $goodstoredList = $goodstoredModel->queryListBySkuCode($value['skCode'],0,$goodstoredListCount);//指定商品的库存货品批次货位列表数据
                foreach ($goodstoredList as $gsData) {
                    $gsCode = $gsData["gs_code"];
                    $gsCount = $gsData['gs_count'];
                    $gsSkuCount = $gsData['sku_count'];
                    $bPrice = $gsData['gb_bprice'];
                    if ($gsCount == 0 || $gsSkuCount == 0 || $gsCount < 0 || $gsSkuCount < 0) continue;
                    if ($skuCount == 0) break;
                    $igsSkuCount = 0;
                    if ($skuCount <= $gsSkuCount) {
                        $updatedSkuCount = bcsub($gsSkuCount, $skuCount, 2);
                        $updatedGoodsCount = bcmul($spuCount, $updatedSkuCount, 2);
                        $igsSkuCount = $skuCount;
                    } else {
                        $updatedSkuCount = 0;
                        $updatedGoodsCount = bcmul($spuCount, $updatedSkuCount, 2);
                        $igsSkuCount = $gsSkuCount;
                    }
                    $isSuccess = $isSuccess &&
                        $goodstoredModel->updateCountAndSkuCountByCode($gsCode, $updatedGoodsCount, $updatedSkuCount);
                    if ($updatedSkuCount == 0 && $gsData['gb_status'] != 3) {
                        $isSuccess = $isSuccess && $goodsbatchModel->updateStatusByCode($gsData['gb_code'], 3);
                    }
                    $addIgsData = array(
                        "count" => bcmul($igsSkuCount, $spuCount, 2),
                        "bprice" => $bPrice,
                        "spucode" => $spuCode,
                        "gscode" => $gsCode,
                        "igocode" => $igoodsCode,
                        "skucode" => $skCode,
                        "skucount" => $igsSkuCount,
                        "invcode" => $invCode,
                    );
                    $igsCode = $igoodsentModel->insert($addIgsData);
                    $isSuccess = $isSuccess && !empty($igsCode);
                    $skuCount = bcsub($skuCount, $igsSkuCount, 2);
                }
                $isSuccess = $isSuccess && ($skuCount == 0);
            } else {
                //出仓单货品数量大于库存，计入统计库存不足数组
                $spName = $goodsData['spu_name'];
                if (!array_key_exists($spName, $understockArr)) {
                    $understockArr[$spName] = bcsub($value['count'], $goodsCount, 2);
                }
            }
        }
        if (!empty($understockArr)) {
            venus_db_rollback();
            $message = "库存不足商品列表" . "<br/>";
            foreach ($understockArr as $spuName => $spuCount) {
                $message .= $spuName . ":" . $spuCount . "<br/>";
            }
            venus_throw_exception(2, $message);
            return false;
        } else {
            if (!$isSuccess) {
                venus_db_rollback();
                $success = false;
                $message = '创建出仓单批次失败';
                return array($success, $data, $message);
            } else {
                venus_db_commit();
                $success = true;
                $message = '';
                return array($success, $data, $message);
            }

        }

    }

    /**
     * @param $param
     * @return array
     * 模糊查询
     * 现阶段有收件人
     */
    public function invoice_search_like($param)
    {
        $warCode = $this->warCode;
        if (!isset($param)) {
            $param = $_POST;
        }
        $invoiceModel = InvoiceDao::getInstance($warCode);
        $warModel = WarehouseDao::getInstance($warCode);

        if (!empty($param['data']['receiver'])) {
            $receiver = trim($param['data']['receiver']);
            $clauseInv = array(
                "receiver" => $receiver
            );
            $receiverArr = $invoiceModel->queryReceiverListByCondition($clauseInv, 0, 10000000);
            $receiverData = array_unique(array_column($receiverArr, "inv_receiver"));
            $clauseWar = array(
                "name" => $receiver
            );
            $warArr = $warModel->queryClientListByCondition($clauseWar, 1000000);
            $warData = array_unique(array_column($warArr, "war_name"));
            if (!empty($receiverData) && !empty($warData)) {
                $dataList = array_unique(array_merge($receiverData, $warData));
            } else {
                if (!empty($receiverData) && empty($warData)) {
                    $dataList = array_unique($receiverData);
                } elseif (!empty($warData) && empty($receiverData)) {
                    $dataList = array_unique($warData);
                } else {
                    $success = true;
                    $message = '';
                    return array($success, array(), $message);
                }
            }


            $data = array(
                "list" => $dataList
            );
            $data['list'] = $dataList;
            $success = true;
            $message = '';
            return array($success, $data, $message);
        } else {
            $success = false;
            $message = '';
            return array($success, array(), $message);
        }
    }
}
