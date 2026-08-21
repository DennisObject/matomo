<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sign in - Matomo</title>
</head>
<body>
    <h1>Sign in</h1>
    @if ($error !== '')
        <p role="alert"><strong>Error</strong>: {{ $error }}</p>
    @endif
    <form method="post" action="/index.php?module=Login" class="loginForm__form">
        <input type="hidden" name="form_nonce" id="login_form_nonce" value="{{ $nonce }}">
        <input type="hidden" name="form_redirect" id="login_form_redirect" value="">
        <p>
            <label for="login_form_login">Username or email</label>
            <input type="text" name="form_login" id="login_form_login" autocomplete="username" required>
        </p>
        <p>
            <label for="login_form_password">Password</label>
            <input type="password" name="form_password" id="login_form_password" autocomplete="current-password" required>
        </p>
        <p>
            <label>
                <input name="form_rememberme" type="checkbox" id="login_form_rememberme" value="1">
                Remember me
            </label>
        </p>
        <p>
            <input type="submit" id="login_form_submit" value="Sign in">
        </p>
    </form>
</body>
</html>
