<?php
declare(strict_types=1);

/** Shared by the browser installer and the private Food Monster deploy worker. */
function gelato_seed_first_owner(PDO $pdo, array $input, string $passwordHash, bool $emailVerified = true, bool $resume = false): array
{
    $lock = 'gelato-owner-' . substr(hash('sha256', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
    $gate = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $gate->execute([$lock]);
    if ((int)$gate->fetchColumn() !== 1) {
        throw new RuntimeException('First-owner setup is already in progress.');
    }
    try {
        if (app_owner_exists($pdo)) {
            if ($resume) {
                $match = $pdo->prepare("SELECT DISTINCT u.id, om.organization_id FROM users u
                    JOIN organization_memberships om ON om.user_id = u.id AND om.status = 'active'
                    JOIN user_roles ur ON ur.membership_id = om.id AND ur.revoked_at IS NULL
                    JOIN roles r ON r.id = ur.role_id AND r.organization_id = om.organization_id
                    WHERE (r.is_owner_role = 1 OR (r.slug = 'super_admin' AND r.is_system_role = 1))
                      AND u.status = 'active' AND u.archived_at IS NULL AND u.email = ? AND u.password_hash = ?");
                $match->execute([$input['email'], $passwordHash]);
                $owners = $match->fetchAll();
                if (count($owners) === 1) {
                    return ['organization_id' => (int)$owners[0]['organization_id'], 'user_id' => (int)$owners[0]['id']];
                }
            }
            throw new RuntimeException('The first Super Admin has already been created.');
        }
        $pdo->beginTransaction();
        $statement = $pdo->prepare("INSERT INTO organizations (name, legal_name, status, timezone) VALUES (?, ?, 'active', ?)");
        $statement->execute([$input['restaurant_name'], $input['legal_name'] ?: null, $input['timezone']]);
        $organizationId = (int)$pdo->lastInsertId();

        $statement = $pdo->prepare("INSERT INTO locations (organization_id, name, status) VALUES (?, ?, 'active')");
        $statement->execute([$organizationId, $input['location_name'] ?: 'Main Location']);
        $locationId = (int)$pdo->lastInsertId();

        $displayName = trim($input['first_name'] . ' ' . $input['last_name']);
        $statement = $pdo->prepare(
            "INSERT INTO users
            (email, password_hash, first_name, last_name, display_name, status, email_verified_at, password_changed_at)
            VALUES (?, ?, ?, ?, ?, 'active', IF(?, NOW(6), NULL), NOW(6))"
        );
        $statement->execute([$input['email'], $passwordHash, $input['first_name'], $input['last_name'], $displayName, $emailVerified ? 1 : 0]);
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
        return ['organization_id' => $organizationId, 'user_id' => $userId];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    } finally {
        $gate = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $gate->execute([$lock]);
    }
}
