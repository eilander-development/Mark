import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const html = readFileSync(new URL('../resources/ironforge.html', import.meta.url), 'utf8');
const result = spawnSync('php', ['-r', 'require "vendor/autoload.php"; echo json_encode((new App\\Services\\CoreWorkout)->rules());'], { encoding: 'utf8' });
assert.equal(result.status, 0, result.stderr);
const rules = JSON.parse(result.stdout);

function setup() {
    const elements = {};
    const c = vm.createContext({
        appState: { currentWeek: 1, currentDay: 'mon', trainingRules: { coreWorkout: rules }, weeks: { 1: { mon: { sessionId: 7 }, tue: { sessionId: 8 }, thu: {}, fri: {} }, 7: { mon: {} } } },
        document: { getElementById: id => elements[id], querySelector: () => null, querySelectorAll: () => [] },
        isDeloadWeek: week => Number(week) % 7 === 0,
        isBiweeklyHoldWeek: week => c.appState.overloadFrequency === 'biweekly' && week > 1 && week % 2 === 0,
        getExerciseIncrement: () => 2,
        showToast(message) { c.message = message; },
        saveState() { c.saved = true; }, renderExercises() {}, updateDashboard() {}, confirm: () => true,
        liveWorkout: { active: true, phase: 'summary', weekNum: 1, dayKey: 'mon', slots: ['bench'], currentSlotIndex: 0, currentSetIndex: 0, newPRs: [], tempExertion: 'good' },
        getDayCompletionStatus: () => ({ isFullyCompleted: true }),
        prepareCurrentLiveSetValues() {}, resumeLiveWorkoutSession() { c.resumed = true; }, renderLiveWorkoutView() {},
        isSetNewAllTimePR: () => false,
        startLiveRestTimer() { c.restStarted = true; }, showLiveWorkoutSummary() { c.liveWorkout.phase = 'summary'; },
        SPLIT_INFO: { mon: { slots: ['bench'] } },
        async jsonApi(url, method, body) { c.request = { url, method, body }; return { ok: true }; },
    });
    for (const name of ['getCoreHistory', 'getCoreAdvice', 'getNextProgression', 'getRecoveryLoad', 'getCoreWorkout', 'isCoreSlot', 'coreExerciseByName', 'trainingDayWithCore', 'getTrainingDay', 'getTrainingSlotKeys', 'getTrainingSetCount', 'coreInvitationHtml', 'saveCoreWorkout', 'startCoreWorkout', 'coreExerciseSwitchHtml', 'changeLiveCoreWorkout', 'submitLiveCoreSet', 'clearCurrentDay', 'escapeReportText']) {
        const start = html.search(new RegExp(`    (?:async )?function ${name}\\(`));
        assert.notEqual(start, -1, name);
        const rest = html.slice(start + 1);
        const end = rest.search(/\n    (?:async )?function /);
        vm.runInContext(html.slice(start, start + 1 + end), c);
    }
    return { c, elements };
}

test('completion invites optional core work without showing exercises or inputs before acceptance', async () => {
    const { c } = setup();
    c.getDayCompletionStatus = () => ({ isFullyCompleted: false });
    assert.equal(c.coreInvitationHtml(1, 'mon'), '');
    await c.startCoreWorkout();
    assert.equal(c.request, undefined);
    c.getDayCompletionStatus = () => ({ isFullyCompleted: true });
    const invitation = c.coreInvitationHtml(1, 'mon');
    assert.match(invitation, /Wil je nog buikspieren doen/);
    assert.match(invitation, /Nee, afronden/);
    assert.doesNotMatch(invitation, /<input|<select|<iframe|Reverse crunch/);
    assert.equal(c.appState.weeks[1].mon.coreWorkout, undefined);
    await c.startCoreWorkout();
    assert.equal(c.request.url, '/api/sessions/7');
    assert.equal(c.appState.weeks[1].mon.coreWorkout.length, 2);
    assert.equal(c.liveWorkout.phase, 'set');
    assert.equal(c.liveWorkout.slots[c.liveWorkout.currentSlotIndex], 'slot_core_0');
    assert.equal(c.resumed, true);
    assert.equal(c.appState.showLiveVideoPanel, true);
});

test('declining never adds required sets and accepting resumes the first unfinished core set', async () => {
    const { c } = setup();
    assert.deepEqual(Array.from(c.getTrainingSlotKeys(1, 'mon')), ['bench']);
    const workout = c.getCoreWorkout(1, 'mon');
    workout[0].sets.forEach(set => { set.completed = true; });
    workout[1].sets[0].completed = true;
    c.appState.weeks[1].mon.coreWorkout = workout;
    await c.startCoreWorkout();
    assert.equal(c.liveWorkout.slots[c.liveWorkout.currentSlotIndex], 'slot_core_1');
    assert.equal(c.liveWorkout.currentSetIndex, 1);
    workout[1].sets[1].completed = true;
    c.appState.weeks[1].mon.coreWorkout = workout;
    assert.equal(c.coreInvitationHtml(1, 'mon'), '');
});

test('switching uses automatic advice and keeps the picker closed until requested', async () => {
    const { c } = setup();
    await c.startCoreWorkout();
    const controls = c.coreExerciseSwitchHtml(1, 'mon', 'slot_core_0');
    assert.match(controls, /<details/);
    assert.doesNotMatch(controls, /<details[^>]* open/);
    await c.changeLiveCoreWorkout('exercise', 'bird_dog');
    const item = c.appState.weeks[1].mon.coreWorkout[0];
    assert.equal(item.exercise, 'bird_dog');
    assert.equal(item.targetReps, 8);
    assert.equal(item.sets.length, 2);
    assert.equal(c.getTrainingDay(1, 'mon').slot_core_0.selectedName, 'Bird dog');
    assert.equal(c.coreExerciseByName('Bird dog').videoId, 'ZdAHe9_HeEw');
    assert.ok(c.coreExerciseByName('Bird dog').tips.length);
});

test('two or three sets use the trainer and completed work cannot be dropped or replaced', async () => {
    const { c, elements } = setup();
    await c.startCoreWorkout();
    await c.changeLiveCoreWorkout('sets', 3);
    assert.equal(c.getTrainingSetCount(1, 'mon', 'slot_core_0'), 3);
    c.liveWorkout.currentSetIndex = 2;
    elements.lwRepsInput = { value: '11' };
    await c.submitLiveCoreSet();
    assert.equal(c.restStarted, true);
    assert.equal(c.appState.weeks[1].mon.coreWorkout[0].sets[2].reps, 11);
    await c.changeLiveCoreWorkout('exercise', 'crunch');
    await c.changeLiveCoreWorkout('sets', 2);
    assert.equal(c.appState.weeks[1].mon.coreWorkout[0].exercise, 'reverse_crunch');
    assert.equal(c.appState.weeks[1].mon.coreWorkout[0].sets.length, 3);
    assert.equal(c.appState.weeks[1].tue.coreWorkout, undefined);
});

test('invalid repetitions never reach the server', async () => {
    const { c, elements } = setup();
    await c.startCoreWorkout();
    delete c.request;
    for (const value of ['', '0', '1.5', '51', 'abc']) {
        elements.lwRepsInput = { value };
        await c.submitLiveCoreSet();
        assert.equal(c.request, undefined);
        assert.equal(c.appState.weeks[1].mon.coreWorkout[0].sets[0].completed, false);
        assert.match(c.message, /1 tot 50 hele herhalingen/);
    }
});

test('failed saves retain saved data and entered reps for retry without starting rest', async () => {
    const { c, elements } = setup();
    await c.startCoreWorkout();
    const before = JSON.stringify(c.appState.weeks);
    elements.lwRepsInput = { value: '13' };
    for (const jsonApi of [async () => ({ ok: false }), async () => { throw new Error('offline'); }]) {
        c.jsonApi = jsonApi;
        await c.submitLiveCoreSet();
        assert.equal(JSON.stringify(c.appState.weeks), before);
        assert.equal(elements.lwRepsInput.value, '13');
        assert.equal(c.saveCoreWorkout.pending, false);
        assert.equal(c.restStarted, undefined);
        assert.match(c.message, /niet opgeslagen/);
    }
});

test('a pending save blocks duplicate clicks and starts rest exactly once', async () => {
    const { c, elements } = setup();
    await c.startCoreWorkout();
    elements.lwRepsInput = { value: '10' };
    let resolve, calls = 0, rests = 0;
    c.startLiveRestTimer = () => { rests++; };
    c.jsonApi = () => { calls++; return new Promise(done => { resolve = done; }); };
    const pending = c.submitLiveCoreSet();
    await c.submitLiveCoreSet();
    assert.equal(calls, 1);
    assert.equal(c.appState.weeks[1].mon.coreWorkout[0].sets[0].completed, false);
    resolve({ ok: true });
    await pending;
    assert.equal(c.appState.weeks[1].mon.coreWorkout[0].sets[0].completed, true);
    assert.equal(rests, 1);
});

test('the last core set ends in the shared report without another invitation or rest', async () => {
    const { c, elements } = setup();
    const workout = c.getCoreWorkout(1, 'mon');
    workout.forEach(item => item.sets.forEach(set => { set.completed = true; }));
    workout[1].sets[1].completed = false;
    c.appState.weeks[1].mon.coreWorkout = workout;
    await c.startCoreWorkout();
    elements.lwRepsInput = { value: '8' };
    await c.submitLiveCoreSet();
    assert.equal(c.liveWorkout.phase, 'summary');
    assert.equal(c.restStarted, undefined);
    assert.equal(c.coreInvitationHtml(1, 'mon'), '');
});

test('clearing a training resets its core sets only after a successful save, even when the user switches day', async () => {
    const { c } = setup();
    const day = c.appState.weeks[1].mon;
    day.coreWorkout = c.getCoreWorkout(1, 'mon');
    day.bench = { sets: [{ weight: '20', reps: '10', completed: true }] };
    c.jsonApi = async () => ({ ok: false });
    await c.clearCurrentDay();
    assert.equal(day.coreWorkout.length, 2);
    assert.equal(day.bench.sets[0].completed, true);
    assert.match(c.message, /niet worden gewist/);
    c.jsonApi = async () => { c.appState.currentDay = 'tue'; return { ok: true }; };
    await c.clearCurrentDay();
    assert.equal(day.coreWorkout.length, 0);
    assert.equal(day.bench.sets[0].completed, false);
    assert.equal(c.appState.weeks[1].tue.coreWorkout, undefined);
});

function performed(exercise, targetReps, reps = [targetReps, targetReps], exertion = 'good') {
    return { exercise, targetReps, sets: reps.map(reps => ({ reps, completed: true, exertion })) };
}

test('advice keeps the performed exercise, repeats within the week and increases after controlled results', () => {
    const { c } = setup();
    c.appState.weeks[1].mon.coreWorkout = [performed('crunch', 12), performed('dead_bug', 8)];
    const thursday = c.getCoreWorkout(1, 'thu');
    assert.equal(thursday[0].exercise, 'crunch');
    assert.equal(thursday[0].targetReps, 12);
    assert.equal(thursday[0].sets[0].completed, false);
    const next = c.getCoreWorkout(2, 'mon');
    assert.equal(next[0].exercise, 'crunch');
    assert.equal(next[0].targetReps, 14);
    assert.equal(next[1].targetReps, 10);
    assert.equal(next[0].sets.length, 2);
    assert.equal(c.getCoreAdvice(2, 'mon', 'crunch').status, 'Herhalingen opbouwen');
});

test('missing, ungraded, maximal or below-target sets prevent an optimistic increase', () => {
    for (const change of [
        item => { item.sets[1].completed = false; },
        item => { delete item.sets[1].exertion; },
        item => { item.sets[1].exertion = 'max'; },
        item => { item.sets[1].reps = 9; },
    ]) {
        const { c } = setup();
        const item = performed('reverse_crunch', 10);
        change(item);
        c.appState.weeks[1].mon.coreWorkout = [item];
        assert.equal(c.getCoreAdvice(2, 'mon', 'reverse_crunch').reps, 10);
        assert.equal(c.getCoreAdvice(2, 'mon', 'reverse_crunch').count, 2);
    }
});

test('a successful last session cannot hide a missed or maximal set earlier in that week', () => {
    const { c } = setup();
    c.appState.weeks[1].mon.coreWorkout = [performed('reverse_crunch', 10, [9, 10], 'max')];
    c.appState.weeks[1].thu.coreWorkout = [performed('reverse_crunch', 10)];
    assert.equal(c.getCoreAdvice(2, 'mon', 'reverse_crunch').reps, 10);
});

test('the upper repetition limit earns a third shorter set and never grows beyond three sets', () => {
    const { c } = setup();
    c.appState.weeks[1].mon.coreWorkout = [performed('reverse_crunch', 15), performed('dead_bug', 12)];
    const next = c.getCoreWorkout(2, 'mon');
    assert.equal(next[0].sets.length, 3);
    assert.equal(next[0].targetReps, 10);
    assert.equal(next[1].sets.length, 3);
    assert.equal(next[1].targetReps, 8);
    c.appState.weeks[1].mon.coreWorkout = [performed('reverse_crunch', 15, [15, 15, 15])];
    const capped = c.getCoreAdvice(2, 'mon', 'reverse_crunch');
    assert.equal(capped.count, 3);
    assert.equal(capped.reps, 15);
    assert.equal(capped.status, 'Vasthouden');
});

test('consolidation and fatigue override an earned increase or third set', () => {
    const { c } = setup();
    c.appState.weeks[1].mon.coreWorkout = [performed('reverse_crunch', 15)];
    c.appState.overloadFrequency = 'biweekly';
    assert.equal(c.getCoreAdvice(2, 'mon', 'reverse_crunch').count, 2);
    assert.equal(c.getCoreAdvice(2, 'mon', 'reverse_crunch').reps, 15);
    c.appState.weeks[2] = { mon: { recovery: 'tired' } };
    c.appState.overloadFrequency = 'weekly';
    assert.equal(c.getCoreAdvice(2, 'mon', 'reverse_crunch').count, 2);
    assert.equal(c.getCoreAdvice(2, 'mon', 'reverse_crunch').reps, 15);
    c.appState.weeks[2].mon.recovery = 'exhausted';
    assert.equal(c.getCoreAdvice(2, 'mon', 'reverse_crunch').reps, 9);
});

test('two complete comparable failures at maximum effort lower reps while one failure does not', () => {
    const { c } = setup();
    c.appState.weeks[1].mon.coreWorkout = [performed('reverse_crunch', 10, [8, 9], 'max')];
    assert.equal(c.getCoreAdvice(2, 'mon', 'reverse_crunch').reps, 10);
    c.appState.weeks[1].thu.coreWorkout = [performed('reverse_crunch', 10, [8, 9], 'max')];
    assert.equal(c.getCoreAdvice(2, 'mon', 'reverse_crunch').reps, 8);
    c.appState.weeks[1].mon.coreWorkout[0].targetReps = 9;
    assert.equal(c.getCoreAdvice(2, 'mon', 'reverse_crunch').reps, 10);
});

test('deload advice stays anchored to the build week instead of compounding recovery reductions', () => {
    const { c } = setup();
    c.appState.weeks[6] = { mon: { coreWorkout: [performed('reverse_crunch', 14, [14, 14, 14])] } };
    assert.equal(c.getCoreAdvice(7, 'mon', 'reverse_crunch').reps, 10);
    c.appState.weeks[7].mon.coreWorkout = [performed('reverse_crunch', 10, [10, 10])];
    const thursday = c.getCoreAdvice(7, 'thu', 'reverse_crunch');
    assert.equal(thursday.reps, 10);
    assert.equal(thursday.count, 2);
    assert.equal(thursday.previous.week, 6);
});

test('a new cycle resumes the last build target and ignores future, skipped, draft and deload results', () => {
    const { c } = setup();
    c.appState.currentCycle = 2;
    const draft = performed('reverse_crunch', 20);
    draft.sets.forEach(set => { set.completed = false; });
    c.appState.cyclesHistory = [
        { number: 3, snapshot: { weeksSnapshot: { 1: { mon: { coreWorkout: [performed('reverse_crunch', 30)] } } } } },
        { number: 1, snapshot: { weeksSnapshot: {
            5: { mon: { coreWorkout: [performed('reverse_crunch', 14)] } },
            6: { mon: { coreWorkout: [draft] }, thu: { skipped: true, coreWorkout: [performed('reverse_crunch', 25)] } },
            7: { mon: { coreWorkout: [performed('reverse_crunch', 7)] } },
        } } },
    ];
    c.appState.weeks[1].tue.coreWorkout = [performed('reverse_crunch', 40)];
    const advice = c.getCoreAdvice(1, 'mon', 'reverse_crunch');
    assert.equal(advice.reps, 14);
    assert.equal(advice.previous.cycle, 1);
    assert.equal(advice.previous.week, 5);
    assert.equal(advice.status, 'Basis hervatten');
});

test('recording actual reps keeps the prescribed target and effort for later advice, including after reload', async () => {
    const { c, elements } = setup();
    await c.startCoreWorkout();
    elements.lwRepsInput = { value: '8' };
    c.liveWorkout.tempExertion = 'max';
    await c.submitLiveCoreSet();
    const saved = c.appState.weeks[1].mon.coreWorkout;
    assert.equal(saved[0].targetReps, 10);
    assert.equal(saved[0].sets[0].reps, 8);
    assert.equal(saved[0].sets[0].exertion, 'max');
    const { c: reloaded } = setup();
    reloaded.appState.weeks[1].mon.coreWorkout = JSON.parse(JSON.stringify(saved));
    assert.equal(reloaded.getCoreWorkout(1, 'mon')[0].targetReps, 10);
    c.liveWorkout.tempExertion = 'good';
    await c.submitLiveCoreSet();
    assert.equal(c.appState.weeks[1].mon.coreWorkout[0].sets[0].exertion, 'good');
    assert.equal(c.appState.weeks[1].mon.coreWorkout[0].sets[0].reps, 8);
});

test('an existing plan remains fixed when earlier results change, and legacy sets are ungraded', () => {
    const { c } = setup();
    c.appState.weeks[1].mon.coreWorkout = [performed('reverse_crunch', 10)];
    c.appState.weeks[2] = { mon: { coreWorkout: c.getCoreWorkout(2, 'mon') } };
    c.appState.weeks[1].mon.coreWorkout[0] = performed('reverse_crunch', 15);
    const frozen = c.getCoreWorkout(2, 'mon');
    assert.equal(frozen[0].targetReps, 12);
    assert.equal(frozen[0].sets.length, 2);
    assert.equal(c.getTrainingDay(2, 'mon').slot_core_0.progressionPlan.reps, 12);
    const legacy = { exercise: 'reverse_crunch', sets: [{ reps: 10, completed: true }, { reps: 10, completed: true }] };
    c.appState.weeks[1].mon.coreWorkout = [legacy];
    assert.equal(c.getCoreAdvice(2, 'mon', 'reverse_crunch').reps, 10);
    assert.match(c.getCoreAdvice(2, 'mon', 'reverse_crunch').reason, /inspanningsbeoordeling/);
});

test('automatic selection preserves variety when earlier manual selections were duplicates', () => {
    const { c } = setup();
    c.appState.weeks[1].mon.coreWorkout = [performed('dead_bug', 8), performed('dead_bug', 8)];
    const workout = c.getCoreWorkout(2, 'mon');
    assert.equal(new Set(workout.map(item => item.exercise)).size, 2);
});

test('a failed correction retains the saved effort and reps', async () => {
    const { c, elements } = setup();
    await c.startCoreWorkout();
    c.appState.weeks[1].mon.coreWorkout = [performed('reverse_crunch', 10), performed('dead_bug', 8)];
    const before = JSON.stringify(c.appState.weeks);
    c.liveWorkout.tempExertion = 'max';
    elements.lwRepsInput = { value: '9' };
    c.jsonApi = async () => ({ ok: false });
    await c.submitLiveCoreSet();
    assert.equal(JSON.stringify(c.appState.weeks), before);
    assert.match(c.message, /niet opgeslagen/);
});

test('an older imported catalog without repetition limits still produces finite advice', () => {
    const { c } = setup();
    c.appState.trainingRules.coreWorkout = JSON.parse(JSON.stringify(rules));
    delete c.appState.trainingRules.coreWorkout.catalog.reverse_crunch.maxReps;
    c.appState.weeks[1].mon.coreWorkout = [performed('reverse_crunch', 14)];
    assert.equal(c.getCoreAdvice(2, 'mon', 'reverse_crunch').reps, 15);
});
