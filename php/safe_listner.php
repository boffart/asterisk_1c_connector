<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 8 2018
 */

require_once __DIR__.'/phpagi-asmanager.php';
$settings = include(__DIR__.'/settings.php');

$am = new AGI_AsteriskManager();
$res = $am->connect("{$settings['ami_host']}:{$settings['ami_port']}",
				    "{$settings['ami_user']}",
				    "{$settings['ami_pass']}", 
				    'on');
$exit_code = 0;
if(true == $res){
    $params = ['Operation'=>'Add', 'Filter' => 'Event: UserEvent'];
    $am->send_request_timeout('Filter', $params);
    
    $result = $am->ping_ami_listner('1cConnector');
    if($result == false){
        // Проверим, вероятно есть скрипт, который не отвечает.
        $out = [];
        $sc_name = 'worker_1c_ami_listener.php';
        exec("ps -A -o 'pid,args' | grep '$sc_name' | grep -v grep | awk ' {print $1} '", $out);
        $WorkerPID = trim(implode(' ', $out));
        if(!empty($WorkerPID)){
            // Завершаем процесс.
            exec("kill $WorkerPID > /dev/null 2>&1 &");
        }
        // Запускаем новый скрипт.
        $command = "php ".__DIR__."/{$sc_name} start";
        exec("nohup $command > /dev/null 2>&1 &");

        $result = $am->ping_ami_listner('1cConnector');
        if($result == false) {
            $exit_code = 2;
        }
    }

}else{	
 	echo "Ошибка подключения к АТС.";
    $exit_code = 1;
}

exit($exit_code);
