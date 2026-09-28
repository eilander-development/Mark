import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import assert from 'node:assert/strict';

const html = readFileSync(new URL('../resources/ironforge.html', import.meta.url), 'utf8');
function setup() {
    const blank = () => ({ weight: '', reps: '', completed: false });
    const slot = () => ({ selectedName: 'Dumbbell Bench Press', sets: [blank(), blank(), blank()] });
    const elements = { lwSubmitTargetBtn: { innerHTML: '' } };
    const context = vm.createContext({
        appState: { weeks: { 1: { mon: { bench: slot() }, thu: { bench: slot() } } }, userProfile: {} },
        liveWorkout: { weekNum: 1, dayKey: 'thu', slots: ['bench'], currentSlotIndex: 0, currentSetIndex: 0, slotAdaptations: {}, newPRs: [], tempExertion: 'good' },
        EXERCISE_CATALOG: { bench: { defaultName: 'Dumbbell Bench Press', targetReps: 8 } },
        SPLIT_INFO: { mon: { slots: ['bench'], title: 'Maandag: training' }, thu: { slots: ['bench'], title: 'Donderdag: training' } },
        document: { getElementById: id => elements[id] ?? null },
        isExerciseBodyweight: name => name === 'Push-up',
        getExerciseTargetReps: () => 8,
        isDeloadWeek: week => week % 7 === 0,
        isBiweeklyHoldWeek: week => context.appState.overloadFrequency === 'biweekly' && week > 1 && week % 2 === 0,
        getStrengthBenchmark: () => ({}),
        isSetNewAllTimePR: () => false,
        saveState() { context.saved = true; },
        renderExercises() {}, updateDashboard() {}, updateLive1RMPreview() {},
        startLiveRestTimer() { context.restStarted = true; },
        showLiveWorkoutSummary() { context.finished = true; },
        console,
    });
    for (const name of ['calculateSetProgress', 'getSlotProgress', 'getExerciseWeekProgress', 'getNextProgression', 'findPreviousExerciseSession', 'rememberLiveInput', 'renderProgressDetails', 'getDayCompletionStatus', 'getWeekEvaluation', 'getSameWeekExerciseLogged', 'getSlotTargetAdvice', 'autoApplyOverloadAndDeloadInheritance', 'prepareCurrentLiveSetValues', 'submitLiveSet', 'adjustLiveReps', 'setLiveRepsManual', 'setLiveWeightManual', 'updateLiveSubmitButtonText']) {
        const start = html.indexOf(`    function ${name}(`);
        const end = html.indexOf('\n    function ', start + 1);
        vm.runInContext(html.slice(start, end), context);
    }
    return { context, elements };
}

test('Thursday inherits three completed sets of 15 kg and 12 reps without completing them', () => {
    const { context: c } = setup();
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true }));
    c.autoApplyOverloadAndDeloadInheritance(1, 'thu');
    assert.deepEqual(JSON.parse(JSON.stringify(c.appState.weeks[1].thu.bench.sets)), Array.from({ length: 3 }, () => ({ weight: 15, reps: 12, completed: false })));
    c.prepareCurrentLiveSetValues();
    assert.equal(c.liveWorkout.tempReps, 12);
    assert.equal(c.liveWorkout.tempWeight, 15);
});

test('planned or later sets are never treated as an earlier performance', () => {
    const { context: c } = setup();
    Object.assign(c.appState.weeks[1].mon.bench.sets[0], { weight: 15, reps: 12 });
    assert.equal(c.getSameWeekExerciseLogged(1, 'Dumbbell Bench Press', 'thu', 'bench'), null);
    Object.assign(c.appState.weeks[1].thu.bench.sets[0], { weight: 20, reps: 15, completed: true });
    assert.equal(c.getSameWeekExerciseLogged(1, 'Dumbbell Bench Press', 'mon', 'bench'), null);
});

for (const reps of [6, 8, 12]) {
    test(`saving ${reps} reps carries actual values over prefilled next set and starts rest`, () => {
        const { context: c, elements } = setup();
        c.appState.weeks[1].thu.bench.sets[1] = { weight: 10, reps: 8, completed: false };
        c.setLiveWeightManual('17.5');
        c.setLiveRepsManual(String(reps));
        assert.match(elements.lwSubmitTargetBtn.innerHTML, new RegExp(`17.5 kg.*${reps} reps`));
        c.submitLiveSet(false);
        const saved = c.appState.weeks[1].thu.bench.sets[0];
        assert.equal(saved.weight, 17.5);
        assert.equal(saved.reps, reps);
        assert.equal(saved.completed, true);
        assert.equal(c.saved, true);
        assert.equal(c.restStarted, true);
        assert.equal(c.finished, undefined);
        c.liveWorkout.currentSetIndex = 1;
        c.prepareCurrentLiveSetValues();
        assert.equal(c.liveWorkout.tempWeight, 17.5);
        assert.equal(c.liveWorkout.tempReps, reps);
    });
}

test('repetition shortcut updates the green save label', () => {
    const { context: c, elements } = setup();
    c.setLiveWeightManual('15');
    c.setLiveRepsManual('11');
    c.adjustLiveReps(1);
    assert.match(elements.lwSubmitTargetBtn.innerHTML, /15.0 kg.*12 reps/);
});

test('bodyweight repetitions carry to Thursday without requiring a weight', () => {
    const { context: c } = setup();
    c.appState.weeks[1].mon.bench.selectedName = 'Push-up';
    c.appState.weeks[1].thu.bench.selectedName = 'Push-up';
    Object.assign(c.appState.weeks[1].mon.bench.sets[0], { weight: 0, reps: 16, completed: true });
    c.prepareCurrentLiveSetValues();
    assert.equal(c.liveWorkout.tempWeight, 0);
    assert.equal(c.liveWorkout.tempReps, 16);
});

test('inheritance fills missing reps but preserves completed and manually entered sets', () => {
    const { context: c } = setup();
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true }));
    const sets = c.appState.weeks[1].thu.bench.sets;
    Object.assign(sets[0], { weight: 17, reps: 10, completed: true });
    Object.assign(sets[1], { weight: 16 });
    Object.assign(sets[2], { weight: 18, reps: 9 });
    c.autoApplyOverloadAndDeloadInheritance(1, 'thu');
    assert.deepEqual(JSON.parse(JSON.stringify(sets)), [
        { weight: 17, reps: 10, completed: true },
        { weight: 16, reps: 12, completed: false },
        { weight: 18, reps: 9, completed: false },
    ]);
    c.liveWorkout.currentSetIndex = 0;
    c.prepareCurrentLiveSetValues();
    assert.equal(c.liveWorkout.tempWeight, 17);
    assert.equal(c.liveWorkout.tempReps, 10);
});

test('saving the last set still opens the workout summary', () => {
    const { context: c } = setup();
    c.appState.weeks[1].thu.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true }));
    c.appState.weeks[1].thu.bench.sets[2].completed = false;
    c.liveWorkout.currentSetIndex = 2;
    c.prepareCurrentLiveSetValues();
    c.submitLiveSet(false);
    assert.equal(c.finished, true);
    assert.equal(c.restStarted, undefined);
    assert.equal(c.appState.weeks[1].thu.bench.sets[2].reps, 12);
});


test('all inline application scripts parse successfully', () => {
    for (const match of html.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)) {
        if (match[1].trim()) new vm.Script(match[1]);
    }
});

test('one missing repetition gives 97 percent and identifies the unfinished target', () => {
    const { context: c } = setup();
    const sets = [12, 12, 11].map(reps => ({ weight: 15, reps, completed: true }));
    const progress = c.calculateSetProgress(sets, 15, 12, 3, false);
    assert.equal(progress.percent, 97);
    assert.equal(progress.remainingReps, 1);
    assert.equal(progress.achievedSets, 2);
    assert.equal(progress.achieved, false);
});

test('extra reps cannot compensate for a light or missing set', () => {
    const { context: c } = setup();
    const progress = c.calculateSetProgress([
        { weight: 15, reps: 30, completed: true },
        { weight: 10, reps: 12, completed: true },
    ], 15, 12, 3, false);
    assert.equal(progress.percent, 33);
    assert.equal(progress.achievedSets, 1);
    assert.equal(progress.details[1].weightMet, false);
    assert.equal(progress.details[2].completed, false);
});

test('explicit input for a future set wins over the previous set after resuming', () => {
    const { context: c } = setup();
    const slot = c.appState.weeks[1].thu.bench;
    slot.progressionPlan = { weight: 15, reps: 8 };
    slot.sets[0] = { weight: 17, reps: 12, completed: true };
    slot.sets[1] = { weight: 16, reps: 10, completed: false, inputFields: { weight: true, reps: true } };
    c.liveWorkout.currentSetIndex = 1;
    c.prepareCurrentLiveSetValues();
    assert.equal(c.liveWorkout.tempWeight, 16);
    assert.equal(c.liveWorkout.tempReps, 10);
    assert.equal(slot.progressionPlan.weight, 15);
    assert.equal(slot.progressionPlan.reps, 8);
});

test('day and week show the same deficits and withhold overload until both sessions succeed', () => {
    const { context: c } = setup();
    for (const day of ['mon', 'thu']) {
        const slot = c.appState.weeks[1][day].bench;
        slot.progressionPlan = { weight: 15, reps: 12 };
        slot.sets = [12, 12, day === 'thu' ? 11 : 12].map(reps => ({ weight: 15, reps, completed: true, exertion: 'good' }));
    }
    const week = c.getExerciseWeekProgress(1, 'Dumbbell Bench Press');
    assert.equal(week.achievedSets, 5);
    assert.equal(week.requiredSets, 6);
    assert.equal(week.remainingReps, 1);
    assert.equal(c.getNextProgression(week, 2).weight, 15);
    const output = c.renderProgressDetails(1, 'thu', 'bench');
    assert.match(output, /Bijna bij je doel: 2\/3 sets/);
    assert.match(output, /Set 3: nog 1 reps/);
    assert.match(output, /Weekdoel: 5\/6 sets/);
    c.appState.weeks[1].thu.bench.sets[2].reps = 12;
    assert.equal(c.getNextProgression(c.getExerciseWeekProgress(1, 'Dumbbell Bench Press'), 2).weight, 17);
});

test('maximum effort, consolidation and deload take priority over increasing', () => {
    const { context: c } = setup();
    const progress = { targetWeight: 20, targetReps: 12, achieved: true, completedSets: 6, requiredSets: 6, exertion: 'max', isBodyweight: false };
    assert.equal(c.getNextProgression(progress, 2).weight, 20);
    progress.exertion = 'easy';
    assert.equal(c.getNextProgression(progress, 2).weight, 24);
    c.appState.overloadFrequency = 'biweekly';
    assert.equal(c.getNextProgression(progress, 2).status, 'Consolideren');
    assert.equal(c.getNextProgression(progress, 7).weight, 14);
    assert.equal(c.getNextProgression(progress, 7).requiredSets, 2);
});


test('PHP and frontend calculate identical progress and next-week advice', () => {
    const { context: c } = setup();
    const cases = [
        { reps: [12, 12, 11], weights: [15, 15, 15], target: 15, bw: false, effort: 'good', nextWeek: 2, frequency: 'weekly' },
        { reps: [12, 12, 12], weights: [20, 15, 15], target: 20, bw: false, effort: 'max', nextWeek: 3, frequency: 'weekly' },
        { reps: [12, 12, 12], weights: [15, 15, 15], target: 15, bw: false, effort: 'easy', nextWeek: 2, frequency: 'biweekly' },
        { reps: [12, 12, 12], weights: [15, 15, 15], target: 15, bw: false, effort: 'easy', nextWeek: 3, frequency: 'weekly' },
        { reps: [12, 12, 12], weights: [15, 15, 15], target: 15, bw: false, effort: 'good', nextWeek: 7, frequency: 'weekly' },
        { reps: [12, 12, 12], weights: [0, 0, 0], target: 0, bw: true, effort: 'easy', nextWeek: 3, frequency: 'weekly' },
        { reps: [12, 12, 12], weights: [0, 0, 0], target: 0, bw: true, effort: 'max', nextWeek: 3, frequency: 'weekly' },
    ];
    for (const item of cases) {
        const sets = item.reps.map((reps, index) => ({ reps, weight: item.weights[index], completed: true }));
        const progress = c.calculateSetProgress(sets, item.target, 12, 3, item.bw);
        const full = { ...progress, isBodyweight: item.bw, exertion: item.effort };
        c.appState.overloadFrequency = item.frequency;
        const next = c.getNextProgression(full, item.nextWeek);
        const php = spawnSync('php', ['-r', `require 'vendor/autoload.php'; $v = json_decode(stream_get_contents(STDIN), true); $p = new App\\Services\\Periodization; echo json_encode(['progress' => $p->progress($v['sets'], $v['target'], 12, 3, $v['bw']), 'next' => $p->nextProgression($v['full'], $v['nextWeek'], 2, $v['frequency'])]);`], {
            cwd: new URL('..', import.meta.url), encoding: 'utf8', input: JSON.stringify({ ...item, sets, full }),
        });
        assert.equal(php.status, 0, php.stderr);
        assert.deepEqual(JSON.parse(php.stdout), JSON.parse(JSON.stringify({ progress, next })));
    }
});


test('week report totals only saved work and uses the shared exercise progress', () => {
    const { context: c } = setup();
    c.appState.weeks[1].mon.bench.progressionPlan = { weight: 15, reps: 12 };
    c.appState.weeks[1].mon.bench.sets = [12, 12, 11].map(reps => ({ weight: 15, reps, completed: true }));
    c.appState.weeks[1].thu.bench.sets.forEach(set => Object.assign(set, { weight: 15, reps: 12 }));
    const report = c.getWeekEvaluation(1);
    assert.equal(report.totalVolume, 525);
    assert.equal(report.totalCompletedSets, 3);
    assert.equal(report.totalPlannedSets, 6);
    assert.equal(report.daysCompletedCount, 1);
    assert.equal(report.isCompleted, false);
    assert.equal(report.exercises[0].progress.achievedSets, 2);
    assert.equal(report.exercises[0].nextAdvisedWeight, 15);
});

test('live view renders actual save values and the original plan together', () => {
    const { context: c, elements } = setup();
    elements.lwContentArea = { innerHTML: '' };
    c.updateLiveWorkoutCardLayout = () => {};
    c.renderLiveVideoCompanion = () => {};
    c.updateLiveExertionButtons = () => {};
    c.getExerciseDetails = () => ({ equipment: 'Dumbbell' });
    c.getExerciseVideoData = () => ({ url: '' });
    c.calculate1RM = () => 20;
    c.formatTime = () => '1:30';
    c.liveWorkout.phase = 'set';
    c.prepareCurrentLiveSetValues();
    c.setLiveWeightManual('15');
    c.setLiveRepsManual('12');
    const start = html.indexOf('    function renderLiveWorkoutView(');
    const end = html.indexOf('\n    function ', start + 1);
    vm.runInContext(html.slice(start, end), c);
    c.renderLiveWorkoutView();
    assert.match(elements.lwContentArea.innerHTML, /OPSLAAN \(15 kg.*12 reps\)/);
    assert.match(elements.lwContentArea.innerHTML, /Gepland:/);
    assert.match(elements.lwContentArea.innerHTML, /Eigen invoer voor deze set/);
    assert.doesNotMatch(elements.lwContentArea.innerHTML, /Afronden met aangepaste|Confetti & Rapport/);
});
