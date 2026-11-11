<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Новый пароль администратора | LEGIONAUTORENT</title>
    <style>
        body{margin:0;min-height:100vh;display:grid;place-items:center;background:#111;color:#fff;font:16px/1.5 Arial,sans-serif;padding:16px}
        form{width:min(420px,100%);background:#191919;border:1px solid #ffffff24;border-radius:12px;padding:32px}
        h1{font-size:22px;margin:0 0 8px}p{color:#b0b0b0;margin:0 0 24px;font-size:14px}
        label{display:block;font-size:13px;color:#b0b0b0;margin:16px 0 6px}
        input{width:100%;box-sizing:border-box;padding:12px;border-radius:8px;border:1px solid #444;background:#111;color:#fff;font:inherit}
        button{margin-top:24px;width:100%;padding:14px;border:0;border-radius:8px;background:#f7b500;color:#000;font-weight:600;font-size:15px;cursor:pointer}
        .error{color:#ff7a7a;font-size:13px;margin-top:6px}
    </style>
</head>
<body>
    <form method="post" action="{{ $action }}">
        @csrf
        <h1>Новый пароль</h1>
        <p>Логин: <strong>{{ $user->username }}</strong>. Ссылка одноразовая: после сохранения она перестанет работать.</p>
        <label for="password">Новый пароль (не меньше 12 символов)</label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>
        <label for="password_confirmation">Повторите пароль</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
        @error('password')<div class="error">{{ $message }}</div>@enderror
        <button type="submit">Сохранить пароль</button>
    </form>
</body>
</html>
