<?php
/**
 * Created by PhpStorm.
 * User: lingn
 * Date: 2019/5/23
 * Time: 11:28
 * 采购部订单满足率报表
 */

//在命令行中输入 chcp 65001 回车, 控制台会切换到新的代码页,新页面输出可为中文
venus_script_begin("开始获取采购部周报及月报报表数据");

$excelData = array();
$fileName = "";

$excelData = getPurchasingOrderDataPskuds($stime, $etime);
$fileName = export_report_pskuds($excelData, "057");
$fileArr["采购部"]["057"] = $fileName;
function getPurchasingOrderDataPskuds($stime, $etime)
{
    $condition = array(
        "rec_type" => 1,
        "rec_mark" => array("like", "JM%"),
    );
    $condition["rec_ctime"] = array(
        array('EGT', $stime),
        array('ELT', $etime),
        'AND'
    );
    $recData = M("receipt")->where($condition)->limit(0, 100000000)->select();
    $recCtimeArr = array();
    foreach ($recData as $recDatum) {
        $recCode = $recDatum['rec_code'];
        $recCtime = $recDatum['rec_ctime'];
        $recMark = $recDatum['rec_mark'];
        $recCtimeArr[$recCode]['ctime'] = $recCtime;
        $recCtimeArr[$recCode]['mark'] = $recMark;
    }

    $recCodeArr = array_keys($recCtimeArr);
    $data = array();
    foreach ($recCodeArr as $recCode) {
        $gbDataList = queryGbListByRecCodePskuds($recCode, 0, 1000);
        foreach ($gbDataList as $gbData) {
            $totalBprice = bcmul(bcmul($gbData['sku_count'], $gbData['spu_count'], 4), $gbData['gb_bprice'], 2);
            $totalSprice = bcmul($gbData['spu_count'], $gbData['spu_sprice'], 2);
            $data["采购部周报及月报"][] = array($gbData['sku_code'], $gbData['spu_name'],
                venus_spu_type_name($gbData['spu_type']), venus_spu_catalog_name($gbData['spu_subtype']),
                $gbData['spu_brand'], $gbData['sup_code'], $gbData['sup_name'],
                $gbData['sku_norm'], $gbData['sku_unit'], $recCtimeArr[$recCode]['ctime'],
                $gbData['gb_skuinit'], $gbData['sku_count'], $totalBprice, $totalSprice);
        }
    }
    return $data;
}


function queryGbListByRecCodePskuds($recCode, $page = 0, $count = 100)
{
    $condition = array("rec_code" => $recCode);
    return M("Goodsbatch")->alias('gb')->field('*,spu.spu_code,sku.sku_code')
        ->join("JOIN wms_spu spu ON spu.spu_code = gb.spu_code")
        ->join("JOIN wms_sku sku ON sku.sku_code = gb.sku_code")
        ->join("JOIN wms_supplier sup ON spu.sup_code = sup.sup_code")
        ->where($condition)->order('gb.gb_code desc')->limit("{$page},{$count}")->fetchSql(false)->select();
}


/**
 * @param $data
 * @param $typeName
 * @return string
 */
function export_report_pskuds($data, $typeName)
{
    $template = C("FILE_TPLS") . $typeName . ".xlsx";
    $saveDir = C("FILE_SAVE_PATH") . $typeName;

    $fileName = md5(json_encode($data)) . ".xlsx";
    if (file_exists($fileName)) {
        return $fileName;
    }
    vendor('PHPExcel.class');
    vendor('PHPExcel.IOFactory');
    vendor('PHPExcel.Writer.Excel2007');
    vendor("PHPExcel.Reader.Excel2007");
    $objReader = new \PHPExcel_Reader_Excel2007();
    $objPHPExcel = $objReader->load($template);    //加载excel文件,设置模板

    $templateSheet = $objPHPExcel->getSheet(0);


    foreach ($data as $sheetName => $list) {
        $line = count($list);

        $excelSheet = $templateSheet->copy();

        $excelSheet->setTitle($sheetName);
        //创建新的工作表
        $sheet = $objPHPExcel->addSheet($excelSheet);
        $addLine = $line - 5;
        $sheet->insertNewRowBefore(5, $addLine);
//        exit();
        $lettersCount = 0;
        $line = 2;
        $lettersLength = count($list[0]);
        $letters = array();
        for ($letter = 0; $letter < $lettersLength; $letter++) {
            $letterCell = getLettersCell($letter);
            $letters[] = $letterCell;
        }
        foreach ($list as $index => $arr) {
            //输出数据
            foreach ($arr as $i => $value) {
                $sheet->setCellValue("$letters[$i]$line", $value);
            }
            $line++;
            if ($lettersCount < $lettersLength) {
                $lettersCount = $lettersLength;
            }
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

