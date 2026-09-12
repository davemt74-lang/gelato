<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/operations-wholesale.php';

$pdo=app_pdo();
$pdo->exec("INSERT INTO organizations (name) VALUES ('Wholesale Operations CI')");
$org=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES ('wholesale-ops@example.test','x','Casey','Operator','Casey Operator','active')");
$user=(int)$pdo->lastInsertId();
$membership=$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')");
$membership->execute([$org,$user]);
operations_ensure_default_categories($pdo,$org,$user);

$account=$pdo->prepare("INSERT INTO wholesale_accounts (organization_id,public_id,business_name,account_status,primary_email,preferred_fulfillment,created_by,updated_by) VALUES (?,?,?,'active',?,'Delivery',?,?)");
$account->execute([$org,'wacct-ci','CI Market','buyer@example.test',$user,$user]);
$accountId=(int)$pdo->lastInsertId();
$items=[
    ['quantity'=>2,'product'=>'Pistachio 5L pan','unitPrice'=>48],
    ['quantity'=>3,'product'=>'Vanilla 5L pan','unitPrice'=>42],
];
$order=$pdo->prepare("INSERT INTO wholesale_orders (organization_id,wholesale_account_id,public_id,order_number,status,items_json,total,fulfillment_type,requested_for,customer_notes,internal_notes,created_by,updated_by) VALUES (?,?,?,?, 'confirmed', ?,222,'Delivery',DATE_ADD(CURDATE(),INTERVAL 2 DAY),'Use rear delivery entrance.','Private margin note must not leak.',?,?)");
$order->execute([$org,$accountId,'worder-ci','W-CI-001',json_encode($items,JSON_THROW_ON_ERROR),$user,$user]);
$orderId=(int)$pdo->lastInsertId();

$touched=operations_sync_wholesale_tasks($pdo,$org,$user);
if($touched!==3){fwrite(STDERR,"expected 3 touched tasks, got {$touched}\n");exit(2);}
$taskCount=(int)$pdo->query("SELECT COUNT(*) FROM restaurant_tasks WHERE organization_id={$org} AND source_type IN ('wholesale_order_item','wholesale_order_fulfillment')")->fetchColumn();
if($taskCount!==3)exit(3);
$itemTasks=$pdo->query("SELECT t.*,c.slug category_slug FROM restaurant_tasks t LEFT JOIN task_categories c ON c.id=t.category_id WHERE t.organization_id={$org} AND t.source_type='wholesale_order_item' ORDER BY t.source_public_id")->fetchAll();
if(count($itemTasks)!==2||$itemTasks[0]['category_slug']!=='wholesale'||!str_contains((string)$itemTasks[0]['title'],'Pistachio'))exit(4);
if(str_contains((string)$itemTasks[0]['description'],'$48')||str_contains((string)$itemTasks[0]['description'],'Private margin'))exit(5);
$fulfillment=$pdo->query("SELECT t.*,c.slug category_slug FROM restaurant_tasks t LEFT JOIN task_categories c ON c.id=t.category_id WHERE t.organization_id={$org} AND t.source_type='wholesale_order_fulfillment' LIMIT 1")->fetch();
if(!$fulfillment||$fulfillment['category_slug']!=='delivery'||$fulfillment['status']!=='queued')exit(6);

$first=operations_task_set_status($pdo,$org,$itemTasks[0]['public_id'],'in_progress',$user);
operations_wholesale_task_status_changed($pdo,$org,$first,'in_progress',$user);
$status=$pdo->query("SELECT status FROM wholesale_orders WHERE id={$orderId}")->fetchColumn();
if($status!=='in_production')exit(7);
operations_sync_wholesale_tasks($pdo,$org,$user);
$statuses=$pdo->query("SELECT status FROM restaurant_tasks WHERE organization_id={$org} AND source_type='wholesale_order_item' ORDER BY source_public_id")->fetchAll(PDO::FETCH_COLUMN);
if($statuses!==['in_progress','queued']){fwrite(STDERR,'item status preservation failed: '.json_encode($statuses)."\n");exit(8);}

$first=operations_task_set_status($pdo,$org,$itemTasks[0]['public_id'],'completed',$user);
operations_wholesale_task_status_changed($pdo,$org,$first,'completed',$user);
$second=operations_task_set_status($pdo,$org,$itemTasks[1]['public_id'],'completed',$user);
operations_wholesale_task_status_changed($pdo,$org,$second,'completed',$user);
$status=$pdo->query("SELECT status FROM wholesale_orders WHERE id={$orderId}")->fetchColumn();
if($status!=='ready')exit(9);

$fulfillment=operations_task_by_public_id($pdo,$org,$fulfillment['public_id']);
$fulfillment=operations_task_set_status($pdo,$org,$fulfillment['public_id'],'in_progress',$user);
operations_wholesale_task_status_changed($pdo,$org,$fulfillment,'in_progress',$user);
$status=$pdo->query("SELECT status FROM wholesale_orders WHERE id={$orderId}")->fetchColumn();
if($status!=='out_for_delivery')exit(10);
$fulfillment=operations_task_set_status($pdo,$org,$fulfillment['public_id'],'completed',$user);
operations_wholesale_task_status_changed($pdo,$org,$fulfillment,'completed',$user);
$row=$pdo->query("SELECT status,delivered_at FROM wholesale_orders WHERE id={$orderId}")->fetch();
if($row['status']!=='delivered'||empty($row['delivered_at']))exit(11);

operations_sync_wholesale_tasks($pdo,$org,$user);
$after=(int)$pdo->query("SELECT COUNT(*) FROM restaurant_tasks WHERE organization_id={$org} AND source_type IN ('wholesale_order_item','wholesale_order_fulfillment')")->fetchColumn();
if($after!==3)exit(12);
$verified=operations_task_by_public_id($pdo,$org,$itemTasks[0]['public_id']);
if(!in_array((string)$verified['status'],['completed','verified'],true))exit(13);

echo "wholesale_tasks={$after} order_status={$row['status']} delivered_at={$row['delivered_at']}\n";
