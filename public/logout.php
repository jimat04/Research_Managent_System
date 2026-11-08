<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logout-transition.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isLoggedIn()) {
    $logoUrl = SITE_URL . 'photos/rms-logo.png';
    $loginUrl = SITE_URL . 'public/login.php';
    $logoutAction = SITE_URL . 'public/logout.php';
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign out | RMS</title>
    <style>
        :root {
            --bg-dark: #0f172a;
            --bg-mid: #182338;
            --card-bg: rgba(17, 24, 39, 0.72);
            --card-border: rgba(148, 163, 184, 0.18);
            --ink: #e5eefb;
            --muted: #9aa8bb;
            --soft: #ccd5e3;
            --gold: #d4a857;
            --gold-deep: #b98629;
            --danger: #ef4444;
            --danger-deep: #b91c1c;
            --shadow: 0 30px 70px rgba(15, 23, 42, 0.52);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background:
                radial-gradient(circle at top left, rgba(212, 168, 87, 0.18), transparent 20%),
                radial-gradient(circle at bottom right, rgba(148, 163, 184, 0.12), transparent 28%),
                linear-gradient(135deg, var(--bg-dark), var(--bg-mid));
            color: var(--ink);
            font: 16px/1.5 "Inter", "Segoe UI", sans-serif;
        }

        .logout-shell {
            position: relative;
            width: min(100%, 440px);
            padding: 24px;
            border: 1px solid var(--card-border);
            border-radius: 24px;
            background: rgba(15, 23, 42, 0.78);
            backdrop-filter: blur(12px);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .logout-shell::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image: linear-gradient(rgba(255,255,255,0.04) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.04) 1px, transparent 1px);
            background-size: 32px 32px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,0.7), transparent 90%);
            pointer-events: none;
        }

        .logout-card {
            position: relative;
            z-index: 1;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--soft);
            letter-spacing: 0.12em;
            text-transform: uppercase;
            font-size: 11px;
            font-weight: 700;
        }

        .brand-mark {
            display: inline-grid;
            place-items: center;
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.22);
        }

        .brand-mark img {
            width: 24px;
            height: 24px;
            object-fit: contain;
            display: block;
        }

        .logo-orbit {
            position: relative;
            width: 90px;
            height: 90px;
            margin: 24px auto 18px;
            display: grid;
            place-items: center;
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.2);
        }

        .logo-orbit::before,
        .logo-orbit::after {
            content: "";
            position: absolute;
            inset: -10px;
            border: 1px solid rgba(212, 168, 87, 0.5);
            border-left-color: transparent;
            border-radius: 28px;
            animation: orbit 1.2s linear infinite;
        }

        .logo-orbit::after {
            inset: -19px;
            border-color: rgba(255, 255, 255, 0.12);
            border-right-color: transparent;
            animation-duration: 2.1s;
        }

        .logo-orbit img {
            position: relative;
            z-index: 1;
            width: 52px;
            height: 52px;
            object-fit: contain;
            filter: drop-shadow(0 10px 20px rgba(15, 23, 42, 0.28));
        }

        h1 {
            margin: 0;
            text-align: center;
            font-size: clamp(2rem, 4vw, 2.5rem);
            line-height: 1.1;
            letter-spacing: -0.04em;
        }

        .lead {
            margin: 12px auto 0;
            max-width: 32ch;
            text-align: center;
            color: var(--muted);
            font-size: 0.98rem;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 10px;
            margin: 18px auto 0;
            border-radius: 999px;
            border: 1px solid rgba(212, 168, 87, 0.32);
            background: rgba(212, 168, 87, 0.08);
            color: #f7deab;
            font-size: 0.72rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .status-pill::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--gold);
            box-shadow: 0 0 0 5px rgba(212, 168, 87, 0.18);
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 28px;
        }

        .button {
            flex: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            border-radius: 12px;
            text-decoration: none;
            font: 600 0.96rem/1.2 "Inter", "Segoe UI", sans-serif;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
            cursor: pointer;
        }

        .button:hover {
            transform: translateY(-1px);
        }

        .button:focus-visible {
            outline: 3px solid rgba(212, 168, 87, 0.28);
            outline-offset: 2px;
        }

        .button-secondary {
            border: 1px solid rgba(148, 163, 184, 0.46);
            background: rgba(15, 23, 42, 0.35);
            color: var(--ink);
        }

        .button-primary {
            border: 1px solid var(--danger);
            background: linear-gradient(135deg, var(--danger), var(--danger-deep));
            color: #fff;
            box-shadow: 0 12px 24px rgba(239, 68, 68, 0.28);
        }

        form {
            flex: 1;
        }

        form button {
            width: 100%;
            border: 0;
            padding: 0;
        }

        .meta {
            margin-top: 16px;
            color: var(--muted);
            text-align: center;
            font-size: 0.78rem;
        }

        @keyframes orbit {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 480px) {
            .logout-shell {
                padding: 18px;
                border-radius: 20px;
            }

            .actions {
                flex-direction: column;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .logo-orbit::before,
            .logo-orbit::after {
                animation: none;
            }
        }
    </style>
  <link rel="icon" type="image/png" href="../photos/rms-logo.png">
</head>
<body>
    <main class="logout-shell" aria-labelledby="logout-title">
        <div class="logout-card">
            <div class="brand">
                <span class="brand-mark"><img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="RMS logo"></span>
                <span>RMS</span>
            </div>

            <div class="logo-orbit" aria-hidden="true">
                <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" aria-hidden="true">
            </div>

            <h1 id="logout-title">Sign out</h1>
            <p class="lead">Your session will end and you’ll be returned to the RMS sign-in page.</p>
            <div class="status-pill">Secure session</div>

            <div class="actions">
                <a class="button button-secondary" href="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>">Cancel</a>
                <form method="post" action="<?php echo htmlspecialchars($logoutAction, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <button class="button button-primary" type="submit">Sign out</button>
                </form>
            </div>

            <div class="meta">Protecting your account before your next session.</div>
        </div>
    </main>
    <?php renderLogoutTransition(); ?>
</body>
</html>
    <?php
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    exit('Logout requires a valid form submission.');
}

if (isLoggedIn()) {
    logActivity('User logged out', 'authentication');
}

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

$_SESSION = [];
session_unset();
session_destroy();

session_start();
session_regenerate_id(true);

header('Location: ' . SITE_URL . 'public/login.php');
exit();
?>
