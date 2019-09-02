<?php
/**
 * Created by PhpStorm.
 * User: lilingna
 * Date: 2019/1/15
 * Time: 14:58
 */

namespace Wms\Service;


use Common\Service\ExcelService;
use Common\Service\PassportService;
use Wms\Dao\IgoodsentDao;
use Wms\Dao\OrderDao;
use Wms\Dao\OrdergoodsDao;
use Wms\Dao\OrdertaskDao;
use Wms\Dao\SpuDao;

class OrderbatchService
{
    private static $OWN_SUPCODE = "SU00000000000001";//自营供货
    private static $OWN_SUPCODE2 = "SU00000000000002";//自营供货（缺货）
    private static $SUP_SUPCODE3 = "SU00000000000003";//鲜鱼水菜，奶制品
    private static $ORDER_FINISH = 2;//订单最终销售单状态
    protected static $STATUS_DICTS = ["无数据", "未处理", "已处理"];
    private static $downloadStime = "16:00:00";
    private static $downloadEtime = "23:59:59";

    //构造函数
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
    }

    //在OTCODE所在的分单任务单的自营列表中，导出当前的自营备货单
    //venus.wms.orderbatch.ownlist.export
    public function ownlist_export()
    {
        return $this->ownlist_warehouse_export();
        $post = $_POST['data'];
        $otcode = $post["otCode"];
        $condition = array("otcode" => $otcode, "supcode" => self::$OWN_SUPCODE);
        $orderGoodsDao = OrdergoodsDao::getInstance();
        $goodsCount = $orderGoodsDao->queryCountByOrderCode($condition);
        $goodsList = $orderGoodsDao->queryListByCondition($condition, 0, $goodsCount);
        $encrycode = $otcode . date("_备货单_Y.m.d");
        return $this->export_goodslist_excelfile($goodsList, 0, $encrycode);
    }



    //导出当前日期的属于分单任务列表中的所有订单的备货单总表（前提是当天分单任务列表中的所有分单任务都完成）
    //venus.wms.orderbatch.ownlist.exportall
    public function ownlist_exportall()
    {
        $post = $_POST['data'];
        $pdate = $post["pDate"];
        $condition = array("sctime" => "$pdate 00:00:00", "ectime" => "$pdate 23:59:59");
        $ordertaskSupCount = OrdertaskDao::getInstance()->queryCountByCondition(array_merge($condition, array("ownstatus" => 1)));
        if ($ordertaskSupCount > 0) {
            return array(false, "", '当前分单任务列表中存在未完成的自营备货任务，请先完成再导出总备货单');
        }
        $ordertaskCount = OrdertaskDao::getInstance()->queryCountByCondition($condition);
        $ordertaskList = OrdertaskDao::getInstance()->queryListByCondition($condition, 0, $ordertaskCount);
        $otCodeList = array_column($ordertaskList, "ot_code");
        $conditionOrder = array(
            "otcode" => array("in", $otCodeList),
            "wstatus" => 3
        );
        $orderCount = OrderDao::getInstance()->queryCountByCondition($conditionOrder);
        $orderList = OrderDao::getInstance()->queryListByCondition($conditionOrder, 0, $orderCount);
        foreach ($orderList as $index => $orderItem) {
            if ($orderItem["w_order_status"] != 3) {
                unset($orderList[$index]);
            }
        }
        $ocodes = array_unique(array_column($orderList, "order_code"));//提取所有ordercode
        $condition = array("ocodes" => $ocodes, "supcode" => self::$OWN_SUPCODE);
        $orderGoodsDao = OrdergoodsDao::getInstance();
        $goodsCount = $orderGoodsDao->queryCountByOrderCode($condition);
        $goodsList = $orderGoodsDao->queryListByCondition($condition, 0, $goodsCount);

        $encrycode = date("总备货单_Y年m月d日");
        return $this->export_goodslist_excelfile($goodsList, 0, $encrycode);
    }
    /*************************************************************************************/
    //在OTCODE所在的分单任务单的自营列表中，导入自营货品列表并确认出库
    //venus.wms.orderbatch.ownlist.finish
    public function ownlist_finish()
    {
        return $this->ownlist_warehouse_finish();
        $datas = ExcelService::getInstance()->upload("file");
        $otcode = $_POST['otCode'];
        $encrycode = key($datas);
        $excelDataList = $datas[$encrycode];
        $excelotcode = explode("_", $encrycode)[0];
        //return array(false,1,$excelotcode."asdasdas".$otcode);

        if (!preg_match("/^OT\d{14}$/", $excelotcode)) {
            return array(false, "", "<span style='font-size: 30px;color: red'>表格数据已经损坏，请与IT组联系解决</span>");
        }
        if ($excelotcode != $otcode) {
            return array(false, "", "<span style='font-size: 30px;color: red'>备货单数据与当天分单任务不符</span>");
        }
        $otOrder = OrdertaskDao::getInstance()->queryByCode($otcode);
        if (empty($otOrder)) {
            return array(false, "", "<span style='font-size: 30px;color: red'>系统检测无此分单任务[{$otcode}]，请与IT组联系解决</span>");
        }
        if ($otOrder["ot_ownstatus"] != '1') {
            return array(false, "", "<span style='font-size: 30px;color: red'>系统检测当前分单任务已经处理，请勿重复提交</span>");
        }

        $orderGoodsDao = OrdergoodsDao::getInstance();
        venus_db_starttrans();
        $invoiceList = array();
        $own2supFound = false;//出现自营转直采
        $ownGoodFound = false;//出现自营货品
        $isSuccess = true;
        foreach ($excelDataList as $index => $item) {
            $goodsName = trim($item["D"]);//商品名称
            $goodsCode = trim($item["C"]);//goods编号
            $goodsIndex = trim($item["A"]);//序号
            $warehouseCount = trim($item["K"]);
            $skuInit = floatval($item["E"]);//应备货数量
            $wSkuInit = floatval($warehouseCount);//实备货数量

            //行数
            $line = $index + 1;

            //序列非整数检测
            if (!is_numeric($goodsIndex)) {
                continue;//非数据项目
            }
            if (!preg_match("/^G\d{14}$/", $goodsCode)) {
                return array(false, "", "第{$line}行：货品【 {$goodsName} 】的货品编码被窜改 <br><span style='font-size: 30px;color: red'>请将表格发给IT组确认解决</span>");
            }

            $goodsData = $orderGoodsDao->queryByCode($goodsCode);
            if (empty($goodsData)) {
                return array(false, "", "第{$line}行：货品编码【 {$goodsCode} 】的商品<br>【 {$goodsName} 】数据异常<br><span style='font-size: 30px;color: red'>请将表格发给IT组确认解决</span>");
            }

            $goodsSkuInit = $goodsData["sku_init"];
            if ($goodsSkuInit != $skuInit) {
                return array(false, "", "第{$line}行：货品编码【 {$goodsCode} 】的商品<br>【 {$goodsName} 】数据异常<br>应备货数量被窜改【{$goodsSkuInit}!={$skuInit}】<br><span style='font-size: 30px;color: red'>请将表格发给IT组确认解决</span>");
            }

            //记录准备出仓的货品
            $ocode = $goodsData["order_code"];
            if (!isset($invoiceList[$ocode])) {
                $invoiceList[$ocode] = array();
            }

            //检测实际备货数量为数字类型
            if (!is_numeric($warehouseCount) || $warehouseCount < 0) {
                return array(false, "", "={$warehouseCount}=第{$line}行：货品编码【 {$goodsCode} 】的商品<br>【 {$goodsName} 】数据异常<br><span style='font-size: 30px;color: red'>商品的实际备货数量填写有误！</span>");
            }


            $spuCount = $goodsData["spu_count"];//规格倍数
            $spuCunit = $goodsData["spu_cunit"];//最小计量单位

            //检测实际备货数量，及计划备货数量的关系
            if ($spuCunit == 1 && $wSkuInit > $skuInit) {
                return array(false, "", "第{$line}行：货品编码【 {$goodsCode} 】的商品<br>【 {$goodsName} 】数据异常<br><span style='font-size: 30px;color: red'>非抄码商品，实际备货数量应该<=计划备货数量！</span>");
            }
            if ($spuCunit == 1 && floor($wSkuInit) < $wSkuInit) {
                return array(false, "", "第{$line}行：货品编码【 {$goodsCode} 】的商品<br>【 {$goodsName} 】数据异常<br><span style='font-size: 30px;color: red'>非抄码商品，实际备货数量不应该有小数点</span>");
            }
            if (ceil(round(bcmod(($wSkuInit * 100), ($spuCunit * 100)))) > 0) {
                return array(false, "", "第{$line}行：货品编码【 {$goodsCode} 】的商品<br>【 {$goodsName} 】数据异常<br><span style='font-size: 30px;color: red'>最小计数单位有误，请修正后提交</span>");
            }

            if (!isset($warehouseCount) || !is_numeric($wSkuInit) || $skuInit == $wSkuInit) {
                $ownGoodFound = true;
                $invoiceList[$ocode][] = $goodsData;//无变化时默认记录到出仓数据中
                continue;//未发现备货数据差异 或 无数据变化
            }

            //检测是否是否符合最小单位要求
            if ($wSkuInit == 0) {//无库存，全部转为直采
                $own2supFound = true;
                $isSuccess = $isSuccess &&
                    $orderGoodsDao->updateSupCodeByCode($goodsCode, self::$OWN_SUPCODE2);
                if (!$isSuccess) return array(false, array(), "无库存，全部转为直采修改ordergoods");
            } elseif ($wSkuInit > $skuInit) {//实际数量大于下单数量
                $ownGoodFound = true;
                $isSuccess = $isSuccess &&
                    $orderGoodsDao->updateAllCountByCode($goodsCode, bcmul($wSkuInit, $spuCount, 2), $wSkuInit, $wSkuInit, $wSkuInit);
                if (!$isSuccess) return array(false, array(), "实际数量大于下单数量修改ordergoods");
                $invoiceList[$ocode][] = $orderGoodsDao->queryByCode($goodsCode);//写入出仓单
            } elseif ($wSkuInit < $skuInit) {//实际数量小于下单数量
                $own2supFound = true;//出现自营转直采的情况
                $ownGoodFound = true;
                $isSuccess = $isSuccess &&
                    $orderGoodsDao->updateAllCountByCode($goodsCode, bcmul($wSkuInit, $spuCount, 2), $wSkuInit, $wSkuInit, $wSkuInit);
                if (!$isSuccess) return array(false, array(), "实际数量小于下单数量修改ordergoods");
                $supskucount = bcsub($skuInit, $wSkuInit, 2);//转直采的sku数量
                $isSuccess = $isSuccess &&
                    $orderGoodsDao->insert(array(
                        "count" => bcmul($supskucount, $spuCount, 2),
                        "skucode" => $goodsData["sku_code"],//spu编号
                        "skuinit" => $supskucount,//当前货品中下单时的sku的数量
                        "spucode" => $goodsData["spu_code"],//spu编号
                        "spucount" => $spuCount,//1个sku规格中含有的spu数量
                        "sprice" => $goodsData["spu_sprice"],//spu的销售价
                        "bprice" => $goodsData["spu_bprice"],//spu的采购价
                        "pproprice" => $goodsData["profit_price"],//spu需要增加的客户利润价
                        "ocode" => $goodsData["order_code"],//订单编号
                        "otcode" => $goodsData["ot_code"],//分单编号
                        "supcode" => self::$OWN_SUPCODE2,//自营不足转直采购的货品
                        "warcode" => $goodsData["war_code"],
                        "ucode" => $goodsData["user_code"],
                    ));
                if (!$isSuccess) return array(false, array(), "插入直采");
                $invoiceList[$ocode][] = $orderGoodsDao->queryByCode($goodsCode);//写入出仓单
            }
            //将当前货品状态变为仓内已处理
            $isSuccess = $isSuccess &&
                OrdergoodsDao::getInstance()->updateWStatusByCode($goodsCode, 3);
        }


        //if (true) return array(false, array(), "debug");
        //return array(false, "", $ownGoodFound?"true":"false");
        //w_order_status
        if ($ownGoodFound) {//存在自营
            $isSuccess = $isSuccess &&
                OrdertaskDao::getInstance()->updateOwnStatusByCode($otcode, 2);//自营备货单完成处理，3
        } else {//已无自营
            $isSuccess = $isSuccess &&
                OrdertaskDao::getInstance()->updateOwnStatusByCode($otcode, 0);//无自营货品
        }
        if (!$isSuccess) return array(false, array(), "自营转直采更新工单自营状态");
        if ($own2supFound) {//存在直采
            OrdertaskDao::getInstance()->updateSupStatusByCode($otcode, -1);//临时重置，之后更新
            $isSuccess = $isSuccess &&
                OrdertaskDao::getInstance()->updateSupStatusByCode($otcode, 1);//直采订单创建待处理
            $otOrder["ot_supstatus"] = 1;
        }
        if (!$isSuccess) return array(false, array(), "自营转直采更新工单直采状态");
        //在无直采的情况下，订单及货品标记完成
        if ($otOrder["ot_supstatus"] == '0') {
            //标记当前分单任务的所有订单为完成处理状态
            $isSuccess = $isSuccess &&
                OrderDao::getInstance()->updateWStatusByOtCode($otcode, 3);
            //标记当前分单任务的所有订单的所有货品为完成处理状态
            $isSuccess = $isSuccess &&
                OrdergoodsDao::getInstance()->updateWStatusByOtCode($otcode, 3);

        }


        if (!$isSuccess) return array(false, array(), "在无直采的情况下，订单及货品标记完成");
        //return array(false,1,$isSuccess."=".json_encode(1));
        //初始化订单信息字典
        $orderDict = array();
        $ocodes = array_keys($invoiceList);
        $condition = array("ocodes" => $ocodes);
        $orderCount = OrderDao::getInstance()->queryCountByCondition($condition);
        $orderList = OrderDao::getInstance()->queryListByCondition($condition, 0, $orderCount);
        foreach ($orderList as $orderData) {
            $ocode = $orderData["order_code"];
            $orderDict[$ocode] = array(
                "uname" => $orderData["user_name"],
                "uphone" => $orderData["user_phone"],
                "wpostal" => $orderData["war_postal"],
                "waddress" => "#" . $orderData["war_address"],
            );
        }
        //return array(false,1,"=".json_encode($orderDict));


        $wmsMessages = array();
        //处理仓库出单的脚本
        $invoiceService = new InvoiceService();
        foreach ($invoiceList as $ocode => $goodsList) {
            if (!empty($goodsList)) {
                $invoiceGoodsList = array();
                foreach ($goodsList as $goodsItem) {
                    $skuCount = floatval($goodsItem["sku_count"]);
                    $spuCount = floatval($goodsItem["spu_count"]);
                    $invoiceGoodsList[] = array(
                        "spCode" => $goodsItem["spu_code"],
                        "count" => bcmul($skuCount, $spuCount, 2),
                        "spCunit" => $goodsItem["spu_cunit"],
                        "skCode" => $goodsItem["sku_code"],
                        "skCount" => $goodsItem["sku_count"],
                        "goods_code" => $goodsItem["goods_code"],//用于收集实际采购价的外部ID
                    );
                }
                $orderData = $orderDict[$ocode];

                list($wmsIsSuccess, $message, $exGoodsCode2SpuBPrice) = $invoiceService->invoice_quick_create(array(
                    "data" => array(
                        "uname" => $orderData["uname"],
                        "phone" => $orderData["uphone"],
                        "address" => $orderData["waddress"],
                        "postal" => $orderData["wpostal"],
                        "mark" => "小程序单(自营)",
                        "ecode" => $ocode,//标示订单唯一的字段
                        "list" => $invoiceGoodsList,
                        "type" => 4,//出仓单类型为：销售出仓
                    )
                ));

                if (!empty($message)) {
                    $wmsMessages[] = $message;
                }
                if (!$wmsIsSuccess) return array(false, array(), implode(",", $wmsMessages));
                $isSuccess = $isSuccess && $wmsIsSuccess;
                //更新采购成本价格
                foreach ($goodsList as $goodsItem) {
                    $goodsCode = $goodsItem["goods_code"];
                    if (!array_key_exists($goodsCode, $exGoodsCode2SpuBPrice)) continue;
                    $spuBprice = $exGoodsCode2SpuBPrice[$goodsCode];/*货品出仓时的实际成本价*/
                    if ($spuBprice == $goodsItem["spu_bprice"]) continue;
                    $isSuccess = $isSuccess &&
                        $orderGoodsDao->updateBpriceByCode($goodsCode, $spuBprice);
                    if (!$isSuccess) return array(false, $orderGoodsDao->updateBpriceByCodeFetchSql($goodsCode, $spuBprice), "更新采购成本价格");
                }
                if (!$isSuccess) return array(false, $goodsList, "更新采购成本价格");
            }
        }
        //return array(false,1,"v1 =".($isSuccess?"1":"0").json_encode($exGoodsCode2SpuBPrice));


        //临时标记
        if ($isSuccess) {
            venus_db_commit();
            return array($isSuccess, "", "备货单导入处理成功，系统已经完成出库。<br>" .
                ($own2supFound ? "---------------------------------<br>另外，出现自营不足转直采的货品，请注意处理本分单任务中的直采数据" : "") .
                (empty($wmsMessages) ? "" : "<br>---------------------------------<br>仓库数据上发现缺货，信息如下：" . implode(",", $wmsMessages))
            );
        } else {
            venus_db_rollback();
            return array(false, "", "处理失败，请与IT组联系");
        }

    }


    //导出当前日期的属于分单任务列表中的所有订单的直采单总表（前提是当天分单任务列表中的所有分单任务都完成）
    //venus.wms.orderbatch.suplist.exportall
    public function suplist_exportall()
    {
        $post = $_POST['data'];
        $pdate = $post["pDate"];

        $condition = array("sctime" => "$pdate 00:00:00", "ectime" => "$pdate 23:59:59");
        $ordertaskSupCount = OrdertaskDao::getInstance()->queryCountByCondition(array_merge($condition, array("ownstatus" => 1)));
        if ($ordertaskSupCount > 0) {
            return array(false, "", '当前分单任务列表中存在未完成的自营备货任务，请先完成再导出总直采单');
        }
        $ordertaskCount = OrdertaskDao::getInstance()->queryCountByCondition($condition);
        $ordertaskList = OrdertaskDao::getInstance()->queryListByCondition($condition, 0, $ordertaskCount);
        $otCodeList = array_column($ordertaskList, "ot_code");
        $conditionOrder = array(
            "otcode" => array("in", $otCodeList),
            "wstatus" => 3
        );
        $orderCount = OrderDao::getInstance()->queryCountByCondition($conditionOrder);
        $orderList = OrderDao::getInstance()->queryListByCondition($conditionOrder, 0, $orderCount);
        foreach ($orderList as $index => $orderItem) {
            if ($orderItem["w_order_status"] != 3) {
                unset($orderList[$index]);
            }
        }
        $ocodes = array_unique(array_column($orderList, "order_code"));//提取所有ordercode
        if (empty($ocodes)) return array(false, array(), "今日无已处理订单");
        $condition = array("ocodes" => $ocodes, "supcode" => array('NEQ', self::$OWN_SUPCODE));
        $orderGoodsDao = OrdergoodsDao::getInstance();
        $goodsCount = $orderGoodsDao->queryCountByOrderCode($condition);
        $goodsList = $orderGoodsDao->queryListByCondition($condition, 0, $goodsCount);
        if (empty($goodsList)) return array(false, array(), "今日无已直采数据");

        $encrycode = date("总直采单_Y年m月d日");
        return $this->export_goodslist_excelfile($goodsList, 1, $encrycode);
    }

    //导出当前日期的属于分单任务列表中的所有订单的销售单总表（前提是当天分单任务列表中的所有分单任务都完成）
    //venus.wms.orderbatch.order.export
    public function order_export()
    {
        $post = $_POST['data'];
        $pdate = $post["pDate"];

        $condition = array("sctime" => "$pdate 00:00:00", "ectime" => "$pdate 23:59:59");
        $ordertaskCount = OrdertaskDao::getInstance()->queryCountByCondition($condition);
        $ordertaskList = OrdertaskDao::getInstance()->queryListByCondition($condition, 0, $ordertaskCount);
        $otCodeList = array_column($ordertaskList, "ot_code");
        $conditionOrder = array(
            "otcode" => array("in", $otCodeList),
            "wstatus" => 3
        );
        $orderCount = OrderDao::getInstance()->queryCountByCondition($conditionOrder);
        $orderList = OrderDao::getInstance()->queryListByCondition($conditionOrder, 0, $orderCount);
        foreach ($orderList as $index => $orderItem) {
            if ($orderItem["w_order_status"] != 3) {
                unset($orderList[$index]);
            }
        }
        $ocodes = array_unique(array_column($orderList, "order_code"));//提取所有ordercode
        $condition = array("ocodes" => $ocodes, "supcode" => array('NEQ', self::$SUP_SUPCODE3));
        $orderGoodsDao = OrdergoodsDao::getInstance();
        $goodsCount = $orderGoodsDao->queryCountByOrderCode($condition);
        $goodsList = $orderGoodsDao->queryListByCondition($condition, 0, $goodsCount);

        $orderDict = array();//所属订单数据字典
        //数据预处理
        $orderGoodsList = array();
        foreach ($goodsList as $goodsData) {
            $ocode = $goodsData["order_code"];
            $sputype = $goodsData["spu_type"];

            //************************************//
            $spusubtype = $goodsData["spu_subtype"];
//            //21090719注释，注释原因：仓库提出销售单需要大餐酸奶数据
//            if ($spusubtype == "10604") {
//                continue;
//            }

            //记录商品字典
            if (!isset($orderDict[$ocode])) {
                $orderDict[$ocode] = OrderDao::getInstance()->queryByCode($ocode);
            }
            if (!isset($orderGoodsList[$ocode])) {
                $orderGoodsList[$ocode] = array();
            }
            if (!isset($orderGoodsList[$ocode][$sputype])) {
                $orderGoodsList[$ocode][$sputype] = array();
            }
            $orderGoodsList[$ocode][$sputype][] = $goodsData;
        }
        //return array(false,count($goodsList),json_encode($orderGoodsList));

        //表格数据格式化
        $excelData = array();
        $orderGoodsData = array();
        foreach ($orderGoodsList as $ocode => $goodTypeList) {
            $orderData = $orderDict[$ocode];
            $warehouseName = $orderData["war_name"];
            $userName = $orderData["user_name"];
            $room = $orderData["room"];
            if (!empty($room)) {
                $warehouseName = $warehouseName . $room;
            }
            $orderGoodsData[$warehouseName][$orderData['order_pdate']][$ocode] = $goodTypeList;
        }
        $sellSeq = 1;
        foreach ($orderGoodsData as $warehouseName => $orderGoodsDatum) {
            foreach ($orderGoodsDatum as $pdate => $orderGoods) {
//                $seq = 0;
                $spuNameArr = array();
                foreach ($orderGoods as $ocode => $goodTypeList) {
                    foreach ($goodTypeList as $sputype => $goodsList) {
                        foreach ($goodsList as $goodsData) {
//                            $seq++;
                            //$goodsCode = $goodsData["goods_code"];
                            $goodsCount = floatval($goodsData["sku_init"]);
                            $spuName = $goodsData["spu_name"];
                            $skuUnit = $goodsData["sku_unit"];
                            $skuNorm = $goodsData["sku_norm"];
                            $spuBrand = $goodsData["spu_brand"];
                            $spusubtype = $goodsData["spu_subtype"];
                            $spuType = C("SPU_TYPE")[$sputype];
                            if ($spuType == "休闲食品" || $spuType == "酒水饮料") {
                                $sheetName = $warehouseName . "_休闲食品酒水饮料" . "_" . $pdate;
                            } elseif ($spusubtype == "10604") {
                                $sheetName = $warehouseName . "_大餐酸奶" . "_" . $pdate;
                            } else {
                                $sheetName = $warehouseName . "_" . $pdate;
                            }
                            if (empty($excelData[$sheetName])) {
                                $sellCode = "S" . date("Ymd", time()) . str_pad($sellSeq, 3, 0, STR_PAD_LEFT);
                                $excelData[$sheetName][] = array("送货日期：" . date("Y年m月d日", strtotime($pdate)), "客户名称：{$warehouseName}", "销售单编号：{$sellCode}");
                                $excelData[$sheetName][] = array('序号', '货品编号', '货品名称', '订货数量', "实收数量", "单位", "规格", "品牌", "类别", "单价", "订单编号");
                                $sellSeq++;
                            }
                            if (array_key_exists("spunum", $excelData[$sheetName])) {
                                $seq = count($excelData[$sheetName]) - 2;
                            } else {
                                $seq = count($excelData[$sheetName]) - 1;
                            }
                            $goodsPrice = $goodsData["spu_count"] * bcadd(floatval($goodsData["spu_sprice"]), floatval($goodsData["profit_price"]), 2);
                            $excelData[$sheetName][] = array($seq, '', $spuName, $goodsCount, '', $skuUnit, $skuNorm, $spuBrand, $spuType, $goodsPrice, $ocode);
                            if (!in_array($spuName, $spuNameArr[$sheetName])) {
                                $spuNameArr[$sheetName][] = $spuName;
                                $excelData[$sheetName]["spunum"] = count($spuNameArr[$sheetName]);
                            }
                        }
                    }
                }
            }
        }

        $encrycode = date("总销售单_Y年m月d日");
        $fileName = $this->excel_sell_format($excelData, $encrycode, "007");
        return array(!empty($fileName), $fileName, empty($fileName) ? "处理失败" : '');
    }

//导出用于出库的货品备货单,私有方法，只用于当前导出文件使用
//$datatype 0：备货单；1：总直采/大餐酸奶
    private
    function export_goodslist_excelfile($goodsList, $datatype = 0, $encrycode = "data")
    {
        list($title, $ordertype, $opertype, $from) =
            empty($datatype) ?
                array("北京世纪佳明科贸有限公司自营订货单", "拣货", "出库", "库位号") :
                array("北京世纪佳明科贸有限公司直采订货单", "订货", "直采", "供货商");
        $orderDict = array();//所属订单数据字典
        //数据预处理
        $goodsListForPrepared = array();
        foreach ($goodsList as $goodsData) {
            $ocode = $goodsData["order_code"];
            $sputype = $goodsData["spu_type"];
            //记录商品字典
            if (!isset($orderDict[$ocode])) {
                $orderDict[$ocode] = OrderDao::getInstance()->queryByCode($ocode);
            }
            //创建按一级分类的第一维度
            if (!isset($goodsListForPrepared[$sputype])) {
                $goodsListForPrepared[$sputype] = array();
            }
            //创建按公司名称的第二维度
            if (!isset($goodsListForPrepared[$sputype][$ocode])) {
                $goodsListForPrepared[$sputype][$ocode] = array();
            }
            $goodsListForPrepared[$sputype][$ocode][] = $goodsData;
        }
        //return array(false,count($goodsList),json_encode($goodsListForPrepared));

        //表格数据格式化
        $excelData = array();
        foreach ($goodsListForPrepared as $sputype => $subGoodList) {
            $seq = 0;//序号
            $excelData[] = array($title);
            $excelData[] = array(date("{$ordertype}日期：Y年m月d日"), '', '', '', "类别：{$opertype}，" . C("SPU_TYPE")[$sputype]);
            if (empty($datatype)) {
                $excelData[] = array('序号', '项目名称', '货品编号', '货品名称', "数量", "单位", "规格", "品牌", $from, "备注", empty($datatype) ? "实际拣货数量" : "");
            } else {
                $excelData[] = array('序号', '项目名称', '货品编号', "SKU编号", '货品名称', "数量", "单位", "规格", "采购单价", "采购金额", "品牌", $from, "备注", empty($datatype) ? "实际拣货数量" : "");
            }
            foreach ($subGoodList as $ocode => $goodsList) {
                foreach ($goodsList as $goodsData) {
                    $seq++;
                    $orderData = $orderDict[$ocode];
                    $warehouseName = $orderData["war_name"];
                    $orderData = $orderDict[$ocode];
                    $userName = $orderData["user_name"];
                    $room = $orderData["room"];
                    if (!empty($room)) {
                        $warehouseName = $warehouseName . $room;
                    }

                    $goodsCode = $goodsData["goods_code"];
                    $goodsCount = floatval($goodsData["sku_init"]);
                    $spuName = $goodsData["spu_name"];
                    $skuUnit = $goodsData["sku_unit"];
                    $skuNorm = $goodsData["sku_norm"];
                    $spuBrand = $goodsData["spu_brand"];
                    $supName = empty($datatype) ? "" : $goodsData["sup_name"];
                    $skuCode = $goodsData['sku_code'];
                    $skuBprice = floatval(bcmul($goodsData['spu_bprice'], $goodsData['spu_count'], 2));
                    $bprice = floatval(bcmul($skuBprice, $goodsCount, 2));
                    $excelData[] =
                        empty($datatype) ?
                            array($seq, $warehouseName, $goodsCode, $spuName, $goodsCount, $skuUnit, $skuNorm, $spuBrand, $supName) :
                            array($seq, $warehouseName, $goodsCode, $skuCode, $spuName, $goodsCount, $skuUnit, $skuNorm, $skuBprice, $bprice, $spuBrand, $supName);
                }
                $excelData[] = array("合计品类", '', '', count($goodsList));
            }
        }
        $excelFileData = array($encrycode => $excelData);
        $fileName = ExcelService::getInstance()->exportExcel($excelFileData, "", "002");
        return array(!empty($fileName), $fileName, empty($fileName) ? "处理失败" : '');
    }


//导出备货单，先省备货单文件
    public
    function ownlist_warehouse_export()
    {
        $post = $_POST['data'];
        $otcode = $post["otCode"];
        $condition = array("otcode" => $otcode, "supcode" => self::$OWN_SUPCODE);
        venus_db_starttrans();
        $orderGoodsDao = OrdergoodsDao::getInstance();
        $orderDao = OrderDao::getInstance();
        $otDao = OrdertaskDao::getInstance();

        $goodsCount = $orderGoodsDao->queryCountByOrderCode($condition);
        $goodsList = $orderGoodsDao->queryListByCondition($condition, 0, $goodsCount);
        $encrycode = $otcode . date("_备货单_Y.m.d");
        $isSuccess = true;
        //创建快速出仓单，并得到仓库数据中每个货品实际的出仓数量
        $warehouseService = new WarehouseService();
        $orderInfoData = array();
        $loginInfo = $this->getUserInfo();
        $warCode = $loginInfo["warCode"];
        $worCode = $loginInfo["worCode"];
        $spuModel=SpuDao::getInstance($warCode);
        $filePath = C("FILE_SAVE_PATH") . C("FILE_TYPE_NAME.INVOICE_ORDER_COLLECTION") . "/" . md5(urlencode($encrycode)) . ".xlsx";
        if (file_exists($filePath)) {
            return array(true, md5(urlencode($encrycode)) . ".xlsx", "文件已经存在");
        }
        $spuInfoArr = array();
        $orderGoodsData = array();
        foreach ($goodsList as $goodsData) {
            $orderData = $orderDao->queryByCode($goodsData['order_code']);
            if (!array_key_exists($goodsData['order_code'], $orderInfoData)) {
                $wname = $orderData["war_name"];
                $userName = $orderData["user_name"];
                $room = $orderData["room"];
                if (!empty($room)) {
                    $wname = $wname . $room;
                }
                $orderInfoData[$goodsData['order_code']] = array(
                    "uname" => $orderData["user_name"],
                    "uphone" => $orderData["user_phone"],
                    "wpostal" => $orderData["war_postal"],
                    "waddress" => "#" . $orderData["war_address"],
                    "wname" => $wname,
                );
            }

            $orderGoodsData[$goodsData['order_code']][] = array(
                "goodscode" => $goodsData['goods_code'],
                "skucode" => $goodsData['sku_code'],
                "spucode" => $goodsData['spu_code'],
                "skucount" => $goodsData['sku_count'],
            );
            if (!array_key_exists($goodsData['sku_code'], $spuInfoArr)) {
                $spuInfoArr[$goodsData['sku_code']] = array(
                    "name" => $goodsData['spu_name'],
                    "unit" => $goodsData['sku_unit'],
                    "norm" => $goodsData['sku_norm'],
                    "brand" => $goodsData['spu_brand'],
                    "type" => $goodsData['spu_type'],
                );
            }
        }
        $orderGoodsDataArr = array();
        $orderGoodsCodeSupArr = array();
        foreach ($orderGoodsData as $orderCode => $orderGoodsDatum) {
            $data = array(
                "type" => 4,//销售出仓
                "mark" => "小程序单(自营)",
                "warCode" => $warCode,
                "worCode" => $worCode,
                "receiver" => $orderInfoData[$orderCode]["uname"],
                "phone" => $orderInfoData[$orderCode]["uphone"],
                "address" => $orderInfoData[$orderCode]["waddress"],
                "postal" => $orderInfoData[$orderCode]["wpostal"],
                "list" => $orderGoodsDatum,
                "ecode" => $orderCode,
                "isallowlessgoods" => 1,
            );
            $createInvRes = $warehouseService->create_invoice($data);
            $isSuccess = $isSuccess && $createInvRes[0];
            if (!$isSuccess) return array(false, array(), $orderCode . $createInvRes[2]);
            $goodsListByInvData = $createInvRes[1];
            if (!empty($goodsListByInvData)) {
                foreach ($goodsListByInvData as $goodscode => $goodsListByInvDatum) {
                    foreach ($goodsListByInvDatum as $skucode => $count) {
                        $sputype = $spuInfoArr[$skucode]['type'];
                        $orderGoodsDataArr[$orderInfoData[$orderCode]["wname"]][$orderCode][$sputype][$goodscode] = array(
                            "name" => $spuInfoArr[$skucode]['name'],
                            "unit" => $spuInfoArr[$skucode]['unit'],
                            "norm" => $spuInfoArr[$skucode]['norm'],
                            "brand" => $spuInfoArr[$skucode]['brand'],
                            "count" => $count
                        );

                    }
                };
            } else {
                foreach ($orderGoodsDatum as $item) {
                    $orderGoodsCodeSupArr[$item["spucode"]][]=$item["goodscode"];
                }
            }

        }
        if (!empty($orderGoodsDataArr)) {
            //结合每个货品实际的出仓数量，和订单数据，生成excel文件，并用指定文件名保存到指定目录
            foreach ($orderGoodsDataArr as $warehouseName => $orderGoods) {
                $pCode = venus_unique_code("P");
                $sheetName = $encrycode;
                $excelData[$sheetName][] = array("制单时间日期：" . date("Y年m月d日 H:i:s", time()), "项目名称：{$warehouseName}", "拣货单编号：{$pCode}");
                $excelData[$sheetName][] = array('序号', "类别", '货品编号', '货品名称', '拣货数量', "单位", "规格", "品牌", "仓位号", "订单编号");
                $seq = 0;
                $spuNameArr = array();
                foreach ($orderGoods as $ocode => $goodTypeList) {
                    foreach ($goodTypeList as $sputype => $goodsList) {
                        foreach ($goodsList as $goodscode => $goodsData) {
                            $seq++;
                            $goodsCount = $goodsData["count"];
                            $spuName = $goodsData["name"];
                            $skuUnit = $goodsData["unit"];
                            $skuNorm = $goodsData["norm"];
                            $spuBrand = $goodsData["brand"];
                            $spuType = C("SPU_TYPE")[$sputype];

                            $excelData[$sheetName][] = array($seq, $spuType, $goodscode, $spuName, $goodsCount, $skuUnit, $skuNorm, $spuBrand, '', $ocode);
                            if (!in_array($spuName, $spuNameArr)) {
                                $spuNameArr[] = $spuName;
                            }
                        }
                    }
                }
                $excelData[$sheetName]["spunum"] += count($spuNameArr);
            }
            $fileName = $this->excel_sell_format($excelData, $encrycode, "008");
            //导出文件，共仓库备货使用
            if (!empty($fileName)) {
                venus_db_commit();
                return array(!empty($fileName), $fileName, '');
            } else {
                venus_db_rollback();
                return array(!empty($fileName), $fileName, "处理失败");
            }
        } else {
            $isSuccess = $isSuccess && $otDao->updateOwnStatusByCode($otcode, array_keys(self::$STATUS_DICTS, "无数据")[0]);
            foreach ($orderGoodsCodeSupArr as $spuCode=>$ogCodeArr) {
                $spuData=$spuModel->queryByCode($spuCode);
                $isSuccess = $isSuccess && $orderGoodsDao->updateBpriceByCode(array("in",$ogCodeArr), $spuData['spu_bprice']);
            }
            $orderCodeArr = array_keys($orderGoodsData);
            foreach ($orderCodeArr as $orderCode) {
                $isSuccess = $isSuccess && $orderGoodsDao->updateSupCodeByOrderCode($orderCode, "SU00000000000002");
            }
            $otDataNew = $otDao->queryByCode($otcode);
            if ($otDataNew['ot_supstatus'] == array_keys(self::$STATUS_DICTS, "无数据")[0]) {
                $isSuccess = $isSuccess && $otDao->updateSupStatusByCode($otcode, array_keys(self::$STATUS_DICTS, "未处理")[0]);
            }
            if ($isSuccess) {
                venus_db_commit();
                return array(false, array(), '无自营货品');
            } else {
                venus_db_rollback();
                return array(false, array(), '处理失败,请重新操作');
            }
        }
    }

    //回传备货单并确认出库
    public
    function ownlist_warehouse_finish()
    {
        $skuCodeArrWhite = array(
            "SK0000529",
            "SK0000536",
            "SK0001014",
            "SK0000530",
            "SK0000531",
            "SK0000766",
            "SK0000774",
            "SK0000775",
            "SK0000777",
            "SK0000780",
        );//检测spu最小计量单位小于0，倍数大于1（例如：黄瓜大条spucunit=0.1,spucount=2）

        //根据回传文件解析相应的数据和编号，做文件验证合法性的初步验证
        $datas = ExcelService::getInstance()->upload("file");
        $otcode = $_POST['otCode'];
        $encrycode = key($datas);
        $excelDataList = $datas[$encrycode];
        $excelotcode = explode("_", $encrycode)[0];
        //return array(false,1,$excelotcode."asdasdas".$otcode);

        if (!preg_match("/^OT\d{14}$/", $excelotcode)) {
            return array(false, "", "<span style='font-size:30px;color:red'>表格数据已经损坏，请与IT组联系解决</span>");
        }
        if ($excelotcode != $otcode) {
            return array(false, "", "<span style='font-size:30px;color:red'>备货单数据与当天分单任务不符</span>");
        }
        $otOrder = OrdertaskDao::getInstance()->queryByCode($otcode);
        if (empty($otOrder)) {
            return array(false, "", "<span style='font-size:30px;color:red'>系统检测无此分单任务[{$otcode}]，请与IT组联系解决</span>");
        }
        if ($otOrder["ot_ownstatus"] != '1') {
            return array(false, "", "<span style='font-size:30px;color:red'>系统检测当前分单任务已经处理，请勿重复提交</span>");
        }


        //根据$otcode，生成指定文件名，将指定文件名的备货单数据读入内存变量$fileDataList,接下来和$excelDataList数据对比
        $filePath = C("FILE_SAVE_PATH") . C("FILE_TYPE_NAME.INVOICE_ORDER_COLLECTION") . "/" . md5(urlencode($encrycode)) . ".xlsx";
        if (!file_exists($filePath)) {
            return array(false, array() . ".xlsx", "文件不存在");
        }
        $datas = ExcelService::getInstance()->getExcel($filePath);
        $encrycode = key($datas);
        $fileDataList = $datas[$encrycode];

        //return array(false, "", "调试中ownlist_warehouse_finish:" . json_encode($fileDataList));

        $orderGoodsDao = OrdergoodsDao::getInstance();
        $otDao = OrdertaskDao::getInstance();
        $orderDao = OrderDao::getInstance();
        $spuModel = SpuDao::getInstance($this->warCode);
        venus_db_starttrans();
        $isSuccess = true;
        $excelGoodsCodeData = array();
        foreach ($fileDataList as $index => $fileData) {
            if (!is_numeric($fileData["A"])) continue;//非数据项目
            $line = $index + 1;
            $excelData = $excelDataList[$index];//取出同行数据
            if (!preg_match('/^O\d{14}$/', $excelData["J"]) ||
                !preg_match('/^G\d{14}$/', $excelData["C"])) {
                return array(false, "", "<span style='font-size:18px;color:red'>第{$line}行：疑似表格数据【货品编号/订单号编号】已经损坏，请与IT组联系解决</span>");
            }

            $goodsName = trim($fileData["D"]);//商品名称
            $goodsCode = trim($fileData["C"]);//goods编号

            $goodsData = $orderGoodsDao->queryByCode($goodsCode);
            $spuCount = $goodsData["spu_count"];//规格倍数
            $spuCunit = $goodsData["spu_cunit"];//最小计量单位
            $skuCode = $goodsData["sku_code"];//sku编号
            $spuCode = $goodsData["spu_code"];//sku编号
            $oCode = $goodsData["order_code"];//sku编号

            $spuData = $spuModel->queryByCode($spuCode);

            $oSkuCount = floatval($goodsData["sku_init"]);//采购数量
            $skuInit = floatval(trim($fileData["E"]));//可备货数量
            $wSkuInit = floatval(trim($excelData["E"]));//实际备货数量

            //检测实际备货数量，及计划备货数量的关系
            if ($spuCunit == 1 && $wSkuInit > $skuInit) {
                return array(false, "", "第{$line}行：货品编码【 {$goodsCode} 】的商品<br>【 {$goodsName} 】数据异常<br><span style='font-size: 30px;color: red'>非抄码商品，实际备货数量应该<=计划备货数量！</span>");
            }
            if ($spuCunit == 1 && floor($wSkuInit) < $wSkuInit) {
                return array(false, "", "第{$line}行：货品编码【 {$goodsCode} 】的商品<br>【 {$goodsName} 】数据异常<br><span style='font-size: 30px;color: red'>非抄码商品，实际备货数量不应该有小数点</span>");
            }
            if (ceil(round(bcmod(($wSkuInit * 100), ($spuCunit * 100)))) > 0) {
                return array(false, "", "第{$line}行：货品编码【 {$goodsCode} 】的商品<br>【 {$goodsName} 】数据异常<br><span style='font-size: 30px;color: red'>最小计数单位有误，请修正后提交</span>");
            }

            if ($spuCunit < 1 && $spuCount > 1 && intval($wSkuInit) != $wSkuInit && !in_array($skuCode, $skuCodeArrWhite)) {
                return array(false, "", "第{$line}行：货品编码【 {$goodsCode} 】的商品<br>【 {$goodsName} 】数据异常<br><span style='font-size: 30px;color: red'>规格与最小最小计数单位不符，请修正后提交</span>");
            }

            //检测出仓单中货品数据是否需要调整，及相关调整
            if ($wSkuInit != $skuInit) {
                if ($wSkuInit > $skuInit) {//如果实际备货数量大于可备货数量(经过前面验证，此情况必定是抄码)
                    $updateInvoiceData = array(
                        "warCode" => "WA000001",
                        "ecode" => $oCode,
                        "mark" => "小程序单(自营)",
                        "type" => 7,
                        "skuCode" => $skuCode,
                        "skuCount" => bcsub($skuInit, $wSkuInit, 2),
                        "isRegular" => 1,
                    );
                } else {
                    $updateInvoiceData = array(
                        "warCode" => "WA000001",
                        "ecode" => $oCode,
                        "mark" => "小程序单(自营)",
                        "type" => 1,
                        "skuCode" => $skuCode,
                        "skuCount" => bcsub($skuInit, $wSkuInit, 2),
                        "isRegular" => 1,
                    );
                }
                $warehouseService = new WarehouseService();
                $updateInvoice = $warehouseService->update_invoice_goods($updateInvoiceData);
                $isSuccess = $isSuccess && $updateInvoice[0];
                if (!$updateInvoice[0] && empty($message)) $message = $updateInvoice[2];
            }

            //检测是否存在自营转直采的数据，及相应处理（注意对超码商品的操作）
            if ($wSkuInit != 0) {
                if ($oSkuCount > $wSkuInit) {
                    $supSkuCount = bcsub($oSkuCount, $wSkuInit, 2);
                    $insertOgData = array(
                        "count" => bcmul($supSkuCount, $spuCount, 2),
                        "skuinit" => $supSkuCount,
                        "skucode" => $skuCode,
                        "spucount" => $spuCount,
                        "spucode" => $goodsData['spu_code'],
                        "supcode" => "SU00000000000002",
                        "ocode" => $oCode,
                        "otcode" => $otcode,
                        "pproprice" => $spuData['profit_price'],
                        "bprice" => $spuData['spu_bprice'],
                        "sprice" => $goodsData['spu_sprice'],
                        "warcode" => $goodsData['war_code'],
                        "ucode" => $goodsData['user_code'],
                    );
                    $isSuccess = $isSuccess &&
                        $orderGoodsDao->insert($insertOgData);
                }

                if ($oSkuCount != $wSkuInit) {
                    $updateOgSkuCount = $wSkuInit;
                    $updateOgCount = bcmul($updateOgSkuCount, $spuCount, 2);
                    $isSuccess = $isSuccess &&
                        $orderGoodsDao->updateCountAndSkuinitAndSkucountByCode($goodsCode, $updateOgSkuCount, $updateOgCount);
                    $isSuccess = $isSuccess &&
                        $orderGoodsDao->updateWskuCountByCode($goodsCode, $updateOgSkuCount);
                }

                //将当前货品状态变为仓内已处理
                $isSuccess = $isSuccess &&
                    OrdergoodsDao::getInstance()->updateWStatusByCode($goodsCode, 3);
                //更新自营货品实际加权平均采购价
                $igsBprice = $this->getOwnBpriceData($oCode, $spuCode);
                if ($igsBprice != $goodsData['spu_bprice']) {
                    $updateOgBprice = $orderGoodsDao->updateBpriceByCode($goodsCode, $igsBprice);
                    $isSuccess = $isSuccess && $updateOgBprice;
                    if (!$isSuccess && empty($message)) $message = $goodsCode . "修改采购价失败";
                }
                $excelGoodsCodeData[] = $goodsCode;
            } else {
                $isSuccess = $isSuccess && $orderGoodsDao->updateBpriceByCode($goodsCode, $spuData['spu_bprice']);
                $isSuccess = $isSuccess && $orderGoodsDao->updateSupCodeByCode($goodsCode, "SU00000000000002");
            }

        }

        $diffOrdergoodsCodeData = $this->getDiffOrdergoodsCode($otcode, $excelGoodsCodeData);
        if (!empty($diffOrdergoodsCodeData)) {
            foreach ($diffOrdergoodsCodeData as $diffOrdergoodsCodeDatum) {
                $goodsData=$orderGoodsDao->queryByCode($diffOrdergoodsCodeDatum);
                $spuData=$spuModel->queryByCode($goodsData['spu_code']);
                $isSuccess = $isSuccess && $orderGoodsDao->updateBpriceByCode($diffOrdergoodsCodeDatum, $spuData['spu_bprice']);
                $isSuccess = $isSuccess && $orderGoodsDao->updateSupCodeByCode($diffOrdergoodsCodeDatum, "SU00000000000002");
            }
        }
        //判断是否有未处理的自营货品
        $issetOwnOrdergoodsData = $orderGoodsDao->queryListByCondition(array("supcode" => "SU00000000000001", "otcode" => $otcode, "wstatus" => 2), 0, 10000);
        if (empty($issetOwnOrdergoodsData)) {
            $isSuccess = $isSuccess &&
                $otDao->updateOwnStatusByCode($otcode, array_keys(self::$STATUS_DICTS, "已处理")[0]);
        } else {
            venus_db_rollback();
            return array($isSuccess, $issetOwnOrdergoodsData, "仍有自营货品未出库");
        }
        //是否有直采商品
        $ordergoodsSupClause = array(
            "otcode" => $otcode,
            "supcode" => array("neq", "SU00000000000001")
        );
        $issetSupOrdergoodsData = $orderGoodsDao->queryListByCondition($ordergoodsSupClause);
        if (!empty($issetSupOrdergoodsData)) {
            $otData = $otDao->queryByCode($otcode);
            if ($otData['ot_supstatus'] == array_keys(self::$STATUS_DICTS, "无数据")[0]) {
                $supStatus = array_keys(self::$STATUS_DICTS, "未处理")[0];
                $isSuccess = $isSuccess &&
                    $otDao->updateSupStatusByCode($otcode, $supStatus);
            }
        } else {
            //在不存在直采货品的情况下更新订单总价格包括采购价，销售价，销售利润，客户利等等
            $clauseOrder = array(
                "otcode" => $otcode
            );
            $ocodeCount = $orderDao->queryCountByCondition($clauseOrder);
            $ocodeList = $orderDao->queryListByCondition($clauseOrder, 0, $ocodeCount);
            $ocodeArr = array_column($ocodeList, "order_code");
            foreach ($ocodeArr as $ocode) {
                $issetOrdergoodsList = OrdergoodsDao::getInstance()->queryListByOrderCode($ocode, 0, 10000);
                $uptOrderData = \Common\Service\OrderService::getInstance()->updatePrice($issetOrdergoodsList);
                $uptOrderRes = OrderDao::getInstance()->updatePriceByCode(
                    $ocode, $uptOrderData['totalBprice'],
                    $uptOrderData['totalSprice'], $uptOrderData['totalSprofit'], $uptOrderData['totalCprofit'], $uptOrderData['totalTprice']);
                $isSuccess = $isSuccess && $uptOrderRes;
                if (!$isSuccess && empty($message)) $message = "同步订单价格失败";
            }

        }
        if (empty($issetOwnOrdergoodsData) && empty($issetSupOrdergoodsData)) {
            $isSuccess = $isSuccess && $orderDao->updateWStatusByOtCode($otcode, 3);
        }
        if ($isSuccess) {
            venus_db_commit();
            return array($isSuccess, array(), "确认出库成功");
        } else {
            if (!empty($message)) {
                venus_db_rollback();
                return array($isSuccess, array(), $message);
            } else {
                venus_db_rollback();
                return array($isSuccess, array(), "确认出库失败");
            }
        }
    }

    //导出当前日期的属于分单任务列表中的所有已完成订单的销售单总表（前提是订单已经验收完成）
    //venus.wms.orderbatch.order.finish.export
    public function order_finish_export()
    {
        $post = $_POST['data'];
        $oCodes = $post["ocodes"];
        if (empty($oCodes)) return array(false, array(), "请选择最终销售单订单");
        $condition = array("oCodes" => $oCodes);
        $orderCount = OrderDao::getInstance()->queryCountByCondition($condition);
        $orderList = OrderDao::getInstance()->queryListByCondition($condition, 0, $orderCount);
        foreach ($orderList as $index => $orderItem) {
            $ocode = $orderItem['order_code'];
            if ($orderItem["order_status"] == 3) {
                unset($orderList[$index]);
                continue;
            }
            if ($orderItem["w_order_status"] != 3) {
                return array(false, array(), $ocode . ":该订单未处理不是最终订单");
            }
            if ($orderItem["w_order_status"] == 3 && $orderItem["order_status"] == 1) {
                return array(false, array(), $ocode . ":该订单未验货不是最终订单");
            }
            if ($orderItem["w_order_status"] == 3 && $orderItem["order_status"] == 4) {
                return array(false, array(), $ocode . ":该订单验货中不是最终订单");
            }
            if ($orderItem["is_finalsalesorder"] != 2) {
                return array(false, array(), $ocode . ":该订单不是最终订单");
            }
        }
        $ocodes = array_unique(array_column($orderList, "order_code"));//提取所有ordercode
        if (empty($ocodes)) return array(false, array(), "当前所选日期无已验货完成并已经无退货单的订单");
        $condition = array("ocodes" => $ocodes, "supcode" => array('NEQ', self::$SUP_SUPCODE3));
        $orderGoodsDao = OrdergoodsDao::getInstance();
        $goodsCount = $orderGoodsDao->queryCountByOrderCode($condition);
        $goodsList = $orderGoodsDao->queryListByCondition($condition, 0, $goodsCount);
        if (empty($goodsList)) return array(false, array(), "当前所选日期无已验货完成并已经无退货单的非鲜鱼水菜订单");

        $isSuccess = true;
        venus_db_starttrans();
        $orderDict = array();//所属订单数据字典
        //数据预处理
        $orderGoodsList = array();
        foreach ($goodsList as $goodsData) {
            $ocode = $goodsData["order_code"];
            $sputype = $goodsData["spu_type"];

            //************************************//
            $spusubtype = $goodsData["spu_subtype"];
            if ($spusubtype == "10604") {
                continue;
            }

            //记录商品字典
            if (!isset($orderDict[$ocode])) {
                $orderDict[$ocode] = OrderDao::getInstance()->queryByCode($ocode);
            }
            if (!isset($orderGoodsList[$ocode])) {
                $orderGoodsList[$ocode] = array();
            }
            if (!isset($orderGoodsList[$ocode][$sputype])) {
                $orderGoodsList[$ocode][$sputype] = array();
            }
            $orderGoodsList[$ocode][$sputype][] = $goodsData;
        }


        //表格数据格式化
        $excelData = array();
        $orderGoodsData = array();
        foreach ($orderGoodsList as $ocode => $goodTypeList) {
            $orderData = $orderDict[$ocode];
            $warehouseName = $orderData["war_name"];
            $userName = $orderData["user_name"];
            $room = $orderData["room"];
            if (!empty($room)) {
                $warehouseName = $warehouseName . $room;
            }
            $orderGoodsData[$warehouseName][$orderData['order_pdate']][$ocode] = $goodTypeList;
            $isSuccess = $isSuccess && OrderDao::getInstance()->updateIsDownloadByCode($ocode, 2);
        }
        $sellSeq = 1;
        foreach ($orderGoodsData as $warehouseName => $orderGoodsDatum) {
            foreach ($orderGoodsDatum as $pdate => $orderGoods) {
                $sellCode = "S" . date("Ymd", strtotime($pdate)) . str_pad($sellSeq, 3, 0, STR_PAD_LEFT);
                $sheetName = $warehouseName . "_" . $pdate;
                $excelData[$sheetName][] = array("送货日期：" . date("Y年m月d日", strtotime($pdate)), "客户名称：{$warehouseName}", "销售单编号：{$sellCode}");
                $excelData[$sheetName][] = array('序号', '货品名称', '订货数量', "实收数量", "单位", "规格", "品牌", "类别", "单价", '总金额', "订单编号");
                $seq = 0;
                $spuNameArr = array();
                foreach ($orderGoods as $ocode => $goodTypeList) {
                    foreach ($goodTypeList as $sputype => $goodsList) {
                        foreach ($goodsList as $goodsData) {
                            $seq++;
                            //$goodsCode = $goodsData["goods_code"];
                            $goodsInit = floatval($goodsData["sku_init"]);
                            $goodsCount = floatval($goodsData["sku_count"]);
                            $spuName = $goodsData["spu_name"];
                            $skuUnit = $goodsData["sku_unit"];
                            $skuNorm = $goodsData["sku_norm"];
                            $spuBrand = $goodsData["spu_brand"];
                            $spuType = C("SPU_TYPE")[$sputype];

                            $goodsPrice = floatval(bcmul($goodsData["spu_count"], bcadd(floatval($goodsData["spu_sprice"]), floatval($goodsData["profit_price"]), 2), 6));;
                            $goodsMoney = floatval(bcmul($goodsPrice, $goodsCount, 6));
                            $excelData[$sheetName][] = array($seq, $spuName, $goodsInit, $goodsCount, $skuUnit, $skuNorm, $spuBrand, $spuType, $goodsPrice, $goodsMoney, $ocode);
                            if (!in_array($spuName, $spuNameArr)) {
                                $spuNameArr[] = $spuName;
                            }
                        }
                    }
                }
                $excelData[$sheetName]["spunum"] = count($spuNameArr);
                $sellSeq++;
            }
        }
        if ($isSuccess) {
            $encrycode = date("验收后销售单_Y年m月d日", strtotime($pdate));
            $fileName = $this->excel_sell_format($excelData, $encrycode, "0071");
            if (!empty($fileName)) {
                venus_db_commit();
            } else {
                venus_db_rollback();
            }
            return array(!empty($fileName), $fileName, empty($fileName) ? "处理失败" : '');
        } else {
            venus_db_rollback();
            return array(false, array(), "标记已处理状态失败");
        }

    }

    private
    function getUserInfo()
    {
        $workerData = PassportService::getInstance()->loginUser();
        if (empty($workerData)) {
            venus_throw_exception(110);
        }
        return array(
            'warCode' => $workerData["war_code"],
            'worCode' => $workerData["wor_code"],
            'worName' => $workerData["wor_name"],
            'worRname' => $workerData["wor_rname"],
        );
    }

    private
    function excel_sell_format($data, $encrycode, $typeName)
    {
        $template = C("FILE_TPLS") . $typeName . ".xlsx";
        $saveDir = C("FILE_SAVE_PATH") . $typeName;

        $fileName = md5(urlencode($encrycode)) . ".xlsx";
        vendor('PHPExcel.class');
        vendor('PHPExcel.IOFactory');
        vendor('PHPExcel.Writer.Excel2007');
        vendor("PHPExcel.Reader.Excel2007");
        $objReader = new \PHPExcel_Reader_Excel2007();
        $objPHPExcel = $objReader->load($template);    //加载excel文件,设置模板

        $templateSheet = $objPHPExcel->getSheet(0);


        foreach ($data as $sheetName => $list) {

            $excelSheet = $templateSheet->copy();

            $excelSheet->setTitle($sheetName);
            //创建新的工作表
            $sheet = $objPHPExcel->addSheet($excelSheet);
            $addLine = count($list) - 3;
            $sheet->insertNewRowBefore(3, $addLine);   //在行3前添加n行
            $endLine = count($list) + 2;
            if ($typeName == "007" || $typeName == "0071") {
                $sheet->getStyle("A3:K{$endLine}")->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);//水平方向中间居中
                $sheet->getStyle("A3:K{$endLine}")->getAlignment()->setVertical(\PHPExcel_Style_Alignment::VERTICAL_CENTER);//垂直方向上中间居中
            }
            if ($typeName == "008") {
                $sheet->getStyle("A3:J{$endLine}")->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);//水平方向中间居中
                $sheet->getStyle("A3:J{$endLine}")->getAlignment()->setVertical(\PHPExcel_Style_Alignment::VERTICAL_CENTER);//垂直方向上中间居中
            }
            $line = 3;
            $sumSpuValue = $list['spunum'];
            if ($typeName == "007" && strstr($list[0][0], "送货") == true) {
                $sheet->setCellValue("A2", $list[0][0]);
                $sheet->setCellValue("D2", $list[0][1]);
                $sheet->setCellValue("I2", $list[0][2]);
            }
            if ($typeName == "0071" && strstr($list[0][0], "送货") == true) {
                $sheet->setCellValue("A2", $list[0][0]);
                $sheet->setCellValue("C2", $list[0][1]);
                $sheet->setCellValue("H2", $list[0][2]);
            }
            if ($typeName == "008" && strstr($list[0][0], "制单") == true) {
                $sheet->setCellValue("A2", $list[0][0]);
                $sheet->setCellValue("E2", $list[0][1]);
                $sheet->setCellValue("H2", $list[0][2]);
            }
            foreach ($list as $index => $arr) {
                if ($index == "spunum") continue;
                if ($index == 0) continue;
                $lettersLength = count($arr);
                $letters = array();
                for ($letter = 0; $letter < $lettersLength; $letter++) {
                    $letters[] = $this->getLettersCell($letter);
                }
                //输出数据
                foreach ($arr as $i => $value) {
                    if ($typeName == "008" && strstr($value, "制单") == true) {
                        $sheet->mergeCells("A{$line}:D{$line}");
                        $sheet->mergeCells("E{$line}:G{$line}");
                        $sheet->mergeCells("H{$line}:J{$line}");
                        $sheet->getStyle("A{$line}:J{$line}")->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_LEFT);//水平方向中间居左
                        $sheet->setCellValue("A{$line}", $arr[0]);
                        $sheet->setCellValue("E{$line}", $arr[1]);
                        $sheet->setCellValue("H{$line}", $arr[2]);
                    } else {
                        if ($typeName == "008" && strstr($arr[0], "制单") == true) {
                            continue;
                        } else {
                            $sheet->setCellValue("$letters[$i]$line", $value);
                        }
                    }

                }
                $line++;
            }
            if ($typeName == "007") {
                $sheet->setCellValue("C{$line}", $sumSpuValue);
            }
            if ($typeName == "0071") {
                $sheet->setCellValue("B{$line}", $sumSpuValue);
                $moneyLastLine = $line - 1;
                $sheet->setCellValue("J{$line}", "=SUM(J4:J{$moneyLastLine})");
            }
            if ($typeName == "008") {
                $sheet->setCellValue("D{$line}", $sumSpuValue);
            }

        }
        //移除多余的工作表
        $objPHPExcel->removeSheetByIndex(0);
        //设置保存文件名字

        $objWriter = \PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');

        if (!file_exists($saveDir)) {
            mkdir("$saveDir");
        }
        $objWriter->save($saveDir . "/" . $fileName);
        return $fileName;
    }

    /**
     * @param $otCode
     * @param $excelOwnOrdergoods
     * @return array
     * 导出自营备货单后，回传备货单时获取表格与ordergoods列表缺少的goodsCode数组
     */
    private function getDiffOrdergoodsCode($otCode, $excelOwnOrdergoods)
    {
        $orderGoodsDao = OrdergoodsDao::getInstance();
        $issetOwnOrdergoodsData = $orderGoodsDao->queryListByCondition(array("supcode" => "SU00000000000001", "otcode" => $otCode), 0, 10000);
        $ordergoodsCodeArr = array_column($issetOwnOrdergoodsData, "goods_code");
        $diffOgCodeArr = array_diff($ordergoodsCodeArr, $excelOwnOrdergoods);
        return $diffOgCodeArr;
    }

    private function getLettersCell($letter)
    {
        $y = $letter / 26;
        if ($y >= 1) {
            $y = intval($y);
            return chr($y + 64) . chr($letter - $y * 26 + 65);
        } else {
            return chr($letter + 65);
        }
    }

    /**
     * @param $orderCode
     * @param $spuCode
     * @return float
     * 通过订单编号和spu编号获取实际加权平均采购价
     */
    private function getOwnBpriceData($orderCode, $spuCode)
    {
        $igoodsentModel = IgoodsentDao::getInstance($this->warCode);
        //通过订单编号和spu编号获取自营出仓批次总条数
        $igsCount = $igoodsentModel->queryOwnCountByOcodeAndSpuCode($orderCode, $spuCode);
        //通过订单编号和spu编号获取自营出仓批次列表
        $igsData = $igoodsentModel->queryOwnListByOcodeAndSpuCode($orderCode, $spuCode, $igsCount);

        //计算实际加权平均采购价
        $sum = 0;//总采购价
        $count = 0;//总数量
        foreach ($igsData as $igsDatum) {
            $sum = floatval(bcadd($sum, bcmul($igsDatum['igs_count'], $igsDatum['igs_bprice'], 4), 4));
            $count = floatval(bcadd($count, $igsDatum['igs_count'], 4));
        }
        return floatval(bcdiv($sum, $count, 2));

    }

    //导出当前日期的属于分单任务列表中的所有订单的大餐酸奶总表（前提是当天分单任务列表中的所有分单任务都处于创建及已完成状态）
    //venus.wms.orderbatch.suplist.yogurt.exportall
    public function suplist_yogurt_exportall()
    {
        $post = $_POST['data'];
        $pdate = $post["pDate"];

        $condition = array("sctime" => "$pdate 00:00:00", "ectime" => "$pdate 23:59:59");
//        $ordertaskSupCount = OrdertaskDao::getInstance()->queryCountByCondition(array_merge($condition));
//        if ($ordertaskSupCount > 0) {
//            return array(false, "", '当前分单任务列表中存在未完成的自营备货任务，请先完成再导出总直采单');
//        }
        $ordertaskCount = OrdertaskDao::getInstance()->queryCountByCondition($condition);
        $ordertaskList = OrdertaskDao::getInstance()->queryListByCondition($condition, 0, $ordertaskCount);
        $otCodeList = array_column($ordertaskList, "ot_code");
        $conditionOrder = array(
            "otcode" => array("in", $otCodeList),
        );
        $orderCount = OrderDao::getInstance()->queryCountByCondition($conditionOrder);
        $orderList = OrderDao::getInstance()->queryListByCondition($conditionOrder, 0, $orderCount);
        foreach ($orderList as $index => $orderItem) {
            if ($orderItem["w_order_status"] == 1 || $orderItem["w_order_status"] == 4 || empty($orderItem["ot_code"])) {
                unset($orderList[$index]);
            }
        }
        $ocodes = array_unique(array_column($orderList, "order_code"));//提取所有ordercode
        if (empty($ocodes)) return array(false, array(), "目前今日无大餐酸奶数据");

        $condition = array(
            "ocodes" => $ocodes,
            "supcode" => array('NEQ', self::$OWN_SUPCODE),
            "spusubtype" => "10604"
        );
        $orderGoodsDao = OrdergoodsDao::getInstance();
        $goodsCount = $orderGoodsDao->queryCountByOrderCode($condition);
        $goodsList = $orderGoodsDao->queryListByCondition($condition, 0, $goodsCount);
        if (empty($goodsList)) return array(false, array(), "目前今日无大餐酸奶数据");
        $encrycode = date("大餐酸奶_Y年m月d日");
        return $this->export_goodslist_excelfile($goodsList, 1, $encrycode);
    }

    //按钮时间控制
    public function button_time($pdate)
    {
        $stime = $pdate . " " . self::$downloadStime;
        $etime = $pdate . " " . self::$downloadEtime;
        $time = $pdate . date(" H:i:s", time());

        if ($time < $stime || $time > $etime) {
            return array(false, array(), "当天" . self::$downloadStime . "-" . self::$downloadEtime . "可下载数据");
        }
    }
}




