<?php
// ============================================================
// ERROR 403 - ACCESS DENIED
// ============================================================
// This page is shown when users try to access resources
// they don't have permission to view

http_response_code(403);

// Load config to get site constants (but don't require database)
if (file_exists(__DIR__ . '/includes/config.php')) {
    include_once __DIR__ . '/includes/config.php';
} else {
    define('SITE_URL', '/rms/');
    define('SITE_NAME', 'Research Management System');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 - Access Denied | <?php echo SITE_NAME; ?></title>
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
            background: linear-gradient(135deg, var(--danger), #dc2626);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: white;
            box-shadow: 0 10px 30px rgba(239, 68, 68, 0.3);
        }

        .error-code {
            font-family: 'Poppins', sans-serif;
            font-size: 5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--danger), #fca5a5);
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

        .error-details {
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .error-details p {
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .error-details a {
            color: var(--primary-light);
            text-decoration: underline;
        }

        .error-details a:hover {
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
                🔒
            </div>

            <div class="error-code">403</div>

            <h1 class="error-title">Access Denied</h1>

            <p class="error-message">
                You don't have permission to access this page or resource.<br>
                This area may be restricted to certain user roles.
            </p>

            <div class="error-actions">
                <a href="javascript:history.back()" class="error-btn error-btn-secondary">
                    ← Go Back
                </a>
                <a href="<?php echo SITE_URL; ?>" class="error-btn error-btn-primary">
                    🏠 Go to Homepage
                </a>
            </div>

            <div class="error-details">
                <p>
                    If you believe this is a mistake, please contact your administrator<br>
                    or <a href="<?php echo SITE_URL; ?>contact.php">submit a support request</a>.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
