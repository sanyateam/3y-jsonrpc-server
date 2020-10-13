<?php
/**
 * Who ?: Chaz6chez
 * How !: 250220719@qq.com
 * Where: http://chaz6chez.top
 * Time : 2019/6/5|14:10
 * What : Creating Fucking Bug For Every Code
 */

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
$server = new RpcServer('JsonRpc2://0.0.0.0:5252');
# 设置allow
$server->setAllow([
    'Test'
]);
# 进程数
$server->count  = 8;
# 端口复用
$server->reusePort = true;

if (!defined('GLOBAL_START')){
    RpcServer::$logFile = LOG_PATH . "/{$server->name}.log";
    RpcServer::runAll();
}