#!/usr/bin/php
<?php
require 'vendor/autoload.php';

$CONFIG_FILE_PATH = "monitor.json";
$globalConfig = array();
if( file_exists($CONFIG_FILE_PATH) ){ $globalConfig = json_decode(file_get_contents($CONFIG_FILE_PATH,true)); }

#~ Get the file database path to record where we stopped the last time
$LOG_DB_PATH = $globalConfig->config->log_db_path; //~ "/var/run/logstash";

//~ Walking through provided inputs
foreach($globalConfig->inputs as $key => $data){

  $LOG_2_MONITOR = $data->filepath;//~ "/var/log/apache2/error.log.1";
  $LOG_DB_FILE_PATH = $LOG_DB_PATH."/".basename($LOG_2_MONITOR).".pos";
  
  echo "[".date('Y/m/d H:i:s')."] Monitoring ".$LOG_2_MONITOR;
  
  
  //~ Initialize the client
  $client = new Elasticsearch\Client();
  
  //~ Get the last position in the log file
  $logHandlePosition = file_exists($LOG_DB_FILE_PATH)?chop(file_get_contents($LOG_DB_FILE_PATH)):0;
  $logHandlePosition = intval($logHandlePosition)>0?intval($logHandlePosition):0;
  
  $handle = @fopen($LOG_2_MONITOR, "r");
  if ($handle) {
    //~ Positioning the pointer to the last location
    fseek($handle, $logHandlePosition);
    
    $nbLines = 0;
    
    //~ Digesting the new log
    while (($buffer = fgets($handle, 4096)) !== false) {
      $lineInfos = array();
    
      $pattern = $data->pattern;//~ '/^\[(.+?)\] \[(.+?)\:(.+?)\] \[pid (\d+?)\] (.+?): (.*)$/';
      $buffer = trim($buffer);
      
      //~ We are filling up the data from the params section
      foreach($data->params_body as $key => $value){
        $lineInfos[$key] = preg_replace($pattern, $value, $buffer);
      }
    
      //~ Get time stamp
      $log_datetime = date_create_from_format($data->timestamp_format,$lineInfos['timestamp']);
      
      //~ Preparing elasticsearch payload
      $params = array();
      $params['body']  = $lineInfos;
      $params['index'] = $data->index;
      $params['type']  = $data->type;
      $params['timestamp'] = $log_datetime->getTimestamp();
      //~ $params['id']    = 'my_id'; Let the system create the index
      
      //~ Send the data to elasticsearch
      $ret = $client->index($params);
      
      $nbLines++;
    }
    
    echo " $nbLines \n";
    
    //~ Record the last position in the file
    //~ to not restart from the begining all the time
    file_put_contents($LOG_DB_FILE_PATH, ftell($handle));
    
    //~ Sends error out if we didn't reach the end if the file
    if (!feof($handle)) {
      echo "Error: unexpected fgets() fail\n";
    }
    
    //~ Close the file
    fclose($handle);
  }
  else{ echo "Unable to open file: ".$LOG_2_MONITOR."\n"; }

} //~ foreach

?>
