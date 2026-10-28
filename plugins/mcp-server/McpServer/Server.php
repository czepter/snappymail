<?php

namespace McpServer;

use RainLoop\Model\MainAccount;
use RainLoop\Utils;
use SnappyMail\SensitiveString;

/**
 * HTTP layer: routing, OAuth endpoints, MCP Streamable HTTP endpoint and pages.
 *
 * URLs work in two styles:
 * - path:  https://example.com/mcp, https://example.com/mcp/authorize
 *          (needs the web server to pass unknown paths to index.php,
 *          which the shipped .htaccess and docker nginx config do)
 * - query: https://example.com/?mcp, https://example.com/?mcp/authorize
 * All generated URLs use the style of the current request.
 */
class Server
{
	const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

	private \RainLoop\Actions $oActions;

	private \McpServerPlugin $oPlugin;

	private Store $oStore;

	private OAuth $oOAuth;

	private Pending $oPending;

	private string $sStyle = 'path';

	private string $sBaseUrl;

	function __construct(\McpServerPlugin $oPlugin, \RainLoop\Actions $oActions)
	{
		$this->oPlugin = $oPlugin;
		$this->oActions = $oActions;
		$this->oStore = new Store(APP_PRIVATE_DATA . 'mcp-server');

		$this->oOAuth = new OAuth($this->oStore);
		$this->oOAuth->iAccessTtl = 60 * \max(5, (int) $this->config('access_token_ttl', 60));
		$this->oOAuth->iRefreshTtl = 86400 * \max(1, (int) $this->config('refresh_token_ttl', 30));
		$this->oOAuth->bAllowRegistration = !!$this->config('allow_registration', true);
		$aScopes = [OAuth::SCOPE_READ];
		$this->config('allow_write', true) && $aScopes[] = OAuth::SCOPE_WRITE;
		$this->config('allow_send', true) && $aScopes[] = OAuth::SCOPE_SEND;
		$this->oOAuth->aAllowedScopes = $aScopes;

		$this->oPending = new Pending($this->oStore);
		$this->oPending->iTtl = 3600 * \max(1, (int) $this->config('confirmation_ttl', 24));

		$sBase = \trim((string) $this->config('public_url', ''));
		$this->sBaseUrl = $sBase ? \rtrim($sBase, '/') . '/' : $oActions->Http()->GetFullUrl();
	}

	private function config(string $sKey, $mDefault = null)
	{
		return $this->oPlugin->Config()->Get('plugin', $sKey, $mDefault);
	}

	/**
	 * Detects whether the current request is for this plugin
	 * @return array|null [style, sub path]
	 */
	public static function route() : ?array
	{
		$sPath = \rawurldecode(\parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH) ?: '/');
		$sBasePath = \MailSo\Base\Http::SingletonInstance()->GetPath();
		$sSub = null;
		$sStyle = 'path';
		if (\str_starts_with($sPath, '/.well-known/')) {
			$sSub = \substr($sPath, 1);
		} else if (\str_starts_with($sPath, $sBasePath)) {
			$sRel = \substr($sPath, \strlen($sBasePath));
			if (\str_starts_with($sRel, 'index.php/')) {
				$sRel = \substr($sRel, 10);
			}
			if (\str_starts_with($sRel, '.well-known/')) {
				$sSub = $sRel;
			} else if ('mcp' === $sRel || \str_starts_with($sRel, 'mcp/')) {
				$sSub = \substr($sRel, 4);
			}
		}
		if (null === $sSub) {
			// "?mcp/authorize&..." or, when a client rebuilt the query string, "?mcp%2Fauthorize=&..."
			$sFirst = \trim(\rtrim(\rawurldecode(\explode('&', $_SERVER['QUERY_STRING'] ?? '', 2)[0]), '='), ' /');
			if ('mcp' === $sFirst || \str_starts_with($sFirst, 'mcp/')) {
				$sSub = \substr($sFirst, 4);
				$sStyle = 'query';
			}
		}
		if (null === $sSub) {
			return null;
		}
		$sSub = \trim($sSub, '/');
		if (\str_starts_with($sSub, '.well-known/')
		 && !\preg_match('#^\.well-known/(oauth-protected-resource|oauth-authorization-server|openid-configuration)(/|$)#', $sSub)
		) {
			// Not ours, like /.well-known/carddav
			return null;
		}
		return [$sStyle, $sSub];
	}

	/**
	 * URLs
	 */

	private function issuer() : string
	{
		return \rtrim($this->sBaseUrl, '/');
	}

	private function origin() : string
	{
		$aUrl = \parse_url($this->sBaseUrl);
		return $aUrl['scheme'] . '://' . $aUrl['host'] . (isset($aUrl['port']) ? ':' . $aUrl['port'] : '');
	}

	private function resource(?string $sStyle = null) : string
	{
		return $this->sBaseUrl . ('query' === ($sStyle ?: $this->sStyle) ? '?mcp' : 'mcp');
	}

	private function url(string $sSub, array $aQuery = []) : string
	{
		if ('query' === $this->sStyle) {
			$sUrl = $this->sBaseUrl . '?mcp/' . $sSub;
			return $aQuery ? $sUrl . '&' . \http_build_query($aQuery) : $sUrl;
		}
		$sUrl = $this->sBaseUrl . 'mcp/' . $sSub;
		return $aQuery ? $sUrl . '?' . \http_build_query($aQuery) : $sUrl;
	}

	private function resourceMetadataUrl() : string
	{
		if ('query' === $this->sStyle) {
			return $this->url('.well-known/oauth-protected-resource');
		}
		// RFC 9728 section 3.1: inserted between host and path of the resource
		return $this->origin() . '/.well-known/oauth-protected-resource' . \parse_url($this->resource(), \PHP_URL_PATH);
	}

	/**
	 * Responses
	 */

	private static function cors() : void
	{
		\header('Access-Control-Allow-Origin: *');
		\header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
		\header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID');
		\header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id, Mcp-Protocol-Version');
		\header('Access-Control-Max-Age: 86400');
	}

	private static function json($mData, int $iStatus = 200, array $aHeaders = []) : void
	{
		foreach (['ETag', 'Last-Modified', 'Expires', 'Content-Security-Policy'] as $sHeader) {
			\header_remove($sHeader);
		}
		\http_response_code($iStatus);
		\header('Content-Type: application/json; charset=utf-8');
		\header('Cache-Control: no-store');
		\header('Pragma: no-cache');
		foreach ($aHeaders as $sHeader) {
			\header($sHeader);
		}
		echo \json_encode($mData, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
	}

	private static function oauthError(OAuthException $e) : void
	{
		$aHeaders = [];
		if (401 === $e->status && 'invalid_client' === $e->error && !empty($_SERVER['PHP_AUTH_USER'])) {
			$aHeaders[] = 'WWW-Authenticate: Basic realm="mcp"';
		}
		static::json(['error' => $e->error, 'error_description' => $e->getMessage()], $e->status, $aHeaders);
	}

	private static function str(array $aData, string $sKey) : string
	{
		return \is_string($aData[$sKey] ?? null) ? $aData[$sKey] : '';
	}

	/**
	 * Form encoded (or JSON) request body, only string values
	 */
	private static function requestBody() : array
	{
		$aData = $_POST;
		if (!$aData) {
			$sBody = (string) \file_get_contents('php://input');
			$aData = \json_decode($sBody, true);
			\is_array($aData) || \parse_str($sBody, $aData);
		}
		return \array_filter($aData, 'is_string');
	}

	private static function bearerToken() : string
	{
		$sHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
		if (!$sHeader && \function_exists('getallheaders')) {
			foreach (\getallheaders() as $sName => $sValue) {
				if ('authorization' === \strtolower($sName)) {
					$sHeader = $sValue;
				}
			}
		}
		return \preg_match('/^Bearer\s+([A-Za-z0-9\-._~+\/]+=*)\s*$/i', $sHeader, $aMatch) ? $aMatch[1] : '';
	}

	/**
	 * Client credentials from HTTP Basic or the request body
	 */
	private static function clientCredentials(array $aBody) : array
	{
		$sHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
		if (\preg_match('/^Basic\s+(.+)$/i', $sHeader, $aMatch)) {
			$aParts = \explode(':', (string) \base64_decode($aMatch[1]), 2);
			if (2 === \count($aParts)) {
				return [\urldecode($aParts[0]), \urldecode($aParts[1])];
			}
		}
		if (isset($_SERVER['PHP_AUTH_USER'])) {
			return [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ?? ''];
		}
		return [(string) ($aBody['client_id'] ?? ''), isset($aBody['client_secret']) ? (string) $aBody['client_secret'] : null];
	}

	/**
	 * Dispatch
	 */

	public function handle(string $sStyle, string $sSub) : void
	{
		$this->sStyle = $sStyle;
		$sMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

		// Clean up expired records now and then
		if (1 === \random_int(1, 100)) {
			try {
				$this->oStore->gc();
			} catch (\Throwable $e) {
				$this->oActions->logException($e);
			}
		}

		$bPage = \in_array($sSub, ['authorize', 'confirm', 'connections']);
		if (!$bPage) {
			static::cors();
			if ('OPTIONS' === $sMethod) {
				\http_response_code(204);
				return;
			}
		}

		try {
			if (\str_starts_with($sSub, '.well-known/oauth-protected-resource')) {
				$this->protectedResourceMetadata();
			} else if (\str_starts_with($sSub, '.well-known/')) {
				$this->authorizationServerMetadata();
			} else switch ($sSub) {
				case '':
					$this->mcp($sMethod);
					break;
				case 'authorize':
					$this->authorize($sMethod);
					break;
				case 'token':
					$this->token($sMethod);
					break;
				case 'register':
					$this->register($sMethod);
					break;
				case 'revoke':
					$this->revoke($sMethod);
					break;
				case 'confirm':
					$this->confirm($sMethod);
					break;
				case 'connections':
					$this->connections($sMethod);
					break;
				default:
					static::json(['error' => 'not_found'], 404);
			}
		} catch (OAuthException $e) {
			static::oauthError($e);
		} catch (\Throwable $e) {
			$this->oActions->logException($e, \LOG_ERR);
			if ($bPage) {
				(new Pages)->message('Error', Pages::e($e->getMessage()), 'error', 500);
			} else {
				static::json(['error' => 'server_error', 'error_description' => 'Internal error'], 500);
			}
		}
	}

	/**
	 * Metadata
	 */

	private function protectedResourceMetadata() : void
	{
		static::json([
			'resource' => $this->resource(),
			'authorization_servers' => [$this->issuer()],
			'scopes_supported' => $this->oOAuth->aAllowedScopes,
			'bearer_methods_supported' => ['header'],
			'resource_name' => 'SnappyMail',
			'resource_documentation' => 'https://snappymail.eu/'
		]);
	}

	private function authorizationServerMetadata() : void
	{
		$aMetadata = [
			'issuer' => $this->issuer(),
			'authorization_endpoint' => $this->url('authorize'),
			'token_endpoint' => $this->url('token'),
			'revocation_endpoint' => $this->url('revoke'),
			'response_types_supported' => ['code'],
			'response_modes_supported' => ['query'],
			'grant_types_supported' => ['authorization_code', 'refresh_token'],
			'code_challenge_methods_supported' => ['S256'],
			'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
			'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
			'scopes_supported' => $this->oOAuth->aAllowedScopes,
			'authorization_response_iss_parameter_supported' => true,
			'service_documentation' => 'https://snappymail.eu/'
		];
		if ($this->oOAuth->bAllowRegistration) {
			$aMetadata['registration_endpoint'] = $this->url('register');
		}
		static::json($aMetadata);
	}

	/**
	 * OAuth endpoints
	 */

	private function register(string $sMethod) : void
	{
		if ('POST' !== $sMethod) {
			static::json(['error' => 'invalid_request', 'error_description' => 'POST required'], 405, ['Allow: POST']);
			return;
		}
		$aBody = \json_decode((string) \file_get_contents('php://input'), true);
		if (!\is_array($aBody)) {
			throw new OAuthException('invalid_client_metadata', 'JSON body required');
		}
		static::json($this->oOAuth->registerClient($aBody), 201);
	}

	private function token(string $sMethod) : void
	{
		if ('POST' !== $sMethod) {
			static::json(['error' => 'invalid_request', 'error_description' => 'POST required'], 405, ['Allow: POST']);
			return;
		}
		$aBody = static::requestBody();
		[$sClientId, $sClientSecret] = static::clientCredentials($aBody);
		$aClient = $this->oOAuth->authenticateClient($sClientId, $sClientSecret);
		$sGrantType = (string) ($aBody['grant_type'] ?? '');
		if (!\in_array($sGrantType, $aClient['grant_types'] ?? ['authorization_code', 'refresh_token'])) {
			throw new OAuthException('unauthorized_client', 'Grant type not allowed for this client');
		}
		switch ($sGrantType) {
			case 'authorization_code':
				$aTokens = $this->oOAuth->exchangeAuthorizationCode(
					$aClient,
					(string) ($aBody['code'] ?? ''),
					(string) ($aBody['redirect_uri'] ?? ''),
					(string) ($aBody['code_verifier'] ?? '')
				);
				break;
			case 'refresh_token':
				$aTokens = $this->oOAuth->refresh(
					$aClient,
					(string) ($aBody['refresh_token'] ?? ''),
					isset($aBody['scope']) ? (string) $aBody['scope'] : null
				);
				break;
			default:
				throw new OAuthException('unsupported_grant_type', 'Unsupported grant_type');
		}
		static::json($aTokens);
	}

	private function revoke(string $sMethod) : void
	{
		if ('POST' !== $sMethod) {
			static::json(['error' => 'invalid_request', 'error_description' => 'POST required'], 405, ['Allow: POST']);
			return;
		}
		$aBody = static::requestBody();
		[$sClientId, $sClientSecret] = static::clientCredentials($aBody);
		$aClient = $this->oOAuth->authenticateClient($sClientId, $sClientSecret);
		$this->oOAuth->revokeToken($aClient, (string) ($aBody['token'] ?? ''));
		static::json([]);
	}

	private function validResource(string $sResource) : bool
	{
		$sResource = \rtrim($sResource, '/');
		return '' === $sResource
			|| \in_array($sResource, [\rtrim($this->resource('path'), '/'), $this->resource('query'), $this->issuer()], true);
	}

	private static function redirectWith(string $sRedirectUri, array $aParams) : void
	{
		$sUrl = $sRedirectUri . (\str_contains($sRedirectUri, '?') ? '&' : '?') . \http_build_query($aParams);
		\header('Cache-Control: no-store');
		\header('Location: ' . $sUrl, true, 302);
	}

	/**
	 * The logged in SnappyMail account of the browser session
	 */
	private function sessionAccount() : ?MainAccount
	{
		try {
			return $this->oActions->getMainAccountFromToken(false);
		} catch (\Throwable $e) {
			return null;
		}
	}

	private static function csrf(string $sPurpose) : string
	{
		return \hash_hmac('sha256', $sPurpose, APP_SALT . Utils::GetSessionToken());
	}

	private static function checkCsrf(string $sPurpose, array $aBody) : bool
	{
		// Only accept forms posted by our own pages
		$sSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
		if ($sSite && !\in_array($sSite, ['same-origin', 'none'])) {
			return false;
		}
		return \is_string($aBody['csrf'] ?? null) && \hash_equals(static::csrf($sPurpose), $aBody['csrf']);
	}

	/**
	 * Shows the login form or processes it.
	 * @return MainAccount|null null when a page was rendered
	 */
	private function requireLogin(string $sMethod, string $sReturnUrl, Pages $oPages) : ?MainAccount
	{
		$oAccount = $this->sessionAccount();
		if ($oAccount) {
			return $oAccount;
		}

		$bTwoFactor = \in_array('two-factor-auth', \SnappyMail\Repository::getEnabledPackagesNames());
		$sEmail = '';
		$sError = '';
		if ('POST' === $sMethod && 'login' === ($_POST['action'] ?? '')) {
			$sEmail = \trim(static::str($_POST, 'email'));
			if (!static::checkCsrf('mcp-login', $_POST)) {
				$sError = 'Invalid request, please try again.';
			} else try {
				// The two-factor-auth plugin reads the code from the action parameters
				$this->oActions->SetActionParams(['totp_code' => static::str($_POST, 'totp_code')], 'DoLogin');
				$this->oActions->LoginProcess($sEmail, new SensitiveString(static::str($_POST, 'password')));
				\header('Location: ' . $sReturnUrl, true, 303);
				return null;
			} catch (\Throwable $e) {
				\sleep(1);
				$sError = 'Login failed';
			}
		} else if (!isset($_GET['_sm'])) {
			// After a cross-site navigation SameSite=Strict cookies are missing, reload once
			$oPages->bounce($sReturnUrl . (\str_contains($sReturnUrl, '?') ? '&' : '?') . '_sm=1');
			return null;
		}
		$oPages->login(static::csrf('mcp-login'), $sError ? \html_entity_decode($oPages->t($sError)) : '', $sEmail, $bTwoFactor);
		return null;
	}

	private function authorize(string $sMethod) : void
	{
		$oPages = new Pages;
		// The consent form posts the request, the login form keeps it in the URL
		$aParams = \array_filter(isset($_POST['client_id']) ? $_POST : $_GET, 'is_string');

		$sClientId = $aParams['client_id'] ?? '';
		$sRedirectUri = $aParams['redirect_uri'] ?? '';
		$aClient = $this->oOAuth->getClient($sClientId);
		if (!$aClient) {
			$oPages->message('Error', 'Unknown client_id', 'error', 400);
			return;
		}
		$aRedirectUris = $aClient['redirect_uris'];
		if (!\strlen($sRedirectUri) && 1 === \count($aRedirectUris)) {
			$sRedirectUri = $aRedirectUris[0];
		}
		$bRedirectValid = false;
		foreach ($aRedirectUris as $sRegistered) {
			$bRedirectValid = $bRedirectValid || OAuth::redirectUriMatches($sRegistered, $sRedirectUri);
		}
		if (!$bRedirectValid) {
			$oPages->message('Error', 'Invalid redirect_uri', 'error', 400);
			return;
		}

		// From here errors are reported to the client (RFC 6749 section 4.1.2.1)
		$sState = $aParams['state'] ?? '';
		$fError = function (string $sError, string $sDescription) use ($sRedirectUri, $sState) {
			$aError = ['error' => $sError, 'error_description' => $sDescription, 'iss' => $this->issuer()];
			\strlen($sState) && $aError['state'] = $sState;
			static::redirectWith($sRedirectUri, $aError);
		};
		if ('code' !== ($aParams['response_type'] ?? '')) {
			$fError('unsupported_response_type', 'Only response_type=code is supported');
			return;
		}
		$sChallenge = $aParams['code_challenge'] ?? '';
		if ('S256' !== ($aParams['code_challenge_method'] ?? '') || !\preg_match('/^[A-Za-z0-9\-_]{43}$/', $sChallenge)) {
			$fError('invalid_request', 'PKCE with code_challenge_method=S256 is required');
			return;
		}
		$sResource = $aParams['resource'] ?? '';
		if (!$this->validResource($sResource)) {
			$fError('invalid_target', 'Unknown resource');
			return;
		}
		$aScopes = $this->oOAuth->filterScopes(isset($aParams['scope']) ? $aParams['scope'] : ($aClient['scope'] ?? null));
		if (!$aScopes) {
			$fError('invalid_scope', 'None of the requested scopes is available');
			return;
		}

		$aRequest = [
			'response_type' => 'code',
			'client_id' => $sClientId,
			'redirect_uri' => $sRedirectUri,
			'code_challenge' => $sChallenge,
			'code_challenge_method' => 'S256',
			'scope' => \implode(' ', $aScopes),
			'state' => $sState,
			'resource' => $sResource
		];
		$sReturnUrl = $this->url('authorize', \array_filter($aRequest, 'strlen'));

		$oAccount = $this->requireLogin($sMethod, $sReturnUrl, $oPages);
		if (!$oAccount) {
			return;
		}

		$sCsrfPurpose = "mcp-authorize|{$sClientId}|{$sRedirectUri}|{$sChallenge}";
		$sError = '';
		if ('POST' === $sMethod && \in_array($_POST['action'] ?? '', ['allow', 'deny'])) {
			if (!static::checkCsrf($sCsrfPurpose, $_POST)) {
				$sError = 'Invalid request, please try again.';
			} else if ('deny' === $_POST['action']) {
				$fError('access_denied', 'The user denied the request');
				return;
			} else {
				$aGranted = \array_values(\array_intersect($aScopes, (array) ($_POST['granted_scope'] ?? [])));
				if (!$aGranted) {
					$sError = 'Select at least one permission.';
				} else {
					$sCode = $this->oOAuth->createAuthorizationCode(
						$oAccount, $aClient, $sRedirectUri, $aGranted, $sChallenge, $sResource
					);
					$aResult = ['code' => $sCode, 'iss' => $this->issuer()];
					\strlen($sState) && $aResult['state'] = $sState;
					static::redirectWith($sRedirectUri, $aResult);
					return;
				}
			}
		}

		$oPages->consent(
			$aClient,
			(string) (\parse_url($sRedirectUri, \PHP_URL_HOST) ?: $sRedirectUri),
			$oAccount->Email(),
			$aScopes,
			\array_merge($aRequest, ['csrf' => static::csrf($sCsrfPurpose)]),
			$this->url('connections'),
			!!$this->oActions->GetAccounts($oAccount),
			$sError
		);
	}

	/**
	 * Send confirmation page
	 */
	private function confirm(string $sMethod) : void
	{
		$oPages = new Pages;
		$sId = static::str($_GET, 'id');
		$sKey = static::str($_GET, 'k');
		$aPending = $this->oPending->get($sId);
		if (!$aPending || !\strlen($sKey)) {
			$oPages->message('Error', $oPages->t('Unknown or expired link.'), 'error', 404);
			return;
		}

		$oAccount = $this->requireLogin($sMethod, $this->url('confirm', ['id' => $sId, 'k' => $sKey]), $oPages);
		if (!$oAccount) {
			return;
		}
		if ($oAccount->Email() !== $aPending['email']) {
			$oPages->message('Error', $oPages->t('Please sign in as %s.', \SnappyMail\IDN::emailToUtf8($aPending['email'])), 'error', 403);
			return;
		}

		$fStatusPage = function (array $aPending) use ($oPages) : bool {
			switch ($aPending['status']) {
				case Pending::STATUS_SENT:
					$oPages->message('Confirm email', $oPages->t('The email was sent.') . ' ' . $oPages->t('You can close this window.'), 'ok');
					return true;
				case Pending::STATUS_DISCARDED:
					$oPages->message('Confirm email', $oPages->t('The email was discarded.') . ' ' . $oPages->t('You can close this window.'));
					return true;
				case Pending::STATUS_SENDING:
					$oPages->message('Confirm email', $oPages->t('This email is being sent.'));
					return true;
				case 'expired':
					$oPages->message('Confirm email', $oPages->t('This email has expired.'), 'error');
					return true;
			}
			return false;
		};
		if ($fStatusPage($aPending)) {
			return;
		}

		$aMessage = $this->oPending->message($aPending, $sKey);
		if (!$aMessage) {
			$oPages->message('Error', $oPages->t('Unknown or expired link.'), 'error', 404);
			return;
		}

		$sCsrfPurpose = "mcp-confirm|{$sId}";
		$sError = 'failed' === $aPending['status'] ? (string) ($aPending['error'] ?? '') : '';
		if ('POST' === $sMethod && \in_array($_POST['action'] ?? '', ['send', 'discard'])) {
			if (!static::checkCsrf($sCsrfPurpose, $_POST)) {
				$sError = \html_entity_decode($oPages->t('Invalid request, please try again.'));
			} else if ('discard' === $_POST['action']) {
				$this->oPending->setStatus($aPending, Pending::STATUS_DISCARDED);
				$fStatusPage($this->oPending->get($sId));
				return;
			} else {
				// Prevent double sending
				$this->oPending->setStatus($aPending, Pending::STATUS_SENDING);
				try {
					$this->oActions->setRequestAccount($aPending['account']);
					$this->oActions->SetActionParams($aMessage, 'DoSendMessage');
					$this->oActions->DoSendMessage();
					$this->oPending->setStatus($this->oPending->get($sId), Pending::STATUS_SENT);
					$fStatusPage($this->oPending->get($sId));
					return;
				} catch (\Throwable $e) {
					$this->oActions->logException($e, \LOG_ERR);
					$sError = $e->getMessage();
					if ($e instanceof \RainLoop\Exceptions\ClientException) {
						$sError = \RainLoop\Notifications::GetNotificationsMessage($e->getCode(), $e->getPrevious())
							. ($e->getPrevious() ? ': ' . $e->getPrevious()->getMessage() : '');
					}
					$this->oPending->setStatus($this->oPending->get($sId), Pending::STATUS_FAILED, $sError);
				}
			}
		}

		$oPages->confirm($aPending, $aMessage, ['csrf' => static::csrf($sCsrfPurpose)], $sError);
	}

	/**
	 * Lists and revokes the authorized MCP clients of the logged in user
	 */
	private function connections(string $sMethod) : void
	{
		$oPages = new Pages;
		$oAccount = $this->requireLogin($sMethod, $this->url('connections'), $oPages);
		if (!$oAccount) {
			return;
		}
		$sNotice = '';
		if ('POST' === $sMethod && 'revoke' === ($_POST['action'] ?? '') && static::checkCsrf('mcp-connections', $_POST)) {
			$aGrant = $this->oOAuth->getGrant(static::str($_POST, 'grant'));
			if ($aGrant && $aGrant['email'] === $oAccount->Email()) {
				$this->oOAuth->revokeGrant($aGrant['id']);
				$sNotice = 'Access was revoked.';
			}
		}
		$oPages->connections($this->oOAuth->grantsOf($oAccount->Email()), static::csrf('mcp-connections'), $sNotice);
	}

	/**
	 * MCP Streamable HTTP transport (stateless, JSON responses)
	 */
	private function mcp(string $sMethod) : void
	{
		if ('POST' !== $sMethod) {
			// No server initiated SSE stream
			static::json(['error' => 'method_not_allowed'], 405, ['Allow: POST']);
			return;
		}

		$sToken = static::bearerToken();
		$sChallenge = 'Bearer resource_metadata="' . $this->resourceMetadataUrl() . '", scope="' . \implode(' ', $this->oOAuth->aAllowedScopes) . '"';
		if (!$sToken) {
			static::json(['error' => 'invalid_token', 'error_description' => 'Authorization required'], 401, ["WWW-Authenticate: {$sChallenge}"]);
			return;
		}
		try {
			[$aGrant, $oAccount, $aScopes] = $this->oOAuth->authenticate($sToken, $this->oActions);
		} catch (OAuthException $e) {
			static::json(
				['error' => $e->error, 'error_description' => $e->getMessage()],
				401,
				["WWW-Authenticate: {$sChallenge}, error=\"invalid_token\", error_description=\"{$e->getMessage()}\""]
			);
			return;
		}

		$mRequest = \json_decode((string) \file_get_contents('php://input'), true);
		if (!\is_array($mRequest)) {
			static::json(static::rpcError(null, -32700, 'Parse error'), 400);
			return;
		}

		$oTools = new Tools($this->oActions, $oAccount, $aScopes, $aGrant, $this->oPending,
			fn(string $sId, string $sKey) => $this->url('confirm', ['id' => $sId, 'k' => $sKey]));
		$oTools->iMaxRecipients = \max(1, (int) $this->config('max_recipients', 20));
		$oTools->iMaxAttachmentSize = 1048576 * \max(1, (int) $this->config('max_attachment_size', 5));

		$bBatch = \array_is_list($mRequest);
		$aResponses = [];
		foreach ($bBatch ? $mRequest : [$mRequest] as $aMessage) {
			$aResponse = \is_array($aMessage) ? $this->rpc($aMessage, $oTools) : static::rpcError(null, -32600, 'Invalid Request');
			$aResponse && $aResponses[] = $aResponse;
		}
		if (!$aResponses) {
			// Only notifications or responses
			\http_response_code(202);
			return;
		}
		static::json($bBatch ? $aResponses : $aResponses[0]);
	}

	private static function rpcError($mId, int $iCode, string $sMessage) : array
	{
		return ['jsonrpc' => '2.0', 'id' => $mId, 'error' => ['code' => $iCode, 'message' => $sMessage]];
	}

	private function rpc(array $aMessage, Tools $oTools) : ?array
	{
		if (!isset($aMessage['method'])) {
			// A response from the client, we never send requests
			return null;
		}
		$bNotification = !\array_key_exists('id', $aMessage);
		$mId = $aMessage['id'] ?? null;
		$aParams = \is_array($aMessage['params'] ?? null) ? $aMessage['params'] : [];

		try {
			switch ($aMessage['method']) {
				case 'initialize':
					$sVersion = (string) ($aParams['protocolVersion'] ?? '');
					$mResult = [
						'protocolVersion' => \in_array($sVersion, static::PROTOCOL_VERSIONS) ? $sVersion : static::PROTOCOL_VERSIONS[0],
						'capabilities' => ['tools' => ['listChanged' => false]],
						'serverInfo' => [
							'name' => 'snappymail',
							'title' => 'SnappyMail',
							'version' => APP_VERSION
						],
						'instructions' => 'Access to the mailbox of the SnappyMail user. Use list_folders and list_messages to find messages and get_message to read them. '
							. 'send_email never sends directly: it returns a confirmation_url that the user must open to confirm the email, always show this link to the user.'
					];
					break;
				case 'ping':
					$mResult = new \stdClass;
					break;
				case 'tools/list':
					$mResult = ['tools' => $oTools->definitions()];
					break;
				case 'tools/call':
					$sName = (string) ($aParams['name'] ?? '');
					$aArgs = \is_array($aParams['arguments'] ?? null) ? $aParams['arguments'] : [];
					try {
						$mResult = $oTools->call($sName, $aArgs);
					} catch (\InvalidArgumentException $e) {
						return $bNotification ? null : static::rpcError($mId, -32602, $e->getMessage());
					}
					break;
				case 'resources/list':
					$mResult = ['resources' => []];
					break;
				case 'prompts/list':
					$mResult = ['prompts' => []];
					break;
				default:
					if ($bNotification) {
						// notifications/initialized, notifications/cancelled, etc.
						return null;
					}
					return static::rpcError($mId, -32601, 'Method not found');
			}
		} catch (\Throwable $e) {
			$this->oActions->logException($e, \LOG_ERR);
			return $bNotification ? null : static::rpcError($mId, -32603, 'Internal error');
		}

		return $bNotification ? null : ['jsonrpc' => '2.0', 'id' => $mId, 'result' => $mResult];
	}
}
