'use strict';
const assert=require('assert');
const harness=require('../assets/js/glasses-v10-soak-harness.js');

const report=harness.run({cycles:500,seed:1337,maxQueue:3});
assert.strictEqual(report.schema,'gelato.glasses_runtime_soak.v1');
assert.strictEqual(report.cycles,500);
assert.ok(report.framesProduced>=500,'sustained frame production expected');
assert.ok(report.framesProcessed>0,'frames must be processed');
assert.ok(report.framesDropped>0,'burst overload should exercise dropped-frame accounting');
assert.ok(report.framesTimedOut>0,'timeout faults should be exercised');
assert.ok(report.recoveryAttempts>0,'recovery faults should be exercised');
assert.strictEqual(report.recoveryAttempts,report.recoverySuccesses,'all deterministic recovery cycles must succeed');
assert.strictEqual(report.recoveryFailures,0);
assert.ok(report.maxObservedQueueDepth<=3,'queue must remain bounded');
assert.ok(report.maxConcurrentWorkers<=1,'single worker invariant must hold');
assert.strictEqual(report.stalledCycles,0);
assert.strictEqual(report.unhandledErrors,0);
assert.strictEqual(report.finalQueueDepth,0);
assert.strictEqual(report.workerActive,false);
assert.strictEqual(report.monotonicDelivery,true);
assert.strictEqual(report.finalRecoveryState,'ready');

const repeat=harness.run({cycles:500,seed:1337,maxQueue:3});
assert.deepStrictEqual(repeat,report,'seeded soak run must be deterministic');

console.log('glasses-v10-soak-harness-ok');
