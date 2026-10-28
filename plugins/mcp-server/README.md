# MCP Server

Gives AI assistants (Claude, ChatGPT, Cursor, MCP Inspector, …) access to the
mailbox of a SnappyMail user through the [Model Context Protocol](https://modelcontextprotocol.io).
SnappyMail itself acts as OAuth 2.1 authorization server, so users connect
an assistant by signing in to SnappyMail and allowing access.

**Emails are never sent by the assistant directly.** The `send_email` tool only
prepares the email and returns a confirmation link. The email is sent when the
user opens that link, signed in to SnappyMail as the same user, reviews the
email and clicks "Send now".

## Setup

1. Enable the plugin in Admin → Extensions.
2. Connect the MCP client to `https://mail.example.com/mcp`
   (or `https://mail.example.com/?mcp` when the web server does not pass
   unknown paths to `index.php`).

The client discovers everything else (RFC 9728 / RFC 8414) and registers
itself (RFC 7591).

OAuth discovery uses `/.well-known/oauth-protected-resource` and
`/.well-known/oauth-authorization-server`. The shipped Apache `.htaccess`
already passes those paths to `index.php`. For nginx route them to SnappyMail:

```nginx
location ^~ /.well-known/oauth- {
	try_files $uri /index.php$is_args$args;
}
location = /mcp {
	try_files $uri /index.php$is_args$args;
}
location ^~ /mcp/ {
	try_files $uri /index.php$is_args$args;
}
```

When SnappyMail runs in a sub directory, clients find the metadata through
`https://example.com/webmail/.well-known/openid-configuration`.

Behind a reverse proxy set "Public URL" in the plugin settings when the
detected URL is wrong.

## Tools

| Tool | Scope | Description |
|------|-------|-------------|
| `list_accounts` | `mail.read` | Main and additional accounts, sender identities |
| `list_folders` | `mail.read` | Folders with role and counts |
| `list_messages` | `mail.read` | List and search (from, to, subject, text, date, unread, flagged, attachment) |
| `get_message` | `mail.read` | Headers, text body, attachment list |
| `get_attachment` | `mail.read` | Attachment content (text, image or base64) |
| `search_contacts` | `mail.read` | Search the address book |
| `mark_messages` | `mail.write` | Read/unread, flagged/unflagged |
| `move_messages` | `mail.write` | Move to another folder |
| `delete_messages` | `mail.write` | Move to trash, permanently only with `permanent: true` from the trash |
| `save_draft` | `mail.write` | Save a draft to review in the webmail |
| `send_email` | `mail.send` | Prepare an email, returns the confirmation link |
| `get_email_status` | `mail.send` | Pending, sent, discarded or expired |
| `cancel_email` | `mail.send` | Discard a prepared email |

All mail tools take an optional `account` argument for additional accounts.
The user chooses the scopes on the consent page; the admin can disable
writing and sending entirely.

## Pages

- `/mcp/authorize`: sign in and consent
- `/mcp/confirm`: review and send (or discard) a prepared email
- `/mcp/connections`: list and revoke connected apps

## Security

- Authorization code flow with PKCE (S256) only, single use codes,
  refresh token rotation with replay detection (a reused refresh token
  revokes the whole authorization).
- Tokens and codes are stored as SHA-256 hashes only. The mail credentials of
  an authorization are encrypted with a random key that is only stored
  encrypted by the issued tokens, so data on disk is useless without a token.
- The prepared email is encrypted with a key that only exists in the
  confirmation link. Confirming requires the browser session of the same
  user plus a CSRF token, so the assistant can not confirm by itself.
  With an HTML body, the text version shown for confirmation is generated
  from the HTML.
- Senders are limited to the identities of the account, recipients to
  "Maximum recipients per email".
- Sign in on the consent page runs the normal SnappyMail login, including
  the two-factor-auth plugin and auth failure logging (fail2ban).

Data is stored in `data/_data_/_default_/mcp-server/`.

Requires the PHP sodium extension.
