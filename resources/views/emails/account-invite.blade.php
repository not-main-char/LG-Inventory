<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, Segoe UI, Inter, sans-serif; background:#F7F3E9; color:#182A20; margin:0; padding:0; }
        .wrap { max-width:480px; margin:40px auto; background:#fff; border-radius:14px; overflow:hidden; border:1px solid #E7E0CC; }
        .head { background:#1E3A26; padding:28px 32px; color:#fff; }
        .head h1 { margin:0; font-size:19px; }
        .body { padding:28px 32px; font-size:14px; line-height:1.6; }
        .btn { display:inline-block; background:#28503A; color:#fff !important; text-decoration:none; padding:12px 22px; border-radius:8px; font-weight:600; margin:18px 0; }
        .role { display:inline-block; background:#E4ECD9; color:#28503A; font-size:11px; font-weight:700; text-transform:uppercase; padding:3px 9px; border-radius:5px; letter-spacing:.04em; }
        .footer { padding:18px 32px; font-size:12px; color:#8a8a8a; border-top:1px solid #F1ECDC; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="head">
            <h1>LG Agri-Tourism</h1>
        </div>
        <div class="body">
            <p>Hi {{ $name }},</p>
            <p>An account has been created for you on the LG Agri-Tourism farm management system, with the role: <span class="role">{{ $role }}</span></p>
            <p>For security, no one — not even the admin who created your account — has set or seen your password. Please set your own password using the secure link below before logging in:</p>
            <a href="{{ $resetLink }}" class="btn">Set My Password</a>
            <p style="font-size:12px;color:#8a8a8a">If the button doesn't work, copy and paste this link into your browser:<br>{{ $resetLink }}</p>
        </div>
        <div class="footer">
            If you weren't expecting this account, you can safely ignore this email.
        </div>
    </div>
</body>
</html>
