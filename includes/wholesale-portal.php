<?php
declare(strict_types=1);

function wholesale_portal_table_ready(PDO $pdo, string $table): bool
{
    try {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $statement->execute([$table]);
        return (int)$statement->fetchColumn() === 1;
    } catch (Throwable) {
        return false;
    }
}

function wholesale_portal_public_id(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(10));
}

function wholesale_portal_role_id(PDO $pdo, int $organizationId): int
{
    $statement = $pdo->prepare("SELECT id FROM roles WHERE organization_id=? AND slug='wholesale_customer' LIMIT 1");
    $statement->execute([$organizationId]);
    $id = (int)$statement->fetchColumn();
    if ($id > 0) return $id;
    $insert = $pdo->prepare("INSERT INTO roles (organization_id,name,slug,description,is_system_role,is_owner_role,is_assignable) VALUES (?,'Wholesale Customer','wholesale_customer','External wholesale buyer with access only to their own customer portal and scoped Agent.',1,0,1)");
    $insert->execute([$organizationId]);
    $id = (int)$pdo->lastInsertId();
    $permission = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key IN ('wholesale_portal.view','wholesale_portal.agent','wholesale_portal.profile_edit','wholesale_portal.requests','wholesale_portal.quotes','wholesale_portal.orders')");
    $permission->execute([$id]);
    return $id;
}

function wholesale_portal_account_for_user(PDO $pdo, array $user): ?array
{
    if (!wholesale_portal_table_ready($pdo, 'wholesale_accounts')) return null;
    $statement = $pdo->prepare(
        "SELECT a.*, au.account_role, au.is_primary, au.status AS portal_user_status
         FROM wholesale_account_users au
         INNER JOIN wholesale_accounts a ON a.id=au.wholesale_account_id
         WHERE au.organization_id=? AND au.user_id=? AND au.status='active'
           AND a.organization_id=? AND a.archived_at IS NULL AND a.account_status IN ('active','on_hold')
         LIMIT 1"
    );
    $statement->execute([(int)$user['organization_id'], (int)$user['id'], (int)$user['organization_id']]);
    $row = $statement->fetch();
    return $row ?: null;
}

function wholesale_portal_require_account(PDO $pdo, array $user): array
{
    $account = wholesale_portal_account_for_user($pdo, $user);
    if (!$account) {
        if (str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/')) {
            app_json_response(['ok'=>false,'message'=>'Your wholesale login is not linked to an active customer account.'],403);
        }
        http_response_code(403);
        exit('Your wholesale login is not linked to an active customer account.');
    }
    return $account;
}

function wholesale_portal_account_row(PDO $pdo, int $organizationId, string $publicId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM wholesale_accounts WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');
    $statement->execute([$organizationId,$publicId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function wholesale_portal_create_from_lead(PDO $pdo, int $organizationId, string $leadPublicId, int $userId): array
{
    $statement = $pdo->prepare('SELECT * FROM wholesale_leads WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');
    $statement->execute([$organizationId,$leadPublicId]);
    $lead = $statement->fetch();
    if (!$lead) throw new RuntimeException('Wholesale lead not found.');

    $existing = $pdo->prepare('SELECT * FROM wholesale_accounts WHERE organization_id=? AND wholesale_lead_id=? AND archived_at IS NULL LIMIT 1');
    $existing->execute([$organizationId,(int)$lead['id']]);
    $account = $existing->fetch();
    if ($account) return $account;

    $publicId = wholesale_portal_public_id('wacct');
    $insert = $pdo->prepare(
        "INSERT INTO wholesale_accounts
         (organization_id,public_id,wholesale_lead_id,business_name,primary_email,phone,website,business_type,
          preferred_fulfillment,flavor_preferences,package_preferences_json,private_label_interest,customer_notes,created_by,updated_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $insert->execute([
        $organizationId,$publicId,(int)$lead['id'],$lead['business_name'],$lead['email'],$lead['phone'],$lead['website'],$lead['business_type'],
        $lead['fulfillment_preference'],$lead['flavors_interest'],$lead['package_sizes_json'],(int)$lead['private_label_interest'],
        'Customer account created from wholesale pipeline lead.', $userId,$userId
    ]);
    $accountId = (int)$pdo->lastInsertId();
    if (trim((string)($lead['location_text'] ?? '')) !== '') {
        $loc = $pdo->prepare("INSERT INTO wholesale_account_locations (organization_id,wholesale_account_id,public_id,name,address_line_1,contact_name,phone,is_primary) VALUES (?,?,?,?,?,?,?,1)");
        $loc->execute([$organizationId,$accountId,wholesale_portal_public_id('wloc'),'Primary location',$lead['location_text'],$lead['contact_name'],$lead['phone']]);
    }
    $activity = $pdo->prepare("INSERT INTO wholesale_lead_activities (organization_id,wholesale_lead_id,activity_type,summary,details,created_by) VALUES (?,?,'account','Wholesale customer portal account created',?,?)");
    $activity->execute([$organizationId,(int)$lead['id'],'Customer account '.$publicId.' created and is ready for buyer invitation.',$userId]);
    $account = wholesale_portal_account_row($pdo,$organizationId,$publicId);
    if (!$account) throw new RuntimeException('Wholesale account could not be loaded after creation.');
    wholesale_portal_sync_account_knowledge($pdo,$organizationId,(int)$account['id'],$userId);
    return $account;
}

function wholesale_portal_create_invite(PDO $pdo, int $organizationId, int $accountId, string $email, string $contactName, string $accountRole, int $invitedBy): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    $accountRole = in_array($accountRole,['owner','buyer','accounting'],true) ? $accountRole : 'buyer';
    $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email=? AND archived_at IS NULL');
    $exists->execute([$email]);
    if ((int)$exists->fetchColumn() > 0) throw new RuntimeException('That email already has a user account. Use a different buyer email or contact the account owner to link the existing user.');

    $pdo->prepare('UPDATE wholesale_portal_invites SET revoked_at=NOW(6) WHERE organization_id=? AND wholesale_account_id=? AND email=? AND accepted_at IS NULL AND revoked_at IS NULL')->execute([$organizationId,$accountId,$email]);
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256',$token);
    $insert = $pdo->prepare("INSERT INTO wholesale_portal_invites (organization_id,wholesale_account_id,email,contact_name,account_role,token_hash,invited_by,expires_at) VALUES (?,?,?,?,?,?,?,DATE_ADD(NOW(6),INTERVAL 7 DAY))");
    $insert->execute([$organizationId,$accountId,$email,mb_substr(trim($contactName),0,180,'UTF-8'),$accountRole,$hash,$invitedBy]);
    return ['token'=>$token,'inviteId'=>(int)$pdo->lastInsertId(),'expiresInDays'=>7];
}

function wholesale_portal_send_invite_email(string $email, string $contactName, string $businessName, string $inviteUrl): bool
{
    if (!function_exists('mail')) return false;
    $config = app_config();
    $appName = (string)($config['app']['name'] ?? 'Restaurant Workspace');
    $mail = $config['mail'] ?? [];
    $fromName = str_replace(["\r","\n"],'',trim((string)($mail['from_name'] ?? $appName)));
    $host = (string)(parse_url((string)($config['app']['url'] ?? ''),PHP_URL_HOST) ?: 'localhost');
    $fromEmail = str_replace(["\r","\n"],'',trim((string)($mail['from_email'] ?? ('no-reply@'.$host))));
    $safeName = trim($contactName) !== '' ? $contactName : 'there';
    $subject = $appName . ' wholesale portal invitation';
    $body = "Hello {$safeName},\n\n{$businessName} has been invited to the private {$appName} wholesale customer portal.\n\nCreate your login:\n{$inviteUrl}\n\nThis invitation expires in 7 days. The portal gives you access only to your company account, quotes, orders, requests, and customer-scoped AI assistant.\n";
    $headers = ['From: '.$fromName.' <'.$fromEmail.'>','Reply-To: '.$fromEmail,'Content-Type: text/plain; charset=UTF-8','X-Mailer: PHP/'.PHP_VERSION];
    return @mail($email,$subject,$body,implode("\r\n",$headers));
}

function wholesale_portal_items(array $row): array
{
    $items = json_decode((string)($row['items_json'] ?? '[]'),true);
    return is_array($items) ? $items : [];
}

function wholesale_portal_customer_context(PDO $pdo, int $organizationId, int $accountId): array
{
    $accountStatement = $pdo->prepare('SELECT * FROM wholesale_accounts WHERE id=? AND organization_id=? AND archived_at IS NULL LIMIT 1');
    $accountStatement->execute([$accountId,$organizationId]);
    $account = $accountStatement->fetch();
    if (!$account) return [];

    $quotes = $pdo->prepare("SELECT public_id,quote_number,status,items_json,subtotal,delivery_fee,tax_total,total,valid_until,customer_message,terms_text,sent_at,accepted_at,declined_at,created_at,updated_at FROM wholesale_quotes WHERE organization_id=? AND wholesale_account_id=? ORDER BY created_at DESC LIMIT 12");
    $quotes->execute([$organizationId,$accountId]);
    $orders = $pdo->prepare("SELECT public_id,order_number,status,items_json,subtotal,delivery_fee,tax_total,total,fulfillment_type,requested_for,promised_for,delivered_at,customer_notes,created_at,updated_at FROM wholesale_orders WHERE organization_id=? AND wholesale_account_id=? ORDER BY created_at DESC LIMIT 20");
    $orders->execute([$organizationId,$accountId]);
    $requests = $pdo->prepare("SELECT public_id,request_type,status,subject,details,metadata_json,created_at,updated_at FROM wholesale_customer_requests WHERE organization_id=? AND wholesale_account_id=? ORDER BY created_at DESC LIMIT 20");
    $requests->execute([$organizationId,$accountId]);
    $locations = $pdo->prepare("SELECT public_id,name,address_line_1,address_line_2,city,state,postal_code,country_code,contact_name,phone,delivery_notes,is_primary,status FROM wholesale_account_locations WHERE organization_id=? AND wholesale_account_id=? AND status='active' ORDER BY is_primary DESC,name");
    $locations->execute([$organizationId,$accountId]);

    return ['account'=>$account,'quotes'=>$quotes->fetchAll(),'orders'=>$orders->fetchAll(),'requests'=>$requests->fetchAll(),'locations'=>$locations->fetchAll()];
}

function wholesale_portal_account_text(PDO $pdo, int $organizationId, int $accountId): string
{
    $ctx = wholesale_portal_customer_context($pdo,$organizationId,$accountId);
    if (!$ctx) return '';
    $a = $ctx['account'];
    $lines = [
        'Wholesale customer account: '.$a['business_name'],
        'Account status: '.$a['account_status'],
        'Primary email: '.(($a['primary_email']??'') ?: 'not recorded'),
        'Phone: '.(($a['phone']??'') ?: 'not recorded'),
        'Business type: '.(($a['business_type']??'') ?: 'not recorded'),
        'Price tier: '.(($a['price_tier']??'') ?: 'not recorded'),
        'Payment terms: '.(($a['payment_terms']??'') ?: 'not recorded'),
        'Preferred fulfillment: '.(($a['preferred_fulfillment']??'') ?: 'not recorded'),
        'Flavor preferences: '.(($a['flavor_preferences']??'') ?: 'not recorded'),
        'Package preferences: '.implode(', ',json_decode((string)($a['package_preferences_json']??'[]'),true) ?: []),
        'Private label interest: '.((int)$a['private_label_interest']===1?'yes':'no'),
        'Customer-visible notes: '.(($a['customer_notes']??'') ?: 'none recorded'),
    ];
    if ($ctx['quotes']) {
        $lines[]='Recent quotes:';
        foreach ($ctx['quotes'] as $q) $lines[]='- '.$q['quote_number'].' '.$q['status'].' total $'.number_format((float)$q['total'],2).($q['valid_until']?' valid through '.$q['valid_until']:'');
    }
    if ($ctx['orders']) {
        $lines[]='Recent orders:';
        foreach ($ctx['orders'] as $o) $lines[]='- '.$o['order_number'].' '.$o['status'].' total $'.number_format((float)$o['total'],2).($o['promised_for']?' promised '.$o['promised_for']:'');
    }
    if ($ctx['requests']) {
        $lines[]='Recent customer requests:';
        foreach ($ctx['requests'] as $r) $lines[]='- '.$r['request_type'].' ['.$r['status'].'] '.$r['subject'].': '.$r['details'];
    }
    return implode("\n",$lines);
}

function wholesale_portal_sync_account_knowledge(PDO $pdo, int $organizationId, int $accountId, ?int $userId): void
{
    if (!wholesale_portal_table_ready($pdo,'agent_knowledge_records')) return;
    $account = $pdo->prepare('SELECT * FROM wholesale_accounts WHERE id=? AND organization_id=? AND archived_at IS NULL LIMIT 1');
    $account->execute([$accountId,$organizationId]);
    $row = $account->fetch();
    if (!$row) return;
    $content = wholesale_portal_account_text($pdo,$organizationId,$accountId);
    if ($content === '') return;
    $publicId = 'wholesale_account-'.$row['public_id'];
    $statement = $pdo->prepare(
        "INSERT INTO agent_knowledge_records
         (organization_id,public_id,source_type,source_public_id,title,content,content_sha256,visibility,status,version,updated_by)
         VALUES (?,?,'wholesale_account',?,?,?,?,'internal','active',1,?)
         ON DUPLICATE KEY UPDATE title=VALUES(title),content=VALUES(content),content_sha256=VALUES(content_sha256),visibility='internal',status='active',version=version+1,updated_by=VALUES(updated_by),updated_at=NOW(6)"
    );
    $statement->execute([$organizationId,$publicId,$row['public_id'],'Wholesale Customer: '.$row['business_name'],$content,hash('sha256',$content),$userId]);
}

function wholesale_portal_customer_safe_context(PDO $pdo, int $organizationId, int $accountId): array
{
    $ctx = wholesale_portal_customer_context($pdo,$organizationId,$accountId);
    if (!$ctx) return [];
    $a = $ctx['account'];
    unset($a['internal_notes'],$a['created_by'],$a['updated_by'],$a['archived_at'],$a['wholesale_lead_id']);
    foreach ($ctx['quotes'] as &$quote) $quote['items'] = wholesale_portal_items($quote);
    foreach ($ctx['orders'] as &$order) $order['items'] = wholesale_portal_items($order);
    return ['account'=>$a,'quotes'=>$ctx['quotes'],'orders'=>$ctx['orders'],'requests'=>$ctx['requests'],'locations'=>$ctx['locations']];
}
