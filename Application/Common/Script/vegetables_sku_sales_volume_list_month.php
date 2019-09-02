<?php
ini_set('memory_limit', '2028M');
define('APP_DIR', dirname(__FILE__) . '/../../../');
define('APP_DEBUG', true);
define('APP_MODE', 'cli');
define('APP_PATH', APP_DIR . './Application/');
define('RUNTIME_PATH', APP_DIR . './Runtime_script/'); // 系统运行时目录
require APP_DIR . './ThinkPHP/ThinkPHP.php';

use Common\Service\ExcelService;

$time = venus_script_begin("鲜鱼水菜sku（市价）月销量");
$stime = "2019-05-24 00:00:00";
$etime = "2019-06-24 00:00:00";

$dbName = "zwdb_wms";
$spuSql = "SELECT * FROM $dbName.wms_spu spu LEFT JOIN $dbName.wms_sku sku ON sku.spu_code = spu.spu_code";
$spuSql .= " WHERE spu.spu_type = 116";
$spuList = M()->query($spuSql);

$goodsSql = "SELECT SUM(sku_count) as sku_count, sku.sku_code, sku.sku_norm, sku.sku_unit, spu.spu_name, spu.spu_type, 
              spu.spu_subtype, goods.spu_count, goods.spu_bprice, goods.spu_sprice, goods.profit_price FROM $dbName.wms_order o 
              LEFT JOIN $dbName.wms_ordergoods goods ON goods.order_code = o.order_code";
$goodsSql .= " LEFT JOIN $dbName.wms_sku sku ON sku.sku_code = goods.sku_code";
$goodsSql .= " LEFT JOIN $dbName.wms_spu spu ON spu.spu_code = goods.spu_code";
$goodsSql .= " LEFT JOIN $dbName.wms_supplier sup ON sup.sup_code = goods.supplier_code";
$goodsSql .= " WHERE o.order_ctime >= '$stime' AND o.order_ctime <= '$etime'";
$goodsSql .= " AND spu.spu_type = 116 AND goods.spu_sprice = 0 AND goods.sku_count != 0";
$goodsSql .= " AND o.order_is_external = 1 AND o.w_order_status = 3 group by sku.sku_code";//AND goods.supplier_code = 'SU00000000000001'
$orderGoodsList = M()->query($goodsSql);
$ordergoodsSkuCodeData = array_column($orderGoodsList, "sku_code");
$skuCountList = array_column($orderGoodsList, "sku_count");
$skuCountList = array_unique($skuCountList);
rsort($skuCountList);
$skuCountList = array_values($skuCountList);
$skuDataList = array();
$spuTypeSort = array();
$spuSubTypeSort = array();
$allLine = 0;
foreach ($orderGoodsList as $item) {
    $skCount = $item['sku_count'];
    $key = array_keys($skuCountList, $skCount);
    $skuDataList[$item['sku_code']] = $key[0] + 1;
    if ($allLine < $key[0] + 1) $allLine = $key[0] + 1;
    $spuTypeSort[$item['spu_type']][$item['sku_code']] = $skCount;
    $spuSubTypeSort[$item['spu_subtype']][$item['sku_code']] = $skCount;
}
$skuCountTypedata = array();
foreach ($spuTypeSort as $type => $oitem) {
    $skuCountArrOne = array_values($oitem);
    $skuCountArrOne = array_unique($skuCountArrOne);
    rsort($skuCountArrOne);
    $skuCountArrOne = array_values($skuCountArrOne);
    $skuCountTypedata[$type] = $skuCountArrOne;
}
$skuCountSubTypedata = array();
foreach ($spuSubTypeSort as $subtype => $oitem) {
    $skuCountArrTwo = array_values($oitem);
    $skuCountArrTwo = array_unique($skuCountArrTwo);
    rsort($skuCountArrTwo);
    $skuCountArrTwo = array_values($skuCountArrTwo);
    $skuCountSubTypedata[$subtype] = $skuCountArrTwo;
}

$skuData = array();
foreach ($orderGoodsList as $index => $spuItem) {
    $spuType = $spuItem['spu_type'];
    $spuSubType = $spuItem['spu_subtype'];
    $skCount = $spuItem['sku_count'];
    $key = array_keys($skuCountTypedata[$spuType], $skCount);
    $keys = array_keys($skuCountSubTypedata[$spuSubType], $skCount);
    $sprice = $spuItem['spu_sprice'];
    $spcount = $spuItem['spu_count'];
    $pprice = $orderDatum['profit_price'];
    $skSprice = venus_calculate_sku_price_by_spu($sprice, $spcount, $pprice);
    $totalTprice = bcmul($skSprice, $skCount, 4);//销售总价
    $bprice = $spuItem['spu_bprice'];
    $skBprice = bcmul($bprice, $spcount, 4);
    $totalBprice = bcmul($skBprice, $skCount, 4);//采购总价
    $grossProfit = bcsub($totalTprice, $totalBprice, 4);//毛利
    $grossProfitMargin = bcdiv($grossProfit, $totalTprice, 4);//毛利率
    $skuData[$skuDataList[$spuItem['sku_code']]][] = array(
        $spuItem['spu_subtype'], $key[0] + 1, $spuItem['sku_code'], $spuItem['spu_name'],
        $spuItem['sku_norm'], $spuItem['sku_unit'], $spuItem['sku_count'], $totalTprice, $totalBprice, $grossProfit, $grossProfitMargin, $spuItem['spu_type']
    );
}

$fname = "SKU销量排行榜";
$header = array("二级分类","sku编号", "sku名称", "sku规格", "销售总量", "sku计量单位");// "一级分类销量排名", "采购总额", "销售总额", "毛利", "毛利率"
foreach ($spuList as $index => $spuItem) {
    $skCode = $spuItem['sku_code'];
    if (!in_array($skCode, $ordergoodsSkuCodeData)) {
//        $skuCount = empty($spuItem['sku_count']) ? 0 : $spuItem['sku_count'];
        if(!empty($spuItem['sku_count'])){
            $skuCount = $spuItem['sku_count'];
            $sprice = $spuItem['spu_sprice'];
            $spcount = $spuItem['spu_count'];;
            $pprice = $orderDatum['profit_price'];
            $skSprice = venus_calculate_sku_price_by_spu($sprice, $spcount, $pprice);
            $totalTprice = bcmul($skSprice, $spuItem['sku_count'], 4);//销售总额
            $bprice = $spuItem['spu_bprice'];
            $skBprice = bcmul($bprice, $spcount, 4);
            $totalBprice = bcmul($skBprice, $skuCount, 4);//采购总额
            $grossProfit = bcsub($totalTprice, $totalBprice, 4);//毛利
            $grossProfitMargin = bcdiv($grossProfit, $totalTprice, 4);//毛利率
            $skuData[($allLine + 1)][] = array(
                $spuItem['spu_subtype'], (count($skuCountTypedata[$spuItem['spu_type']]) + 1), $spuItem['sku_code'],
                $spuItem['spu_name'], $spuItem['sku_norm'], $spuItem['sku_unit'], $skuCount, $totalTprice, $totalBprice, $grossProfit, $grossProfitMargin, $spuItem['spu_type']
            );
        }

    }

}

$excelData = array();
$skTotalSalesList = array();
foreach ($skuData as $skuDatum) {
    foreach ($skuDatum as $index => $item) {
        $excelData[] = array(
            "spSubtype" => $item[0],
            "spType" => $item[1],
            "skCode" => $item[2],
            "spName" => $item[3],
            "skNorm" => $item[4],
            "skUnit" => $item[5],
            "skCount" => $item[6],
            "totalTprice" => $item[7],
            "totalBprice" => $item[8],
            "grossProfit" => $item[9],
            "grossProfitMargin" => $item[10],
            "spuType" => $item[11],
        );
    }
}
$codes = array();
$totalSalesRanking = array_column($excelData, 'skCode');
array_multisort($totalSalesRanking, SORT_ASC, $excelData);
foreach ($excelData as $index => $val) {
    $grossProfitMargin = empty($val['grossProfitMargin']) ? 0 : $val['grossProfitMargin'];
    $lists = array(
        venus_spu_catalog_name($val['spSubtype']),
//        $val['spType'],
        $val['skCode'],
        $val['spName'],
        $val['skNorm'],
        $val['skCount'],
        $val['skUnit'],
//        $val['totalTprice'],
//        $val['totalBprice'],
//        $val['grossProfit'],
//        $grossProfitMargin,
    );
    if (in_array($val['spuType'], $codes)) {
        $skTotalSalesList[$val['spuType']][] = $lists;
    } else {
        $spuTypeName = venus_spu_type_name($val['spuType']);
        $skTotalSalesList[$spuTypeName][]= $lists;
        $codes[] = $val['skCode'];
    }
}

$fileName = ExcelService::getInstance()->exportExcel($skTotalSalesList, $header, "001");

if ($fileName) {
    $title = "鲜鱼水菜sku（市价）月销量";
    $content = "鲜鱼水菜sku（市价）月销量";
    $address = array("guofang.liu@shijijiaming.com");//"wenlong.yang@shijijiaming.com","xiaolong.hu@shijijiaming.com"
    $attachment = array(
        "鲜鱼水菜sku（市价）月销量.xlsx" => C("FILE_SAVE_PATH")."001/".$fileName,
    );

    if (sendMailer($title, $content, $address, $attachment)) {
        echo "(发送成功)";
    } else {
        echo "(发送失败)";
    }
} else {
    $success = false;
    $data = "";
    $message = "下载失败";
}
venus_script_finish($time);
exit();




