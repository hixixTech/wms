<?php

namespace Wms\Service;

use Common\Service\PassportService;
use Common\Service\PHPRpcService;
use Wms\Dao\OrdergoodsDao;
use Wms\Dao\OrderDao;
use Wms\Dao\ReturnDao;
use Wms\Dao\ReturntaskDao;
use Wms\Dao\SkuDao;
use Wms\Dao\SkuexternalDao;
use Wms\Dao\SupplierDao;


class PurchaseService
{
    private static $ORDER_STATUS_CREATE = "1";
    private static $ORDER_STATUS_FINISH = "2";
    private static $ORDER_STATUS_CANCEL = "3";
    private static $ORDER_STATUS_EXAMINECARGO = "4";

    public $uCode;
    public $waCode;
    public $workerWarehouseCode;
    public $uToken;

    function __construct()
    {
        $userData = PassportService::getInstance()->loginUser();
        if (empty($userData) || $userData["type"] !== "oms") {
            $msg = $userData['user_wxcode'] . "|" . $userData["type"];
            E($msg, 1);
//            venus_throw_exception(110);
        }

        $this->uCode = $userData["user_code"];
        $this->waCode = $userData["warehousecode"];
        $this->uToken = $userData["user_token"];
        $this->workerWarehouseCode = $userData["war_code"];//user所代表的第三方仓库工作人员的仓库编号
        $this->workerWarehouseName = $userData["war_name"];//user所代表的第三方仓库工作人员的仓库编号
        $this->userIsExternal = $userData["user_is_external"];//是否是外部用户：1.内部 2.外部
        $this->warIsExternal = $userData["war_is_external"];//是否是外部项目组：1.内部 2.外部
    }

    //2.提交采购订单
    public function order_create()
    {
        if ($this->workerWarehouseCode == "WA100025") {
            return array(false, "", "此功能暂不开放！");
        }

        $morning = "06:00:00";//当天时间早上
        $night = "19:00:00";//当天时间晚上（外部）
        $night2 = "16:00:00";//当天时间晚上（内部）
        $currentTime = date("H:i:s", time());//当前时间 时分秒

        if ($this->userIsExternal == 2) {//2为外部用户
            if ($this->workerWarehouseCode == "WA100006" || $this->workerWarehouseCode == "WA100011" || $this->workerWarehouseCode == "WA100023" ||
            $this->workerWarehouseCode == "WA100032" || $this->workerWarehouseCode == "WA100009" || $this->workerWarehouseCode == "WA100010" ||
            $this->workerWarehouseCode == "WA100012" || $this->workerWarehouseCode == "WA100028") {
                if ($currentTime < $morning || $currentTime > $night2) {
                    return array(false, "", "下单时间为  6:00 ～ 16:00\n如遇其他问题请与客服联系");
                }
            } else {
                if ($currentTime < $morning || $currentTime > $night) {
                    return array(false, "", "下单时间为  6:00 ～ 19:00\n如遇其他问题请与客服联系");
                }
            }
            $oIsExternal = 2;
        } else {
            if ($currentTime < $morning || $currentTime > $night2) {
                return array(false, "", "下单时间为  6:00 ～ 16:00\n如遇其他问题请与客服联系");
            }
            $oIsExternal = 1;
        }

        $post = json_decode($_POST['data'], true);
        $oMark = $post['oMark'];
        $oPlan = $post['oPlan'];
        $room = $post['room'];//餐厅
        $List = $post['list'];

        $presentTime = date("Y-m-d", time());//当前时间 年月日
        $saturDay = get_week($presentTime);//周六
        $getWeek = get_week($oPlan);
        if ($saturDay == "星期六") {//下单时间
            return array(false, "", "周六不可以下单哦， \n如遇其他问题请与客服联系");
        }
        if ($getWeek == "星期六") {//送货时间
            return array(false, "", "送达日期不能选择周六， \n如遇其他问题请与客服联系");
        }

        if ($oPlan < $presentTime) {
            return array(false, "", "送货日期不是合法日期");
        }

        if (empty($oPlan)) {
            venus_throw_exception(1, "送货日期不能为空");
            return false;
        }
        if (empty($List)) {
            venus_throw_exception(1, "sku货品不能为空");
            return false;
        }
        $success = true;
        venus_db_starttrans();//启动事务
        $goodsListArrRes = $this->explode_order($List);
        $success = $success && $goodsListArrRes[0];
        $goodsListArr = $goodsListArrRes[1];
        $message = $goodsListArrRes[2];
        foreach ($goodsListArr as $goodsList) {
            $oTag = $goodsList['tag'];
            unset($goodsList['tag']);
            $oData = array(
                "ctime" => venus_current_datetime(),
                "pdate" => $oPlan,
                "status" => self::$ORDER_STATUS_CREATE,//已创建
                "mark" => $oMark,
                "otag" => $oTag,
                "isfsorder" => 1,//是否是最终销售单1.不是 2.是
                "sprice" => 0,
                "bprice" => 0,
                "sprofit" => 0,
                "cprofit" => 0,
                "tprice" => 0,
                "warcode" => $this->workerWarehouseCode,
                "room" => $room,//餐厅
                "oisexternal" => $oIsExternal,
                "ucode" => $this->uCode
            );
            $orderDao = OrderDao::getInstance();
            $orderCode = $orderDao->insert($oData);
            $success = $success && !empty($orderCode);
            if ($success) {
                $totalBprice = 0;//订单总内部采购价
                $totalSprice = 0;//订单总内部销售价
                $totalSprofit = 0;//订单总内部利润金额
                $totalCprofit = 0;//订单客户总利润额
                $totalTprice = 0;//订单总金额
                $skuDao = SkuDao::getInstance($this->waCode);
                foreach ($goodsList as $goodsItem) {
                    $skuData = $skuDao->queryByCode($goodsItem['skCode']);
                    if ($this->userIsExternal == 2) {//2为外部用户
                        $condition['skCode'] = $goodsItem['skCode'];
                        $condition['warCode'] = $this->workerWarehouseCode;
                        $spuEprice = SkuexternalDao::getInstance()->queryListBySkCode($condition);
                        $externalSprice = $spuEprice[0]['spu_eprice'];
                        $supCode = $spuEprice[0]['supcode'];
                        if ($supCode) {
                            $parameter['supcode'] = $supCode;
                            $supDataList = SupplierDao::getInstance("WA000001")->queryListByCondition($parameter);
                            $supType = $supDataList[0]['sup_type'];
                            if ($supType == 1) {
                                $supCode = "SU00000000000001";
                            } else {
                                $supCode = $spuEprice[0]['supcode'];
                            }
                        } else {
                            if ($skuData['is_selfsupport'] == 1) {
                                $supCode = "SU00000000000001";
                            } else {
                                $supCode = $skuData['sup_code'];
                            }
                        }

                    } else {
                        $externalSprice = $skuData['spu_sprice'];
                        if ($skuData['is_selfsupport'] == 1) {
                            $supCode = "SU00000000000001";
                        } else {
                            $supCode = $skuData['sup_code'];
                        }
                    }
                    if ($supCode == 'SU00000000000001') {
                        $bprice = 0;
                    } else {
                        $bprice = $skuData['spu_bprice'];
                    }
                    $spuCount = $goodsItem['skNum'] * $skuData['spu_count'];
                    $gData = array(
                        "count" => $spuCount,
                        "skucode" => $goodsItem['skCode'],
                        "skuinit" => $goodsItem['skNum'],
                        "spucode" => $skuData['spu_code'],
                        "spucount" => $skuData['spu_count'],
                        "sprice" => $externalSprice,//$skuData['spu_sprice'],
                        "bprice" => $bprice,//$skuData['spu_bprice']//自2019-06-10下午开会讨论后结果，采购价不再读取货品字典价格，依据出仓批次价格做更新，更改时间2019-06-11 11:49
                        "pproprice" => $skuData['profit_price'],
                        "ocode" => $orderCode,
                        "supcode" => $supCode,
                        "warcode" => $this->workerWarehouseCode,
                        "ucode" => $this->uCode
                    );
                    $sprice = bcmul($externalSprice, $spuCount, 4);
                    $bprice = bcmul($bprice, $spuCount, 4);
                    $totalBprice += $bprice;
                    $totalSprice += $sprice;
                    $totalSprofit = $totalSprice - $totalBprice;
                    $totalCprofit += bcmul($skuData['profit_price'], $spuCount, 4);
                    $totalTprice += venus_calculate_sku_price_by_spu($externalSprice, $spuCount, $skuData['profit_price']);

                    $success = $success && OrdergoodsDao::getInstance()->insert($gData);
                }
                if ($totalTprice != 0) {
                    $success = $success && $orderDao->updatePriceByCode($orderCode, $totalBprice, $totalSprice, $totalSprofit, $totalCprofit, $totalTprice);
                }

            }
        }

        if ($success) {
            venus_db_commit();//提交事务
//            $message = "下单成功";
        } else {
            venus_db_rollback();//回滚事务
//            $message = "下单失败";
        }
        return array($success, "", $message);


    }

    //采购单列表
    public function order_list()
    {
        $post = json_decode($_POST['data'], true);
        $pageCurrent = $post['pageCurrent'];//当前页码
        $pageSize = 100;//每页显示条数
        $oStatus = $post['oStatus'];

        //当前页码
        if (empty($pageCurrent)) {
            $pageCurrent = 0;
        }
        $condition = array();
        if (!empty($oStatus)) {
            $condition['status'] = $oStatus;
        }
        $condition['warcode'] = $this->workerWarehouseCode;
        $OrderDao = OrderDao::getInstance();
        $totalCount = $OrderDao->queryCountByCondition($condition);//获取指定条件的总条数
        $pageLimit = pageLimit($totalCount, $pageCurrent, $pageSize);
        $orderList = $OrderDao->queryListByCondition($condition, $pageLimit['page'], $pageLimit['pSize']);
        if (empty($orderList)) {
            $purchaseList = array(
                "pageCurrent" => 0,
                "pageSize" => 100,
                "totalCount" => 0
            );
            $purchaseList["list"] = array();
        } else {
            $purchaseList = array(
                "pageCurrent" => $pageCurrent,
                "pageSize" => $pageSize,
                "totalCount" => $totalCount
            );
            foreach ($orderList as $index => $orderItem) {
                $g = substr($orderItem['order_pdate'], -1);
                $purchaseList["list"][$index] = array(
                    "oCode" => $orderItem['order_code'],//订单编号
                    "oCtime" => $orderItem['order_ctime'],//下单时间
                    "oPdate" => $orderItem['order_pdate'],//计划送达日期
                    "oPrice" => $orderItem['order_bprice'],//订单总价
                    "oStatus" => $orderItem['order_status'],//订单状态
                    "oTag" => $orderItem['order_tag'],//订单标签
                    "g" => $g,
//                    "oStatusCommn" => venus_order_status_desc($orderItem['order_status'])
                    "oStatusCommn" => $orderItem['wor_rname'] . "," . venus_order_status_desc($orderItem['order_status'])
                );
            }
        }
        return array(true, $purchaseList, "");
    }

    //采购单详情
    public function order_detail()
    {
        $post = json_decode($_POST['data'], true);
        $oCode = $post['oCode'];
        if (empty($oCode)) {
            venus_throw_exception(1, "订单编号不能为空");
            return false;
        }
        $pnumber = 0;
        $pSize = 10000;
        $orderData = OrderDao::getInstance()->queryByCode($oCode);
        $goodsList = OrdergoodsDao::getInstance()->queryListByOrderCode($oCode, $pnumber, $pSize);
        $detailList = array();
        if ($orderData) {
            $detailList = array(
                "oCode" => $orderData['order_code'],
                "oSprice" => ($orderData['order_tprice'] == intval($orderData['order_tprice'])) ? intval($orderData['order_tprice']) : round($orderData['order_tprice'], 2),
                "oBprice" => $orderData['order_bprice'],
                "oPprice" => $orderData['order_sprofit'],
                "oTime" => $orderData['order_ctime'],
                "oPlan" => $orderData['order_pdate'],
                "oStatus" => $orderData['order_status'],
                "oStatusCommn" => venus_order_status_desc($orderData['order_status'])
            );
            foreach ($goodsList as $index => $goodsItem) {
//                $percent = $goodsItem["pro_percent"];
                $profitPrice = $goodsItem["profit_price"];
                $sprice = $goodsItem["spu_sprice"];
                $count = $goodsItem["spu_count"];
                $totalCount = $goodsItem['goods_count'];
                $skPrice = venus_calculate_sku_price_by_spu($sprice, $count, $profitPrice);
                $totalPrice = venus_calculate_sku_price_by_spu($sprice, $totalCount, $profitPrice);

                $detailList["list"][$index] = array(
                    "goodscode" => $goodsItem['goods_code'],
                    "skCode" => $goodsItem['sku_code'],
                    "spName" => $goodsItem['spu_name'],
                    "skPrice" => ($skPrice == intval($skPrice)) ? intval($skPrice) : round($skPrice, 2),
                    "totalPrice" => ($totalPrice == intval($totalPrice)) ? intval($totalPrice) : round($totalPrice, 2),
                    "skNum" => floatval($goodsItem['sku_init']),
                    "skCount" => floatval($goodsItem['sku_count']),
                    "skBrand" => $goodsItem['spu_brand'],
                    "skUnit" => $goodsItem['sku_unit'],
                    "skNorm" => $goodsItem['sku_norm'] . " × {$count}" . $goodsItem["spu_unit"],//规格中增加表示规格数量的信息
                    "skImg" => $goodsItem['spu_img']
                );

            }
        }
        return array(true, $detailList, "");
    }

    //修改采购单状态(完成订单)
    public function order_status_update()
    {
//        if ($this->userIsExternal == 2) {
//            return array(false, "", "该功能暂不开放！");
//        }
        $post = json_decode($_POST['data'], true);
        $oCode = $post['oCode'];
        $oStatus = $post['oStatus'];
        $recTime = $post['date'];//入仓时间
        $skList = $post['list'];//快进快出添加
        $intradayTime = date("Y-m-d", time());//当天的时间
        if (empty($oCode)) {
            venus_throw_exception(1, "订单编号不能为空");
            return false;
        }

        if (empty($oStatus)) {
            venus_throw_exception(1, "订单状态不能为空");
            return false;
        }

        $orderList = OrderDao::getInstance()->queryByCode($oCode);
        $orderStatus = $orderList['order_status'];
        if ($orderStatus == 2) {
            venus_throw_exception(2, "请不要重复操作!");
            return false;
        }

        venus_db_starttrans();
        $isSuccess = true;
        if ($oStatus == self::$ORDER_STATUS_FINISH) {
            $otCode = OrderDao::getInstance()->queryByCode($oCode);
            if ($otCode['w_order_status'] == "3") {
                $isSuccess = $isSuccess &&
                    OrderDao::getInstance()->updateStatusByCode($oCode, $oStatus);
            } else {
                venus_db_rollback();
                $success = false;
                $message = "此订单正在处理中，如有问题，请联系客服";
                return array($success, "", $message);
            }

            //优化验收数据
            list($success, $message) = $this->optimizeOrderGoodsCount($oCode);
            $isSuccess = $isSuccess && $success;

            //如果验货时修改过数量，就产生一条退货单
            $orderGoodsList = OrdergoodsDao::getInstance()->queryListByOrderCode($oCode);
            foreach ($orderGoodsList as $index => $orderGoodsItem) {
                if ($orderGoodsItem['w_sku_count'] !== $orderGoodsItem['sku_count']) {
                    if ($orderGoodsItem['sku_count'] > $orderGoodsItem['sku_init']) {
                        $oType = 7;//多送货品
                        $returnGoodsCount = "-" . bcsub($orderGoodsItem['sku_count'], $orderGoodsItem['sku_init'], 4);//退货数量
                    } else {
                        $oType = 1;//退货原因：实收不足，产生退货
                        $returnGoodsCount = bcsub($orderGoodsItem['sku_init'], $orderGoodsItem['sku_count'], 4);//退货数量
                    }

                    $orderGoodsReturnData = array(
                        "oNode" => 1,//验货节点：1.验货前，2.验货后
                        "otype" => $oType,
                        "ostatus" => 1,
                        "rtCode" => "",
                        "gcode" => $orderGoodsItem['goods_code'],
                        "gcount" => $returnGoodsCount,
                        "skucode" => $orderGoodsItem['sku_code'],
                        "skucount" => $orderGoodsItem['sku_count'],
                        "spucode" => $orderGoodsItem['spu_code'],
                        "spucount" => $orderGoodsItem['spu_count'],
                        "sprice" => $orderGoodsItem['spu_sprice'],
                        "sbrice" => $orderGoodsItem['spu_bprice'],
                        "percent" => $orderGoodsItem['pro_percent'],
                        "proprice" => $orderGoodsItem['profit_price'],
                        "ocode" => $orderGoodsItem['order_code'],
                        "otcode" => $orderGoodsItem['ot_code'],
                        "supcode" => $orderGoodsItem['supplier_code'],
                        "ucode" => $orderGoodsItem['user_code'],
                        "warcode" => $this->workerWarehouseCode,
                        "warname" => $this->workerWarehouseName,
                        "ogrLog" => "",
                        "igoCode" => "",
                    );
                    $ogrCode = ReturntaskDao::getInstance()->insert_returngoods($orderGoodsReturnData);
                    //查询此项目组当天是否已经退过货
                    $condition = array(
                        "rtAddtime" => $intradayTime,
                        "warCode" => $this->workerWarehouseCode,
                    );
                    $intradayWarCodeReturn = ReturntaskDao::getInstance()->queryListByCondition($condition, 0, 10000);
                    if (empty($intradayWarCodeReturn)) {
                        $returntaskData = array(
                            "rtStatus" => 1,//当前任务状态 1.申请中 2.已处理
                            "warCode" => $this->workerWarehouseCode,
                            "warName" => $this->workerWarehouseName,
                        );
                        $RtCode = ReturntaskDao::getInstance()->insert($returntaskData);
                        $isSuccess = $isSuccess &&
                            ReturntaskDao::getInstance()->updateRtcodeByCode($ogrCode, $RtCode);
                    } else {
                        $isSuccess = $isSuccess &&
                            ReturntaskDao::getInstance()->updateRtcodeByCode($ogrCode, $intradayWarCodeReturn[0]['rt_code']);
                    }

                }
            }
            //更新当前任务状态
            /*   $cond = array(
                    "rt_code" => $intradayWarCodeReturn[0]['rt_code'],
    //                "rtAddtime" => $intradayTime,
                    "warCode" => $this->workerWarehouseCode,
                );
                $returnGoodsData = ReturntaskDao::getInstance()->queryListByCondition($cond);
                $returnGoodsStatus = $returnGoodsData[0]['rt_status'];
                if ($returnGoodsStatus == 2) {//1未处理2已处理
                    $isSuccess = $isSuccess && ReturntaskDao::getInstance()->updateRtStatusByCode($returnGoodsData[0]['rt_code'], 1);
                }*/

            //更新是否是最终销售单状态
            $cond = array(
                "oCode" => $oCode,
                "ogrNode" => 1,
                "ogrStatus" => 1
            );
            $returnGoodsList = ReturntaskDao::getInstance()->queryListByReturnTaskCode($cond);
            if (empty($returnGoodsList)) {
                $isSuccess = $isSuccess && OrderDao::getInstance()->updateIsFinalSalesOrderByCode($oCode, 2);//2.是最终销售单
            }

            $orderData = OrderDao::getInstance()->queryByCode($oCode);//获取订单信息
            $goodsList = OrdergoodsDao::getInstance()->queryListByOrderCode($oCode, $page = 0, $count = 10000);//获取订单里的所有货品数据
            foreach ($goodsList as $index => $goodsItem) {
                $count = round(bcmul($goodsItem['sku_count'], $goodsItem['spu_count'], 3), 2);
                /*
                if ($goodsItem["spu_type"] == "114" || $goodsItem["spu_subtype"] == "10601" || $goodsItem["spu_subtype"] == "10602" || $goodsItem["spu_subtype"] == "10603") {//休闲食品
                    $supCode = "SU40409165143262";
                } else if ($goodsItem["spu_type"] == "116") {//鲜鱼水菜
                    $supCode = "SU40409165031663";
                } else {
                    $supCode = "SU00000000000001";
                }*/

                if ($goodsItem["spu_type"] == "114" || $goodsItem["spu_type"] == "116" || $goodsItem["spu_subtype"] == "10601"
                    || $goodsItem["spu_subtype"] == "10602" || $goodsItem["spu_subtype"] == "10603") {//休闲食品
                    $supCode = $goodsItem['supplier_code'];
                } else {
                    $supCode = "SU00000000000001";
                }

                if ($count >= 0) {
                    $msg = array(
                        'spCode' => $goodsItem["spu_code"],
                        'spName' => $goodsItem["spu_name"],
                        'spAbname' => $goodsItem["spu_abname"],
                        'spType' => $goodsItem["spu_type"],
                        'spSubtype' => $goodsItem["spu_subtype"],
                        'spStoretype' => $goodsItem["spu_storetype"],
                        'spBrand' => $goodsItem["spu_brand"],
                        'spFrom' => $goodsItem["spu_from"],
                        'spNorm' => $goodsItem["spu_norm"],
                        'spUnit' => $goodsItem["spu_unit"],
                        'spMark' => $goodsItem["spu_mark"],
                        'spCunit' => $goodsItem["spu_cunit"],
                        'skCode' => $goodsItem["sku_code"],
                    );
                    foreach ($skList as $skItem) {
                        if ($skItem['skCode'] == $goodsItem['sku_code'] && $skItem['isFast'] == true) {
//                            if ($count == 0) continue;
                            $listFast[$index] = array(//快进快出货品
                                "skCode" => $goodsItem['sku_code'],
                                "skCount" => $count,
                                "spCode" => $goodsItem['spu_code'],
                                "spBprice" => bcadd($goodsItem['spu_sprice'], $goodsItem['profit_price'], 2),
                                "supCode" => $supCode,
                                "supName" => $goodsItem['sup_name'],
                                "supPhone" => $goodsItem['sup_phone'],
                                "supManager" => $goodsItem['sup_manager'],
                                "count" => $count,
                                "spCunit" => $goodsItem['spu_cunit'],
                                "msg" => $msg
                            );
                        } else if ($skItem['skCode'] == $goodsItem['sku_code'] && $skItem['isFast'] == false) {
                            $list[$index] = array(//入仓数据
                                "skCode" => $goodsItem['sku_code'],
                                "skCount" => $count,
                                "spCode" => $goodsItem['spu_code'],
                                "spBprice" => bcadd($goodsItem['spu_sprice'], $goodsItem['profit_price'], 2),
                                "supCode" => $supCode,
                                "supName" => $goodsItem['sup_name'],
                                "supPhone" => $goodsItem['sup_phone'],
                                "supManager" => $goodsItem['sup_manager'],
                                "count" => $count,
                                "spCunit" => $goodsItem['spu_cunit'],
                                "msg" => $msg
                            );
                        }
                    }

                } else {
                    $isSuccess = false;
                    $message = $goodsItem["spu_name"] . "数量不对";
                }
            }
            if (empty($recTime)) {
                $ctime = $intradayTime;
            } else {
                $ctime = $recTime;
            }
            $oMark = $orderData['order_mark'] . "订单编号:" . $oCode;
            $room = $orderData['room'];//餐厅
            $ecode = $oCode;
            $projectTeam = array("WA100000", "WA100001", "WA100003", "WA100009", "WA100019", "WA100020", "WA100017", "WA100002", "WA100010",
                "WA100016", "WA100006", "WA100004", "WA100005", "WA100013", "WA100012", "WA100015", "WA100022", "WA100011", "WA100028", "WA100024", "WA100023", "WA100008", "WE100029", "WA100032");
            if ($isSuccess) {
                if (in_array($this->workerWarehouseCode, $projectTeam)) {
                    $result = PHPRpcService::getInstance()->request($this->uToken, "venus.wms.receipt.receipt.order", array(
                        "list" => $list,
                        "listFast" => $listFast,
                        "mark" => $oMark,
                        "ctime" => $ctime,
                        "room" => $room,//餐厅
                        "ecode" => $ecode,
                        "recType" => 2,
                    ));

                    $isSuccess = $isSuccess && $result['success'];
                    $message = $result['message'];
                } else {
                    $isSuccess = true;
//                    $message = "此功能暂不开放！";
                }
            }
        }

        if ($isSuccess) {
            //更新订单相关的所有价格
            venus_db_commit();
            $this->update_returntaskstatus();
            $message = "更新订单状态成功";
        } else {
            venus_db_rollback();
            $message = "更新订单状态失败" . $message;
        }
        return array($isSuccess, "", $message);

    }

    //采购单分单列表
    public function order_split_search()
    {
        $post = json_decode($_POST['data'], true);
        $oCode = $post['oCode'];
        if (empty($oCode)) {
            venus_throw_exception(1, "订单编号不能为空");
            return false;
        }
        $pnumber = 0;
        $pSize = 10000;
        $orderGoodsList = OrdergoodsDao::getInstance()->queryListByOrderCode($oCode, $pnumber, $pSize);

        $codes = array();
        if ($orderGoodsList) {
            foreach ($orderGoodsList as $index => $orderGoodsItem) {
                //非米面粮油、调味干货类的货品，都可以快进快出  $orderGoodsItem['spu_type'] == "102" || $orderGoodsItem['spu_type'] == "104"
//                $projectTeam = array("WA100000", "WA100001", "WA100022", "WA100012", "WA100003", "WA100010", "WA100013", "WA100002", "WA100005", "WA100006", "WA100020", "WA100028", "WA100019");
                $projectTeam = array("WA100000", "WA100001", "WA100004", "WA100003", "WA100009", "WA100019", "WA100020", "WA100017", "WA100002", "WA100010",
                    "WA100016", "WA100006", "WA100005", "WA100013", "WA100012", "WA100015", "WA100022", "WA100011", "WA100028", "WA100024", "WA100023", "WA100008", "WA100032");
                if (in_array($this->workerWarehouseCode, $projectTeam)) {
                    if ($orderGoodsItem['spu_type'] == "102" || $orderGoodsItem['spu_type'] == "104") {
                        $fast = false;
                    } else {
                        $fast = true;
                    }
                }

                $skPrice = venus_calculate_sku_price_by_spu($orderGoodsItem['spu_sprice'], $orderGoodsItem['spu_count'], $orderGoodsItem['profit_price']);
                $totalPrice = venus_calculate_sku_price_by_spu($orderGoodsItem['spu_sprice'], $orderGoodsItem['goods_count'], $orderGoodsItem['profit_price']);

                if (!empty($orderGoodsItem['sup_code'])) {
                    $count = $orderGoodsItem['spu_count'];
                    $lists = array(
                        "goodscode" => $orderGoodsItem['goods_code'],
                        "skCode" => $orderGoodsItem['sku_code'],
                        "spName" => $orderGoodsItem['spu_name'],
                        "skNum" => ($orderGoodsItem['sku_count'] == intval($orderGoodsItem['sku_count'])) ? intval($orderGoodsItem['sku_count']) : round($orderGoodsItem['sku_count'], 2),
                        "skBrand" => $orderGoodsItem['spu_brand'],
                        "skNorm" => $orderGoodsItem['sku_norm'] . " × {$count}" . $orderGoodsItem["spu_unit"],//规格中增加表示规格数量的信息
                        "skUnit" => $orderGoodsItem['sku_unit'],
                        "spCunit" => $orderGoodsItem['spu_cunit'],
                        "skPrice" => ($skPrice == intval($skPrice)) ? intval($skPrice) : round($skPrice, 2),
                        "totalPrice" => ($totalPrice == intval($totalPrice)) ? intval($totalPrice) : round($totalPrice, 2),
                        "spCount" => $orderGoodsItem['spu_count'],
                        "skImg" => $orderGoodsItem['spu_img'],
                        "fast" => $fast //是否可以快进快出 true可以 false 不可以
                    );
                }

                if (in_array($orderGoodsItem['sup_code'], $codes)) {
                    $results[$orderGoodsItem['sup_code']]['list'][] = $lists;
                } else {
                    $results[$orderGoodsItem['sup_code']]['status'] = $orderGoodsItem['goods_status'];
                    $results[$orderGoodsItem['sup_code']]['statusname'] = venus_ordergoods_status_desc($orderGoodsItem['goods_status']);
                    $results[$orderGoodsItem['sup_code']]['supName'] = $orderGoodsItem['sup_name'];
                    $results[$orderGoodsItem['sup_code']]['list'][] = $lists;
                    $codes[] = $orderGoodsItem['sku_code'];
                }
            }
            $data = array();
            foreach ($results as $keys => $item) {
                $data[] = $item;
            }

        }
        return array(true, $data, "");
    }

    //确认收货
    public function order_goods_receipt()
    {
        $post = json_decode($_POST['data'], true);
        $goodsList = $post['list'];
        $oCode = $post['oCode'];
        $oStatus = self::$ORDER_STATUS_EXAMINECARGO;
        if (empty($goodsList)) {
            venus_throw_exception(1, "货品编号及数量不能为空");
            return false;
        }
        if (empty($oCode)) {
            venus_throw_exception(1, "订单编号不能为空");
            return false;
        }
        $otCode = OrderDao::getInstance()->queryByCode($oCode);
        if ($otCode['w_order_status'] != "3") {
            $success = false;
            $message = "此订单正在处理中，如有问题，请联系客服";
            return array($success, "", $message);
        }
        foreach ($goodsList as $goodsItem) {
            $goodsData = OrdergoodsDao::getInstance()->queryByCode($goodsItem['goodsCode']);
//            if ($goodsItem['skuCount'] > $goodsData['sku_count']) {
//                return array(false, "", "正常验货,\n多余的货品请联系司机带回去");
//            }
            $goodsCount = $goodsItem['skuCount'] * $goodsData['spu_count'];//更改货品数量
            $goodsCountUpd = OrdergoodsDao::getInstance()->updateCountAndStatusByCode($goodsItem['goodsCode'], $goodsItem['skuCount'], $goodsCount, 1);
        }
        $oStatusUpd = OrderDao::getInstance()->updateStatusByCode($oCode, $oStatus);//更新订单的状态
        $this->updatePrice($oCode);//更新订单相关的所有价格 //$updateOerderPrice =
        if ($goodsCountUpd && $oStatusUpd) {
            $success = true;
            $message = "确认收货成功";
        } else {
            $success = false;
            $message = "确认收货失败";
        }
        return array($success, "", $message);
    }


    //取消订单
    public function order_cancel()
    {
        $morning = "06:00:00";//当天时间早上
        $night = "19:00:00";//当天时间晚上
        $night2 = "16:00:00";//当天时间晚上
        $currentTime = date("H:i:s", time());//当前时间

        if ($this->userIsExternal == 2) {//2为外部用户
            if ($currentTime < $morning || $currentTime > $night) {
                return array(false, "", " 6:00 ～ 19:00可取消订单\n如遇其他问题请与客服联系");
            }
        } else {
            if ($currentTime < $morning || $currentTime > $night2) {
                return array(false, "", " 6:00 ～ 16:00可取消订单\n如遇其他问题请与客服联系");
            }
        }
        $post = json_decode($_POST['data'], true);
        $oCode = $post['oCode'];
        $oStatus = $post['oStatus'];
        $orderData = OrderDao::getInstance()->queryByCode($oCode);
        if ($orderData['order_status'] == self::$ORDER_STATUS_CREATE && empty($orderData['ot_code'])) {
            $updateOstatus = OrderDao::getInstance()->updateStatusByCode($oCode, $oStatus);
            $updateWostatus = OrderDao::getInstance()->updateWStatusByCode($oCode, 4);
        } else {
            $success = false;
            $message = "此订单正在处理中，如有问题，请联系客服";
            return array($success, "", $message);
        }
        if ($updateOstatus && $updateWostatus) {
            $success = true;
            $message = "取消订单成功";
        } else {
            $success = false;
            $message = "取消订单失败";
        }
        return array($success, "", $message);
    }

    //删除订单(修改订单,此订单将被删除，数据会恢复到购物车)
    public function order_delete()
    {
        $morning = "06:00:00";//当天时间早上
        $night = "19:00:00";//当天时间晚上
        $currentTime = date("H:i:s", time());//当前时间
        if ($currentTime < $morning || $currentTime > $night) {
            return array(false, "", " 6:00 ～ 19:00可修改订单\n如遇其他问题请与客服联系");
        }
        $post = json_decode($_POST['data'], true);
        $oCode = $post['oCode'];//订单编号
        if (empty($oCode)) {
            venus_throw_exception(1, "订单编号不能为空");
            return false;
        }
        $otCode = OrderDao::getInstance()->queryByCode($oCode);
        if (empty($otCode['ot_code'])) {
            $orderDel = OrderDao::getInstance()->deleteByCode($oCode);
            $orderGoodsDel = OrdergoodsDao::getInstance()->deleteByOcode($oCode);
        } else {
            $success = false;
            $message = "此订单正在处理中，如有问题，请联系客服";
            return array($success, "", $message);
        }

        if ($orderDel && $orderGoodsDel) {
            $success = true;
            $message = "删除订单成功";
        } else {
            $success = false;
            $message = "删除订单失败";
        }
        return array($success, "", $message);
    }

    /**
     * @采购车
     */
    public function purchasing_car_list()
    {
        $post = json_decode($_POST['data'], true);
        $skuCodes = $post['list'];

        foreach ($skuCodes as $index => $skuItem) {

            if ($this->userIsExternal == 1) {
                $skuData = SkuDao::getInstance()->queryBySkuCode($skuItem);
                $sprice = $skuData['spu_sprice'];
            } else {
                $skuLists = SkuDao::getInstance()->queryByCode($skuItem);
                $spucode = $skuLists['spu_code'];
                $skuData = SkuexternalDao::getInstance()->queryByExternalSkuCode($spucode, $this->workerWarehouseCode);
                $sprice = $skuData['spu_eprice'];
            }

            if (!empty($skuData['sku_code'])) {
//                $sprice = $skuData['spu_sprice'];
                $count = $skuData['spu_count'];
                $profitPrice = $skuData['profit_price'];
                $skPrice = venus_calculate_sku_price_by_spu($sprice, $count, $profitPrice);
                $totalprice = ($skPrice == intval($skPrice)) ? intval($skPrice) : round($skPrice, 2);
                $mark = $skuData["spu_mark"];
                $skuDataList["list"][$index] = array(
                    "spName" => $skuData['spu_name'] . (!empty($mark) ? "[{$mark}]" : ""),
                    "spAbName" => $skuData['spu_abname'],
                    "skBrand" => $skuData['spu_brand'],
                    "skCode" => $skuData['sku_code'],
                    "skNorm" => $skuData['sku_norm'],
                    "skUnit" => $skuData['sku_unit'],
                    "skCunit" => $skuData['spu_cunit'],
                    "skTotalPrice" => $totalprice,
                    "skImg" => (empty($skuData["spu_img"]) ? "_" : $skuData["spu_code"]) . ".jpg?_=" . C("SKU_IMG_VERSION"),
                );
            }
        }
        if (empty($skuDataList)) {
            $skuDataList['list'] = array();
        }
        return array(true, $skuDataList, "");
    }

    //更新订单相关金额
    private function updatePrice($oCode)
    {
        $goodsList = OrdergoodsDao::getInstance()->queryListByOrderCode($oCode, $page = 0, $count = 10000);//获取订单里的所有货品数据
        $totalBprice = 0;//订单总内部采购价
        $totalSprice = 0;//订单总内部销售价
        $totalSprofit = 0;//订单总内部利润金额
        $totalCprofit = 0;//订单客户总利润额
        $totalTprice = 0;//订单总金额
        foreach ($goodsList as $index => $goodsItem) {

            $bprice = bcmul($goodsItem['spu_bprice'], $goodsItem['goods_count'], 4);
            $sprice = bcmul($goodsItem['spu_sprice'], $goodsItem['goods_count'], 4);
            $totalBprice += $bprice;
            $totalSprice += $sprice;
            $totalSprofit = $totalSprice - $totalBprice;
            $totalCprofit += bcmul($goodsItem['profit_price'], $goodsItem['goods_count'], 4);
            $totalTprice += venus_calculate_sku_price_by_spu($goodsItem['spu_sprice'], $goodsItem['goods_count'], $goodsItem['profit_price']);
        }
        return OrderDao::getInstance()->updatePriceByCode($oCode, $totalBprice, $totalSprice, $totalSprofit, $totalCprofit, $totalTprice);

    }

    //修正验收货品（同时出现自营、直采）的验收数量
    private function optimizeOrderGoodsCount($oCode)
    {
        $orderGoodsDao = OrdergoodsDao::getInstance();
        $count = $orderGoodsDao->queryCountByOrderCode($oCode);
        $goodsList = $orderGoodsDao->queryListByOrderCode($oCode, 0, $count);//获取订单里的所有货品数据
        $ownGoodsDict = array();
        $supGoodsDict = array();
        foreach ($goodsList as $goodsItem) {
            $supCode = $goodsItem["supplier_code"];
            $skuCode = $goodsItem["sku_code"];
            if ($supCode == "SU00000000000001") {
                if (!isset($ownGoodsDict[$skuCode])) {
                    $ownGoodsDict[$skuCode] = $goodsItem;
                } else {
                    return array(false, "自营类型货品出现重复");
                }
            }
            if ($supCode == "SU00000000000002") {
                if (!isset($supGoodsDict[$skuCode])) {
                    $supGoodsDict[$skuCode] = $goodsItem;
                } else {
                    return array(false, "直采类型货品出现重复");
                }
            }
        }

        $isSuccess = true;
        foreach ($supGoodsDict as $skuCode => $supGoodsItem) {
            $ownGoodsItem = $ownGoodsDict[$skuCode];
            if (empty($ownGoodsItem)) {
                continue;//库内无库存，自营直接转直采的情况
            }
            $ownSpuCount = $ownGoodsItem["spu_count"];
            $ownGoodsCode = $ownGoodsItem["goods_code"];
            $ownGoodsInit = $ownGoodsItem["w_sku_count"];
            $ownGoodsCount = $ownGoodsItem["sku_count"];
            if ($ownGoodsInit == $ownGoodsCount) {
                continue;//自营货品该条记录的发货数与验收数相符的情况
            }
            $supSpuCount = $ownGoodsItem["spu_count"];
            $supGoodsCode = $supGoodsItem["goods_code"];
            $supGoodsInit = $supGoodsItem["w_sku_count"];
            $supGoodsCount = $supGoodsItem["sku_count"];
            $initSum = $ownGoodsInit + $supGoodsInit;
            $countSum = $ownGoodsCount + $supGoodsCount;
            $diffCount = $initSum - $countSum;
            if ($diffCount == 0) {//
                //恢复两条记录的数量count = init
                $isSuccess = $isSuccess &&
                    $orderGoodsDao->updateCountByCode($ownGoodsCode, $ownGoodsInit, $ownGoodsInit * $ownSpuCount) &&
                    $orderGoodsDao->updateCountByCode($supGoodsCode, $supGoodsInit, $supGoodsInit * $supSpuCount);
            } elseif ($diffCount < 0) {
                //实收多了
                $isSuccess = $isSuccess &&
                    $orderGoodsDao->updateCountByCode($ownGoodsCode, $ownGoodsInit, $ownGoodsInit * $ownSpuCount);
                $supGoodsCount = $countSum - $ownGoodsInit;//按实收计算
                $isSuccess = $isSuccess &&
                    $orderGoodsDao->updateCountByCode($supGoodsCode, $supGoodsCount, $supGoodsCount * $supSpuCount);
            } elseif ($diffCount > 0) {
                //实收不足
                if ($diffCount <= $supGoodsInit) {
                    $isSuccess = $isSuccess &&
                        $orderGoodsDao->updateCountByCode($ownGoodsCode, $ownGoodsInit, $ownGoodsInit * $ownSpuCount);
                    $supGoodsCount = $supGoodsInit - $diffCount;
                    $isSuccess = $isSuccess &&
                        $orderGoodsDao->updateCountByCode($supGoodsCode, $supGoodsCount, $supGoodsCount * $supSpuCount);
                } else {
                    $ownGoodsCount = $initSum - $diffCount;
                    $isSuccess = $isSuccess &&
                        $orderGoodsDao->updateCountByCode($ownGoodsCode, $ownGoodsCount, $ownGoodsCount * $ownSpuCount);
                    $supGoodsCount = 0;
                    $isSuccess = $isSuccess &&
                        $orderGoodsDao->updateCountByCode($supGoodsCode, $supGoodsCount, $supGoodsCount * $supSpuCount);
                }
            }
        }
        return array($isSuccess, $isSuccess ? "处理成功" : "优化验收数据处理失败");

    }

    /**
     * 更新当前任务状态
     */
    public function update_returntaskstatus()
    {
        $intradayTime = date("Y-m-d", time());//当天的时间
        //更新当前任务状态
        $cond = array(
            "rtAddtime" => $intradayTime,
            "warCode" => $this->workerWarehouseCode,
        );
        $returnGoodsData = ReturntaskDao::getInstance()->queryListByCondition($cond);
        if (!empty($returnGoodsData)) {
            $returnTaskCode = $returnGoodsData[0]['rt_code'];
            $clause = array(
                "rtCode" => $returnTaskCode,
                "ogrStatus" => 1
            );
            $returnGoodsList = ReturntaskDao::getInstance()->queryListByReturnTaskCode($clause);
            if (!empty($returnGoodsList)) {
                $isSuccess = ReturntaskDao::getInstance()->updateRtStatusByCode($returnTaskCode, 1);
            }
        }
        if ($isSuccess) {
            return array(true, "", "更新退货任务状态成功！");
        }
    }

    //拆分订单策略
    private function explode_order($goodsListData)
    {
        /**
         * 拆单规则
         * 米面粮油102：t+1
         * 调味干货104：t+1
         * 酒水饮料106：t+3
         * 猪牛羊肉108：t+1
         * 鸡鸭禽蛋110：t+1
         * 水产冻货112：t+1
         * 休闲食品114：t+3
         * 鲜鱼水菜116：t+1
         *
         * 20190411新改
         * 大餐酸奶放入常规里，休闲食品和酒水饮料（不含大餐酸奶）单独拆出来
         */

        //拆单规则数组
        $goodaTypeArr = array(
            "106", "114"
        );

        //list，skCode，skNum
        $skuModel = SkuDao::getInstance();
        //将列表内货品根据固定条件分割成多个列表，列表结果格式和参数一致
        $newGoodsList = array();
        $typeArrOne = array();
        $typeArrThree = array();
        foreach ($goodsListData as $goodsListDatum) {
            $skuCode = $goodsListDatum['skCode'];
            $skuNum = $goodsListDatum['skNum'];
            $skuData = $skuModel->queryByCode($skuCode);
            $spuType = $skuData['spu_type'];
            $spuSubType = $skuData['spu_subtype'];
            if (in_array($spuType, $goodaTypeArr) && $spuSubType != "10604") {
                $newGoodsList["3"][] = array(
                    "skCode" => $skuCode,
                    "skNum" => $skuNum,
                );
                if (!in_array(venus_spu_type_name($spuType), $typeArrThree)) {
                    $typeArrThree[] = venus_spu_type_name($spuType);
                }
            } else {
                $newGoodsList["1"]['tag'] = "常规订单";
                $newGoodsList["1"][] = array(
                    "skCode" => $skuCode,
                    "skNum" => $skuNum,
                );
            }
        }
        if (!empty($typeArrThree)) {
            $newGoodsList["3"]['tag'] = "(" . join(",", $typeArrThree) . ")";
        }
        if (count($newGoodsList) > 1) {
            $message = "系统根据本订单货品类型，已经自动完成分单";
        } else {
            $message = "系统根据本订单货品类型，无需自动完成分单";
        }
        return array(
            true, $newGoodsList, $message
        );
    }
}
