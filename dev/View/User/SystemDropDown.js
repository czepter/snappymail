import { AppUserStore } from 'Stores/User/App';
import { AccountUserStore } from 'Stores/User/Account';
//import { FolderUserStore } from 'Stores/User/Folder';

import { ScopeMessageList, ScopeMessageView, ScopeSettings } from 'Common/Enums';
import { settings } from 'Common/Links';

import { showScreenPopup } from 'Knoin/Knoin';
import { AbstractViewRight } from 'Knoin/AbstractViews';

import { KeyboardShortcutsHelpPopupView } from 'View/Popup/KeyboardShortcutsHelp';
import { AccountPopupView } from 'View/Popup/Account';
import { ContactsPopupView } from 'View/Popup/Contacts';

import { fireEvent, stopEvent, SettingsCapa, registerShortcut } from 'Common/Globals';

import { switchAccount } from 'Common/AccountSwitch';
//import { koComputable } from 'External/ko';
import { addObservablesTo } from 'External/ko';

export class SystemDropDownUserView extends AbstractViewRight {
	constructor() {
		super();

		this.allowAccounts = SettingsCapa('AdditionalAccounts');

		this.accountEmail = AccountUserStore.email;

		this.accounts = AccountUserStore;
		this.accountsLoading = AccountUserStore.loading;
/*
		this.accountsUnreadCount = : koComputable(() => 0);
		this.accountsUnreadCount = : koComputable(() => AccountUserStore().reduce((result, item) => result + item.count(), 0));
*/

		addObservablesTo(this, {
			currentAudio: '',
			// bootstrap dropdown
			accountMenu: null
		});

		this.allowContacts = AppUserStore.allowContacts();

		// knockout calls it with the account as this
		this.accountClick = this.accountClick.bind(this);

		addEventListener('audio.stop', () => this.currentAudio(''));
		addEventListener('audio.start', e => this.currentAudio(e.detail));
	}

	stopPlay() {
		fireEvent('audio.api.stop');
	}

	accountClick(account, event) {
		let email = account?.email;
		if (email && 0 === event.button && AccountUserStore.email() != email) {
			stopEvent(event);
			this.accountMenu()?.ddBtn.hide();
			switchAccount(email, {
				onError: () => account.isAdditional() && showScreenPopup(AccountPopupView, [account])
			});
		}
		return true;
	}

	accountName() {
		const email = AccountUserStore.email();
		return AccountUserStore.find(account => account.email == email)?.label() || IDN.toUnicode(email);
	}

	settingsClick() {
		hasher.setHash(settings());
	}

	settingsHelp() {
		showScreenPopup(KeyboardShortcutsHelpPopupView);
	}

	addAccountClick() {
		this.allowAccounts && showScreenPopup(AccountPopupView);
	}

	contactsClick() {
		this.allowContacts && showScreenPopup(ContactsPopupView);
	}

	logoutClick() {
		rl.app.logout();
	}

	onBuild() {
		registerShortcut('m', '', [ScopeMessageList, ScopeMessageView, ScopeSettings], () => {
			if (!this.viewModelDom.hidden) {
//				exitFullscreen();
				this.accountMenu().ddBtn.toggle();
				return false;
			}
		});

		// shortcuts help
		registerShortcut('?,f1,help', '', [ScopeMessageList, ScopeMessageView, ScopeSettings], () => {
			if (!this.viewModelDom.hidden) {
				showScreenPopup(KeyboardShortcutsHelpPopupView);
				return false;
			}
		});
	}
}
