<?php
const SQLITE_DB = "/opt/job/job.db";
//创建socket server
$server = new swoole_server("127.0.0.1", 9501);
$server->set(array(
    'worker_num' => 1,//设置启动的Worker进程数
    'reactor_num' => 1,//设置主进程内事件处理线程的数量
    'task_worker_num' => 50,//设置异步任务的工作进程数量
    'daemonize' => 1,//守护进程化
    'log_file' => "/opt/job/log",//指定swoole错误日志文件
    'enable_coroutine' => true,//底层自动在onRequest回调中创建协程
    'task_enable_coroutine' => true,//Task工作进程支持协程
));
//创建内存表
$table = new swoole_table(2048);//参数指定表格的最大行数
$table->column("timerId", swoole_table::TYPE_INT, 10);
$table->column("key", swoole_table::TYPE_STRING, 50);
$table->create();
//创建调度进程,目前单worker进程模式下没必要使用，多开workers 进程是需要的，因为 clear() 不能用于清除其他进程的定时器，只作用于当前进程
$process = new swoole_process(function (swoole_process $worker) {
    global $server;
    swoole_set_process_name("JobHolder");
    //将管道加入到事件循环中
    swoole_event_add($worker->pipe, function ($pipe) use ($worker) {
        $data = $worker->read();
        var_dump("got msg:{$data}");
        $obj = json_decode($data, JSON_OBJECT_AS_ARRAY);
        switch ($obj["action"]) {
            case "exit":
                $worker->exit();
                break;
            case "kill":
                swoole_process::kill($worker->pid);
                break;
            case "a":
                swoole_timer::after(60000, function () {
                    var_dump("test");
                });
                break;
            case "b":
                var_dump(swoole_timer::list());
                break;
            case "c":
                swoole_timer::clear($obj["data"]);
                break;
            default:
                $worker->write("?");
                break;
        }
    });
}, false, SOCK_DGRAM, true);
//$process->useQueue(1, 2 | swoole_process::IPC_NOWAIT);
$process->start();
//必须注册信号SIGCHLD对退出的进程执行wait
swoole_process::signal(SIGCHLD, function ($sig) use ($process) {
    var_dump("signal coming...");
    swoole_event_del($process->pipe);
    swoole_process::signal(SIGCHLD, null);
    //必须为false，非阻塞模式
    while ($ret = swoole_process::wait(false)) {
        echo "PID={$ret['pid']}\n";
    }
    swoole_event::exit();
});
//在4.4版本中不再将信号监听作为EventLoop退出的block条件。因此在程序中如果只有信号监听的事件，进程会直接退出。
//swoole_event::wait();
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
$server->on('receive', function (swoole_server $server, $fd, $reactor_id, $data) {
    global $table, $process;
    $formattedData = trim(strval($data));
    $action = $formattedData;
    $jsonObj = null;//["action" => "xxxx","value" => "xxxx"]
    if (strpos($formattedData, "{") !== false) {
        $jsonObj = json_decode($formattedData, JSON_OBJECT_AS_ARRAY);
        $action = $jsonObj["action"];
    }
    switch ($action) {
        case "":
            $server->send($fd, PHP_EOL);
            break;
        case "bye":
            $server->close($fd);
            break;
        case "add":
            $sqlite = new SQLite3(SQLITE_DB);
            $sqlite->exec('CREATE TABLE IF NOT EXISTS job (key STRING,value STRING,timer STRING)');
            $obj = $jsonObj["data"];
            //launch the timer
            $id = swoole_timer::after(20000, function ($value, swoole_server $server) {
                //投递异步任务
                $task_id = $server->task($value);
                var_dump("task created,id:{$task_id}");
            }, $obj, $server);
            //insert to DB
            $jsonStr = json_encode($obj["value"]);
            $sqlite->exec("insert into job values('{$obj["key"]}','{$jsonStr}',{$id})");
            //save to table
            $table->set($id, [
                'timerId' => $id,
                'key' => $obj["key"]
            ]);
            $server->send($fd, "PUSHED" . PHP_EOL);
            $server->close($fd);
            break;
        case "del":
            $id = $jsonObj["data"];
            //check if exists
            if ($table->exist($id)) {
                $key = $table->get($id, "key");
                // delete from DB
                $sqlite = new SQLite3(SQLITE_DB);
                $sqlite->exec("delete from job where key = {$key}");
                // remove from table
                $table->del($id);
                // clear the timer
                swoole_timer::clear($id);
                $server->send($fd, "OK" . PHP_EOL);
            } else {
                $server->send($fd, "NOT FOUND" . PHP_EOL);
            }
            break;
        case "list":
            $sqlite = new SQLite3(SQLITE_DB);
            $res = $sqlite->query("select * from job");
            $list = [];
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $list[] = $row;
            }
            $server->send($fd, json_encode($list) . PHP_EOL);
            break;
        case "jobInfo":
            $list = swoole_timer::list();
            var_dump($list);
            $server->send($fd, "OK" . PHP_EOL);
            break;
        case "testJob":
            $sqlite = new SQLite3(SQLITE_DB);
            $sqlite->exec('CREATE TABLE IF NOT EXISTS job (key STRING,value STRING,timer STRING)');
            for ($i = 0; $i < 1000; $i++) {
                //launch the timer
                $id = swoole_timer::after(20000, function ($value, swoole_server $server) {
                    //投递异步任务
                    $task_id = $server->task($value);
                    var_dump("task created,id:{$task_id}");
                }, $i, $server);
                $sqlite->exec("insert into job values({$i},'test',{$id})");
                //save to table
                $table->set($id, [
                    'timerId' => $id,
                    'key' => $i
                ]);
            }
            var_dump($table->count());
            $server->send($fd, "OK" . PHP_EOL);
            break;
        case "clearJob":
            $sqlite = new SQLite3(SQLITE_DB);
            $list = swoole_timer::list();
            foreach ($list as $k => $v) {
                swoole_timer::clear($v);
                $table->del($v);
                // delete from DB
                $sqlite->exec("delete from job where timer = {$v}");
            }
            $server->send($fd, "OK" . PHP_EOL);
            break;
        case "quitProc":
            $arg = [
                "action" => "exit"
            ];
            $process->write(json_encode($arg));
            $server->send($fd, "OK" . PHP_EOL);
            break;
        case "killProc":
            $arg = [
                "action" => "kill"
            ];
            $process->write(json_encode($arg));
            $server->send($fd, "OK" . PHP_EOL);
            break;
        case "testProc":
            $arg = [
                "action" => "test"
            ];
            $process->write(json_encode($arg));
            var_dump($process->read());
            $server->send($fd, "OK" . PHP_EOL);
            break;
        case "a":
            for ($i = 0; $i < 1000; $i++) {
                $arg = [
                    "action" => "a"
                ];
                $process->write(json_encode($arg));
            }
            $server->send($fd, "OK" . PHP_EOL);
            break;
        case "b":
            $arg = [
                "action" => "b"
            ];
            $process->write(json_encode($arg));
            $server->send($fd, "OK" . PHP_EOL);
            break;
        case "c":
            for ($i = 1; $i <= 1000; $i++) {
                $arg = [
                    "action" => "c",
                    "data" => $i
                ];
                $process->write(json_encode($arg));
            }
            $server->send($fd, "OK" . PHP_EOL);
            break;
        default:
            $server->send($fd, "Unknown syntax: {$action}");
            $server->close($fd);
    }
});
//处理异步任务
$server->on('task', function ($serv, swoole_server_task $task) {
//    sleep(1);
    var_dump("this is job" . $task->data);
    $swoole_mysql = new \Swoole\Coroutine\MySQL();
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
    $task->finish($task->data);
});

//处理异步任务的结果
$server->on('finish', function ($serv, $task_id, $data) {
    global $table;
    $sqlite = new SQLite3(SQLITE_DB);
    $res = $sqlite->query("select * from job where key = {$data}");
    foreach ($res->fetchArray(SQLITE3_ASSOC) as $k => $v) {
        //从table 中删除
        if ($k == "timer") {
            $table->del($v);
        }
    }
    //从sqlite 删除该条任务的记录
    $sqlite->exec("delete from job where key = {$data}");
    //不需要去管定时器，待触发完毕会自动销毁
    echo "AsyncTask[$task_id] Finish: $data" . PHP_EOL;
});
$server->on('close', function ($server, $fd) {
    echo "connection close: {$fd}\n";
});
$server->start();