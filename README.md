# wm-jsonrpc-server

***
A JsonRpc-Server for WorkerMan

***

## 说明

- 服务基于workerman常驻内存
- 基于TCP通讯协议
- 基于JsonRpc-2.0业务协议
- 支持全双工

##使用

- 需要启动文件（例：launcher.php）如下
~~~
use JsonRpcServer\RpcServer;

if (!defined('GLOBAL_START')){
    ini_set('date.timezone','Asia/Shanghai');
    define('SERVER_PATH', __DIR__);
    define('ROOT_PATH', dirname(SERVER_PATH));
    define('LOG_PATH', SERVER_PATH . '/log');
    require_once ROOT_PATH . '/vendor/autoload.php';
}

# API server
//$server = new RpcServer('JsonRpc2://[::]:5252');
$server = new RpcServer('JsonRpc2://127.0.0.1:5252');
# 进程数
$server->count  = 8;
# 端口复用
$server->reusePort = true;

if (!defined('GLOBAL_START')){
    RpcServer::$logFile = LOG_PATH . "/{$server->name}.log";
    RpcServer::runAll();
}
~~~
- 与launcher.php同级创建目录log
- 使用基于workerman的命令行操作启动
    - 常驻启动
    ~~~
    php launcher.php start -d
    ~~~
    - debug启动
    ~~~
    php launcher.php start
    ~~~
    - windows 下启动
    ~~~
    php launcher.php
    ~~~