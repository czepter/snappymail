<?php

use RainLoop\Plugins\Property;
use RainLoop\Enumerations\PluginPropertyType;

class McpServerPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'MCP Server',
		AUTHOR   = 'SnappyMail',
		URL      = 'https://snappymail.eu/',
		VERSION  = '1.0',
		RELEASE  = '2026-10-28',
		REQUIRED = '2.36.1',
		CATEGORY = 'General',
		LICENSE  = 'MIT',
		DESCRIPTION = 'Model Context Protocol server with OAuth 2.1, gives AI assistants access to the mailbox. Emails are only sent after confirmation by the user.';

	public function Init() : void
	{
		\spl_autoload_register(function (string $sClassName) {
			if (\str_starts_with($sClassName, 'McpServer\\')) {
				$sFile = __DIR__ . '/' . \strtr($sClassName, '\\', '/') . '.php';
				\is_file($sFile) && include_once $sFile;
			}
		});
		// Runs before the Sec-Fetch check, as OAuth and MCP requests come from other sites and apps
		$this->addHook('filter.http-paths', 'filterHttpPaths');
	}

	public function Supported() : string
	{
		return \function_exists('sodium_crypto_secretbox') ? '' : 'The PHP sodium extension is required';
	}

	public function filterHttpPaths(array &$aPaths) : void
	{
		$aRoute = \McpServer\Server::route();
		if ($aRoute) {
			(new \McpServer\Server($this, $this->Manager()->Actions()))->handle(...$aRoute);
			exit;
		}
	}

	protected function configMapping() : array
	{
		return [
			Property::NewInstance('public_url')
				->SetLabel('Public URL')
				->SetType(PluginPropertyType::URL)
				->SetDescription('Base URL of SnappyMail as seen by MCP clients, like https://mail.example.com/. Leave empty to detect it.'),
			Property::NewInstance('allow_registration')
				->SetLabel('Allow dynamic client registration')
				->SetType(PluginPropertyType::BOOL)
				->SetDefaultValue(true)
				->SetDescription('MCP clients like Claude or ChatGPT register themselves (RFC 7591)'),
			Property::NewInstance('allow_write')
				->SetLabel('Allow changing messages')
				->SetType(PluginPropertyType::BOOL)
				->SetDefaultValue(true)
				->SetDescription('Tools to flag, move and delete messages and to save drafts'),
			Property::NewInstance('allow_send')
				->SetLabel('Allow sending emails')
				->SetType(PluginPropertyType::BOOL)
				->SetDefaultValue(true)
				->SetDescription('Emails are always sent only after the user confirmed them in the browser'),
			Property::NewInstance('confirmation_ttl')
				->SetLabel('Confirmation link lifetime (hours)')
				->SetType(PluginPropertyType::INT)
				->SetDefaultValue(24),
			Property::NewInstance('max_recipients')
				->SetLabel('Maximum recipients per email')
				->SetType(PluginPropertyType::INT)
				->SetDefaultValue(20),
			Property::NewInstance('max_attachment_size')
				->SetLabel('Maximum attachment download size (MB)')
				->SetType(PluginPropertyType::INT)
				->SetDefaultValue(5),
			Property::NewInstance('access_token_ttl')
				->SetLabel('Access token lifetime (minutes)')
				->SetType(PluginPropertyType::INT)
				->SetDefaultValue(60),
			Property::NewInstance('refresh_token_ttl')
				->SetLabel('Authorization lifetime without use (days)')
				->SetType(PluginPropertyType::INT)
				->SetDefaultValue(30)
		];
	}
}
