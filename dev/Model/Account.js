import { AbstractModel } from 'Knoin/AbstractModel';
import { addObservablesTo, addComputablesTo } from 'External/ko';
import Remote from 'Remote/User/Fetch';
import { SettingsUserStore } from 'Stores/User/Settings';
import { AccountUserStore } from 'Stores/User/Account';
import { UnifiedUserStore } from 'Stores/User/Unified';
import { ClientSideKeyNameExpandedAccounts } from 'Common/EnumsUser';
import { i18n } from 'Common/Translator';
import { isArray } from 'Common/Utils';
import * as Local from 'Storage/Client';

const
	// Same order as the system folders of the active account
	roleOrder = ['inbox', 'drafts', 'sent', 'archive', 'junk', 'trash'],
	roleNames = {
		inbox: 'FOLDER_LIST/INBOX_NAME',
		drafts: 'FOLDER_LIST/DRAFTS_NAME',
		sent: 'FOLDER_LIST/SENT_NAME',
		archive: 'FOLDER_LIST/ARCHIVE_NAME',
		junk: 'GLOBAL/SPAM',
		trash: 'FOLDER_LIST/TRASH_NAME'
	},
	// Glyphs of the snappymail font, same as FolderModel.icon()
	roleIcons = {
		inbox: '📥',
		sent: '📧',
		drafts: '🗎',
		junk: '⚠',
		trash: '🗑',
		archive: '🗄'
	},

	// Each account gets its own color, white text on them has at least 4.5:1 contrast
	accountColors = ['#2F6FB0', '#B4590F', '#6E4FA0', '#2E7D5B', '#A23B5C', '#3B6E8F', '#8A6D1F'],

	rolePos = role => {
		const i = roleOrder.indexOf(role);
		return 0 > i ? roleOrder.length : i;
	},

	expandedAccounts = () => {
		const value = Local.get(ClientSideKeyNameExpandedAccounts);
		return value && 'object' === typeof value ? value : {};
	};

export class AccountModel extends AbstractModel {
	/**
	 * @param {string} email
	 * @param {boolean=} canBeDelete = true
	 * @param {number=} count = 0
	 */
	constructor(email, name, isAdditional = true) {
		super();

		this.name = name;
		this.email = email;

		this.displayName = name ? name + ' <' + email + '>' : email;

		const stored = expandedAccounts()[email];

		addObservablesTo(this, {
			unreadEmails: null,
			askDelete: false,
			isAdditional: isAdditional,
			expanded: null != stored ? !!stored : AccountUserStore.email() === email,
			folders: null,
			foldersLoading: false,
			foldersError: false
		});

		addComputablesTo(this, {
			isActive: () => AccountUserStore.email() === this.email,
			color: () => accountColors[Math.max(0, AccountUserStore().indexOf(this)) % accountColors.length],
			initial: () => this.label().trim().charAt(0).toUpperCase(),
			// Second line under the name, when the name is not the email address
			emailLine: () => {
				const email = IDN.toUnicode(this.email);
				return email !== this.label() ? email : '';
			},
			// Only show the unread count when the folders are hidden
			collapsedUnread: () => (!this.expanded()
				&& (this.isActive() ? UnifiedUserStore.activeInboxUnread() : this.unreadEmails())) || null,
			ariaLabel: () => this.collapsedUnread()
				? this.label() + ', ' + i18n('FOLDER_LIST/UNREAD_COUNT', {COUNT: this.collapsedUnread()})
				: this.label()
		});

		this.expanded() && !this.isActive() && setTimeout(() => this.fetchFolders(), 1000);

		// The account switch keeps the page, so show the current folders of the account we leave
		this.addSubscribables({
			isActive: active => active || !this.expanded() || this.fetchFolders()
		});

		// Load at random between 3 and 30 seconds
		SettingsUserStore.showUnreadCount() && AccountUserStore.email() !== email
		&& setTimeout(()=>this.fetchUnread(), (Math.ceil(Math.random() * 10)) * 3000);
	}

	label() {
		return this.name || IDN.toUnicode(this.email);
	}

	/**
	 * @param {boolean} value
	 */
	setExpanded(value) {
		const list = expandedAccounts();
		list[this.email] = !!value;
		Local.set(ClientSideKeyNameExpandedAccounts, list);
		this.expanded(!!value);
		value && !this.isActive() && null == this.folders() && this.fetchFolders();
	}

	/**
	 * Get INBOX unread messages
	 */
	fetchUnread() {
		Remote.request('AccountUnread', (iError, oData) => {
			iError || this.unreadEmails(oData?.Result?.unreadEmails || null);
		}, {
			email: this.email
		});
	}

	/**
	 * Get the folders of a not active account
	 */
	fetchFolders() {
		this.foldersLoading(true);
		this.foldersError(false);
		Remote.request('AccountFolders', (iError, oData) => {
			this.foldersLoading(false);
			const items = oData?.Result;
			if (iError || !isArray(items)) {
				this.foldersError(true);
				return;
			}
			const depth = folder => {
				const parts = folder.delimiter ? folder.fullName.split(folder.delimiter) : [folder.fullName];
				// Subfolders of the INBOX are shown on the same level as INBOX itself
				return Math.max(0, parts.length - 1 - ('INBOX' === parts[0].toUpperCase() && 1 < parts.length ? 1 : 0));
			};
			this.folders(items.map(folder => ({
				name: roleNames[folder.role] && !depth(folder) ? i18n(roleNames[folder.role]) : folder.name,
				fullName: folder.fullName,
				role: folder.role,
				icon: depth(folder) ? null : roleIcons[folder.role] || null,
				selectable: folder.selectable,
				unreadEmails: folder.unreadEmails || null,
				depth: depth(folder)
			})).sort((a, b) => {
				const ra = a.depth ? roleOrder.length : rolePos(a.role),
					rb = b.depth ? roleOrder.length : rolePos(b.role);
				return ra - rb || a.fullName.localeCompare(b.fullName);
			}));
			const inbox = items.find(folder => 'inbox' === folder.role);
			inbox && this.unreadEmails(inbox.unreadEmails || null);
		}, {
			email: this.email
		});
	}

	/**
	 * Imports all mail to main account
	 *//*
	importAll(account) {
		Remote.streamPerLine(line => {
			try {
				line = JSON.parse(line);
				console.dir(line);
			} catch (e) {
				// OOPS
			}
		}, 'AccountImport', {
			Action: 'AccountImport',
			email: account.email
		});
	}
	*/

}
