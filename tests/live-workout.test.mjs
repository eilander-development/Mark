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
    for (const name of ['applyGoalOrdering', 'goalRepetitionRange', 'trainingGoalLabel', 'weekMuscleSets', 'muscleSetsHtml', 'getExerciseIncrement', 'equipmentLabels', 'profileSummaryHtml', 'equipmentSummaryHtml', 'settingsExerciseNames', 'openSettingsModal', 'saveTrainingSettings', 'saveProfileSettings', 'saveDisplaySettings', 'renderBenchmarkModal', 'trainingEvidenceHtml', 'getRecoveryLoad', 'recoveryCheckHtml', 'earlyRecoveryAdviceHtml', 'performanceChangeHtml', 'comparisonIndicatorHtml', 'updateWorkoutHeroButtons', 'renderLockedRoutineView', 'renderWorkloadChart', 'getPeriodDashboardData', 'periodDashboardHtml', 'updateDayTitleBanner', 'volumeComparisonClass', 'getVolumeComparison', 'calculateSessionVolume', 'calculateWeekVolume', 'canStartNewPeriod', 'executeStartNewMesocycle', 'escapeReportText', 'getExerciseHistory', 'formatHistorySets', 'compareHistoryEntries', 'exerciseHistoryHtml', 'preparationDetailsHtml', 'weekExerciseOutlookHtml', 'getOverloadOutlook', 'completedDayResultsHtml', 'getNextSplitDay', 'getLiveRestStats', 'getTrainingTiming', 'summarizeTrainingTimes', 'getCycleReport', 'getCycleHistoryReport', 'calculate1RM', 'getAllTimeRecord', 'isSetNewAllTimePR', 'lastHeavyWeekNum', 'peakSlotWeight', 'calculateSetProgress', 'getSlotProgress', 'getExerciseWeekProgress', 'getNextProgression', 'findPreviousExerciseSession', 'rememberLiveInput', 'renderProgressDetails', 'getDayCompletionStatus', 'getWeekEvaluation', 'getSameWeekExerciseLogged', 'getSlotTargetAdvice', 'autoApplyOverloadAndDeloadInheritance', 'prepareCurrentLiveSetValues', 'submitLiveSet', 'adjustLiveReps', 'setLiveRepsManual', 'setLiveWeightManual', 'updateLiveSubmitButtonText']) {
        const start = html.indexOf(`    function ${name}(`);
        const end = html.indexOf('\n    function ', start + 1);
        vm.runInContext(html.slice(start, end), context);
    }
    return { context, elements };
}

test('Thursday inherits three completed sets of 15 kg and 12 reps without completing them', () => {
    const { context: c } = setup();
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true, exertion: 'good' }));
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
    Object.assign(c.appState.weeks[1].thu.bench.sets[0], { weight: 20, reps: 15, completed: true, exertion: 'good' });
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
    Object.assign(c.appState.weeks[1].mon.bench.sets[0], { weight: 0, reps: 16, completed: true, exertion: 'good' });
    c.prepareCurrentLiveSetValues();
    assert.equal(c.liveWorkout.tempWeight, 0);
    assert.equal(c.liveWorkout.tempReps, 16);
});

test('inheritance fills missing reps but preserves completed and manually entered sets', () => {
    const { context: c } = setup();
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true, exertion: 'good' }));
    const sets = c.appState.weeks[1].thu.bench.sets;
    Object.assign(sets[0], { weight: 17, reps: 10, completed: true, exertion: 'good' });
    Object.assign(sets[1], { weight: 16 });
    Object.assign(sets[2], { weight: 18, reps: 9 });
    c.autoApplyOverloadAndDeloadInheritance(1, 'thu');
    assert.deepEqual(JSON.parse(JSON.stringify(sets)), [
        { weight: 17, reps: 10, completed: true, exertion: 'good' },
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
    c.appState.weeks[1].thu.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true, exertion: 'good' }));
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
    const sets = [12, 12, 11].map(reps => ({ weight: 15, reps, completed: true, exertion: 'good' }));
    const progress = c.calculateSetProgress(sets, 15, 12, 3, false);
    assert.equal(progress.percent, 97);
    assert.equal(progress.remainingReps, 1);
    assert.equal(progress.achievedSets, 2);
    assert.equal(progress.achieved, false);
});

test('extra reps cannot compensate for a light or missing set', () => {
    const { context: c } = setup();
    const progress = c.calculateSetProgress([
        { weight: 15, reps: 30, completed: true, exertion: 'good' },
        { weight: 10, reps: 12, completed: true, exertion: 'good' },
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
    slot.sets[0] = { weight: 17, reps: 12, completed: true, exertion: 'good' };
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
        const sets = item.reps.map((reps, index) => ({ reps, weight: item.weights[index], completed: true, exertion: 'good' }));
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
    c.appState.weeks[1].mon.bench.sets = [12, 12, 11].map(reps => ({ weight: 15, reps, completed: true, exertion: 'good' }));
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
    c.appState.exerciseIncrements = { 'dumbbell bench press': 0.5 };
    c.renderLiveWorkoutView();
    assert.match(elements.lwContentArea.innerHTML, /adjustLiveWeight\(0.5\)/);
    assert.ok(elements.lwContentArea.innerHTML.indexOf('lwSubmitTargetBtn') < elements.lwContentArea.innerHTML.indexOf('Voortgang, vorige trainingen'));
    assert.match(elements.lwContentArea.innerHTML, /<details[^>]*><summary[^>]*>ⓘ Voortgang/);
    assert.match(elements.lwContentArea.innerHTML, /OPSLAAN \(15 kg.*12 reps\)/);
    assert.match(elements.lwContentArea.innerHTML, /Gepland doel/);
    assert.match(elements.lwContentArea.innerHTML, /Eigen invoer voor deze set/);
    assert.doesNotMatch(elements.lwContentArea.innerHTML, /Afronden met aangepaste|Confetti & Rapport/);
    const classes = new Set();
    elements.liveWorkoutCard = { classList: { toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name), add: (...names) => names.forEach(n => classes.add(n)), remove: (...names) => names.forEach(n => classes.delete(n)) } };
    elements.lwDayPhaseBadge = {};
    c.appState.weeks[7] = c.appState.weeks[1];
    c.liveWorkout.weekNum = 7;
    c.renderLiveWorkoutView();
    assert.equal(classes.has('deload-training'), true);
    assert.match(elements.lwDayPhaseBadge.textContent, /WEEK 7 · DELOAD/);
    c.liveWorkout.weekNum = 1;
    c.renderLiveWorkoutView();
    assert.equal(classes.has('deload-training'), false);
    assert.doesNotMatch(elements.lwDayPhaseBadge.textContent, /DELOAD/);

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
    c.appState.weeks[1].mon.bench.sets.forEach(set => Object.assign(set, { weight: 6, reps: 12, completed: true, exertion: 'good' }));
    c.appState.weeks[2] = { mon: { bench: { selectedName: 'Dumbbell Bench Press', progressionPlan: { weight: 6, reps: 12, minReps: 12, maxReps: 15 }, sets: Array.from({ length: 3 }, () => ({ weight: 6, reps: '', completed: false })) } } };
    const output = c.renderProgressDetails(2, 'mon', 'bench');
    assert.match(output, /Nog 3 sets niet gelogd/);
    assert.match(output, /Nog onvoldoende gegevens/);
    assert.match(output, /eerst reps \(12–15\), daarna gewicht/);
    assert.match(output, /Vorige training/);
    assert.doesNotMatch(output, /Mogelijke stap:/);
});

test('partial sessions show more reps or heavier weight without claiming an overall gain', () => {
    const { context: c } = setup();
    c.appState.weeks[1].mon.bench.sets.forEach(set => Object.assign(set, { weight: 6, reps: 10, completed: true, exertion: 'good' }));
    Object.assign(c.appState.weeks[1].thu.bench.sets[0], { weight: 6, reps: 12, completed: true, exertion: 'good' });
    Object.assign(c.appState.weeks[1].thu.bench.sets[1], { weight: 8, reps: 8, completed: true, exertion: 'good' });
    const output = c.renderProgressDetails(1, 'thu', 'bench');
    assert.match(output, /Set 1: .*?= 0 kg.*?▲ \+2 reps/);
    assert.match(output, /Set 2: .*?▲ \+2 kg.*?▼ -2 reps/);
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
        c.appState.weeks[1][day].bench.sets.forEach(set => Object.assign(set, { weight: 15, reps: 12, completed: true, exertion: 'good' }));
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
        selectedName: 'Dumbbell Bench Press', sets: [{ weight: 20, reps: 12, completed: true, exertion: 'good' }, { weight: 100, reps: 12, completed: false }],
    } } } } } }];
    assert.equal(c.getAllTimeRecord('Dumbbell Bench Press').maxWeight, 20);
    assert.equal(c.isSetNewAllTimePR('Dumbbell Bench Press', 18, 10, 1, 'thu', 'bench', 0), false);
    assert.equal(c.isSetNewAllTimePR('Dumbbell Bench Press', 22, 1, 1, 'thu', 'bench', 0), true);
    assert.equal(c.isSetNewAllTimePR('Dumbbell Bench Press', 20, 13, 1, 'thu', 'bench', 0), true);
    Object.assign(c.appState.weeks[1].thu.bench.sets[0], { weight: 30, reps: 12, completed: true, exertion: 'good' });
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
        { weight: 40, reps: 0, completed: true, exertion: 'good' },
        { weight: 12, reps: 10, completed: true, exertion: 'good' },
    ] } } };
    assert.equal(c.peakSlotWeight('mon', 'bench'), 12);
});

test('training card counts lighter work without presenting it as meeting the weight goal', () => {
    const { context: c } = setup();
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true, exertion: 'good' }));
    const slot = c.appState.weeks[1].thu.bench;
    slot.progressionPlan = { weight: 15, reps: 12 };
    Object.assign(slot.sets[0], { weight: 12, reps: 10, completed: true, exertion: 'good' });
    const rendered = c.renderProgressDetails(1, 'thu', 'bench');
    assert.match(rendered, /Sets uitgevoerd: 1\/3 · Doel gehaald: 0\/3/);
    assert.match(rendered, /Verschil met vorige training: Set 1: .*?text-rose-400.*?▼ -3 kg/);
    assert.match(rendered, /standaard ‘niet beoordeeld’/);
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
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 10, completed: true, exertion: 'good' }));
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
    monday.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true, exertion: 'good' }));
    monday[0].reps = 11;
    assert.equal(c.getOverloadOutlook(1, 'Dumbbell Bench Press').title, 'Voorlopig herhalen');
    monday[0].reps = 12;
    monday[0].exertion = 'max';
    assert.equal(c.getOverloadOutlook(1, 'Dumbbell Bench Press').next, null);
    monday[0].exertion = 'good';
    assert.equal(c.getOverloadOutlook(1, 'Dumbbell Bench Press').title, 'Op koers voor meer gewicht');
    c.appState.weeks[1].thu.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true, exertion: 'good' }));
    assert.equal(c.getOverloadOutlook(1, 'Dumbbell Bench Press').provisional, false);
    assert.equal(c.getOverloadOutlook(1, 'Dumbbell Bench Press').next.weight, 17);
});

test('outlook respects consolidation, recovery and the cycle boundary', () => {
    const { context: c } = setup();
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true, exertion: 'good' }));
    c.appState.overloadFrequency = 'biweekly';
    assert.equal(c.getOverloadOutlook(1, 'Dumbbell Bench Press').title, 'Consolidatieweek gepland');
    c.appState.weeks[6] = c.appState.weeks[1];
    assert.equal(c.getOverloadOutlook(6, 'Dumbbell Bench Press').title, 'Herstelweek gepland');
    c.appState.weeks[7] = c.appState.weeks[1];
    assert.equal(c.getOverloadOutlook(7, 'Dumbbell Bench Press').next, null);
});


test('unfinished future sets without a start weight use the known exercise weight for the forecast', () => {
    const { context: c } = setup();
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 10, completed: true, exertion: 'good' }));
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
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 10, completed: true, exertion: 'good' }));
    c.renderExercises();
    assert.match(elements.exerciseSlotsList.innerHTML, /Resultaten & vooruitblik/);
});


test('exercise history includes prior cycles, excludes drafts and preserves the original set positions', () => {
    const { context: c } = setup();
    c.appState.currentCycle = 2;
    c.appState.cyclesHistory = [{ number: 1, snapshot: { weeksSnapshot: { 6: { mon: { bench: {
        selectedName: 'Dumbbell Bench Press', sets: [{ weight: 12, reps: 8, completed: false }, { weight: 12, reps: 8, completed: true, exertion: 'good' }],
    } } } } } }];
    Object.assign(c.appState.weeks[1].mon.bench.sets[1], { weight: 12, reps: 10, completed: true, exertion: 'good' });
    Object.assign(c.appState.weeks[1].thu.bench.sets[0], { weight: 14, reps: 8, completed: true, exertion: 'good' });
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
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true, exertion: 'good' }));
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
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true, exertion: 'good' }));
    let exercise = c.getWeekEvaluation(1).exercises[0];
    assert.match(c.weekExerciseOutlookHtml(1, exercise), /Voorlopige verwachting/);
    assert.match(c.weekExerciseOutlookHtml(1, exercise), /Nog 3 sets te beoordelen/);
    c.appState.weeks[1].thu.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true, exertion: 'good' }));
    exercise = c.getWeekEvaluation(1).exercises[0];
    assert.match(c.weekExerciseOutlookHtml(1, exercise), /Advies voor volgende week/);
    assert.match(c.weekExerciseOutlookHtml(1, exercise), /3 × 17 kg × 8 reps/);
    for (const day of ['mon', 'thu']) c.appState.weeks[1][day].bench.selectedName = '<script>alert(1)</script>';
    exercise = c.getWeekEvaluation(1).exercises[0];
    const result = c.weekExerciseOutlookHtml(1, exercise);
    assert.match(result, /&lt;script&gt;/);
    assert.doesNotMatch(result, /<script>/);
});


test('volume compares matching completed days instead of an incomplete week against a full week', () => {
    const { context: c } = setup();
    c.appState.weeks[2] = JSON.parse(JSON.stringify(c.appState.weeks[1]));
    for (const day of ['mon', 'thu']) c.appState.weeks[1][day].bench.sets.forEach(s => Object.assign(s, { weight: 10, reps: 10, completed: true, exertion: 'good' }));
    c.appState.weeks[2].mon.bench.sets.forEach(s => Object.assign(s, { weight: 10, reps: 12, completed: true, exertion: 'good' }));
    assert.match(c.getVolumeComparison(2), /\+60 kg \(\+20%\)/);
    assert.doesNotMatch(c.getVolumeComparison(2), /Volume is geen/);
    assert.match(c.getVolumeComparison(2, 'thu'), /zodra de training is afgerond/);
    c.appState.weeks[2].mon.bench.sets[0].reps = 4;
    assert.match(c.getVolumeComparison(2, 'mon'), /-20 kg/);
    c.appState.weeks[2].mon.bench.selectedName = 'Another exercise';
    assert.match(c.getVolumeComparison(2), /Schema gewijzigd/);
});

test('volume suppresses comparison for recovery, a new cycle and missing reference data', () => {
    const { context: c } = setup();
    assert.match(c.getVolumeComparison(7), /Herstelweek/);
    assert.match(c.getVolumeComparison(1), /nieuwe periode/);
    c.appState.weeks[2] = JSON.parse(JSON.stringify(c.appState.weeks[1]));
    c.appState.weeks[2].mon.bench.sets.forEach(s => Object.assign(s, { weight: 10, reps: 12, completed: true, exertion: 'good' }));
    assert.match(c.getVolumeComparison(2, 'mon'), /nog niet volledig gelogd/);
});

test('week seven detail card prepares a new cycle instead of prescribing a week eight target', () => {
    const { context: c } = setup();
    c.appState.weeks[7] = JSON.parse(JSON.stringify(c.appState.weeks[1]));
    c.appState.weeks[7].mon.bench.sets.forEach(s => Object.assign(s, { weight: 10, reps: 8, completed: true, exertion: 'good' }));
    const result = c.renderProgressDetails(7, 'mon', 'bench');
    assert.match(result, /Nieuwe cyclus voorbereiden/);
    assert.doesNotMatch(result, /Volgende week:/);
});

test('cancelling cycle closure leaves the entire training state intact', () => {
    const { context: c } = setup();
    Object.assign(c.appState, { currentWeek: 7, totalWeeks: 7, currentCycle: 1, nextCycle: { available: false } });
    let message;
    c.window = { confirm(text) { message = text; return false; } };
    const before = JSON.stringify(c.appState);
    assert.equal(c.canStartNewPeriod(), true);
    c.executeStartNewMesocycle();
    assert.match(message, /blijven onvoltooid in het archief/);
    assert.equal(JSON.stringify(c.appState), before);
});


test('saving without an effort choice preserves work but does not count it as good', () => {
    const { context: c } = setup();
    c.prepareCurrentLiveSetValues();
    assert.equal(c.liveWorkout.tempExertion, 'unknown');
    c.setLiveWeightManual('15'); c.setLiveRepsManual('12'); c.submitLiveSet(false);
    assert.equal(c.appState.weeks[1].thu.bench.sets[0].exertion, 'unknown');
    assert.equal(c.getExerciseWeekProgress(1, 'Dumbbell Bench Press').unknownSets, 1);
    assert.equal(c.getOverloadOutlook(1, 'Dumbbell Bench Press').title, 'Inspanning niet beoordeeld');
});

test('skipped sets are resolved without counting as completed or authorizing an increase', () => {
    const { context: c, elements } = setup();
    for (const day of ['mon', 'thu']) c.appState.weeks[1][day].bench.progressionPlan = { weight: 15, reps: 12 };
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { weight: 15, reps: 12, completed: true, exertion: 'good' }));
    c.appState.weeks[1].thu.skipped = true;
    const progress = c.getExerciseWeekProgress(1, 'Dumbbell Bench Press');
    assert.equal(progress.skippedSets, 3);
    assert.equal(progress.completedSets, 3);
    assert.equal(c.getNextProgression(progress, 2).provisional, false);
    assert.equal(c.getNextProgression(progress, 2).change, 'repeat');
    assert.equal(c.getDayCompletionStatus(1, 'thu').isReviewed, true);
    assert.equal(c.getDayCompletionStatus(1, 'thu').isFullyCompleted, false);
    Object.assign(c.appState, { currentWeek: 1, currentDay: 'thu' });
    elements.dayHeaderStatusText = {};
    c.updateDayTitleBanner();
    assert.equal(elements.dayHeaderStatusText.textContent, 'Overgeslagen');
    c.appState.weeks[1].thu.skipped = false;
    assert.equal(c.getNextProgression(c.getExerciseWeekProgress(1, 'Dumbbell Bench Press'), 2).provisional, true);
});

test('volume colours are positive, negative or neutral and recovery never shows a loss', () => {
    const { context: c } = setup();
    assert.equal(c.volumeComparisonClass('+60 kg (+20%)'), 'text-emerald-400');
    assert.equal(c.volumeComparisonClass('-60 kg (-20%)'), 'text-rose-400');
    assert.equal(c.volumeComparisonClass('0 kg (0%)'), 'text-slate-400');
    assert.equal(c.volumeComparisonClass(c.getVolumeComparison(7)), 'text-slate-400');
});


test('period dashboard counts performed sessions instead of the selected week and distinguishes skipped days', () => {
    const { context: c } = setup();
    c.appState.currentWeek = 7;
    c.appState.totalWeeks = 7;
    c.appState.weeks[1].mon.bench.sets.forEach(s => Object.assign(s, { completed: true, weight: 20, reps: 8, exertion: 'good' }));
    c.appState.weeks[1].thu.skipped = true;
    Object.assign(c.appState.weeks[1].thu.bench.sets[0], { completed: true, weight: 20, reps: 8 });
    const dashboard = c.getPeriodDashboardData();
    assert.equal(dashboard.completed, 1);
    assert.equal(dashboard.skipped, 1);
    assert.equal(dashboard.total, 28);
    assert.equal(dashboard.weeks[0].volume, 640);
    assert.equal(dashboard.attention[0].count, 1);
    assert.equal(dashboard.weeks[6].deload, true);
    assert.match(c.periodDashboardHtml(), /4% voltooid/);
    assert.match(c.periodDashboardHtml(), /Week 7/);
});

test('period exercise progress excludes deload but its volume includes only two valid sets', () => {
    const { context: c } = setup();
    c.appState.currentCycle = 1;
    c.appState.weeks[7] = { mon: { bench: { selectedName: 'Dumbbell Bench Press', sets: Array.from({ length: 3 }, () => ({ completed: true, weight: 14, reps: 8, exertion: 'good' })) } } };
    const dashboard = c.getPeriodDashboardData();
    assert.equal(dashboard.weeks[6].volume, 224);
    assert.equal(dashboard.weeks[6].sets, 2);
    assert.equal(dashboard.exercises.length, 0);
    assert.equal(dashboard.attention.length, 0);
    assert.match(c.periodDashboardHtml(), /Nog geen prestaties/);
});


test('volume bars use an unclipped linear scale and empty weeks have zero height', () => {
    const { context: c, elements } = setup();
    const columns = [];
    elements.trendChartContainer = { innerHTML: '', appendChild: col => columns.push(col) };
    c.document.createElement = () => ({});
    c.appState.totalWeeks = 7;
    c.calculateWeekVolume = week => ({ 1: 7000, 2: 8000 }[week] || 0);
    c.renderWorkloadChart();
    assert.match(columns[0].innerHTML, /height:87.5%/);
    assert.match(columns[1].innerHTML, /height:100%/);
    assert.match(columns[2].innerHTML, /height:0%/);
    assert.match(columns[0].innerHTML, /7\.000/);
    assert.match(columns[6].innerHTML, /purple/);
    assert.equal(columns[0].type, 'button');
});


test('week chart colors distinguish completed weeks, selection, open weeks and deload', () => {
    const { context: c, elements } = setup();
    const columns = [];
    elements.trendChartContainer = { innerHTML: '', appendChild: col => columns.push(col) };
    c.document.createElement = () => ({});
    c.appState.currentWeek = 4;
    c.calculateWeekVolume = () => 1000;
    c.getDayCompletionStatus = (week, day) => ({ isFullyCompleted: week < 4 && !(week === 2 && day === 'fri') });
    c.renderWorkloadChart();
    assert.match(columns[0].innerHTML, /bg-emerald-500/);
    assert.match(columns[1].innerHTML, /bg-slate-500/);
    assert.match(columns[2].innerHTML, /bg-emerald-500/);
    assert.match(columns[3].innerHTML, /bg-blue-500/);
    assert.match(columns[6].innerHTML, /bg-purple-500/);
});


test('week seven shows a purple deload start card and explicit start labels', () => {
    const { context: c, elements } = setup();
    c.appState.currentWeek = 7;
    c.appState.currentDay = 'mon';
    c.appState.weeks[7] = c.appState.weeks[1];
    elements.startWorkoutHeroBtn = {};
    c.updateWorkoutHeroButtons();
    assert.match(elements.startWorkoutHeroBtn.innerHTML, /Start Deloadtraining/);
    c.document.createElement = () => ({});
    let card;
    const container = { appendChild: item => { card = item; } };
    c.renderLockedRoutineView(container, c.appState.weeks[7].mon, [], true, true, false);
    assert.match(card.className, /deload-training/);
    assert.match(card.innerHTML, /Start Deloadtraining/);
    c.appState.currentWeek = 1;
    c.updateWorkoutHeroButtons();
    assert.match(elements.startWorkoutHeroBtn.innerHTML, /Start Training/);
    c.renderLockedRoutineView(container, c.appState.weeks[1].mon, [], false, true, false);
    assert.doesNotMatch(card.className, /deload-training/);
});


test('performance indicators show weight and reps separately and keep deload neutral', () => {
    const { context: c } = setup();
    const mixed = c.performanceChangeHtml({ weight: 15, reps: 12 }, { weight: 17, reps: 8 });
    assert.match(mixed, /text-emerald-400[^]*▲ \+2 kg/);
    assert.match(mixed, /text-rose-400[^]*▼ -4 reps/);
    assert.match(c.performanceChangeHtml({ weight: 6, reps: 12 }, { weight: 6, reps: 12 }), /= 0 reps/);
    const deload = c.performanceChangeHtml({ weight: 15, reps: 12 }, { weight: 10, reps: 8 }, false, true);
    assert.match(deload, /text-purple-300/);
    assert.doesNotMatch(deload, /▼|text-rose/);
    assert.doesNotMatch(c.performanceChangeHtml({ weight: 0, reps: 8 }, { weight: 0, reps: 12 }, true), /kg/);
    assert.match(c.comparisonIndicatorHtml('+480 kg (+12%)'), /▲ \+480 kg/);
});


test('adaptive deload uses readiness and never treats missing or partial evidence as recovered', () => {
    const { context: c } = setup();
    const base = { targetWeight: 20, targetReps: 8, completedSets: 6, requiredSets: 6, achieved: true, exertion: 'good', recovery: 'recovered' };
    assert.equal(c.getNextProgression(base, 7).weight, 16);
    assert.equal(c.getNextProgression({ ...base, recovery: 'exhausted' }, 7).weight, 12);
    for (const override of [{ recovery: 'unknown' }, { unknownSets: 1 }, { completedSets: 3 }, { exertion: 'max' }]) {
        assert.equal(c.getNextProgression({ ...base, ...override }, 7).weight, 14);
    }
    assert.equal(c.getNextProgression({ ...base, achieved: false, maxSets: 3 }, 7).weight, 12);
    assert.equal(c.getNextProgression({ ...base, isBodyweight: true, recovery: 'exhausted' }, 7).reps, 5);
});

test('early recovery proposal requires repeated comparable declines and fatigue at multiple exercises', () => {
    const { context: c } = setup();
    c.appState.currentCycle = 1;
    c.appState.weeks = { 1: { mon: { recovery: 'tired' } }, 2: { mon: { recovery: 'tired' } }, 3: { mon: { recovery: 'tired' } }, 4: { mon: { a: { selectedName: 'Bench' }, b: { selectedName: 'Row' } } } };
    const entries = [1, 2, 3].map(week => ({ cycle: 1, week, day: 'mon', isDeload: false, sets: [1, 2, 3].map(position => ({ position, weight: 20, reps: 13 - week, exertion: 'max' })) }));
    c.getExerciseHistory = () => entries;
    assert.match(c.earlyRecoveryAdviceHtml(4, 'mon'), /Overweeg eerder extra herstel/);
    c.appState.weeks[3].mon.recovery = 'unknown';
    assert.equal(c.earlyRecoveryAdviceHtml(4, 'mon'), '');
    c.appState.weeks[3].mon.recovery = 'tired';
    entries[2].sets[0].weight = 22;
    assert.equal(c.earlyRecoveryAdviceHtml(4, 'mon'), '');
    assert.equal(c.earlyRecoveryAdviceHtml(7, 'mon'), '');
});


test('evidence explanation distinguishes studies from app rules and removes fabricated strength norms', () => {
    const { context: c, elements } = setup();
    elements.strengthBenchmarkContent = { innerHTML: '' };
    c.renderBenchmarkModal();
    const output = elements.strengthBenchmarkContent.innerHTML;
    assert.match(output, /geen wetenschappelijk gevalideerde normen/);
    assert.match(output, /ACSM-richtlijn \(2026\)/);
    assert.match(output, /geen gevalideerde vermoeidheidstest/);
    assert.doesNotMatch(output, /40% meer stabilisatiewerk|Evidence-based standaarden gekalibreerd/);
});


test('explicit recovery check recalculates week seven from the build week while manual set input wins', () => {
    const { context: c } = setup();
    c.appState.weeks[6] = JSON.parse(JSON.stringify(c.appState.weeks[1]));
    for (const day of ['mon', 'thu']) c.appState.weeks[6][day].bench.sets.forEach(set => Object.assign(set, { completed: true, weight: 20, reps: 8, exertion: 'good' }));
    c.appState.weeks[7] = JSON.parse(JSON.stringify(c.appState.weeks[1]));
    Object.assign(c.appState.weeks[7].mon.bench.sets[0], { completed: true, weight: 14, reps: 8, exertion: 'good' });
    c.appState.weeks[7].thu.recovery = 'exhausted';
    c.liveWorkout.weekNum = 7;
    c.liveWorkout.dayKey = 'thu';
    c.prepareCurrentLiveSetValues();
    assert.equal(c.liveWorkout.tempWeight, 12);
    assert.equal(c.getSlotTargetAdvice(7, 'thu', 'bench').prevWeekFound, 'Week 6');
    c.setLiveWeightManual('13');
    c.prepareCurrentLiveSetValues();
    assert.equal(c.liveWorkout.tempWeight, 13);
});


test('failed recovery save preserves the previous check and goals', async () => {
    const { context: c } = setup();
    c.appState.currentWeek = 1;
    c.appState.currentDay = 'mon';
    c.appState.weeks[1].mon.sessionId = 123;
    c.appState.weeks[1].mon.recovery = 'tired';
    c.jsonApi = async () => ({ ok: false });
    c.showToast = () => {};
    const start = html.indexOf('    async function setRecoveryCheck(');
    const end = html.indexOf('\n    function ', start + 1);
    vm.runInContext(html.slice(start, end), c);
    await c.setRecoveryCheck('exhausted');
    assert.equal(c.appState.weeks[1].mon.recovery, 'tired');
    assert.equal(c.saved, undefined);
});


test('exercise increments apply to weekly progression and recovery while other exercises use the default', () => {
    const { context: c } = setup();
    c.appState.overloadIncrement = 2;
    c.appState.exerciseIncrements = { 'dumbbell bench press': 0.5 };
    for (const day of ['mon', 'thu']) {
        const slot = c.appState.weeks[1][day].bench;
        slot.progressionPlan = { weight: 6, reps: 12, minReps: 8, maxReps: 12 };
        slot.sets.forEach(set => Object.assign(set, { weight: 6, reps: 12, completed: true, exertion: 'good' }));
    }
    const progress = c.getExerciseWeekProgress(1, 'Dumbbell Bench Press');
    assert.equal(c.getNextProgression(progress, 2).weight, 6.5);
    assert.equal(c.getNextProgression(progress, 7).weight, 4);
    assert.equal(c.getExerciseIncrement(' DUMBBELL BENCH PRESS '), 0.5);
    assert.equal(c.getNextProgression({ ...progress, exerciseName: 'Other exercise' }, 2).weight, 8);
    assert.equal(c.getWeekEvaluation(1).exercises[0].nextAdvisedWeight, 6.5);
});

test('profile summary uses saved personal details and only selected equipment', () => {
    const { context: c } = setup();
    c.appState.userProfile = { birthYear: 1993, bodyWeightKg: 71, experienceLevel: 'beginner', equipment: { dumbbells: true, pullup_bar: false } };
    assert.equal(c.profileSummaryHtml(), 'Geboortejaar 1993 · 71 kg · Beginner');
    assert.equal(c.equipmentSummaryHtml(), 'Materiaal: Dumbbells.');
    c.appState.userProfile = {};
    assert.match(c.profileSummaryHtml(), /niet ingevuld/);
    assert.equal(c.equipmentSummaryHtml(), 'Nog geen materiaal geselecteerd.');
});

test('settings save commits only after the server accepts the change and reports failures', async () => {
    const { context: c } = setup();
    const start = html.indexOf('    async function persistSettingsForm(');
    const end = html.indexOf('\n    function ', start + 1);
    vm.runInContext(html.slice(start, end), c);
    const status = { textContent: '' }, button = { disabled: false };
    const form = { querySelector: selector => selector.includes('status') ? status : button };
    c.appState.overloadIncrement = 2;
    c.jsonApi = async () => ({ ok: false });
    await c.persistSettingsForm(form, '/api/preferences', {}, { overloadIncrement: 0.5 });
    assert.equal(c.appState.overloadIncrement, 2);
    assert.equal(c.saved, undefined);
    assert.match(status.textContent, /Niet opgeslagen/);
    assert.equal(button.disabled, false);
    c.jsonApi = async () => ({ ok: true });
    await c.persistSettingsForm(form, '/api/preferences', {}, { overloadIncrement: 0.5 });
    assert.equal(c.appState.overloadIncrement, 0.5);
    assert.equal(c.saved, true);
    assert.match(status.textContent, /Opgeslagen/);
});


test('settings opens with saved profile and unique exercise overrides and escapes exercise names', () => {
    const { context: c, elements } = setup();
    for (const id of ['trainingSettingsContent', 'profileSettingsContent', 'settingsVideoPanel']) elements[id] = {};
    elements.settingsModal = { open: false, showModal() { this.open = true; } };
    c.appState.userProfile = { birthYear: 1993, bodyWeightKg: 71, experienceLevel: 'beginner', equipment: { dumbbells: true } };
    c.appState.exerciseIncrements = { 'dumbbell bench press': 0.5 };
    c.appState.weeks[1].mon.bench.selectedName = '<script>alert("exercise")</script>';
    c.openSettingsModal();
    assert.equal(elements.settingsModal.open, true);
    assert.match(elements.profileSettingsContent.innerHTML, /value="1993"/);
    assert.match(elements.profileSettingsContent.innerHTML, /value="71"/);
    assert.match(elements.trainingSettingsContent.innerHTML, /value="0.5"/);
    assert.equal((elements.trainingSettingsContent.innerHTML.match(/data-exercise="dumbbell bench press"/g) || []).length, 1);
    assert.doesNotMatch(elements.trainingSettingsContent.innerHTML, /<script>/);
    assert.match(elements.trainingSettingsContent.innerHTML, /&lt;script&gt;/);
});


test('muscle sets separate direct and supporting work and keep missed-target work in the count', () => {
    const { context: c } = setup();
    c.appState.trainingRules = { muscles: { chest: 'Borst', triceps: 'Triceps' }, exerciseMuscles: { 'dumbbell bench press': { primary: 'chest', secondary: ['triceps'] } } };
    Object.assign(c.appState.weeks[1].mon.bench.sets[0], { weight: 15, reps: 3, completed: true });
    Object.assign(c.appState.weeks[1].mon.bench.sets[1], { weight: 15, reps: 12, completed: false });
    Object.assign(c.appState.weeks[1].mon.bench.sets[2], { weight: 0, reps: 12, completed: true });
    const report = c.weekMuscleSets(1);
    assert.equal(report.groups.chest.completed, 1);
    assert.equal(report.groups.chest.planned, 6);
    assert.equal(report.groups.triceps.completed, 0);
    assert.equal(report.groups.triceps.indirect, 1);
    assert.match(c.muscleSetsHtml(1), /1\/6/);
    c.appState.weeks[7] = JSON.parse(JSON.stringify(c.appState.weeks[1]));
    const deload = c.weekMuscleSets(7);
    assert.equal(deload.groups.chest.planned, 4);
    assert.equal(deload.isDeload, true);
    assert.match(c.muscleSetsHtml(7), /Herstelsets/);
    assert.doesNotMatch(c.muscleSetsHtml(7), /Rond 10/);
    c.appState.weeks[1].thu.bench.selectedName = '<Unknown>';
    assert.match(c.muscleSetsHtml(1), /&lt;Unknown&gt;/);
    assert.equal(c.weekMuscleSets(1).groups.chest.planned, 3);
});

test('one maximal set can be confirmed next week but repeated missed maximal sessions reduce the goal', () => {
    const { context: c } = setup();
    c.appState.weeks[2] = JSON.parse(JSON.stringify(c.appState.weeks[1]));
    for (const week of [1, 2]) for (const day of ['mon', 'thu']) {
        const slot = c.appState.weeks[week][day].bench;
        slot.progressionPlan = { weight: 15, reps: 8, minReps: 8, maxReps: 12 };
        slot.sets.forEach((set, index) => Object.assign(set, { weight: 15, reps: 8, completed: true, exertion: day === 'mon' && index === 2 ? 'max' : 'good' }));
    }
    const first = c.getExerciseWeekProgress(1, 'Dumbbell Bench Press');
    assert.equal(c.getNextProgression(first, 2).change, 'repeat');
    const confirmed = c.getExerciseWeekProgress(2, 'Dumbbell Bench Press');
    assert.equal(confirmed.confirmedEffort, true);
    assert.equal(c.getNextProgression(confirmed, 3).reps, 10);
    c.appState.weeks[2].mon.bench.progressionPlan.reps = 10;
    c.appState.weeks[2].thu.bench.progressionPlan.reps = 10;
    for (const day of ['mon', 'thu']) c.appState.weeks[2][day].bench.sets.forEach(set => { set.reps = 10; });
    assert.equal(c.getExerciseWeekProgress(2, 'Dumbbell Bench Press').confirmedEffort, false);
    c.appState.weeks[2].thu.bench.sets[0].exertion = 'max';
    assert.equal(c.getExerciseWeekProgress(2, 'Dumbbell Bench Press').confirmedEffort, false);
    for (const day of ['mon', 'thu']) c.appState.weeks[2][day].bench.sets.forEach(set => Object.assign(set, { reps: 6, exertion: 'max' }));
    const missed = c.getExerciseWeekProgress(2, 'Dumbbell Bench Press');
    assert.equal(missed.repeatedMaxMisses, true);
    assert.equal(c.getNextProgression(missed, 3).weight, 13);
});

test('strength and combined goals change only designated ranges and preserve frozen plans', () => {
    const { context: c } = setup();
    assert.deepEqual(JSON.parse(JSON.stringify(c.goalRepetitionRange('strength', 'slot_a1', 8))), { minReps: 4, maxReps: 6 });
    assert.equal(c.goalRepetitionRange('combined', 'slot_b2', 8).minReps, 8);
    assert.equal(c.goalRepetitionRange('strength', 'slot_a1', 8, true).minReps, 8);
    const progress = { targetWeight: 20, targetReps: 4, minReps: 4, maxReps: 6, completedSets: 6, requiredSets: 6, achieved: true, exertion: 'good', isBodyweight: false };
    assert.equal(c.getNextProgression(progress, 2).reps, 5);
    assert.equal(c.getNextProgression({ ...progress, targetReps: 6 }, 2).reps, 4);
    assert.equal(c.getNextProgression({ ...progress, targetReps: 6 }, 2).weight, 22);
    c.appState.trainingGoal = 'strength';
    c.appState.weeks[1].mon.bench.progressionPlan = { weight: 15, reps: 10, minReps: 8, maxReps: 12 };
    assert.equal(c.getSlotProgress(1, 'mon', 'bench').minReps, 8);
    c.SPLIT_INFO.tue = { slots: ['slot_b1', 'slot_b2', 'slot_b3'] };
    c.applyGoalOrdering();
    assert.equal(c.SPLIT_INFO.tue.slots[0], 'slot_b2');
    c.appState.trainingGoal = 'hypertrophy';
    c.applyGoalOrdering();
    assert.equal(c.SPLIT_INFO.tue.slots[0], 'slot_b1');
});

test('week report uses a single focusable scroll container constrained to the viewport', () => {
    const start = html.indexOf('<div id="weekReportModal"');
    const end = html.indexOf('<!--', start);
    const modal = html.slice(start, end);
    assert.match(modal, /id="weekReportScrollArea"[^>]*tabindex="0"/);
    assert.match(modal, /max-h-\[calc\(100dvh-2rem\)\]/);
    assert.equal((modal.match(/overflow-y-auto/g) || []).length, 1);
    assert.doesNotMatch(modal, /overflow-hidden|no-scrollbar/);
});
