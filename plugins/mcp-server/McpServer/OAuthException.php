<?php

namespace McpServer;

class OAuthException extends \RuntimeException
{
	public string $error;

	public int $status;

	function __construct(string $sError, string $sDescription = '', int $iStatus = 400)
	{
		parent::__construct($sDescription ?: $sError);
		$this->error = $sError;
		$this->status = $iStatus;
	}
}
