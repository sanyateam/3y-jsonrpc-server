<?php

namespace JsonRpcServer;

use JsonRpcServer\Exception\MethodNotFoundException;
use JsonRpcServer\Exception\RpcException;
use JsonRpcServer\Exception\ServerErrorException;
use JsonRpcServer\Format\ErrorFmt;
use JsonRpcServer\Format\JsonFmt;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

class RpcServer extends Worker {
    /**
     * Used to save user OnWorkerStart callback settings.
     *
     * @var callable
     */
    protected $_onWorkerStart = null;

    /**
     * 已注册的服务
     * @var array
     */
    protected static $_registered = [];

    /**
     * Construct.
     *
     * @param string $socket_name
     * @param array $context_option
     */
    public function __construct($socket_name, array $context_option = []) {
        list(, $address) = \explode(':', $socket_name, 2);
        parent::__construct("jsonRpc2:{$address}", $context_option);
        $this->name = 'JsonRpcServer';
    }

    /**
     * Run webserver instance.
     *
     * @see Workerman.Worker::run()
     */
    public function run() {
        $this->_onWorkerStart = $this->onWorkerStart;
        $this->onClose        = [$this, 'onClose'];
        $this->onConnect      = [$this, 'onConnect'];
        $this->onWorkerStop   = [$this, 'onWorkerStop'];
        $this->onWorkerReload = [$this, 'onWorkerReload'];
        parent::run();
    }

    public function onWorkerStart() {
        $this->register();
    }

    public function onWorkerStop() {

    }

    public function onWorkerReload() {

    }

    public function onWorkerClose() {

    }

    /**
     * @param TcpConnection $connection
     * @param array $data 解析协议后的内容
     * @return bool|null
     */
    public function onMessage(TcpConnection $connection, $data) {
        list($exception, $buffer) = $data;
        $fmt = JsonFmt::factory($buffer);
        $resFmt = clone $fmt;
        $resFmt->clean();
        $resFmt->id = $fmt->id ? $fmt->id : null;
        $errorFmt = ErrorFmt::factory();
        # 异常检查
        if($exception instanceof RpcException){
            $errorFmt->code = $exception->getCode();
            $errorFmt->message = $exception->getMessage();
            $resFmt->error = $errorFmt->outputArray($errorFmt::FILTER_STRICT);
            return $connection->send($resFmt->outputArrayByKey($resFmt::FILTER_STRICT, $resFmt::TYPE_RESPONSE));
        }
        $e = new ServerErrorException();
        if($exception instanceof \Exception){
            $errorFmt->code    = $e->getCode();
            $errorFmt->message = $e->getMessage();
            $errorFmt->data    = $exception->getTraceAsString();
            $resFmt->error     = $errorFmt->outputArray($errorFmt::FILTER_STRICT);
            return $connection->send($resFmt->outputArrayByKey($resFmt::FILTER_STRICT, $resFmt::TYPE_RESPONSE));
        }

        $class  = explode('.', $fmt->method);
        $method = array_pop($class);
        $class  = implode('\\', $class);
        if(
            !class_exists($class) or
            method_exists($class,$method)
        ){
            $e = new MethodNotFoundException();
            $errorFmt->code    = $e->getCode();
            $errorFmt->message = $e->getMessage();
            $resFmt->error     = $errorFmt->outputArray($errorFmt::FILTER_STRICT);
            return $connection->send($resFmt->outputArrayByKey($resFmt::FILTER_STRICT, $resFmt::TYPE_RESPONSE));
        }
        # 调用
        try {
            $class = new $class;
            # failed
            if(!$resFmt->result = call_user_func_array([$class, $method], $fmt->params)){
                $resFmt->result = null;
                $errorFmt->code    = $e->getCode();
                $errorFmt->message = $e->getMessage();
                $params            = json_encode($fmt->params,JSON_UNESCAPED_UNICODE);
                $errorFmt->data    = "{$fmt->method} error:{$params}";
                $resFmt->error     = $errorFmt->outputArray($errorFmt::FILTER_STRICT);
                return $connection->send($resFmt->outputArrayByKey($resFmt::FILTER_STRICT, $resFmt::TYPE_RESPONSE));
            }
            # success
            return $connection->send($resFmt->outputArrayByKey($resFmt::FILTER_STRICT, $resFmt::TYPE_RESPONSE));
        } catch(\Exception $exception) {
            $errorFmt->code    = $e->getCode();
            $errorFmt->message = $e->getMessage();
            $errorFmt->data    = $exception->getTraceAsString();
            $resFmt->error     = $errorFmt->outputArray($errorFmt::FILTER_STRICT);
            return $connection->send($resFmt->outputArrayByKey($resFmt::FILTER_STRICT, $resFmt::TYPE_RESPONSE));
        }

    }

    public function register() {
        spl_autoload_register([$this, '_autoload']);
    }

    /**
     * @param $class
     * @throws \Exception
     */
    protected function _autoload($class) {
        throw new \Exception("class {$class} not found", '-1');
    }

}
