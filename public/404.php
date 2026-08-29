<?php
// ============================================================
// ERROR 404 - PAGE NOT FOUND
// ============================================================
// This page is shown when users try to access a page that
// doesn't exist

http_response_code(404);

// Load config to get site constants (but don't require database)
if (file_exists(__DIR__ . '/includes/config.php')) {
    require_once __DIR__ . '/../includes/config.php';
} else {
    define('SITE_URL', '/rms/');
    define('SITE_NAME', 'Research Management System');
}

// Log 404 for analytics (optional)
if (isset($conn) && $conn instanceof mysqli) {
    $requested_url = $_SERVER['REQUEST_URI'] ?? 'unknown';
    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    $stmt = $conn->prepare("INSERT INTO system_logs (log_type, message, ip_address, created_at) VALUES ('404', ?, ?, NOW())");
    if ($stmt) {
        $log_msg = "Page not found: {$requested_url} | Referrer: {$referrer}";
        $stmt->bind_param('ss', $log_msg, $ip);
        $stmt->execute();
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>css/style.css">
    <style>
        .error-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--bg-darker) 0%, var(--bg-dark) 100%);
            padding: 20px;
        }

        .error-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 60px 40px;
            max-width: 600px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
        }

        .error-icon {
            width: 120px;
            height: 120px;
            margin: 0 auto 30px;
            background: linear-gradient(135deg, var(--secondary), var(--info));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: white;
            box-shadow: 0 10px 30px rgba(15, 108, 189, 0.3);
        }

        .error-code {
            font-family: 'Poppins', sans-serif;
            font-size: 5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--secondary-light), var(--accent-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
            margin-bottom: 20px;
        }

        .error-title {
            font-family: 'Poppins', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            color: white;
            margin-bottom: 15px;
        }

        .error-message {
            color: #cbd5e1;
            font-size: 1.1rem;
            line-height: 1.8;
            margin-bottom: 40px;
        }

        .error-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .error-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            border-radius: 12px;
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }

        .error-btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            box-shadow: 0 4px 15px rgba(91, 30, 188, 0.3);
        }

        .error-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(91, 30, 188, 0.4);
        }

        .error-btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .error-btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }

        .error-suggestions {
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .error-suggestions h3 {
            color: white;
            font-size: 1.1rem;
            margin-bottom: 15px;
        }

        .error-suggestions ul {
            list-style: none;
            padding: 0;
        }

        .error-suggestions li {
            color: #94a3b8;
            font-size: 0.9rem;
            margin-bottom: 10px;
        }

        .error-suggestions a {
            color: var(--primary-light);
            text-decoration: underline;
        }

        .error-suggestions a:hover {
            color: var(--accent-light);
        }

        @media (max-width: 768px) {
            .error-card {
                padding: 40px 30px;
            }

            .error-code {
                font-size: 3.5rem;
            }

            .error-title {
                font-size: 1.5rem;
            }

            .error-message {
                font-size: 1rem;
            }

            .error-actions {
                flex-direction: column;
            }

            .error-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-card">
            <div class="error-icon">
                🔍
            </div>

            <div class="error-code">404</div>

            <h1 class="error-title">Page Not Found</h1>

            <p class="error-message">
                The page you're looking for doesn't exist or may have been moved.<br>
                Let's get you back on track.
            </p>

            <div class="error-actions">
                <a href="javascript:history.back()" class="error-btn error-btn-secondary">
                    ← Go Back
                </a>
                <a href="<?php echo SITE_URL; ?>" class="error-btn error-btn-primary">
                    🏠 Go to Homepage
                </a>
            </div>

            <div class="error-suggestions">
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="<?php echo SITE_URL; ?>public/login.php">Login to your account</a></li>
                    <li><a href="<?php echo SITE_URL; ?>about.php">About the Research System</a></li>
                    <li><a href="<?php echo SITE_URL; ?>contact.php">Contact Support</a></li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
