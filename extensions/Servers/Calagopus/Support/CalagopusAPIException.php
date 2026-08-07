<?php

namespace Paymenter\Extensions\Servers\Calagopus\Support;

use Exception;

class CalagopusAPIException extends Exception
{
	public function __construct(public readonly int $status, string $message)
	{
		parent::__construct($message);
	}
}
