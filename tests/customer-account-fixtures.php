<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

/** A test-only mailbox accepts the real enrollment token; never sends email. */
function test_customer_account_verified(PDO $pdo,int $organizationId,array $input): array
{
    $request=customer_account_register($pdo,$organizationId,$input);
    if(!customer_account_deliver_verification($pdo,$request,static fn(string $to,string $subject,string $body,string $headers):bool=>true)) {
        throw new RuntimeException('Test mailbox did not accept the verification email.');
    }
    return customer_account_complete_registration($pdo,$organizationId,(string)$request['token'],$input);
}
