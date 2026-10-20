import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const html = readFileSync(new URL('../resources/ironforge.html', import.meta.url), 'utf8');
const source = [...html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].map(match => match[1]).filter(text => text.trim()).join('\n');

function harness() {
  const elements = new Map();
  const element = (markup = '') => {
    const classes = new Set((markup.match(/class="([^"]*)"/)?.[1] || '').split(/\s+/).filter(Boolean));
    return ({
    innerHTML: '', textContent: '', style: { zIndex: markup.match(/z-index:\s*(\d+)/)?.[1] || '' }, dataset: {}, value: '',
    classList: { add(name) { classes.add(name); }, remove(name) { classes.delete(name); }, contains(name) { return classes.has(name); }, toggle(name) { if (classes.has(name)) classes.delete(name); else classes.add(name); } },
    addEventListener() {}, setAttribute() {}, getAttribute() { return null; }, focus() {},
    querySelector() { return null; }, querySelectorAll() { return []; }, appendChild(child) { return child; }
  });
  };
  const document = {
    readyState: 'loading', visibilityState: 'visible',
    getElementById(id) { if (!elements.has(id)) elements.set(id, element(html.match(new RegExp('<[^>]*id="' + id + '"[^>]*>'))?.[0] || '')); return elements.get(id); },
    addEventListener() {}, querySelectorAll() { return []; }, querySelector() { return null; },
    createElement: element, body: element()
  };
  const context = vm.createContext({
    console: { log() {}, warn() {}, error() {} }, document,
    window: { addEventListener() {}, innerWidth: 1000, location: { hash: '', protocol: 'http:', hostname: 'localhost' }, confirm() { return true; } },
    navigator: {}, localStorage: { getItem() { return null; }, setItem() {} },
    setTimeout, clearTimeout, setInterval() { return 0; }, clearInterval() {},
    fetch: async () => { throw Error('Unexpected request'); }, URL, URLSearchParams
  });
  vm.runInContext(source, context);
  vm.runInContext('showToast = () => {};', context);
  return { context, elements, run: code => vm.runInContext(code, context) };
}

function proposal(key = 'balanced') {
  const groups = { mon: ['push_a', 'Push A', 'slot_a1', 'Dumbbell Bench Press'], tue: ['pull_a', 'Pull A', 'slot_a2', 'Barbell Row'],
    thu: ['push_b', 'Push B', 'slot_a1', 'Incline Dumbbell Press'], fri: ['pull_b', 'Pull B', 'slot_a2', 'Dumbbell One-Arm Row'] };
  const schema = {}, days = [], definitions = {};
  for (const [day, [variantKey, title, slotKey, name]] of Object.entries(groups)) {
    const slot = { selectedName: name, weight: null, targetReps: 8, minReps: 8, maxReps: 12, sets: 2, rest: 150, weightLabel: 'Kg per dumbbell' };
    schema[day] = { [slotKey]: slot };
    days.push({ day, title, slots: [{ slotKey, ...slot }] });
    definitions[day] = { title, variantKey, version: 'v1', exercises: { [slotKey]: slot } };
  }
  return { schema, days, programTemplate: { key, type: 'push_pull', days: definitions },
    exerciseHistory: {}, library: { templates: [{ key: 'balanced', name: 'Gemengd' }, { key: 'dumbbells', name: 'Dumbbells' }] } };
}

function prepare(h, p = proposal()) {
  h.context.fixture = p;
  h.run("appState = { currentCycle: 1, cycleId: 1, currentWeek: 7, totalWeeks: 7, currentDay: 'mon', trainingGoal: 'hypertrophy', weeks: {7:{mon:{slot_a1:{selectedName:'Barbell Bench Press',sets:[{weight:30,reps:10,completed:true}]}}}}, userProfile: { equipment: {dumbbells:true,barbell:true,bench:true,adjustable_bench:true,rack:true} }, programLibrary: { variants: [], roles: {slot_a1:{exercises:['Dumbbell Bench Press','Incline Dumbbell Press']},slot_a2:{exercises:['Barbell Row','Dumbbell One-Arm Row']}}} }; nextCycleProposal = JSON.parse(JSON.stringify(fixture));");
}

test('failed loading never seeds an empty training or enables persistence', async () => {
  const h = harness();
  h.context.fetch = async () => ({ ok: false, status: 500 });
  h.run("seedInitialWeeks = () => { throw Error('must not seed'); };");
  await assert.rejects(h.run('initAppState()'), /marker-state HTTP 500/);
  assert.equal(h.run('laravelPersistEnabled'), false);
  assert.equal(h.run('Object.keys(appState.weeks).length'), 0);
});

test('wizard displays guide before save and preserves candidate, weight and active training', () => {
  const h = harness(); prepare(h);
  const before = h.run('JSON.stringify(appState.weeks)');
  h.run('openSetupRoutineModal(true); onSetupWeightChange("mon","slot_a1",17.5); openSetupGuide("mon","slot_a1");');
  assert.equal(h.run('guideActiveExercise'), 'Dumbbell Bench Press');
  assert.equal(h.elements.get('guideTitle').textContent, 'Dumbbell Bench Press');
  assert.match(h.elements.get('guideTargetRest').textContent, /2 × 8–12 reps/);
  assert.match(h.elements.get('setupRoutineContent').innerHTML, /Video & uitleg/);
  assert.doesNotMatch(h.elements.get('setupRoutineContent').innerHTML, /Cyclus piekdoel|Normwaarde/);
  h.run('closeGuideModal();');
  assert.equal(h.run('setupWizardState.schema.mon.slot_a1.weight'), 17.5);
  assert.equal(h.run('JSON.stringify(appState.weeks)'), before);
  const handlers = [...h.elements.get('setupRoutineContent').innerHTML.matchAll(/(?:onclick|onchange)="([^"]*)"/g)];
  for (const [, handler] of handlers) new vm.Script(handler.replaceAll('&quot;', '"').replaceAll('&#39;', "'"));
});

test('switching goal and proposal retains manually entered weight', async () => {
  const h = harness(); prepare(h);
  const requests = [];
  h.context.fetch = async (url, options) => {
    const body = JSON.parse(options.body); requests.push(body);
    const response = proposal(body.templateKey);
    if (body.schema.mon?.slot_a1?.weightEdited) Object.assign(response.schema.mon.slot_a1, body.schema.mon.slot_a1);
    response.schema.mon.slot_a1.targetReps = body.training_goal === 'strength' ? 4 : 8;
    return { ok: true, json: async () => response };
  };
  h.run('openSetupRoutineModal(true); onSetupWeightChange("mon","slot_a1",17.5); closeSetupRoutineModal(); appState.nextTrainingGoal = "strength";');
  await h.run('selectPeriodTemplate("balanced")');
  assert.equal(requests[0].schema.mon.slot_a1.weight, 17.5);
  assert.equal(h.run('nextCycleProposal.schema.mon.slot_a1.targetReps'), 4);
  await h.run('selectPeriodTemplate("dumbbells")');
  await h.run('selectPeriodTemplate("balanced")');
  assert.equal(h.run('nextCycleProposal.schema.mon.slot_a1.weight'), 17.5);
});

test('variant choice changes only the requested day in the preview request', async () => {
  const h = harness(); prepare(h);
  let request;
  h.context.fetch = async (url, options) => {
    request = JSON.parse(options.body);
    return { ok: true, json: async () => proposal() };
  };
  h.run('openSetupRoutineModal(true); onSetupWeightChange("thu","slot_a1",21);');
  await h.run('onSetupVariantChange("mon","push_e")');
  assert.equal(request.dayVariants.mon, 'push_e');
  assert.equal(request.dayVariants.thu, 'push_b');
  assert.equal(request.schema.mon, undefined);
  assert.equal(request.schema.thu.slot_a1.weight, 21);
});

test('planned sets support two to five sets and deload caps', () => {
  const h = harness();
  assert.equal(h.run('getPlannedSetCount({prescription:{sets:2}},1)'), 2);
  assert.equal(h.run('getPlannedSetCount({prescription:{sets:5}},1)'), 5);
  assert.equal(h.run('getPlannedSetCount({prescription:{sets:5}},7)'), 2);
});

test('failed period start preserves live state and double click issues only one request', async () => {
  const h = harness(); prepare(h);
  h.run('ensureTrainingSaved = async () => {};');
  let release, count = 0;
  h.context.fetch = async () => {
    count++;
    return new Promise(resolve => { release = () => resolve({ ok: false, json: async () => ({errors:{cycle:['Stale proposal']}}) }); });
  };
  const before = h.run('JSON.stringify(appState)');
  const first = h.run('executeStartNewMesocycle(true,nextCycleProposal.schema,nextCycleProposal.programTemplate)');
  await Promise.resolve();
  assert.equal(await h.run('executeStartNewMesocycle(true,nextCycleProposal.schema,nextCycleProposal.programTemplate)'), false);
  release();
  assert.equal(await first, false);
  assert.equal(count, 1);
  assert.equal(h.run('JSON.stringify(appState)'), before);
});


test('six-week report offers recovery or a new period without a mandatory seventh week', () => {
  const h = harness(); prepare(h);
  h.run("appState.programDefinition = {type:'push_pull'}; appState.totalWeeks=6; appState.currentWeek=6; appState.weeks[6]=appState.weeks[7]; delete appState.weeks[7]; renderWeekReportModal(6);");
  const report = h.elements.get('weekReportModalContent').innerHTML;
  assert.match(report, /Lichte herstelweek/);
  assert.match(report, /Nieuwe periode 2 kiezen/);
  assert.match(report, /geen bewezen optimale duur/);
  assert.doesNotMatch(report, /Volgende week is Week 7|Ga naar Week 7|Advies voor Week 7/);
  assert.equal(h.run('getWeekEvaluation(6).isNextDeload'), false);
});

test('existing seven-week periods keep their planned recovery week', () => {
  const h = harness(); prepare(h);
  h.run("appState.weeks[6]=appState.weeks[7]; renderWeekReportModal(6);");
  const report = h.elements.get('weekReportModalContent').innerHTML;
  assert.match(report, /Volgende week is Week 7/);
  assert.match(report, /Ga naar Week 7/);
  assert.doesNotMatch(report, /onclick="chooseRecoveryWeek/);
});

test('Push Pull report uses the same progression advice as the backend', () => {
  const h = harness(); prepare(h);
  h.run("appState.programDefinition = {type:'push_pull'}; appState.totalWeeks=6; appState.currentWeek=1; appState.weeks[1]=appState.weeks[7]; getExerciseWeekProgress = () => ({requiredSets:3,completedSets:3,skippedSets:0}); getNextProgression = () => ({weight:999,reps:99,status:'Wrong',reason:'Wrong'}); serverWeekReports.set(1,{exercises:[{name:'Barbell Bench Press',next:{weight:30,reps:11,requiredSets:3,status:'Extra herhaling',reason:'Eigen prestaties'}}]});");
  const result = h.run('getOverloadOutlook(1,"Barbell Bench Press")');
  assert.equal(result.next.weight, 30);
  assert.equal(result.next.reps, 11);
  assert.equal(result.title, 'Extra herhaling');
  assert.equal(result.reason, 'Eigen prestaties');
});

test('material alternative explains exactly which equipment is missing', () => {
  const h = harness();
  const advice = proposal();
  Object.assign(advice.days[1].slots[0], { materialAlternative: 'Chest-Supported Dumbbell Row', missingEquipment: ['adjustable_bench'] });
  h.context.fixture = advice;
  const preview = h.run('renderNextCycleAdviceSlots(fixture)');
  assert.match(preview, /Materiaalalternatief voor Chest-Supported Dumbbell Row/);
  assert.match(preview, /Niet aangevinkt in je materiaalprofiel: Verstelbaar bankje/);
});


test('proposal footer opens the selected Push Pull variants instead of the current routine', () => {
  const h = harness(); prepare(h);
  h.run("appState.programLibrary.variants = ['push','pull'].flatMap(family => ['A','B','C','D','E','F'].map(letter => ({family,key:family+'_'+letter.toLowerCase(),name:(family==='push'?'Push ':'Pull ')+letter}))); startNewMesocyclePrompt();");
  const actions = h.elements.get('startCycleModalActions').innerHTML;
  const handler = actions.match(/id="choosePeriodVariantsBtn" onclick="([^"]+)"/)?.[1];
  assert.ok(handler);
  assert.doesNotMatch(h.elements.get('startCycleModalContent').innerHTML, /choosePeriodVariantsBtn/);
  assert.match(html, /id="startCycleModalContent" class="[^"]*overflow-y-auto/);
  assert.match(html, /id="startCycleModalActions" class="[^"]*shrink-0/);
  const before = h.run('JSON.stringify(appState.weeks)');
  h.run(handler);
  assert.equal(h.run('setupWizardState.forNewCycle'), true);
  assert.equal(h.elements.get('setupRoutineModalTitle').textContent, 'Nieuwe Push/Pull-periode inrichten');
  let wizard = h.elements.get('setupRoutineContent').innerHTML;
  assert.match(wizard, /Dagvariant/);
  for (const letter of ['A','B','C','D','E','F']) assert.match(wizard, new RegExp('>Push '+letter+'<'));
  assert.doesNotMatch(wizard, /Upper A|Upper B/);
  h.run('setSetupWizardDay("tue")');
  wizard = h.elements.get('setupRoutineContent').innerHTML;
  for (const letter of ['A','B','C','D','E','F']) assert.match(wizard, new RegExp('>Pull '+letter+'<'));
  assert.equal(h.run('JSON.stringify(appState.weeks)'), before);
});

test('exercise guide stays above the setup wizard and closing it keeps the draft open', () => {
  const h = harness(); prepare(h);
  h.run('openSetupRoutineModal(true); openSetupGuide("mon","slot_a1");');
  const guide = h.elements.get('exerciseGuideModal'), wizard = h.elements.get('setupRoutineModal');
  assert.equal(guide.classList.contains('hidden'), false);
  assert.equal(wizard.classList.contains('hidden'), false);
  assert.ok(Number(guide.style.zIndex) > Number(wizard.style.zIndex));
  assert.equal(h.elements.get('guideActiveLabel').textContent, 'Gekozen in voorstel:');
  h.run('closeGuideModal()');
  assert.equal(guide.classList.contains('hidden'), true);
  assert.equal(wizard.classList.contains('hidden'), false);
});

test('video from a proposal uses its exact exercise without opening the routine editor', () => {
  const h = harness(); prepare(h);
  h.run('startNewMesocyclePrompt();');
  const preview = h.elements.get('startCycleModalContent').innerHTML;
  const handler = preview.match(/onclick="(openProposalGuide\([^"]+\))"/)?.[1];
  assert.ok(handler);
  const before = h.run('JSON.stringify(appState.weeks)');
  h.run(handler);
  assert.equal(h.run('guideActiveExercise'), 'Dumbbell Bench Press');
  assert.equal(h.elements.get('guideTitle').textContent, 'Dumbbell Bench Press');
  assert.match(h.elements.get('guideTargetRest').textContent, /2 × 8–12/);
  assert.equal(h.elements.get('startCycleModal').classList.contains('hidden'), false);
  assert.ok(Number(h.elements.get('exerciseGuideModal').style.zIndex) > Number(h.elements.get('startCycleModal').style.zIndex));
  assert.equal(h.run('JSON.stringify(appState.weeks)'), before);
});


test('proposal guide shows execution only and cannot replace a variant exercise', async () => {
  const h = harness(); prepare(h);
  let requests = 0;
  h.context.fetch = async () => { requests++; throw Error('No exercise request expected'); };
  const before = h.run('JSON.stringify(nextCycleProposal)');
  h.run('openProposalGuide("mon","slot_a1"); previewAlternativeInGuide("Incline Dumbbell Press");');
  await h.run('applyPreviewedExercise()');
  assert.equal(requests, 0);
  assert.equal(h.run('guidePreviewedExercise'), 'Dumbbell Bench Press');
  assert.equal(h.run('JSON.stringify(nextCycleProposal)'), before);
  assert.equal(h.elements.get('guideAlternativesSection').classList.contains('hidden'), true);
  assert.equal(h.elements.get('applyExerciseBtn').classList.contains('hidden'), true);
});

test('day variant can be selected but its exercises are read only and weights remain editable', async () => {
  const h = harness(); prepare(h);
  h.run('openSetupRoutineModal(true);');
  const content = h.elements.get('setupRoutineContent').innerHTML;
  assert.doesNotMatch(content, /<select aria-label="Oefening/);
  assert.match(content, /Oefening uit dagvariant/);
  assert.match(content, /Dumbbell Bench Press/);
  assert.match(content, /A–F beheren via Beheer → Programma/);
  const before = h.run('JSON.stringify(setupWizardState.schema)');
  await h.run('onSetupSlotChange("mon","slot_a1","Incline Dumbbell Press")');
  assert.equal(h.run('JSON.stringify(setupWizardState.schema)'), before);
  h.run('onSetupWeightChange("mon","slot_a1",17.5)');
  assert.equal(h.run('setupWizardState.schema.mon.slot_a1.weight'), 17.5);
});

test('old current-routine editor is removed and menu management uses a matching card', () => {
  const h = harness(); prepare(h);
  h.run('openSetupRoutineModal();');
  assert.equal(h.run('setupWizardState.forNewCycle'), true);
  assert.equal(h.elements.get('setupRoutineModalTitle').textContent, 'Nieuwe Push/Pull-periode inrichten');
  const menu = html.match(/<!-- Drawer Content[^]*?<!-- Drawer Footer/)[0];
  assert.doesNotMatch(menu, /Huidig schema aanpassen|openSetupRoutineModal/);
  assert.match(menu, /href="\/beheer" class="[^"]*rounded-xl[^"]*flex items-center gap-3/);
  assert.match(menu, /Dagvarianten A–F, periodevoorstellen en oefeningen/);
  assert.match(menu, /Startgewichten & uitleg/);
  const managementStyle = menu.match(/<a href="\/beheer" class="([^"]*)"/)[1];
  const settingsStyle = menu.match(/<button onclick="closeMenuDrawer\(\); openSettingsModal\(\)" class="([^"]*)"/)[1];
  assert.equal(settingsStyle, managementStyle);
  assert.ok(menu.indexOf('id="menuNewPeriodBtn"') < menu.indexOf('openBenchmarkModal()'));
});

test('schema lock controls are absent from page and setup wizard', () => {
  assert.doesNotMatch(html, /id="routineSetupBtn"/);
  const h = harness(); prepare(h); h.run('openSetupRoutineModal(true);');
  assert.doesNotMatch(h.elements.get('setupRoutineContent').innerHTML, /Ontgrendelen|Vergrendelen|Schema Vast|Schema Open/);
});
