<?php

declare(strict_types=1);

$inviteLink = pull_invite_link();
$invites = household_invites_for_current();
$members = household_members_for_current();
$householdName = (string) ($currentUser['household_name'] ?? 'this household');
$householdPublicCode = (string) ($currentUser['household_public_code'] ?? '');
$householdPrefill = old_form('household_name', $householdName);
$currentUserId = (int) ($currentUser['id'] ?? 0);
$ownerId = household_owner_user_id();
$canManageInvites = $currentUserId > 0 && $currentUserId === $ownerId;
?>
<section class="content-section household-hub">
    <div class="page-intro">
        <div>
            <p class="section-kicker">HOUSEHOLD</p>
            <h2><?= e($householdName) ?></h2>
            <p>This is your household hub. Update the name, copy the household ID, see who has access, and invite someone to share this ledger.</p>
        </div>
    </div>

    <div class="household-details">
        <form method="post" class="invite-form household-name-form" autocomplete="off">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_household">
            <input type="hidden" name="return_page" value="household">
            <label>
                <span>Household name</span>
                <input name="household_name" type="text" value="<?= $householdPrefill ?>" maxlength="80" required>
            </label>
            <button class="primary-button" type="submit">Save name</button>
        </form>
        <div class="invite-link-card household-id-card">
            <p class="eyebrow">HOUSEHOLD ID</p>
            <p>Read-only. Share this code if you need to identify the household. It is not the invite link.</p>
            <label>
                <span>Household ID</span>
                <input class="household-code" type="text" readonly value="<?= e($householdPublicCode) ?>" onclick="this.select()" spellcheck="false">
            </label>
        </div>
    </div>

    <div class="household-section">
        <div class="panel-heading">
            <div>
                <p class="eyebrow">PEOPLE</p>
                <h3>Members</h3>
            </div>
        </div>
        <div class="table-panel">
            <div class="data-table-wrap">
                <table class="data-table compact-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Joined</th>
                            <th>Role</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $member):
                            $memberId = (int) $member['id'];
                            $isYou = $memberId === $currentUserId;
                            $isOwner = $memberId === $ownerId;
                            $joined = new DateTimeImmutable((string) $member['created_at']);
                            $role = $isOwner ? 'Owner' : 'Member';
                        ?>
                            <tr>
                                <td>
                                    <strong><?= e((string) $member['display_name']) ?></strong>
                                    <?php if ($isYou): ?><small>You</small><?php endif; ?>
                                </td>
                                <td><?= e((string) $member['login']) ?></td>
                                <td><?= e($joined->format('j M Y')) ?></td>
                                <td>
                                    <span class="status-pill <?= $isOwner ? 'active' : '' ?>">
                                        <i></i><?= e($role) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$members): ?>
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state compact">
                                        <span>No members found</span>
                                        <p>This household has no users yet.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="range-note">Everyone here has the same access to this ledger except invites, which only the owner can send. Ownership is stored on the household and can be transferred if the owner deletes their account. Membership cannot be moved to another household from this page.</p>
    </div>

    <div class="household-section">
        <div class="panel-heading">
            <div>
                <p class="eyebrow">ACCESS</p>
                <h3>Invites</h3>
            </div>
        </div>
        <p class="range-note"><?= e($canManageInvites
            ? 'Send a 24-hour link so another person can join this household. They sign up with the invited email and share this ledger.'
            : 'Only the household owner can send or resend invites. You can still see who has been invited.') ?></p>

        <?php if ($canManageInvites && $inviteLink): ?>
            <div class="invite-link-card" role="status">
                <p class="eyebrow">INVITE LINK</p>
                <p>Share this link with <strong><?= e($inviteLink['email']) ?></strong>. It expires in 24 hours. Copy it if the email does not arrive.</p>
                <label>
                    <span>Invite URL</span>
                    <input type="text" readonly value="<?= e($inviteLink['url']) ?>" onclick="this.select()">
                </label>
            </div>
        <?php endif; ?>

        <?php if ($canManageInvites): ?>
        <form method="post" class="invite-form" autocomplete="off">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="send_invite">
            <label>
                <span>Email to invite</span>
                <input name="email" type="email" value="<?= old_form('invite_email') ?>" maxlength="190" required placeholder="name@example.com">
            </label>
            <button class="primary-button" type="submit">Send invite</button>
        </form>
        <p class="range-note">HomeLedger emails the link when SMTP is configured. Always copy it from this page as well. Resend issues a new 24-hour link and retires the old one. Up to 10 new invites per household per day.</p>
        <?php endif; ?>

        <div class="table-panel invite-table-panel">
            <div class="data-table-wrap">
                <table class="data-table compact-table">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Expires</th>
                            <th>Status</th>
                            <th class="align-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invites as $invite):
                            $status = invite_status_label($invite);
                            $pending = $status === 'Pending' || $status === 'Expired';
                            $expires = new DateTimeImmutable((string) $invite['expires_at']);
                        ?>
                            <tr>
                                <td><?= e((string) $invite['email']) ?></td>
                                <td><?= e($expires->format('j M Y H:i')) ?></td>
                                <td>
                                    <span class="status-pill <?= $status === 'Pending' ? 'active' : ($status === 'Accepted' ? 'accepted' : 'paused') ?>">
                                        <i></i><?= e($status) ?>
                                    </span>
                                </td>
                                <td class="align-right">
                                    <?php if ($canManageInvites && $pending && empty($invite['accepted_at'])): ?>
                                        <div class="invite-row-actions">
                                            <form method="post" data-confirm="Send a new 24-hour link? The old link will stop working.">
                                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="resend_invite">
                                                <input type="hidden" name="id" value="<?= (int) $invite['id'] ?>">
                                                <button class="secondary-button" type="submit">Resend</button>
                                            </form>
                                            <form method="post" data-confirm="Cancel this invite? The link will stop working.">
                                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="revoke_invite">
                                                <input type="hidden" name="id" value="<?= (int) $invite['id'] ?>">
                                                <button class="secondary-button" type="submit">Cancel</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$invites): ?>
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state compact">
                                        <span>No invites yet</span>
                                        <p><?= e($canManageInvites
                                            ? 'Enter an email above to send a 24-hour join link for this household.'
                                            : 'No invites have been sent yet.') ?></p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
