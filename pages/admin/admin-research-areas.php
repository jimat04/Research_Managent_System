<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-shell.php';
require_once __DIR__ . '/../../includes/research-workflow.php';

requireRole('admin');
$user = getCurrentUser();

$error = '';
$success = (string) ($_SESSION['research_classification_success'] ?? '');
unset($_SESSION['research_classification_success']);
$classification_url = SITE_URL . 'pages/admin/admin-research-areas.php';
$tab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'categories';
$tab = in_array($tab, ['categories', 'areas', 'mappings'], true) ? $tab : 'categories';
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$mapping_version = static function (array $ids): string {
    sort($ids, SORT_NUMERIC);
    return hash('sha256', implode(',', $ids));
};
$record_activity = static function (string $action): bool {
    try {
        return (bool) logActivity($action, 'research');
    } catch (Throwable $exception) {
        // A failed audit write must not report an already committed catalog save as rolled back.
        return false;
    }
};
// Reference codes documented in RESEARCH_AREA_REFINEMENT.md; numeric IDs stay unchanged.
$category_codes = [3 => 'RC001', 1 => 'RC002', 2 => 'RC003', 4 => 'RC004', 5 => 'RC005'];
$status_actions = [
    'delete_category' => ['entity' => 'category', 'active' => 0, 'tab' => 'categories'],
    'restore_category' => ['entity' => 'category', 'active' => 1, 'tab' => 'categories'],
    'delete_area' => ['entity' => 'area', 'active' => 0, 'tab' => 'areas'],
    'restore_area' => ['entity' => 'area', 'active' => 1, 'tab' => 'areas'],
];
$categories = [];
$areas = [];
$catalog_ready = false;
try {
    $category_stmt = $conn->prepare(
        'SELECT category_id, category_name, description, status FROM research_categories ORDER BY category_name'
    );
    if (!$category_stmt->execute()) {
        throw new RuntimeException('Unable to load research categories.');
    }
    $category_result = $category_stmt->get_result();
    while ($row = $category_result->fetch_assoc()) {
        $categories[(int) $row['category_id']] = $row;
    }
    $category_stmt->close();
    $areas = rms_research_area_catalog($conn, true);
    $catalog_ready = true;
} catch (Throwable $exception) {
    $error = 'Research classification could not be loaded. Please try again later.';
}
$area_ids = array_map('intval', array_column($areas, 'area_id'));
$areas_by_id = array_column($areas, null, 'area_id');
$selected_category_id = (int) filter_var($_GET['category_id'] ?? null, FILTER_VALIDATE_INT);
if (!isset($categories[$selected_category_id])) {
    $selected_category_id = (int) (array_key_first($categories) ?? 0);
}
$mapping_selection = null;
$status_action_key = is_string($_GET['change'] ?? null) ? $_GET['change'] : '';
$status_entry_id = (int) filter_var($_GET['entry_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $catalog_ready) {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session has expired. Refresh the page and try again.';
    } elseif (is_string($_POST['action'] ?? null) && isset($status_actions[$_POST['action']])) {
        $status_action_key = $_POST['action'];
        $status_action = $status_actions[$status_action_key];
        $tab = $status_action['tab'];
        $status_entry_id = (int) filter_var($_POST['entry_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $entry = $status_action['entity'] === 'category'
            ? ($categories[$status_entry_id] ?? null) : ($areas_by_id[$status_entry_id] ?? null);
        if (!$entry) {
            $error = 'The selected classification entry no longer exists. Reload the page.';
        } elseif (($_POST['confirm_status_change'] ?? null) !== '1') {
            $error = 'Confirm this classification change before continuing.';
        } else {
            try {
                // Existing inactive states preserve IDs, mappings and saved proposal references.
                $status_sql = $status_action['entity'] === 'category'
                    ? 'UPDATE research_categories SET status = ? WHERE category_id = ? AND status = ?'
                    : 'UPDATE research_areas SET is_active = ? WHERE area_id = ? AND is_active = ?';
                $new_status = $status_action['active'];
                $expected_status = 1 - $new_status;
                $status_stmt = $conn->prepare($status_sql);
                if (!$status_stmt) {
                    throw new RuntimeException('Unable to prepare the classification change.');
                }
                $status_stmt->bind_param('iii', $new_status, $status_entry_id, $expected_status);
                if (!$status_stmt->execute()) {
                    throw new RuntimeException('Unable to save the classification change.');
                }
                $changed = $status_stmt->affected_rows === 1;
                $status_stmt->close();
                if (!$changed) {
                    $error = 'This entry has already changed. Reload the page to see its current status.';
                } else {
                    $entry_name = $status_action['entity'] === 'category' ? $entry['category_name'] : $entry['area_code'] . ' - ' . $entry['area_name'];
                    $verb = $new_status === 1 ? 'Restored' : 'Deleted';
                    $success = $verb . ' ' . $entry_name . '. '
                        . ($new_status === 1 ? 'The entry is active again; its existing mappings and scope settings still apply.'
                            : 'The entry is now inactive for new proposals. Saved proposals and mappings were preserved. You can restore it at any time.');
                    if (!$record_activity($verb . ' research ' . $status_action['entity'] . ' (ID ' . $status_entry_id . '): ' . $entry_name)) {
                        $success .= ' The change was saved, but its audit log could not be confirmed.';
                    }
                    $_SESSION['research_classification_success'] = $success;
                    header('Location: ' . $classification_url . '?tab=' . $tab);
                    exit;
                }
            } catch (Throwable $exception) {
                $error = 'The classification status could not be changed. Reload the page and try again.';
            }
        }
    } elseif (($_POST['action'] ?? null) === 'save_mappings') {
        $tab = 'mappings';
        $posted_category_id = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
        $raw_area_ids = $_POST['area_ids'] ?? [];
        $mapping_selection = [];
        if (!$posted_category_id || !isset($categories[$posted_category_id])) {
            $error = 'Select an existing research category.';
        } elseif (!is_array($raw_area_ids) || count($raw_area_ids) > count($areas)) {
            $error = 'Select valid research areas.';
        } else {
            $selected_category_id = (int) $posted_category_id;
            foreach ($raw_area_ids as $raw_area_id) {
                $id = (is_string($raw_area_id) || is_int($raw_area_id)) && ctype_digit((string) $raw_area_id)
                    ? filter_var($raw_area_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
                if (!$id || !in_array($id, $area_ids, true) || in_array($id, $mapping_selection, true)) {
                    $error = 'Research areas must exist and cannot be selected more than once.';
                    break;
                }
                $mapping_selection[] = $id;
            }
        }
        if ($error === '' && ($_POST['confirm_mappings'] ?? null) !== '1') {
            $error = 'Confirm the mapping changes before saving.';
        }
        if ($error === '') {
            try {
                if (!$conn->begin_transaction()) {
                    throw new RuntimeException('Unable to start the mapping update.');
                }
                // Serialize mapping writers for this category, then reject an outdated view.
                $lock = $conn->prepare('SELECT category_id FROM research_categories WHERE category_id = ? FOR UPDATE');
                $lock->bind_param('i', $selected_category_id);
                if (!$lock->execute()) {
                    throw new RuntimeException('Unable to lock the category.');
                }
                if (!$lock->get_result()->fetch_assoc()) {
                    throw new InvalidArgumentException('This category no longer exists. Refresh the page.');
                }
                $lock->close();
                $current_stmt = $conn->prepare('SELECT area_id FROM category_research_areas WHERE category_id = ? FOR UPDATE');
                $current_stmt->bind_param('i', $selected_category_id);
                if (!$current_stmt->execute()) {
                    throw new RuntimeException('Unable to load current mappings.');
                }
                $current_ids = array_map('intval', array_column($current_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'area_id'));
                $current_stmt->close();
                if (!is_string($_POST['mapping_version'] ?? null)
                    || !hash_equals($mapping_version($current_ids), $_POST['mapping_version'])) {
                    throw new InvalidArgumentException('These mappings changed after you opened the page. Reload the category and review the current mappings before saving again.');
                }
                $removed_ids = array_diff($current_ids, $mapping_selection);
                $added_ids = array_diff($mapping_selection, $current_ids);
                $remove = $conn->prepare('DELETE FROM category_research_areas WHERE category_id = ? AND area_id = ?');
                foreach ($removed_ids as $id) {
                    $remove->bind_param('ii', $selected_category_id, $id);
                    if (!$remove->execute()) {
                        throw new RuntimeException('Unable to remove a mapping.');
                    }
                }
                $remove->close();
                $add = $conn->prepare('INSERT INTO category_research_areas (category_id, area_id) VALUES (?, ?)');
                foreach ($added_ids as $id) {
                    $add->bind_param('ii', $selected_category_id, $id);
                    if (!$add->execute()) {
                        throw new RuntimeException('Unable to add a mapping.');
                    }
                }
                $add->close();
                if (!$conn->commit()) {
                    throw new RuntimeException('Unable to commit the mapping update.');
                }
                $success = 'Category mappings saved: ' . count($added_ids) . ' added, ' . count($removed_ids) . ' removed. Existing proposal selections were preserved.';
                if (!$record_activity('Updated research classification mappings for category ' . $selected_category_id
                    . ' (' . count($added_ids) . ' added, ' . count($removed_ids) . ' removed)')) {
                    $success .= ' The save succeeded, but its audit log could not be confirmed.';
                }
                $_SESSION['research_classification_success'] = $success;
                header('Location: ' . $classification_url . '?tab=mappings&category_id=' . $selected_category_id);
                exit;
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = $exception instanceof InvalidArgumentException ? $exception->getMessage()
                    : 'The mappings could not be saved. No mapping changes were committed. Please reload and try again.';
            }
        }
    } else {
        $tab = 'areas';
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $area_name_input = $_POST['area_name'] ?? '';
        $description_input = $_POST['description'] ?? '';
        $area_name = is_string($area_name_input) ? trim($area_name_input) : '';
        $description = is_string($description_input) ? trim($description_input) : '';
        $label_input = $_POST['display_label'] ?? '';
        $display_label = is_string($label_input) ? trim($label_input) : '';
        $scope_input = $_POST['scope_enabled'] ?? null;
        $scope_enabled = $scope_input === null ? 0 : (in_array($scope_input, ['1', 1], true) ? 1 : -1);
        $area_id = filter_var($_POST['area_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $active_input = $_POST['is_active'] ?? null;
        $is_active = $active_input === null
            ? 0
            : (in_array($active_input, ['1', 1], true) ? 1 : -1);

        if ($scope_enabled < 0 || !is_string($label_input) || mb_strlen($display_label) > 180) {
            $error = 'Enter a valid curated label (up to 180 characters) and selection-scope status.';
        } elseif ($is_active < 0) {
            $error = 'The research-area active status is invalid.';
        } elseif (!is_string($area_name_input) || !is_string($description_input)) {
            $error = 'Research-area name and description must be text.';
        } elseif (!in_array($action, ['create', 'update'], true)) {
            $error = 'The requested catalog action is invalid.';
        } elseif ($area_name === '' || mb_strlen($area_name) > 180) {
            $error = 'Enter a research-area name of 1 to 180 characters.';
        } elseif (mb_strlen($description) > 5000) {
            $error = 'The description must not exceed 5,000 characters.';
        } elseif ($action === 'update' && (!$area_id || !in_array($area_id, $area_ids, true))) {
            $error = 'The selected research area is invalid.';
        }

        if ($error === '') {
            try {
                if (!$conn->begin_transaction()) {
                    throw new RuntimeException('Unable to start the catalog update.');
                }
                if ($action === 'create') {
                    $code_result = $conn->query(
                        "SELECT COALESCE(MAX(CAST(SUBSTRING(area_code, 3) AS UNSIGNED)), 0) + 1 AS next_code
                           FROM research_areas
                          WHERE area_code REGEXP '^RA[0-9]+$'"
                    );
                    if (!$code_result) {
                        throw new RuntimeException('Unable to allocate a research-area code: ' . $conn->error);
                    }
                    $next_code = (int) ($code_result->fetch_assoc()['next_code'] ?? 1);
                    $code_result->free();
                    $area_code = 'RA' . str_pad((string) $next_code, 3, '0', STR_PAD_LEFT);
                    $insert = $conn->prepare(
                        'INSERT INTO research_areas (area_code, area_name, description, is_active) VALUES (?, ?, NULLIF(?, \'\'), 1)'
                    );
                    if (!$insert) {
                        throw new RuntimeException('Unable to prepare research-area creation: ' . $conn->error);
                    }
                    $insert->bind_param('sss', $area_code, $area_name, $description);
                    if (!$insert->execute()) {
                        throw new RuntimeException('Unable to create research area: ' . $insert->error);
                    }
                    $area_id = (int) $insert->insert_id;
                    $insert->close();
                    $success = 'Research area ' . $area_code . ' was added.';
                } else {
                    $update = $conn->prepare(
                        'UPDATE research_areas SET area_name = ?, description = NULLIF(?, \'\'), is_active = ? WHERE area_id = ?'
                    );
                    if (!$update) {
                        throw new RuntimeException('Unable to prepare research-area update: ' . $conn->error);
                    }
                    $update->bind_param('ssii', $area_name, $description, $is_active, $area_id);
                    if (!$update->execute() || $update->affected_rows > 1) {
                        throw new RuntimeException('Unable to update the selected research area.');
                    }
                    $update->close();
                    $success = 'Research area settings were updated. Category mappings were preserved.';
                }

                $scope = $conn->prepare(
                    'INSERT INTO research_area_selection_scope (area_id, display_label, is_enabled)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE display_label = VALUES(display_label), is_enabled = VALUES(is_enabled)'
                );
                if (!$scope) {
                    throw new RuntimeException('Unable to prepare proposal-selection settings.');
                }
                $display_label = $display_label !== '' ? $display_label : $area_name;
                $scope->bind_param('isi', $area_id, $display_label, $scope_enabled);
                if (!$scope->execute()) {
                    throw new RuntimeException('Unable to save proposal-selection settings.');
                }
                $scope->close();
                if (!$conn->commit()) {
                    throw new RuntimeException('Unable to commit the catalog update.');
                }
                if (!$record_activity('Updated research-area catalog entry ' . $area_name)) {
                    $success .= ' The catalog update was saved, but its audit log could not be confirmed.';
                }
                $_SESSION['research_classification_success'] = $success;
                header('Location: ' . $classification_url . '?tab=areas');
                exit;
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = 'The research area could not be saved. Check for a duplicate name, then try again.';
                $success = '';
            }
        }
    }
}

$status_action = $status_actions[$status_action_key] ?? null;
$status_entry = null;
if ($status_action && $catalog_ready) {
    $tab = $status_action['tab'];
    $status_entry = $status_action['entity'] === 'category'
        ? ($categories[$status_entry_id] ?? null) : ($areas_by_id[$status_entry_id] ?? null);
    if (!$status_entry && $error === '') {
        $error = 'The selected classification entry no longer exists. Reload the page.';
    }
}
$mapped_area_ids = array_fill_keys(array_keys($categories), []);
foreach ($areas as $area) {
    foreach ($area['category_ids'] as $category_id) {
        $mapped_area_ids[$category_id][] = (int) $area['area_id'];
    }
}
$current_mapping = $mapped_area_ids[$selected_category_id] ?? [];
$mapping_selection = $mapping_selection ?? $current_mapping;
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$status_filter = is_string($_GET['area_status'] ?? null) ? $_GET['area_status'] : '';
$scope_filter = is_string($_GET['scope'] ?? null) ? $_GET['scope'] : '';
$status_filter = in_array($status_filter, ['0', '1'], true) ? $status_filter : '';
$scope_filter = in_array($scope_filter, ['0', '1'], true) ? $scope_filter : '';
$filtered_areas = array_filter($areas, static function (array $area) use ($search, $status_filter, $scope_filter): bool {
    return ($search === '' || mb_stripos($area['area_code'] . ' ' . $area['area_name'] . ' ' . $area['selection_label'], $search) !== false)
        && ($status_filter === '' || (int) $area['is_active'] === (int) $status_filter)
        && ($scope_filter === '' || (int) $area['scope_enabled'] === (int) $scope_filter);
});
$edit_id = (int) filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
if ($error !== '' && ($_POST['action'] ?? null) === 'update') {
    $edit_id = (int) filter_var($_POST['area_id'] ?? null, FILTER_VALIDATE_INT);
}
$editing_area = null;
foreach ($areas as $area) {
    if ((int) $area['area_id'] === $edit_id) {
        $editing_area = $area;
        break;
    }
}
$area_form = $editing_area ?? ['area_id' => 0, 'area_name' => '', 'description' => '', 'selection_label' => '', 'scope_enabled' => 0, 'is_active' => true];
if ($error !== '' && in_array($_POST['action'] ?? null, ['create', 'update'], true)) {
    foreach (['area_name', 'description', 'display_label'] as $field) {
        if (is_string($_POST[$field] ?? null)) {
            $area_form[$field === 'display_label' ? 'selection_label' : $field] = $_POST[$field];
        }
    }
    $area_form['scope_enabled'] = ($_POST['scope_enabled'] ?? null) === '1';
    $area_form['is_active'] = ($_POST['is_active'] ?? null) === '1';
}

renderAdminShell($user, 'admin-research-areas.php', 'Research Classification', 'Institute Administration · Categories, research areas and proposal selection.');
?>
<link rel="stylesheet" href="<?php echo $escape(SITE_URL . 'css/research-classification.css'); ?>">
<section id="classification-top" class="classification-workspace" aria-label="Research Classification">
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger" role="alert"><?php echo $escape($error); ?></div>
  <?php elseif ($success !== ''): ?>
    <div class="alert alert-success" role="status"><?php echo $escape($success); ?></div>
  <?php endif; ?>
  <div class="card classification-intro">
    <div>
      <h2>Manage research classification</h2>
      <p>Configure the categories and areas available on proposal forms. Saved proposals retain their existing area selections.</p>
      <?php if ($catalog_ready): ?>
        <p class="classification-totals"><?php echo count($categories); ?> categories <span aria-hidden="true">·</span> <?php echo count($areas); ?> research areas <span aria-hidden="true">·</span> <?php echo count(array_filter($areas, static function (array $area): bool { return (int) $area['scope_enabled'] === 1; })); ?> enabled in BSIT/BSCS scope</p>
      <?php endif; ?>
    </div>
    <a class="btn btn-secondary" href="<?php echo $escape(SITE_URL . 'pages/admin/admin-dashboard.php'); ?>">Back to dashboard</a>
  </div>
  <nav class="classification-tabs" aria-label="Classification views">
    <?php foreach (['categories' => 'Research Categories', 'areas' => 'Research Areas', 'mappings' => 'Category-to-Area Mapping'] as $key => $label): ?>
      <a href="<?php echo $escape($classification_url . '?tab=' . $key); ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo $escape($label); ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($status_entry):
    $restoring = $status_action['active'] === 1;
    $status_entry_name = $status_action['entity'] === 'category'
        ? $status_entry['category_name'] : $status_entry['area_code'] . ' - ' . $status_entry['area_name'];
  ?>
    <section id="classification-confirm" class="card classification-confirm" aria-labelledby="classification-confirm-title">
      <h3 id="classification-confirm-title"><?php echo $restoring ? 'Restore' : 'Delete'; ?> research <?php echo $escape($status_action['entity']); ?></h3>
      <p><strong><?php echo $escape($status_entry_name); ?></strong></p>
      <p><?php echo $restoring
          ? 'Restoring makes this entry active again. Its existing category mappings and area selection settings will apply.'
          : 'Deleting makes this entry inactive for new proposals. Its record, mappings and saved proposal selections are preserved. You can restore it later.'; ?></p>
      <form method="post" action="<?php echo $escape($classification_url . '?tab=' . $tab . '&change=' . $status_action_key . '&entry_id=' . $status_entry_id . '#classification-confirm'); ?>" class="classification-form" data-saving-form>
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="<?php echo $escape($status_action_key); ?>">
        <input type="hidden" name="entry_id" value="<?php echo $status_entry_id; ?>">
        <label class="classification-check"><input type="checkbox" name="confirm_status_change" value="1" required> I confirm that I want to <?php echo $restoring ? 'restore' : 'delete'; ?> this research <?php echo $escape($status_action['entity']); ?>.</label>
        <div class="classification-actions">
          <button class="btn <?php echo $restoring ? 'btn-secondary' : 'classification-delete'; ?>" type="submit"><?php echo $restoring ? 'Confirm restore' : 'Confirm delete'; ?></button>
          <a class="btn btn-secondary" href="<?php echo $escape($classification_url . '?tab=' . $tab); ?>">Cancel</a>
        </div>
        <p class="classification-saving" role="status" hidden>Saving classification status…</p>
      </form>
    </section>
  <?php endif; ?>
  <?php if ($catalog_ready && $tab === 'categories'): ?>
    <div class="card">
      <h3>Research categories</h3>
      <p>The predefined categories keep their original IDs. RC codes are reference labels; manage their area assignments through the mapping view.</p>
      <p>Delete makes a category inactive for new proposals. Inactive categories stay listed here with a Restore action.</p>
      <div class="table-wrap" tabindex="0" role="region" aria-label="Research categories table">
        <table>
          <caption class="classification-sr-only">All research categories and current mapping counts</caption>
          <thead><tr><th scope="col">Reference code / ID</th><th scope="col">Category</th><th scope="col">Description</th><th scope="col">Status</th><th scope="col">Mapped areas</th><th scope="col">Manage</th></tr></thead>
          <tbody>
          <?php foreach ($categories as $category): $id = (int) $category['category_id']; ?>
            <tr>
              <td><strong><?php echo $escape($category_codes[$id] ?? '—'); ?></strong><br><span class="classification-muted">ID <?php echo $id; ?></span></td>
              <th scope="row"><?php echo $escape($category['category_name']); ?></th>
              <td><?php echo $escape($category['description'] ?: 'No description provided.'); ?></td>
              <td><span class="classification-badge"><?php echo (int) $category['status'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
              <td><?php echo count($mapped_area_ids[$id] ?? []); ?></td>
              <td><div class="classification-actions">
                <a class="btn btn-secondary btn-sm" href="<?php echo $escape($classification_url . '?tab=mappings&category_id=' . $id); ?>">Manage mappings<span class="classification-sr-only"> for <?php echo $escape($category['category_name']); ?></span></a>
                <a class="btn btn-sm <?php echo (int) $category['status'] === 1 ? 'classification-delete' : 'btn-secondary'; ?>" href="<?php echo $escape($classification_url . '?tab=categories&change=' . ((int) $category['status'] === 1 ? 'delete_category' : 'restore_category') . '&entry_id=' . $id . '#classification-confirm'); ?>"><?php echo (int) $category['status'] === 1 ? 'Delete' : 'Restore'; ?><span class="classification-sr-only"> <?php echo $escape($category['category_name']); ?></span></a>
              </div></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$categories): ?><tr><td colspan="6">No research categories are configured.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php elseif ($catalog_ready && $tab === 'areas'): ?>
    <div class="card">
      <div class="card-header">
        <h3>Research areas</h3>
        <a class="btn btn-primary" href="<?php echo $escape($classification_url . '?tab=areas&add=1#area-settings'); ?>"<?php echo !$editing_area ? ' data-area-form-link' : ''; ?>>Add research area</a>
      </div>
      <p>All original catalog records remain available here. Proposal eligibility requires both an active area and enabled BSIT/BSCS scope, plus a mapping to the selected category.</p>
      <p>Delete makes an area inactive for new proposals. Use the Inactive status filter to find and restore it.</p>
      <form method="get" action="<?php echo $escape($classification_url); ?>" class="classification-filters">
        <input type="hidden" name="tab" value="areas">
        <label for="area-search">Search code, name or label<input id="area-search" type="search" name="q" value="<?php echo $escape($search); ?>"></label>
        <label for="area-status">Active status<select id="area-status" name="area_status"><option value="">All statuses</option><option value="1" <?php echo $status_filter === '1' ? 'selected' : ''; ?>>Active</option><option value="0" <?php echo $status_filter === '0' ? 'selected' : ''; ?>>Inactive</option></select></label>
        <label for="area-scope">BSIT/BSCS scope<select id="area-scope" name="scope"><option value="">All areas</option><option value="1" <?php echo $scope_filter === '1' ? 'selected' : ''; ?>>Enabled curated areas</option><option value="0" <?php echo $scope_filter === '0' ? 'selected' : ''; ?>>Outside enabled scope</option></select></label>
        <button class="btn btn-primary" type="submit">Apply filters</button>
        <a class="btn btn-secondary" href="<?php echo $escape($classification_url . '?tab=areas'); ?>">Clear filters</a>
      </form>
      <p><?php echo count($filtered_areas); ?> of <?php echo count($areas); ?> areas shown</p>
      <div class="table-wrap" tabindex="0" role="region" aria-label="Research areas table">
        <table>
          <caption class="classification-sr-only">Research area codes, original names, curated labels and eligibility settings</caption>
          <thead><tr><th scope="col">Code</th><th scope="col">Original name</th><th scope="col">Curated display label</th><th scope="col">Active status</th><th scope="col">BSIT/BSCS selection</th><th scope="col">Configure</th></tr></thead>
          <tbody>
          <?php foreach ($filtered_areas as $area): ?>
            <tr>
              <th scope="row"><?php echo $escape($area['area_code']); ?></th>
              <td><?php echo $escape($area['area_name']); ?></td>
              <td><?php echo $escape($area['display_label'] ?? '—'); ?></td>
              <td><span class="classification-badge"><?php echo $area['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
              <td><span class="classification-badge<?php echo $area['proposal_eligible'] ? ' is-enabled' : ''; ?>"><?php echo $area['proposal_eligible'] ? 'Eligible' : 'Not eligible'; ?></span><br><span class="classification-muted"><?php echo (int) $area['scope_enabled'] === 1 ? 'Scope enabled' : 'Scope disabled'; ?> · <?php echo count($area['category_ids']); ?> categories</span></td>
              <td><div class="classification-actions">
                <a class="btn btn-secondary btn-sm" href="<?php echo $escape($classification_url . '?tab=areas&edit=' . (int) $area['area_id'] . '#area-settings'); ?>">Edit<span class="classification-sr-only"> <?php echo $escape($area['area_code']); ?></span></a>
                <a class="btn btn-sm <?php echo $area['is_active'] ? 'classification-delete' : 'btn-secondary'; ?>" href="<?php echo $escape($classification_url . '?tab=areas&change=' . ($area['is_active'] ? 'delete_area' : 'restore_area') . '&entry_id=' . (int) $area['area_id'] . '#classification-confirm'); ?>"><?php echo $area['is_active'] ? 'Delete' : 'Restore'; ?><span class="classification-sr-only"> <?php echo $escape($area['area_code']); ?></span></a>
              </div></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$filtered_areas): ?><tr><td colspan="6">No research areas match these filters. Clear the filters to view the complete catalog.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <details id="area-settings" class="card classification-editor" <?php echo $editing_area || ($_GET['add'] ?? null) === '1' || ($error !== '' && ($_POST['action'] ?? null) === 'create') ? 'open' : ''; ?>>
      <summary><?php echo $editing_area ? 'Configure ' . $escape($editing_area['area_code']) : 'Add a research area'; ?></summary>
      <form method="post" action="<?php echo $escape($classification_url . '?tab=areas' . ($editing_area ? '&edit=' . (int) $editing_area['area_id'] : '')); ?>" class="classification-form" data-saving-form>
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="<?php echo $editing_area ? 'update' : 'create'; ?>">
        <input type="hidden" name="area_id" value="<?php echo (int) $area_form['area_id']; ?>">
        <label for="area-name">Original catalog name<input id="area-name" name="area_name" maxlength="180" value="<?php echo $escape($area_form['area_name']); ?>" required></label>
        <label for="area-label">Curated display label<input id="area-label" name="display_label" maxlength="180" value="<?php echo $escape($area_form['selection_label']); ?>" aria-describedby="area-label-help"></label>
        <p id="area-label-help">Leave blank to use the catalog name. Use this label to refine the wording researchers see.</p>
        <label for="area-description">Description<textarea id="area-description" name="description" maxlength="5000" rows="3"><?php echo $escape($area_form['description']); ?></textarea></label>
        <label class="classification-check"><input type="checkbox" name="scope_enabled" value="1" <?php echo $area_form['scope_enabled'] ? 'checked' : ''; ?>> Include in BSIT/BSCS proposal selection</label>
        <?php if ($editing_area): ?>
          <label class="classification-check"><input type="checkbox" name="is_active" value="1" <?php echo $area_form['is_active'] ? 'checked' : ''; ?>> Active for new proposals</label>
        <?php endif; ?>
        <p>Manage category assignments in the <a href="<?php echo $escape($classification_url . '?tab=mappings'); ?>">Category-to-Area Mapping tab</a>. Saving these settings preserves existing mappings and proposal selections.</p>
        <div class="classification-actions"><button class="btn btn-primary" type="submit"><?php echo $editing_area ? 'Save area settings' : 'Add research area'; ?></button><a class="btn btn-secondary" href="<?php echo $escape($classification_url . '?tab=areas'); ?>">Cancel</a></div>
        <p class="classification-saving" role="status" hidden>Saving area settings…</p>
      </form>
    </details>
    <div class="classification-actions classification-bottom-navigation">
      <a class="btn btn-secondary" href="#classification-top">Back to top</a>
    </div>
  <?php elseif ($catalog_ready && $tab === 'mappings'): ?>
    <div class="card">
      <h3>Category-to-area mapping</h3>
      <p>An area may belong to multiple categories. Mapping an inactive or scope-disabled area does not make it eligible for new proposals.</p>
      <form method="get" action="<?php echo $escape($classification_url); ?>" class="classification-filters" data-category-form>
        <input type="hidden" name="tab" value="mappings">
        <label for="mapping-category">Research category<select id="mapping-category" name="category_id" required>
          <?php foreach ($categories as $category): $id = (int) $category['category_id']; ?>
            <option value="<?php echo $id; ?>" <?php echo $id === $selected_category_id ? 'selected' : ''; ?>><?php echo $escape(($category_codes[$id] ?? 'ID ' . $id) . ' · ' . $category['category_name'] . ((int) $category['status'] !== 1 ? ' (inactive)' : '')); ?></option>
          <?php endforeach; ?>
        </select></label>
        <button class="btn btn-secondary" type="submit">Load mappings</button>
      </form>
      <?php if ($selected_category_id && $areas): ?>
      <?php if ((int) $categories[$selected_category_id]['status'] !== 1): ?>
        <p>This category is inactive for new proposals. Its mappings remain available for administration and saved projects.</p>
      <?php endif; ?>
      <form method="post" action="<?php echo $escape($classification_url . '?tab=mappings&category_id=' . $selected_category_id); ?>" class="classification-form" id="mapping-form" data-saving-form>
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save_mappings">
        <input type="hidden" name="category_id" value="<?php echo $selected_category_id; ?>">
        <input type="hidden" name="mapping_version" value="<?php echo $escape($mapping_version($current_mapping)); ?>">
        <fieldset>
          <legend>Areas for <?php echo $escape($categories[$selected_category_id]['category_name']); ?></legend>
          <p><?php echo count($current_mapping); ?> current mappings. Checked areas will be assigned when you save.</p>
          <div class="classification-mapping-list">
          <?php foreach ($areas as $area): $id = (int) $area['area_id']; $current = in_array($id, $current_mapping, true); $checked = in_array($id, $mapping_selection, true); ?>
            <label class="classification-mapping-option" data-current="<?php echo $current ? '1' : '0'; ?>">
              <input type="checkbox" name="area_ids[]" value="<?php echo $id; ?>" <?php echo $checked ? 'checked' : ''; ?>>
              <span><strong><?php echo $escape($area['area_code'] . ' · ' . $area['selection_label']); ?></strong><span class="classification-muted"><?php echo $escape($area['area_name']); ?> · <?php echo $area['proposal_eligible'] && (int) $categories[$selected_category_id]['status'] === 1 ? 'Eligible for new selections' : 'Not eligible for new selections'; ?></span></span>
              <span class="classification-mapping-state"><?php echo $current ? 'Currently mapped' : 'Not mapped'; ?><br><span data-change-label><?php echo $current === $checked ? 'Unchanged' : ($checked ? 'Will add' : 'Will remove'); ?></span></span>
            </label>
          <?php endforeach; ?>
          </div>
        </fieldset>
        <p id="mapping-changes" role="status" aria-live="polite">Review the checkboxes and confirm before saving. Changes remain unsaved until you save mappings.</p>
        <label class="classification-check"><input type="checkbox" name="confirm_mappings" value="1" required> I confirm these mapping changes, including any removals. Removing a mapping affects new selections; existing proposals keep their saved areas.</label>
        <div class="classification-actions"><button class="btn btn-primary" type="submit">Save mappings</button><a class="btn btn-secondary" href="<?php echo $escape($classification_url . '?tab=mappings&category_id=' . $selected_category_id); ?>">Discard changes</a></div>
        <p class="classification-saving" role="status" hidden>Saving mappings…</p>
      </form>
      <?php else: ?><p>No categories or research areas are available to map.</p><?php endif; ?>
    </div>
  <?php endif; ?>
</section>
<script src="<?php echo $escape(SITE_URL . 'js/research-classification.js'); ?>" defer></script>
<?php renderAdminShellClose(); ?>
