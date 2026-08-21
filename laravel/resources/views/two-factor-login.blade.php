<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Two-factor authentication - Matomo</title>
</head>
<body>
    <h1>Two-factor authentication</h1>
    @if ($error !== '')
        <p role="alert"><strong>Error</strong>: {{ $error }}</p>
    @endif
    <form method="post" action="/index.php?module=TwoFactorAuth&amp;action=loginTwoFactorAuth">
        <input type="hidden" name="form_nonce" value="{{ $nonce }}">
        <p>
            <label for="login_form_authcode">Authentication code</label>
            <input type="text" name="form_authcode" id="login_form_authcode" inputmode="numeric" autocomplete="one-time-code" required>
        </p>
        <p>
            <input type="submit" value="Verify">
        </p>
    </form>
</body>
</html>
