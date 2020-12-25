<?php

namespace JsonRpcServer;

use JsonRpcServer\Exception\MethodNotFoundException;
use JsonRpcServer\Exception\RpcException;
use JsonRpcServer\Exception\ServerErrorException;
use JsonRpcServer\Exception\ServiceErrorException;
use JsonRpcServer\Format\ErrorFmt;
use JsonRpcServer\Format\JsonFmt;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

class RpcServer extends Worker {

    protected $_allow = [];
    /**
     * @var callable
     */
    protected $_init = null;
    protected $_pid = null;

    /**
     * 已注册的服务
     * @var array
     */
    protected static $_registered = [];
    protected static $_params     = null;
    protected static $_debug      = false;
    /**
     * @var TcpConnection
     */
    protected static $_connection = null;

    /**
     * Construct.
     *
     * @param string $socket_name
     * @param array $context_option
     */
    public function __construct($socket_name, array $context_option = []) {
        list(, $address) = \explode(':', $socket_name, 2);
        parent::__construct("JsonRpc2:{$address}", $context_option);
        $this->name = 'JsonRpcServer';
    }

    public static function getConnection(){
        return self::$_connection;
    }

    public static function getParams(){
        return self::$_params;
    }

    public static function setDebug(bool $debug = false){
        self::$_debug = $debug;
    }

    public static function getDebug(){
        return self::$_debug;
    }

    public static function throwServiceException($info, $message = 'Service error'){
        throw new ServiceErrorException($message, null, $info);
    }

    /**
     * Run webserver instance.
     *
     * @see Workerman.Worker::run()
     */
    public function run() {
        $this->onWorkerStart  = [$this, 'onWorkerStart'];
        $this->onClose        = [$this, 'onClose'];
        $this->onMessage      = [$this, 'onMessage'];
        $this->onConnect      = [$this, 'onConnect'];
        $this->onWorkerStop   = [$this, 'onWorkerStop'];
        $this->onWorkerReload = [$this, 'onWorkerReload'];
        $this->onBufferFull   = [$this, 'onBufferFull'];
        $this->onBufferDrain  = [$this, 'onBufferDrain'];
        $this->onError        = [$this, 'onError'];
        parent::run();
    }
    public function onWorkerStart(Worker $worker) {
        $this->_pid = function_exists('posix_getpid') ? posix_getpid() : md5(microtime().rand(0,100));
        self::safeEcho("\n# ------ {$this->_pid} START ------ #\n");
        $this->register();
        $this->initialize($this->_init,true);
    }
    public function onConnect(TcpConnection $connection){
        self::safeEcho("\n# ------ {$this->_pid} CONNECT [{$connection->id}] ------ #\n");
        self::$_connection = $connection;
    }
    public function onClose(TcpConnection $connection){
        self::safeEcho("\n# ------ {$this->_pid} CLOSE [{$connection->id}] ------ #\n");
    }
    public function onWorkerStop(Worker $worker) {
        self::safeEcho("\n# ------ {$this->_pid} END ------ #\n");
        $this->_pid = null;
    }
    public function onWorkerReload() {}
    public function onWorkerClose() {}
    public function onBufferFull() {}
    public function onBufferDrain() {}
    public function onError() {}

    /**
     * @param TcpConnection $connection
     * @param array $data 解析协议后的内容
     * @return bool|null
     */
    public function onMessage(TcpConnection $connection, $data) {
        list($exception, $buffer) = $data;
        $fmt = JsonFmt::factory($buffer);
        self::$_params = $fmt->params;

        self::safeEcho("\n <recv>: {$GLOBALS['recv_buffer']}");
        $resFmt = clone $fmt;
        $resFmt->clean(true);
        $resFmt->id = $fmt->id ? $fmt->id : null;
        $errorFmt = ErrorFmt::factory();
        # 异常检查
        if($exception instanceof RpcException){
            return $this->_throwError($exception);
        }
        $e = new ServerErrorException();
        if($exception instanceof \Exception){
            return $this->_throwError($e, $exception);
        }
        list($class, $method, $service) = $this->_analyzeMethod($fmt->method);
        # 不存在
        if(
            !class_exists($class) or
            !method_exists($class, $method)
        ){
            return $this->_throwError(new MethodNotFoundException(), 'NOT FOUND');
        }
        # 非允许
        if(!in_array($service, $this->_allow)){
            return $this->_throwError(new MethodNotFoundException(), 'ALLOW');
        }
        # 调用
        try {
            $class = new $class;
            $resFmt->result = null;
            # failed
            if(!$resFmt->result = call_user_func_array([$class, $method], [$fmt->params, $this])){
                return $this->_throwError($e, 'FUNC');
            }
            # success
            if($fmt->id){
                return $this->_send($connection,$resFmt->outputArrayByKey($resFmt::FILTER_STRICT, $resFmt::TYPE_RESPONSE));
            }
//            return ;
            return $this->_send($connection,null);
        } catch(\Exception $exception) {
            return $this->_throwError($e, $exception);
        }

    }

    public function setAllow(array $allow){
        $this->_allow = $allow;
    }

    public function initialize(callable $function, $execute = false){
        $this->_init = $function;
        $this->initGlobalArray();
        if($this->_init and $execute){
            try {
                call_user_func($this->_init);
            }catch(\Exception $exception){
                self::safeEcho($exception->getMessage() . ' ' . $exception->getCode());
                self::safeEcho($exception->getTraceAsString());
            }
        }
    }

    public function register() {
        spl_autoload_register([$this, '_autoload']);
    }

    public function initGlobalArray(){
        $GLOBALS['GLOBAL_ARRAY'] = [];
    }

    public function getGlobalArray(string $field = null){
        if($field){
            return isset($GLOBALS['GLOBAL_ARRAY'][$field]) ? $GLOBALS['GLOBAL_ARRAY'][$field] : null;
        }
        return $GLOBALS['GLOBAL_ARRAY'];
    }

    public function setGlobalArray(string $field, $data){
        $GLOBALS['GLOBAL_ARRAY'][$field] = $data;
    }

    /**
     * @param $class
     * @throws \Exception
     */
    protected function _autoload($class) {
        throw new \Exception("class {$class} not found", '-1');
    }

    protected function _analyzeMethod(string $method){
        $class  = explode('.', $method);
        $service = $class[0];
        $method = array_pop($class);
        $class  = implode('\\', $class);
        return [$class, $method, $service];
    }

    protected function _throwError(RpcException $exception, $info = null){
        $resFmt = JsonFmt::factory();
        $errorFmt = ErrorFmt::factory();
        $errorFmt->code    = $exception->getCode();
        $errorFmt->message = $exception->getMessage();
        if($info){
            $errorFmt->data = $info instanceof \Exception ? $this->_debugInfo($info) : $info;
        }
        $resFmt->error   = $errorFmt->outputArray($errorFmt::FILTER_STRICT);
        return $this->_send(self::getConnection(), $resFmt->outputArrayByKey($resFmt::FILTER_STRICT, $resFmt::TYPE_RESPONSE));
    }

    /**
     * todo 通知的忽略处理 return;
     * @param TcpConnection $connection
     * @param $buffer
     * @return bool|null
     */
    protected function _send(TcpConnection $connection, $buffer){
        $GLOBALS['send_buffer'] = $buffer === null ? "\n" : json_encode($buffer) . "\n";
        self::safeEcho("\n <send>: {$GLOBALS['send_buffer']}");
        return $connection->send($buffer);
    }

    protected function _debugInfo(\Exception $exception){
        if(self::$_debug){
            return [
                '_code'    => $exception->getCode(),
                '_message' => $exception->getMessage(),
                '_file'    => $exception->getFile() . '(' .$exception->getLine(). ')',
                '_trace'   => $exception->getTraceAsString(),
                '_info'    => $exception instanceof ServiceErrorException ? $exception->getInfo() : []
            ];
        }

        return [
            '_code'    => $exception->getCode(),
            '_message' => $exception->getMessage(),
        ];
    }
}
