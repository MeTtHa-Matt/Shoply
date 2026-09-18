(function () {
    'use strict';
    const csrf = document.querySelector('input[name="csrf_token"]');
    const list = document.querySelector('[data-list-id]');
    const noteForm = document.querySelector('.add-item-form');
    let noteEditor = null;
    let noteSavedValue = '';
    let noteBaseValue = '';
    let noteFocused = false;
    let pendingNoteValue = null;
    let noteAwaitingReturn = false;
    const listIndex = document.querySelector('[data-list-index]');
    const bottomNav = document.querySelector('.bottom-nav');

    const addItemForm = document.querySelector('.add-item-form');
    const voiceRecognitionApi = window.webkitSpeechRecognition || window.SpeechRecognition;
    let voiceButton = null;
    let voiceFeedback = null;
    let voiceTranscript = null;
    let voiceResponse = null;
    let voiceState = null;
    let pendingVoiceCommand = null;
    if (csrf && (addItemForm || listIndex)) {
        voiceButton = document.createElement('button');
        voiceButton.className = 'voice-button';
        voiceButton.type = 'button';
        voiceButton.textContent = '🎙';
        voiceButton.setAttribute('aria-label', 'Ajouter un article avec la voix');
        voiceButton.title = 'Ajouter avec la voix';
        voiceFeedback = document.createElement('div');
        voiceFeedback.className = 'voice-feedback voice-dialog';
        voiceFeedback.hidden = true;
        voiceFeedback.setAttribute('aria-live', 'polite');
        voiceFeedback.setAttribute('role', 'dialog');
        voiceFeedback.setAttribute('aria-modal', 'true');
        voiceFeedback.innerHTML = '<div class="voice-dialog-backdrop"></div><section class="voice-dialog-card" aria-labelledby="voice-dialog-title"><button class="voice-dialog-close" type="button" aria-label="Fermer">×</button><div class="voice-orb" aria-hidden="true">🎙</div><h2 id="voice-dialog-title">Je vous écoute</h2><p class="voice-dialog-state">Parlez naturellement...</p><p class="voice-dialog-line"><strong>Vous avez dit :</strong><span class="voice-transcript"></span></p><p class="voice-dialog-line"><strong>Réponse :</strong><span class="voice-response"></span></p></section></div>';
        voiceTranscript = voiceFeedback.querySelector('.voice-transcript');
        voiceResponse = voiceFeedback.querySelector('.voice-response');
        voiceState = voiceFeedback.querySelector('.voice-dialog-state');
        voiceFeedback.querySelector('.voice-dialog-close').addEventListener('click', function () { voiceFeedback.classList.remove('is-open'); voiceFeedback.hidden = true; });
        if (addItemForm) {
            const submitButton = addItemForm.querySelector('button[type="submit"]');
            addItemForm.insertBefore(voiceButton, submitButton);
        } else {
            voiceButton.classList.add('list-index-voice-button');
            const listIndexHeading = listIndex.querySelector('.list-index-head');
            if (listIndexHeading) listIndexHeading.appendChild(voiceButton);
        }
        document.body.appendChild(voiceFeedback);
    }

    window.requestAnimationFrame(function () {
        document.body.classList.add('is-ready');
    });

    document.querySelectorAll('a[href]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target === '_blank' || link.hasAttribute('download')) return;
            var destination;
            try { destination = new URL(link.href, window.location.href); } catch (error) { return; }
            if (destination.origin !== window.location.origin || destination.href === window.location.href) return;
            event.preventDefault();
            document.body.classList.add('is-exiting');
            window.setTimeout(function () { window.location.assign(destination.href); }, 180);
        });
    });

    document.querySelectorAll('.auth-body form[method="post"], form[action*="page=logout"]').forEach(function (form) {
        form.addEventListener('submit', function () {
            document.body.classList.add(form.action.indexOf('page=logout') !== -1 ? 'is-logging-out' : 'is-submitting');
            var button = form.querySelector('button[type="submit"]');
            if (button) {
                button.setAttribute('aria-busy', 'true');
                button.disabled = true;
            }
        });
    });

    if (bottomNav && window.matchMedia('(max-width: 760px)').matches) {
        const navTop = Math.max(12, window.innerHeight - bottomNav.offsetHeight - 24);
        document.documentElement.style.setProperty('--fixed-nav-top', navTop + 'px');
    }

    if (listIndex) {
        const meta = document.querySelector('[data-shared-list-ids]');
        const sharedIds = meta && meta.dataset.sharedListIds ? meta.dataset.sharedListIds.split(',').filter(Boolean) : [];
        const rows = listIndex.querySelector('.list-index-rows');
        const sharedRows = [];
        if (rows && sharedIds.length) {
            Array.from(rows.querySelectorAll('.list-index-row')).forEach(function (row) {
                const match = row.href.match(/[?&]list_id=(\d+)/);
                if (match && sharedIds.indexOf(match[1]) !== -1) sharedRows.push(row);
            });
            if (sharedRows.length) {
                const heading = document.createElement('div');
                heading.className = 'list-index-subheading';
                heading.textContent = 'Mes listes partagées';
                const sharedRowsContainer = document.createElement('div');
                sharedRowsContainer.className = 'list-index-rows list-index-shared-rows';
                sharedRows.forEach(function (row) { sharedRowsContainer.appendChild(row); });
                rows.after(heading, sharedRowsContainer);
            }
        }
    }

    const sharedPanel = document.querySelector('.shared-panel');
    const notificationsIcon = document.querySelector('.notifications-panel .focus-icon');
    if (notificationsIcon) {
        notificationsIcon.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>';
    }
    if (sharedPanel) {
        const addPeopleLink = document.createElement('a');
        addPeopleLink.className = 'shared-add-button button button-primary';
        addPeopleLink.href = 'index.php?page=home&view=profile';
        addPeopleLink.textContent = 'Ajouter des proches';
        const sharedLists = sharedPanel.querySelector('.shared-list-grid');
        if (sharedLists) {
            sharedLists.after(addPeopleLink);
        } else {
            sharedPanel.appendChild(addPeopleLink);
        }
    }

    if (sharedPanel && csrf && document.querySelector('.dashboard-shared')) {
        const shareInvite = sharedPanel.querySelector('.share-invite');
        if (shareInvite) shareInvite.remove();
        const manageButton = document.createElement('button');
        manageButton.type = 'button';
        manageButton.className = 'button button-quiet shared-manage-trigger';
        manageButton.innerHTML = '<span aria-hidden="true">↗</span><span>Gérer mes partages</span>';
        manageButton.setAttribute('aria-label', 'Gérer mes partages');
        const liveSharedGrid = document.createElement('div');
        liveSharedGrid.className = 'live-shared-grid';
        sharedPanel.appendChild(liveSharedGrid);
        sharedPanel.appendChild(manageButton);
        sharedPanel.insertBefore(manageButton, liveSharedGrid);

        const drawer = document.createElement('aside');
        drawer.className = 'share-drawer';
        drawer.setAttribute('aria-hidden', 'true');
        drawer.innerHTML = '<div class="share-drawer-backdrop" data-share-close></div><section class="share-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="share-drawer-title"><header><button class="share-drawer-back" type="button" aria-label="Retour" hidden>←</button><div><span class="eyebrow">À PLUSIEURS</span><h2 id="share-drawer-title">Gérer mes partages</h2></div><button class="share-drawer-close" type="button" aria-label="Fermer" data-share-close>×</button></header><div class="share-drawer-body"><div class="share-drawer-list-view"><p class="share-drawer-intro">Choisissez une liste pour voir les personnes qui y ont accès.</p><div class="share-owned-lists"></div></div><div class="share-drawer-detail" hidden></div></div></section>';
        document.body.appendChild(drawer);
        const listView = drawer.querySelector('.share-drawer-list-view');
        const detailView = drawer.querySelector('.share-drawer-detail');
        const ownedLists = drawer.querySelector('.share-owned-lists');
        const backButton = drawer.querySelector('.share-drawer-back');
        let sharedData = [];
        let sharedFriends = [];
        let sharedGroups = [];

        function closeShareDrawer() {
            drawer.classList.remove('is-open');
            drawer.setAttribute('aria-hidden', 'true');
        }
        function loadSharedLists() {
            return requestJson('index.php?page=home&api=shared_lists').then(function (data) {
                sharedData = data.lists || [];
                sharedFriends = data.friends || [];
                sharedGroups = data.groups || [];
                var activeShares = sharedData.filter(function (listData) { return listData.members.length > 0; });
                liveSharedGrid.innerHTML = activeShares.length ? '<div class="shared-divider"><span>LISTES PARTAGÉES</span></div><div class="shared-list-grid">' + activeShares.map(function (listData) { return '<a class="shared-list-card" href="index.php?page=home&view=shop&list_id=' + Number(listData.id) + '"><span class="list-dot"></span><strong>' + escapeHtml(listData.name) + '</strong><small>' + listData.members.length + ' personne' + (listData.members.length === 1 ? '' : 's') + ' · partagée</small><span class="card-arrow">→</span></a>'; }).join('') + '</div>' : '';
                ownedLists.innerHTML = sharedData.length ? sharedData.map(function (listData) {
                    return '<button class="share-owned-list" type="button" data-share-list-id="' + Number(listData.id) + '"><span class="list-row-mark">↗</span><span><strong>' + escapeHtml(listData.name) + '</strong><small>' + listData.members.length + ' personne' + (listData.members.length === 1 ? '' : 's') + ' · voir les accès</small></span><b>→</b></button>';
                }).join('') : '<div class="share-drawer-empty"><span>↗</span><p>Aucune liste à gérer.</p><small>Partagez une de vos listes pour la retrouver ici.</small></div>';
            });
        }
        function openShareDetail(listData) {
            listView.hidden = true;
            detailView.hidden = false;
            backButton.hidden = false;
            detailView.innerHTML = '<div class="share-detail-heading"><span class="focus-icon">↗</span><strong>' + escapeHtml(listData.name) + '</strong><small>Personnes autorisées à consulter et modifier cette liste.</small></div><div class="share-members">' + (listData.members.length ? listData.members.map(function (member) { return '<div class="share-member"><span class="user-avatar">' + escapeHtml(member.name.charAt(0).toUpperCase()) + '</span><div><strong>' + escapeHtml(member.name) + '</strong><small>' + escapeHtml(member.email) + '</small></div><button class="button button-quiet revoke-share" type="button" data-member-id="' + Number(member.user_id) + '">Arrêter</button></div>'; }).join('') : '<div class="share-drawer-empty"><span>◎</span><p>Personne n’a accès à cette liste.</p></div>') + '</div>' + (listData.members.length ? '<button class="button button-danger revoke-all-share" type="button">Arrêter tous les partages</button>' : '');
            detailView.insertAdjacentHTML('afterbegin', '<div class="direct-share"><strong>Partager directement</strong><small>Recherchez uniquement parmi vos proches.</small><label class="sr-only" for="direct-share-search">Rechercher un proche</label><input id="direct-share-search" class="direct-share-search" type="search" placeholder="Nom ou email..."><div class="direct-share-results"></div></div>');
            const directSearch = detailView.querySelector('.direct-share-search');
            const directResults = detailView.querySelector('.direct-share-results');
            detailView.querySelector('.direct-share > small').textContent = 'Recherchez un proche ou un groupe.';
            directSearch.placeholder = 'Nom, email ou groupe...';
            const currentMemberIds = listData.members.map(function (member) { return Number(member.user_id); });
            function renderDirectFriends(term) {
                const normalizedTerm = term.trim().toLowerCase();
                const memberIds = listData.members.map(function (member) { return String(member.user_id); });
                const matches = sharedFriends.filter(function (friend) { return memberIds.indexOf(String(friend.id)) === -1 && (!normalizedTerm || (friend.name + ' ' + friend.email).toLowerCase().indexOf(normalizedTerm) !== -1); }).filter(function (friend) { return !sharedGroups.some(function (group) { return group.member_ids.length && group.member_ids.every(function (memberId) { return currentMemberIds.indexOf(Number(memberId)) !== -1; }) && group.member_ids.indexOf(Number(friend.id)) !== -1; }); });
                const groupMatches = sharedGroups.filter(function (group) { return group.member_ids.length && !group.member_ids.every(function (memberId) { return currentMemberIds.indexOf(Number(memberId)) !== -1; }) && (!normalizedTerm || group.name.toLowerCase().indexOf(normalizedTerm) !== -1); });
                const groupMarkup = groupMatches.map(function (group) { return '<button class="group-share-result" type="button" data-share-group-id="' + Number(group.id) + '"><span class="list-row-mark">◎</span><span><strong>' + escapeHtml(group.name) + '</strong><small>' + group.member_ids.length + ' proche' + (group.member_ids.length === 1 ? '' : 's') + '</small></span><b>+</b></button>'; }).join('');
                const friendMarkup = matches.map(function (friend) { return '<button class="direct-share-result" type="button" data-share-user-id="' + Number(friend.id) + '"><span class="user-avatar">' + escapeHtml(friend.name.charAt(0).toUpperCase()) + '</span><span><strong>' + escapeHtml(friend.name) + '</strong><small>' + escapeHtml(friend.email) + '</small></span><b>+</b></button>'; }).join('');
                directResults.innerHTML = groupMarkup + friendMarkup || '<small class="direct-share-empty">Aucun proche ou groupe disponible.</small>';
            }
            renderDirectFriends('');
            directSearch.addEventListener('input', function () { renderDirectFriends(directSearch.value); });
            directResults.addEventListener('click', function (event) { const friendButton = event.target.closest('[data-share-user-id]'); if (!friendButton) return; friendButton.disabled = true; requestJson('index.php?page=home', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'}, body:new URLSearchParams({csrf_token:csrf.value, action:'share_list', list_id:listData.id, invite_user_id:friendButton.dataset.shareUserId}) }).then(function (data) { if (!data.ok) throw new Error('share'); showToast('Liste partagée.'); return loadSharedLists(); }).then(function () { const refreshedList = sharedData.find(function (item) { return Number(item.id) === Number(listData.id); }); if (refreshedList) openShareDetail(refreshedList); }).catch(function () { friendButton.disabled = false; showToast('Impossible de partager cette liste.'); }); });
            directResults.addEventListener('click', function (event) { const groupButton = event.target.closest('[data-share-group-id]'); if (!groupButton) return; groupButton.disabled = true; requestJson('index.php?page=home', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'}, body:new URLSearchParams({csrf_token:csrf.value, action:'share_group', list_id:listData.id, group_id:groupButton.dataset.shareGroupId}) }).then(function (data) { if (!data.ok) throw new Error('group-share'); showToast('Groupe partagé.'); return loadSharedLists(); }).then(function () { const refreshedList = sharedData.find(function (item) { return Number(item.id) === Number(listData.id); }); if (refreshedList) openShareDetail(refreshedList); }).catch(function () { groupButton.disabled = false; showToast('Impossible de partager ce groupe.'); }); });
            detailView.insertAdjacentHTML('afterbegin', '<div class="group-share"><strong>Partager avec un groupe</strong><small>Un groupe partage tous ses proches disponibles.</small><div class="group-share-results"></div></div>');
            const groupResults = detailView.querySelector('.group-share-results');
            const availableGroups = sharedGroups.filter(function (group) { return group.member_ids.length && !group.member_ids.every(function (memberId) { return currentMemberIds.indexOf(Number(memberId)) !== -1; }); });
            groupResults.innerHTML = availableGroups.length ? availableGroups.map(function (group) { return '<button class="group-share-result" type="button" data-share-group-id="' + Number(group.id) + '"><span class="list-row-mark">◎</span><span><strong>' + escapeHtml(group.name) + '</strong><small>' + group.member_ids.length + ' proche' + (group.member_ids.length === 1 ? '' : 's') + '</small></span><b>+</b></button>'; }).join('') : '<small class="direct-share-empty">Tous vos groupes sont déjà partagés.</small>';
            groupResults.addEventListener('click', function (event) { const groupButton = event.target.closest('[data-share-group-id]'); if (!groupButton) return; groupButton.disabled = true; requestJson('index.php?page=home', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'}, body:new URLSearchParams({csrf_token:csrf.value, action:'share_group', list_id:listData.id, group_id:groupButton.dataset.shareGroupId}) }).then(function (data) { if (!data.ok) throw new Error('group-share'); showToast('Groupe partagé.'); return loadSharedLists(); }).then(function () { const refreshedList = sharedData.find(function (item) { return Number(item.id) === Number(listData.id); }); if (refreshedList) openShareDetail(refreshedList); }).catch(function () { groupButton.disabled = false; showToast('Impossible de partager ce groupe.'); }); });
            detailView.querySelectorAll('.revoke-share').forEach(function (button) { button.addEventListener('click', function () { revokeShare(listData.id, Number(button.dataset.memberId), button); }); });
            const revokeAll = detailView.querySelector('.revoke-all-share');
            if (revokeAll) revokeAll.addEventListener('click', function () { revokeShare(listData.id, 0, revokeAll); });
        }
        function revokeShare(listId, memberId, button) {
            button.disabled = true;
            requestJson('index.php?page=home', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'}, body:new URLSearchParams({csrf_token:csrf.value, action:'revoke_share', list_id:listId, member_id:memberId}) }).then(function (data) {
                if (!data.ok) throw new Error('revoke');
                showToast(memberId ? 'Partage arrêté.' : 'Tous les partages sont arrêtés.');
                return loadSharedLists();
            }).then(function () { const listData = sharedData.find(function (item) { return Number(item.id) === Number(listId); }); if (listData) openShareDetail(listData); }).catch(function () { button.disabled = false; showToast('Impossible d’arrêter ce partage.'); });
        }
        manageButton.addEventListener('click', function () { drawer.classList.add('is-open'); drawer.setAttribute('aria-hidden', 'false'); backButton.hidden = true; listView.hidden = false; detailView.hidden = true; loadSharedLists().catch(function () { ownedLists.innerHTML = '<div class="share-drawer-empty"><p>Impossible de charger vos partages.</p></div>'; }); });
        drawer.querySelectorAll('[data-share-close]').forEach(function (control) { control.addEventListener('click', closeShareDrawer); });
        backButton.addEventListener('click', function () { backButton.hidden = true; listView.hidden = false; detailView.hidden = true; });
        ownedLists.addEventListener('click', function (event) { const button = event.target.closest('[data-share-list-id]'); if (!button) return; const listData = sharedData.find(function (item) { return Number(item.id) === Number(button.dataset.shareListId); }); if (listData) openShareDetail(listData); });
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && drawer.classList.contains('is-open')) closeShareDrawer(); });
        const shareForm = document.querySelector('.share-invite form');
        if (shareForm) shareForm.addEventListener('submit', function (event) { event.preventDefault(); const button = shareForm.querySelector('button[type="submit"]'); button.disabled = true; requestJson(shareForm.action, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'}, body:new URLSearchParams(new FormData(shareForm)) }).then(function (data) { if (!data.ok) throw new Error('share'); showToast('Liste partagée.'); shareForm.reset(); loadSharedLists(); }).catch(function () { showToast('Impossible de partager cette liste.'); }).finally(function () { button.disabled = false; }); });
        loadSharedLists().catch(function () {});
    }

    const profileFriends = document.querySelector('.profile-friends');
    if (profileFriends && csrf && document.querySelector('.dashboard-profile')) {
        function renderFriendGroups(data) {
            var groups = data.groups || [];
            var friends = data.friends || [];
            var groupSections = groups.map(function (group) {
                var members = friends.filter(function (friend) { return Number(friend.group_id) === Number(group.id); });
                return '<section class="friend-group"><header><strong>' + escapeHtml(group.name) + '</strong><small>' + members.length + ' proche' + (members.length === 1 ? '' : 's') + '</small></header>' + (members.length ? members.map(renderFriendRow).join('') : '<p class="friend-group-empty">Aucun proche dans ce groupe.</p>') + '</section>';
            }).join('');
            var ungrouped = friends.filter(function (friend) { return !friend.group_id; });
            profileFriends.innerHTML = '<div class="friend-groups-head"><div><strong>Mes proches</strong><small>Créez des groupes pour les retrouver plus facilement.</small></div><form class="friend-group-create"><input name="group_name" type="text" maxlength="80" placeholder="Nouveau groupe" aria-label="Nom du nouveau groupe" required><button class="icon-button" type="submit" aria-label="Créer le groupe">+</button></form></div><div class="friend-groups-list">' + groupSections + (ungrouped.length ? '<section class="friend-group friend-group-ungrouped"><header><strong>Sans groupe</strong><small>' + ungrouped.length + ' proche' + (ungrouped.length === 1 ? '' : 's') + '</small></header>' + ungrouped.map(renderFriendRow).join('') + '</section>' : '') + (!groups.length && !friends.length ? '<div class="share-drawer-empty"><span>◎</span><p>Vous n’avez pas encore de proche.</p></div>' : '') + '</div>';
            profileFriends.querySelector('.friend-group-create').addEventListener('submit', function (event) {
                event.preventDefault();
                requestJson('index.php?page=home', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'}, body:new URLSearchParams({csrf_token:csrf.value, action:'create_friend_group', group_name:event.target.group_name.value}) }).then(function (response) { if (!response.ok) throw new Error('group'); return loadFriendGroups(); }).catch(function () { showToast('Impossible de créer ce groupe.'); });
            });
            profileFriends.querySelectorAll('.friend-group-select').forEach(function (select) {
                select.addEventListener('change', function () { requestJson('index.php?page=home', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'}, body:new URLSearchParams({csrf_token:csrf.value, action:'assign_friend_group', friend_id:select.dataset.friendId, group_id:select.value}) }).then(function (response) { if (!response.ok) throw new Error('assign'); return loadFriendGroups(); }).catch(function () { showToast('Impossible de classer ce proche.'); }); });
            });
        }
        function renderFriendRow(friend) {
            return '<div class="friend-group-row"><span class="user-avatar">' + escapeHtml(friend.name.charAt(0).toUpperCase()) + '</span><div><strong>' + escapeHtml(friend.name) + '</strong><small>' + escapeHtml(friend.email) + '</small></div><select class="friend-group-select" data-friend-id="' + Number(friend.id) + '" aria-label="Groupe de ' + escapeHtml(friend.name) + '"><option value="0">Sans groupe</option>' + (window.profileGroupOptions || []).map(function (group) { return '<option value="' + Number(group.id) + '"' + (Number(friend.group_id) === Number(group.id) ? ' selected' : '') + '>' + escapeHtml(group.name) + '</option>'; }).join('') + '</select></div>';
        }
        function loadFriendGroups() {
            return requestJson('index.php?page=home&api=profile_groups').then(function (data) { window.profileGroupOptions = data.groups || []; renderFriendGroups(data); });
        }
        loadFriendGroups().catch(function () { showToast('Impossible de charger vos groupes.'); });
    }

    if (profileFriends && csrf && document.querySelector('.dashboard-profile')) {
        profileFriends.classList.add('profile-friends-managed');
        const friendManageButton = document.createElement('button');
        friendManageButton.type = 'button';
        friendManageButton.className = 'button button-quiet friend-manage-trigger';
        friendManageButton.innerHTML = '<span aria-hidden="true">◎</span><span>Gérer mes proches</span>';
        friendManageButton.setAttribute('aria-label', 'Gérer mes proches');
        profileFriends.after(friendManageButton);
        const friendDrawer = document.createElement('aside');
        friendDrawer.className = 'share-drawer friend-drawer';
        friendDrawer.setAttribute('aria-hidden', 'true');
        friendDrawer.innerHTML = '<div class="share-drawer-backdrop" data-friend-close></div><section class="share-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="friend-drawer-title"><header><button class="share-drawer-back" type="button" aria-label="Retour" hidden>←</button><div><span class="eyebrow">MON CERCLE</span><h2 id="friend-drawer-title">Gérer mes proches</h2></div><button class="share-drawer-close" type="button" aria-label="Fermer" data-friend-close>×</button></header><div class="share-drawer-body"><div class="friend-drawer-list-view"><div class="friend-drawer-create"></div><div class="friend-drawer-groups"></div></div><div class="friend-drawer-detail" hidden></div></div></section>';
        document.body.appendChild(friendDrawer);
        const friendListView = friendDrawer.querySelector('.friend-drawer-list-view');
        const friendDetailView = friendDrawer.querySelector('.friend-drawer-detail');
        const friendGroupsTarget = friendDrawer.querySelector('.friend-drawer-groups');
        const friendBack = friendDrawer.querySelector('.share-drawer-back');
        let friendGroupData = {groups: [], friends: []};
        function loadFriendDrawer() { return requestJson('index.php?page=home&api=profile_groups').then(function (data) { friendGroupData = data; renderFriendDrawer(); }); }
        function renderFriendDrawer() {
            friendDrawer.querySelector('.friend-drawer-create').innerHTML = '<form class="friend-group-create"><input name="group_name" type="text" maxlength="80" placeholder="Créer un groupe" aria-label="Nom du groupe" required><button class="button button-primary" type="submit">Créer</button></form>';
            friendGroupsTarget.innerHTML = friendGroupData.groups.length ? friendGroupData.groups.map(function (group) { var count = friendGroupData.friends.filter(function (friend) { return friend.group_ids.indexOf(Number(group.id)) !== -1; }).length; return '<button class="share-owned-list friend-group-card" type="button" data-friend-group-id="' + Number(group.id) + '"><span class="list-row-mark">◎</span><span><strong>' + escapeHtml(group.name) + '</strong><small>' + count + ' proche' + (count === 1 ? '' : 's') + '</small></span><b>→</b></button>'; }).join('') : '<div class="share-drawer-empty"><span>◎</span><p>Aucun groupe créé.</p><small>Créez un groupe pour organiser vos proches.</small></div>';
            friendDrawer.querySelector('.friend-group-create').addEventListener('submit', function (event) { event.preventDefault(); requestJson('index.php?page=home', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'}, body:new URLSearchParams({csrf_token:csrf.value, action:'create_friend_group', group_name:event.target.group_name.value})}).then(function (response) { if (!response.ok) throw new Error('group'); return loadFriendDrawer(); }).catch(function () { showToast('Impossible de créer ce groupe.'); }); });
        }
        function openFriendGroup(groupId) {
            const group = friendGroupData.groups.find(function (item) { return Number(item.id) === Number(groupId); });
            if (!group) return;
            friendListView.hidden = true;
            friendDetailView.hidden = false;
            friendBack.hidden = false;
            const members = friendGroupData.friends.filter(function (friend) { return friend.group_ids.indexOf(Number(group.id)) !== -1; });
            friendDetailView.innerHTML = '<div class="share-detail-heading"><span class="focus-icon">◎</span><strong>' + escapeHtml(group.name) + '</strong><small>Ajoutez ou retirez des proches de ce groupe.</small></div><div class="friend-drawer-add"><label class="sr-only" for="friend-group-search">Rechercher un proche</label><input id="friend-group-search" type="search" placeholder="Ajouter un proche..."><div class="friend-drawer-results"></div></div><div class="share-members">' + (members.length ? members.map(function (friend) { return '<div class="share-member"><span class="user-avatar">' + escapeHtml(friend.name.charAt(0).toUpperCase()) + '</span><div><strong>' + escapeHtml(friend.name) + '</strong><small>' + escapeHtml(friend.email) + '</small></div><button class="button button-quiet friend-remove" type="button" data-friend-id="' + Number(friend.id) + '">Retirer</button></div>'; }).join('') : '<div class="share-drawer-empty"><span>◎</span><p>Ce groupe est vide.</p></div>') + '</div>';
            const search = friendDetailView.querySelector('#friend-group-search');
            const results = friendDetailView.querySelector('.friend-drawer-results');
            function renderAvailable(term) { const normalized = term.trim().toLowerCase(); const matches = friendGroupData.friends.filter(function (friend) { return friend.group_ids.indexOf(Number(group.id)) === -1 && (!normalized || (friend.name + ' ' + friend.email).toLowerCase().indexOf(normalized) !== -1); }); results.innerHTML = matches.length ? matches.map(function (friend) { return '<button class="direct-share-result" type="button" data-friend-add-id="' + Number(friend.id) + '"><span class="user-avatar">' + escapeHtml(friend.name.charAt(0).toUpperCase()) + '</span><span><strong>' + escapeHtml(friend.name) + '</strong><small>' + escapeHtml(friend.email) + '</small></span><b>+</b></button>'; }).join('') : '<small class="direct-share-empty">Aucun proche disponible.</small>'; }
            renderAvailable('');
            search.addEventListener('input', function () { renderAvailable(search.value); });
            function updateMembership(friendId, action, groupId) { requestJson('index.php?page=home', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'}, body:new URLSearchParams({csrf_token:csrf.value, action:action, friend_id:friendId, group_id:groupId})}).then(function (response) { if (!response.ok) throw new Error('assign'); return loadFriendDrawer(); }).then(function () { const refreshed = friendGroupData.groups.find(function (item) { return Number(item.id) === Number(group.id); }); if (refreshed) openFriendGroup(refreshed.id); }).catch(function () { showToast('Impossible de modifier ce groupe.'); }); }
            results.addEventListener('click', function (event) { const button = event.target.closest('[data-friend-add-id]'); if (button) updateMembership(Number(button.dataset.friendAddId), 'assign_friend_group', group.id); });
            friendDetailView.querySelectorAll('.friend-remove').forEach(function (button) { button.addEventListener('click', function () { updateMembership(Number(button.dataset.friendId), 'unassign_friend_group', group.id); }); });
        }
        friendManageButton.addEventListener('click', function () { friendDrawer.classList.add('is-open'); friendDrawer.setAttribute('aria-hidden', 'false'); friendBack.hidden = true; friendListView.hidden = false; friendDetailView.hidden = true; loadFriendDrawer().catch(function () { showToast('Impossible de charger vos groupes.'); }); });
        friendDrawer.querySelectorAll('[data-friend-close]').forEach(function (control) { control.addEventListener('click', function () { friendDrawer.classList.remove('is-open'); friendDrawer.setAttribute('aria-hidden', 'true'); }); });
        friendBack.addEventListener('click', function () { friendBack.hidden = true; friendListView.hidden = false; friendDetailView.hidden = true; });
        friendGroupsTarget.addEventListener('click', function (event) { const button = event.target.closest('[data-friend-group-id]'); if (button) openFriendGroup(Number(button.dataset.friendGroupId)); });
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && friendDrawer.classList.contains('is-open')) { friendDrawer.classList.remove('is-open'); friendDrawer.setAttribute('aria-hidden', 'true'); } });
    }

    function showToast(message) {
        const toast = document.createElement('div');
        toast.className = 'toast';
        toast.textContent = message;
        document.body.appendChild(toast);
        window.setTimeout(function () {
            toast.classList.add('is-leaving');
            window.setTimeout(function () { toast.remove(); }, 300);
        }, 2200);
    }

    function requestJson(url, options) {
        if (url instanceof HTMLInputElement && url.form) {
            url = url.form.getAttribute('action') || 'index.php?page=home';
        }
        return fetch(url, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok) {
                    const error = new Error(data.message || ('La requête a échoué (HTTP ' + response.status + ').'));
                    error.status = response.status;
                    error.payload = data;
                    throw error;
                }
                return data;
            });
        });
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (character) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character];
        });
    }

    function renderSearchResults(form, users) {
        var results = form.parentElement.querySelector('.user-results');
        if (!results) {
            results = document.createElement('div');
            results.className = 'user-results';
            form.after(results);
        }
        results.innerHTML = users.length ? users.map(function (user) {
            var name = (user.first_name + ' ' + user.last_name).trim();
            return '<div class="user-result"><span class="user-avatar">' + escapeHtml(user.first_name.charAt(0).toUpperCase()) + '</span><div><strong>' + escapeHtml(name) + '</strong><small>' + escapeHtml(user.email) + '</small></div><form class="friend-request-form" method="post" action="index.php?page=home"><input type="hidden" name="csrf_token" value="' + escapeHtml(csrf.value) + '"><input type="hidden" name="action" value="send_friend_request"><input type="hidden" name="receiver_id" value="' + Number(user.id) + '"><button class="icon-button" type="submit" aria-label="Ajouter ' + escapeHtml(name) + '">+</button></form></div>';
        }).join('') : '<div class="quiet-empty"><span>?</span><p>Aucun utilisateur trouvé.</p></div>';
    }

    document.querySelectorAll('.person-search').forEach(function (form) {
        var input = form.querySelector('input[type="search"]');
        if (!input || !csrf) return;
        var searchTimer;
        input.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                requestJson('index.php?page=home&api=search_users&q=' + encodeURIComponent(input.value.trim()))
                    .then(function (data) { renderSearchResults(form, data.users || []); })
                    .catch(function () {});
            }, 180);
        });
        form.addEventListener('submit', function (event) { event.preventDefault(); input.dispatchEvent(new Event('input')); });
    });

    document.addEventListener('submit', function (event) {
        var friendForm = event.target.closest('.friend-request-form, .user-result form');
        if (!friendForm || !csrf) return;
        event.preventDefault();
        var button = friendForm.querySelector('button');
        if (button) button.disabled = true;
        requestJson(friendForm.getAttribute('action') || 'index.php?page=home', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded', 'X-Requested-With':'fetch'}, body: new URLSearchParams(new FormData(friendForm)) })
            .then(function (data) { if (!data.ok) throw new Error(data.message || 'request'); friendForm.closest('.user-result').classList.add('is-sent'); showToast('Demande envoyée.'); })
            .catch(function () { showToast('La demande n’a pas pu être envoyée.'); if (button) button.disabled = false; });
    });

    function updateNotificationBadge() {
        requestJson('index.php?page=home&api=notifications').then(function (data) {
            var link = document.querySelector('.notification-link');
            if (!link) return;
            var badge = link.querySelector('b');
            if (data.unread > 0) {
                if (!badge) { badge = document.createElement('b'); link.appendChild(badge); }
                badge.textContent = data.unread > 9 ? '9+' : data.unread;
            } else if (badge) badge.remove();
        }).catch(function () {});
    }
    if (document.querySelector('.notification-link')) window.setInterval(updateNotificationBadge, 5000);

    function updateProgress(items) {
        var completed = items.filter(function (item) { return Number(item.is_done) === 1; }).length;
        var total = items.length;
        var completedNode = document.querySelector('[data-progress-completed]');
        var totalNode = document.querySelector('[data-progress-total]');
        var percentNode = document.querySelector('[data-progress-percent]');
        var bar = document.querySelector('[data-progress-bar]');
        if (!completedNode || !totalNode || !percentNode || !bar) return;
        var percent = total ? Math.round((completed / total) * 100) : 0;
        completedNode.textContent = completed;
        totalNode.textContent = total;
        percentNode.textContent = percent + '%';
        bar.style.width = percent + '%';
    }

    function syncListSummaries() {
        requestJson('index.php?page=home&api=lists').then(function (data) {
            var summaries = data.lists || [];
            var availableIds = summaries.map(function (summary) { return String(summary.id); });
            document.querySelectorAll('a[href*="list_id="]').forEach(function (link) {
                var match = link.href.match(/[?&]list_id=(\d+)/);
                if (!match || availableIds.indexOf(match[1]) !== -1) return;
                link.classList.add('is-revoked');
                window.setTimeout(function () { link.remove(); }, 220);
            });
            if (list && availableIds.indexOf(String(list.dataset.listId)) === -1) {
                var workspace = document.querySelector('.list-workspace');
                if (workspace && !workspace.classList.contains('access-revoked')) {
                    workspace.classList.add('access-revoked');
                    workspace.innerHTML = '<div class="workspace-empty"><span class="empty-icon">×</span><h2>Accès retiré.</h2><p>Cette liste n’est plus partagée avec vous.</p><a class="button button-primary" href="index.php?page=home&view=lists">Retour à mes listes</a></div>';
                    showToast('Le partage de cette liste a été arrêté.');
                }
            }
            summaries.forEach(function (summary) {
                var links = Array.from(document.querySelectorAll('a[href*="list_id="]')).filter(function (link) {
                    return link.href.match(/[?&]list_id=(\d+)/) && Number(link.href.match(/[?&]list_id=(\d+)/)[1]) === Number(summary.id);
                });
                if (!links.length) {
                    var currentView = document.querySelector('.dashboard-shop') ? 'shop' : 'lists';
                    var isShared = String(summary.owner_id) !== String(document.body.dataset.userId || '0');
                    var listRows = null;
                    if (listIndex) {
                        var emptyState = listIndex.querySelector('.quiet-empty');
                        if (emptyState) emptyState.remove();
                        if (isShared) {
                            listRows = listIndex.querySelector('.list-index-shared-rows');
                            if (!listRows) {
                                var sharedHeading = document.createElement('div');
                                sharedHeading.className = 'list-index-subheading';
                                sharedHeading.textContent = 'Mes listes partagées';
                                listRows = document.createElement('div');
                                listRows.className = 'list-index-rows list-index-shared-rows';
                                listIndex.appendChild(sharedHeading);
                                listIndex.appendChild(listRows);
                            }
                        } else {
                            listRows = listIndex.querySelector('.list-index-rows:not(.list-index-shared-rows)');
                        }
                        if (!listRows) {
                            listRows = document.createElement('div');
                            listRows.className = isShared ? 'list-index-rows list-index-shared-rows' : 'list-index-rows';
                            listIndex.appendChild(listRows);
                        }
                    }
                    if (listRows) {
                        var row = document.createElement('a');
                        row.className = 'list-index-row';
                        row.href = 'index.php?page=home&view=' + currentView + '&list_id=' + Number(summary.id);
                        row.innerHTML = '<span class="list-row-mark">' + (currentView === 'shop' ? '✓' : '•') + '</span><span class="list-row-content"><strong>' + escapeHtml(summary.name) + '</strong><small>' + Number(summary.item_count) + ' article' + (Number(summary.item_count) === 1 ? '' : 's') + (currentView === 'shop' ? ' · ' + Number(summary.completed_count) + ' acheté(s)' : '') + '</small></span><span class="list-row-arrow">→</span>';
                        listRows.appendChild(row);
                        links.push(row);
                    }
                    var switcher = document.querySelector('.list-switcher');
                    if (switcher) {
                        var card = document.createElement('a');
                        card.className = 'list-switch-card';
                        card.href = 'index.php?page=home&view=' + currentView + '&list_id=' + Number(summary.id);
                        card.innerHTML = '<span class="list-switch-top"><span class="list-dot"></span><small>' + Number(summary.completed_count) + '/' + Number(summary.item_count) + '</small></span><strong>' + escapeHtml(summary.name) + '</strong><span class="list-switch-progress"><i class="progress-width-' + (Number(summary.item_count) ? Math.round((Number(summary.completed_count) / Number(summary.item_count)) * 100) : 0) + '"></i></span>';
                        switcher.insertBefore(card, switcher.querySelector('.list-switch-new'));
                        links.push(card);
                    }
                }
                links.forEach(function (link) {
                    var count = Number(summary.item_count);
                    var completed = Number(summary.completed_count);
                    var small = link.querySelector('small');
                    var progress = link.querySelector('.list-switch-progress i');
                    if (small && link.classList.contains('list-switch-card')) small.textContent = completed + '/' + count;
                    if (small && link.classList.contains('list-index-row')) small.textContent = count + ' article' + (count === 1 ? '' : 's') + (link.href.indexOf('view=shop') !== -1 ? ' · ' + completed + ' acheté(s)' : '');
                    if (small && link.classList.contains('shared-list-card')) small.textContent = completed + '/' + count + ' articles · partagée';
                    if (progress) progress.style.width = (count ? Math.round((completed / count) * 100) : 0) + '%';
                });
            });
        }).catch(function () {});
    }
    if (document.querySelector('.dashboard')) {
        syncListSummaries();
        window.setInterval(syncListSummaries, 4000);
    }

    function syncItems() {
        if (!list) return;
        requestJson('index.php?page=home&api=list_items&list_id=' + encodeURIComponent(list.dataset.listId)).then(function (data) {
            updateProgress(data.items);
            if (noteEditor && list.classList.contains('note-list')) {
                var serverValue = data.items.map(function (item) { return item.label; }).join('\n');
                pendingNoteValue = serverValue;
                return;
            }
            var current = Array.from(list.querySelectorAll('[data-item-id]')).map(function (item) { return item.dataset.itemId + ':' + item.querySelector('.item-label').textContent + ':' + item.classList.contains('is-done'); }).join('|');
            var incoming = data.items.map(function (item) { return item.id + ':' + item.label + ':' + Boolean(Number(item.is_done)); }).join('|');
            if (current === incoming) return;
            list.innerHTML = data.items.length ? data.items.map(function (item) {
                var done = Number(item.is_done) === 1;
                return '<div class="shopping-item ' + (done ? 'is-done' : '') + '" data-item-id="' + Number(item.id) + '"><button class="item-check" type="button" aria-label="' + (done ? 'Décocher ' : 'Cocher ') + escapeHtml(item.label) + '" aria-pressed="' + (done ? 'true' : 'false') + '">' + (done ? '✓' : '○') + '</button><span class="item-label">' + escapeHtml(item.label) + '</span><form method="post" action="index.php?page=home"><input type="hidden" name="csrf_token" value="' + escapeHtml(csrf.value) + '"><input type="hidden" name="action" value="delete_item"><input type="hidden" name="list_id" value="' + Number(list.dataset.listId) + '"><input type="hidden" name="item_id" value="' + Number(item.id) + '"><button class="item-delete" type="submit" aria-label="Supprimer ' + escapeHtml(item.label) + '">×</button></form></div>';
            }).join('') : '<div class="items-empty"><span>✦</span><p>Votre liste attend ses premiers articles.</p></div>';
        }).catch(function () {});
    }

    if (list) window.setInterval(syncItems, 4000);

    if (list && csrf) {
        list.addEventListener('click', function (event) {
            const item = event.target.closest('[data-item-id]');
            if (!item || event.target.closest('form')) return;
            const button = item.querySelector('.item-check');
            if (!button) return;
            const body = new URLSearchParams({
                csrf_token: csrf.value,
                action: 'toggle_item',
                list_id: list.dataset.listId,
                item_id: item.dataset.itemId
            });
            button.disabled = true;
            fetch('index.php?page=home', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' }, body: body })
                .then(function (response) { if (!response.ok) throw new Error('request'); return response.json(); })
                .then(function (data) {
                    if (!data.ok) throw new Error(data.message || 'request');
                    item.classList.toggle('is-done', data.done);
                    button.setAttribute('aria-pressed', data.done ? 'true' : 'false');
                    button.setAttribute('aria-label', (data.done ? 'Décocher ' : 'Cocher ') + item.querySelector('.item-label').textContent);
                    const completed = document.querySelector('[data-progress-completed]');
                    const total = document.querySelector('[data-progress-total]');
                    const percent = document.querySelector('[data-progress-percent]');
                    const bar = document.querySelector('[data-progress-bar]');
                    if (completed && total && percent && bar) {
                        const value = data.total ? Math.round((data.completed / data.total) * 100) : 0;
                        completed.textContent = data.completed;
                        total.textContent = data.total;
                        percent.textContent = value + '%';
                        bar.style.width = value + '%';
                        if (data.total > 0 && data.completed === data.total) {
                            window.setTimeout(function () {
                                if (!window.confirm('Cette liste est terminée. Voulez-vous la supprimer maintenant ?')) return;
                                const form = document.createElement('form');
                                form.method = 'post';
                                form.action = 'index.php?page=home';
                                form.innerHTML = '<input type="hidden" name="csrf_token" value="' + csrf.value + '"><input type="hidden" name="action" value="delete_list"><input type="hidden" name="list_id" value="' + list.dataset.listId + '"><input type="hidden" name="return_view" value="shop">';
                                document.body.appendChild(form);
                                form.submit();
                            }, 350);
                        }
                    }
                })
                .catch(function () { showToast('La sauvegarde a échoué.'); })
                .finally(function () { button.disabled = false; });
        });
    }

    if (voiceButton && voiceRecognitionApi) {
        function resetVoiceButton() {
            voiceButton.disabled = false;
            voiceButton.classList.remove('is-listening');
            voiceButton.setAttribute('aria-label', 'Ajouter un article avec la voix');
        }
        function showVoiceFeedback(transcript, response) {
            voiceFeedback.hidden = false;
            voiceFeedback.classList.add('is-open');
            if (voiceState) voiceState.textContent = response === 'Je vous écoute...' ? 'Parlez naturellement...' : 'Réponse reçue';
            voiceTranscript.textContent = transcript || 'Écoute en cours...';
            voiceResponse.textContent = response || '...';
        }
        function speakVoiceMessage(message) {
            if (!message || !('speechSynthesis' in window)) return;
            window.speechSynthesis.cancel();
            const utterance = new SpeechSynthesisUtterance(message);
            utterance.lang = 'fr-FR';
            utterance.rate = 1.16;
            utterance.pitch = 1;
            window.speechSynthesis.speak(utterance);
        }
        function closeVoiceFeedback() {
            voiceFeedback.classList.remove('is-open');
            voiceFeedback.hidden = true;
        }

        function secureVoiceContext() {
            const hostname = window.location.hostname;
            const localHost = hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '::1';
            return window.isSecureContext === true && (window.location.protocol === 'https:' || localHost);
        }

        function requestMicrophoneAccess() {
            if (!secureVoiceContext()) {
                const origin = window.location.protocol + '//' + window.location.host;
                const error = new Error('Chrome ne peut pas proposer l’autorisation du microphone sur ' + origin + '. Ouvrez Shoply en HTTPS (ou sur localhost pour les tests), puis réessayez.');
                showVoiceError('configuration', error);
                return Promise.reject(error);
            }
            if (document.permissionsPolicy && typeof document.permissionsPolicy.allowsFeature === 'function' && !document.permissionsPolicy.allowsFeature('microphone')) {
                const error = new Error('La politique de sécurité du document bloque le microphone. Vérifiez l’en-tête Permissions-Policy ou l’attribut allow="microphone" si Shoply est intégré dans une iframe.');
                showVoiceError('policy-blocked', error);
                return Promise.reject(error);
            }
            if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
                const error = new Error('Le microphone est indisponible car cette page n’est pas dans un contexte sécurisé. Utilisez HTTPS ou localhost.');
                showVoiceError('audio-capture', error);
                return Promise.reject(error);
            }
            return navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                stream.getTracks().forEach(function (track) { track.stop(); });
                return true;
            }).catch(function (error) {
                const code = error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError' ? 'not-allowed' : 'audio-capture';
                showVoiceError(code, error);
                throw error;
            });
        }

        function recognitionErrorMessage(errorCode) {
            const messages = {
                'not-allowed': 'L’accès au microphone a été refusé. Autorisez le microphone dans les réglages du site ou du téléphone.',
                'service-not-allowed': 'Le service de reconnaissance vocale n’est pas autorisé par ce navigateur.',
                'audio-capture': 'Aucun microphone n’est disponible. Vérifiez les réglages audio de votre appareil.',
                'network': 'Le service de reconnaissance vocale du navigateur est inaccessible. Vérifiez le réseau, un bloqueur ou essayez Chrome/Edge à jour.',
                'no-speech': 'Aucune parole n’a été détectée. Parlez après le signal du microphone.',
                'aborted': 'La reconnaissance vocale a été arrêtée.',
                'language-not-supported': 'La langue française n’est pas disponible pour la reconnaissance vocale.',
                'configuration': 'La saisie vocale nécessite HTTPS. Utilisez une adresse https:// ou localhost.',
                'policy-blocked': 'Le navigateur bloque le microphone pour cette page. Si Shoply est dans une iframe, ajoutez allow="microphone". Sinon, vérifiez la politique de sécurité du site.',
                'microphone-denied': 'L’accès au microphone a été refusé. Veuillez réinitialiser les autorisations dans les paramètres de votre navigateur ou de votre téléphone (icône de cadenas dans la barre d’adresse ou paramètres de la PWA).'
            };
            return messages[errorCode] || 'La reconnaissance vocale a rencontré une erreur. Réessayez.';
        }

        function showVoiceError(errorCode, error) {
            let message = (errorCode === 'configuration' || errorCode === 'audio-capture' || errorCode === 'policy-blocked') && error instanceof Error ? error.message : recognitionErrorMessage(errorCode);
            if (errorCode === 'not-allowed' && window.top !== window.self) {
                message = 'Le microphone est bloqué dans cette iframe. Ajoutez allow="microphone" sur l’iframe ou ouvrez Shoply directement.';
            }
            console.error('[Shoply voice]', errorCode, error || message);
            showVoiceFeedback('', message);
            if (voiceState) voiceState.textContent = 'Erreur microphone';
            speakVoiceMessage(message);
            resetVoiceButton();
        }

        function handleMicrophoneClick() {
            voiceButton.disabled = true;
            voiceButton.classList.add('is-listening');
            voiceButton.setAttribute('aria-label', 'Écoute en cours');
            if (voiceState) voiceState.textContent = 'Autorisation du microphone...';
            showVoiceFeedback('', 'Autorisez le microphone si votre navigateur vous le demande.');
            requestMicrophoneAccess().then(function () {
                if (voiceState) voiceState.textContent = 'Microphone autorisé. Parlez maintenant...';
                const recognition = new voiceRecognitionApi();
                recognition.lang = document.documentElement.lang || 'fr-FR';
                recognition.interimResults = true;
                recognition.continuous = false;
                recognition.maxAlternatives = 3;
                let finalTranscript = '';
                let receivedResult = false;
                let recognitionHadError = false;
                recognition.onresult = function (event) {
                    let transcript = '';
                    for (let index = 0; index < event.results.length; index++) transcript += event.results[index][0].transcript;
                    transcript = transcript.trim();
                    showVoiceFeedback(transcript, 'Analyse en cours...');
                    const lastResult = event.results[event.results.length - 1];
                    if (!lastResult.isFinal || receivedResult) return;
                    receivedResult = true;
                    finalTranscript = transcript;
                    if (!finalTranscript) return;
                    const commandContext = pendingVoiceCommand;
                    pendingVoiceCommand = null;
                    const requestBody = { csrf_token: csrf.value, list_id: list ? list.dataset.listId : '0', transcription: finalTranscript };
                    if (commandContext) {
                        requestBody.confirm_create = 'true';
                        requestBody.pending_target_list = commandContext.target_list || '';
                        requestBody.pending_item = commandContext.item || '';
                    }
                    requestJson('api/voice-command.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' }, body: new URLSearchParams(requestBody) })
                        .then(function (data) {
                            if (!data.ok) throw new Error(data.message || 'La commande vocale a échoué.');
                            showVoiceFeedback(finalTranscript, data.message_to_user);
                            speakVoiceMessage(data.message_to_user);
                            if (data.action === 'add') {
                                showToast('Article ajouté à la liste.');
                                if (list) syncItems();
                                syncListSummaries();
                                window.setTimeout(closeVoiceFeedback, 700);
                            } else if (data.pending && data.pending.target_list && data.pending.item) {
                                pendingVoiceCommand = data.pending;
                                window.setTimeout(handleMicrophoneClick, 1100);
                            }
                        })
                        .catch(function (error) {
                            if (commandContext) pendingVoiceCommand = commandContext;
                            console.error('[Shoply voice] Backend error', error);
                            showVoiceFeedback(finalTranscript, error.message);
                            showToast(error.message);
                            speakVoiceMessage(error.message);
                        });
                };
                recognition.onstart = function () {
                    if (voiceState) voiceState.textContent = 'Je vous écoute. Parlez maintenant...';
                };
                recognition.onsoundstart = function () {
                    if (voiceState) voiceState.textContent = 'Je vous entends...';
                };
                recognition.onerror = function (event) {
                    if (event.error === 'aborted') {
                        resetVoiceButton();
                        return;
                    }
                    recognitionHadError = true;
                    showVoiceError(event.error, event.message);
                };
                recognition.onend = function () {
                    if (!receivedResult && !recognitionHadError) showVoiceError('no-speech');
                    else resetVoiceButton();
                };
                try {
                    recognition.start();
                } catch (error) {
                    showVoiceError('audio-capture', error);
                }
            }).catch(function (error) {
                resetVoiceButton();
            });
        }

        voiceButton.addEventListener('click', handleMicrophoneClick);
    } else if (voiceButton) {
        voiceButton.disabled = true;
        voiceButton.title = 'La saisie vocale n’est pas disponible dans ce navigateur';
    }

    if (list && csrf) {
        const leaveLink = document.createElement('a');
        leaveLink.className = 'button button-quiet leave-list-button';
        leaveLink.href = list.classList.contains('shopping-mode') ? 'index.php?page=home&view=shop' : 'index.php?page=home&view=lists';
        leaveLink.textContent = 'Quitter la liste';
        leaveLink.setAttribute('aria-label', 'Quitter la liste');
        const workspaceHeading = document.querySelector('.list-workspace .workspace-heading');
        if (workspaceHeading) {
            workspaceHeading.appendChild(leaveLink);
            if (document.querySelector('.list-workspace[data-can-delete-list="true"]')) {
                const deleteForm = document.createElement('form');
                deleteForm.method = 'post';
                deleteForm.action = 'index.php?page=home';
                deleteForm.dataset.confirm = 'Supprimer définitivement cette liste ?';
                deleteForm.className = 'delete-list-form';
                deleteForm.innerHTML = '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrf.value) + '"><input type="hidden" name="action" value="delete_list"><input type="hidden" name="list_id" value="' + Number(list.dataset.listId) + '"><input type="hidden" name="return_view" value="' + (list.classList.contains('shopping-mode') ? 'shop' : 'lists') + '"><button class="button button-danger" type="submit" aria-label="Supprimer la liste">Supprimer la liste</button>';
                workspaceHeading.appendChild(deleteForm);
            }
        }
    }
    if (noteForm && list && csrf && !list.classList.contains('shopping-mode')) {
        const note = document.createElement('textarea');
        note.className = 'shopping-note';
        note.rows = 10;
        note.placeholder = 'Écrivez un article par ligne...';
        note.setAttribute('aria-label', 'Articles de la liste, un par ligne');
        note.value = Array.from(list.querySelectorAll('.item-label')).map(function (item) { return item.textContent.trim(); }).join('\n');
        noteForm.replaceWith(note);
        list.classList.add('note-list');
        noteEditor = note;
        noteSavedValue = note.value;
        noteBaseValue = note.value;
        let saveInFlight = false;
        function refreshNoteOnFocus() {
            var valueAtFocus = note.value;
            requestJson('index.php?page=home&api=list_items&list_id=' + encodeURIComponent(list.dataset.listId)).then(function (data) {
                if (!noteFocused) return;
                var serverValue = data.items.map(function (item) { return item.label; }).join('\n');
                if (note.value === valueAtFocus) {
                    note.value = serverValue;
                    noteSavedValue = serverValue;
                    noteBaseValue = serverValue;
                    pendingNoteValue = null;
                }
            }).catch(function () {});
        }
        function saveNote(isLeaving) {
            if (saveInFlight || note.value === noteSavedValue) return;
            saveInFlight = true;
            const body = new URLSearchParams({ csrf_token: csrf.value, action: 'save_note', list_id: list.dataset.listId, content: note.value, base_content: noteBaseValue });
            fetch('index.php?page=home', { method: 'POST', keepalive: Boolean(isLeaving), headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' }, body: body })
                .then(function (response) { if (!response.ok) throw new Error('save'); return response.json(); })
                .then(function (data) { if (!data.ok) throw new Error('save'); noteSavedValue = data.content; noteBaseValue = data.content; pendingNoteValue = data.content; if (!noteFocused && !noteAwaitingReturn) note.value = data.content; if (!isLeaving) showToast('Liste enregistrée'); })
                .catch(function () { if (!isLeaving) showToast('La liste n’a pas pu être enregistrée.'); })
                .finally(function () { saveInFlight = false; if (noteFocused) refreshNoteOnFocus(); });
        }
            note.addEventListener('focus', function () { noteFocused = true; noteAwaitingReturn = false; if (!saveInFlight) refreshNoteOnFocus(); });
        note.addEventListener('blur', function () { noteFocused = false; noteAwaitingReturn = true; saveNote(true); });
        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') saveNote(true); });
        window.addEventListener('pagehide', function () { saveNote(true); });
    }

    var modal = document.createElement('div');
    modal.className = 'shoply-modal';
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = '<div class="shoply-modal-backdrop" data-modal-close></div><section class="shoply-modal-card" role="dialog" aria-modal="true" aria-labelledby="shoply-modal-title"><span class="shoply-modal-icon">!</span><h2 id="shoply-modal-title">Confirmer la suppression</h2><p class="shoply-modal-message"></p><div class="shoply-modal-actions"><button class="button button-quiet" type="button" data-modal-close>Annuler</button><button class="button button-danger" type="button" data-modal-submit>Supprimer</button></div></section>';
    document.body.appendChild(modal);
    var modalForm = null;
    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        modalForm = null;
    }
    document.querySelectorAll('[data-modal-close]').forEach(function (control) { control.addEventListener('click', closeModal); });
    modal.querySelector('[data-modal-submit]').addEventListener('click', function () {
        if (!modalForm) return;
        modalForm.removeAttribute('data-confirm');
        modalForm.submit();
    });
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            modalForm = form;
            modal.querySelector('.shoply-modal-message').textContent = form.dataset.confirm;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            modal.querySelector('[data-modal-submit]').focus();
        });
    });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && modal.classList.contains('is-open')) closeModal(); });

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('sw.js?v=18', { scope: './' }).catch(function () {});
        });
    }
}());