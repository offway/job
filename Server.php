<?php
$server = new swoole_server("127.0.0.1", 9501);
$server->set(array(
    'worker_num' => 4,//设置启动的Worker进程数
    'reactor_num' => 4,//设置主进程内事件处理线程的数量
    'task_worker_num' => 50,//设置异步任务的工作进程数量
    'daemonize' => 1,//守护进程化
    'log_file' => "/home/admin/log",//指定swoole错误日志文件
    'enable_coroutine' => true,//底层自动在onRequest回调中创建协程
    'task_enable_coroutine' => true,//Task工作进程支持协程
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
    $action = $formattedData;
    $jsonObj = null;
    if (strpos($formattedData, "{") != false) {
        $jsonObj = json_decode($formattedData, JSON_OBJECT_AS_ARRAY);
        $action = $jsonObj["action"];
    }
    switch ($action) {
        case "":
            $server->send($fd, PHP_EOL);
            break;
        case "88":
            $server->close($fd);
            break;
        case "add":
            $sqlite = new SQLite3("job.db");
            $sqlite->exec('CREATE TABLE IF NOT EXISTS job (key STRING,value STRING)');
            $sqlite->exec("insert into job values({$jsonObj["key"]},{$jsonObj["value"]})");
            $id = \Swoole\Timer::after(20000, function ($i, $server) {
                //投递异步任务
                $task_id = $server->task($i);
                var_dump("taskId is {$task_id}");
            }, $jsonObj["value"], $server);
            var_dump("timerId is {$id}");
            $server->send($fd, $id . PHP_EOL);
            break;
        case "jobList":
            $sqlite = new SQLite3("job.db");
            $res = $sqlite->query("select * from job");
            $list = [];
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $list[] = $row;
            }
            var_dump($list);
            $server->send($fd, json_encode($list) . PHP_EOL);
            break;
        case "testJob":
            $sqlite = new SQLite3("job.db");
            $sqlite->exec('CREATE TABLE IF NOT EXISTS job (key STRING,value STRING)');
            for ($i = 0; $i < 1000; $i++) {
                $sqlite->exec("insert into job values({$i},'test')");
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
            $server->send($fd, json_encode($list) . PHP_EOL);
            break;
        default:
            $server->send($fd, "Unknown syntax: {$data}");
    }
});
//处理异步任务
$server->on('task', function ($serv, Swoole\Server\Task $task) {
//    sleep(1);
    var_dump("this is job" . $task->data);
    $swoole_mysql = new Swoole\Coroutine\MySQL();
    $swoole_mysql->connect([
        'host' => 'rm-uf6bdv92a95017474oo.mysql.rds.aliyuncs.com',
        'port' => 3306,
        'user' => 'puhao',
        'password' => 'Puhao2018',
        'database' => 'phweb_dev',
    ]);
    if ($swoole_mysql->connected) {
        $res = $swoole_mysql->query('show databases');
        var_dump($res);
    } else {
        var_dump($swoole_mysql->errno);
        var_dump($swoole_mysql->error);
        var_dump($swoole_mysql->connect_errno);
        var_dump($swoole_mysql->connect_error);
    }
    //返回任务执行的结果
    $task->finish(true);
});

//处理异步任务的结果
$server->on('finish', function ($serv, $task_id, $data) {
    echo "AsyncTask[$task_id] Finish: $data" . PHP_EOL;
});
$server->on('close', function ($server, $fd) {
    echo "connection close: {$fd}\n";
});
$server->start();