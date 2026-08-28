<?php
include 'includes/config.php';

$name = '';
$email = '';
$concern_type = 'General Inquiry';
$message = '';
$errors = [];
$success = false;
$concern_types = [
    'General Inquiry',
    'Technical Support',
    'Research Advisory',
    'Account Issue',
    'Other'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $concern_type = trim($_POST['concern_type'] ?? 'General Inquiry');
    $message = trim($_POST['message'] ?? '');

    if ($name === '') {
        $errors[] = 'Please enter your name.';
    }

    if ($email === '') {
        $errors[] = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (!in_array($concern_type, $concern_types, true)) {
        $concern_type = 'General Inquiry';
    }

    if ($message === '') {
        $errors[] = 'Please enter your message.';
    }

    if (empty($errors)) {
        // @rms-db: Create a contact_messages table here if contact storage is needed in a future version.
        $success = true;
        $name = '';
        $email = '';
        $concern_type = 'General Inquiry';
        $message = '';
    }
}

function contact_escape($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact | Research Management System</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* @rms-ui: Keep public contact controls readable on the light card surface. */
        .contact-section { background: linear-gradient(180deg, #fff 0%, #F0EBFF 100%); }
        .contact-grid {
            display: grid;
            grid-template-columns: minmax(260px, 0.85fr) minmax(320px, 1.15fr);
            gap: 28px;
            max-width: 1100px;
            margin: 0 auto;
        }
        .contact-card { height: 100%; }
        .contact-card .card-body { padding: 30px; }
        .contact-detail {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            padding: 16px 0;
            border-bottom: 1px solid var(--border);
        }
        .contact-detail:last-child { border-bottom: 0; }
        .contact-detail-icon { font-size: 1.35rem; line-height: 1.4; }
        .contact-detail strong { display: block; margin-bottom: 2px; }
        .contact-detail span:last-child { color: var(--text-light); font-size: 0.9rem; }
        .contact-form .form-label { color: var(--text-dark); }
        .contact-form .form-control {
            background: #fff;
            border-color: var(--border);
            color: var(--text-dark);
        }
        .contact-form .form-control::placeholder { color: var(--text-muted); }
        .contact-form select.form-control option { color: var(--text-dark); }
        .contact-form textarea.form-control { resize: vertical; min-height: 150px; }
        @media (max-width: 768px) {
            .contact-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">
            <div class="nav-logo">🔬</div>
            <div class="nav-brand-text">
                <span class="brand-main">Research<br>Management</span>
                <span class="brand-sub">System</span>
            </div>
        </div>
        <ul class="nav-links">
            <li><a href="index.php">Home</a></li>
            <li><a href="about.php">About</a></li>
            <li><a href="index.php#features">Features</a></li>
            <li><a href="research-archive.php">Research Archive</a></li>
            <li><a href="contact.php" class="active">Contact</a></li>
        </ul>
        <div class="nav-actions">
            <a href="login.php" class="btn btn-secondary btn-sm">Login</a>
            <a href="login.php#register" class="btn btn-primary btn-sm">Register</a>
        </div>
    </nav>

    <section class="hero" style="min-height: 52vh;">
        <div class="hero-container" style="grid-template-columns: 1fr; text-align: center;">
            <div class="hero-content" style="margin: 0 auto;">
                <h1>Get in <span class="highlight">Touch</span></h1>
                <p style="max-width: 650px; margin-left: auto; margin-right: auto;">We'd love to hear from you. Reach out to the RMS team or your department's research coordinator.</p>
            </div>
        </div>
    </section>

    <section class="section contact-section">
        <div class="contact-grid">
            <div class="card contact-card">
                <div class="card-header">
                    <div>
                        <div class="card-title">Contact Information</div>
                        <div class="card-subtitle">We are here to help with your research journey.</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="contact-detail">
                        <span class="contact-detail-icon">📧</span>
                        <div><strong>Email</strong><span>research@earist.edu.ph</span></div>
                    </div>
                    <div class="contact-detail">
                        <span class="contact-detail-icon">📞</span>
                        <div><strong>Phone</strong><span>(02) 1234-5678</span></div>
                    </div>
                    <div class="contact-detail">
                        <span class="contact-detail-icon">📍</span>
                        <div><strong>Address</strong><span>EARIST, Manila, Philippines</span></div>
                    </div>
                    <div class="contact-detail">
                        <span class="contact-detail-icon">🕒</span>
                        <div><strong>Office Hours</strong><span>Mon–Fri, 8:00 AM – 5:00 PM</span></div>
                    </div>
                </div>
            </div>

            <div class="card contact-card contact-form">
                <div class="card-header">
                    <div>
                        <div class="card-title">Send a Message</div>
                        <div class="card-subtitle">Complete the form and our team will respond soon.</div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($success): ?>
                        <div class="alert alert-success" role="status">Your message has been received. We'll get back to you within 2-3 business days.</div>
                    <?php endif; ?>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-error" role="alert">
                            <ul style="margin: 0; padding-left: 20px;">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo contact_escape($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="contact.php">
                        <div class="form-group">
                            <label class="form-label" for="name">Name</label>
                            <input class="form-control" type="text" id="name" name="name" required value="<?php echo contact_escape($name); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="email">Email</label>
                            <input class="form-control" type="email" id="email" name="email" required value="<?php echo contact_escape($email); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="concern_type">Concern Type</label>
                            <select class="form-control" id="concern_type" name="concern_type">
                                <?php foreach ($concern_types as $type): ?>
                                    <option value="<?php echo contact_escape($type); ?>"<?php echo $concern_type === $type ? ' selected' : ''; ?>><?php echo contact_escape($type); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="message">Message</label>
                            <textarea class="form-control" id="message" name="message" rows="6" required><?php echo contact_escape($message); ?></textarea>
                        </div>
                        <button class="btn btn-primary" type="submit">Send Message</button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <footer class="footer">
        <div class="footer-grid">
            <div class="footer-col">
                <h4 style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;"><span style="font-size: 1.4rem;">🔬</span><span style="font-family: 'Poppins', sans-serif;">RMS</span></h4>
                <p style="color: #8B8FAD; font-size: 0.85rem; line-height: 1.6;">Empowering academic institutions to streamline their research management processes with modern, intelligent technology.</p>
            </div>
            <div class="footer-col">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="about.php">About</a></li>
                    <li><a href="index.php#features">Features</a></li>
                    <li><a href="research-archive.php">Research Archive</a></li>
                    <li><a href="contact.php">Contact</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Resources</h4>
                <ul>
                    <li><a href="#">Guidelines</a></li>
                    <li><a href="#">FAQ</a></li>
                    <li><a href="#">Documentation</a></li>
                    <li><a href="#">Support</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Contact</h4>
                <p style="font-size: 0.85rem; color: #8B8FAD; display: flex; gap: 8px;"><span>📧</span> research@earist.edu.ph</p>
                <p style="font-size: 0.85rem; color: #8B8FAD; display: flex; gap: 8px; margin-top: 8px;"><span>📞</span> (02) 1234-5678</p>
            </div>
        </div>
        <div class="footer-bottom">© 2024 Research Management System. All rights reserved.</div>
    </footer>
</body>
</html>
