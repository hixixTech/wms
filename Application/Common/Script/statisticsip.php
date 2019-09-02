<?php
ini_set('memory_limit', '356M');
define('APP_DIR', dirname(__FILE__) . '/../../../');
define('APP_DEBUG', true);
define('APP_MODE', 'cli');
define('APP_PATH', APP_DIR . './Application/');
define('RUNTIME_PATH', APP_DIR . './Runtime_script/'); // 系统运行时目录
require APP_DIR . './ThinkPHP/ThinkPHP.php';

$time = venus_script_begin("统计访问系统的ip次数");

$readFile = '/usr/local/nginx/logs/error.log';//读取文件
$writeFile = '/home/wms/app/Public/spufiles/ip.txt';//写入文件
$confFile = '/usr/local/nginx/conf/vhosts/ip.conf';//配置文件
//$readFile = 'C:/Users/gfz_1/Desktop/error.log';//读取文件
//$writeFile = 'C:/Users/gfz_1/Desktop/ip.txt';//写入文件
//$writeFiles = 'C:/Users/gfz_1/Desktop/ipe.txt';//写入文件
$arr_new = array();
$new_file = array();
$prohibitIp = "";
if (file_exists($readFile)) {//判断该文件是否存在
    $file_arr = file($readFile);//得到数组

    foreach ($file_arr as $value) {//对数组的处理
        $logList = trim($value);
        $logList = explode(",", $logList);

        if(preg_match("/%|.php/",$logList[3])){
            $logsTime = substr($logList[0], 0, strpos($logList[0], ' '));
            $theDayBefore = date('Y/m/d', strtotime('-1 days'));//上周周一
            if ($logsTime == $theDayBefore) {
                $ip = substr($logList[1], 8, strpos($logList[0], ': '));
                $arr_new[] = trim($ip);
            }
        }
    }
    $arr_new = array_count_values($arr_new);
    if(!empty($arr_new)){
        arsort($arr_new);
        $statisticsIp = json_encode($arr_new);
        file_put_contents($writeFile, $statisticsIp);

        $new_file = file_get_contents($writeFile);
        $new_file = json_decode($new_file);
        foreach ($new_file as $key => $val) {
            $prohibitIp[] = "deny"." ".$key;
        }
        file_put_contents($confFile, PHP_EOL.implode(";" . PHP_EOL, $prohibitIp).";", FILE_APPEND);
    }
} else {

    echo "文件不存在!";
}

venus_script_finish($time);
exit();




