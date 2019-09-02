<?php

namespace Erp\Service;

use Common\Service\ExcelService;
use Common\Service\PassportService;
use Erp\Dao\ShopordersDao;
use Erp\Dao\ShoporderdetailDao;
use Erp\Service\PrizeService;

class OrderonlineService {

    public $waCode;

    function __construct()
    {
        $workerData = PassportService::getInstance()->loginUser();
        //\Think\Log::write(json_encode(session_id()),'zk0418');
        //\Think\Log::write(json_encode($workerData),'zk0418');

        /*if(empty($workerData)){
            venus_throw_exception(110);
        }*/
        /*$this->warCode = $workerData["war_code"];//'WA000001';//
        $this->worCode = $workerData["wor_code"];// */
        $this->warCode = 'WA000001';
        $this->worCode = 'WO40516134750488';//正式'WO40516134830324';//
        
    }
    //店铺列表
    public function store_list(){
      $orderModel = ShopordersDao::getInstance($this->warCode);  
      $data = $orderModel->queryByStoreId();
      return  array(true, $data, '店铺列表查询成功');
    }
    //
    public function order_list(){
        $post = $_POST['data'];
        $shopId = $post['shopId'];
        $orderNum = $post['tradeNum'];
        $mobile = $post['mobile'];
        $buyerName = $post['buyerName'];
        $page = $post['pageCurrent'];
        $count = $post['pageSize'];
        if(!empty($shopId)){
            $condition['shop_id'] = $shopId;
        }
        if(!empty($orderNum)){
            $condition['tradenum'] = $orderNum;
        }
        if(!empty($mobile)){
            $condition['buyer_mobile'] = $mobile;
        }
        if(!empty($buyerName)){
            $condition['buyer_name'] = $buyerName;
        }
        $list = array();
        $orderModel = ShopordersDao::getInstance($this->warCode);
        if($this->worCode == C("LOING_INFO.1")){
            $condition['logistics_status'] = array('in', array(0,1,2,3)); 
            $role = 1;
            $list = $orderModel->queryBySearch($condition, $page, $count);
            \Think\Log::write(json_encode($list),'zk0704-a');
        }elseif($this->worCode == C("LOING_INFO.2")){
            $condition['a.logistics_status'] = array('in', array(2,3)); 
            $role = 2;
            $list = $orderModel->queryCangSearch($condition, $page, $count);

        }
        
        $list['role'] = $role;
        return array(true, $list, '');

    }
    //method taobao.trade.get
    public function order_detail(){
        $tradenum = $_POST['data']['tradenum'];
        if(empty($tradenum)){
            return array(false, '', '订单号不能为空哦');
        }
        $model = ShoporderdetailDao::getInstance($this->warCode);
        $data = $model->detailBytradenum($tradenum);

        return array(true, $data, '订单详情返回成功');
    }
    
    //客服审核通过/批量审核通过
    public function status_update(){
        $status = $_POST['data']['status'];
        $ids = $_POST['data']['ids'];//审核通过的id
        $orderModel = ShopordersDao::getInstance();
        /*$res = $orderModel->querymsgById($id);
        if(!$res){
            return array(false, '', '未查询到数据');
        }
        if($res["logistics_status"] !== 0){
            return array(false, '', '订单状态不对哦');
        }*/

        $data = $orderModel->updateByIds($ids, 1);


        return array(true, '', '审核通过');
    }

    //打印面单//批量打印面单生成excel
    public function get_pdf(){
        $ids = $_POST['data']['ids'];
        $status = $_POST['data']['status'];
        $orderModel = ShopordersDao::getInstance();
        $data = $orderModel->querymsgByIds($ids);
        $res = $this->make_pdf($data);
        $excel = $this->get_excel($ids);
        $updateTime = $this->updated_at($ids);
        $url['pdf'] = $res;
        $url['excel'] = $excel;
        if($res){
            if($status == 3){
                return array(true, $url, '打印面单成功');
            }else{
                //减去库存，添加订单成本
                venus_db_starttrans();//启动事务

                /*foreach($data as $v){
                    $arr = array();
                    $orderId = $v['order_id'];
                    $arr = explode(',', $v['seller_message']);
                    $arr[] = $v['merchant_code'];
                    //
                    $receip =  new ReceiptService();
                    $costData = $receip->sale_goodsbatch($orderId, $arr);
                    \Think\Log::write(json_encode($costData),'zk0606-b');
                    if(empty($costData)){
                        venus_db_rollback();
                        return array(false, '', '该商品不存在或库存不足');
                    }
                    $costData = json_encode($costData);
                    $res = $orderModel->updateByOrderId($orderId, $costData);
                    \Think\Log::write(json_encode($costData),'zk0606-a');
                }*/
                $result = $orderModel->updateByCangIds($ids, 3);

                if(!$result /*|| !$costData*/ || !$res){
                    venus_db_rollback();
                    return array(false, '', '面单下载失败');
                }
            }
            
        }  
        venus_db_commit();   
        return array(true, $url, '打印面单成功');
    }

    //将图片转换成PDF
    public function make_pdf($arr){
        $im = new \Imagick();   
        for( $i=0;$i<count($arr);$i++ ) 
        { 
            $auxIMG = new \Imagick(); 
            $auxIMG->readImage($arr[$i]['logistics_img']);
            $im->addImage($auxIMG); 
        }
        //\Think\Log::write(json_encode('come in'),'zk0505c');
        $name = date('YmdHis');//md5(time());
        $time = date("Ymd");
        $fileurl =  $_SERVER['DOCUMENT_ROOT'].'/Public/files/erp/'.$time;
        if(!is_dir($fileurl)){
            mkdir($fileurl);
        }
        $url = $fileurl.'/'.$name.'.pdf';
        $res = $im->writeImages($url, true); 
        $link = $time.'/'.$name.'.pdf';//$_SERVER['HTTP_HOST'].'/static/Pdfs/'.$time.'/'.$name.'.pdf';
        if($res){
            $im->destroy();
            $auxIMG->destroy();
            return $link;//生成的PDF路径
        }
    }
    //修改更新时间
    public function updated_at($ids){
        $result = ShopordersDao::getInstance()->updatedTime($ids);
        if(!$result){
            return array(false, '', '更新订单时间失败');
        }
    }

    //EXcel订单导入
    public function order_import(){
        $shopId = 1;
        $shopmes = array(
            'name' => '禾先生',
            'shop_id' => 1,
            'shop_type' => 0,
        );
        $datas = ExcelService::getInstance()->upload("file");
        $dicts = array(
            "A" => "tradenum",//订单单号
            "D" => "partner_trade_no",//支付单号
            "F" => "price_total",//买家应付款
            "I" => "total_fee",//总金额
            "M" => "order_status",//订单状态 
            "N" => "buyer_message",//买家备注
            "O" => "buyer_name",//收货人姓名
            "P" => "address",//收货地址
            "S" => "buyer_mobile",//联系手机号
            "T" => "created_at",//订单创建时间
            "U" => "partner_trade_no",//订单付款时间
            "V" => "goods_name",//商品标题
            "X" => "logistics_num",//物流单号
            //"" => "",//
            "Z" => "seller_message",//卖家订单备注
            "AA" => "num",//商品数量
            //"Y" => "",//扣款商家金额
        );

        $skuList = array();
        foreach ($datas as $sheetName => $list) {
            unset($list[0]);
            $skuList = array_merge($skuList, $list);
        }

        venus_db_starttrans();//启动事务
        $result = true;
        $filter[0] = "/=/";
        $filter[1] = '/"/';
        $filter[2] = "/'/";
        $filtered[2] = "";
        $filtered[1] = "";
        $filtered[0] = "";
        foreach ($skuList as $index => $orderItem) {
            $orderData = array();
            foreach ($dicts as $col => $key) {
                $orderData[$key] = isset($orderItem[$col]) ? preg_replace($filter, $filtered, $orderItem[$col]) : "";
            }
            if(!empty($orderData['address'])){

                $address = explode(' ', preg_replace("/[\s]+/is"," ",$orderData['address']));
                $orderData['buyer_state'] = $address[0];
                $orderData['buyer_city'] = $address[1];
                $orderData['buyer_district'] = $address[2];
                $orderData['buyer_address'] = $orderData['address'];
            }
            $orderStatus = $orderData['order_status'];
            if(!empty($orderStatus)){
                if($orderStatus == '买家已付款，等待卖家发货'){
                    $orderData['logistics_status'] = 0;
                }else{
                    $orderData['logistics_status'] = 3;
                    //continue;
                } 
            }
            if (trim($orderData['tradenum']) == '' || trim($orderData['partner_trade_no']) == '') {
                if (trim($orderData['address']) == '' && trim($orderData['buyer_mobile']) == '') {
                    continue;
                } else {
                    if (trim($orderData['address']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "买家地址不能为空");
                        return false;
                    }
                    if (trim($orderData['buyer_mobile']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "买家手机号不能为空");
                        return false;
                    }
                }
            } else {
                $orderModel = ShopordersDao::getInstance();
                //查询订单号判断该订单是否已经存在
                $res = $orderModel->querymsgByTradenum($orderData['tradenum']);
                if($res){
                    continue;
                }
                $type = 1;
                $orderData['order_id'] = $this->get_trade_no($type);
                $orderData['updated_at'] = date("Y-m-d H:i:s");
                $result = $result && $orderModel->insert($orderData, $shopmes);
            }      
        }
        if ($result) {
            venus_db_commit();
            $success = true;
            $message = "店铺订单导入成功";

        } else {
            venus_db_rollback();
            $success = false;
            $message = "店铺订单导入失败";
        }
        return array($success, "", $message);
    }

    //订单内部编号导入  正式
    public function number_import(){
        $datas = ExcelService::getInstance()->upload("file");
        $dicts = array(
            "A" => "tradenum",//订单单号
            "D" => "num",//购买数量
            "E" => "merchant",//外部编号
        );
        $skuList = array();
        foreach ($datas as $sheetName => $list) {
            unset($list[0]);
            $skuList = array_merge($skuList, $list);
        }

        venus_db_starttrans();//启动事务
        $result = true;
        $filter[0] = "/=/";
        $filter[1] = '/"/';
        $filter[2] = "/'/";
        $filtered[2] = "";
        $filtered[1] = "";
        $filtered[0] = "";
        $orderModel = ShopordersDao::getInstance();
        foreach ($skuList as $index => $orderItem) {
            $orderData = array();
            foreach ($dicts as $col => $key) {
                $orderData[$key] = isset($orderItem[$col]) ? preg_replace($filter, $filtered, $orderItem[$col]) : "";
            }
            if (trim($orderData['tradenum']) == '') {
                if (trim($orderData['merchant']) == '' && trim($orderData['num']) == '') {
                    continue;
                } else {
                    if (trim($orderData['merchant']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "外部编号不能为空");
                        return false;
                    }
                    if (trim($orderData['num']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "商品数量不能为空");
                        return false;
                    }
                }
            } else { 
                //查询订单号判断该订单是否已经存在
                $res = $orderModel->querymsgByTradenum($orderData['tradenum']);
                $detail = ShoporderdetailDao::getInstance($this->warCode);
                if(!empty($res)){
                    $content = $detail->detailBytradenum($orderData['tradenum']);  
                    if(!empty($content)){
                        $content2 = $detail->detailByMerchant($orderData['tradenum'], $orderData['merchant']); 
                        //判断该订单号，订单商品是否已经入数据库                 
                        if(empty($content2)){
                            $aa = $detail->queryLastOrderId($orderData['tradenum']);
                            $cc = explode('-', $aa['order_id']);
                            $num = $this->getNum($cc[1]);
                            $orderData['order_id'] = $res['order_id'];
                            $result = $detail->insert($orderData);
                            $num = $num + 1;
                            //赠品是否拆单，查询商品表格
                            for($i=0;$i<$orderData['num'];$i++){
                                $dd = $i + $num;
                                $orderData['order_id'] = $res['order_id'].'-'.$this->getLetter($dd);
                                $orderData['seller_message'] = '';
                                $result = $detail->insideAdd($orderData);
                            }
                        }else{
                            continue;
                        }
                    }else{  
                        $message = $res['seller_message'];
                        $message = explode(',', $message);
                        if(in_array('1', $message)){
                            $orderData['order_id'] = $res['order_id'];
                            $result = $detail->insert($orderData);
                            $orderData['order_id'] = $res['order_id'].'-a';
                            $orderData['seller_message'] = '包装用防水袋';
                            $orderData['merchant'] = '0.5KG米砖';
                            $result = $detail->insideAdd($orderData);
                        }elseif(in_array('2', $message)){
                            $orderData['order_id'] = $res['order_id'];
                            $result = $detail->insert($orderData);
                            $orderData['order_id'] = $res['order_id'].'-a';
                            $orderData['seller_message'] = '包装用防水袋';
                            $orderData['merchant'] = '食盐一袋';
                            $result = $detail->insideAdd($orderData);
                        }elseif(in_array('3', $message)){ //备注3为小米为4罐
                            $orderData['order_id'] = $res['order_id'];
                            $result = $detail->insert($orderData);
                            $orderData['order_id'] = $res['order_id'].'-a';
                            $orderData['seller_message'] = $res['seller_message'];
                            $orderData['number'] = 4;//商品数量
                            $result = $detail->insideAdd($orderData);
                        }elseif(in_array('4', $message)){ //备注4为小米为7罐
                            $orderData['order_id'] = $res['order_id'];
                            $result = $detail->insert($orderData);
                            $orderData['order_id'] = $res['order_id'].'-a';
                            $orderData['seller_message'] = $res['seller_message'];
                            $orderData['number'] = 7;//商品数量
                            $result = $detail->insideAdd($orderData);
                        }else{
                            $orderData['order_id'] = $res['order_id'];
                            $result = $detail->insert($orderData);
                            //赠品是否拆单，查询商品表格
                            for($i=0;$i<$orderData['num'];$i++){
                                $orderData['order_id'] = $res['order_id'].'-'.$this->getLetter($i);
                                if($i == 0){
                                    $orderData['seller_message'] = $res['seller_message'];
                                    $result = $detail->insideAdd($orderData);
                                }else{
                                    $orderData['seller_message'] = '';
                                    $result = $detail->insideAdd($orderData);
                                }      
                            }
                        } 
                    }                   
                }else{
                    continue;
                }   
            }            
        }
        if ($result) {
            venus_db_commit();
            $success = true;
            $message = "订单外部编号导入成功";

        } else {
            venus_db_rollback();
            $success = false;
            $message = "订单外部编号导入失败";
        }
        return array($success, "", $message);
    }

    ////订单内部编号导入,自行进行拆单
    public function number_import3(){
        $datas = ExcelService::getInstance()->upload("file");
        $dicts = array(
            "A" => "tradenum",//订单单号
            "D" => "num",//购买数量
            "E" => "merchant",//外部编号
        );
        $skuList = array();
        foreach ($datas as $sheetName => $list) {
            unset($list[0]);
            $skuList = array_merge($skuList, $list);
        }

        venus_db_starttrans();//启动事务
        $result = true;
        $filter[0] = "/=/";
        $filter[1] = '/"/';
        $filter[2] = "/'/";
        $filtered[2] = "";
        $filtered[1] = "";
        $filtered[0] = "";
        $orderModel = ShopordersDao::getInstance();
        foreach ($skuList as $index => $orderItem) {
            $orderData = array();
            foreach ($dicts as $col => $key) {
                $orderData[$key] = isset($orderItem[$col]) ? preg_replace($filter, $filtered, $orderItem[$col]) : "";
            }
            if (trim($orderData['tradenum']) == '') {
                if (trim($orderData['merchant']) == '' && trim($orderData['num']) == '') {
                    continue;
                } else {
                    if (trim($orderData['merchant']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "外部编号不能为空");
                        return false;
                    }
                    if (trim($orderData['num']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "商品数量不能为空");
                        return false;
                    }
                }
            } else { 
                //查询订单号判断该订单是否已经存在
                $res = $orderModel->querymsgByTradenum($orderData['tradenum']);
                $detail = ShoporderdetailDao::getInstance($this->warCode);
                if(empty($res)){
                    continue;
                }else{
                    $data = $detail->detailExist($orderData['tradenum'], $orderData['merchant']);
                    if(!empty($data)){
                        continue;
                    }
                    $orderData['order_id'] = $res['order_id'];
                    $result = $detail->insert($orderData);
                    //插入人工分单表格中,将数据插入点到人工拆单的列表中
                    for($i=0;$i<$orderData['num'];$i++){
                        $orderData['num'] = 1;

                    }
                    
                    
                }
                
                /*if(!empty($res)){
                    $content = $detail->queryDetail($orderData['tradenum'], $orderData['merchant']);
                    if(!empty($content)){
                        continue;
                    }
                    $orderData['order_id'] = $res['order_id'];
                    $result = $detail->insert($orderData);                 
                }else{
                    continue;
                }*/
                
            }            
        }
        if ($result) {
            venus_db_commit();
            $success = true;
            $message = "订单外部编号导入成功";

        } else {
            venus_db_rollback();
            $success = false;
            $message = "订单外部编号导入失败";
        }
        return array($success, "", $message);
    }

    //订单内部编号导入备份
    public function number_import2(){
        $datas = ExcelService::getInstance()->upload("file");
        $dicts = array(
            "A" => "tradenum",//订单单号
            "D" => "num",//购买数量
            "E" => "merchant",//外部编号
        );
        $skuList = array();
        foreach ($datas as $sheetName => $list) {
            unset($list[0]);
            $skuList = array_merge($skuList, $list);
        }

        venus_db_starttrans();//启动事务
        $result = true;
        $filter[0] = "/=/";
        $filter[1] = '/"/';
        $filter[2] = "/'/";
        $filtered[2] = "";
        $filtered[1] = "";
        $filtered[0] = "";
        $orderModel = ShopordersDao::getInstance();
        foreach ($skuList as $index => $orderItem) {
            $orderData = array();
            foreach ($dicts as $col => $key) {
                $orderData[$key] = isset($orderItem[$col]) ? preg_replace($filter, $filtered, $orderItem[$col]) : "";
            }
            if (trim($orderData['tradenum']) == '') {
                if (trim($orderData['merchant']) == '' && trim($orderData['num']) == '') {
                    continue;
                } else {
                    if (trim($orderData['merchant']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "外部编号不能为空");
                        return false;
                    }
                    if (trim($orderData['num']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "商品数量不能为空");
                        return false;
                    }
                }
            } else { 
                //查询订单号判断该订单是否已经存在
                $res = $orderModel->querymsgByTradenum($orderData['tradenum']);
                $detail = ShoporderdetailDao::getInstance($this->warCode);
                if(!empty($res)){
                    $content = $detail->detailBytradenum($orderData['tradenum']);
                    if(!empty($content)){
                        continue;
                    }
                    $message = $res['seller_message'];
                    $message = explode(',', $message);
                    if(in_array('1', $message)){
                        $orderData['order_id'] = $res['order_id'];
                        $result = $detail->insert($orderData);
                        $orderData['order_id'] = $res['order_id'].'-a';
                        $orderData['seller_message'] = '包装用防水袋';
                        $orderData['merchant'] = '0.5KG米砖';
                        $result = $detail->insideAdd($orderData);
                    }elseif(in_array('2', $message)){
                        $orderData['order_id'] = $res['order_id'];
                        $result = $detail->insert($orderData);
                        $orderData['order_id'] = $res['order_id'].'-a';
                        $orderData['seller_message'] = '包装用防水袋';
                        $orderData['merchant'] = '食盐一袋';
                        $result = $detail->insideAdd($orderData);
                    }elseif(in_array('3', $message)){ //备注3为小米为4罐
                        $orderData['order_id'] = $res['order_id'];
                        $result = $detail->insert($orderData);
                        $orderData['order_id'] = $res['order_id'].'-a';
                        $orderData['seller_message'] = $res['seller_message'];
                        $orderData['num'] = 4;//商品数量
                        $result = $detail->insideAdd($orderData);
                    }elseif(in_array('4', $message)){ //备注4为小米为7罐
                        $orderData['order_id'] = $res['order_id'];
                        $result = $detail->insert($orderData);
                        $orderData['order_id'] = $res['order_id'].'-a';
                        $orderData['seller_message'] = $res['seller_message'];
                        $orderData['num'] = 7;//商品数量
                        $result = $detail->insideAdd($orderData);
                    }else{
                        $orderData['order_id'] = $res['order_id'];
                        $result = $detail->insert($orderData);
                        //赠品是否拆单，查询商品表格
                        for($i=0;$i<$orderData['num'];$i++){
                            $type = 2;
                            $orderData['order_id'] = $res['order_id'].'-'.$this->getLetter($i);
                            if($i == 0){
                                $orderData['seller_message'] = $res['seller_message'];
                                $result = $detail->insideAdd($orderData);

                            }else{
                                $orderData['seller_message'] = '';
                                $result = $detail->insideAdd($orderData);
                            }
                            
                        }
                    }            
                }else{
                    continue;
                }
                
            }
            
        }
        if ($result) {
            venus_db_commit();
            $success = true;
            $message = "订单外部编号导入成功";

        } else {
            venus_db_rollback();
            $success = false;
            $message = "订单外部编号导入失败";
        }
        return array($success, "", $message);
    }

    //按重量进行拆单---new拆单
    public function splits_order(){
        //查询所有导入的订单，状态为1的订单【订单详情导入后将订单表状态改为1】
        $order = ShopordersDao::getInstance($this->warCode);
        $detail = ShoporderdetailDao::getInstance($this->warCode);
        $status = 0;
        $data = $order->queryBystatus($status);
        if(empty($data)){
            return array(false, '', '还没有要拆分的订单哦');
        }
        //循环查询每笔订单的信息，并进行拆分
        foreach($data as $v){
            $message = $v['seller_message'];
            $message = explode(',', $message);
            $orderData['tradenum'] = $v['tradenum'];
            $orderData['logistics_status'] = 1;
            if(in_array('1', $message)){
                $orderData['order_id'] = $v['order_id'].'-a';
                $orderData['seller_message'] = '包装用防水袋';
                $orderData['merchant'] = '0.5KG米砖';
                $result = $detail->insideAdd($orderData);
            }elseif(in_array('2', $message)){
                $orderData['order_id'] = $v['order_id'].'-a';
                $orderData['seller_message'] = '包装用防水袋';
                $orderData['merchant'] = '食盐一袋';
                $result = $detail->insideAdd($orderData);
            }else{
                $arr100 = array();
                $arr200 = array();
                $sum = 0; 
                //将所有的货品整理在一起，再进行拆单
                //赠品货品
                for($i=0;$i<count($message);$i++){
                    $number = $message[$i];
                    $content = C('GIFT_RULE'.$number);
                    $merchant = $content['merchant'];
                    //查询sku标识 100，直接发包裹，200，两个发一个， 300，其他 
                    $skuData = $detail->queryBySku($merchant);
                    if($skuData['sku_mark'] == 100){
                        $arr100[]['sku_name'] = $skuData['sku_name'];
                        $arr100[]['sku_number'] = 1;
                    }elseif ($skuData['sku_mark'] == 200) {
                        $arr200[]['sku_name'] = $skuData['sku_name'];
                        $arr200[]['sku_number'] = 1;
                    }        
                }
                //商品货品
                $goodsData = $detail->orderDetail($v['tradenum']);
                \Think\Log::write('商品货品'.json_encode($goodsData),'zk0619-b');
                for($i=0;$i<count($goodsData);$i++){
                    $skuData = $detail->queryBySku($goodsData[$i]['merchant_code']);
                    if($skuData['sku_mark'] == 100){
                        $arr100[$i]['sku_name'] = $skuData['sku_name'];
                        $arr100[$i]['sku_number'] = $goodsData[$i]['number'];
                    }elseif ($skuData['sku_mark'] == 200) {
                        $arr200[$i]['sku_name'] = $skuData['sku_name'];
                        $arr200[$i]['sku_number'] = $goodsData[$i]['number'];
                    }
                }
                \Think\Log::write('数组100-'.json_encode($arr100),'zk0619-c');
                \Think\Log::write('数组200-'.json_encode($arr200),'zk0619-d');

                //开始拆单，拣货单分配
                //先拆100单
                
                for($i=0;$i<count($arr100);$i++){
                    \Think\Log::write('数量100-'.json_encode($sum),'zk0619-h');
                    \Think\Log::write('拆单100-'.json_encode($i),'zk0619-e');
                    if($arr100[$i]['sku_number'] < 2){
                        $orderData['order_id'] = $v['order_id'].'-'.$this->getLetter($sum);
                        if($i == 0){
                            $orderData['seller_message'] = $message;
                            $orderData['merchant'] = $arr100[$i]['sku_name'];
                            $result = $detail->insideAdd($orderData);     
                        }else{
                            $orderData['seller_message'] = '';
                            $orderData['merchant'] = $arr100[$i]['sku_name'];
                            $result = $detail->insideAdd($orderData); 
                        }
                        $sum += 1;
                    }else{
                        for($j=0;$j<$arr100[$i]['sku_number'];$j++){
                            $orderData['order_id'] = $v['order_id'].'-'.$this->getLetter($sum);
                            if($i == 0){
                                $orderData['seller_message'] = $message;
                                $orderData['merchant'] = $arr100[$i]['sku_name'];
                                $result = $detail->insideAdd($orderData);     
                            }else{
                                $orderData['seller_message'] = '';
                                $orderData['merchant'] = $arr100[$i]['sku_name'];
                                $result = $detail->insideAdd($orderData); 
                            }
                            $sum += 1;
                        }
                    }
                }
                //再拆200单
                //将200单按 数量降序排序得到新数组
                $messages = '';//拣货单明细
                $numbers = 0;
                for($i=0;$i<count($arr200);$i++){
                    \Think\Log::write('数量200-'.json_encode($sum),'zk0619-j');
                    $number200 = $arr200[$i]['sku_number'];
                    \Think\Log::write('拆单200-'.json_encode($i),'zk0619-f');
                    if( $number200< 2){
                        $numbers += 1;
                        if($numbers == 2){
                            $messages .= '--'.$arr200[$i]['sku_name'];
                            $orderData['merchant'] = $messages;
                            $orderData['order_id'] = $v['order_id'].'-'.$this->getLetter($sum);
                            $result = $detail->insideAdd($orderData);
                            $sum += 1;  
                            $messages = '';  
                            $numbers = '';   
                        }else{
                            $messages .= $arr200[$i]['sku_name'];
                            $numbers += 1;
                        }
                    }else{
                        $cc = floor( $number200 / 2 );
                        for($j=0;$j<$cc;$j++){
                           $orderData['order_id'] = $v['order_id'].'-'.$this->getLetter($sum);
                           $orderData['seller_message'] = '';
                           $orderData['merchant'] = $arr200[$i]['sku_name'];
                           $orderData['num'] = 2;
                           $result = $detail->insideAdd($orderData);
                           $sum += 1;       
                        }
                        $dd = $number200 % 2;
                        if($dd == 1){
                            $numbers += 1;
                            if($numbers == 2){
                                $messages .= '--'.$arr200[$i]['sku_name'];
                                $orderData['order_id'] = $v['order_id'].'-'.$this->getLetter($sum);
                                $orderData['seller_message'] = '';
                                $orderData['merchant'] = $messages;
                                $result = $detail->insideAdd($orderData);
                                $sum += 1;  
                                $messages = '';  
                                $numbers = '';   
                            }else{
                                $messages = $arr200[$i]['sku_name'];
                                $numbers += 1;
                            }
                        }
                    }                   
                }
                /*//计算赠品的质量
                $gift_weight = '';
                $goods_weight = '';
                for($i=0;$i<count($message);$i++){
                    $number = $message[$i];
                    $content = C('GIFT_RULE'.$number);
                    if($content['type'] == 200){
                        $merchant = $content['merchant'];
                        //查询sku重量
                        $skuData = $detail->queryBySku($merchant);
                        $gift_weight += $skuData['sku_weight'];
                    }
                }
                //查询订单详情计算订单商品总重量
                $goodsData = $detail->orderDetail($v['tradenum']);
                \Think\Log::write('订单详情信息-'.json_encode($goodsData),'zk0619-a');
                for($i=0;$i<count($goodsData);$i++){
                    $skuData = $detail->queryBySku($goodsData[$i]['merchant_code']);
                    $goods_weight += $skuData['sku_weight']*$goodsData[$i]['number'];
                }
                $weights = $gift_weight + $goods_weight;
                if($weights <= 2){
                    $orderData['type'] = 1;
                    $orderData['goods_weight'] = $weights;
                    $orderData['order_id'] = $v['order_id'].'-a';
                    $result = $detail->insideAdd($orderData);
                }elseif($weights < 4){
                    $orderData['type'] = 2;
                    $orderData['goods_weight'] = $weights;
                    $orderData['order_id'] = $v['order_id'].'-a';
                    $result = $detail->insideAdd($orderData);
                }else{
                    $a = $weights / 4;
                    $num = floor($a);
                    $b = $weights % 4;
                    for($i=0;$i<$num;$i++){
                        $orderData['type'] = 2;
                        $orderData['goods_weight'] = 4;
                        $orderData['order_id'] = $v['order_id'].'-'.$this->getLetter($i);
                        if($i == 0){
                            $orderData['seller_message'] = $message;
                            $result = $detail->insideAdd($orderData);

                        }else{
                            $orderData['seller_message'] = '';
                            $result = $detail->insideAdd($orderData);
                        }     
                    }
                    if($b <= 2 && $b >0){
                        $orderData['type'] = 1;
                        $orderData['goods_weight'] = $b;
                        $orderData['order_id'] = $v['order_id'].'-'.$this->getLetter($num);
                        $result = $detail->insideAdd($orderData);
                    }elseif($b <= 4 && $b > 2){
                        $orderData['type'] = 2;
                        $orderData['goods_weight'] = $b;
                        $orderData['order_id'] = $v['order_id'].'-'.$this->getLetter($num);
                        $result = $detail->insideAdd($orderData);
                    }

                } */
            }        
        }
    }
    //定时生成拣货单Excel 和 面单PDF---new拆单
    public function print_order(){
        //查询所有状态为2的订单
        $orderModel = ShopordersDao::getInstance($this->warCode);
        $detail = ShoporderdetailDao::getInstance($this->warCode);
        $status = 2;
        $datas = $detail->queryBystatus($status);
        $ids = array();
        foreach($datas as $v){
            array_push($ids, $v['id']);
        }
        \Think\Log::write('面单id-'.json_encode($ids),'zk0619-b');
        $data = $orderModel->querymsgByIds($ids);
        $res = $this->make_pdf($data);
        $excel = $this->get_excel($ids);
        $updateTime = $this->updated_at($ids);
        $url['pdf'] = $res;
        $url['excel'] = $excel;
        if($res){
            if($status == 3){
                return array(true, $url, '打印面单成功');
            }else{
                //减去库存，添加订单成本
                venus_db_starttrans();//启动事务
                $result = $orderModel->updateByCangIds($ids, 3);
                if(!$result /*|| !$costData*/ || !$res){
                    venus_db_rollback();
                    return array(false, '', '面单下载失败');
                }
            }    
        }  
        venus_db_commit();   
        return array(true, $url, '打印面单成功');

    }
    
    //-------------人工拆单start
    //根据订单总量 返回所有的字母编号
    public function all_number(){
        $num = $_POST['data']['number'];
        \Think\Log::write(json_encode($num),'zk0717-number');
        $arr = array();
        for($i=0;$i<$num;$i++){
            $arr[]=$this->getLetter($i);
        }
        return array(true, $arr, '订单编号返回成功');    
    }
    //检索16点到第二天16点  相同账号，相同收货地址的订单合并在一起??


    //拆单的数据详情点击人工拆单，则将拆单数据进行插入
    public function hand_detail(){
        $tradenum = $_POST['data']['tradenum'];
        \Think\Log::write(json_encode($tradenum),'zk0717-tradenum');
        if(empty($tradenum)){
            return array(false, '', '订单号不能为空哦');
        }
        $model = ShoporderdetailDao::getInstance($this->warCode);
        $datas = $model->contentBytradenum($tradenum);
        \Think\Log::write(json_encode($datas),'zk0717-datas');
        if(!empty($datas)){
            return array(true, $datas, '订单详情返回成功');
        }
        $arr = array();
        $data = $model->queryByDetailnum($tradenum);
        \Think\Log::write(json_encode($data),'zk0717-data');
        $j = 0;
        foreach($data as $v){
            /*if($v['number'] == 1){
                $arr[$j]['tradenum'] = $tradenum;
                $arr[$j]['merchant_code'] = $v['merchant_code'];
                $arr[$j]['number'] = 1;
                $result = $model->handInsert($arr[$j]);
                $j++;
            }else{*/
                for($i=0;$i<$v['number'];$i++){
                    $arr[$j]['tradenum'] = $tradenum;
                    $arr[$j]['merchant_code'] = $v['merchant_code'];
                    $result = $model->handInsert($arr[$j]);
                    $j++;
                }
           /* }*/
        }
        $datas = $model->contentBytradenum($tradenum);

        return array(true, $datas, '订单详情返回成功');
    }
    //插入数据到物流单，并生成合并物流单的详情
    public function merge_order(){
        $post = $_POST['data'];
        $tradenum = $post['tradenum'];
        \Think\Log::write(json_encode($post),'zk0717-merge');
        $merge = $post['merge'];
        $orderModel = ShopordersDao::getInstance();
        $detail = ShoporderdetailDao::getInstance($this->warCode);
        $res = $orderModel->querymsgByTradenum($tradenum);
        //查询订单信息
        venus_db_starttrans();//启动事务
        foreach($merge as $key=>$v){
            $type = 1;
            $orderId = $this->get_trade_no($type).'-'.$key;
            $item = array(
                'order_id' => $orderId,
                "tradenum" => $tradenum,//订单单号
                "seller_message"=> $res["seller_message"],
                "created_at" => date("Y-m-d H:i:s"),
                "merchant" =>  $v,//商品编号
                "num" => 0,//
            );
            $result = true;
            $res = true;
            $result = $result && $detail->insideAdd($item);
            $res = $res && $detail->updateHandOrder($orderId,$v);
        }
        //审核通过
        $datas =  $orderModel->querymsgByTradenum($tradenum);
        $ids = array($datas['id']);
        $data = $orderModel->updateByIds($ids, 1);
        if ($result && $res && $data) {
            venus_db_commit();
            $success = true;
            $message = "人工拆单成功";

        } else {
            venus_db_rollback();
            $success = false;
            $message = "人工拆单失败";
        }    
        return array($success, "", $message);
    }

    public function pass_order(){
        $post = $_POST['data'];
        $detail = ShoporderdetailDao::getInstance($this->warCode);
        $orderModel = ShopordersDao::getInstance();
        $res = $orderModel->querymsgByTradenum($post['tradenum']);
        $details = $detail->orderDetail($post['tradenum']);
        $result = true;
        venus_db_starttrans();//启动事务
        foreach($details as $orderData){
            $message = $res['seller_message'];
            $message = explode(',', $message);
            if(in_array('1', $message)){
                $orderData['order_id'] = $res['order_id'].'-a';
                $orderData['seller_message'] = '包装用防水袋';
                $orderData['merchant'] = '0.5KG米砖';
                $orderData['tradenum'] = $post['tradenum'];
                $result = $result && $detail->insideAdd($orderData);
            }elseif(in_array('2', $message)){
                $orderData['order_id'] = $res['order_id'].'-a';
                $orderData['seller_message'] = '包装用防水袋';
                $orderData['merchant'] = '食盐一袋';
                $orderData['tradenum'] = $post['tradenum'];
                $result = $result && $detail->insideAdd($orderData);
            }elseif(in_array('3', $message)){ //备注3为小米为4罐
                $orderData['order_id'] = $res['order_id'].'-a';
                $orderData['seller_message'] = $res['seller_message'];
                $orderData['number'] = 4;//商品数量
                $orderData['tradenum'] = $post['tradenum'];
                $result = $result && $detail->insideAdd($orderData);
            }elseif(in_array('4', $message)){ //备注4为小米为7罐
                $orderData['order_id'] = $res['order_id'].'-a';
                $orderData['seller_message'] = $res['seller_message'];
                $orderData['number'] = 7;//商品数量
                $orderData['tradenum'] = $post['tradenum'];
                $result = $result && $detail->insideAdd($orderData);
            }else{
                //赠品是否拆单，查询商品表格
                for($i=0;$i<$orderData['num'];$i++){
                    $orderData['order_id'] = $res['order_id'].'-'.$this->getLetter($i);
                    if($i == 0){
                        $orderData['seller_message'] = $res['seller_message'];
                        $orderData['tradenum'] = $post['tradenum'];
                        $result = $result && $detail->insideAdd($orderData);
                    }else{
                        $orderData['seller_message'] = '';
                        $orderData['tradenum'] = $post['tradenum'];
                        $result = $result && $detail->insideAdd($orderData);
                    }      
                }
            }         
        }   
        //审核通过
        $datas =  $orderModel->querymsgByTradenum($post['tradenum']);
        $ids = array($datas['id']);
        $data = $orderModel->updateByIds($ids, 1);
        if ($result && $data) {
            venus_db_commit();
            $success = true;
            $message = "审核成功";

        } else {
            venus_db_rollback();
            $success = false;
            $message = "审核失败";
        }    
        return array($success, "", $message);      
    }


    //-------------人工拆单end
    //每笔订单成本计算
    public function out_list(){
        $post = $_POST['data'];
        $skuName = $post['skuName'];
        /*$skuCode = $post['skuCode'];*/
        /*$timeStart = $post['timeStart'];
        $timeEnd = $post['timeEnd'];*/
        $page = $post['pageCurrent'];
        $size = $post['pageSize'];
        if(!empty($skuName)){
            $condition['a.merchant_code'] = $skuName;
        }
        /*if(!empty($skuCode)){
            $search['merchant_code'] = $skuCode;
        }*/
        $goodsData = ShoporderdetailDao::getInstance();
        $data = $goodsData->goodsLists($condition, $page, $size);
        //\Think\Log::write(json_encode($data),'zk0606-c');
        $datas = $data['list'];
        $arr['total'] = $data['total'];
        //转换成本输出
        $list = array();
        for($i=0;$i<count($datas);$i++){
            $vv = '';
            $list[$i]['order_id'] = $datas[$i]['order_id'];
            $list[$i]['tradenum'] = $datas[$i]['tradenum'];
            $list[$i]['merchant_code'] = $datas[$i]['merchant_code'];
            $vv = json_decode($datas[$i]['order_cost'], true);
            foreach($vv['goods'] as $goods){
                $list[$i]['goodsPrice'] += $goods['bprice'];
                $list[$i]['freight'] = $goods['freight'];
            }
            foreach($vv['inner'] as $inner){
                $list[$i]['innerSku'] = $inner['code'];
                $list[$i]['innerPrice'] = $inner['bprice'];
            }
            foreach($vv['outer'] as $outer){
                $list[$i]['outer'] = $outer['code'];
                $list[$i]['outerPrice'] = $outer['bprice'];
            }
            foreach($vv['gift'] as $gift){
                $list[$i]['giftSku'] = $gift['code'];
                $list[$i]['giftPrice'] += $gift['bprice'];

            }
        
        }
        $arr['list'] = $list;
        if(empty($data)){
            return array(false, '', '查询数据失败');
        }
        return array(true, $arr, '出库列表返回成功');

    }
    //生成面单的excel
    public function get_excel($ids){
        $goodsData = ShoporderdetailDao::getInstance()->goodsList($ids);
        $goodData = array();
        $fname = "面单对应货品表";
        $header = array("内部订单编号", "订单号","收货人姓名", "联系手机号", "订单备注"/*, "宝贝标题"*/, "宝贝数量", "货品编号");
        
        foreach ($goodsData as $index => $goodsItem) {
            $goodsList = array(
                "order_id" => $goodsItem['order_id'],
                "tradeNum" => ' '.$goodsItem['tradenum'],
                "buyer_name" => $goodsItem['buyer_name'],
                "buyer_mobile" => $goodsItem['buyer_mobile'],
                "seller_message" => $goodsItem['seller_message'],
                //"goods_name" => $goodsItem['goods_name'],
                "count" => $goodsItem['num'],
                "merchant_code" => $goodsItem['merchant_code'],
            );
            $goodData[$fname][] = array(
                    $goodsList['order_id'],$goodsList['tradeNum'],$goodsList['buyer_name'],$goodsList['buyer_mobile'],$goodsList['seller_message'],/*$goodsList['goods_name'],*/$goodsList['count'],$goodsList['merchant_code']
                );
        }
        $time = date('Ymd');
        $url = 'erp/'.$time;
        $fileName = ExcelService::getInstance()->exportExcel($goodData, $header, 'erp/'.$time);
        if ($fileName) {
            return $time.'/'.$fileName;
        } else {
            $success = false;
            $data = "";
            $message = "excel生成失败";
        }
        return array($success, $data, $message);
    }
    //根据订单好查询运单号----前端插件
    public function get_logistNum(){
        $list = $_POST['list'];
        if(empty($list)){
            return array(false, '', '当前没有要上传的订单号');
        }
        $model = ShoporderdetailDao::getInstance();
        $res = $model->queryByTradenum($list);
        if(!$res){
            return array(false, '', '查询失败');
        }
        return array(true, $res, '查询运单号成功');
    }
    //前端插件---导入插入信息到excel
    public function insert_excel(){
        $list = $_POST['list'];
        \Think\Log::write(json_encode($list),'zk0621-a');
        $shopmes = array(
            'name' => '禾先生',
            'shop_id' => 1,
            'shop_type' => 0,
        );
        $orderModel = ShopordersDao::getInstance();
        venus_db_starttrans();//启动事务
        foreach($list as $orderData){
            /*if(!empty($orderData['address'])){
                $address = explode(' ', preg_replace("/[\s]+/is"," ",$orderData['address']));
                $orderData['buyer_state'] = $address[0];
                $orderData['buyer_city'] = $address[1];
                $orderData['buyer_district'] = $address[2];
                $orderData['buyer_address'] = $orderData['address'];
            }*/
            $orderStatus = $orderData['order_status'];
            if(!empty($orderStatus)){
                if($orderStatus == '买家已付款'){
                    $orderData['logistics_status'] = 0;
                }else{
                    $orderData['logistics_status'] = 3;
                    //continue;
                } 
            }
            if (trim($orderData['tradenum']) == '' && trim($orderData['buyer_state'])) {
                if (trim($orderData['address']) == '' && trim($orderData['buyer_mobile']) == '') {
                    continue;
                } else {
                    if (trim($orderData['address']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "买家地址不能为空");
                        return false;
                    }
                    if (trim($orderData['buyer_mobile']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "买家手机号不能为空");
                        return false;
                    }
                }
            } else {
                $orderModel = ShopordersDao::getInstance();
                $orderData['buyer_address'] = $orderData['address'];
                //查询订单号判断该订单是否已经存在
                $res = $orderModel->querymsgByTradenum($orderData['tradenum']);
                if($res){
                    continue;
                }
                $type = 1;
                $orderData['order_id'] = $this->get_trade_no($type);
                $orderData['updated_at'] = date("Y-m-d H:i:s");
                \Think\Log::write(json_encode($orderData),'zk0621-插入的数据内容');
                //$result = $result && $orderModel->insert($orderData, $shopmes);
                $result = $orderModel->insert($orderData, $shopmes);
                \Think\Log::write(json_encode($result),'zk0621-插入的结果');
            }
        }
        \Think\Log::write(json_encode($result),'zk0621-q');
        if ($result) {
            venus_db_commit();
            $success = true;
            $message = "店铺订单导入成功";

        } else {
            venus_db_rollback();
            $success = false;
            $message = "店铺订单导入失败";
        }
        return array($success, "", $message);


    }
    //前端插件---导入订单详情--new
    public function insert_detail3(){
        $list = $_POST['list'];
        \Think\Log::write(json_encode($list),'zk0621-b');
        venus_db_starttrans();//启动事务
        $orderModel = ShopordersDao::getInstance();
        $detail = ShoporderdetailDao::getInstance($this->warCode);
        foreach ($skuList as $orderData) {
            if (trim($orderData['tradenum']) == '') {
                if (trim($orderData['merchant']) == '' && trim($orderData['num']) == '') {
                    continue;
                } else {
                    if (trim($orderData['merchant']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "外部编号不能为空");
                        return false;
                    }
                    if (trim($orderData['num']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "商品数量不能为空");
                        return false;
                    }
                }
            } else { 
                //查询订单号判断该订单是否已经存在
                $res = $orderModel->querymsgByTradenum($orderData['tradenum']);
                if(empty($res)){
                    continue;
                }else{
                    $data = $detail->detailExist($orderData['tradenum'], $orderData['merchant']);
                    if(!empty($data)){
                        continue;
                    }
                    $orderData['order_id'] = $res['order_id'];
                    $result = $detail->insert($orderData);
                }                
            }            
        }
        if ($result) {
            venus_db_commit();
            $success = true;
            $message = "订单外部编号导入成功";

        } else {
            venus_db_rollback();
            $success = false;
            $message = "订单外部编号导入失败";
        }
        return array($success, "", $message);
    }

    //前端插件---导入订单详情 0719
    public function insert_detail2(){
        $list = $_POST['list'];
        \Think\Log::write(json_encode($list),'zk0621-b');
        venus_db_starttrans();//启动事务
        $orderModel = ShopordersDao::getInstance();
        $detail = ShoporderdetailDao::getInstance($this->warCode);
        venus_db_starttrans();//启动事务
        foreach ($list as $orderData) {
            if (trim($orderData['tradenum']) == '') {
                if (trim($orderData['merchant']) == '' && trim($orderData['num']) == '') {
                    continue;
                } else {
                    if (trim($orderData['merchant']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "外部编号不能为空");
                        return false;
                    }
                    if (trim($orderData['num']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "商品数量不能为空");
                        return false;
                    }
                }
            } else { 
                //查询订单号判断该订单是否已经存在
                $res = $orderModel->querymsgByTradenum($orderData['tradenum']);
                if(!empty($res)){
                    $content = $detail->detailBytradenum($orderData['tradenum']);  
                    if(!empty($content)){
                        $content2 = $detail->detailByMerchant($orderData['tradenum'], $orderData['merchant']); 
                        //判断该订单号，订单商品是否已经入数据库                 
                        if(empty($content2)){
                            $aa = $detail->queryLastOrderId($orderData['tradenum']);
                            $cc = explode('-', $aa['order_id']);
                            $num = $this->getNum($cc[1]);
                            $orderData['order_id'] = $res['order_id'];
                            $result = $detail->insert($orderData);
                            $a = strchr($orderData['tradenum'], '不发货');
                            if($a){
                                continue;
                            }  
                            $num = $num + 1;
                            //赠品是否拆单，查询商品表格
                            for($i=0;$i<$orderData['num'];$i++){
                                $dd = $i + $num;
                                $orderData['order_id'] = $res['order_id'].'-'.$this->getLetter($dd);
                                $orderData['seller_message'] = '';
                                $result = $detail->insideAdd($orderData);
                            }
                        }else{
                            continue;
                        }
                    }else{  
                        $message = $res['seller_message'];
                        $message = explode(',', $message);
                        if(in_array('1', $message)){
                            $orderData['order_id'] = $res['order_id'];
                            $result = $detail->insert($orderData);
                            $orderData['order_id'] = $res['order_id'].'-a';
                            $orderData['seller_message'] = '包装用防水袋';
                            $orderData['merchant'] = '0.5KG米砖';
                            $result = $detail->insideAdd($orderData);
                        }elseif(in_array('2', $message)){
                            $orderData['order_id'] = $res['order_id'];
                            $result = $detail->insert($orderData);
                            $orderData['order_id'] = $res['order_id'].'-a';
                            $orderData['seller_message'] = '包装用防水袋';
                            $orderData['merchant'] = '食盐一袋';
                            $result = $detail->insideAdd($orderData);
                        }elseif(in_array('3', $message)){ //备注3为小米为4罐
                            $orderData['order_id'] = $res['order_id'];
                            $result = $detail->insert($orderData);
                            $orderData['order_id'] = $res['order_id'].'-a';
                            $orderData['seller_message'] = $res['seller_message'];
                            $orderData['num'] = 4;//商品数量
                            $result = $detail->insideAdd($orderData);
                        }elseif(in_array('4', $message)){ //备注4为小米为7罐
                            $orderData['order_id'] = $res['order_id'];
                            $result = $detail->insert($orderData);
                            $orderData['order_id'] = $res['order_id'].'-a';
                            $orderData['seller_message'] = $res['seller_message'];
                            $orderData['num'] = 7;//商品数量
                            $result = $detail->insideAdd($orderData);
                        }else{
                            $orderData['order_id'] = $res['order_id'];
                            $result = $detail->insert($orderData);
                            //赠品是否拆单，查询商品表格
                            for($i=0;$i<$orderData['num'];$i++){
                                $orderData['order_id'] = $res['order_id'].'-'.$this->getLetter($i);
                                if($i == 0){
                                    $orderData['seller_message'] = $res['seller_message'];
                                    $result = $detail->insideAdd($orderData);
                                }else{
                                    $orderData['seller_message'] = '';
                                    $result = $detail->insideAdd($orderData);
                                }      
                            }
                        } 
                    }                   
                }else{
                    continue;
                }
                
            }            
        }
        if ($result) {
            venus_db_commit();
            $success = true;
            $message = "订单外部编号导入成功";

        } else {
            venus_db_rollback();
            $success = false;
            $message = "订单外部编号导入失败";
        }
        return array($success, "", $message);
    }

    //前端插件----导入订单详情no拆单
   public function insert_detail(){
        $list = $_POST['list'];
        \Think\Log::write(json_encode($list),'zk0621-b');
        venus_db_starttrans();//启动事务
        $orderModel = ShopordersDao::getInstance();
        $detail = ShoporderdetailDao::getInstance($this->warCode);
        venus_db_starttrans();//启动事务
        foreach ($list as $orderData) {
            if (trim($orderData['tradenum']) == '') {
                if (trim($orderData['merchant']) == '' && trim($orderData['num']) == '') {
                    continue;
                } else {
                    if (trim($orderData['merchant']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "外部编号不能为空");
                        return false;
                    }
                    if (trim($orderData['num']) == '') {
                        venus_db_rollback();//回滚事务
                        venus_throw_exception(1, "商品数量不能为空");
                        return false;
                    }
                }
            } else { 
                //查询订单号判断该订单是否已经存在
                $res = $orderModel->querymsgByTradenum($orderData['tradenum']);
                if(!empty($res)){
                    $data = $detail->detailExist($orderData['tradenum'], $orderData['merchant']);
                    if(!empty($data)){
                        continue;
                    }
                    $orderData['order_id'] = $res['order_id'];
                    $result = $detail->insert($orderData);
                    //插入人工分单表格中,将数据插入点到人工拆单的列表中
                    for($i=0;$i<$orderData['num'];$i++){
                        $orderData['num'] = 1;

                    }                   
                }else{
                    continue;
                }
                
            }            
        }
        if ($result) {
            venus_db_commit();
            $success = true;
            $message = "订单外部编号导入成功";

        } else {
            venus_db_rollback();
            $success = false;
            $message = "订单外部编号导入失败";
        }
        return array($success, "", $message);
    }


    //显示每日生成的excel
    public function all_excel(){
       $time = date('Ymd');
       $dir =  C("FILE_SAVE_PATH").'erp/'.$time;
       $files = scandir($dir);


    }
    /*
     *每日PDF，Excel载链接展示
     *@describe 日期+字母+3位数
     */
    public function files_list(){
        $start = $_POST['data']['startTime'];
        $end = $_POST['data']['endTime'];
        if(empty($start) || empty($end)){
            $start = date("Y-m-d 00:00:00");
            $end = date("Y-m-d 23:59:59");
        }
        $condition['timestamp'] = array('between', array($start, $end));
        $condition['subordinate_departments'] = array('in', array(22, 23));
        $list = ShopordersDao::getInstance()->queryCountByCondition($condition);

        if(!$list){
            return array(false, '', '暂无数据');
        }
        return array(true, $list, '数据查询成功');
    }


    /*
     *生成内部订单号
     *@describe 日期+字母+3位数
     */
    function get_trade_no($type){

        $model = ShopordersDao::getInstance();
        $now = time ();
        $expireAt = $now + 10 * 60;
        $created_day = date ( 'Ymd', $now );   
        $last_id = $model->get_last($created_day,$type);
        if (! $last_id) {
          return false;
        }
        return $this->generateTradeNo($now , $last_id, 'zw');
    }
    /*
     *@describe 订单号
     *@param $now 当前时间
     *@param $trade_day_id 当天交易量总额数
     *@author  zk
      */    
    function generateTradeNo($now, $trade_day_id, $type) {
        $aa = date ( 'Ymd', $now ) . $type . str_replace ( " ", "", sprintf ( "%04d", $trade_day_id ) );
        return $aa;
    }
    //字母数组
    public function getLetter($nu){
        $arr = array('a','b','c','d','e','f','g','h','i','j','k','l','m','n','o','p','q','r','s','t','u','v','w','x','y','z','aa','ab','ac','ad','ae','af','ag','ah','ai','aj','ak','al','am','an','ao','ap','aq','ar','as','at','au','av','aw','ax','ay','az','ba','bb','bc','bd','be','bf','bg','bh','bi','bj','bk','bl','bm','bn','bo','bp','bq','br','bs','bt','bu','bv','bw','bx','by','bz');
        return $arr[$nu];
    }
    //根据键值查找键名
    public function getNum($letter){
        $arr = array('a','b','c','d','e','f','g','h','i','j','k','l','m','n','o','p','q','r','s','t','u','v','w','x','y','z','aa','ab','ac','ad','ae','af','ag','ah','ai','aj','ak','al','am','an','ao','ap','aq','ar','as','at','au','av','aw','ax','ay','az','ba','bb','bc','bd','be','bf','bg','bh','bi','bj','bk','bl','bm','bn','bo','bp','bq','br','bs','bt','bu','bv','bw','bx','by','bz');
        return array_search($letter, $arr);
    }

}



