<?php
/**
 * Created by PhpStorm.
 * User: lingn
 * Date: 2018/10/18
 * Time: 14:53
 */

namespace Wms\Service;


use Common\Service\ExcelService;
use Common\Service\PassportService;
use Wms\Dao\GoodsbatchDao;
use Wms\Dao\GoodsDao;
use Wms\Dao\GoodstoredDao;
use Wms\Dao\IgoodsDao;
use Wms\Dao\IgoodsentDao;
use Wms\Dao\InvoiceDao;
use Wms\Dao\OrderDao;
use Wms\Dao\OrdergoodsDao;
use Wms\Dao\OrdertaskDao;
use Wms\Dao\PositionDao;
use Wms\Dao\ReceiptDao;
use Wms\Dao\SkuDao;
use Wms\Dao\SpuDao;

class OrdertaskService
{
    protected static $STATUS_DICTS = ["无数据", "未处理", "已处理"];
    protected static $START_TIME = "00:00:00";
    protected static $END_TIME = "23:59:59";
    protected static $ORDER_STATUS_HANDLE_CREATE = 1;//待处理
    protected static $ORDER_STATUS_HANDLE = 2;//处理中
    protected static $ORDER_STATUS_HANDLE_FINISH = 3;//已处理
    protected static $ORDER_STATUS_HANDLE_CANCEL = 4;//已取消
    protected static $ORDERGOODS_STATUS_HANDLE_CREATE = 1;//待处理
    protected static $ORDERGOODS_STATUS_HANDLE = 2;//处理中
    protected static $ORDERGOODS_STATUS_HANDLE_FINISH = 3;//已处理

    public function __construct()
    {
//        获取登录用户信息
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


    //1.搜索采购单
    public function order_search()
    {
        $post = $_POST['data'];
        $odate = $post["cDate"];
        $stime = $post["stime"];
        $ostatus = $post["status"];
        $otcode = $post["otCode"];
        $pdate = $post["pDate"];//送达时间
        $warCode = $post["warCode"];//项目组编号
        $isFinalOrder = $post["isFinalOrder"];//是否是最终销售单
        $isDownload = $post["isDownload"];//是否已下载

        $page = $post["page"];
        $pagemaxcount = 100;


        $page = empty($page) ? 0 : $page;

        if (!empty($otcode)) {
            $condition["otcode"] = $otcode;
        } else {
            //针对查询账单状态的请求，只处理创建时间在20190425之后的单子
            if (!empty($stime)) {
                $condition = array(
                    "sctime" => "$stime 00:00:00"
                );
            }
        }

        if (!empty($odate)) {
            if ($ostatus != 1) {
                $condition["sctime"] = "{$odate} " . self::$START_TIME;
                $condition["ectime"] = "{$odate} " . self::$END_TIME;
            } else {
                $condition["ectime"] = "{$odate} " . self::$END_TIME;
            }
        }

        if (!empty($pdate)) {
            $condition["pdate"] = $pdate;
        }

        if (!empty($ostatus)) {
            $condition["wstatus"] = $ostatus;
        }

        if (!empty($warCode)) {
            $condition["warcode"] = $warCode;
        }

        if (!empty($isFinalOrder)) {
            $condition["isfsorder"] = $isFinalOrder;
        }

        if (!empty($isDownload)) {
            $condition["isdownload"] = $isDownload;
        }


        $orderDao = OrderDao::getInstance();
        $orderCount = $orderDao->queryCountByCondition($condition);
        $orderList = $orderDao->queryListByCondition($condition, $page, $pagemaxcount);

        $orderListData = array();
        $pdateDict = array();
        $warDict = array();
        foreach ($orderList as $orderData) {
            //客户已经取消的单子忽略
            if ($orderData["order_status"] == 3) continue;
            $pdate = $orderData["order_pdate"];
            $pdatetime = "{$pdate} 00:00:00";
            $cdatetime = $orderData["order_ctime"];
            $day = floor((strtotime($pdatetime) - strtotime($cdatetime)) / 86400);
            if ($orderData["order_status"] == 1) {
                $ostatus = "未验货";
            } else {
                $ostatus = C("ORDER_STATUS")[$orderData["order_status"]];
            }

            $wstatus = C("W_ORDER_STATUS")[$orderData["w_order_status"]];
            $userNameArr = explode("[", $orderData["user_name"]);
            $userName = $userNameArr[0];
            $finalMsg = $orderData['is_finalsalesorder'] == 2 ? '最终销售单' : '非最终销售单';
            $downloadMsg = $orderData['is_download'] == 2 ? '已下载' : '未下载';
            $orderListData[] = array(
                "oCode" => $orderData["order_code"],
                "oCtime" => $cdatetime,
                "oPdate" => $pdate,
                "warName" => $orderData["war_name"] . "(" . $userName . ")",
                "otCode" => $orderData["ot_code"],
                "otStatus" => $orderData["w_order_status"],
                "otStatusMsg" => $wstatus,
                "predict" => ($day > 0 ? 1 : 0),//创建时间和发货时间相差大于1天
                "statusMsg" => "[客户{$ostatus}] [仓库{$wstatus}] [{$finalMsg}] [{$downloadMsg}]",
                "isFinalOrder" => $orderData["is_finalsalesorder"],
                "isDownload" => $orderData["is_download"],
            );
            if (!in_array($pdate, $pdateDict)) {
                $pdateDict[] = $pdate;
            }
            if (!in_array($orderData["war_name"], $warDict)) {
                $warDict[$orderData["war_code"]] = $orderData["war_name"];
            }
        }
        sort($pdateDict);
        array_unshift($pdateDict, "全部");
        $warDicts = array();
        $warDicts[] = array(
            "code" => null,
            "name" => "全部",
        );
        foreach ($warDict as $warCode => $warName) {
            $warDicts[] = array(
                "code" => $warCode,
                "name" => $warName,
            );
        }
        $data = array(
            "pageCurrent" => $page,
            "pageSize" => $pagemaxcount,//floor($orderCount / $pagemaxcount),
            "totalCount" => $orderCount,
            "list" => $orderListData,
            "plandates" => $pdateDict,
            "wars" => $warDicts
        );
        return array(true, $data, "");
    }

    //2.搜索采购单任务列表
    public function task_search()
    {
        $post = $_POST['data'];
        $otdate = $post["cDate"];
        $otcode = $post["otCode"];


        $condition = array();
        if (!empty($otdate)) {
            $otdate = substr($otdate, 0, 10);
            $condition["sctime"] = "{$otdate} " . self::$START_TIME;
            $condition["ectime"] = "{$otdate} " . self::$END_TIME;
        }

        if (!empty($otcode)) {
            $condition["otcode"] = $otcode;
        }

        $orderTaskDao = OrdertaskDao::getInstance();
        $orderTaskCount = $orderTaskDao->queryCountByCondition($condition);
        $orderTaskList = $orderTaskDao->queryListByCondition($condition, 0, $orderTaskCount);
        $orderTaskListData = array();
        foreach ($orderTaskList as $orderTaskData) {
            $orderTaskListData[] = array(
                "otCode" => $orderTaskData["ot_code"],
                "ctime" => $orderTaskData["ot_ctime"],
                "ownStatus" => $orderTaskData["ot_ownstatus"],
                "ownStatusMsg" => self::$STATUS_DICTS[$orderTaskData["ot_ownstatus"]],
                "supStatus" => $orderTaskData["ot_supstatus"],
                "supStatusMsg" => self::$STATUS_DICTS[$orderTaskData["ot_supstatus"]],
                "orderCount" => $orderTaskData["ot_ordercount"],
            );
        }

        //统计
        $orderDao = OrderDao::getInstance();
        $condition = array();
        if (!empty($otdate)) {
            $condition["sctime"] = "{$otdate} " . self::$START_TIME;
            $condition["ectime"] = "{$otdate} " . self::$END_TIME;
        }
        //待处理查询所有
        $status1count = $orderDao->queryCountByCondition(array("ectime" => "{$otdate} " . self::$END_TIME, "wstatus" => "1"));
        $status2count = $orderDao->queryCountByCondition(array_merge($condition, array("wstatus" => "2")));
        $status3count = $orderDao->queryCountByCondition(array_merge($condition, array("wstatus" => "3")));
        $stat = array("count1" => $status1count, "count2" => $status2count, "count3" => $status3count);


        //待处理1 处理中2 已处理3


        $data = array("list" => $orderTaskListData, "stat" => $stat);
        return array(true, $data, "");
    }

    //3.订单详情
    public function order_detail()
    {
        $post = $_POST['data'];
        $ocode = $post["oCode"];

        $condition = array("ocode" => $ocode);
        $orderGoodsDao = OrdergoodsDao::getInstance();
        $goodsCount = $orderGoodsDao->queryCountByOrderCode($condition);
        $goodsList = $orderGoodsDao->queryListByCondition($condition, 0, $goodsCount);

        $orderData = OrderDao::getInstance()->queryByCode($ocode);

        //return array(true, $goodsList, "");
        $orderDataInfo = array(
            "oCode" => $orderData["order_code"],
            "oStatus" => $orderData["order_status"],
            "oStatusMsg" => C("W_ORDER_STATUS")[$orderData["w_order_status"]],
            "oPdate" => $orderData["order_pdate"],
            "warName" => $orderData["war_name"],
            "uName" => $orderData["user_name"],
            "uPhone" => $orderData["user_phone"],
            "oMark" => $orderData["order_mark"],
            "uCode" => $orderData["user_code"],
            "otCode" => $orderData["ot_code"],

        );
        $goodsListData = array();
        foreach ($goodsList as $goodsData) {
            $goodsListData[] = array(
                "goodsCode" => $goodsData["goods_code"],
                "goodsCount" => floatval($goodsData["goods_count"]),
                "spName" => $goodsData["spu_name"],
                "skNorm" => $goodsData["sku_norm"],
                "skForm" => $goodsData["spu_form"],
                "skBrand" => $goodsData["spu_brand"],
                "skInit" => floatval($goodsData["sku_init"]),
                "spInit" => $goodsData["spu_init"],
                "skCount" => floatval($goodsData["sku_count"]),
                "spCunit" => $goodsData["spu_cunit"],
                "skUnit" => $goodsData["sku_unit"],
                "skMark" => $goodsData["sku_mark"],
                "spCount" => floatval($goodsData["spu_count"]),
                "goodsStatus" => $goodsData["w_goods_status"],
                "goodsStatusMsg" => C("W_ORDERGOODS_STATUS")[$orderData["w_goods_status"]],
                "own" => ($goodsData["sup_code"] == "SU00000000000001" ? 0 : 1),
            );
        }

        $data = array(
            "pageCurrent" => 0,
            "pageSize" => $goodsCount,
            "totalCount" => $goodsCount,
            "info" => $orderDataInfo,
            "list" => $goodsListData,
        );
        return array(true, $data, "");
    }



//----------------------------------------------------------------------------------------------------------------------

    /**
     * @return array|bool
     * 修改采购单货品数量
     */
    public function goods_update()
    {
        $goodsCode = $_POST['data']['goodsCode'];
        $skCount = $_POST['data']['skCount'];
        $spCount = $_POST['data']['spCount'];
        $spCunit = $_POST['data']['spCunit'];
        $oCode = $_POST['data']['oCode'];//最后改总利润，总价格时修改

        if (empty($goodsCode)) {
            venus_throw_exception(1, "货品编号不能为空");
            return false;//false
        }

        if (empty($skCount) && $skCount != 0) {
            venus_throw_exception(1, "sku货品数量不能为空");
            return false;
        }

        if (empty($spCount) && $spCount != 0) {
            venus_throw_exception(1, "spu货品数量不能为空");
            return false;
        }

        if (empty($spCunit)) {
            venus_throw_exception(1, "spu最小计量单位不能为空");
            return false;
        }
        if ($spCunit == 1) {
            if (intval($skCount) == $skCount && intval($spCount) == $spCount) {
                $goodscount = bcmul(intval($skCount), intval($spCount));
            } else {
                venus_throw_exception(1, "spu数量格式不正确");
                return false;
            }
        }
        if ($spCunit == 0.1) {
            $float = strlen(explode(".", $skCount)[1]);
            if ($float == 1) {
                $goodscount = bcmul($skCount, intval($spCount), 1);
            } else {
                venus_throw_exception(1, "spu数量格式不正确");
                return false;
            }
        }
        if ($spCunit == 0.01) {
            $float = strlen(explode(".", $skCount)[1]);
            if ($float == 2) {
                $goodscount = bcmul($skCount, intval($spCount), 2);
            } else {
                venus_throw_exception(1, "spu数量格式不正确");
                return false;
            }
        }
        venus_db_starttrans();
        $ordergoodsModel = OrdergoodsDao::getInstance();
        $ordergoodsStatus = $ordergoodsModel->queryByCode($goodsCode)['w_goods_status'];
        if ($ordergoodsStatus == self::$ORDERGOODS_STATUS_HANDLE_FINISH) {
            venus_throw_exception(1, "此货品不能修改");
            return false;
        } else {
            $amountUpt = $ordergoodsModel->updateCountByCode($goodsCode, $skCount, $goodscount);
            $amountWskuUpt = $ordergoodsModel->updateWskuCountByCode($goodsCode, $skCount);
            if ($amountUpt && $amountWskuUpt) {
                venus_db_commit();
                $success = true;
                $message = "修改成功";
            } else {
                venus_db_rollback();
                $success = false;
                $message = "修改失败";
            }
        }

        return array($success, "", $message);
    }

    /**
     * @return array|bool
     * 创建分单任务
     */
    public function task_create()
    {
        $oCodes = $_POST['data']['oCodes'];
        if (empty($oCodes)) {
            venus_throw_exception(1, "采购单列表不能为空");
            return false;
        }
        $ordergoodsModel = OrdergoodsDao::getInstance();
        $orderModel = OrderDao::getInstance();
        $ordertaskModel = OrdertaskDao::getInstance();

        $orderPdateArr = array();
        $orderData = $orderModel->queryPdateListByOcodes($oCodes);
        foreach ($orderData as $orderDatum) {
            if (empty($orderPdateArr)) {
                $orderPdateArr[] = $orderDatum['order_pdate'];
            } else {
                if (!in_array($orderDatum['order_pdate'], $orderPdateArr)) {
                    $message = "您选择的订单中含有不同的送达时间";
                    venus_throw_exception(2, $message);
                    return false;
                } else {
                    continue;
                }
            }
            if (!empty($orderDatum['ot_code'])) {
                $message = "您选择的订单中含有已分单订单";
                venus_throw_exception(2, $message);
                return false;
            }
            if ($orderDatum['w_order_status'] == self::$ORDER_STATUS_HANDLE_CANCEL) {
                $message = "您选择的订单中含有已取消订单";
                venus_throw_exception(2, $message);
                return false;
            }

        }

        venus_db_starttrans();
        //是否有自营商品
        $ordergoodsOwnClause = array(
            "ocodes" => $oCodes,
            "supcode" => "SU00000000000001"
        );
        $ordergoodsOwnData = $ordergoodsModel->queryListByCondition($ordergoodsOwnClause);
        //是否有直采商品
        $ordergoodsSupClause = array(
            "ocodes" => $oCodes,
            "supcode" => array("neq", "SU00000000000001")
        );
        $ordergoodsSupData = $ordergoodsModel->queryListByCondition($ordergoodsSupClause);

        if (!empty($ordergoodsOwnData)) {
            $ownStatus = array_keys(self::$STATUS_DICTS, "未处理")[0];
        } else {
            $ownStatus = array_keys(self::$STATUS_DICTS, "无数据")[0];
        }
        if (!empty($ordergoodsSupData)) {
            $supStatus = array_keys(self::$STATUS_DICTS, "未处理")[0];
        } else {
            $supStatus = array_keys(self::$STATUS_DICTS, "无数据")[0];
        }
        $addOrderTaskData['ctime'] = venus_current_datetime();
        $addOrderTaskData['ownstatus'] = $ownStatus;
        $addOrderTaskData['supstatus'] = $supStatus;
        $addOrderTaskData['ordercount'] = count($oCodes);
        $addOrderTaskData['mark'] = json_encode($oCodes);
        $addOrderTaskRes = $ordertaskModel->insert($addOrderTaskData);
        //添加otcode
        $uptOtCodeToOrdergoodsRes = $ordergoodsModel->updateOtCodeByOrderCodes($oCodes, $addOrderTaskRes);
        $uptOtCodeToOrderRes = $orderModel->updateOtCodeByOrderCodes($oCodes, $addOrderTaskRes);
        //修改订单状态为处理中
        $uptStatusToOrderByOtCodeRes = $orderModel->updateWStatusByOtCode($addOrderTaskRes, self::$ORDER_STATUS_HANDLE);
        $uptStatusToOrdergoodsByOtCodeRes = $ordergoodsModel->updateWStatusByOtCode($addOrderTaskRes, self::$ORDERGOODS_STATUS_HANDLE);
        if ($addOrderTaskRes && $uptOtCodeToOrdergoodsRes && $uptOtCodeToOrderRes && $uptStatusToOrderByOtCodeRes && $uptStatusToOrdergoodsByOtCodeRes) {
            venus_db_commit();
            $success = true;
            $message = "创建成功";
        } else {
            venus_db_rollback();
            $success = false;
            $message = "创建失败";
        }
        return array($success, "", $message);

    }

    //分单任务删除
    public function task_delete()
    {
        $otCode = $_POST['data']['otCode'];

        $otModel = OrdertaskDao::getInstance();
        $orderModel = OrderDao::getInstance();
        $ordergoodsModel = OrdergoodsDao::getInstance();

        $otData = $otModel->queryByCode($otCode);
        $encrycode = $otCode . date("_备货单_Y.m.d");
        $filePath = C("FILE_SAVE_PATH") . C("FILE_TYPE_NAME.INVOICE_ORDER_COLLECTION") . "/" . md5(urlencode($encrycode)) . ".xlsx";
        if ($otData['ot_ownstatus'] == array_keys(self::$STATUS_DICTS, "已处理")[0] || $otData['ot_supstatus'] == array_keys(self::$STATUS_DICTS, "已处理")[0] || file_exists($filePath)) {
            $success = false;
            $message = "删除失败,已有相关订单进行处理";
        } else {
            venus_db_starttrans();
            //修改订单状态和货品状态为待处理
            $uptStatusToOrderRes = $orderModel->updateWStatusByOtCode($otCode, self::$ORDER_STATUS_HANDLE_CREATE);
            $uptStatusToOrdergoodsRes = $ordergoodsModel->updateWStatusByOtCode($otCode, self::$ORDERGOODS_STATUS_HANDLE_CREATE);
            //清除otcode
            $delOtRes = $otModel->deleteByCode($otCode);
            $delOtCodeToOrderRes = $orderModel->clearOtCodeByOtCode($otCode);
            $delOtCodeToOrdergoodsRes = $ordergoodsModel->clearOtCodeByOtCode($otCode);
            if ($delOtRes && $delOtCodeToOrderRes && $delOtCodeToOrdergoodsRes && $uptStatusToOrderRes && $uptStatusToOrdergoodsRes) {
                venus_db_commit();
                $success = true;
                $message = "删除成功";
            } else {
                venus_db_rollback();
                $success = false;
                $message = "删除失败";
            }
        }

        return array($success, "", $message);
    }

    /**
     * @return array|bool
     * 直采货品列表-修改价格
     */
    public function goods_bprice_update()
    {
        $spuList = $_POST['data']['spuList'];
        $orderList = $_POST['data']['orderList'];
        $otCode = $_POST['data']['otCode'];
        if (empty($spuList)) {
            venus_throw_exception(1, "货品列表不能为空");
            return false;
        }
        if (empty($orderList)) {
            venus_throw_exception(1, "采购单列表不能为空");
            return false;
        }

        $ordergoodsModel = OrdergoodsDao::getInstance();
        $otModel = OrdertaskDao::getInstance();
        $spuModel = SpuDao::getInstance("WA000001");

        $otData = $otModel->queryByCode($otCode);
        //直采货品如果已处理，不能修改采购价
        if ($otData['ot_supstatus'] == array_keys(self::$STATUS_DICTS, "已处理")[0]) {
            $success = false;
            $message = '此分单任务直采货品已处理';
            return array($success, array(), $message);
        } else {
            venus_db_starttrans();
            foreach ($spuList as $spuData) {
                foreach ($spuData as $spuCode => $spuBprice) {
                    if (empty($spuBprice)) {
                        venus_db_rollback();
                        venus_throw_exception(1, "spu价格不能为空");
                        return false;
                    }

                    if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', $spuBprice)) {
                        venus_db_rollback();
                        venus_throw_exception(4, "spu价格格式不正确");
                        return false;
                    }

                    $uptOrdergoodsBprice = $ordergoodsModel->updateBpriceByOrderCodeAndSpuCodeAndSpuBprice($spuCode, $spuBprice, $orderList);
                    $uptSpuBprice = $spuModel->updateBpriceCodeByCode($spuCode, $spuBprice);
                    if (!$uptOrdergoodsBprice || !$uptSpuBprice) {
                        venus_db_rollback();
                        $message = '修改成本价失败';
                        venus_throw_exception(2, $message);
                        return false;
                    }
                }
            }
            venus_db_commit();
            $success = true;
            $message = '修改成功';
            return array($success, array(), $message);
        }

    }


    /**
     * type    1:自营，2直采
     * @return array|bool
     * 供应商分单
     */
    public function export_sup()
    {
        $otCode = $_POST['data']['otCode'];
        $type = $_POST['data']['type'];
        if (empty($otCode)) {
            venus_throw_exception(1, "分单任务编号不能为空");
            return false;
        }
        if ($type == 1) {
            $message = "无科贸自营订单";
        } else {
            $message = "无科贸直采订单";
        }
        return $this->order_download($otCode, $type, $message);
    }

    /**
     * @param $otCode 分单任务编号
     * @param $type 1:自营，2直采
     * @param $message
     * @return array
     * 导出订单方法
     */
    public function order_download($otCode, $type, $message)
    {
        $warCode = $this->warCode;
        $ordertaskModel = OrdertaskDao::getInstance($warCode);
        $otData = $ordertaskModel->queryByCode($otCode);
        if ($type == 1) {
            if ($otData['ot_ownstatus'] == array_keys(self::$STATUS_DICTS, "已处理")[0]) {
                $isAllowDown = true;
            } elseif ($otData['ot_ownstatus'] == array_keys(self::$STATUS_DICTS, "无数据")[0]) {
                $isAllowDown = false;
                $data = "";
                $message = "此工单无数据";
//                return array(false, array(), $message);
            } else {
                $isAllowDown = false;
                $data = "";
                $message = "请先确认出库";
//                return array(false, array(), $message);
            }
        }
        if ($type == 2) {
            if ($otData['ot_supstatus'] == array_keys(self::$STATUS_DICTS, "已处理")[0]) {
                $isAllowDown = true;
            } elseif ($otData['ot_supstatus'] == array_keys(self::$STATUS_DICTS, "无数据")[0]) {
                $isAllowDown = false;
                $data = "";
                $message = "此工单无数据";
//                return array(false, array(), $message);
            } else {
                $isAllowDown = false;
                $data = "";
                $message = "请先确认出库";
//                return array(false, array(), $message);
            }
        }
        if ($isAllowDown) {
            $supOrderGoods = array();
            $goodsList = OrdergoodsDao::getInstance()->queryListByOrderTaskCode($otCode, 0, 1000000);//获取订单里的所有货品数据
            foreach ($goodsList as $goodsData) {

                if ($type == 1) {
                    if ($goodsData['supplier_code'] == "SU00000000000001") {
                        $goodsDetailDataArr[$goodsData['order_code']][] = $goodsData;
                    }
                }
                if ($type == 2) {
                    if ($goodsData['supplier_code'] != "SU00000000000001") {
                        $goodsDetailDataArr[$goodsData['order_code']][] = $goodsData;
                    }
                }
            }

            foreach ($goodsDetailDataArr as $oCode => $goodsDetailData) {
                $orderData = OrderDao::getInstance()->queryByCode($oCode);//获取订单信息
                $pdate = date("m.d", strtotime($orderData["order_pdate"]));
                $warcode = $orderData["war_code"];
                $warname = $orderData["war_name"];
                $userName = $orderData["user_name"];
                if ($warname == "市委党校学苑") {
                    $room = $orderData["room"];
                    if (!empty($room)) {
                        $warname = $warname . $room;
                    } else {
                        return array(false, array(), $oCode . "未选择下属餐厅");
                    }
//                    if (strstr($userName, "张红儒") == true || strstr($userName, "陈琳") == true) {
//                        $warname = "市委党校学苑";
//                    } elseif (strstr($userName, "张友") == true || strstr($userName, "冷小强") == true) {
//                        $warname = "市委党校一二厅";
//                    } elseif (strstr($userName, "史晓亮") == true) {
//                        $warname = "市委党校清真";
//                    } elseif (strstr($userName, "宫杰") == true) {
//                        $warname = "市委党校三四厅";
//                    } else {
//                        $warname = "市委党校合同工";
//                    }
                }
                foreach ($goodsDetailData as $goodsDetailDatum) {
                    $spuType = $goodsDetailDatum["spu_type"];
                    if ($type == 1) {
                        $key = "科贸";
                    }
                    if ($type == 2) {
                        $key = "直采";
                    }
                    if (!array_key_exists($key, $supOrderGoods)) {
                        $supOrderGoods[$key] = array();
                    }
                    if ($type == 2 && $goodsDetailDatum['supplier_code'] == "SU00000000000003") {
                        $header = $pdate . "鲜鱼水菜供应商" . "出库单";
                    } else {
                        $header = $pdate . venus_spu_type_name($spuType) . "出库单";
                    }

                    if (!array_key_exists($warname, $supOrderGoods[$key][$header])) {
                        $supOrderGoods[$key][$header][$warname] = array();
                    }

                    if (!array_key_exists($goodsDetailDatum['sku_code'], $supOrderGoods[$key][$header][$warname][$goodsDetailDatum['spu_subtype']])) {

                        if (!empty($goodsDetailDatum['sku_mark'])) {
                            $spuName = $goodsDetailDatum['spu_name'] . "(" . $goodsDetailDatum['sku_mark'] . ")";
                        } else {
                            $spuName = $goodsDetailDatum['spu_name'];
                        }
                        $supOrderGoods[$key][$header][$warname][$goodsDetailDatum['spu_subtype']][$goodsDetailDatum['sku_code']]['spu_name'] = $spuName;
                        $supOrderGoods[$key][$header][$warname][$goodsDetailDatum['spu_subtype']][$goodsDetailDatum['sku_code']]['spu_type'] = $goodsDetailDatum['spu_type'];
                        $supOrderGoods[$key][$header][$warname][$goodsDetailDatum['spu_subtype']][$goodsDetailDatum['sku_code']]['spu_type_name'] = venus_spu_type_name($goodsDetailDatum['spu_type']);
                        $supOrderGoods[$key][$header][$warname][$goodsDetailDatum['spu_subtype']][$goodsDetailDatum['sku_code']]['spu_subtype'] = venus_spu_catalog_name($goodsDetailDatum['spu_subtype']);
                        $supOrderGoods[$key][$header][$warname][$goodsDetailDatum['spu_subtype']][$goodsDetailDatum['sku_code']]['spu_brand'] = $goodsDetailDatum['spu_brand'];
                        $supOrderGoods[$key][$header][$warname][$goodsDetailDatum['spu_subtype']][$goodsDetailDatum['sku_code']]['sku_unit'] = $goodsDetailDatum['sku_unit'];
                        $supOrderGoods[$key][$header][$warname][$goodsDetailDatum['spu_subtype']][$goodsDetailDatum['sku_code']]['sku_norm'] = $goodsDetailDatum['sku_norm'];
                        $supOrderGoods[$key][$header][$warname][$goodsDetailDatum['spu_subtype']][$goodsDetailDatum['sku_code']]['sup_name'] = $goodsDetailDatum['sup_name'];
                        $supOrderGoods[$key][$header][$warname][$goodsDetailDatum['spu_subtype']][$goodsDetailDatum['sku_code']]['sup_code'] = $goodsDetailDatum['supplier_code'];
                        $supOrderGoods[$key][$header][$warname][$goodsDetailDatum['spu_subtype']][$goodsDetailDatum['sku_code']]['spu_sprice'] = bcmul(bcadd($goodsDetailDatum['spu_sprice'], $goodsDetailDatum['profit_price'], 2), $goodsDetailDatum['spu_count'], 2);
                        $supOrderGoods[$key][$header][$warname][$goodsDetailDatum['spu_subtype']][$goodsDetailDatum['sku_code']]['sku_count'] = floatval($goodsDetailDatum['w_sku_count']);
                    } else {
                        $supOrderGoods[$key][$header][$warname][$goodsDetailDatum['spu_subtype']][$goodsDetailDatum['sku_code']]['sku_count'] += floatval($goodsDetailDatum['w_sku_count']);;
                    }
                }
            }
            if (empty($supOrderGoods)) {
                $success = false;
                $data = "";
            } else {
                $orderExport = array();
                $orderSupDataArr = array();
                foreach ($supOrderGoods as $sheetName => $supOrderGoodsDataArr) {
                    foreach ($supOrderGoodsDataArr as $header => $supOrderGoodsData) {
                        if (strpos($header, "鲜鱼水菜供应商") == false) {
                            $header = join(explode("供应商", $header));
                            $orderExport[$sheetName][] = array("北京世纪佳明科贸有限公司.$header", '', '', '', '');
                            $orderExport[$sheetName][] = array('序号', '项目名称', '品类', '数量', '单位', '规格', '品牌', '单价', '归类', '供货商', '备注');
                        }
                        $keys = 0;
                        foreach ($supOrderGoodsData as $warName => $supOrderGoodsDatum) {
                            foreach ($supOrderGoodsDatum as $goodsData) {
                                foreach ($goodsData as $goodsDatum) {
                                    if ($type == 2 && $goodsDatum['sup_code'] == "SU00000000000003") {
                                        $orderSupDataArr[$goodsDatum['spu_type_name']][$warName][] = array($goodsDatum['spu_name'], $goodsDatum['sku_count'], $goodsDatum['sku_unit'], $goodsDatum['sku_norm'], $goodsDatum['spu_brand'], $goodsDatum['spu_sprice'], $goodsDatum['spu_subtype'], $goodsDatum['sup_name'], '');
                                    } else {
                                        $orderExport[$sheetName][] = array($keys + 1, $warName, $goodsDatum['spu_name'], $goodsDatum['sku_count'], $goodsDatum['sku_unit'], $goodsDatum['sku_norm'], $goodsDatum['spu_brand'], $goodsDatum['spu_sprice'], $goodsDatum['spu_subtype'], $goodsDatum['sup_name'], '');
                                        $keys++;
                                    }
                                }
                            }
                        }
                        if (strpos($header, "鲜鱼水菜供应商") == false) {
                            $orderExport[$sheetName][] = array('', '', '', '', '', '', '', '', '', '');
                            $orderExport[$sheetName][] = array('', '', '', '', '', '', '', '', '', '');
                        }
                    }
                }
                unset($supOrderGoodsDataArr);
                //添加直采分单按照供应商项目组分
                if ($type == 2) {
                    foreach ($orderSupDataArr as $spuTypeName => $orderSupData) {

                        foreach ($orderSupData as $warName => $orderSupDatum) {
                            $orderExport[$spuTypeName][] = array('项目名称', $warName, '', '', '', '', '', '', '', '');
                            $orderExport[$spuTypeName][] = array('序号', '品类', '数量', '单位', '规格', '品牌', '单价', '归类', '供货商', '备注');
                            foreach ($orderSupDatum as $num => $goodsData) {
                                array_unshift($goodsData, $num + 1);
                                $orderExport[$spuTypeName][] = $goodsData;
                            }
                            $orderExport[$spuTypeName][] = array('', '', '', '', '', '', '', '', '', '');
                            $orderExport[$spuTypeName][] = array('', '', '', '', '', '', '', '', '', '');
                        }
                    }
                }

                $fileName = ExcelService::getInstance()->exportExcel($orderExport, '', "002", 1);

                if ($fileName) {
                    $success = true;
                    $data = $fileName;
                    $message = "";
                } else {
                    $success = false;
                    $data = "";
                    $message = "下载失败";

                }
            }
        }

        return array($success, $data, $message);
    }

    /**
     * //自营出仓单创建
     */
    public
    function own_inv_create($param)
    {
//        $errSku = array(
//            "SK0000026"
//        );
        if (!isset($param)) {
            $param = $_POST;
        }
        $oCodes = $param['data']['oCodes'];
        $otCode = $param['data']['otCode'];
        $isAllowFastInv = $param['data']['isAllow'];
        $understockArr = array();
        $type = 1;//pc端，手工记账
        $warCode = $this->warCode;
        $worCode = $this->worcode;
        $ordergoodsModel = OrdergoodsDao::getInstance($warCode);
        $otModel = OrdertaskDao::getInstance($warCode);
        $orderModel = OrderDao::getInstance($warCode);
        $goodsModel = GoodsDao::getInstance($warCode);
        $goodstoredModel = GoodstoredDao::getInstance($warCode);
        $invModel = InvoiceDao::getInstance($warCode);
        $igoodsModel = IgoodsDao::getInstance($warCode);
        $igoodsentModel = IgoodsentDao::getInstance($warCode);
        $spuModel = SpuDao::getInstance($warCode);
        $skuModel = SkuDao::getInstance($warCode);
        $ordertaskModel = OrdertaskDao::getInstance($warCode);
        $otData = $ordertaskModel->queryByCode($otCode);
        if ($otData['ot_supstatus'] == array_keys(self::$STATUS_DICTS, "已处理")[0]) {
            venus_throw_exception(2, "分单任务直采采购单已处理");
            return false;
        }
        if ($otData['ot_ownstatus'] == array_keys(self::$STATUS_DICTS, "已处理")[0]) {
            venus_throw_exception(2, "分单任务自营采购单已处理");
            return false;
        } else {
            venus_db_starttrans();
            $invSpuDataArr = array();
            $skuGoodsData = array();
            foreach ($oCodes as $oCode) {
                if (!empty($oCode)) {
                    $goodsList = $ordergoodsModel->queryListByOrderCode($oCode, $page = 0, $count = 100000);//获取订单里的所有货品数据
                    foreach ($goodsList as $goodsData) {
                        if ($goodsData['supplier_code'] == "SU00000000000001" && $goodsData['goods_count'] > 0) {
                            $orderMsg = $orderModel->queryByCode($oCode);
                            //出仓单数据
                            $addInvData = array();
                            $addInvData['receiver'] = $orderMsg['user_name'];
                            $addInvData['phone'] = $orderMsg['user_phone'];
                            $addInvData['address'] = $orderMsg['war_address'];
                            $addInvData['postal'] = $orderMsg['war_postal'];
                            $addInvData['type'] = $type;
                            $addInvData['mark'] = $orderMsg['order_mark'] . ":own";
                            $addInvData['worcode'] = $worCode;
                            $addInvData['ctime'] = $orderMsg['order_ctime'];
                            $addInvData['ecode'] = $oCode;
                            $goodsToInv = array();
                            $goodsToInv['ordergoodscode'] = $goodsData['goods_code'];
                            $goodsToInv['skucode'] = $goodsData['sku_code'];
                            $goodsToInv['skucount'] = $goodsData['sku_count'];
                            $goodsToInv['spucode'] = $goodsData['spu_code'];
                            $goodsToInv['spucount'] = $goodsData['spu_count'];
                            $goodsToInv['count'] = $goodsData['goods_count'];
                            $goodsToInv['sprice'] = $goodsData['spu_sprice'];
                            $goodsToInv['pprice'] = $goodsData['profit_price'];
                            $goodsToInv['percent'] = $goodsData['pro_percent'];
                            $goodsToInv['bprice'] = $goodsData['spu_bprice'];
                            $goodsToInv['warcode'] = $goodsData['war_code'];
                            $goodsToInv['ucode'] = $goodsData['user_code'];
                            $invSpuDataArr[$oCode]['goods'][] = $goodsToInv;
                            $invSpuDataArr[$oCode]['invMsg'] = $addInvData;
                            $skuGoodsData[$goodsData['sku_code']]['skucount'] += $goodsData['sku_count'];
                            $skuGoodsData[$goodsData['sku_code']]['ordergoodscode'][] = $goodsData['goods_code'];
                            unset($goodsToInv);
                            unset($addInvData);
                            $ordergoodsStatus = self::$ORDERGOODS_STATUS_HANDLE_FINISH;
                            $uptOrdergoodsStatus = $ordergoodsModel->updateWStatusByCode($goodsData['goods_code'], $ordergoodsStatus);
                            if (!$uptOrdergoodsStatus) {
                                venus_db_rollback();
                                venus_throw_exception(2, "修改采购单货品状态失败");
                                return false;
                            }
                        } else {
                            continue;
                        }
                    }
                }

            }
            foreach ($skuGoodsData as $skuCode => $skuCountGoodsDatum) {
                $goodsData = $goodsModel->queryBySkuCode($skuCode);
                if (!empty($goodsData)) {
                    if ($skuCountGoodsDatum['skucount'] > $goodsData['sku_count']) {
                        $spName = $goodsData['spu_name'];
                        $understockArr [$spName] = bcsub($skuCountGoodsDatum['skucount'], $goodsData['sku_count'], 2);
                    }
                } else {
                    $spInfo = $skuModel->queryByCode($skuCode);
                    $spName = $spInfo['spu_name'];
                    $understockArr [$spName] = number_format($skuCountGoodsDatum['skucount'], 2);
                }
            }
            if (empty($understockArr) || $isAllowFastInv == 1) {
                $goodsNewData = array();
                $inserInvArr = array();
                foreach ($invSpuDataArr as $ocode => $invSpuData) {
                    $insert[] = $ocode;
                    $igoodsDataList = array();
                    $addInvData = $invSpuData['invMsg'];
                    //            $addInvData['status'] = self::$INVOICE_STATUS_FINISH;
                    $addInvData['status'] = 5;
                    $invCode = $invModel->insert($addInvData);
                    if (!$invCode) {
                        venus_db_rollback();
                        $message = '创建出仓单失败';
                        venus_throw_exception(2, $message);
                        return false;
                    }
                    foreach ($invSpuData['goods'] as $invSpuDatum) {
//                        if (in_array($invSpuDatum['skucode'], $errSku)) {
//                            $updateByOgcode = $ordergoodsModel->updateSupCodeByCode($invSpuDatum['ordergoodscode'], "SU00000000000002");
//                            if (!$updateByOgcode) {
//                                venus_db_rollback();
//                                $message = '修改货品失败';
//                                venus_throw_exception(2, $message);
//                                return false;
//                            }
//                        } else {
                        $goodsData = $goodsModel->queryBySkuCode($invSpuDatum['skucode']);

                        if (empty($goodsData) || $goodsData['goods_count'] == 0 || $goodsData['goods_count'] < $invSpuDatum['count']) {
                            if ($otData['ot_supstatus'] != array_keys(self::$STATUS_DICTS, "已处理")[0]) {
                                $otDataNew = $otModel->queryByCode($otCode);
                                if ($otDataNew['ot_supstatus'] == array_keys(self::$STATUS_DICTS, "无数据")[0]) {
                                    $updateOtSupstatus = $otModel->updateSupStatusByCode($otCode, array_keys(self::$STATUS_DICTS, "未处理")[0]);
                                } else {
                                    $updateOtSupstatus = true;
                                }
                                if (!empty($goodsData) && $goodsData['goods_count'] != 0) {
                                    $insertOgData = array(
                                        "count" => bcsub($invSpuDatum['count'], $goodsData['goods_count'], 2),
                                        "skuinit" => bcdiv(bcsub($invSpuDatum['count'], $goodsData['goods_count'], 2), $invSpuDatum['spucount'], 2),
                                        "skucode" => $invSpuDatum['skucode'],
                                        "spucount" => $invSpuDatum['spucount'],
                                        "spucode" => $invSpuDatum['spucode'],
                                        "supcode" => "SU00000000000002",
                                        "ocode" => $ocode,
                                        "otcode" => $otCode,
                                        "pproprice" => $invSpuDatum['pprice'],
                                        "bprice" => $invSpuDatum['bprice'],
                                        "sprice" => $invSpuDatum['sprice'],
                                        "warcode" => $invSpuDatum['warcode'],
                                        "ucode" => $invSpuDatum['ucode'],
                                    );
                                    $addOgRes = $ordergoodsModel->insert($insertOgData);
                                    $updateByOgcode = $ordergoodsModel->updateCountAndSkuinitAndSkucountByCode($invSpuDatum['ordergoodscode'], $goodsData['sku_count'], $goodsData['goods_count']);
                                    $updateWskucountByOgcode = $ordergoodsModel->updateWskuCountByCode($invSpuDatum['ordergoodscode'], $goodsData['sku_count']);
                                    $addIgoData = $invSpuDatum;
                                    $addIgoData['skucount'] = $goodsData['sku_count'];
                                    $addIgoData['count'] = $goodsData['goods_count'];
                                    $addIgoData['invcode'] = $invCode;
                                    $addIgoData['goodscode'] = $goodsData['goods_code'];
                                    $addIgoRes = $igoodsModel->insert($addIgoData);
                                    if (!$addIgoRes || !$addOgRes || !$updateWskucountByOgcode) {
                                        venus_db_rollback();
                                        $message = '创建出仓单货品失败';
                                        venus_throw_exception(2, $message);
                                        return false;
                                    }
                                } else {
                                    $updateByOgcode = $ordergoodsModel->updateSupCodeByCode($invSpuDatum['ordergoodscode'], "SU00000000000002");
                                }
                                if (!$updateByOgcode || !$updateOtSupstatus) {
                                    venus_db_rollback();
                                    $message = '修改货品失败';
                                    venus_throw_exception(2, $message);
                                    return false;
                                }
                            }
                        } else {
                            $addIgoData = $invSpuDatum;
                            $addIgoData['invcode'] = $invCode;
                            $addIgoData['goodscode'] = $goodsData['goods_code'];
                            $addIgoRes = $igoodsModel->insert($addIgoData);
                            if (!$addIgoRes) {
                                venus_db_rollback();
                                $message = '创建出仓单货品失败';
                                venus_throw_exception(2, $message);
                                return false;
                            }
                        }
//                        }

                    }
                    $igoodsDataList = $igoodsModel->queryListByInvCode($invCode, 0, 10000);
                    if (!empty($igoodsDataList)) {
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
                            //创建状态产生批次库位及库存变化
                            $goodstoredList = $goodstoredModel->queryListBySkuCode($igoodsDatum['sku_code'], 0, 10000);//指定商品的库存货品批次货位列表数据
                            $igoodsentData = $this->branch_goodstored($goodstoredList, $igoodsDatum['igo_count'], $igoodsDatum['igo_code'], $igoodsDatum['spu_code'], $invCode);//调用出仓批次方法
                            foreach ($igoodsentData as $igoodsentDatum) {
                                if (is_array($igoodsentDatum)) {
                                    $goodsoredCount = $igoodsentDatum['remaining'];
                                    $uptGsSpuCount = $goodstoredModel->updateByCode($igoodsentDatum['gscode'], $goodsoredCount);//修改发货库存批次剩余数量
                                    $gsSkuCount = $goodstoredModel->queryByCode($igoodsentDatum['gscode'])['sku_count'];
                                    if ($gsSkuCount < $igoodsentDatum['skucount']) {
                                        $spName = $goodsData['spu_name'];
                                        if (!array_key_exists($spName, $understockArr)) {
                                            $understockArr[$spName] = bcsub($invSpuDatum['skucount'], bcdiv($goodsNewData[$invSpuDatum['spucode']], $invSpuDatum["spucount"], 2), 2);
                                        } else {
                                            $understockArr[$spName] = bcadd($understockArr[$spName], $invSpuDatum['skucount']);
                                        }
                                    } else {
                                        $uptGsSkuCount = $goodstoredModel->updateSkuCountByCode($igoodsentDatum['gscode'], $gsSkuCount - $igoodsentDatum['skucount']);//减少发货库存批次sku数量
                                        $igoodsentCode = $igoodsentModel->insert($igoodsentDatum);//创建发货批次
                                        if (!$uptGsSpuCount || !$uptGsSkuCount) {
                                            $spName = $spuModel->queryByCode($igoodsDatum['spu_code'])['spu_name'];
                                            venus_db_rollback();
                                            venus_throw_exception(2, "修改" . $spName . "库存批次失败");
                                            return false;
                                        }
                                        if (!$igoodsentCode) {
                                            venus_db_rollback();
                                            venus_throw_exception(2, "创建发货批次失败");
                                            return false;
                                        }
                                    }
                                }

                            }
                            $newCountGoods = $goodsData['goods_count'] - $igoodsDatum['igo_count'];//新库存
                            $newSkuCountGoods = $goodsData['sku_count'] - $igoodsDatum['sku_count'];
                            $uptGoods = $goodsModel->updateCountByCode($goodsData['goods_code'], $goodsData['goods_count'], $newCountGoods, $newSkuCountGoods);//修改库存
                            if (!$uptGoods) {
                                venus_db_rollback();
                                venus_throw_exception(2, "修改库存失败");
                                return false;
                            }
                        }
                    }
                }
                $issetOwn = $ordergoodsModel->queryListByCondition(array("supcode" => "SU00000000000001", "otcode" => $otCode), 0, 10000);
                if (empty($issetOwn)) {
                    $uptOtOwnStatus = $otModel->updateOwnStatusByCode($otCode, array_keys(self::$STATUS_DICTS, "无数据")[0]);
                } else {
                    $ownStatus = array_keys(self::$STATUS_DICTS, "已处理")[0];
                    $uptOtOwnStatus = $ordertaskModel->updateOwnStatusByCode($otCode, $ownStatus);
                }
                if ($uptOtOwnStatus) {
                    if ($otData['ot_supstatus'] == array_keys(self::$STATUS_DICTS, "已处理")[0] || $otData['ot_supstatus'] == array_keys(self::$STATUS_DICTS, "无数据")[0]) {
                        $orderStatus = self::$ORDER_STATUS_HANDLE_FINISH;
                        $uptOrderStatus = $orderModel->updateWStatusByCodes($oCodes, $orderStatus);
                        if (!$uptOrderStatus) {
                            venus_db_rollback();
                            venus_throw_exception(2, "修改采购单状态失败");
                            return false;
                        } else {
                            venus_db_commit();
                            $success = true;
                            $message = '自营出仓成功';
                            $data = array();
                            return array($success, $data, $message);
                        }
                    } else {
                        venus_db_commit();
                        $success = true;
                        $message = '自营出仓成功';
                        $data = array();
                        return array($success, $data, $message);
                    }
                } else {
                    venus_db_rollback();
                    venus_throw_exception(2, "修改工单状态失败");
                    return false;
                }
            } else {
                venus_db_rollback();
                $message = "库存不足商品列表" . "<br/>";
                foreach ($understockArr as $spuName => $spuCount) {
                    $message .= $spuName . ":" . $spuCount . "<br/>";
                }
                $success = false;
                $data = array();
                return array($success, $data, $message);
            }
        }
    }

    /*
    //自营出仓单创建
        public
        function own_inv_create()
        {
            $oCodes = $_POST['data']['oCodes'];

            $otCode = $_POST['data']['otCode'];
            $warCode = $this->warCode;
            $orderModel = OrderDao::getInstance($warCode);
            $ordertaskModel = OrdertaskDao::getInstance($warCode);
            $ordergoodsModel = OrdergoodsDao::getInstance($warCode);
            $otData = $ordertaskModel->queryByCode($otCode);
            if ($otData['ot_ownstatus'] == array_keys(self::$STATUS_DICTS, "已处理")[0]) {
                venus_throw_exception(2, "分单任务直采采购单已处理");
                return false;
            } else {
                foreach ($oCodes as $oCode) {
                    $goodsList = $ordergoodsModel->queryListByOrderCode($oCode, $page = 0, $count = 100000);//获取订单里的所有货品数据

                    foreach ($goodsList as $goodsData) {
                        if ($goodsData['sup_type'] == 1 && $goodsData['supplier_code'] == "SU00000000000001" && $goodsData['goods_count'] > 0) {
                            $ordergoodsStatus = self::$ORDERGOODS_STATUS_HANDLE_FINISH;
                            $uptOrdergoodsStatus = $ordergoodsModel->updateWStatusByCode($goodsData['goods_code'], $ordergoodsStatus);
                            if (!$uptOrdergoodsStatus) {
                                venus_db_rollback();
                                venus_throw_exception(2, "修改采购单货品状态失败");
                                return false;
                            }
                        }
                    }
                }
                $ownStatus = array_keys(self::$STATUS_DICTS, "已处理")[0];
                $uptOtOwnStatus = $ordertaskModel->updateOwnStatusByCode($otCode, $ownStatus);
                if (!$uptOtOwnStatus) {
                    venus_db_rollback();
                    venus_throw_exception(2, "修改分单自营状态失败");
                    return false;
                } else {
                    if ($otData['ot_supstatus'] == array_keys(self::$STATUS_DICTS, "已处理")[0] || $otData['ot_supstatus'] == array_keys(self::$STATUS_DICTS, "无数据")[0]) {
                        $orderStatus = self::$ORDER_STATUS_HANDLE_FINISH;
                        $uptOrderStatus = $orderModel->updateWStatusByCodes($oCodes, $orderStatus);
                        if (!$uptOrderStatus) {
                            venus_db_rollback();
                            venus_throw_exception(2, "修改采购单状态失败");
                            return false;
                        } else {
                            venus_db_commit();
                            $success = true;
                            $message = '自营出仓成功';
                            $data = array();
                            return array($success, $data, $message);
                        }
                    } else {
                        venus_db_commit();
                        $success = true;
                        $message = '自营出仓成功';
                        $data = array();
                        return array($success, $data, $message);
                    }
                }
            }
        }
    */
    /**
     * @return array|bool
     * 直采采购单快速入仓出仓
     */
    public
    function sup_inv_create($param)
    {
        $type = 4;//销售出仓
        $warCode = $this->warCode;
        $worCode = $this->worcode;
        $ctime = venus_current_datetime();

        if (!isset($param)) {
            $param = $_POST;
        }
        $orderList = $param['data']['oCodes'];//orderList数据格式{order_code}
        $otCode = $param['data']['otCode'];

        $ordergoodsModel = OrdergoodsDao::getInstance($warCode);
        $orderModel = OrderDao::getInstance($warCode);
        $recModel = ReceiptDao::getInstance($warCode);
        $goodsModel = GoodsDao::getInstance($warCode);
        $goodsbatchModel = GoodsbatchDao::getInstance($warCode);
        $goodstoredModel = GoodstoredDao::getInstance($warCode);
        $positionModel = PositionDao::getInstance($warCode);
        $invModel = InvoiceDao::getInstance($warCode);
        $igoodsModel = IgoodsDao::getInstance($warCode);
        $igoodsentModel = IgoodsentDao::getInstance($warCode);
        $spuModel = SpuDao::getInstance($warCode);
        $ordertaskModel = OrdertaskDao::getInstance($warCode);
        $otData = $ordertaskModel->queryByCode($otCode);
        if ($otData['ot_ownstatus'] == array_keys(self::$STATUS_DICTS, "未处理")[0]) {
            venus_throw_exception(2, "请先处理自营采购单");
            return false;
        }
        if ($otData['ot_supstatus'] == array_keys(self::$STATUS_DICTS, "已处理")[0]) {
            venus_throw_exception(2, "分单任务直采采购单已处理");
            return false;
        } else {
            venus_db_starttrans();
            $recSpuDataArr = array();
            $invSpuDataArr = array();
            $goodsListArr = array();
            foreach ($orderList as $oCode) {
                $goodsList = $ordergoodsModel->queryListByOrderCode($oCode, $page = 0, $count = 100000);//获取订单里的所有货品数据
                foreach ($goodsList as $goodsData) {
                    if ($goodsData['supplier_code'] !== "SU00000000000001" && $goodsData['goods_count'] > 0) {
                        $ordergoodsStatus = self::$ORDERGOODS_STATUS_HANDLE_FINISH;
                        $uptOrdergoodsStatus = $ordergoodsModel->updateWStatusByCode($goodsData['goods_code'], $ordergoodsStatus);
                        if (!$uptOrdergoodsStatus) {
                            venus_db_rollback();
                            venus_throw_exception(2, "修改采购单货品状态失败");
                            return false;
                        }
                        $orderMsg = $orderModel->queryByCode($oCode);
                        //出仓单数据
                        $addInvData = array();
                        $addInvData['receiver'] = $orderMsg['user_name'];
                        $addInvData['phone'] = $orderMsg['user_phone'];
                        $addInvData['address'] = $orderMsg['war_address'];
                        $addInvData['postal'] = $orderMsg['war_postal'];
                        $addInvData['type'] = $type;
                        $addInvData['mark'] = "小程序单(直采)";
                        $addInvData['worcode'] = $worCode;
                        $addInvData['ctime'] = $orderMsg['order_ctime'];
                        $addInvData['ecode'] = $oCode;
                        $goodsToInv = array();
                        $goodsToInv['skucode'] = $goodsData['sku_code'];
                        $goodsToInv['skucount'] = $goodsData['sku_count'];
                        $goodsToInv['spucode'] = $goodsData['spu_code'];
                        $goodsToInv['count'] = $goodsData['goods_count'];
                        $goodsToInv['sprice'] = $goodsData['spu_sprice'];
                        $goodsToInv['pprice'] = $goodsData['profit_price'];
                        $goodsToInv['percent'] = $goodsData['pro_percent'];
                        $invSpuDataArr[$oCode]['goods'][] = $goodsToInv;
                        $invSpuDataArr[$oCode]['invMsg'] = $addInvData;
                        unset($goodsToInv);
                        unset($addInvData);
                        //入仓单数据
                        $goodsToRec = array();
                        if (!array_key_exists($goodsData['spu_code'], $recSpuDataArr)) {
                            $posCode = $positionModel->queryByWarCode($warCode)['pos_code'];
                            $goodsToRec['skucode'] = $goodsData['sku_code'];
                            $goodsToRec['spucode'] = $goodsData['spu_code'];
                            $goodsToRec['supcode'] = $goodsData['sup_code'];
                            $goodsToRec['bprice'] = $goodsData['spu_bprice'];
                            $goodsToRec['poscode'] = $posCode;
                            $goodsToRec['ctime'] = $ctime;
                            $recSpuDataArr[$goodsData['spu_code']] = $goodsToRec;
                            $recSpuDataArr[$goodsData['spu_code']]['count'] = $goodsData['goods_count'];
                            $recSpuDataArr[$goodsData['spu_code']]['skucount'] = $goodsData['sku_count'];
                        } else {
                            $recSpuDataArr[$goodsData['spu_code']]['count'] += $goodsData['goods_count'];
                            $recSpuDataArr[$goodsData['spu_code']]['skucount'] += $goodsData['sku_count'];
                        }
                        unset($goodsToRec);
                    } else {
                        continue;
                    }
                }
            }
            $addRecData['ctime'] = $ctime;
            $addRecData['mark'] = $otCode;
            $addRecData['status'] = 3;
            $addRecData['type'] = 1;
            $addRecData['worcode'] = $worCode;
            $recCode = $recModel->insert($addRecData);
            foreach ($recSpuDataArr as $spuCode => $recSpuData) {
                $addGbData = $recSpuData;
                $addGbData['status'] = 4;
                $addGbData['reccode'] = $recCode;
                $gbCode = $goodsbatchModel->insert($addGbData);
                $addGsData = $recSpuData;
                $addGsData['init'] = $recSpuData['count'];
                $addGsData['gbcode'] = $gbCode;
                $gsCode = $goodstoredModel->insert($addGsData);
                $issetGoods = $goodsModel->queryBySpuCode($recSpuData['spucode']);
                if ($issetGoods) {
                    $goodsCode = $issetGoods['goods_code'];
                    $init = $issetGoods['goods_init'] + $recSpuData['count'];
                    $count = $issetGoods['goods_count'] + $recSpuData['count'];
                    $skuinit = $issetGoods['sku_init'] + $recSpuData['skucount'];
                    $skucount = $issetGoods['sku_count'] + $recSpuData['skucount'];
                    $goodsRes = $goodsModel->updateCountAndInitByCode($goodsCode, $init, $count, $skuinit, $skucount);
                } else {
                    $goodsAddData = array(
                        'init' => $recSpuData['count'],
                        'count' => $recSpuData['count'],
                        'spucode' => $recSpuData['spucode'],
                        'skucode' => $recSpuData['skucode'],
                        'skuinit' => $recSpuData['skucount'],
                        'skucount' => $recSpuData['skucount'],
                    );
                    $goodsRes = $goodsModel->insert($goodsAddData);
                }
                if (!$gbCode) {
                    venus_db_rollback();
                    $message = '创建批次失败';
                    venus_throw_exception(2, $message);
                    return false;
                }
                if (!$gsCode) {
                    venus_db_rollback();
                    $message = '创建库存批次失败';
                    venus_throw_exception(2, $message);
                    return false;
                }
                if (!$goodsRes) {
                    venus_db_rollback();
                    $message = '存入库存失败';
                    venus_throw_exception(2, $message);
                    return false;
                }
            }

            foreach ($invSpuDataArr as $ocode => $invSpuData) {
                $igoodsDataList = array();
                $addInvData = $invSpuData['invMsg'];
                $addInvData['status'] = 5;
                $invCode = $invModel->insert($addInvData);
                if (!$invCode) {
                    venus_db_rollback();
                    $message = '创建出仓单失败';
                    venus_throw_exception(2, $message);
                    return false;
                }
                foreach ($invSpuData['goods'] as $invSpuDatum) {
                    $addIgoData = $invSpuDatum;
                    $addIgoData['invcode'] = $invCode;
                    $goodsData = $goodsModel->queryBySkuCode($invSpuDatum['skucode']);
                    $addIgoData['goodscode'] = $goodsData['goods_code'];
                    $addIgoRes = $igoodsModel->insert($addIgoData);

                    if (!$addIgoRes) {
                        venus_db_rollback();
                        $message = '创建出仓单货品失败';
                        venus_throw_exception(2, $message);
                        return false;
                    }
                }
                $igoodsDataList = $igoodsModel->queryListByInvCode($invCode, 0, 10000);

                foreach ($igoodsDataList as $igoodsDatum) {
                    $goodsData = $goodsModel->queryBySpuCode($igoodsDatum['spu_code']);
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
                    //创建状态产生批次库位及库存变化
                    $goodstoredList = $goodstoredModel->queryListByRecCodeAndSkuCode($recCode, $igoodsDatum['sku_code'], 0, 10000);//指定商品的库存货品批次货位列表数据
                    $igoodsentData = $this->branch_goodstored($goodstoredList, $igoodsDatum['igo_count'], $igoodsDatum['igo_code'], $igoodsDatum['spu_code'], $invCode);//调用出仓批次方法
                    $sentCountGoodsData = array();
                    foreach ($igoodsentData as $igoodsentDatum) {
                        if (is_array($igoodsentDatum)) {
                            $goodsoredCount = $igoodsentDatum['remaining'];
                            $uptGsSpuCount = $goodstoredModel->updateByCode($igoodsentDatum['gscode'], $goodsoredCount);//修改发货库存批次剩余数量
                            $gsSkuCount = $goodstoredModel->queryByCode($igoodsentDatum['gscode'])['sku_count'];
                            $uptGsSkuCount = $goodstoredModel->updateSkuCountByCode($igoodsentDatum['gscode'], $gsSkuCount - $igoodsentDatum['skucount']);//减少发货库存批次sku数量
                            $igoodsentCode = $igoodsentModel->insert($igoodsentDatum);//创建发货批次
                            if (!$uptGsSpuCount || !$uptGsSkuCount) {
                                $spName = $spuModel->queryByCode($igoodsDatum['spu_code'])['spu_name'];
                                venus_db_rollback();
                                venus_throw_exception(2, "修改" . $spName . "库存批次失败");
                                return false;
                            }
                            if (!$igoodsentCode) {
                                venus_db_rollback();
                                venus_throw_exception(2, "创建发货批次失败");
                                return false;
                            }
//                            $newCountGoods = $goodsData['goods_count'] - $igoodsentDatum['count'];//新库存
//                            $newSkuCountGoods = $goodsData['sku_count'] - $igoodsentDatum['skucount'];
//                            $uptGoods = $goodsModel->updateCountByCode($goodsData['goods_code'], $goodsData['goods_count'], $newCountGoods, $newSkuCountGoods);//修改库存
//                            if (!$uptGoods) {
//                                venus_db_rollback();
//                                venus_throw_exception(2, "修改库存失败");
//                                return false;
//                            }
                        }
                    }
                    $newCountGoods = $goodsData['goods_count'] - $igoodsDatum['igo_count'];//新库存
                    $newSkuCountGoods = $goodsData['sku_count'] - $igoodsDatum['sku_count'];
                    $uptGoods = $goodsModel->updateCountByCode($goodsData['goods_code'], $goodsData['goods_count'], $newCountGoods, $newSkuCountGoods);//修改库存
                    if (!$uptGoods) {
                        venus_db_rollback();
                        venus_throw_exception(2, "修改库存失败");
                        return false;
                    }
                }
            }
            $supStatus = array_keys(self::$STATUS_DICTS, "已处理")[0];
            $uptOtSupStatus = $ordertaskModel->updateSupStatusByCode($otCode, $supStatus);

            if (!$uptOtSupStatus) {
                venus_db_rollback();
                venus_throw_exception(2, "修改分单直采状态失败");
                return false;
            } else {
                if ($otData['ot_ownstatus'] == array_keys(self::$STATUS_DICTS, "已处理")[0] || $otData['ot_ownstatus'] == array_keys(self::$STATUS_DICTS, "无数据")[0]) {
                    $clauseOrder = array(
                        "otcode" => $otCode
                    );
                    $ocodeCount = $orderModel->queryCountByCondition($clauseOrder);
                    $ocodeList = $orderModel->queryListByCondition($clauseOrder, 0, $ocodeCount);
                    $ocodeArr = array_column($ocodeList, "order_code");
                    foreach ($ocodeArr as $ocode) {
                        $issetOrdergoodsList = OrdergoodsDao::getInstance()->queryListByOrderCode($ocode, 0, 10000);
                        $uptOrderData = \Common\Service\OrderService::getInstance()->updatePrice($issetOrdergoodsList);
                        $uptOrderRes = OrderDao::getInstance()->updatePriceByCode(
                            $ocode, $uptOrderData['totalBprice'],
                            $uptOrderData['totalSprice'], $uptOrderData['totalSprofit'], $uptOrderData['totalCprofit'], $uptOrderData['totalTprice']);
                        if (!$uptOrderRes) {
                            venus_db_rollback();
                            venus_throw_exception(2, "同步订单价格失败");
                            return false;
                        }
                    }
                    $orderStatus = self::$ORDER_STATUS_HANDLE_FINISH;
                    $uptOrderStatus = $orderModel->updateWStatusByCodes($orderList, $orderStatus);
                    if (!$uptOrderStatus) {
                        venus_db_rollback();
                        venus_throw_exception(2, "修改采购单状态失败");
                        return false;
                    } else {
                        venus_db_commit();
                        $success = true;
                        $message = '直采出仓成功';
                        $data = array();
                        return array($success, $data, $message);
                    }
                } else {
                    venus_db_commit();
                    $success = true;
                    $message = '直采出仓成功';
                    $data = array();
                    return array($success, $data, $message);
                }
            }
        }

    }

//type:1自营；2直采  分单任务详情
    public
    function task_detail()
    {
        $post = $_POST['data'];
        $otCode = $post['otCode'];
        $type = $post['type'];
        $data = array();
        $spuDataArr = array();
        $spuData = array();
        $goodsList = OrdergoodsDao::getInstance()->queryListByOrderTaskCode($otCode);

        foreach ($goodsList as $goodsData) {
            if ($type == 1) {
                if ($goodsData['supplier_code'] == "SU00000000000001") {
                    if (!array_key_exists($goodsData['spu_code'], $spuData)) {
                        $spuData[$goodsData['spu_code']]['sku_code'] = $goodsData['sku_code'];
                        $spuData[$goodsData['spu_code']]['spu_name'] = $goodsData['spu_name'];
                        $spuData[$goodsData['spu_code']]['spu_norm'] = $goodsData['spu_norm'];
                        $spuData[$goodsData['spu_code']]['spu_brand'] = $goodsData['spu_brand'];
                        $spuData[$goodsData['spu_code']]['spu_bprice'] = $goodsData['spu_bprice'];
                        $spuData[$goodsData['spu_code']]['spu_form'] = $goodsData['spu_form'];
                        $spuData[$goodsData['spu_code']]['spu_mark'] = $goodsData['spu_mark'];
                        $spuData[$goodsData['spu_code']]['spu_count'] = $goodsData['spu_count'];
                        $spuData[$goodsData['spu_code']]['unit'] = $goodsData['sku_unit'];
                        $spuData[$goodsData['spu_code']]['spu_cunit'] = $goodsData['spu_cunit'];
                        $spuData[$goodsData['spu_code']]['sup_code'] = $goodsData['sup_code'];
                        $spuData[$goodsData['spu_code']]['count'] = $goodsData['sku_count'];
                    } else {
                        $spuData[$goodsData['spu_code']]['count'] += $goodsData['sku_count'];
                    }
                }
            }
            if ($type == 2) {
                if ($goodsData['supplier_code'] != "SU00000000000001") {
                    if (!array_key_exists($goodsData['spu_code'], $spuData)) {
                        $spuData[$goodsData['spu_code']]['sku_code'] = $goodsData['sku_code'];
                        $spuData[$goodsData['spu_code']]['spu_name'] = $goodsData['spu_name'];
                        $spuData[$goodsData['spu_code']]['spu_norm'] = $goodsData['spu_norm'];
                        $spuData[$goodsData['spu_code']]['spu_brand'] = $goodsData['spu_brand'];
                        $spuData[$goodsData['spu_code']]['spu_bprice'] = $goodsData['spu_bprice'];
                        $spuData[$goodsData['spu_code']]['spu_form'] = $goodsData['spu_form'];
                        $spuData[$goodsData['spu_code']]['spu_mark'] = $goodsData['spu_mark'];
                        $spuData[$goodsData['spu_code']]['spu_count'] = $goodsData['spu_count'];
                        $spuData[$goodsData['spu_code']]['unit'] = $goodsData['sku_unit'];
                        $spuData[$goodsData['spu_code']]['spu_cunit'] = $goodsData['spu_cunit'];
                        $spuData[$goodsData['spu_code']]['sup_code'] = $goodsData['sup_code'];
                        $spuData[$goodsData['spu_code']]['sup_name'] = $goodsData['sup_name'];
                        $spuData[$goodsData['spu_code']]['count'] = $goodsData['sku_count'];
                    } else {
                        $spuData[$goodsData['spu_code']]['count'] += $goodsData['sku_count'];
                    }
                }
            }
        }

        foreach ($spuData as $spCode => $spuDatum) {
            $data['list'][] = array(
                "code" => $spuDatum["sku_code"],
                "spCode" => $spCode,
                "spName" => $spuDatum["spu_name"],
                "spNorm" => $spuDatum["spu_norm"],
                "spBrand" => $spuDatum["spu_brand"],
                "spBprice" => $spuDatum["spu_bprice"],
                "spForm" => $spuDatum["spu_form"],
                "spMark" => $spuDatum["spu_mark"],
                "spCount" => floatval($spuDatum["spu_count"]),
                "skCount" => floatval($spuDatum["count"]),
                "unit" => $spuDatum["unit"],
                "spCunit" => $spuDatum["spu_cunit"],
                "supCode" => $spuDatum['sup_code']
            );
            if ($type == 2) {
                $data['supList'][0] = "全部";
                $data['supList'][$spuDatum['sup_code']] = $spuDatum['sup_name'];
            }
        }

        $success = true;
        $message = '';
        return array($success, $data, $message);

    }

    /**
     * @param $goodstored array 库存批次货位数据
     * @param $igoCount string 需要发出的货品数量
     * @param $igoCode string 需要发出的igoods编号
     * @param $spuCode string 需要发出的spu编号
     * @param $invcode string 出仓单编号
     * @return mixed
     */
    public
    function branch_goodstored($goodstored, $igoCount, $igoCode, $spuCode, $invcode)
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
                        "skucount" => floatval($item['sku_count']),
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
                            "skucount" => floatval($igoodsentCount / $item['spu_count']),
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

    //直采修改价格20190122
    public function sup_bprice_update()
    {
        $spuList = $_POST['data']['spuList'];
        $orderList = $_POST['data']['orderList'];
        $otCode = $_POST['data']['otCode'];
        if (empty($spuList)) {
            $success = false;
            $data = array();
            $message = '货品列表不能为空';
            return array($success, $data, $message);
        }
        if (empty($orderList)) {
            $success = false;
            $data = array();
            $message = '采购单列表不能为空';
            return array($success, $data, $message);
        }

        $ordergoodsModel = OrdergoodsDao::getInstance($this->warCode);
        $otModel = OrdertaskDao::getInstance($this->warCode);
        $spuModel = SpuDao::getInstance($this->warCode);
        $gbModel = GoodsbatchDao::getInstance($this->warCode);
        $gsModel = GoodstoredDao::getInstance($this->warCode);
        $igoModel = IgoodsDao::getInstance($this->warCode);
        $igsModel = IgoodsentDao::getInstance($this->warCode);
        $recModel = ReceiptDao::getInstance($this->warCode);
        $invModel = InvoiceDao::getInstance($this->warCode);
        venus_db_starttrans();
        $otData = $otModel->queryByCode($otCode);
        $gbSpuData = array();
        $gsSpuData = array();
        $igsSpuData = array();
        if ($otData['ot_supstatus'] == array_keys(self::$STATUS_DICTS, "已处理")[0]) {
            $recData = $recModel->queryByEcode($otCode);
            $recCode = $recData['rec_code'];
            $gbDataList = $gbModel->queryListByRecCode($recCode, 0, 100000);
            foreach ($gbDataList as $gbData) {
                if (in_array($gbData['gb_code'], $gbSpuData[$gbData['spu_code']])) continue;
                $gbSpuData[$gbData['spu_code']][] = $gbData['gb_code'];
            }
            $gbCodeArr = array_column($gbDataList, "gb_code");
            $gsDataList = $gsModel->queryListByGbCodes(array("in", $gbCodeArr), 0, 100000);
            foreach ($gsDataList as $gsData) {
                if (in_array($gsData['gs_code'], $gsSpuData[$gsData['spu_code']])) continue;
                $gsSpuData[$gsData['spu_code']][] = $gsData['gs_code'];
            }
            $gsCodeArr = array_column($gsDataList, "gs_code");
            $igsDataList = $igsModel->queryListByGsCode(array("in", $gsCodeArr));
            foreach ($igsDataList as $igsData) {
                if (in_array($igsData['igs_code'], $igsSpuData[$igsData['spu_code']])) continue;
                $igsSpuData[$igsData['spu_code']][] = $igsData['igs_code'];
            }
        }
        $isSuccess = true;
        foreach ($spuList as $spuData) {
            foreach ($spuData as $spuCode => $spuBprice) {
                if (empty($spuBprice) || $spuBprice == 0) {
                    venus_db_rollback();
                    $success = false;
                    $data = array();
                    $message = 'spu价格不能为空';
                    return array($success, $data, $message);
                }

                if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', $spuBprice)) {
                    venus_db_rollback();
                    $success = false;
                    $data = array();
                    $message = 'spu价格格式不正确';
                    return array($success, $data, $message);
                }

                $isSuccess = $isSuccess && $ordergoodsModel->updateBpriceByOrderCodeAndSpuCodeAndSpuBprice($spuCode, $spuBprice, $orderList);
                $isSuccess = $isSuccess && $spuModel->updateBpriceCodeByCode($spuCode, $spuBprice);
                if (!empty($gbSpuData[$spuCode])) $isSuccess = $isSuccess && $gbModel->updateBpriceByCode(array("in", $gbSpuData[$spuCode]), $spuCode, $spuBprice);
                if (!empty($gsSpuData[$spuCode])) $isSuccess = $isSuccess && $gsModel->updateBpriceByCode(array("in", $gsSpuData[$spuCode]), $spuCode, $spuBprice);
                if (!empty($igsSpuData[$spuCode])) $isSuccess = $isSuccess && $igsModel->updateBpriceByCode(array("in", $igsSpuData[$spuCode]), $spuCode, $spuBprice);
            }
        }
        if (!$isSuccess) {
            venus_db_rollback();
            $success = false;
            $data = array();
            $message = '修改成本价失败';
        } else {
            venus_db_commit();
            $success = true;
            $data = array();
            $message = '修改成本价成功';
        }
        return array($success, $data, $message);
    }
}