<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

function archive_escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function archive_fetch_all($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

$search = trim($_GET['search'] ?? '');
$selected_category = filter_input(INPUT_GET, 'category_id', FILTER_VALIDATE_INT);
$selected_ay = filter_input(INPUT_GET, 'ay_id', FILTER_VALIDATE_INT);
$selected_category = $selected_category ?: 0;
$selected_ay = $selected_ay ?: 0;

$deleted_column_stmt = $conn->prepare("SHOW COLUMNS FROM research_projects LIKE 'deleted_at'");
$deleted_column_stmt->execute();
$has_deleted_at = $deleted_column_stmt->get_result()->num_rows > 0;
$deleted_column_stmt->close();
$deleted_filter = $has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';

// @rms-db: Apply rms_db_migration.sql so research_projects.deleted_at is available for soft deletes.
$count_stmt = $conn->prepare("SELECT COUNT(*) FROM research_projects WHERE status IN ('completed', 'archived')" . ($has_deleted_at ? ' AND deleted_at IS NULL' : ''));
$count_stmt->execute();
$count_stmt->bind_result($archived_count);
$count_stmt->fetch();
$count_stmt->close();

$category_stmt = $conn->prepare('SELECT category_id, category_name FROM research_categories WHERE status = 1 ORDER BY category_name, category_id');
$categories = archive_fetch_all($category_stmt);
$category_stmt->close();

$year_stmt = $conn->prepare(
    "SELECT DISTINCT ay.ay_id, ay.label, ay.semester
     FROM academic_years ay
     INNER JOIN research_projects rp ON rp.ay_id = ay.ay_id
     WHERE ay.semester IN ('1st', '2nd')
       AND ay.label <= COALESCE(
           (SELECT MAX(current_ay.label) FROM academic_years current_ay WHERE current_ay.is_active = 1),
           ay.label
       )
       AND rp.status IN ('completed', 'archived'){$deleted_filter}
     ORDER BY ay.label DESC, FIELD(ay.semester, '1st', '2nd')"
);
$academic_years = archive_fetch_all($year_stmt);
$year_stmt->close();

$query = "
    SELECT
        rp.project_id,
        rp.title,
        rp.abstract,
        rp.research_area,
        rp.status,
        rp.updated_at,
        rc.category_name,
        ay.label AS academic_year,
        ay.semester,
        lead_user.first_name AS lead_first_name,
        lead_user.last_name AS lead_last_name,
        COALESCE(pm.co_member_count, 0) AS co_member_count
    FROM research_projects rp
    LEFT JOIN research_categories rc ON rc.category_id = rp.category_id
    LEFT JOIN academic_years ay ON ay.ay_id = rp.ay_id
    LEFT JOIN (
        SELECT
            project_id,
            MAX(CASE WHEN role = 'lead' THEN user_id END) AS lead_user_id,
            SUM(CASE WHEN role = 'member' THEN 1 ELSE 0 END) AS co_member_count
        FROM project_members
        GROUP BY project_id
    ) pm ON pm.project_id = rp.project_id
    LEFT JOIN users lead_user ON lead_user.user_id = COALESCE(pm.lead_user_id, rp.created_by)
    WHERE rp.status IN ('completed', 'archived')
            $deleted_filter
";

$parameters = [];
$types = '';

if ($search !== '') {
    $query .= ' AND (rp.title LIKE ? OR rp.abstract LIKE ?)';
    $search_term = '%' . $search . '%';
    $parameters[] = $search_term;
    $parameters[] = $search_term;
    $types .= 'ss';
}

if ($selected_category > 0) {
    $query .= ' AND rp.category_id = ?';
    $parameters[] = $selected_category;
    $types .= 'i';
}

if ($selected_ay > 0) {
    $query .= ' AND rp.ay_id = ?';
    $parameters[] = $selected_ay;
    $types .= 'i';
}

$query .= ' ORDER BY rp.updated_at DESC, rp.title ASC';
// @rms-ui: Add pagination controls when the public archive grows beyond the current result set.

$archive_stmt = $conn->prepare($query);
if (!empty($parameters)) {
    $archive_stmt->bind_param($types, ...$parameters);
}
$projects = archive_fetch_all($archive_stmt);
$archive_stmt->close();

$is_logged_in = isLoggedIn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Research Archive | Research Management System</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* @rms-ui: Keep the archive scannable while reusing the shared public palette. */
        .archive-hero { min-height: 56vh; }
        .archive-hero .hero-container { grid-template-columns: 1.2fr 0.8fr; }
        .archive-hero .hero-content p { max-width: 620px; }
        .archive-stat { display: inline-flex; gap: 8px; align-items: baseline; color: #D0D3E8; font-size: 0.9rem; }
        .archive-stat strong { color: white; font-family: 'Poppins', sans-serif; font-size: 1.5rem; }
        .archive-visual-icon { font-size: 7rem; filter: drop-shadow(0 12px 30px rgba(91, 30, 188, 0.35)); }
        .archive-section { background: linear-gradient(180deg, #fff 0%, #F0EBFF 100%); }
        .archive-filter { max-width: 1200px; margin: 0 auto 32px; }
        .archive-filter .card-body { display: grid; grid-template-columns: minmax(220px, 2fr) repeat(2, minmax(160px, 1fr)) auto auto; gap: 16px; align-items: end; }
        .archive-filter .form-group { margin-bottom: 0; }
        .archive-filter .form-label { color: var(--text-dark); }
        .archive-filter .form-control { background: #fff; border-color: var(--border); color: var(--text-dark); }
        .archive-filter .form-control::placeholder { color: var(--text-muted); }
        .archive-filter select.form-control option { color: var(--text-dark); }
        .archive-reset { color: var(--primary); font-size: 0.85rem; font-weight: 600; padding-bottom: 12px; }
        .archive-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 24px; max-width: 1200px; margin: 0 auto; align-items: start; }
        .archive-card { display: flex; flex-direction: column; min-width: 0; height: 100%; transition: transform .24s ease, box-shadow .24s ease, border-color .24s ease; }
        .archive-card:hover { transform: translateY(-3px); border-color: rgba(91, 30, 188, .28); box-shadow: 0 18px 42px rgba(45, 20, 84, .12); }
        .archive-card.is-expanded { grid-column: 1 / -1; height: auto; transform: none; border-color: rgba(91, 30, 188, .34); box-shadow: 0 22px 52px rgba(45, 20, 84, .14); }
        .archive-card .card-body { position: relative; display: flex; flex-direction: column; flex: 1; cursor: pointer; outline: none; }
        .archive-card .card-body:focus-visible { box-shadow: inset 0 0 0 3px rgba(91, 30, 188, .22); }
        .archive-card-title { color: var(--primary-dark); font-size: 1.1rem; line-height: 1.35; margin-bottom: 12px; }
        .archive-meta { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 16px; }
        .archive-badge { background: rgba(91, 30, 188, 0.1); color: var(--primary); border-radius: 50px; padding: 4px 10px; font-size: 0.72rem; font-weight: 600; }
        .archive-year { color: var(--text-muted); font-size: 0.78rem; }
        .archive-author { color: var(--text-light); font-size: 0.85rem; margin-bottom: 14px; }
        .archive-abstract { color: var(--text-light); font-size: 0.9rem; line-height: 1.65; margin-bottom: 20px; }
        .archive-published { display: flex; align-items: center; justify-content: space-between; gap: 14px; color: var(--text-muted); font-size: 0.78rem; margin-top: auto; padding-top: 16px; border-top: 1px solid var(--border); }
        .archive-expand-label { color: var(--primary); font-weight: 700; white-space: nowrap; }
        .archive-expand-label::after { content: '+'; display: inline-grid; width: 20px; height: 20px; margin-left: 7px; place-items: center; border-radius: 6px; background: rgba(91,30,188,.1); font-size: .9rem; transition: transform .2s ease, background .2s ease; }
        .archive-card.is-expanded .archive-expand-label::after { content: '\2212'; transform: rotate(180deg); background: rgba(91,30,188,.16); }
        .archive-card.is-expanded .archive-abstract { display: none; }
        .archive-expanded[hidden] { display: none; }
        .archive-expanded { margin: 4px 0 22px; padding: 24px; border: 1px solid rgba(91,30,188,.14); border-radius: 14px; background: linear-gradient(145deg, #fbf9ff, #fff); cursor: text; }
        .archive-expanded h3 { margin: 0 0 11px; color: var(--primary-dark); font-size: .76rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
        .archive-full-abstract { width: 100%; max-width: none; margin: 0; color: var(--text-light); font-size: .94rem; line-height: 1.8; text-align: justify; text-align-last: left; white-space: pre-wrap; }
        .archive-detail-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px; margin: 24px 0 0; overflow: hidden; border: 1px solid var(--border); border-radius: 11px; background: var(--border); }
        .archive-detail { min-width: 0; padding: 15px 16px; background: #fff; }
        .archive-detail span { display: block; margin-bottom: 5px; color: var(--text-muted); font-size: .68rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .archive-detail strong { display: block; color: var(--text-dark); font-size: .82rem; line-height: 1.45; overflow-wrap: anywhere; }
        .archive-empty { max-width: 700px; margin: 0 auto; text-align: center; padding: 56px 32px; }
        .archive-empty-icon { display: block; font-size: 2.5rem; margin-bottom: 12px; }
        @media (max-width: 1000px) {
            .archive-filter .card-body { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .archive-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 768px) {
            .archive-hero .hero-container { grid-template-columns: 1fr; }
            .archive-visual { display: none; }
            .archive-filter .card-body, .archive-grid { grid-template-columns: 1fr; }
            .archive-card.is-expanded { grid-column: auto; }
            .archive-detail-grid { grid-template-columns: 1fr; }
            .archive-filter .btn, .archive-reset { width: 100%; text-align: center; }
        }
        @media (prefers-reduced-motion: reduce) {
            .archive-card, .archive-expand-label::after { transition: none; }
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
            <li><a href="research-archive.php" class="active">Research Archive</a></li>
            <li><a href="contact.php">Contact</a></li>
        </ul>
        <div class="nav-actions">
            <a href="login.php" class="btn btn-secondary btn-sm">Login</a>
            <a href="login.php#register" class="btn btn-primary btn-sm">Register</a>
        </div>
    </nav>

    <section class="hero archive-hero">
        <div class="hero-container">
            <div class="hero-content hero-entrance">
                <h1>Research <span class="highlight">Archive</span></h1>
                <p>Browse completed and published research from EARIST students and faculty.</p>
                <div class="hero-actions">
                    <?php if ($is_logged_in): ?>
                        <a href="pages/student/submit-research.php" class="btn btn-primary btn-lg">+ Submit Research</a>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-primary btn-lg">Login to submit your research</a>
                    <?php endif; ?>
                </div>
                <div class="archive-stat"><strong><?php echo archive_escape($archived_count); ?></strong> research works archived</div>
            </div>
            <div class="hero-visual archive-visual" aria-hidden="true"><span class="archive-visual-icon">📚</span></div>
        </div>
    </section>

    <section class="section archive-section" data-reveal>
        <div class="card archive-filter">
            <div class="card-header">
                <div>
                    <div class="card-title">Find Research</div>
                    <div class="card-subtitle">Search the public catalog by title, abstract, category, or academic year.</div>
                </div>
            </div>
            <div class="card-body">
                <form method="GET" action="research-archive.php" style="display: contents;">
                    <div class="form-group">
                        <label class="form-label" for="search">Search</label>
                        <input class="form-control" type="search" id="search" name="search" placeholder="Search title or abstract" value="<?php echo archive_escape($search); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="category_id">Category</label>
                        <select class="form-control" id="category_id" name="category_id">
                            <option value="0">All categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo archive_escape($category['category_id']); ?>"<?php echo (int) $selected_category === (int) $category['category_id'] ? ' selected' : ''; ?>><?php echo archive_escape($category['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ay_id">Academic Year</label>
                        <select class="form-control" id="ay_id" name="ay_id">
                            <option value="0">All academic years</option>
                            <?php foreach ($academic_years as $academic_year): ?>
                                <option value="<?php echo archive_escape($academic_year['ay_id']); ?>"<?php echo (int) $selected_ay === (int) $academic_year['ay_id'] ? ' selected' : ''; ?>><?php echo archive_escape($academic_year['label'] . ' - ' . $academic_year['semester']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-primary" type="submit">Search</button>
                    <a class="archive-reset" href="research-archive.php">Reset</a>
                </form>
            </div>
        </div>

        <?php if (empty($projects)): ?>
            <div class="card archive-empty">
                <span class="archive-empty-icon">📭</span>
                <h2 class="card-title">No research works match your filters.</h2>
                <p style="color: var(--text-light); margin-top: 8px;">Try another search or reset the filters to browse the full archive.</p>
            </div>
        <?php else: ?>
            <div class="archive-grid">
                <?php foreach ($projects as $project): ?>
                    <?php
                    $abstract = trim((string) ($project['abstract'] ?? ''));
                    $abstract_preview = function_exists('mb_substr') ? mb_substr($abstract, 0, 150) : substr($abstract, 0, 150);
                    $abstract_length = function_exists('mb_strlen') ? mb_strlen($abstract) : strlen($abstract);
                    $lead_name = trim(($project['lead_first_name'] ?? '') . ' ' . ($project['lead_last_name'] ?? ''));
                    $published_year = date('Y', strtotime($project['updated_at']));
                    $detail_id = 'archive-details-' . (int) $project['project_id'];
                    ?>
                    <article class="card archive-card" data-archive-card data-research-title="<?php echo archive_escape($project['title']); ?>">
                        <div class="card-body" role="button" tabindex="0" aria-expanded="false" aria-controls="<?php echo archive_escape($detail_id); ?>" aria-label="Read full record for <?php echo archive_escape($project['title']); ?>">
                            <h2 class="archive-card-title"><?php echo archive_escape($project['title']); ?></h2>
                            <div class="archive-meta">
                                <span class="archive-badge"><?php echo archive_escape($project['category_name'] ?: 'Uncategorized'); ?></span>
                                <span class="archive-year"><?php echo archive_escape($project['academic_year'] ? $project['academic_year'] . ($project['semester'] ? ' · ' . $project['semester'] : '') : 'Academic year unavailable'); ?></span>
                            </div>
                            <p class="archive-author"><strong>Lead author:</strong> <?php echo archive_escape($lead_name ?: 'Author unavailable'); ?><?php if ((int) $project['co_member_count'] > 0): ?> + <?php echo archive_escape($project['co_member_count']); ?> co-author<?php echo (int) $project['co_member_count'] === 1 ? '' : 's'; ?><?php endif; ?></p>
                            <p class="archive-abstract"><?php echo archive_escape($abstract ? $abstract_preview . ($abstract_length > 150 ? '…' : '') : 'No abstract available.'); ?></p>
                            <div class="archive-expanded" id="<?php echo archive_escape($detail_id); ?>" hidden>
                                <h3>Complete abstract</h3>
                                <p class="archive-full-abstract"><?php echo archive_escape($abstract ?: 'No abstract available.'); ?></p>
                                <div class="archive-detail-grid" aria-label="Research information">
                                    <div class="archive-detail"><span>Research area</span><strong><?php echo archive_escape($project['research_area'] ?: 'Not specified'); ?></strong></div>
                                    <div class="archive-detail"><span>Category</span><strong><?php echo archive_escape($project['category_name'] ?: 'Uncategorized'); ?></strong></div>
                                    <div class="archive-detail"><span>Academic period</span><strong><?php echo archive_escape($project['academic_year'] ? $project['academic_year'] . ($project['semester'] ? ' · ' . $project['semester'] : '') : 'Unavailable'); ?></strong></div>
                                    <div class="archive-detail"><span>Lead author</span><strong><?php echo archive_escape($lead_name ?: 'Author unavailable'); ?></strong></div>
                                    <div class="archive-detail"><span>Research team</span><strong><?php echo (int) $project['co_member_count'] > 0 ? archive_escape($project['co_member_count']) . ' co-author' . ((int) $project['co_member_count'] === 1 ? '' : 's') : 'Lead author only'; ?></strong></div>
                                    <div class="archive-detail"><span>Repository status</span><strong><?php echo archive_escape(ucfirst((string) $project['status'])); ?></strong></div>
                                </div>
                            </div>
                            <div class="archive-published"><span>Year published: <?php echo archive_escape($published_year); ?></span><span class="archive-expand-label">Read full record</span></div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
                <p style="font-size: 0.85rem; color: #8B8FAD; display: flex; gap: 8px;"><span>📧</span> research@university.edu.ph</p>
                <p style="font-size: 0.85rem; color: #8B8FAD; display: flex; gap: 8px; margin-top: 8px;"><span>📞</span> +63 912 345 6789</p>
            </div>
        </div>
        <div class="footer-bottom">© 2024 Research Management System. All rights reserved.</div>
    </footer>
    <script>
    (() => {
        const cards = [...document.querySelectorAll('[data-archive-card]')];

        function setExpanded(card, expanded) {
            const body = card.querySelector('.card-body');
            const details = card.querySelector('.archive-expanded');
            const label = card.querySelector('.archive-expand-label');
            if (!body || !details) return;

            card.classList.toggle('is-expanded', expanded);
            body.setAttribute('aria-expanded', String(expanded));
            body.setAttribute('aria-label', `${expanded ? 'Collapse' : 'Read'} full record for ${card.dataset.researchTitle}`);
            details.hidden = !expanded;
            if (label) label.textContent = expanded ? 'Close record' : 'Read full record';

            if (expanded) {
                window.requestAnimationFrame(() => {
                    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    card.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'nearest' });
                });
            }
        }

        function toggleCard(card) {
            const shouldExpand = !card.classList.contains('is-expanded');
            cards.forEach((otherCard) => {
                if (otherCard !== card) setExpanded(otherCard, false);
            });
            setExpanded(card, shouldExpand);
        }

        cards.forEach((card) => {
            const body = card.querySelector('.card-body');
            body.addEventListener('click', (event) => {
                if (event.target.closest('.archive-expanded')) return;
                toggleCard(card);
            });
            body.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                toggleCard(card);
            });
        });
    })();
    </script>
<script src="../js/public-motion.js" defer></script>
</body>
</html>
