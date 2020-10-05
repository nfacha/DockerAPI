<?php

namespace src\DockerAPI;


use Exception;


class DockerException extends Exception
{

    /**
     * Construct the exception. Note: The message is NOT binary safe.
     * @link https://php.net/manual/en/exception.construct.php
     * @param string $message [optional] The Exception message to throw.
     * @param array $data
     */
    public function __construct($message = "", $data = [])
    {
        $message .= ' @@@ ' . json_encode($data);
        parent::__construct($message, null, null);
    }



}
