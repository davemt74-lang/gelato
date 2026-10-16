const fs=require('node:fs');const assert=require('node:assert/strict');
const js=fs.readFileSync('js/global-agent.js','utf8');
assert.match(js,/submitted\?\.operationalFollowup/);
assert.match(js,/\^task-\[a-f0-9\]\{20\}\$/);
assert.match(js,/followup\.alreadyCreated/);
assert.match(js,/operationalFollowup:\{taskId:createdTaskId/);
assert.match(js,/const answer=\(greeting\?/);
console.log('FM-G9F conversational task receipt contract PASS (5 assertions)');
