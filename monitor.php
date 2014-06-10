<?php
require 'vendor/autoload.php';

$LOG_DB_PATH = "/var/run/logstash";
$LOG_2_MONITOR = "/var/log/apache2/error.log.1";
$LOG_DB_FILE_PATH = $LOG_DB_PATH."/".basename($LOG_2_MONITOR).".pos";

//~ Initialize the client
$client = new Elasticsearch\Client();

//~ Get the last position in the log file
$logHandlePosition = file_exists($LOG_DB_FILE_PATH)?chop(file_get_contents($LOG_DB_FILE_PATH)):0;
$logHandlePosition = intval($logHandlePosition)>0?intval($logHandlePosition):0;

$handle = @fopen($LOG_2_MONITOR, "r");
if ($handle) {
  //~ Positioning the pointer to the last location
  fseek($handle, $logHandlePosition);
  
  //~ Digesting the new log
  while (($buffer = fgets($handle, 4096)) !== false) {
    $lineInfos = array();
  
    $process = chop(preg_replace('/^\[.+?\] \[(.+?)\:.+?\].*$/', '$1', $buffer));
    $lineInfos[$process] = chop(preg_replace('/^\[.+?\] \[.+?\:(.+?)\].*$/', '$1', $buffer));
    
    $lineInfos['pid'] = chop(preg_replace('/^\[.+?\] \[.+?\] \[pid (.+?)\].*$/', '$1', $buffer));
    $lineInfos['message'] = chop(preg_replace('/^\[.+?\] \[.+?\] \[.+?\] (.*)$/', '$1', $buffer));
    $lineInfos['server_ip'] = "";
    $lineInfos['server_name'] = "arm64 A3";
  
    //~ Get time stamp
    $log_datetime = chop(preg_replace('/^\[(.+?)\].*$/', '$1', $buffer));
    $log_datetime = date_create_from_format('D M d H:i:s.u Y',$log_datetime);
    $lineInfos['log_time'] = $log_datetime->format('Y-m-d H:i:s');
    
    $params = array();
    $params['body']  = $lineInfos;
    $params['index'] = 'demo';
    $params['type']  = 'apache_logs';
    $params['timestamp'] = $log_datetime->getTimestamp();
    //~ $params['id']    = 'my_id'; Let the system create the index
    
    //~ Send the data to elasticsearch
    $ret = $client->index($params);
    
    echo ftell($handle)." : ".$buffer;
  }
  
  file_put_contents($LOG_DB_FILE_PATH, ftell($handle));
  
  if (!feof($handle)) {
    echo "Error: unexpected fgets() fail\n";
  }
  fclose($handle);
}

?>
