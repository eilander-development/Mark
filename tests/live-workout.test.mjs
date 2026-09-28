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
    for (const name of ['escapeReportText', 'getExerciseHistory', 'formatHistorySets', 'compareHistoryEntries', 'exerciseHistoryHtml', 'preparationDetailsHtml', 'weekExerciseOutlookHtml', 'getOverloadOutlook', 'completedDayResultsHtml', 'getNextSplitDay', 'getLiveRestStats', 'getTrainingTiming', 'summarizeTrainingTimes', 'getCycleReport', 'getCycleHistoryReport', 'calculate1RM', 'getAllTimeRecord', 'isSetNewAllTimePR', 'lastHeavyWeekNum', 'peakSlotWeight', 'calculateSetProgress', 'getSlotProgress', 'getExerciseWeekProgress', 'getNextProgression', 'findPreviousExerciseSession', 'rememberLiveInput', 'renderProgressDetails', 'getDayCompletionStatus', 'getWeekEvaluation', 'getSameWeekExerciseLogged', 'getSlotTargetAdvice', 'autoApplyOverloadAndDeloadInheritance', 'prepareCurrentLiveSetValues', 'submitLiveSet', 'adjustLiveReps', 'setLiveRepsManual', 'setLiveWeightManual', 'updateLiveSubmitButtonText']) {
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
    assert.equal(c.getNextProgression(progress, 2).weight, 22);
    c.appState.overloadFrequency = 'biweekly';
    assert.equal(c.getNextProgression(progress, 2).status, 'Consolideren');
    assert.equal(c.getNextProgression(progress, 7).weight, 14);
    assert.equal(c.getNextProgression(progress, 7).requiredSets, 2);
});


test('PHP and frontend calculate identical progress and next-week advice', () => {
    const { context: c } = setup();
    const cases = [
        { reps: [8, 8, 8], weights: [6, 6, 6], target: 6, goal: 8, bw: false, effort: 'good', nextWeek: 3, frequency: 'weekly' },
        { reps: [8, 8, 8], weights: [1, 1, 1], target: 1, goal: 8, bw: false, effort: 'max', nextWeek: 7, frequency: 'weekly' },
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
        const progress = c.calculateSetProgress(sets, item.target, item.goal || 12, 3, item.bw);
        const full = { ...progress, isBodyweight: item.bw, exertion: item.effort };
        c.appState.overloadFrequency = item.frequency;
        const next = c.getNextProgression(full, item.nextWeek);
        const php = spawnSync('php', ['-r', `require 'vendor/autoload.php'; $v = json_decode(stream_get_contents(STDIN), true); $p = new App\\Services\\Periodization; echo json_encode(['progress' => $p->progress($v['sets'], $v['target'], $v['goal'] ?? 12, 3, $v['bw']), 'next' => $p->nextProgression($v['full'], $v['nextWeek'], 2, $v['frequency'])]);`], {
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
    assert.match(elements.lwContentArea.innerHTML, /Gepland doel/);
    assert.match(elements.lwContentArea.innerHTML, /Eigen invoer voor deze set/);
    assert.doesNotMatch(elements.lwContentArea.innerHTML, /Afronden met aangepaste|Confetti & Rapport/);
});


test('reps progress through 8, 10, 12 before one weight step resets to 8', () => {
    const { context: c } = setup();
    const base = { targetWeight: 15, minReps: 8, maxReps: 12, achieved: true, completedSets: 6, requiredSets: 6, exertion: 'good', isBodyweight: false };
    for (const [target, expectedWeight, expectedReps, change] of [[8, 15, 10, 'reps'], [10, 15, 12, 'reps'], [12, 17, 8, 'weight']]) {
        const next = c.getNextProgression({ ...base, targetReps: target, achievedReps: target }, 3);
        assert.equal(next.weight, expectedWeight);
        assert.equal(next.reps, expectedReps);
        assert.equal(next.change, change);
        assert.equal(next.minReps, 8);
        assert.equal(next.maxReps, 12);
    }
});

test('higher rep range caps at 15 and credits reps already achieved', () => {
    const { context: c } = setup();
    const base = { targetWeight: 6, targetReps: 12, minReps: 12, maxReps: 15, achieved: true, completedSets: 6, requiredSets: 6, exertion: 'easy', isBodyweight: false };
    assert.equal(c.getNextProgression(base, 3).reps, 14);
    assert.equal(c.getNextProgression({ ...base, targetReps: 14 }, 3).reps, 15);
    const next = c.getNextProgression({ ...base, achievedReps: 15 }, 3);
    assert.equal(next.weight, 8);
    assert.equal(next.reps, 12);
});

test('zero new sets shows provisional advice alongside older training history', () => {
    const { context: c } = setup();
    c.appState.weeks[1].mon.bench.sets.forEach(set => Object.assign(set, { weight: 6, reps: 12, completed: true }));
    c.appState.weeks[2] = { mon: { bench: { selectedName: 'Dumbbell Bench Press', progressionPlan: { weight: 6, reps: 12, minReps: 12, maxReps: 15 }, sets: Array.from({ length: 3 }, () => ({ weight: 6, reps: '', completed: false })) } } };
    const output = c.renderProgressDetails(2, 'mon', 'bench');
    assert.match(output, /Nog 3 sets niet gelogd/);
    assert.match(output, /Volgende week: nog te beoordelen/);
    assert.match(output, /eerst reps \(12–15\), daarna gewicht/);
    assert.match(output, /Vorige training/);
    assert.match(output, /6 kg × 14 reps \(reps opbouwen\)/);
});

test('partial sessions show more reps or heavier weight without claiming an overall gain', () => {
    const { context: c } = setup();
    c.appState.weeks[1].mon.bench.sets.forEach(set => Object.assign(set, { weight: 6, reps: 10, completed: true }));
    Object.assign(c.appState.weeks[1].thu.bench.sets[0], { weight: 6, reps: 12, completed: true });
    Object.assign(c.appState.weeks[1].thu.bench.sets[1], { weight: 8, reps: 8, completed: true });
    const output = c.renderProgressDetails(1, 'thu', 'bench');
    assert.match(output, /Set 1: \+2 reps bij hetzelfde gewicht/);
    assert.match(output, /Set 2: \+2 kg; 10 → 8 reps/);
});

test('low-weight recovery and reductions never increase weight or produce zero', () => {
    const { context: c } = setup();
    for (const weight of [0.1, 1, 2, 2.01]) {
        const base = { targetWeight: weight, targetReps: 8, minReps: 8, maxReps: 12, achieved: false, completedSets: 3, requiredSets: 3, exertion: 'max', isBodyweight: false };
        for (const week of [3, 7]) {
            const next = c.getNextProgression(base, week);
            assert.ok(next.weight > 0 && next.weight <= weight);
            assert.ok(next.reps <= 8);
        }
    }
});


test('a new week starts with the earned weight and lower rep goal instead of stale prefilled values', () => {
    const { context: c } = setup();
    for (const day of ['mon', 'thu']) {
        c.appState.weeks[1][day].bench.progressionPlan = { weight: 15, reps: 12, minReps: 8, maxReps: 12 };
        c.appState.weeks[1][day].bench.sets.forEach(set => Object.assign(set, { weight: 15, reps: 12, completed: true }));
    }
    c.appState.weeks[2] = { mon: { bench: { selectedName: 'Dumbbell Bench Press', sets: Array.from({ length: 3 }, () => ({ weight: 15, reps: 12, completed: false })) } } };
    c.liveWorkout.weekNum = 2;
    c.liveWorkout.dayKey = 'mon';
    c.prepareCurrentLiveSetValues();
    assert.equal(c.liveWorkout.tempWeight, 17);
    assert.equal(c.liveWorkout.tempReps, 8);
    assert.equal(c.appState.weeks[2].mon.bench.progressionPlan.minReps, 8);
    assert.equal(c.appState.weeks[2].mon.bench.progressionPlan.maxReps, 12);
});


test('cycle history includes rep progress, excludes drafts and ignores the deload for performance comparison', () => {
    const { context: c } = setup();
    const slot = (weight, reps, completed = true) => ({ selectedName: 'Dumbbell Bench Press', sets: [{ weight, reps, completed }] });
    const report = c.getCycleHistoryReport({ number: 2, snapshot: { weeksSnapshot: {
        1: { mon: { bench: slot(12, 8) } },
        6: { mon: { bench: slot(12, 12), draft: slot(100, 20, false) } },
        7: { mon: { bench: slot(8, 12) } },
    } } });
    assert.equal(report.cycleNumber, 2);
    assert.equal(report.totalSets, 3);
    assert.equal(report.totalVolume, 336);
    assert.equal(report.totalWorkouts, 0);
    assert.equal(report.progressHighlights[0].startReps, 8);
    assert.equal(report.progressHighlights[0].endReps, 12);
    assert.equal(report.progressHighlights[0].repDiff, 4);
    assert.equal(report.durationEstimated, true);
});

test('all-time records include archived sets and use weight or estimated strength like the server', () => {
    const { context: c } = setup();
    c.appState.cyclesHistory = [{ snapshot: { weeksSnapshot: { 1: { mon: { bench: {
        selectedName: 'Dumbbell Bench Press', sets: [{ weight: 20, reps: 12, completed: true }, { weight: 100, reps: 12, completed: false }],
    } } } } } }];
    assert.equal(c.getAllTimeRecord('Dumbbell Bench Press').maxWeight, 20);
    assert.equal(c.isSetNewAllTimePR('Dumbbell Bench Press', 18, 10, 1, 'thu', 'bench', 0), false);
    assert.equal(c.isSetNewAllTimePR('Dumbbell Bench Press', 22, 1, 1, 'thu', 'bench', 0), true);
    assert.equal(c.isSetNewAllTimePR('Dumbbell Bench Press', 20, 13, 1, 'thu', 'bench', 0), true);
    Object.assign(c.appState.weeks[1].thu.bench.sets[0], { weight: 30, reps: 12, completed: true });
    assert.equal(c.isSetNewAllTimePR('Dumbbell Bench Press', 30, 12, 1, 'thu', 'bench', 0), true);
});

test('rest average weights actual moments and excludes unknown historical counts', () => {
    const { context: c } = setup();
    const timing = c.summarizeTrainingTimes([
        { day: { actualDuration: 120, actualAvgRest: 60, actualRestCount: 2 }, completedSets: 3 },
        { day: { actualAvgRest: 120, actualRestCount: 6 }, completedSets: 7 },
        { day: { actualAvgRest: 999 }, completedSets: 1 },
    ]);
    assert.equal(timing.averageRestSeconds, 105);
    assert.equal(timing.restCount, 8);
    assert.equal(timing.restIncomplete, true);
    assert.equal(timing.totalDurationSeconds, 1200);
    assert.equal(timing.durationEstimated, true);
    assert.equal(c.summarizeTrainingTimes([{ day: {}, completedSets: 0 }]).averageRestSeconds, null);
});

test('next cycle peak ignores uncompleted and invalid sets', () => {
    const { context: c } = setup();
    c.appState.totalWeeks = 7;
    c.appState.weeks[6] = { mon: { bench: { sets: [
        { weight: 50, reps: 12, completed: false },
        { weight: 40, reps: 0, completed: true },
        { weight: 12, reps: 10, completed: true },
    ] } } };
    assert.equal(c.peakSlotWeight('mon', 'bench'), 12);
});

test('training card counts lighter work without presenting it as meeting the weight goal', () => {
    const { context: c } = setup();
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true }));
    const slot = c.appState.weeks[1].thu.bench;
    slot.progressionPlan = { weight: 15, reps: 12 };
    Object.assign(slot.sets[0], { weight: 12, reps: 10, completed: true });
    const rendered = c.renderProgressDetails(1, 'thu', 'bench');
    assert.match(rendered, /Sets uitgevoerd: 1\/3 · Doel gehaald: 0\/3/);
    assert.match(rendered, /class="text-slate-300">Verschil met vorige training: Set 1: -3 kg/);
    assert.match(rendered, /standaard ‘goed’/);
});


test('resuming a saved summary preserves the previous rest count without counting it twice', () => {
    const { context: c } = setup();
    Object.assign(c.liveWorkout, { previousRestCount: 4, previousAvgRest: 60, actualRestDurations: [120, 120] });
    assert.equal(c.getLiveRestStats().count, 6);
    assert.equal(c.getLiveRestStats().average, 80);
    assert.equal(c.getLiveRestStats().count, 6);
});


test('completed day shows actual work and a conditional repetition forecast without starting the next workout', () => {
    const { context: c } = setup();
    for (const day of ['mon', 'thu']) c.appState.weeks[1][day].bench.progressionPlan = { weight: 15, reps: 10 };
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 10, completed: true }));
    const result = c.completedDayResultsHtml(1, 'mon');
    assert.match(result, /Resultaten & vooruitblik/);
    assert.match(result, /3\/3 sets uitgevoerd · 3\/3 setdoelen gehaald/);
    assert.match(result, /Op koers voor extra reps/);
    assert.match(result, /3 × 15 kg × 12 reps/);
    assert.match(result, /nog 3 sets te beoordelen/);
    assert.match(result, /onclick="switchDay\('tue'\)"/);
    assert.doesNotMatch(result, /startLiveWorkoutSession|Schema Staat Vast/);
    assert.equal(c.appState.weeks[1].thu.bench.sets[0].completed, false);
});

test('missed or maximal sets prevent an optimistic forecast and final advice reuses the progression rules', () => {
    const { context: c } = setup();
    for (const day of ['mon', 'thu']) c.appState.weeks[1][day].bench.progressionPlan = { weight: 15, reps: 12 };
    const monday = c.appState.weeks[1].mon.bench.sets;
    monday.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true }));
    monday[0].reps = 11;
    assert.equal(c.getOverloadOutlook(1, 'Dumbbell Bench Press').title, 'Voorlopig herhalen');
    monday[0].reps = 12;
    monday[0].exertion = 'max';
    assert.equal(c.getOverloadOutlook(1, 'Dumbbell Bench Press').next, null);
    monday[0].exertion = 'good';
    assert.equal(c.getOverloadOutlook(1, 'Dumbbell Bench Press').title, 'Op koers voor meer gewicht');
    c.appState.weeks[1].thu.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true }));
    assert.equal(c.getOverloadOutlook(1, 'Dumbbell Bench Press').provisional, false);
    assert.equal(c.getOverloadOutlook(1, 'Dumbbell Bench Press').next.weight, 17);
});

test('outlook respects consolidation, recovery and the cycle boundary', () => {
    const { context: c } = setup();
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true }));
    c.appState.overloadFrequency = 'biweekly';
    assert.equal(c.getOverloadOutlook(1, 'Dumbbell Bench Press').title, 'Consolidatieweek gepland');
    c.appState.weeks[6] = c.appState.weeks[1];
    assert.equal(c.getOverloadOutlook(6, 'Dumbbell Bench Press').title, 'Herstelweek gepland');
    c.appState.weeks[7] = c.appState.weeks[1];
    assert.equal(c.getOverloadOutlook(7, 'Dumbbell Bench Press').next, null);
});


test('unfinished future sets without a start weight use the known exercise weight for the forecast', () => {
    const { context: c } = setup();
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 10, completed: true }));
    assert.equal(c.getOverloadOutlook(1, 'Dumbbell Bench Press').next.weight, 15);
});

test('main screen switches to results only when all valid working sets are saved', () => {
    const { context: c, elements } = setup();
    Object.assign(c.appState, { currentWeek: 1, currentDay: 'mon', routineLocked: true });
    c.window = {};
    c.isDayLockedOnHoofdscherm = () => true;
    c.renderLockedRoutineView = () => { c.plannedViewShown = true; };
    for (const id of ['exerciseSlotsList', 'deloadExplanationBanner', 'dashPhaseBadge', 'daySetsSubtext']) {
        elements[id] = { innerHTML: '', classList: { add() {}, remove() {} } };
    }
    const start = html.indexOf('    function renderExercises(');
    const end = html.indexOf('\n    function ', start + 1);
    vm.runInContext(html.slice(start, end), c);
    c.renderExercises();
    assert.equal(c.plannedViewShown, true);
    assert.doesNotMatch(elements.exerciseSlotsList.innerHTML, /Resultaten & vooruitblik/);
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 10, completed: true }));
    c.renderExercises();
    assert.match(elements.exerciseSlotsList.innerHTML, /Resultaten & vooruitblik/);
});


test('exercise history includes prior cycles, excludes drafts and preserves the original set positions', () => {
    const { context: c } = setup();
    c.appState.currentCycle = 2;
    c.appState.cyclesHistory = [{ number: 1, snapshot: { weeksSnapshot: { 6: { mon: { bench: {
        selectedName: 'Dumbbell Bench Press', sets: [{ weight: 12, reps: 8, completed: false }, { weight: 12, reps: 8, completed: true }],
    } } } } } }];
    Object.assign(c.appState.weeks[1].mon.bench.sets[1], { weight: 12, reps: 10, completed: true });
    Object.assign(c.appState.weeks[1].thu.bench.sets[0], { weight: 14, reps: 8, completed: true });
    const history = c.getExerciseHistory('Dumbbell Bench Press', { week: 1, day: 'thu' });
    assert.equal(history.length, 2);
    assert.equal(history[0].cycle, 1);
    assert.equal(history[0].sets[0].position, 2);
    const rendered = c.exerciseHistoryHtml('Dumbbell Bench Press', { week: 1, day: 'thu' });
    assert.match(rendered, /Set 2: 12 kg × 10/);
    assert.match(rendered, /\+2 reps totaal/);
    assert.doesNotMatch(rendered, /14 kg/);
});

test('history avoids claiming progression when load, number of sets or recovery phase changes', () => {
    const { context: c } = setup();
    const before = { sets: [{ position: 1, weight: 12, reps: 12 }] };
    assert.match(c.compareHistoryEntries(before, { sets: [{ position: 1, weight: 14, reps: 8 }] }), /Gewicht gewijzigd/);
    assert.match(c.compareHistoryEntries(before, { sets: [] }), /Verschillend aantal/);
    assert.match(c.compareHistoryEntries(before, { ...before, isDeload: true }), /Hersteltraining/);
    assert.match(c.exerciseHistoryHtml('Unknown'), /Nog geen opgeslagen uitvoeringen/);
});

test('preparation displays the stored goal separately from the latest actual performance without modifying sets', () => {
    const { context: c } = setup();
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true }));
    c.appState.weeks[1].thu.bench.progressionPlan = { weight: 17, reps: 8 };
    const before = JSON.stringify(c.appState.weeks);
    const result = c.preparationDetailsHtml(1, 'thu', 'bench');
    assert.match(result, /Gepland: 3 × 17 kg × 8 reps/);
    assert.match(result, /Laatste uitvoering.*Set 1: 15 kg × 12/);
    assert.match(result, /Doel vastgelegd bij de start/);
    assert.match(result, /Oefeningshistorie/);
    assert.equal(JSON.stringify(c.appState.weeks), before);
});

test('weekly card distinguishes a provisional projection from final advice and escapes exercise titles', () => {
    const { context: c } = setup();
    for (const day of ['mon', 'thu']) c.appState.weeks[1][day].bench.progressionPlan = { weight: 15, reps: 12 };
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true }));
    let exercise = c.getWeekEvaluation(1).exercises[0];
    assert.match(c.weekExerciseOutlookHtml(1, exercise), /Voorlopige verwachting/);
    assert.match(c.weekExerciseOutlookHtml(1, exercise), /Nog 3 sets te beoordelen/);
    c.appState.weeks[1].thu.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true }));
    exercise = c.getWeekEvaluation(1).exercises[0];
    assert.match(c.weekExerciseOutlookHtml(1, exercise), /Advies voor volgende week/);
    assert.match(c.weekExerciseOutlookHtml(1, exercise), /3 × 17 kg × 8 reps/);
    for (const day of ['mon', 'thu']) c.appState.weeks[1][day].bench.selectedName = '<script>alert(1)</script>';
    exercise = c.getWeekEvaluation(1).exercises[0];
    const result = c.weekExerciseOutlookHtml(1, exercise);
    assert.match(result, /&lt;script&gt;/);
    assert.doesNotMatch(result, /<script>/);
});
