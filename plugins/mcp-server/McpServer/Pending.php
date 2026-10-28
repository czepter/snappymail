<?php

namespace McpServer;

/**
 * Emails prepared by an MCP client.
 * They are only sent after the account owner confirmed them in the browser.
 * The message itself is encrypted with a key that only exists in the
 * confirmation link.
 */
class Pending
{
	const
		STATUS_PENDING   = 'pending_confirmation',
		STATUS_SENDING   = 'sending',
		STATUS_SENT      = 'sent',
		STATUS_DISCARDED = 'discarded',
		STATUS_FAILED    = 'failed';

	private Store $oStore;

	public int $iTtl = 86400;

	function __construct(Store $oStore)
	{
		$this->oStore = $oStore;
	}

	/**
	 * @return array [id, key]
	 */
	public function create(string $sMainEmail, string $sAccountEmail, string $sClientName, array $aMessage) : array
	{
		$sId = \SnappyMail\UUID::generate();
		$sKey = \rtrim(\strtr(\base64_encode(\random_bytes(24)), '+/', '-_'), '=');
		$this->oStore->put('pending', $sId, [
			'id' => $sId,
			'email' => $sMainEmail,
			'account' => $sAccountEmail,
			'client_name' => $sClientName,
			'status' => static::STATUS_PENDING,
			'created' => \time(),
			'expires_at' => \time() + $this->iTtl,
			// keep the record a while after expiry, so the status can be queried
			'expires' => \time() + $this->iTtl + 86400 * 7,
			'message' => OAuth::seal(\json_encode($aMessage), $sKey)
		]);
		return [$sId, $sKey];
	}

	public function get(string $sId) : ?array
	{
		$aPending = \preg_match('/^[a-f0-9\-]{36}$/', $sId) ? $this->oStore->get('pending', $sId) : null;
		if ($aPending && static::STATUS_PENDING === $aPending['status'] && $aPending['expires_at'] < \time()) {
			$aPending['status'] = 'expired';
		}
		return $aPending;
	}

	public function message(array $aPending, string $sKey) : ?array
	{
		$sMessage = isset($aPending['message']) ? OAuth::open($aPending['message'], $sKey) : null;
		$aMessage = $sMessage ? \json_decode($sMessage, true) : null;
		return \is_array($aMessage) ? $aMessage : null;
	}

	public function setStatus(array $aPending, string $sStatus, string $sError = '') : void
	{
		$aPending['status'] = $sStatus;
		$aPending['updated'] = \time();
		if ($sError) {
			$aPending['error'] = $sError;
		}
		if (static::STATUS_SENT === $sStatus || static::STATUS_DISCARDED === $sStatus) {
			// The content is not needed anymore
			unset($aPending['message']);
		}
		$this->oStore->put('pending', $aPending['id'], $aPending);
	}

	public static function publicStatus(array $aPending) : array
	{
		$aResult = [
			'id' => $aPending['id'],
			'status' => $aPending['status'],
			'created_at' => \gmdate('c', $aPending['created']),
			'expires_at' => \gmdate('c', $aPending['expires_at'])
		];
		if (!empty($aPending['updated'])) {
			$aResult['updated_at'] = \gmdate('c', $aPending['updated']);
		}
		if (!empty($aPending['error'])) {
			$aResult['error'] = $aPending['error'];
		}
		return $aResult;
	}
}
