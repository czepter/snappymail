<?php

namespace McpServer;

/**
 * Minimal server side rendered pages: login, consent, send confirmation
 * and the list of connected apps.
 */
class Pages
{
	const STRINGS = [
		'de' => [
			'Sign in' => 'Anmelden',
			'Email' => 'E-Mail',
			'Password' => 'Passwort',
			'Two-factor code (if enabled)' => 'Zwei-Faktor-Code (falls aktiviert)',
			'Sign in to continue.' => 'Bitte melden Sie sich an, um fortzufahren.',
			'Login failed' => 'Anmeldung fehlgeschlagen',
			'Authorize access' => 'Zugriff erlauben',
			'wants to access your mailbox' => 'möchte auf Ihr Postfach zugreifen',
			'Account' => 'Konto',
			'Redirects to' => 'Weiterleitung an',
			'The app name was provided by the app itself and is not verified.' => 'Der App-Name wurde von der App selbst angegeben und ist nicht verifiziert.',
			'Permissions' => 'Berechtigungen',
			'Read folders, messages, attachments and contacts' => 'Ordner, Nachrichten, Anhänge und Kontakte lesen',
			'Flag, move and delete messages, save drafts' => 'Nachrichten markieren, verschieben und löschen, Entwürfe speichern',
			'Prepare emails that are only sent after your confirmation' => 'E-Mails vorbereiten, die erst nach Ihrer Bestätigung gesendet werden',
			'Additional accounts of this login are accessible as well.' => 'Zusätzliche Konten dieser Anmeldung sind ebenfalls zugänglich.',
			'Allow' => 'Erlauben',
			'Deny' => 'Ablehnen',
			'Manage connected apps' => 'Verbundene Apps verwalten',
			'Select at least one permission.' => 'Bitte mindestens eine Berechtigung auswählen.',
			'Error' => 'Fehler',
			'Confirm email' => 'E-Mail bestätigen',
			'prepared this email. It will only be sent when you confirm it.' => 'hat diese E-Mail vorbereitet. Sie wird erst gesendet, wenn Sie sie bestätigen.',
			'From' => 'Von',
			'To' => 'An',
			'Cc' => 'Cc',
			'Bcc' => 'Bcc',
			'Subject' => 'Betreff',
			'Reply to an existing message' => 'Antwort auf eine vorhandene Nachricht',
			'This email also has an HTML version, the text version is shown.' => 'Diese E-Mail hat zusätzlich eine HTML-Version, angezeigt wird die Textversion.',
			'Send now' => 'Jetzt senden',
			'Discard' => 'Verwerfen',
			'The email was sent.' => 'Die E-Mail wurde gesendet.',
			'The email was discarded.' => 'Die E-Mail wurde verworfen.',
			'This email has expired.' => 'Diese E-Mail ist abgelaufen.',
			'This email is being sent.' => 'Diese E-Mail wird gerade gesendet.',
			'Sending failed' => 'Senden fehlgeschlagen',
			'Unknown or expired link.' => 'Unbekannter oder abgelaufener Link.',
			'Please sign in as %s.' => 'Bitte melden Sie sich als %s an.',
			'Sign out' => 'Abmelden',
			'Connected apps' => 'Verbundene Apps',
			'No apps are connected.' => 'Es sind keine Apps verbunden.',
			'Authorized' => 'Autorisiert',
			'Last used' => 'Zuletzt verwendet',
			'never' => 'nie',
			'Revoke' => 'Widerrufen',
			'Access was revoked.' => 'Der Zugriff wurde widerrufen.',
			'You can close this window.' => 'Sie können dieses Fenster schließen.',
			'Continue' => 'Weiter',
			'Expires' => 'Läuft ab',
			'Invalid request, please try again.' => 'Ungültige Anfrage, bitte erneut versuchen.'
		]
	];

	private string $sLang = 'en';

	private string $sNonce;

	function __construct()
	{
		$sAccept = \strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
		if (\preg_match('/^\s*([a-z]{2})/', $sAccept, $aMatch) && isset(static::STRINGS[$aMatch[1]])) {
			$this->sLang = $aMatch[1];
		}
		$this->sNonce = \bin2hex(\random_bytes(16));
	}

	public function t(string $sText, string ...$aArgs) : string
	{
		$sText = static::STRINGS[$this->sLang][$sText] ?? $sText;
		return \htmlspecialchars($aArgs ? \sprintf($sText, ...$aArgs) : $sText, \ENT_QUOTES, 'UTF-8');
	}

	public static function e(?string $sText) : string
	{
		return \htmlspecialchars((string) $sText, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
	}

	public function render(string $sTitle, string $sBody, int $iStatus = 200, string $sHead = '') : void
	{
		\http_response_code($iStatus);
		\header('Content-Type: text/html; charset=utf-8');
		\header('Cache-Control: no-store');
		\header('X-Frame-Options: DENY');
		\header("Content-Security-Policy: default-src 'none'; style-src 'nonce-{$this->sNonce}'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'");
		$sTitle = $this->t($sTitle);
		echo <<<HTML
<!DOCTYPE html>
<html lang="{$this->sLang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex, nofollow">
<title>{$sTitle}</title>
{$sHead}
<style nonce="{$this->sNonce}">
:root{--bg:#f3f4f6;--card:#fff;--text:#1f2937;--muted:#6b7280;--border:#e5e7eb;--primary:#2563eb;--danger:#b91c1c;--ok:#047857}
@media (prefers-color-scheme:dark){:root{--bg:#111827;--card:#1f2937;--text:#f3f4f6;--muted:#9ca3af;--border:#374151;--primary:#3b82f6;--danger:#f87171;--ok:#34d399}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:640px;margin:40px auto;padding:0 16px}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:24px}
h1{font-size:1.35rem;margin:0 0 16px}
p{margin:0 0 12px}
.muted{color:var(--muted);font-size:.9rem}
.error{color:var(--danger);font-weight:600}
.ok{color:var(--ok);font-weight:600}
label{display:block;margin:12px 0 4px;font-weight:600}
input[type=text],input[type=email],input[type=password]{width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font:inherit}
.check{display:flex;gap:10px;align-items:flex-start;font-weight:400;margin:8px 0}
.check input{margin-top:4px}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}
button{padding:9px 18px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:var(--text);font:inherit;cursor:pointer}
button.primary{background:var(--primary);border-color:var(--primary);color:#fff}
button.danger{color:var(--danger)}
dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;margin:0 0 16px}
dt{color:var(--muted)}
dd{margin:0;word-break:break-word}
pre{white-space:pre-wrap;word-break:break-word;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:12px;max-height:50vh;overflow:auto;font:13px/1.45 ui-monospace,SFMono-Regular,Menlo,monospace}
table{width:100%;border-collapse:collapse}
td,th{text-align:left;padding:8px 6px;border-bottom:1px solid var(--border);vertical-align:top}
a{color:var(--primary)}
</style>
</head>
<body><main><div class="card">
<h1>{$sTitle}</h1>
{$sBody}
</div></main></body>
</html>
HTML;
	}

	public static function hidden(array $aFields) : string
	{
		$sResult = '';
		foreach ($aFields as $sName => $sValue) {
			$sResult .= '<input type="hidden" name="' . static::e($sName) . '" value="' . static::e((string) $sValue) . '">';
		}
		return $sResult;
	}

	public function message(string $sTitle, string $sText, string $sClass = '', int $iStatus = 200) : void
	{
		$this->render($sTitle, '<p' . ($sClass ? " class=\"{$sClass}\"" : '') . '>' . $sText . '</p>', $iStatus);
	}

	/**
	 * Reloads the page, so that SameSite=Strict cookies are sent after
	 * a cross-site navigation (like coming from the MCP client)
	 */
	public function bounce(string $sUrl) : void
	{
		$sUrl = static::e($sUrl);
		$this->render('Continue',
			"<p><a href=\"{$sUrl}\">{$this->t('Continue')}</a></p>",
			200,
			"<meta http-equiv=\"refresh\" content=\"0;url={$sUrl}\">"
		);
	}

	public function login(string $sCsrf, string $sError = '', string $sEmail = '', bool $bTwoFactor = false) : void
	{
		$sBody = '<p>' . $this->t('Sign in to continue.') . '</p>';
		if ($sError) {
			$sBody .= '<p class="error">' . static::e($sError) . '</p>';
		}
		$sBody .= '<form method="post" autocomplete="on">'
			. static::hidden(['action' => 'login', 'csrf' => $sCsrf])
			. '<label for="email">' . $this->t('Email') . '</label>'
			. '<input type="email" id="email" name="email" required autocomplete="username" value="' . static::e($sEmail) . '">'
			. '<label for="password">' . $this->t('Password') . '</label>'
			. '<input type="password" id="password" name="password" required autocomplete="current-password">';
		if ($bTwoFactor) {
			$sBody .= '<label for="totp">' . $this->t('Two-factor code (if enabled)') . '</label>'
				. '<input type="text" id="totp" name="totp_code" inputmode="numeric" autocomplete="one-time-code">';
		}
		$sBody .= '<div class="actions"><button class="primary" type="submit">' . $this->t('Sign in') . '</button></div></form>';
		$this->render('Sign in', $sBody);
	}

	public function consent(array $aClient, string $sRedirectHost, string $sEmail, array $aScopes,
		array $aHidden, string $sConnectionsUrl, bool $bHasAdditional, string $sError = '') : void
	{
		$sBody = '<p><strong>' . static::e($aClient['client_name']) . '</strong> ' . $this->t('wants to access your mailbox') . '.</p>';
		if ($sError) {
			$sBody .= '<p class="error">' . $this->t($sError) . '</p>';
		}
		$sBody .= '<dl>'
			. '<dt>' . $this->t('Account') . '</dt><dd>' . static::e(\SnappyMail\IDN::emailToUtf8($sEmail)) . '</dd>'
			. '<dt>' . $this->t('Redirects to') . '</dt><dd>' . static::e($sRedirectHost) . '</dd>'
			. '</dl>'
			. '<p class="muted">' . $this->t('The app name was provided by the app itself and is not verified.') . '</p>'
			. '<form method="post">' . static::hidden($aHidden)
			. '<label>' . $this->t('Permissions') . '</label>';
		foreach ($aScopes as $sScope) {
			$sBody .= '<label class="check"><input type="checkbox" name="granted_scope[]" value="' . static::e($sScope) . '" checked> <span>'
				. $this->t(OAuth::SCOPES[$sScope]) . ' <span class="muted">(' . static::e($sScope) . ')</span></span></label>';
		}
		if ($bHasAdditional) {
			$sBody .= '<p class="muted">' . $this->t('Additional accounts of this login are accessible as well.') . '</p>';
		}
		$sBody .= '<div class="actions">'
			. '<button class="primary" type="submit" name="action" value="allow">' . $this->t('Allow') . '</button>'
			. '<button type="submit" name="action" value="deny">' . $this->t('Deny') . '</button>'
			. '</div></form>'
			. '<p class="muted"><br><a href="' . static::e($sConnectionsUrl) . '" target="_blank" rel="noopener">' . $this->t('Manage connected apps') . '</a></p>';
		$this->render('Authorize access', $sBody);
	}

	public function confirm(array $aPending, array $aMessage, array $aHidden, string $sError = '') : void
	{
		$sBody = '<p><strong>' . static::e($aPending['client_name'] ?: 'MCP client') . '</strong> '
			. $this->t('prepared this email. It will only be sent when you confirm it.') . '</p>';
		if ($sError) {
			$sBody .= '<p class="error">' . $this->t('Sending failed') . ': ' . static::e($sError) . '</p>';
		}
		$sBody .= '<dl>'
			. '<dt>' . $this->t('From') . '</dt><dd>' . static::e($aMessage['from']) . '</dd>'
			. '<dt>' . $this->t('To') . '</dt><dd>' . static::e($aMessage['to']) . '</dd>';
		if (\strlen($aMessage['cc'])) {
			$sBody .= '<dt>' . $this->t('Cc') . '</dt><dd>' . static::e($aMessage['cc']) . '</dd>';
		}
		if (\strlen($aMessage['bcc'])) {
			$sBody .= '<dt>' . $this->t('Bcc') . '</dt><dd>' . static::e($aMessage['bcc']) . '</dd>';
		}
		$sBody .= '<dt>' . $this->t('Subject') . '</dt><dd>' . static::e($aMessage['subject']) . '</dd>'
			. '<dt>' . $this->t('Expires') . '</dt><dd>' . static::e(\gmdate('Y-m-d H:i', $aPending['expires_at'])) . ' UTC</dd>'
			. '</dl>';
		if (!empty($aMessage['draftInfo'])) {
			$sBody .= '<p class="muted">' . $this->t('Reply to an existing message') . '</p>';
		}
		if (\strlen($aMessage['html'] ?? '')) {
			$sBody .= '<p class="muted">' . $this->t('This email also has an HTML version, the text version is shown.') . '</p>';
		}
		$sBody .= '<pre>' . static::e($aMessage['plain']) . '</pre>'
			. '<form method="post">' . static::hidden($aHidden)
			. '<div class="actions">'
			. '<button class="primary" type="submit" name="action" value="send">' . $this->t('Send now') . '</button>'
			. '<button class="danger" type="submit" name="action" value="discard">' . $this->t('Discard') . '</button>'
			. '</div></form>';
		$this->render('Confirm email', $sBody);
	}

	public function connections(array $aGrants, string $sCsrf, string $sNotice = '') : void
	{
		$sBody = $sNotice ? '<p class="ok">' . $this->t($sNotice) . '</p>' : '';
		if (!$aGrants) {
			$sBody .= '<p>' . $this->t('No apps are connected.') . '</p>';
		} else {
			$sBody .= '<table><tr><th>App</th><th>' . $this->t('Permissions') . '</th><th>' . $this->t('Last used') . '</th><th></th></tr>';
			foreach ($aGrants as $aGrant) {
				$sBody .= '<tr><td><strong>' . static::e($aGrant['client_name']) . '</strong><br><span class="muted">'
					. static::e($aGrant['redirect_host'] ?? '') . '<br>' . $this->t('Authorized') . ': ' . static::e(\gmdate('Y-m-d', $aGrant['created'])) . '</span></td>'
					. '<td>' . static::e(\implode(', ', $aGrant['scopes'])) . '</td>'
					. '<td>' . ($aGrant['last_used'] ? static::e(\gmdate('Y-m-d H:i', $aGrant['last_used'])) . ' UTC' : $this->t('never')) . '</td>'
					. '<td><form method="post">' . static::hidden(['action' => 'revoke', 'grant' => $aGrant['id'], 'csrf' => $sCsrf])
					. '<button class="danger" type="submit">' . $this->t('Revoke') . '</button></form></td></tr>';
			}
			$sBody .= '</table>';
		}
		$this->render('Connected apps', $sBody);
	}
}
