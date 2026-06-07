<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Доступ заборонено — LinguaHub</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; }
body {
    font-family: 'Syne', sans-serif;
    background: #07080f;
    color: #e2e8f0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    overflow: hidden;
}

body::before {
    content: '';
    position: fixed; inset: 0; pointer-events: none;
    background:
        radial-gradient(ellipse 60% 45% at 50% 0%,   rgba(245,158,11,.10) 0%, transparent 65%),
        radial-gradient(ellipse 40% 35% at 10% 90%,  rgba(99,102,241,.09) 0%, transparent 60%),
        radial-gradient(ellipse 35% 30% at 90% 80%,  rgba(245,158,11,.06) 0%, transparent 60%);
}

/* Floating symbols */
.floats { position: fixed; inset: 0; pointer-events: none; overflow: hidden; }
.float-el {
    position: absolute;
    opacity: .035;
    font-size: 22px;
    animation: floatUp linear infinite;
}
@keyframes floatUp {
    0%   { transform: translateY(105vh) rotate(0deg);   opacity: 0; }
    8%   { opacity: .04; }
    92%  { opacity: .04; }
    100% { transform: translateY(-80px) rotate(180deg); opacity: 0; }
}

/* Card */
.card {
    position: relative; z-index: 1;
    background: rgba(17,24,39,.88);
    border: 1px solid rgba(245,158,11,.22);
    border-radius: 24px;
    padding: 52px 48px 44px;
    max-width: 460px; width: 90%;
    text-align: center;
    box-shadow:
        0 0 0 1px rgba(245,158,11,.06),
        0 32px 64px rgba(0,0,0,.55),
        0 0 80px rgba(245,158,11,.06);
    animation: cardIn .5s cubic-bezier(.22,1,.36,1) both;
}
@keyframes cardIn {
    from { opacity: 0; transform: translateY(30px) scale(.97); }
    to   { opacity: 1; transform: none; }
}

.logo {
    font-size: 16px; font-weight: 800; letter-spacing: -.5px;
    margin-bottom: 32px; display: inline-block; color: #e2e8f0;
}
.logo span { color: #22d3ee; }

/* Icon */
.icon-wrap {
    position: relative; display: inline-block; margin-bottom: 28px;
}
.icon-circle {
    width: 120px; height: 120px; border-radius: 50%;
    background: radial-gradient(circle at 35% 35%, rgba(245,158,11,.18), rgba(99,102,241,.08));
    border: 1px solid rgba(245,158,11,.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 56px;
    animation: iconFloat 3.5s ease-in-out infinite;
    box-shadow: 0 0 48px rgba(245,158,11,.10);
}
@keyframes iconFloat {
    0%, 100% { transform: translateY(0) rotate(-3deg); }
    50%       { transform: translateY(-8px) rotate(3deg); }
}
.badge {
    position: absolute; bottom: 2px; right: 2px;
    width: 34px; height: 34px; border-radius: 50%;
    background: linear-gradient(135deg, #d97706, #f59e0b);
    display: flex; align-items: center; justify-content: center;
    font-size: 15px;
    box-shadow: 0 4px 14px rgba(245,158,11,.45);
    border: 2px solid #07080f;
    animation: badgePulse 2s ease-in-out infinite;
}
@keyframes badgePulse {
    0%, 100% { box-shadow: 0 4px 14px rgba(245,158,11,.45); }
    50%       { box-shadow: 0 4px 28px rgba(245,158,11,.75); }
}

.error-code {
    font-family: 'JetBrains Mono', monospace;
    font-size: 10px; font-weight: 500;
    color: #f59e0b; letter-spacing: 3px; text-transform: uppercase;
    background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.2);
    padding: 5px 14px; border-radius: 20px;
    display: inline-block; margin-bottom: 16px;
}

.title {
    font-size: 26px; font-weight: 800; line-height: 1.2;
    margin-bottom: 12px; letter-spacing: -.3px;
}
.title .hl { color: #f59e0b; }

.desc {
    font-size: 13px; color: #64748b; line-height: 1.7;
    margin-bottom: 28px;
}

.divider { height: 1px; background: rgba(245,158,11,.10); margin-bottom: 24px; }

.info-box {
    background: rgba(245,158,11,.05); border: 1px solid rgba(245,158,11,.13);
    border-radius: 12px; padding: 14px 16px;
    display: flex; align-items: flex-start; gap: 11px;
    margin-bottom: 28px; text-align: left;
}
.info-icon { font-size: 16px; flex-shrink: 0; margin-top: 2px; }
.info-text { font-size: 12px; color: #94a3b8; line-height: 1.6; }
.info-text strong { color: #e2e8f0; }

.back-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; height: 46px; border-radius: 12px;
    background: rgba(245,158,11,.10); border: 1px solid rgba(245,158,11,.28);
    color: #f59e0b; font-family: 'Syne', sans-serif;
    font-size: 13px; font-weight: 700;
    text-decoration: none; transition: .18s; margin-bottom: 10px;
}
.back-btn:hover {
    background: rgba(245,158,11,.18); border-color: rgba(245,158,11,.5);
    transform: translateY(-1px); box-shadow: 0 6px 20px rgba(245,158,11,.18);
}

.login-link {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; height: 46px; border-radius: 12px;
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08);
    color: #64748b; font-family: 'Syne', sans-serif;
    font-size: 13px; font-weight: 600;
    text-decoration: none; transition: .18s;
}
.login-link:hover { background: rgba(255,255,255,.07); color: #94a3b8; border-color: rgba(255,255,255,.14); }

.footer-note {
    font-family: 'JetBrains Mono', monospace;
    font-size: 10px; color: #1e293b;
    margin-top: 24px; letter-spacing: .5px;
}
</style>
</head>
<body>

<div class="floats" id="floats"></div>

<div class="card">
    <div class="logo">Lingua<span>Hub</span></div>

    <div class="icon-wrap">
        <div class="icon-circle">🚫</div>
        <div class="badge">⚠️</div>
    </div>

    <div class="error-code">ERR · 403 · FORBIDDEN</div>

    <h1 class="title">Доступ <span class="hl">заборонено</span></h1>

    <p class="desc">
        У вас немає прав для перегляду цієї сторінки.<br>
        Ця область доступна лише адміністраторам.
    </p>

    <div class="divider"></div>

    <div class="info-box">
        <span class="info-icon">💡</span>
        <div class="info-text">
            <strong>Що сталося?</strong><br>
            Ви намагаєтесь отримати доступ до захищеної сторінки. Якщо вважаєте це помилкою — зверніться до адміністратора.
        </div>
    </div>

    <a class="back-btn" href="javascript:history.back()">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M19 12H5M12 5l-7 7 7 7"/>
        </svg>
        Повернутись назад
    </a>

    <a class="login-link" href="login.php">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>
        </svg>
        Увійти під іншим акаунтом
    </a>

    <div class="footer-note">linguahub.ua · access_denied · <?= date('Y') ?></div>
</div>

<script>
const wrap = document.getElementById('floats');
const icons = ['🔒','⚠️','🚫','🔑','🛡️','🔒','⚠️','🚫'];
for (let i = 0; i < 16; i++) {
    const el = document.createElement('div');
    el.className = 'float-el';
    el.textContent = icons[Math.floor(Math.random() * icons.length)];
    el.style.left = Math.random() * 100 + 'vw';
    el.style.fontSize = (14 + Math.random() * 18) + 'px';
    el.style.animationDuration = (14 + Math.random() * 14) + 's';
    el.style.animationDelay = -(Math.random() * 22) + 's';
    wrap.appendChild(el);
}
</script>
</body>
</html>