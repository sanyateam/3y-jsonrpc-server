<?php

namespace Protocols;

use JsonRpcServer\Exception\InvalidRequestException;
use JsonRpcServer\Exception\ParseErrorException;
use JsonRpcServer\Exception\RpcException;
use JsonRpcServer\Format\JsonFmt;
use Workerman\Connection\TcpConnection;

/**
 * JsonRpc-2.0 协议
 *
 * Class JsonRpc2
 * @package Protocols
 * @link https://www.jsonrpc.org/
 * @license https://www.jsonrpc.org/specification
 */
class JsonRpc2 {
    
    /**
     * 检查包的完整性
     * 如果能够得到包长，则返回包的在buffer中的长度，否则返回0继续等待数据
     * @param $buffer
     * @param TcpConnection $connection
     * @return false|int
     */
    public static function input($buffer, TcpConnection $connection) {
        # 获得换行字符"\n"位置
        $pos = strpos($buffer, "\n");
        // 没有换行符
        if($pos === false) {
            // 无法得知包长，返回0继续等待数据
            return 0;
        }
        // 有换行符，返回当前包长（包含换行符）
        return $pos + 1;
    }

    /**
     * 打包
     * @param $buffer
     * @param TcpConnection $connection
     * @return string
     * @throws InvalidRequestException
     * @throws RpcException
     */
    public static function encode($buffer, TcpConnection $connection) {
        if($buffer === null){
            return "\n";
        }
        if(!is_array($buffer)){
            # 抛出ParseError异常
            throw new ParseErrorException();
        }
        if(!$buffer){
            # 抛出InvalidRequest异常
            throw new InvalidRequestException();
        }
        $fmt = JsonFmt::factory();
        if(!self::isAssoc($buffer)){
            foreach($buffer as $value){
                self::_throw($fmt, $value, $fmt::TYPE_RESPONSE);
            }
        }
        self::_throw($fmt, $buffer, $fmt::TYPE_RESPONSE);
        return json_encode($buffer) . "\n";
    }

    /**
     * 解包
     * @param string $buffer 原始数据值
     * @param TcpConnection $connection
     * @return array
     */
    public static function decode($buffer, TcpConnection $connection) {

        $GLOBALS['recv_buffer'] = $buffer;

        $data = self::isJson(trim($buffer),true);
        # 不是json
        if(!$data){
            # 抛出ParseError异常
            return self::_res(new ParseErrorException(), null);
        }
        # 空数组
        if(!$data){
            # 抛出InvalidRequest异常
            return self::_res(new InvalidRequestException(), null);
        }
        $fmt = JsonFmt::factory();
        # 不是关联数组
        if(!self::isAssoc($data)){
            foreach($data as $value){
                if(($res = self::_throw($fmt, $value, $fmt::TYPE_REQUEST)) !== true){
                    return self::_res($res, null);
                }
            }
        }
        if(($res = self::_throw($fmt, $data, $fmt::TYPE_REQUEST)) !== true){
            return self::_res($res, null);
        }

        return self::_res(true, $data);
    }

    /**
     * @param JsonFmt $fmt
     * @param $data
     * @param $scene
     * @return RpcException|bool
     */
    protected static function _throw(JsonFmt $fmt, $data, $scene){
        $fmt->clean(true);
        $fmt->setScene($scene);
        $fmt->create($data,true);
//        var_dump($data);
//        var_dump($fmt);
        # 如果有错误
        if($fmt->hasError()){
            # 抛出异常
            $exception = $fmt->getError();
            $exception = "JsonRpcServer\Exception\\{$exception}";
            return new $exception;
        }
        # 如果有特殊错误
        if($fmt->hasSpecialError()){
            # 抛出异常
            $exception = $fmt->getSpecialError();
            $exception = "JsonRpcServer\Exception\\{$exception}";
            return new $exception;
        }
        return true;
    }

    /**
     * @param $exception
     * @param $data
     * @return array
     */
    protected static function _res($exception, $data){
        return [
            $exception,
            $data
        ];
    }

    /**
     * 是否是Json
     * @param $string
     * @param bool $get
     * @return bool|mixed
     */
    public static function isJson($string, bool $get = false){
        @json_decode($string);
        if(json_last_error() != JSON_ERROR_NONE){
            return false;
        }
        if($get){
            return json_decode($string,true);
        }
        return true;
    }

    /**
     * 是否是索引数组
     * @param array $array
     * @return bool
     */
    public static function isAssoc(array $array){
        return boolval(array_keys($array) !== range(0, count($array) - 1));
    }

}

