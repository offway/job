<?php
$server = new swoole_server("127.0.0.1", 9501);
$server->set(array(
    'worker_num' => 4,//设置启动的Worker进程数
    'reactor_num' => 1,//设置主进程内事件处理线程的数量
    'task_worker_num' => 100,//设置异步任务的工作进程数量
    'daemonize' => 1,//守护进程化
    'log_file' => "/home/admin/log"//指定swoole错误日志文件
));
$server->on("start", function ($server) {
    swoole_set_process_name("JobMaster");
});
$server->on("managerStart", function ($server) {
    swoole_set_process_name("JobManager");
});
$server->on("workerStart", function ($server, $worker_id) {
    swoole_set_process_name("JobWorker_" . $worker_id);
});
$server->on('connect', function ($server, $fd) {
    echo "connection open: {$fd}\n";
});
$server->on('receive', function ($server, $fd, $reactor_id, $data) {
    $formattedData = trim(strval($data));
    switch ($formattedData) {
        case "":
            $server->send($fd, PHP_EOL);
            break;
        case "88":
            $server->close($fd);
            break;
        case "job":
            for ($i = 0; $i < 1000; $i++) {
                $id = \Swoole\Timer::after(20000, function ($i, $server) {
                    //投递异步任务
                    $task_id = $server->task($i);
                    var_dump("taskId is {$task_id}");
                }, $i, $server);
                var_dump("timerId is {$id}");
            }
            $server->send($fd, "OK" . PHP_EOL);
            break;
        case "jobInfo":
            $list = \Swoole\Timer::list();
            var_dump($list);
            $server->send($fd, "OK" . PHP_EOL);
            break;
        default:
            $server->send($fd, "Unknown syntax: {$data}");
    }
});
//处理异步任务
$server->on('task', function ($serv, $task_id, $from_id, $data) {
    sleep(1);
    var_dump("this is job" . $data);
    //返回任务执行的结果
    $serv->finish("$data -> OK");
});

//处理异步任务的结果
$server->on('finish', function ($serv, $task_id, $data) {
//    echo "AsyncTask[$task_id] Finish: $data" . PHP_EOL;
});
$server->on('close', function ($server, $fd) {
    echo "connection close: {$fd}\n";
});
$server->start();