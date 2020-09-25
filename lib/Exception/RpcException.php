<?php
namespace JsonRpcServer\Exception;

use Throwable;

class RpcException extends \Exception {

    public function __construct($message, $code, Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}