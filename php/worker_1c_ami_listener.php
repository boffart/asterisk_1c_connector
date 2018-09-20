<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 8 2018
 */

/*
     curl -u web:123 -X POST -H "IBSession: start" \
        -d 'new Structure("test", 1)' \
        'http://172.16.156.128/unf/hs/ami_events';
 */

require_once __DIR__.'/phpagi-asmanager.php';

class WorkerAmiListener {
    private $am 	  = null;
    private $settings = [];

    /**
     * worker_ami_listener constructor.
     * @param array $settings
     * @throws Exception
     */
    function __construct($settings){
	    $this->settings = $settings;
	    $this->login();
        $this->set_filter();
    }
    
    /**
     * Подключение к AMI. 
     */
    public function login(){
        $this->am = new AGI_AsteriskManager();
        $res = $this->am->connect("{$this->settings['ami_host']}:{$this->settings['ami_port']}",
                                  "{$this->settings['ami_user']}",
        						  "{$this->settings['ami_pass']}",
        						  'on');
        if(true == $res){
	     	// Успешно подключились.    
        }else{	
            \WorkerAmiListener::sys_log_msg('AMI_WORKER_EXCEPTION', "Ошибка подключения к АТС.");
        }
    }

    /**
     * Функция обработки оповещений.
     * @param $parameters
     */
    public function callback($parameters){
        if(isset($parameters['UserEvent']) && '1cConnectorPing' == $parameters['UserEvent']){
            usleep(50000);
            $this->am->UserEvent("1cConnectorPong", []);
        }else{
	        $this->Action_SendTo1c($parameters);
        }
    }
	
    /**
     * Старт работы листнера.
     */
    public function start(){
        $this->am->add_event_handler('*', [$this, "callback"]);
        while (true) {
            $result = $this->am->wait_user_event(true);
            if($result == false){
                // Нужен реконнект.
                sleep(2);
				$this->login();
                $this->set_filter();
            }
        }
    }

    /**
     * Установка фильтра
     * @return array
     */
    private function set_filter(){
        $result = null;
        $filters = [
            'Event: Newstate',
            'Event: Hangup',
            'UserEvent: 1cConnectorPing',
            'Event: AttendedTransfer'
        ];
        foreach ($filters as $filter){
            $params   = ['Operation'=>'Add', 'Filter' => $filter];
            $result   = $this->am->send_request_timeout('Filter', $params);
        }
        return $result;
    }

    /**
     * Сборка ответа. Отправка данных в 1С.
     * @param array $parameters
     */
    private function Action_SendTo1c($parameters){
        $result_part1 = 'Result';
        $result_part2 = 'true';
        foreach($parameters as $key => $value){

            $result_part1.= ($result_part1!='')?',':'';
            $result_part2.= ($result_part2!='')?',':'';

            $vowels = array('"', "'", '-');
            $var_name = str_replace($vowels, '', $key);
            $result_part1 .= "$var_name";
            $result_part2 .= "\"$value\"";
        }
        $result = 'New Structure("'.$result_part1.'", '.$result_part2.')';
        
		$this->http_post_data($result);
    }

    /**
     * Отправка данных по http.
     * @param      $value
     * @param bool $relogin
     */
    private function http_post_data($value, $relogin = false){
        $curl = curl_init();
        $url 	= "http://{$this->settings['1c_host']}:{$this->settings['1c_port']}/{$this->settings['1c_base_name']}/hs/ami_events";
        $ckfile = "/tmp/1c_session_cookie.txt";

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $value);
        curl_setopt($curl, CURLOPT_USERPWD, $this->settings['1c_user'].":".$this->settings['1c_pass']);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);

        if ($relogin) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, array("IBSession: start"));
            curl_setopt($curl, CURLOPT_COOKIEJAR, $ckfile);
        } else {
            curl_setopt($curl, CURLOPT_COOKIEFILE, $ckfile);
        }
        $resultrequest  = curl_exec($curl);
        $http_code      = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (in_array($http_code, array(400, 500)) and $relogin == false) {
            $this->http_post_data($value, true);
        }

        if($http_code != 200){
            \WorkerAmiListener::sys_log_msg('AMI_WORKER_HTTP_EXCEPTION', $resultrequest);
        }

    }

    /**
     * Добавить сообщение в Syslog.
     * @param     $log_name
     * @param     $text
     * @param int $level
     */
    static function sys_log_msg($log_name, $text, $level=null){
        $level = ($level==null)?LOG_WARNING:$level;
        openlog("$log_name", LOG_PID | LOG_PERROR, LOG_AUTH);
        syslog($level, "$text");
        closelog();
    }

}

if(count($argv)>1 && $argv[1] == 'start') {
    try{
	    $settings = include(__DIR__.'/settings.php');
        $listener = new \WorkerAmiListener($settings);
        $listener->start();
    }catch (Exception $e) {
        \WorkerAmiListener::sys_log_msg('AMI_WORKER_EXCEPTION', $e->getMessage());
    }
}else{
    \WorkerAmiListener::sys_log_msg('AMI_WORKER_EXCEPTION', 'Action not selected.');
}


