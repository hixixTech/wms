<?php

namespace Wms\Service;

use Common\Service\ExcelService;
use Common\Service\PdfService;
use Wms\Dao\OrderDao;

class OrderexportService
{

    //1.导出小程序订单
    public function purchase_order_export()
    {
        $oCode = $_GET['code'];//订单编号
        if (empty($oCode)) {
            venus_throw_exception(1, "订单编号不能为空");
            return false;
        }
        $cond['oCode'] = $oCode;
        $orderGoodsList = OrderDao::getInstance()->queryListByOrderCode($cond);
        if(empty($orderGoodsList)){
            echo "<script>alert('该订单编号有误，请核对后再下载哦！')</script>";
            exit;
        }
        $orderGoodData = array();

        $warName = $orderGoodsList[0]['war_name'];
        $room = $orderGoodsList[0]['room'];
        $oPdate = $orderGoodsList[0]['order_pdate'];
        $ordergoodsDataArr=array();
        foreach ($orderGoodsList as $orderGoodsData) {
            $ordergoodsDataArr[$orderGoodsData["sup_name"]][]=$orderGoodsData;
        }
        $orderGoodsList=array();
        foreach ($ordergoodsDataArr as $fname=>$ordergoodsData) {
            $keys = 0;
            if(empty($orderGoodData[$fname])){
                $orderGoodData[$fname][] = array("", $warName, '', $room . "原料申购单", '', '', '', '订单编号：' . $oCode);
            }
            if(count($ordergoodsData)>50){
                $orderGoodData[$fname][] = array("序号", "名称", "数量", "单位", "实际数量","序号", "名称", "数量", "单位", "实际数量");
            }else{
                $orderGoodData[$fname][] = array("序号", "名称", "数量", "单位", "实际数量");
            }
            $orderGoodsList = array_chunk($ordergoodsData, 50);
            $orderGoodsListData = $orderGoodsList[0];
            foreach ($orderGoodsListData as $index => $goodsItem) {
                $goodsList = array(
                    "skName" => $goodsItem['spu_name'],
                    "skInit" => $goodsItem['sku_init'],
                    "skCount" => $goodsItem['sku_count'],
                    "skUnit" => $goodsItem['sku_unit'],
                );
                if(isset($orderGoodsList[1][$index])){
                    $goodsListNew = array(
                        "skName" => $orderGoodsList[1][$index]['spu_name'],
                        "skInit" => $orderGoodsList[1][$index]['sku_init'],
                        "skCount" => $orderGoodsList[1][$index]['sku_count'],
                        "skUnit" => $orderGoodsList[1][$index]['sku_unit'],
                    );
                    $orderGoodData[$fname][] = array(
                        $keys + 1, $goodsList['skName'], $goodsList['skInit'], $goodsList['skUnit'], $goodsList['skCount'],
                        $keys + 1, $goodsListNew['skName'], $goodsListNew['skInit'], $goodsListNew['skUnit'], $goodsListNew['skCount']
                    );
                }else{
                    $orderGoodData[$fname][] = array(
                        $keys + 1, $goodsList['skName'], $goodsList['skInit'], $goodsList['skUnit'], $goodsList['skCount'],
                    );
                }
                $keys++;


            }
            $times = date("Y年m月d日", strtotime($oPdate));
            $orderGoodData[$fname][] = array("", "", "", "", $times);
            $orderGoodData[$fname][] = array("", "", "", "", "");
            $orderGoodData[$fname][] = array("项目经理签字：", "", "", "", "", "厨师长签字：");
            $orderGoodData[$fname][] = array("库管签字：", "", "", "", "", "供货商签字：");

        }

        $fileName = ExcelService::getInstance()->exportExcel($orderGoodData, '', "001");

        if ($fileName) {
//            $dir ="001";
            $saveFile="purchaseOrder.xlsx";
            $moveFileRes = rename("/home/wms/app/Public/files/001/$fileName", "/home/wms/app/Public/static/purchaseorder/$saveFile");
            if($moveFileRes){
                header("location:https://wms.shijijiaming.cn/static/purchaseorder/".$saveFile);
            } else {
                echo "移动失败" . "$saveFile";
            }
//            ExcelService::getInstance()->outPut($dir,$fileName,$saveFile);
        } else {
            $success = false;
            $data = "";
            $message = "下载失败";
        }
        return array($success, $data, $message);
    }

    //1.导出小程序订单
    public function purchase_order_export1()//测试
    {
        $oCode = "O40315133829881";//订单编号$_GET['oCode']O40403170206349
        if(empty($oCode)){
            venus_throw_exception(1, "订单编号不能为空");
            return false;
        }
        $cond['oCode'] = $oCode;
        $orderGoodsList = OrderDao::getInstance()->queryListToOrdergoodsByTime($cond);
        $orderGoodData = array();
        $keys = 0;
        $warName = $orderGoodsList[0]['war_name'];
        $room = $orderGoodsList[0]['room'];
        $oCtime = $orderGoodsList[0]['order_ctime'];
        $fname = "申购单";
        $orderGoodData[$fname][] = array("",$warName,$room."原料申购单",'','','','订单编号：'.$oCode);
        $orderGoodData[$fname][] = array("序号", "名称", "数量", "单位", "实际数量");
        foreach ($orderGoodsList as $index => $goodsItem) {
            $goodsLists[$index] = array(
                "0" => $keys + 1,
                "1" => $goodsItem['spu_name'],
                "2" => $goodsItem['sku_init'],
                "3" => $goodsItem['sku_unit'],
                "4" => $goodsItem['sku_count'],
            );

//            $orderGoodData[$fname][] = array(
//                $keys + 1, $goodsList['skName'], $goodsList['skInit'], $goodsList['skUnit'], $goodsList['skCount']);
            $keys++;
        }
        $times = date("Y年m月d日",strtotime($oCtime));
        $orderGoodData[$fname][] = array("", "", "", "", $times);
        $orderGoodData[$fname][] = array("", "", "", "", "");
        $orderGoodData[$fname][] = array("项目经理签字：", "", "", "", "","厨师长签字：");
        $orderGoodData[$fname][] = array("库管签字：", "", "", "", "","供货商签字：");

        $fileName = ExcelService::getInstance()->exportExcel($orderGoodData, '', "001");
        $strContent = "";
        $strContent .= '<table border="1px" cellspacing="0" cellpadding="0" width="600" align="center">';
        $strContent .= '<caption><h2>'.$warName.$room.'原料申购单&nbsp;订单编号：'.$oCode.'</h2></caption>';
        $strContent .= '<tr bgcolor="#dddddd">';
        $strContent .= '<th>序号</th><th>名称</th><th>数量</th><th>单位</th><th>实际数量</th>';
        $strContent .= '</tr>';
        //使用双层for语句嵌套二维数组$contact1,以HTML表格的形式输出
        //使用外层循环遍历数组$contact1中的行
        for($row=0;$row<count($goodsLists);$row++)
        {
            $strContent .= '<tr>';
            for($col=0;$col<count($goodsLists[$row]);$col++)
            {
                $strContent .= '<td>'.$goodsLists[$row][$col].'</td>';
            }
            $strContent .= '</tr>';
        }
        $strContent .= '<tr>';
        $strContent .= '<td>项目经理签字:</td>';
        $strContent .= '<td></td>';
        $strContent .= '<td>厨师长签字:</td>';
        $strContent .= '<td></td>';
        $strContent .= '<td></td>';
        $strContent .= '<tr>';
        $strContent .= '<tr>';
        $strContent .= '<td>库管签字:</td>';
        $strContent .= '<td></td>';
        $strContent .= '<td>供货商签字:</td>';
        $strContent .= '<td></td>';
        $strContent .= '<td></td>';
        $strContent .= '<tr>';
        $strContent .= '</table>';
//        var_dump($strContent);
//        echo "<pre>";
//        var_dump($strContent);
//        echo "</pre>";
//        exit;
        if ($fileName) {
            $success = true;
            $data = $fileName;
            $message = "";
//            $saveFile="purchaseOrder.pdf";
            PdfService::getInstance()->pdf($strContent);
            return array($success, $data, $message);
        } else {
            $success = false;
            $data = "";
            $message = "下载失败";
        }
        return array($success, $data, $message);
    }

}



