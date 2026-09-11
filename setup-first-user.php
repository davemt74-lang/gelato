<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$configError = null;
$databaseError = null;
$schemaInstalled = false;
$ownerExists = false;
$errors = [];
$success = false;
$pdo = null;

if (!app_has_config()) {
    $configError = 'config.php was not found.';
} else {
    try {
        $config = app_config();
        date_default_timezone_set((string)($config['app']['timezone'] ?? 'UTC'));
        app_boot_session();
        $pdo = app_pdo();
        $schemaInstalled = app_schema_is_installed($pdo);
        $ownerExists = $schemaInstalled && app_owner_exists($pdo);
    } catch (Throwable $exception) {
        $databaseError = $exception->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO && $schemaInstalled && !$ownerExists) {
    $input = [
        'restaurant_name' => trim((string)($_POST['restaurant_name'] ?? '')),
        'legal_name' => trim((string)($_POST['legal_name'] ?? '')),
        'location_name' => trim((string)($_POST['location_name'] ?? 'Main Location')),
        'timezone' => trim((string)($_POST['timezone'] ?? 'America/Phoenix')),
        'first_name' => trim((string)($_POST['first_name'] ?? '')),
        'last_name' => trim((string)($_POST['last_name'] ?? '')),
        'email' => strtolower(trim((string)($_POST['email'] ?? ''))),
        'password' => (string)($_POST['password'] ?? ''),
        'password_confirm' => (string)($_POST['password_confirm'] ?? ''),
    ];

    if (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The setup form expired. Refresh the page and try again.';
    }
    if ($input['restaurant_name'] === '' || mb_strlen($input['restaurant_name']) > 160) {
        $errors[] = 'Enter a restaurant name of 160 characters or fewer.';
    }
    if ($input['first_name'] === '' || $input['last_name'] === '') {
        $errors[] = 'Enter the owner’s first and last name.';
    }
    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid owner email address.';
    }
    if (strlen($input['password']) < 12
        || !preg_match('/[A-Z]/', $input['password'])
        || !preg_match('/[a-z]/', $input['password'])
        || !preg_match('/\d/', $input['password'])
        || !preg_match('/[^A-Za-z0-9]/', $input['password'])) {
        $errors[] = 'Use at least 12 characters with uppercase, lowercase, a number, and a symbol.';
    }
    if ($input['password'] !== $input['password_confirm']) {
        $errors[] = 'The password confirmation does not match.';
    }
    try {
        new DateTimeZone($input['timezone']);
    } catch (Throwable) {
        $errors[] = 'Enter a valid PHP timezone, such as America/Phoenix.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            if (app_owner_exists($pdo)) {
                throw new RuntimeException('The first Super Admin has already been created.');
            }

            $statement = $pdo->prepare("INSERT INTO organizations (name, legal_name, status, timezone) VALUES (?, ?, 'active', ?)");
            $statement->execute([$input['restaurant_name'], $input['legal_name'] ?: null, $input['timezone']]);
            $organizationId = (int)$pdo->lastInsertId();

            $statement = $pdo->prepare("INSERT INTO locations (organization_id, name, status) VALUES (?, ?, 'active')");
            $statement->execute([$organizationId, $input['location_name'] ?: 'Main Location']);
            $locationId = (int)$pdo->lastInsertId();

            $displayName = trim($input['first_name'] . ' ' . $input['last_name']);
            $passwordAlgorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            $passwordHash = password_hash($input['password'], $passwordAlgorithm);
            if (!is_string($passwordHash)) {
                throw new RuntimeException('Unable to hash the owner password.');
            }

            $statement = $pdo->prepare(
                "INSERT INTO users
                (email, password_hash, first_name, last_name, display_name, status, email_verified_at, password_changed_at)
                VALUES (?, ?, ?, ?, ?, 'active', NOW(6), NOW(6))"
            );
            $statement->execute([$input['email'], $passwordHash, $input['first_name'], $input['last_name'], $displayName]);
            $userId = (int)$pdo->lastInsertId();

            $statement = $pdo->prepare(
                "INSERT INTO organization_memberships
                (organization_id, user_id, primary_location_id, job_title, status)
                VALUES (?, ?, ?, 'Store Owner', 'active')"
            );
            $statement->execute([$organizationId, $userId, $locationId]);
            $membershipId = (int)$pdo->lastInsertId();

            $roles = [
                'super_admin' => ['Super Admin', 'Store owner with unrestricted organization access.', 1, 1],
                'manager' => ['Manager', 'Manages employees, training progress, certifications, and hiring.', 1, 0],
                'employee' => ['Employee', 'Completes assigned training and reviews personal progress.', 1, 0],
            ];
            $roleIds = [];
            $roleStatement = $pdo->prepare(
                "INSERT INTO roles
                (organization_id, name, slug, description, is_system_role, is_owner_role, is_assignable)
                VALUES (?, ?, ?, ?, ?, ?, 1)"
            );
            foreach ($roles as $slug => [$name, $description, $system, $owner]) {
                $roleStatement->execute([$organizationId, $name, $slug, $description, $system, $owner]);
                $roleIds[$slug] = (int)$pdo->lastInsertId();
            }

            $statement = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions");
            $statement->execute([$roleIds['super_admin']]);

            $managerPermissions = [
                'dashboard.view','agent.employee_view','users.view','users.create','users.edit','users.suspend','users.assign_roles',
                'roles.view','training.self_view','training.assign','training.view_employee_progress','training.issue_certifications',
                'resumes.view','resumes.review','resumes.add_notes','resumes.convert_to_employee','jobs.view','jobs.create','jobs.edit','jobs.publish','forms.view','public_pages.view',
                'brand.view','reports.view','settings.self_edit'
            ];
            $employeePermissions = ['dashboard.view','agent.employee_view','training.self_view','settings.self_edit'];
            $grantStatement = $pdo->prepare(
                "INSERT INTO role_permissions (role_id, permission_id)
                 SELECT ?, id FROM permissions WHERE permission_key = ?"
            );
            foreach ($managerPermissions as $permission) {
                $grantStatement->execute([$roleIds['manager'], $permission]);
            }
            foreach ($employeePermissions as $permission) {
                $grantStatement->execute([$roleIds['employee'], $permission]);
            }

            $statement = $pdo->prepare("INSERT INTO user_roles (membership_id, role_id, location_id, assigned_by) VALUES (?, ?, NULL, ?)");
            $statement->execute([$membershipId, $roleIds['super_admin'], $userId]);

            $positions = [
                ['Store Owner','store_owner','Owns the store, security, hiring, and organization-wide training oversight.'],
                ['Shift Manager','shift_manager','Runs assigned shifts and manages team readiness.'],
                ['Trainer','trainer','Coaches employees and verifies position readiness.'],
                ['Host / Counter','host_counter','Greets guests and handles basic menu and counter questions.'],
                ['Server','server','Takes orders, explains the menu, and handles guest requests.'],
                ['Bartender','bartender','Handles bar service and menu recommendations.'],
                ['Cashier','cashier','Builds accurate orders and handles pricing and payment.'],
                ['Pizza Cook','pizza_cook','Builds pizzas, crusts, toppings, and specialty recipes.'],
                ['Line Cook','line_cook','Prepares non-pizza menu items and verifies recipes.'],
                ['Prep Cook','prep_cook','Prepares ingredients, sauces, and portions.'],
                ['Expo','expo','Verifies completed tickets, sides, sauces, and modifications.'],
                ['Food Runner','food_runner','Identifies and delivers complete orders.'],
                ['Delivery Driver','delivery_driver','Verifies packaged orders and customer handoff.'],
            ];
            $positionStatement = $pdo->prepare("INSERT INTO positions (organization_id, name, slug, description, status) VALUES (?, ?, ?, ?, 'active')");
            $storeOwnerPositionId = null;
            foreach ($positions as [$name, $slug, $description]) {
                $positionStatement->execute([$organizationId, $name, $slug, $description]);
                if ($slug === 'store_owner') {
                    $storeOwnerPositionId = (int)$pdo->lastInsertId();
                }
            }
            if ($storeOwnerPositionId) {
                $statement = $pdo->prepare("INSERT INTO user_positions (membership_id, position_id, is_primary, status, assigned_by) VALUES (?, ?, 1, 'active', ?)");
                $statement->execute([$membershipId, $storeOwnerPositionId, $userId]);
            }

            $statement = $pdo->prepare(
                "INSERT INTO brand_settings
                (organization_id, restaurant_name, legal_name, updated_by)
                VALUES (?, ?, ?, ?)"
            );
            $statement->execute([$organizationId, $input['restaurant_name'], $input['legal_name'] ?: null, $userId]);

            $statement = $pdo->prepare(
                "INSERT INTO public_pages
                (organization_id, page_type, title, slug, seo_title, seo_description, status, version, published_version, created_by, updated_by, published_at)
                VALUES (?, 'landing', ?, 'home', ?, ?, 'published', 1, 1, ?, ?, NOW(6))"
            );
            $pageTitle = $input['restaurant_name'] . ' Careers';
            $statement->execute([$organizationId, $pageTitle, $pageTitle, 'Learn about the restaurant and submit a resume.', $userId, $userId]);
            $pageId = (int)$pdo->lastInsertId();
            $sectionStatement = $pdo->prepare(
                "INSERT INTO public_page_sections (page_id, section_type, heading, content_json, settings_json, sort_order, is_visible)
                 VALUES (?, ?, ?, ?, ?, ?, 1)"
            );
            $sectionStatement->execute([$pageId, 'hero', 'Build your future with us.', json_encode(['text' => 'Join a team that values menu knowledge, service, and continuous training.','button_text' => 'Submit Resume','button_href' => 'apply.html'], JSON_THROW_ON_ERROR), json_encode([], JSON_THROW_ON_ERROR), 10]);
            $sectionStatement->execute([$pageId, 'training', 'Training built around the real menu.', json_encode(['text' => 'Employees learn through study guides, flashcards, daily quizzes, order simulations, and certifications.','button_text' => 'Employee Login','button_href' => 'login.php'], JSON_THROW_ON_ERROR), json_encode([], JSON_THROW_ON_ERROR), 20]);

            $statement = $pdo->prepare(
                "INSERT INTO forms
                (organization_id, form_type, name, slug, description, status, version, created_by, updated_by, published_at)
                VALUES (?, 'resume_application', 'Resume Application', 'resume-application', 'Public employment application and resume form.', 'published', 1, ?, ?, NOW(6))"
            );
            $statement->execute([$organizationId, $userId, $userId]);
            $formId = (int)$pdo->lastInsertId();
            $statement = $pdo->prepare("INSERT INTO form_sections (form_id, title, description, sort_order) VALUES (?, 'Applicant Information', 'Tell us how to contact you and which position interests you.', 10)");
            $statement->execute([$formId]);
            $formSectionId = (int)$pdo->lastInsertId();
            $fieldStatement = $pdo->prepare(
                "INSERT INTO form_fields
                (form_id, section_id, field_key, field_type, label, placeholder, is_required, is_system_field, settings_json, validation_json, conditional_rules_json, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)"
            );
            $fields = [
                ['first_name','short_text','First name','First name',1,10],
                ['last_name','short_text','Last name','Last name',1,20],
                ['email','email','Email address','you@example.com',1,30],
                ['phone','phone','Phone number','Phone number',0,40],
                ['position_interest','short_text','Position of interest','Server, cook, manager…',1,50],
                ['availability_summary','long_text','Availability','Days, evenings, weekends…',1,60],
                ['experience_summary','long_text','Relevant experience','Tell us about your restaurant or customer-service experience.',0,70],
                ['resume_file','file','Resume','Upload PDF, DOC, or DOCX',0,80],
                ['consent','consent','Application consent','',1,90],
            ];
            foreach ($fields as [$key, $type, $label, $placeholder, $required, $order]) {
                $settings = $type === 'file' ? ['allowed_extensions' => ['pdf','doc','docx'], 'max_size_mb' => 8] : [];
                $fieldStatement->execute([$formId, $formSectionId, $key, $type, $label, $placeholder ?: null, $required, json_encode($settings, JSON_THROW_ON_ERROR), json_encode([], JSON_THROW_ON_ERROR), json_encode([], JSON_THROW_ON_ERROR), $order]);
            }

            $statement = $pdo->prepare(
                "INSERT INTO agent_profiles
                (organization_id, agent_type, name, description, access_scope, status, settings_json)
                VALUES (?, 'owner_system_agent', 'Owner System Agent', 'Tracks employee training progress, certifications, inactivity, and new resume submissions.', 'organization', 'active', ?)"
            );
            $statement->execute([$organizationId, json_encode(['daily_briefing' => true], JSON_THROW_ON_ERROR)]);
            $agentProfileId = (int)$pdo->lastInsertId();
            $ruleStatement = $pdo->prepare(
                "INSERT INTO agent_rules
                (organization_id, agent_profile_id, rule_key, name, description, condition_json, severity, message_template, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)"
            );
            $rules = [
                ['new_resume_received','New resume received','Surfaces newly submitted resumes.',['resume_status' => 'new'],'info','A new resume was submitted and is ready for review.'],
                ['employee_training_overdue','Training overdue','Finds active training assignments past their due date.',['assignment_status' => 'assigned','past_due' => true],'warning','An employee has overdue training.'],
                ['quiz_score_below_threshold','Low quiz score','Flags completed quizzes below the configured threshold.',['score_below' => 80],'warning','An employee scored below the training threshold.'],
                ['employee_ready_for_certification','Certification readiness','Finds employees who completed position requirements.',['requirements_complete' => true],'success','An employee appears ready for certification review.'],
            ];
            foreach ($rules as [$key, $name, $description, $condition, $severity, $message]) {
                $ruleStatement->execute([$organizationId, $agentProfileId, $key, $name, $description, json_encode($condition, JSON_THROW_ON_ERROR), $severity, $message]);
            }

            $jobStatement = $pdo->prepare("INSERT INTO jobs (organization_id, public_id, slug, title, department, location_name, employment_type, schedule_text, pay_range, summary, description, responsibilities_json, requirements_json, benefits_json, status, published_at, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', NOW(6), ?, ?)");
            $starterJobs = [
                ['job-server','server','Server','Front of House','Phoenix, Arizona','Full-time or part-time','Day, evening, and weekend shifts','Hourly wage plus tips','Guide guests through the menu, deliver accurate service, and maintain a welcoming dining room.','Servers create a clear, friendly guest experience while protecting menu accuracy and food-safety communication.', ['Learn the complete menu and current specials','Enter orders accurately and communicate modifications','Follow allergen escalation and service procedures'], ['Reliable attendance','Clear communication','Food Handler Card or ability to obtain one'], ['Structured menu training','Position certification path']],
                ['job-pizza-cook','pizza-cook','Pizza Cook','Kitchen','Phoenix, Arizona','Full-time or part-time','Evening and weekend availability preferred','Competitive hourly wage','Prepare pizzas to specification, manage oven timing, and protect recipe consistency.','Pizza cooks own dough, sauce, cheese, topping, and oven accuracy during high-volume service.', ['Build pizzas from approved recipes','Verify modifiers and allergen-sensitive tickets','Maintain station sanitation and ingredient rotation'], ['Kitchen experience preferred','Strong attention to detail','Food Handler Card or ability to obtain one'], ['Paid menu training','Kitchen certification pathway']],
            ];
            foreach ($starterJobs as $job) {
                [$publicId,$slug,$title,$department,$location,$employmentType,$schedule,$pay,$summary,$description,$responsibilities,$requirements,$benefits] = $job;
                $jobStatement->execute([$organizationId,$publicId,$slug,$title,$department,$location,$employmentType,$schedule,$pay,$summary,$description,json_encode($responsibilities, JSON_THROW_ON_ERROR),json_encode($requirements, JSON_THROW_ON_ERROR),json_encode($benefits, JSON_THROW_ON_ERROR),$userId,$userId]);
            }

            app_audit($pdo, $organizationId, $userId, 'installation.first_owner_created', 'user', (string)$userId, null, [
                'email' => $input['email'],
                'role' => 'super_admin',
                'organization' => $input['restaurant_name'],
            ]);

            $pdo->commit();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            $_SESSION['organization_id'] = $organizationId;
            $_SESSION['authenticated_at'] = time();
            $success = true;
            app_redirect('index.php?setup=complete');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception instanceof PDOException && (string)$exception->getCode() === '23000'
                ? 'That email address is already in use.'
                : 'The owner account could not be created: ' . $exception->getMessage();
        }
    }
}

$csrf = app_has_config() && !$databaseError ? app_csrf_token() : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Create First Super Admin</title>
  <style>
    :root{--ink:#171815;--muted:#666b65;--line:#dddcd5;--accent:#d94a2b;--soft:#fff1ec;--good:#157347;--bad:#a62a24}*{box-sizing:border-box}body{margin:0;background:#f4f3ef;color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.shell{width:min(980px,calc(100% - 32px));margin:34px auto}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:18px}.brand{display:flex;gap:11px;align-items:center}.logo{width:46px;height:46px;display:grid;place-items:center;border-radius:14px;color:#fff;background:linear-gradient(145deg,#ff8a63,#d84626);font-weight:900}.brand strong{display:block}.brand span{display:block;margin-top:3px;color:var(--muted);font-size:12px}.pill{padding:7px 10px;border:1px solid var(--line);border-radius:999px;background:#fff;color:var(--muted);font-size:11px;font-weight:800}.card{padding:28px;border:1px solid var(--line);border-radius:24px;background:#fff;box-shadow:0 20px 60px rgba(20,22,18,.08)}h1{margin:7px 0 8px;font-size:clamp(31px,5vw,52px);line-height:.98;letter-spacing:-.055em}.eyebrow{margin:0;color:var(--accent);font-size:10px;font-weight:900;letter-spacing:.13em;text-transform:uppercase}.intro{max-width:720px;margin:0 0 24px;color:var(--muted);line-height:1.6}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.full{grid-column:1/-1}.field-wrap{display:grid;gap:6px}.field-wrap label{font-size:11px;font-weight:850}.field{width:100%;min-height:45px;padding:11px 12px;border:1px solid var(--line);border-radius:12px;background:#fff;color:var(--ink);outline:0}.field:focus{border-color:rgba(217,74,43,.6);box-shadow:0 0 0 4px rgba(217,74,43,.1)}.help{color:var(--muted);font-size:10px;line-height:1.45}.button{min-height:47px;padding:11px 17px;border:0;border-radius:13px;color:#fff;background:var(--accent);font-weight:900}.notice{margin-bottom:18px;padding:14px 15px;border:1px solid;border-radius:13px;font-size:12px;line-height:1.55}.notice.bad{color:var(--bad);border-color:#efc8c4;background:#fff3f2}.notice.good{color:var(--good);border-color:#c9e7d6;background:#effaf4}.notice.info{color:#315f9b;border-color:#cbd9ec;background:#f3f7fd}.steps{display:grid;gap:8px;margin:16px 0 0;padding:0;list-style:none}.steps li{padding:11px 12px;border:1px solid var(--line);border-radius:12px;background:#f8f7f3;font-size:12px}.steps code{font-weight:900}.locked{padding:24px;border:1px solid #c9e7d6;border-radius:18px;background:#effaf4}.locked h2{margin-top:0}.actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:16px}.link{display:inline-flex;align-items:center;min-height:42px;padding:9px 13px;border:1px solid var(--line);border-radius:11px;color:var(--ink);background:#fff;text-decoration:none;font-size:12px;font-weight:850}@media(max-width:700px){.shell{margin:18px auto}.card{padding:20px}.grid{grid-template-columns:1fr}.full{grid-column:auto}.top{align-items:flex-start;flex-direction:column}}
  </style>
</head>
<body>
<main class="shell">
  <div class="top"><div class="brand"><div class="logo">✦</div><div><strong>Restaurant Workspace</strong><span>Secure first-owner installation</span></div></div><span class="pill">One-time setup</span></div>
  <section class="card">
    <p class="eyebrow">Installation</p>
    <h1>Create the first Super Admin.</h1>
    <p class="intro">This page creates the store organization, first location, protected owner account, default account types, positions, brand record, resume form, public landing page, and Owner System Agent.</p>

    <?php if ($configError): ?>
      <div class="notice bad"><strong>Configuration required.</strong> <?= app_escape($configError) ?></div>
      <ol class="steps">
        <li>Rename <code>config-example.php</code> to <code>config.php</code>.</li>
        <li>Enter the database name, database username, and database password.</li>
        <li>Import <code>database/install.sql</code> into that empty database.</li>
        <li>Reload this page.</li>
      </ol>
    <?php elseif ($databaseError): ?>
      <div class="notice bad"><strong>Database connection failed.</strong> <?= app_escape($databaseError) ?></div>
      <p class="help">Confirm the database settings in config.php and verify that PHP has the PDO MySQL extension enabled.</p>
    <?php elseif (!$schemaInstalled): ?>
      <div class="notice bad"><strong>The database is connected, but the schema is missing.</strong> Import <code>database/install.sql</code>, then reload this page.</div>
    <?php elseif ($ownerExists): ?>
      <div class="locked"><h2>Installation is locked.</h2><p>A Super Admin already exists. The first-user form cannot be run again.</p><div class="actions"><a class="link" href="login.php">Open secure login</a><a class="link" href="landing.html">View public landing page</a></div></div>
    <?php else: ?>
      <?php foreach ($errors as $error): ?><div class="notice bad"><?= app_escape($error) ?></div><?php endforeach; ?>
      <div class="notice info">This installer is available only until the first Super Admin is created. It permanently locks itself by checking the database.</div>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= app_escape($csrf) ?>">
        <div class="grid">
          <div class="field-wrap"><label for="restaurant_name">Restaurant name</label><input class="field" id="restaurant_name" name="restaurant_name" value="<?= app_escape($_POST['restaurant_name'] ?? '') ?>" required></div>
          <div class="field-wrap"><label for="legal_name">Legal business name</label><input class="field" id="legal_name" name="legal_name" value="<?= app_escape($_POST['legal_name'] ?? '') ?>"></div>
          <div class="field-wrap"><label for="location_name">First location name</label><input class="field" id="location_name" name="location_name" value="<?= app_escape($_POST['location_name'] ?? 'Main Location') ?>" required></div>
          <div class="field-wrap"><label for="timezone">Timezone</label><input class="field" id="timezone" name="timezone" value="<?= app_escape($_POST['timezone'] ?? 'America/Phoenix') ?>" required></div>
          <div class="field-wrap"><label for="first_name">Owner first name</label><input class="field" id="first_name" name="first_name" value="<?= app_escape($_POST['first_name'] ?? '') ?>" autocomplete="given-name" required></div>
          <div class="field-wrap"><label for="last_name">Owner last name</label><input class="field" id="last_name" name="last_name" value="<?= app_escape($_POST['last_name'] ?? '') ?>" autocomplete="family-name" required></div>
          <div class="field-wrap full"><label for="email">Owner email</label><input class="field" id="email" name="email" type="email" value="<?= app_escape($_POST['email'] ?? '') ?>" autocomplete="email" required></div>
          <div class="field-wrap"><label for="password">Password</label><input class="field" id="password" name="password" type="password" autocomplete="new-password" required><span class="help">At least 12 characters with uppercase, lowercase, number, and symbol.</span></div>
          <div class="field-wrap"><label for="password_confirm">Confirm password</label><input class="field" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required></div>
          <div class="full"><button class="button" type="submit">Create Super Admin and finish setup</button></div>
        </div>
      </form>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
