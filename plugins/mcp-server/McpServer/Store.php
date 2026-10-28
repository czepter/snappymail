<?php

namespace McpServer;

/**
 * Small file based key/value store for OAuth clients, grants, tokens
 * and pending (unconfirmed) emails.
 * Records are JSON files named by the SHA-256 of their key, so secrets
 * (tokens, codes) are never written to disk.
 */
class Store
{
	private string $sPath;

	function __construct(string $sPath)
	{
		$this->sPath = \rtrim($sPath, '/') . '/';
	}

	private function file(string $sType, string $sKey) : string
	{
		if (!\preg_match('/^[a-z]+$/', $sType)) {
			throw new \InvalidArgumentException('Invalid store type');
		}
		$sDir = $this->sPath . $sType . '/';
		if (!\is_dir($sDir) && !\mkdir($sDir, 0700, true) && !\is_dir($sDir)) {
			throw new \RuntimeException("Can not create {$sDir}");
		}
		return $sDir . \hash('sha256', $sKey) . '.json';
	}

	public function get(string $sType, string $sKey) : ?array
	{
		$sFile = $this->file($sType, $sKey);
		if (!\is_file($sFile)) {
			return null;
		}
		$aData = \json_decode((string) \file_get_contents($sFile), true);
		if (!\is_array($aData)) {
			return null;
		}
		if (!empty($aData['expires']) && $aData['expires'] < \time()) {
			@\unlink($sFile);
			return null;
		}
		return $aData;
	}

	public function put(string $sType, string $sKey, array $aData) : void
	{
		$sFile = $this->file($sType, $sKey);
		$sTmp = $sFile . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
		if (false === \file_put_contents($sTmp, \json_encode($aData, \JSON_UNESCAPED_SLASHES))) {
			throw new \RuntimeException('Can not write store record');
		}
		\chmod($sTmp, 0600);
		\rename($sTmp, $sFile);
	}

	public function delete(string $sType, string $sKey) : void
	{
		$sFile = $this->file($sType, $sKey);
		\is_file($sFile) && @\unlink($sFile);
	}

	/**
	 * Atomically fetch and remove a record (for single use codes)
	 */
	public function take(string $sType, string $sKey) : ?array
	{
		$sFile = $this->file($sType, $sKey);
		$sTaken = $sFile . '.taken.' . \bin2hex(\random_bytes(4));
		if (!\is_file($sFile) || !@\rename($sFile, $sTaken)) {
			return null;
		}
		$aData = \json_decode((string) \file_get_contents($sTaken), true);
		@\unlink($sTaken);
		if (!\is_array($aData) || (!empty($aData['expires']) && $aData['expires'] < \time())) {
			return null;
		}
		return $aData;
	}

	/**
	 * @return iterable<array>
	 */
	public function all(string $sType) : iterable
	{
		$sDir = $this->sPath . $sType . '/';
		foreach (\glob($sDir . '*.json') ?: [] as $sFile) {
			$aData = \json_decode((string) \file_get_contents($sFile), true);
			if (\is_array($aData)) {
				if (!empty($aData['expires']) && $aData['expires'] < \time()) {
					@\unlink($sFile);
				} else {
					yield $aData;
				}
			}
		}
	}

	/**
	 * Remove expired records, called now and then
	 */
	public function gc() : void
	{
		$iNow = \time();
		foreach (\glob($this->sPath . '*/*.json') ?: [] as $sFile) {
			$aData = \json_decode((string) \file_get_contents($sFile), true);
			if (!\is_array($aData) || (!empty($aData['expires']) && $aData['expires'] < $iNow)) {
				@\unlink($sFile);
			}
		}
		foreach (\glob($this->sPath . '*/*.tmp') ?: [] as $sFile) {
			\filemtime($sFile) < $iNow - 300 && @\unlink($sFile);
		}
	}
}
