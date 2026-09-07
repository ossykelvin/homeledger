<?php
declare(strict_types=1);
if (!google_oauth_is_configured()) {
    return;
}
$googleInvite = is_string($googleInvite ?? null) ? $googleInvite : '';
$googleNext = is_string($googleNext ?? null) ? $googleNext : '';
$googleQuery = ['page' => 'google'];
if ($googleInvite !== '') {
    $googleQuery['invite'] = $googleInvite;
}
if ($googleNext !== '' && $googleNext !== 'dashboard') {
    $googleQuery['next'] = $googleNext;
}
?>
<div class="auth-or" role="separator"><span>or</span></div>
<a class="google-auth-button" href="?<?= e(http_build_query($googleQuery)) ?>">
    <img class="google-auth-logo" src="assets/brand/google-g.png" width="18" height="18" alt="">
    <span>Sign in with Google</span>
</a>
