<?php
declare(strict_types=1);
/**
 * FM-G12E release contract: the integrated suites above exercise runtime
 * behavior. This guards against accidental removal of the critical boundaries.
 */
$root=dirname(__DIR__);
$read=static function(string $path) use ($root):string {
    $text=file_get_contents($root.'/'.$path);
    if($text===false)throw new RuntimeException('Missing source: '.$path);
    return $text;
};
$room=$read('includes/food-monster-room-presence.php');
$memory=$read('includes/food-monster-conversation-memory.php');
$hints=$read('includes/food-monster-g11d-followups.php');
$client=$read('js/global-agent.js');
$checks=[
  'speaker is bound to verified voice'=>str_contains($room,"provisional_voice_match")
    &&str_contains($room,'fm11a_room_voice_turn')
    &&str_contains($room,'fm11a_room_clear_speaker'),
  'speaker handoff not authorization'=>str_contains($room,'handoffFromPreviousVerifiedTurn')
    &&str_contains($room,"'websiteAuthenticated'=>false"),
  'person memory bound to canonical agent messages'=>str_contains($memory,"'fm9d_person_turn'")
    &&str_contains($memory,'speakerPersonId')
    &&str_contains($memory,'voice_event_public_id'),
  'context is scoped to same person and thread'=>str_contains($memory,'fm10c_contextual_utterance')
    &&str_contains($memory,'operatorThreadId'),
  'training hint requires independent evidence'=>str_contains($hints,'$evidenceText')
    &&str_contains($hints,'count($eligible)<2'),
  'repeat hint is session deduplicated'=>str_contains($hints,'fm12d_hint_fingerprints')
    &&str_contains($hints,"hash('sha256'"),
  'client consumes each signed voice event once'=>str_contains($client,'usedVoiceEvents.has')
    &&str_contains($client,'usedVoiceEvents.add'),
  'fatal mic error discards queued transcript'=>str_contains($client,"'audio-capture'")
    &&str_contains($client,"clearConversationTimer();S.conversationDraft='';resetVoiceDraft();"),
];
foreach($checks as $label=>$passed){
    if(!$passed)throw new RuntimeException('FAIL FM-G12E: '.$label);
}
echo 'FM-G12E integrated security contracts PASS ('.count($checks).' assertions)'."\n";
