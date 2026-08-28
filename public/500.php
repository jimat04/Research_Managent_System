<?php
// ============================================================
// ERROR 500 - INTERNAL SERVER ERROR
// ============================================================
// This page is shown when the server encounters an unexpected
// error during request processing

http_response_code(500);

// Try to load config, but don't fail if it's broken
// (since this is the error handler)
try {
    if (file_exists(__DIR__ . '/includes/config.php')) {
        require_once __DIR__ . '/../includes/config.php';
    }
} catch (Exception $e) {
    // Config is broken - use defaults
}

if (!defined('SITE_URL')) {
    define('SITE_URL', '/rms/');
    define('SITE_NAME', 'Research Management System');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 - Server Error | <?php echo SITE_NAME; ?></title>
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
            background: linear-gradient(135deg, var(--warning), var(--accent));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: white;
            box-shadow: 0 10px 30px rgba(245, 124, 0, 0.3);
        }

        .error-code {
            font-family: 'Poppins', sans-serif;
            font-size: 5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--warning), var(--accent-light));
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
            margin-bottom: 15px;
        }

        .error-details a {
            color: var(--primary-light);
            text-decoration: underline;
        }

        .error-details a:hover {
            color: var(--accent-light);
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 8px;
            color: var(--warning);
            font-size: 0.85rem;
            margin-top: 20px;
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
                ⚠️
            </div>

            <div class="error-code">500</div>

            <h1 class="error-title">Internal Server Error</h1>

            <p class="error-message">
                Something went wrong on our end. We're working to fix it.<br>
                Please try again in a few moments.
            </p>

            <div class="error-actions">
                <a href="javascript:location.reload()" class="error-btn error-btn-secondary">
                    🔄 Refresh Page
                </a>
                <a href="<?php echo SITE_URL; ?>" class="error-btn error-btn-primary">
                    🏠 Go to Homepage
                </a>
            </div>

            <div class="status-indicator">
                📡 Our team has been notified
            </div>

            <div class="error-details">
                <p>
                    If the problem persists, please contact support with the following information:
                </p>
                <p>
                    <strong>Time:</strong> <?php echo date('Y-m-d H:i:s'); ?><br>
                    <strong>Error ID:</strong> <?php echo bin2hex(random_bytes(4)); ?>
                </p>
                <p>
                    <a href="<?php echo SITE_URL; ?>contact.php">Contact Technical Support</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
