<?php

namespace McpServer;

use RainLoop\Exceptions\ClientException;
use RainLoop\Model\Account;
use RainLoop\Model\MainAccount;
use RainLoop\Notifications;

/**
 * The MCP tools, they use the regular SnappyMail actions so that all
 * settings, hooks and plugins apply as in the webmail itself.
 */
class Tools
{
	private \RainLoop\Actions $oActions;

	private MainAccount $oMainAccount;

	private array $aScopes;

	private array $aGrant;

	private Pending $oPending;

	/**
	 * Creates the confirmation link of a pending email
	 * @var callable(string $sId, string $sKey): string
	 */
	private $fConfirmUrl;

	public int $iMaxRecipients = 20;

	public int $iMaxAttachmentSize = 5242880;

	public int $iMaxBodyChars = 30000;

	function __construct(\RainLoop\Actions $oActions, MainAccount $oMainAccount, array $aScopes,
		array $aGrant, Pending $oPending, callable $fConfirmUrl)
	{
		$this->oActions = $oActions;
		$this->oMainAccount = $oMainAccount;
		$this->aScopes = $aScopes;
		$this->aGrant = $aGrant;
		$this->oPending = $oPending;
		$this->fConfirmUrl = $fConfirmUrl;
		$oActions->SetMainAuthAccount($oMainAccount);
	}

	/**
	 * Tool definitions
	 */

	private static function accountProperty() : array
	{
		return [
			'type' => 'string',
			'description' => 'Email address of an additional account (see list_accounts). Defaults to the main account.'
		];
	}

	private static function uidsProperty() : array
	{
		return [
			'type' => 'array',
			'items' => ['type' => 'integer', 'minimum' => 1],
			'minItems' => 1,
			'maxItems' => 500,
			'description' => 'Message UIDs as returned by list_messages'
		];
	}

	private static function composeProperties() : array
	{
		return [
			'to' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Recipients, like "jane@example.com" or "Jane Doe <jane@example.com>"'],
			'cc' => ['type' => 'array', 'items' => ['type' => 'string']],
			'bcc' => ['type' => 'array', 'items' => ['type' => 'string']],
			'subject' => ['type' => 'string'],
			'body' => ['type' => 'string', 'description' => 'Plain text body'],
			'html' => ['type' => 'string', 'description' => 'Optional HTML body instead of "body", the text version is then generated from it'],
			'from' => ['type' => 'string', 'description' => 'Sender address, must be one of the identities from list_accounts. Defaults to the first identity.'],
			'in_reply_to' => [
				'type' => 'object',
				'description' => 'The message this is a reply to, sets the threading headers and the answered flag',
				'properties' => [
					'folder' => ['type' => 'string'],
					'uid' => ['type' => 'integer']
				],
				'required' => ['folder', 'uid']
			],
			'append_signature' => ['type' => 'boolean', 'description' => 'Append the signature of the identity. Default true.'],
			'account' => static::accountProperty()
		];
	}

	public function definitions() : array
	{
		$aTools = [];
		$sOpenWorld = 'openWorldHint';

		if ($this->can(OAuth::SCOPE_READ)) {
			$aTools[] = [
				'name' => 'list_accounts',
				'title' => 'List accounts and identities',
				'description' => 'Lists the mail accounts (main and additional) and the sender identities of an account.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => ['account' => static::accountProperty()]
				],
				'annotations' => ['readOnlyHint' => true, $sOpenWorld => false]
			];
			$aTools[] = [
				'name' => 'list_folders',
				'title' => 'List folders',
				'description' => 'Lists all mail folders with their role (inbox, sent, drafts, trash, junk, archive) and message counts.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => ['account' => static::accountProperty()]
				],
				'annotations' => ['readOnlyHint' => true, $sOpenWorld => false]
			];
			$aTools[] = [
				'name' => 'list_messages',
				'title' => 'List or search messages',
				'description' => 'Lists messages of a folder, newest first. All filters are optional and combined with AND.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'folder' => ['type' => 'string', 'description' => 'Full folder name, default "INBOX"'],
						'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Default 20'],
						'offset' => ['type' => 'integer', 'minimum' => 0],
						'query' => ['type' => 'string', 'description' => 'Free text, searched in from, to, cc and subject. Combined with other filters it searches the message text.'],
						'from' => ['type' => 'string'],
						'to' => ['type' => 'string', 'description' => 'Matches To and Cc'],
						'subject' => ['type' => 'string'],
						'text' => ['type' => 'string', 'description' => 'Search in the message body'],
						'since' => ['type' => 'string', 'description' => 'Date YYYY-MM-DD, messages on or after'],
						'before' => ['type' => 'string', 'description' => 'Date YYYY-MM-DD, messages before'],
						'unread_only' => ['type' => 'boolean'],
						'flagged_only' => ['type' => 'boolean'],
						'has_attachment' => ['type' => 'boolean'],
						'account' => static::accountProperty()
					]
				],
				'annotations' => ['readOnlyHint' => true, $sOpenWorld => false]
			];
			$aTools[] = [
				'name' => 'get_message',
				'title' => 'Read a message',
				'description' => 'Returns headers, the text body and the attachment list of a message. Does not mark it as read.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'folder' => ['type' => 'string'],
						'uid' => ['type' => 'integer', 'minimum' => 1],
						'max_chars' => ['type' => 'integer', 'minimum' => 100, 'description' => 'Truncate the body, default ' . $this->iMaxBodyChars],
						'include_html' => ['type' => 'boolean', 'description' => 'Also return the raw HTML body'],
						'account' => static::accountProperty()
					],
					'required' => ['folder', 'uid']
				],
				'annotations' => ['readOnlyHint' => true, $sOpenWorld => false]
			];
			$aTools[] = [
				'name' => 'get_attachment',
				'title' => 'Download an attachment',
				'description' => 'Returns the content of an attachment: text files as text, images as image, other files base64 encoded.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'folder' => ['type' => 'string'],
						'uid' => ['type' => 'integer', 'minimum' => 1],
						'mime_index' => ['type' => 'string', 'description' => 'As returned by get_message'],
						'account' => static::accountProperty()
					],
					'required' => ['folder', 'uid', 'mime_index']
				],
				'annotations' => ['readOnlyHint' => true, $sOpenWorld => false]
			];
			$aTools[] = [
				'name' => 'search_contacts',
				'title' => 'Search contacts',
				'description' => 'Searches the personal address book by name or email address.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'query' => ['type' => 'string'],
						'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]
					]
				],
				'annotations' => ['readOnlyHint' => true, $sOpenWorld => false]
			];
		}

		if ($this->can(OAuth::SCOPE_WRITE)) {
			$aTools[] = [
				'name' => 'mark_messages',
				'title' => 'Mark messages',
				'description' => 'Marks messages as read/unread and/or flagged/unflagged.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'folder' => ['type' => 'string'],
						'uids' => static::uidsProperty(),
						'seen' => ['type' => 'boolean'],
						'flagged' => ['type' => 'boolean'],
						'account' => static::accountProperty()
					],
					'required' => ['folder', 'uids']
				],
				'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, $sOpenWorld => false]
			];
			$aTools[] = [
				'name' => 'move_messages',
				'title' => 'Move messages',
				'description' => 'Moves messages to another folder.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'folder' => ['type' => 'string'],
						'uids' => static::uidsProperty(),
						'destination' => ['type' => 'string', 'description' => 'Full name of the target folder'],
						'account' => static::accountProperty()
					],
					'required' => ['folder', 'uids', 'destination']
				],
				'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, $sOpenWorld => false]
			];
			$aTools[] = [
				'name' => 'delete_messages',
				'title' => 'Delete messages',
				'description' => 'Moves messages to the trash folder. Messages that are already in the trash (or when there is no trash folder) are only deleted permanently when "permanent" is true.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'folder' => ['type' => 'string'],
						'uids' => static::uidsProperty(),
						'permanent' => ['type' => 'boolean'],
						'account' => static::accountProperty()
					],
					'required' => ['folder', 'uids']
				],
				'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, $sOpenWorld => false]
			];
			$aTools[] = [
				'name' => 'save_draft',
				'title' => 'Save a draft',
				'description' => 'Saves a message in the drafts folder, so the user can review and send it in the webmail.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => static::composeProperties(),
					'required' => ['subject']
				],
				'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, $sOpenWorld => false]
			];
		}

		if ($this->can(OAuth::SCOPE_SEND)) {
			$aTools[] = [
				'name' => 'send_email',
				'title' => 'Send an email (requires confirmation)',
				'description' => 'Prepares an email for sending. The email is NOT sent by this tool: the result contains a confirmation_url that the user must open in the browser to review and confirm (or discard) the email. Always give the user this link. Use get_email_status to check whether it was sent.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => static::composeProperties(),
					'required' => ['to', 'subject']
				],
				'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, $sOpenWorld => true]
			];
			$aTools[] = [
				'name' => 'get_email_status',
				'title' => 'Status of a prepared email',
				'description' => 'Returns whether a prepared email is still waiting for confirmation, was sent, discarded or has expired.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => ['id' => ['type' => 'string']],
					'required' => ['id']
				],
				'annotations' => ['readOnlyHint' => true, $sOpenWorld => false]
			];
			$aTools[] = [
				'name' => 'cancel_email',
				'title' => 'Cancel a prepared email',
				'description' => 'Discards a prepared email that was not confirmed yet.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => ['id' => ['type' => 'string']],
					'required' => ['id']
				],
				'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, $sOpenWorld => false]
			];
		}

		return $aTools;
	}

	private function can(string $sScope) : bool
	{
		return \in_array($sScope, $this->aScopes, true);
	}

	/**
	 * @return array MCP tools/call result
	 */
	public function call(string $sName, array $aArgs) : array
	{
		foreach ($this->definitions() as $aTool) {
			if ($aTool['name'] === $sName) {
				$sMethod = 'tool' . \str_replace('_', '', \ucwords($sName, '_'));
				try {
					$mResult = $this->{$sMethod}($aArgs);
				} catch (ToolException $e) {
					return static::errorResult($e->getMessage());
				} catch (ClientException $e) {
					$sMessage = Notifications::GetNotificationsMessage($e->getCode(), $e->getPrevious());
					if ($e->getPrevious() && Notifications::ClientViewError !== $e->getCode()) {
						$sMessage .= ': ' . $e->getPrevious()->getMessage();
					} else if ($e->getAdditionalMessage()) {
						$sMessage .= ': ' . $e->getAdditionalMessage();
					}
					if (Notifications::AuthError === $e->getCode()) {
						$sMessage .= ' (the mail server rejected the stored credentials, the user may need to authorize this client again)';
					}
					return static::errorResult($sMessage);
				} catch (\Throwable $e) {
					$this->oActions->logException($e, \LOG_ERR);
					return static::errorResult(\get_class($e) . ': ' . $e->getMessage());
				}
				if (isset($mResult['content'])) {
					return $mResult;
				}
				return [
					'content' => [[
						'type' => 'text',
						'text' => \json_encode($mResult, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PRETTY_PRINT)
					]],
					'structuredContent' => $mResult,
					'isError' => false
				];
			}
		}
		throw new \InvalidArgumentException("Unknown tool: {$sName}");
	}

	private static function errorResult(string $sMessage) : array
	{
		return [
			'content' => [['type' => 'text', 'text' => $sMessage]],
			'isError' => true
		];
	}

	/**
	 * Helpers
	 */

	private function useAccount(array $aArgs) : Account
	{
		$sEmail = \trim((string) ($aArgs['account'] ?? ''));
		return $this->oActions->setRequestAccount($sEmail ?: $this->oMainAccount->Email());
	}

	/**
	 * Runs a regular SnappyMail JSON action and returns its result as array
	 */
	private function action(string $sMethod, array $aParams) : mixed
	{
		$this->oActions->SetActionParams($aParams, $sMethod);
		$aResponse = $this->oActions->{$sMethod}();
		return \json_decode(\json_encode($aResponse['Result'] ?? null, \JSON_INVALID_UTF8_SUBSTITUTE), true);
	}

	private function imap(Account $oAccount) : \MailSo\Imap\ImapClient
	{
		$oImapClient = $this->oActions->ImapClient();
		if (!$oImapClient->IsLoggined()) {
			try {
				$oAccount->ImapConnectAndLogin($this->oActions->Plugins(), $oImapClient, $this->oActions->Config());
			} catch (\MailSo\Net\Exceptions\ConnectionException $e) {
				throw new ClientException(Notifications::ConnectionError, $e);
			} catch (\Throwable $e) {
				throw new ClientException(Notifications::AuthError, $e);
			}
		}
		return $oImapClient;
	}

	private function settingsLocal(Account $oAccount) : ?\RainLoop\Settings
	{
		$oSettings = $this->oActions->SettingsProvider(true)->Load($oAccount);
		return $oSettings instanceof \RainLoop\Settings ? $oSettings : null;
	}

	const SYSTEM_FOLDERS = [
		'SentFolder' => 'sent',
		'DraftsFolder' => 'drafts',
		'TrashFolder' => 'trash',
		'JunkFolder' => 'junk',
		'ArchiveFolder' => 'archive'
	];

	private array $aFolderCache = [];

	/**
	 * DoFolders result of the current account
	 */
	private function folders(Account $oAccount) : array
	{
		$sKey = $oAccount->Email();
		if (!isset($this->aFolderCache[$sKey])) {
			$this->aFolderCache[$sKey] = $this->action('DoFolders', []) ?: [];
		}
		return $this->aFolderCache[$sKey];
	}

	/**
	 * Configured system folder (Settings -> Folders), else by the IMAP SPECIAL-USE role
	 */
	private function systemFolder(Account $oAccount, string $sName) : string
	{
		$oSettings = $this->settingsLocal($oAccount);
		$sFolder = $oSettings ? (string) $oSettings->GetConf($sName, '') : '';
		if (!\strlen($sFolder) && isset(static::SYSTEM_FOLDERS[$sName])) {
			foreach ($this->folders($oAccount)['@Collection'] ?? [] as $aFolder) {
				if (static::SYSTEM_FOLDERS[$sName] === ($aFolder['role'] ?? null)) {
					return $aFolder['fullName'];
				}
			}
		}
		return $sFolder;
	}

	private static function folderArg(array $aArgs, string $sKey = 'folder') : string
	{
		$sFolder = \trim((string) ($aArgs[$sKey] ?? ''));
		if (!\strlen($sFolder)) {
			throw new ToolException("{$sKey} is required");
		}
		return $sFolder;
	}

	private static function uidsArg(array $aArgs) : string
	{
		$aUids = \array_values(\array_unique(\array_filter(\array_map('intval', (array) ($aArgs['uids'] ?? [])), fn($i) => 0 < $i)));
		if (!$aUids) {
			throw new ToolException('uids is required');
		}
		return \implode(',', $aUids);
	}

	private static function emails(?array $aList) : array
	{
		return \array_map(fn($a) => \trim(($a['name'] ?? '') ? "{$a['name']} <{$a['email']}>" : ($a['email'] ?? '')), \array_values($aList ?: []));
	}

	private static function date(?int $iTime) : ?string
	{
		return $iTime ? \gmdate('c', $iTime) : null;
	}

	private static function flags(array $aFlags) : array
	{
		$aFlags = \array_map('strtolower', $aFlags);
		return [
			'seen' => \in_array('\\seen', $aFlags),
			'flagged' => \in_array('\\flagged', $aFlags),
			'answered' => \in_array('\\answered', $aFlags),
			'draft' => \in_array('\\draft', $aFlags)
		];
	}

	private static function attachments(?array $aAttachments) : array
	{
		$aResult = [];
		foreach ($aAttachments['@Collection'] ?? [] as $aAttachment) {
			$aResult[] = [
				'mime_index' => (string) $aAttachment['mimeIndex'],
				'file_name' => $aAttachment['fileName'],
				'mime_type' => $aAttachment['mimeType'],
				'size' => $aAttachment['estimatedSize'],
				'inline' => !empty($aAttachment['isInline'])
			];
		}
		return $aResult;
	}

	/**
	 * Read tools
	 */

	protected function toolListAccounts(array $aArgs) : array
	{
		$oAccount = $this->useAccount($aArgs);
		$aAccounts = [[
			'email' => \SnappyMail\IDN::emailToUtf8($this->oMainAccount->Email()),
			'name' => $this->oMainAccount->Name(),
			'main' => true
		]];
		foreach ($this->oActions->GetAccounts($this->oMainAccount) as $aAccount) {
			$aAccounts[] = [
				'email' => \SnappyMail\IDN::emailToUtf8($aAccount['email'] ?? $aAccount[1] ?? ''),
				'name' => $aAccount['name'] ?? '',
				'main' => false
			];
		}
		$aIdentities = [];
		foreach ($this->oActions->GetIdentities($oAccount) as $oIdentity) {
			$aIdentity = $oIdentity->jsonSerialize();
			$aIdentities[] = [
				'email' => $aIdentity['email'],
				'name' => $aIdentity['name'],
				'label' => $aIdentity['label'],
				'reply_to' => $aIdentity['replyTo']
			];
		}
		return [
			'accounts' => $aAccounts,
			'identities_of' => \SnappyMail\IDN::emailToUtf8($oAccount->Email()),
			'identities' => $aIdentities
		];
	}

	protected function toolListFolders(array $aArgs) : array
	{
		$oAccount = $this->useAccount($aArgs);
		$aResult = $this->folders($oAccount);
		$aSystem = [];
		foreach (static::SYSTEM_FOLDERS as $sKey => $sRole) {
			$sFolder = $this->systemFolder($oAccount, $sKey);
			if (\strlen($sFolder)) {
				$aSystem[$sFolder] = $sRole;
			}
		}
		$aFolders = [];
		foreach ($aResult['@Collection'] ?? [] as $aFolder) {
			if (\in_array('\\noselect', \array_map('strtolower', $aFolder['attributes'] ?? []))) {
				continue;
			}
			$aItem = [
				'name' => $aFolder['fullName'],
				'role' => $aSystem[$aFolder['fullName']] ?? $aFolder['role'] ?? null
			];
			if (isset($aFolder['totalEmails'])) {
				$aItem['total'] = $aFolder['totalEmails'];
			}
			if (isset($aFolder['unreadEmails'])) {
				$aItem['unread'] = $aFolder['unreadEmails'];
			}
			$aFolders[] = $aItem;
		}
		return [
			'account' => \SnappyMail\IDN::emailToUtf8($oAccount->Email()),
			'folders' => $aFolders,
			'quota_usage' => $aResult['quotaUsage'] ?? null,
			'quota_limit' => $aResult['quotaLimit'] ?? null
		];
	}

	protected function toolListMessages(array $aArgs) : array
	{
		$this->useAccount($aArgs);
		$sFolder = \trim((string) ($aArgs['folder'] ?? '')) ?: 'INBOX';
		$iLimit = \max(1, \min(100, (int) ($aArgs['limit'] ?? 20)));
		$iOffset = \max(0, (int) ($aArgs['offset'] ?? 0));

		$aSearch = [];
		foreach (['from' => 'from', 'to' => 'to', 'subject' => 'subject', 'text' => 'text'] as $sArg => $sKey) {
			if (\strlen(\trim((string) ($aArgs[$sArg] ?? '')))) {
				$aSearch[$sKey] = \trim($aArgs[$sArg]);
			}
		}
		foreach (['since' => 'since', 'before' => 'before'] as $sArg => $sKey) {
			if (!empty($aArgs[$sArg])) {
				if (!\preg_match('/^\d{4}-\d{2}-\d{2}$/', $aArgs[$sArg])) {
					throw new ToolException("{$sArg} must be a date like 2024-12-31");
				}
				$aSearch[$sKey] = $aArgs[$sArg];
			}
		}
		empty($aArgs['unread_only']) || $aSearch['unseen'] = 1;
		empty($aArgs['flagged_only']) || $aSearch['flagged'] = 1;
		empty($aArgs['has_attachment']) || $aSearch['attachment'] = 1;
		$sQuery = \trim((string) ($aArgs['query'] ?? ''));
		if ($aSearch) {
			if (\strlen($sQuery)) {
				$aSearch['text'] = \trim(($aSearch['text'] ?? '') . ' ' . $sQuery);
			}
			$sSearch = \http_build_query($aSearch);
		} else {
			// A plain string does a simple search (from, to, cc, subject)
			$sSearch = \str_replace(['=', '&', ':'], ' ', $sQuery);
		}

		$aResult = $this->action('DoMessageList', [
			'folder' => $sFolder,
			'offset' => $iOffset,
			'limit' => $iLimit,
			'search' => $sSearch,
			'sort' => '',
			'useThreads' => 0
		]);

		$aMessages = [];
		foreach ($aResult['@Collection'] ?? [] as $aMessage) {
			$aItem = [
				'uid' => $aMessage['uid'],
				'date' => static::date($aMessage['dateTimestamp'] ?? 0),
				'from' => static::emails($aMessage['from'] ?? null),
				'to' => static::emails($aMessage['to'] ?? null),
				'subject' => $aMessage['subject'],
				'flags' => static::flags($aMessage['flags'] ?? []),
				'size' => $aMessage['size'] ?? null
			];
			if (!empty($aMessage['cc'])) {
				$aItem['cc'] = static::emails($aMessage['cc']);
			}
			if (!empty($aMessage['preview'])) {
				$aItem['preview'] = $aMessage['preview'];
			}
			if (!empty($aMessage['attachments']['@Collection'])) {
				$aItem['attachments'] = \count($aMessage['attachments']['@Collection']);
			}
			$aMessages[] = $aItem;
		}

		return [
			'folder' => $sFolder,
			'total' => $aResult['totalEmails'] ?? \count($aMessages),
			'offset' => $iOffset,
			'limit' => $iLimit,
			'messages' => $aMessages
		];
	}

	protected function toolGetMessage(array $aArgs) : array
	{
		$this->useAccount($aArgs);
		$sFolder = static::folderArg($aArgs);
		$iUid = (int) ($aArgs['uid'] ?? 0);
		$aMessage = $this->action('DoMessage', [
			'folder' => $sFolder,
			'uid' => $iUid
		]);
		if (!$aMessage) {
			throw new ToolException('Message not found');
		}

		$sText = (string) ($aMessage['plain'] ?? '');
		$sHtml = (string) ($aMessage['html'] ?? '');
		if (!\strlen(\trim($sText)) && \strlen($sHtml)) {
			$sText = \MailSo\Base\HtmlUtils::ConvertHtmlToPlain($sHtml);
		}
		$iMax = \max(100, (int) ($aArgs['max_chars'] ?? $this->iMaxBodyChars));
		$bTruncated = \mb_strlen($sText) > $iMax;

		$aResult = [
			'folder' => $sFolder,
			'uid' => $iUid,
			'message_id' => $aMessage['messageId'] ?? '',
			'date' => static::date($aMessage['dateTimestamp'] ?? 0),
			'from' => static::emails($aMessage['from'] ?? null),
			'to' => static::emails($aMessage['to'] ?? null),
			'cc' => static::emails($aMessage['cc'] ?? null),
			'reply_to' => static::emails($aMessage['replyTo'] ?? null),
			'subject' => $aMessage['subject'] ?? '',
			'flags' => static::flags($aMessage['flags'] ?? []),
			'in_reply_to' => $aMessage['inReplyTo'] ?? '',
			'body' => $bTruncated ? \mb_substr($sText, 0, $iMax) : $sText,
			'body_truncated' => $bTruncated,
			'attachments' => static::attachments($aMessage['attachments'] ?? null)
		];
		if (!empty($aMessage['references'])) {
			$aResult['references'] = $aMessage['references'];
		}
		if (!empty($aMessage['encrypted'])) {
			$aResult['encrypted'] = true;
		}
		if (!empty($aMessage['isSpam'])) {
			$aResult['spam'] = true;
		}
		if (!empty($aArgs['include_html']) && \strlen($sHtml)) {
			$aResult['html'] = \mb_strlen($sHtml) > $iMax * 3 ? \mb_substr($sHtml, 0, $iMax * 3) : $sHtml;
		}
		return $aResult;
	}

	protected function toolGetAttachment(array $aArgs) : array
	{
		$oAccount = $this->useAccount($aArgs);
		$sFolder = static::folderArg($aArgs);
		$iUid = (int) ($aArgs['uid'] ?? 0);
		$sMimeIndex = \trim((string) ($aArgs['mime_index'] ?? ''));
		if (!\preg_match('/^[0-9]+(\.[0-9]+)*$/', $sMimeIndex)) {
			throw new ToolException('Invalid mime_index');
		}

		$oImapClient = $this->imap($oAccount);
		$sData = null;
		$sType = '';
		$sName = '';
		$iMax = $this->iMaxAttachmentSize;
		$this->oActions->MailClient()->MessageMimeStream(
			function ($rResource, $sContentType, $sFileName) use (&$sData, &$sType, &$sName, $iMax) {
				$sType = \strtolower(\trim(\explode(';', (string) $sContentType)[0])) ?: 'application/octet-stream';
				$sName = \MailSo\Base\Utils::SecureFileName((string) $sFileName);
				$sData = \stream_get_contents($rResource, $iMax + 1);
			},
			$sFolder, $iUid, $sMimeIndex
		);
		if (null === $sData || false === $sData) {
			throw new ToolException('Attachment not found');
		}
		if (\strlen($sData) > $iMax) {
			throw new ToolException("Attachment is larger than {$iMax} bytes");
		}

		$aMeta = [
			'file_name' => $sName,
			'mime_type' => $sType,
			'size' => \strlen($sData)
		];
		$bText = \str_starts_with($sType, 'text/')
			|| \in_array($sType, ['application/json', 'application/xml', 'application/javascript', 'application/x-yaml', 'application/yaml', 'application/csv', 'message/rfc822', 'text/calendar', 'application/ics']);
		if ($bText) {
			$sText = \MailSo\Base\Utils::Utf8Clear($sData);
			return [
				'content' => [
					['type' => 'text', 'text' => \json_encode($aMeta, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)],
					['type' => 'text', 'text' => $sText]
				],
				'isError' => false
			];
		}
		$aContent = [['type' => 'text', 'text' => \json_encode($aMeta, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)]];
		if (\in_array($sType, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'])) {
			$aContent[] = ['type' => 'image', 'data' => \base64_encode($sData), 'mimeType' => $sType];
		} else {
			$aContent[] = [
				'type' => 'resource',
				'resource' => [
					'uri' => 'snappymail://attachment/' . \rawurlencode($sFolder) . "/{$iUid}/{$sMimeIndex}/" . \rawurlencode($sName),
					'mimeType' => $sType,
					'blob' => \base64_encode($sData)
				]
			];
		}
		return ['content' => $aContent, 'isError' => false];
	}

	protected function toolSearchContacts(array $aArgs) : array
	{
		$oProvider = $this->oActions->AddressBookProvider($this->oMainAccount);
		if (!$oProvider->IsActive()) {
			throw new ToolException('The address book is not enabled');
		}
		$iCount = 0;
		$aContacts = [];
		$aList = $oProvider->GetContacts(0, \max(1, \min(100, (int) ($aArgs['limit'] ?? 20))), \trim((string) ($aArgs['query'] ?? '')), $iCount);
		foreach ($aList as $oContact) {
			$oVCard = $oContact->vCard;
			if (!$oVCard) {
				continue;
			}
			$aItem = [
				'name' => isset($oVCard->FN) ? (string) $oVCard->FN : '',
				'emails' => [],
			];
			foreach ($oVCard->EMAIL ?? [] as $oProperty) {
				$aItem['emails'][] = (string) $oProperty;
			}
			foreach ($oVCard->TEL ?? [] as $oProperty) {
				$aItem['phones'][] = (string) $oProperty;
			}
			if (isset($oVCard->ORG)) {
				$aItem['organization'] = (string) $oVCard->ORG;
			}
			$aContacts[] = $aItem;
		}
		return [
			'total' => $iCount,
			'contacts' => $aContacts
		];
	}

	/**
	 * Write tools
	 */

	protected function toolMarkMessages(array $aArgs) : array
	{
		$this->useAccount($aArgs);
		$sFolder = static::folderArg($aArgs);
		$sUids = static::uidsArg($aArgs);
		if (!isset($aArgs['seen']) && !isset($aArgs['flagged'])) {
			throw new ToolException('Set "seen" and/or "flagged"');
		}
		if (isset($aArgs['seen'])) {
			$this->action('DoMessageSetSeen', ['folder' => $sFolder, 'uids' => $sUids, 'setAction' => $aArgs['seen'] ? 1 : 0]);
		}
		if (isset($aArgs['flagged'])) {
			$this->action('DoMessageSetFlagged', ['folder' => $sFolder, 'uids' => $sUids, 'setAction' => $aArgs['flagged'] ? 1 : 0]);
		}
		return ['success' => true, 'folder' => $sFolder, 'uids' => \array_map('intval', \explode(',', $sUids))];
	}

	protected function toolMoveMessages(array $aArgs) : array
	{
		$this->useAccount($aArgs);
		$sFolder = static::folderArg($aArgs);
		$sDestination = static::folderArg($aArgs, 'destination');
		$sUids = static::uidsArg($aArgs);
		if ($sFolder === $sDestination) {
			throw new ToolException('Source and destination are the same folder');
		}
		$this->action('DoMessageMove', ['fromFolder' => $sFolder, 'toFolder' => $sDestination, 'uids' => $sUids]);
		return ['success' => true, 'moved_to' => $sDestination, 'uids' => \array_map('intval', \explode(',', $sUids))];
	}

	protected function toolDeleteMessages(array $aArgs) : array
	{
		$oAccount = $this->useAccount($aArgs);
		$sFolder = static::folderArg($aArgs);
		$sUids = static::uidsArg($aArgs);
		$sTrash = $this->systemFolder($oAccount, 'TrashFolder');
		if (\strlen($sTrash) && $sTrash !== $sFolder) {
			$this->action('DoMessageMove', ['fromFolder' => $sFolder, 'toFolder' => $sTrash, 'uids' => $sUids]);
			return ['success' => true, 'moved_to' => $sTrash, 'uids' => \array_map('intval', \explode(',', $sUids))];
		}
		if (empty($aArgs['permanent'])) {
			throw new ToolException(\strlen($sTrash)
				? 'The messages are in the trash folder, set "permanent": true to delete them permanently'
				: 'No trash folder is configured, set "permanent": true to delete permanently');
		}
		$this->action('DoMessageDelete', ['folder' => $sFolder, 'uids' => $sUids]);
		return ['success' => true, 'deleted_permanently' => true, 'uids' => \array_map('intval', \explode(',', $sUids))];
	}

	/**
	 * Converts compose arguments into DoSendMessage/DoSaveMessage parameters
	 */
	private function composeParams(Account $oAccount, array $aArgs, bool $bRequireRecipients) : array
	{
		$aRecipients = [];
		$iCount = 0;
		foreach (['to', 'cc', 'bcc'] as $sKey) {
			$aList = $aArgs[$sKey] ?? [];
			if (\is_string($aList)) {
				$aList = [$aList];
			}
			$oCollection = new \MailSo\Mime\EmailCollection(\implode(', ', \array_map('strval', (array) $aList)));
			foreach ($oCollection as $oEmail) {
				if (!\preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $oEmail->GetEmail())) {
					throw new ToolException("Invalid email address in {$sKey}: " . $oEmail->GetEmail());
				}
			}
			$iCount += $oCollection->count();
			$aRecipients[$sKey] = $oCollection->ToString();
		}
		if ($bRequireRecipients && !$iCount) {
			throw new ToolException('At least one recipient is required');
		}
		if ($iCount > $this->iMaxRecipients) {
			throw new ToolException("Too many recipients, the maximum is {$this->iMaxRecipients}");
		}

		// Sender must be one of the identities
		$aIdentities = $this->oActions->GetIdentities($oAccount);
		if (!$aIdentities) {
			throw new ToolException('No sender identity found');
		}
		$oIdentity = $aIdentities[0];
		$sFrom = \trim((string) ($aArgs['from'] ?? ''));
		if (\strlen($sFrom)) {
			$oFrom = \MailSo\Mime\Email::Parse($sFrom);
			$oIdentity = null;
			foreach ($aIdentities as $oItem) {
				if (\strtolower(\SnappyMail\IDN::emailToAscii($oItem->Email())) === \strtolower($oFrom->GetEmail())) {
					$oIdentity = $oItem;
					break;
				}
			}
			if (!$oIdentity) {
				throw new ToolException("{$sFrom} is not an identity of this account, see list_accounts");
			}
		}
		$aIdentity = $oIdentity->jsonSerialize();

		$sHtml = \trim((string) ($aArgs['html'] ?? ''));
		// With HTML the text version is always derived from it, so the confirmation page shows what is sent
		$sPlain = \strlen($sHtml)
			? \MailSo\Base\HtmlUtils::ConvertHtmlToPlain($sHtml)
			: \str_replace("\r\n", "\n", (string) ($aArgs['body'] ?? ''));
		$sSignature = \trim((string) $aIdentity['signature']);
		if (($aArgs['append_signature'] ?? true) && \strlen($sSignature)) {
			$bHtmlSignature = \str_contains($sSignature, '<');
			$sPlain .= "\n\n-- \n" . ($bHtmlSignature ? \MailSo\Base\HtmlUtils::ConvertHtmlToPlain($sSignature) : $sSignature);
			if (\strlen($sHtml)) {
				$sHtml .= '<br><br>-- <br>' . ($bHtmlSignature ? $sSignature : \nl2br(\htmlspecialchars($sSignature)));
			}
		}

		$aParams = [
			'from' => $oIdentity->toMime()->ToString(),
			'to' => $aRecipients['to'],
			'cc' => $aRecipients['cc'],
			'bcc' => $aRecipients['bcc'],
			'replyTo' => (string) $aIdentity['replyTo'],
			'subject' => \trim((string) ($aArgs['subject'] ?? '')),
			'plain' => $sPlain,
			'html' => $sHtml,
			'requireTLS' => 0,
			'markAsImportant' => 0,
			'readReceiptRequest' => 0,
			'dsn' => 0,
			'saveFolder' => (string) $aIdentity['sentFolder'] ?: $this->systemFolder($oAccount, 'SentFolder')
		];

		if (!empty($aArgs['in_reply_to']['folder']) && !empty($aArgs['in_reply_to']['uid'])) {
			$sFolder = (string) $aArgs['in_reply_to']['folder'];
			$iUid = (int) $aArgs['in_reply_to']['uid'];
			$aOriginal = $this->action('DoMessage', ['folder' => $sFolder, 'uid' => $iUid]);
			if (!$aOriginal) {
				throw new ToolException('The message to reply to was not found');
			}
			$sMessageId = (string) ($aOriginal['messageId'] ?? '');
			if (\strlen($sMessageId)) {
				$aParams['inReplyTo'] = $sMessageId;
				$aParams['references'] = \trim(($aOriginal['references'] ?? '') . ' ' . $sMessageId);
			}
			$aParams['draftInfo'] = ['reply', $iUid, $sFolder];
		}

		return $aParams;
	}

	protected function toolSaveDraft(array $aArgs) : array
	{
		$oAccount = $this->useAccount($aArgs);
		$sDrafts = $this->systemFolder($oAccount, 'DraftsFolder');
		if (!\strlen($sDrafts)) {
			throw new ToolException('No drafts folder is configured');
		}
		$aParams = $this->composeParams($oAccount, $aArgs, false);
		$aParams['saveFolder'] = $sDrafts;
		$mResult = $this->action('DoSaveMessage', $aParams);
		return [
			'success' => !!$mResult,
			'folder' => $sDrafts,
			'uid' => \is_array($mResult) ? ($mResult['uid'] ?? null) : null
		];
	}

	/**
	 * Send tools
	 */

	protected function toolSendEmail(array $aArgs) : array
	{
		$oAccount = $this->useAccount($aArgs);
		$aParams = $this->composeParams($oAccount, $aArgs, true);
		if (!\strlen($aParams['subject']) && !\strlen(\trim($aParams['plain']))) {
			throw new ToolException('Subject and body are empty');
		}

		[$sId, $sKey] = $this->oPending->create(
			$this->oMainAccount->Email(),
			$oAccount->Email(),
			$this->aGrant['client_name'] ?? '',
			$aParams
		);
		$aPending = $this->oPending->get($sId);

		return [
			'id' => $sId,
			'status' => Pending::STATUS_PENDING,
			'sent' => false,
			'confirmation_url' => ($this->fConfirmUrl)($sId, $sKey),
			'expires_at' => \gmdate('c', $aPending['expires_at']),
			'from' => $aParams['from'],
			'to' => $aParams['to'],
			'cc' => $aParams['cc'],
			'bcc' => $aParams['bcc'],
			'subject' => $aParams['subject'],
			'note' => 'The email has NOT been sent yet. Ask the user to open the confirmation_url, review the email and confirm sending.'
		];
	}

	private function ownPending(array $aArgs) : array
	{
		$aPending = $this->oPending->get(\trim((string) ($aArgs['id'] ?? '')));
		if (!$aPending || $aPending['email'] !== $this->oMainAccount->Email()) {
			throw new ToolException('Unknown email id');
		}
		return $aPending;
	}

	protected function toolGetEmailStatus(array $aArgs) : array
	{
		return Pending::publicStatus($this->ownPending($aArgs));
	}

	protected function toolCancelEmail(array $aArgs) : array
	{
		$aPending = $this->ownPending($aArgs);
		if (Pending::STATUS_PENDING === $aPending['status'] || Pending::STATUS_FAILED === $aPending['status']) {
			$this->oPending->setStatus($aPending, Pending::STATUS_DISCARDED);
			$aPending = $this->oPending->get($aPending['id']);
		}
		return Pending::publicStatus($aPending);
	}
}
