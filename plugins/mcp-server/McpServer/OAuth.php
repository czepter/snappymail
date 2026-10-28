<?php

namespace McpServer;

use RainLoop\Model\MainAccount;

/**
 * OAuth 2.1 authorization server logic (RFC 6749, 7591, 7636, 7009, 8414, 9728)
 *
 * Mail credentials of an authorized account are stored in a grant, encrypted
 * with a random grant key. That key is only stored wrapped (encrypted) by each
 * issued code/token, so the credentials can not be decrypted from disk without
 * a valid token.
 */
class OAuth
{
	const
		SCOPE_READ  = 'mail.read',
		SCOPE_WRITE = 'mail.write',
		SCOPE_SEND  = 'mail.send';

	const SCOPES = [
		self::SCOPE_READ  => 'Read folders, messages, attachments and contacts',
		self::SCOPE_WRITE => 'Flag, move and delete messages, save drafts',
		self::SCOPE_SEND  => 'Prepare emails that are only sent after your confirmation'
	];

	const CODE_TTL = 600;

	private Store $oStore;

	public int $iAccessTtl = 3600;

	public int $iRefreshTtl = 2592000;

	public bool $bAllowRegistration = true;

	/**
	 * Scopes that the admin allows
	 */
	public array $aAllowedScopes = [self::SCOPE_READ, self::SCOPE_WRITE, self::SCOPE_SEND];

	function __construct(Store $oStore)
	{
		$this->oStore = $oStore;
	}

	/**
	 * Crypto helpers
	 */

	private static function secretKey(string $sSecret) : string
	{
		return \hash('sha256', $sSecret . APP_SALT, true);
	}

	public static function seal(string $sData, string $sSecret) : string
	{
		$sNonce = \random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		return \base64_encode($sNonce . \sodium_crypto_secretbox($sData, $sNonce, static::secretKey($sSecret)));
	}

	public static function open(string $sSealed, string $sSecret) : ?string
	{
		$sRaw = \base64_decode($sSealed, true);
		if (!$sRaw || \strlen($sRaw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
			return null;
		}
		$sResult = \sodium_crypto_secretbox_open(
			\substr($sRaw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
			\substr($sRaw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
			static::secretKey($sSecret)
		);
		return false === $sResult ? null : $sResult;
	}

	public static function randomToken(string $sPrefix) : string
	{
		return $sPrefix . \rtrim(\strtr(\base64_encode(\random_bytes(32)), '+/', '-_'), '=');
	}

	/**
	 * Scopes
	 */

	public function filterScopes(?string $sScope) : array
	{
		$aScopes = \strlen(\trim((string) $sScope))
			? \array_values(\array_intersect(\array_keys(static::SCOPES), \preg_split('/\s+/', \trim($sScope))))
			: \array_keys(static::SCOPES);
		return \array_values(\array_intersect($aScopes, $this->aAllowedScopes));
	}

	/**
	 * Clients (RFC 7591 Dynamic Client Registration)
	 */

	public static function validRedirectUri(string $sUri) : bool
	{
		if (\strlen($sUri) > 2000 || \str_contains($sUri, '#')) {
			return false;
		}
		$aUrl = \parse_url($sUri);
		if (!$aUrl || empty($aUrl['scheme'])) {
			return false;
		}
		$sScheme = \strtolower($aUrl['scheme']);
		if ('https' === $sScheme) {
			return !empty($aUrl['host']);
		}
		if ('http' === $sScheme) {
			// RFC 8252 loopback redirect for native apps
			return isset($aUrl['host']) && \in_array(\strtolower($aUrl['host']), ['localhost', '127.0.0.1', '[::1]']);
		}
		// RFC 8252 private-use URI scheme for native apps, like "cursor://"
		return !\in_array($sScheme, ['javascript', 'data', 'file', 'vbscript', 'blob', 'about', 'ftp', 'ws', 'wss'])
			&& \preg_match('/^[a-z][a-z0-9+.\-]*$/', $sScheme);
	}

	/**
	 * Loopback redirects may use any port (RFC 8252 section 7.3)
	 */
	public static function redirectUriMatches(string $sRegistered, string $sRequested) : bool
	{
		if ($sRegistered === $sRequested) {
			return true;
		}
		$aA = \parse_url($sRegistered);
		$aB = \parse_url($sRequested);
		if (!$aA || !$aB || 'http' !== ($aA['scheme'] ?? '') || 'http' !== ($aB['scheme'] ?? '')) {
			return false;
		}
		$sHost = \strtolower($aA['host'] ?? '');
		return \in_array($sHost, ['localhost', '127.0.0.1', '[::1]'])
			&& $sHost === \strtolower($aB['host'] ?? '')
			&& ($aA['path'] ?? '/') === ($aB['path'] ?? '/')
			&& ($aA['query'] ?? '') === ($aB['query'] ?? '');
	}

	public function registerClient(array $aRequest) : array
	{
		if (!$this->bAllowRegistration) {
			throw new OAuthException('access_denied', 'Dynamic client registration is disabled', 403);
		}
		$aRedirectUris = $aRequest['redirect_uris'] ?? null;
		if (!\is_array($aRedirectUris) || !$aRedirectUris || \count($aRedirectUris) > 10) {
			throw new OAuthException('invalid_redirect_uri', 'redirect_uris is required');
		}
		foreach ($aRedirectUris as $sUri) {
			if (!\is_string($sUri) || !static::validRedirectUri($sUri)) {
				throw new OAuthException('invalid_redirect_uri', 'Invalid redirect_uri');
			}
		}
		$sAuthMethod = $aRequest['token_endpoint_auth_method'] ?? 'none';
		if (!\in_array($sAuthMethod, ['none', 'client_secret_post', 'client_secret_basic'])) {
			throw new OAuthException('invalid_client_metadata', 'Unsupported token_endpoint_auth_method');
		}
		$aGrantTypes = $aRequest['grant_types'] ?? ['authorization_code', 'refresh_token'];
		if (!\is_array($aGrantTypes) || \array_diff($aGrantTypes, ['authorization_code', 'refresh_token'])) {
			throw new OAuthException('invalid_client_metadata', 'Unsupported grant_types');
		}
		$aResponseTypes = $aRequest['response_types'] ?? ['code'];
		if (!\is_array($aResponseTypes) || \array_diff($aResponseTypes, ['code'])) {
			throw new OAuthException('invalid_client_metadata', 'Unsupported response_types');
		}

		$sName = \is_string($aRequest['client_name'] ?? null) ? \trim($aRequest['client_name']) : '';
		$sName = \mb_substr(\preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $sName), 0, 100);
		$sClientUri = \is_string($aRequest['client_uri'] ?? null) && \preg_match('#^https://#i', $aRequest['client_uri'])
			? \mb_substr($aRequest['client_uri'], 0, 500) : '';

		$sClientId = \SnappyMail\UUID::generate();
		$aClient = [
			'client_id' => $sClientId,
			'client_id_issued_at' => \time(),
			'client_name' => $sName ?: 'MCP client',
			'redirect_uris' => \array_values($aRedirectUris),
			'grant_types' => \array_values($aGrantTypes),
			'response_types' => ['code'],
			'token_endpoint_auth_method' => $sAuthMethod
		];
		if ($sClientUri) {
			$aClient['client_uri'] = $sClientUri;
		}
		if (isset($aRequest['scope']) && \is_string($aRequest['scope'])) {
			$aClient['scope'] = \implode(' ', $this->filterScopes($aRequest['scope']));
		}

		$aStored = $aClient;
		if ('none' !== $sAuthMethod) {
			$sSecret = static::randomToken('smcs_');
			$aClient['client_secret'] = $sSecret;
			$aClient['client_secret_expires_at'] = 0;
			$aStored['client_secret_hash'] = \hash('sha256', $sSecret);
		}
		$this->oStore->put('clients', $sClientId, $aStored);
		return $aClient;
	}

	public function getClient(string $sClientId) : ?array
	{
		return \strlen($sClientId) ? $this->oStore->get('clients', $sClientId) : null;
	}

	/**
	 * Verifies client credentials at the token/revocation endpoint
	 */
	public function authenticateClient(string $sClientId, ?string $sClientSecret) : array
	{
		$aClient = $this->getClient($sClientId);
		if (!$aClient) {
			throw new OAuthException('invalid_client', 'Unknown client', 401);
		}
		if (!empty($aClient['client_secret_hash'])) {
			if (!\is_string($sClientSecret) || !\hash_equals($aClient['client_secret_hash'], \hash('sha256', $sClientSecret))) {
				throw new OAuthException('invalid_client', 'Client authentication failed', 401);
			}
		}
		return $aClient;
	}

	/**
	 * Authorization code
	 */

	public function createAuthorizationCode(MainAccount $oAccount, array $aClient, string $sRedirectUri,
		array $aScopes, string $sCodeChallenge, string $sResource) : string
	{
		$sGrantKey = \random_bytes(32);
		$sGrantId = \SnappyMail\UUID::generate();
		$this->oStore->put('grants', $sGrantId, [
			'id' => $sGrantId,
			'client_id' => $aClient['client_id'],
			'client_name' => $aClient['client_name'],
			'redirect_host' => \parse_url($sRedirectUri, \PHP_URL_HOST) ?: \parse_url($sRedirectUri, \PHP_URL_SCHEME),
			'email' => $oAccount->Email(),
			'scopes' => $aScopes,
			'created' => \time(),
			'last_used' => 0,
			'expires' => \time() + static::CODE_TTL,
			'account' => static::seal(\json_encode($oAccount), $sGrantKey)
		]);

		$sCode = static::randomToken('smac_');
		$this->oStore->put('codes', $sCode, [
			'grant' => $sGrantId,
			'client_id' => $aClient['client_id'],
			'redirect_uri' => $sRedirectUri,
			'code_challenge' => $sCodeChallenge,
			'scopes' => $aScopes,
			'resource' => $sResource,
			'expires' => \time() + static::CODE_TTL,
			'key' => static::seal($sGrantKey, $sCode)
		]);
		return $sCode;
	}

	public function exchangeAuthorizationCode(array $aClient, string $sCode, string $sRedirectUri, string $sCodeVerifier) : array
	{
		$aCode = $this->oStore->take('codes', $sCode);
		if (!$aCode || $aCode['client_id'] !== $aClient['client_id']) {
			throw new OAuthException('invalid_grant', 'Invalid or expired authorization code');
		}
		if ($aCode['redirect_uri'] !== $sRedirectUri) {
			throw new OAuthException('invalid_grant', 'redirect_uri mismatch');
		}
		// PKCE S256 (RFC 7636)
		if (!\preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $sCodeVerifier)) {
			throw new OAuthException('invalid_grant', 'Invalid code_verifier');
		}
		$sChallenge = \rtrim(\strtr(\base64_encode(\hash('sha256', $sCodeVerifier, true)), '+/', '-_'), '=');
		if (!\hash_equals($aCode['code_challenge'], $sChallenge)) {
			throw new OAuthException('invalid_grant', 'PKCE verification failed');
		}
		$sGrantKey = static::open($aCode['key'], $sCode);
		$aGrant = $this->oStore->get('grants', $aCode['grant']);
		if (!$sGrantKey || !$aGrant) {
			throw new OAuthException('invalid_grant', 'Authorization revoked');
		}
		return $this->issueTokens($aGrant, $sGrantKey, $aCode['scopes'], $aCode['resource']);
	}

	/**
	 * Refresh token with rotation and replay detection
	 */
	public function refresh(array $aClient, string $sRefreshToken, ?string $sScope) : array
	{
		$aToken = $this->oStore->take('tokens', $sRefreshToken);
		if (!$aToken) {
			// Reuse of a rotated refresh token: revoke the whole grant (OAuth 2.1 section 4.3.1)
			$aUsed = $this->oStore->get('usedtokens', $sRefreshToken);
			if ($aUsed) {
				$this->revokeGrant($aUsed['grant']);
			}
			throw new OAuthException('invalid_grant', 'Invalid or expired refresh token');
		}
		if ('refresh' !== $aToken['type'] || $aToken['client_id'] !== $aClient['client_id']) {
			throw new OAuthException('invalid_grant', 'Invalid refresh token');
		}
		$this->oStore->put('usedtokens', $sRefreshToken, [
			'grant' => $aToken['grant'],
			'expires' => $aToken['expires']
		]);
		$sGrantKey = static::open($aToken['key'], $sRefreshToken);
		$aGrant = $this->oStore->get('grants', $aToken['grant']);
		if (!$sGrantKey || !$aGrant) {
			throw new OAuthException('invalid_grant', 'Authorization revoked');
		}
		$aScopes = $aToken['scopes'];
		if (null !== $sScope && \strlen(\trim($sScope))) {
			$aRequested = \preg_split('/\s+/', \trim($sScope));
			if (\array_diff($aRequested, $aScopes)) {
				throw new OAuthException('invalid_scope', 'Scope exceeds the granted scope');
			}
			$aScopes = \array_values($aRequested);
		}
		return $this->issueTokens($aGrant, $sGrantKey, $aScopes, $aToken['resource'] ?? '');
	}

	private function issueTokens(array $aGrant, string $sGrantKey, array $aScopes, string $sResource) : array
	{
		$aScopes = \array_values(\array_intersect($aScopes, $this->aAllowedScopes));

		$sAccess = static::randomToken('smat_');
		$this->oStore->put('tokens', $sAccess, [
			'type' => 'access',
			'grant' => $aGrant['id'],
			'client_id' => $aGrant['client_id'],
			'scopes' => $aScopes,
			'resource' => $sResource,
			'expires' => \time() + $this->iAccessTtl,
			'key' => static::seal($sGrantKey, $sAccess)
		]);

		$sRefresh = static::randomToken('smrt_');
		$this->oStore->put('tokens', $sRefresh, [
			'type' => 'refresh',
			'grant' => $aGrant['id'],
			'client_id' => $aGrant['client_id'],
			'scopes' => $aScopes,
			'resource' => $sResource,
			'expires' => \time() + $this->iRefreshTtl,
			'key' => static::seal($sGrantKey, $sRefresh)
		]);

		// Keep the grant alive as long as the refresh token
		$aGrant['expires'] = \time() + $this->iRefreshTtl;
		$aGrant['issued'] = true;
		$this->oStore->put('grants', $aGrant['id'], $aGrant);

		return [
			'access_token' => $sAccess,
			'token_type' => 'Bearer',
			'expires_in' => $this->iAccessTtl,
			'refresh_token' => $sRefresh,
			'scope' => \implode(' ', $aScopes)
		];
	}

	/**
	 * Validates a bearer token and restores the mail account.
	 * @return array [grant, MainAccount, scopes]
	 */
	public function authenticate(string $sAccessToken, \RainLoop\Actions $oActions) : array
	{
		$aToken = \str_starts_with($sAccessToken, 'smat_') ? $this->oStore->get('tokens', $sAccessToken) : null;
		if (!$aToken || 'access' !== $aToken['type']) {
			throw new OAuthException('invalid_token', 'Invalid or expired access token', 401);
		}
		$aGrant = $this->oStore->get('grants', $aToken['grant']);
		$sGrantKey = static::open($aToken['key'], $sAccessToken);
		$sAccount = ($aGrant && $sGrantKey) ? static::open($aGrant['account'], $sGrantKey) : null;
		$aAccount = $sAccount ? \json_decode($sAccount, true) : null;
		$oAccount = \is_array($aAccount) ? MainAccount::NewInstanceFromTokenArray($oActions, $aAccount) : null;
		if (!$oAccount) {
			throw new OAuthException('invalid_token', 'Authorization revoked', 401);
		}
		if ($aGrant['last_used'] < \time() - 300) {
			$aGrant['last_used'] = \time();
			$this->oStore->put('grants', $aGrant['id'], $aGrant);
		}
		return [$aGrant, $oAccount, \array_values(\array_intersect($aToken['scopes'], $this->aAllowedScopes))];
	}

	/**
	 * RFC 7009
	 */
	public function revokeToken(array $aClient, string $sToken) : void
	{
		$aToken = $this->oStore->get('tokens', $sToken);
		if ($aToken && $aToken['client_id'] === $aClient['client_id']) {
			if ('refresh' === $aToken['type']) {
				// Revoking the refresh token ends the whole authorization
				$this->revokeGrant($aToken['grant']);
			}
			$this->oStore->delete('tokens', $sToken);
		}
	}

	public function revokeGrant(string $sGrantId) : void
	{
		// Tokens become useless without their grant, they expire by themselves
		$this->oStore->delete('grants', $sGrantId);
	}

	public function grantsOf(string $sEmail) : array
	{
		$aResult = [];
		foreach ($this->oStore->all('grants') as $aGrant) {
			// Only grants of which the authorization code was exchanged
			if ($aGrant['email'] === $sEmail && !empty($aGrant['issued'])) {
				unset($aGrant['account']);
				$aResult[] = $aGrant;
			}
		}
		\usort($aResult, fn($a, $b) => $b['created'] <=> $a['created']);
		return $aResult;
	}

	public function getGrant(string $sGrantId) : ?array
	{
		return $this->oStore->get('grants', $sGrantId);
	}
}
