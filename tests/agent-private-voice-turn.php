<?php
declare(strict_types=1);
require __DIR__.'/../includes/agent-private-voice.php';

if(!session_start())throw new RuntimeException('PHP session unavailable');
function gavp_assert(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
}
$thread='agent-11111111111111111111';
$turn='msg-aaaaaaaaaaaaaaaaaaaa';
$token=gavp_issue(7,19,$thread,$turn,1000);
gavp_assert(strlen($token)===48,'Cryptographic token required');
gavp_assert(gavp_consume(7,19,$thread,$turn,$token,1002),'Correct approval must work');
gavp_assert(gavp_was_approved(7,19,$thread,$turn,1003),'Approved turn can be read by independent delegated evidence endpoints');
gavp_assert(!gavp_was_approved(8,19,$thread,$turn,1003),'Other organization has no approved turn');
gavp_assert(!gavp_was_approved(7,20,$thread,$turn,1003),'Other user has no approved turn');
gavp_assert(!gavp_was_approved(7,19,'agent-other',$turn,1003),'Other thread has no approved turn');
gavp_assert(!gavp_was_approved(7,19,$thread,'msg-bbbbbbbbbbbbbbbbbbbb',1003),'Other turn has no approval');
gavp_assert(!gavp_was_approved(7,19,$thread,$turn,1091),'Delegated approval expires');
gavp_assert(!gavp_consume(7,19,$thread,$turn,$token,1003),'Consumed approval must not replay');
$token=gavp_issue(7,19,$thread,$turn,1000);
gavp_assert(!gavp_consume(7,19,$thread,'msg-bbbbbbbbbbbbbbbbbbbb',$token,1001),'Wrong message rejected');
gavp_assert(!gavp_consume(7,19,$thread,$turn,$token,1002),'Mismatch consumes grant');
$token=gavp_issue(7,19,$thread,$turn,1000);
gavp_assert(!gavp_consume(8,19,$thread,$turn,$token,1001),'Wrong organization rejected');
$token=gavp_issue(7,19,$thread,$turn,1000);
gavp_assert(!gavp_consume(7,20,$thread,$turn,$token,1001),'Wrong user rejected');
$token=gavp_issue(7,19,$thread,$turn,1000);
gavp_assert(!gavp_consume(7,19,'agent-other',$turn,$token,1001),'Wrong thread rejected');
$token=gavp_issue(7,19,$thread,$turn,1000);
gavp_assert(!gavp_consume(7,19,$thread,$turn,$token,1046),'Expired grant rejected');
$token=gavp_issue(7,19,$thread,$turn,1000);
gavp_assert(!gavp_consume(7,19,$thread,$turn,str_repeat('0',48),1001),'Forged grant rejected');
gavp_assert(!gavp_consume(7,19,$thread,$turn,$token,1001),'Correct grant cannot follow forgery');
gavp_clear_approved();
gavp_assert(!gavp_was_approved(7,19,$thread,$turn,1003),'A newly committed turn invalidates all prior approval');
fwrite(STDOUT,"Private voice turn grant and delegated reads: replay, expiry and scope checks passed.\n");
