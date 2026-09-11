import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const source = fs.readFileSync(path.join(root, 'marker', 'index.html'), 'utf8');

let html = source.replace(/\r\n/g, '\n');

html = html.replace(
    /function initAppState\(\) \{[\s\S]*?\n    \}\n\n    function seedInitialWeeks/,
    `async function initAppState() {
      try {
        const response = await fetch("/api/marker-state", { headers: { Accept: "application/json" }, cache: "no-store" });
        if (!response.ok) throw new Error("marker-state HTTP " + response.status);
        const payload = await response.json();
        if (payload && payload.appState && payload.appState.weeks) {
          appState = payload.appState;
          lastPersisted = clonePersistedState(appState);
          laravelPersistEnabled = true;
        } else {
          seedInitialWeeks(7);
          laravelPersistEnabled = false;
        }
      } catch (err) {
        console.warn("Kon /api/marker-state niet laden, start leeg schema.", err);
        seedInitialWeeks(7);
        laravelPersistEnabled = false;
      }

      appState.totalWeeks = 7;
      if (appState.weeks) {
        for (let w = 8; w <= 30; w++) {
          if (appState.weeks[w]) delete appState.weeks[w];
        }
      }
      if (appState.currentWeek > 7) appState.currentWeek = 7;
      if (appState.currentCycle === undefined) appState.currentCycle = 1;
      if (!appState.cyclesHistory) appState.cyclesHistory = [];
      if (!appState.cycleStartedAt) appState.cycleStartedAt = new Date().toLocaleDateString('nl-NL');
      if (appState.soundEnabled === undefined) appState.soundEnabled = true;
      if (!appState.preferredRestTimes) appState.preferredRestTimes = {};
      if (!appState.overloadIncrement) appState.overloadIncrement = 2.0;
      if (!appState.overloadFrequency) appState.overloadFrequency = "weekly";
      if (!appState.userProfile) {
        appState.userProfile = {
          birthYear: 1984,
          bodyWeightKg: 82,
          experienceLevel: "intermediate",
          equipment: { dumbbells: true, barbell: true, bench: true, bodyweight: true, pullup_bar: false, bands: false }
        };
      }
      if (appState.routineLocked === undefined) appState.routineLocked = true;
      if (!appState.customExerciseVideos) appState.customExerciseVideos = {};
      if (appState.showLiveVideoPanel === undefined) {
        appState.showLiveVideoPanel = typeof window !== "undefined" ? window.innerWidth >= 768 : true;
      }

      for (let w = 1; w <= appState.totalWeeks; w++) {
        if (!appState.weeks[w]) {
          appState.weeks[w] = generateEmptyWeekData(w);
        } else {
          ["mon", "tue", "thu", "fri"].forEach(day => {
            const daySlots = appState.weeks[w][day];
            if (daySlots) {
              SPLIT_INFO[day].slots.forEach(slotKey => {
                if (daySlots[slotKey]) {
                  const currentName = daySlots[slotKey].selectedName || "";
                  const validAlternatives = EXERCISE_CATALOG[slotKey] ? EXERCISE_CATALOG[slotKey].alternatives : [];
                  if (!validAlternatives.includes(currentName)) {
                    daySlots[slotKey].selectedName = EXERCISE_CATALOG[slotKey].defaultName;
                  }
                }
              });
            }
          });
        }
      }
      updateSoundUI();
    }

    function seedInitialWeeks`,
);

html = html.replace(
    /function saveState\(\) \{[\s\S]*?\n    \}/,
    `function saveState() {
      updateDashboard();
      scheduleLaravelPersist();
    }

    let laravelPersistTimer = null;
    let laravelPersistInFlight = false;
    let laravelPersistQueued = false;
    let laravelPersistEnabled = false;
    let lastPersisted = null;

    function clonePersistedState(state) {
      return JSON.parse(JSON.stringify(state));
    }

    function jsonApi(url, method, body) {
      return fetch(url, {
        method,
        headers: { Accept: "application/json", "Content-Type": "application/json" },
        body: body === undefined ? undefined : JSON.stringify(body)
      });
    }

    function scheduleLaravelPersist() {
      if (!laravelPersistEnabled) return;
      if (laravelPersistTimer) clearTimeout(laravelPersistTimer);
      laravelPersistTimer = setTimeout(() => { persistIncrementalToLaravel(); }, 350);
    }

    function flushLaravelPersist() {
      if (!laravelPersistEnabled) return;
      if (laravelPersistTimer) {
        clearTimeout(laravelPersistTimer);
        laravelPersistTimer = null;
      }
      persistIncrementalToLaravel();
    }

    function stampIdsFrom(source, target) {
      if (!source || !source.weeks || !target || !target.weeks) return;
      Object.keys(target.weeks).forEach((week) => {
        const srcWeek = source.weeks[week];
        const dstWeek = target.weeks[week];
        if (!srcWeek || !dstWeek) return;
        ["mon", "tue", "thu", "fri"].forEach((day) => {
          const srcDay = srcWeek[day];
          const dstDay = dstWeek[day];
          if (!srcDay || !dstDay) return;
          dstDay.sessionId = srcDay.sessionId;
          Object.keys(dstDay).forEach((slotKey) => {
            const srcSlot = srcDay[slotKey];
            const dstSlot = dstDay[slotKey];
            if (!srcSlot || !dstSlot || !Array.isArray(dstSlot.sets)) return;
            dstSlot.id = srcSlot.id;
            dstSlot.sessionId = srcSlot.sessionId || srcDay.sessionId;
            dstSlot.sets.forEach((set, index) => {
              if (srcSlot.sets && srcSlot.sets[index] && srcSlot.sets[index].id) {
                set.id = srcSlot.sets[index].id;
              }
            });
          });
        });
      });
    }

    function lookupSetId(week, day, slotKey, index, set) {
      if (set && set.id) return set.id;
      return lastPersisted && lastPersisted.weeks
        && lastPersisted.weeks[week]
        && lastPersisted.weeks[week][day]
        && lastPersisted.weeks[week][day][slotKey]
        && lastPersisted.weeks[week][day][slotKey].sets
        && lastPersisted.weeks[week][day][slotKey].sets[index]
        ? lastPersisted.weeks[week][day][slotKey].sets[index].id
        : null;
    }

    function lookupSlotId(week, day, slotKey, slot) {
      if (slot && slot.id) return slot.id;
      return lastPersisted && lastPersisted.weeks
        && lastPersisted.weeks[week]
        && lastPersisted.weeks[week][day]
        && lastPersisted.weeks[week][day][slotKey]
        ? lastPersisted.weeks[week][day][slotKey].id
        : null;
    }

    function setFingerprint(set) {
      if (!set) return "";
      return [set.weight || "", set.reps || "", set.completed ? "1" : "0", set.exertion || "good"].join("|");
    }

    async function persistIncrementalToLaravel() {
      if (!laravelPersistEnabled || !lastPersisted) return;
      if (laravelPersistInFlight) {
        laravelPersistQueued = true;
        return;
      }
      laravelPersistInFlight = true;
      try {
        const prev = lastPersisted;
        const next = appState;
        if ((next.currentCycle || 1) > (prev.currentCycle || 1)) {
          const schema = next.pendingCycleSchema || null;
          const started = await jsonApi("/api/cycles", "POST", schema ? { schema } : {});
          if (!started.ok) throw new Error("cycles HTTP " + started.status);
          delete next.pendingCycleSchema;
          const fresh = await fetch("/api/marker-state", { headers: { Accept: "application/json" }, cache: "no-store" });
          if (!fresh.ok) throw new Error("marker-state HTTP " + fresh.status);
          const payload = await fresh.json();
          if (payload && payload.appState && payload.appState.weeks) {
            stampIdsFrom(payload.appState, next);
            lastPersisted = clonePersistedState(payload.appState);
          }
        }

        const requests = [];
        const prefBody = {};
        if (next.currentWeek !== prev.currentWeek) prefBody.current_week = next.currentWeek;
        if (next.currentDay !== prev.currentDay) prefBody.current_day = next.currentDay;
        if (next.soundEnabled !== prev.soundEnabled) prefBody.sound_enabled = !!next.soundEnabled;
        if (next.routineLocked !== prev.routineLocked) prefBody.routine_locked = !!next.routineLocked;
        if (next.showLiveVideoPanel !== prev.showLiveVideoPanel) prefBody.show_live_video_panel = !!next.showLiveVideoPanel;
        if (Number(next.overloadIncrement) !== Number(prev.overloadIncrement)) prefBody.overload_increment = Number(next.overloadIncrement);
        if (next.overloadFrequency !== prev.overloadFrequency) prefBody.overload_frequency = next.overloadFrequency;
        if (JSON.stringify(next.preferredRestTimes || {}) !== JSON.stringify(prev.preferredRestTimes || {})) {
          prefBody.preferred_rest_times = next.preferredRestTimes || {};
        }
        if (JSON.stringify(next.customExerciseVideos || {}) !== JSON.stringify(prev.customExerciseVideos || {})) {
          prefBody.custom_exercise_videos = next.customExerciseVideos || {};
        }
        if (Object.keys(prefBody).length) requests.push(jsonApi("/api/preferences", "PATCH", prefBody));

        const nextProfile = next.userProfile || {};
        const prevProfile = prev.userProfile || {};
        const profileBody = {};
        if (nextProfile.birthYear !== prevProfile.birthYear) profileBody.birth_year = nextProfile.birthYear;
        if (nextProfile.bodyWeightKg !== prevProfile.bodyWeightKg) profileBody.body_weight_kg = nextProfile.bodyWeightKg;
        if (nextProfile.experienceLevel !== prevProfile.experienceLevel) profileBody.experience_level = nextProfile.experienceLevel;
        if (JSON.stringify(nextProfile.equipment || {}) !== JSON.stringify(prevProfile.equipment || {})) {
          profileBody.equipment = nextProfile.equipment || {};
        }
        if (Object.keys(profileBody).length) requests.push(jsonApi("/api/profile", "PATCH", profileBody));

        const weeks = next.weeks || {};
        Object.keys(weeks).forEach((week) => {
          const nextWeek = weeks[week] || {};
          const prevWeek = (prev.weeks && prev.weeks[week]) || {};
          ["mon", "tue", "thu", "fri"].forEach((day) => {
            const nextDay = nextWeek[day];
            const prevDay = prevWeek[day] || {};
            if (!nextDay) return;
            const sessionId = nextDay.sessionId
              || prevDay.sessionId
              || Object.values(nextDay).find((slot) => slot && slot.sessionId)?.sessionId
              || Object.values(prevDay).find((slot) => slot && slot.sessionId)?.sessionId;
            if (sessionId && (nextDay.actualDuration !== prevDay.actualDuration || nextDay.actualAvgRest !== prevDay.actualAvgRest)) {
              requests.push(jsonApi("/api/sessions/" + sessionId, "PATCH", {
                actual_duration: nextDay.actualDuration ?? null,
                actual_avg_rest: nextDay.actualAvgRest ?? null
              }));
            }
            Object.keys(nextDay).forEach((slotKey) => {
              const nextSlot = nextDay[slotKey];
              if (!nextSlot || !Array.isArray(nextSlot.sets)) return;
              const prevSlot = prevDay[slotKey] || {};
              const slotId = lookupSlotId(week, day, slotKey, nextSlot);
              if (slotId && (nextSlot.selectedName !== prevSlot.selectedName || (nextSlot.note || "") !== (prevSlot.note || ""))) {
                const slotBody = { context: "setup" };
                if (nextSlot.selectedName !== prevSlot.selectedName) slotBody.selectedName = nextSlot.selectedName;
                if ((nextSlot.note || "") !== (prevSlot.note || "")) slotBody.note = nextSlot.note || "";
                requests.push(jsonApi("/api/slots/" + slotId, "PATCH", slotBody));
              }
              nextSlot.sets.forEach((set, index) => {
                const prevSet = (prevSlot.sets && prevSlot.sets[index]) || {};
                if (setFingerprint(set) === setFingerprint(prevSet)) return;
                const setId = lookupSetId(week, day, slotKey, index, set);
                if (!setId) return;
                requests.push(jsonApi("/api/sets/" + setId, "PATCH", {
                  weight: set.weight ?? "",
                  reps: set.reps ?? "",
                  completed: !!set.completed,
                  exertion: set.exertion || "good",
                  context: "live"
                }));
              });
            });
          });
        });

        if (requests.length) {
          const responses = await Promise.all(requests);
          const failed = responses.find((response) => !response.ok);
          if (failed) throw new Error("incremental persist HTTP " + failed.status);
        }
        lastPersisted = clonePersistedState(next);
      } catch (err) {
        console.warn("Kon training niet incrementieel naar de database schrijven:", err);
      } finally {
        laravelPersistInFlight = false;
        if (laravelPersistQueued) {
          laravelPersistQueued = false;
          persistIncrementalToLaravel();
        }
      }
    }

    if (typeof document !== "undefined") {
      document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "hidden") flushLaravelPersist();
      });
      window.addEventListener("pagehide", flushLaravelPersist);
    }`,
);

html = html.replace(
    /async function callCloudApi\(method = "GET", bodyData = null\) \{[\s\S]*?\n    \}/,
    `async function callCloudApi(method = "GET", bodyData = null) {
      if (method !== "GET") {
        return fetch("/api/marker-state", {
          method: "PUT",
          headers: { Accept: "application/json", "Content-Type": "application/json" },
          body: JSON.stringify(bodyData && bodyData.appState ? { appState: bodyData.appState } : { appState: bodyData || appState })
        });
      }
      return fetch("/api/import/val", { headers: { Accept: "application/json" }, cache: "no-store" });
    }`,
);

html = html.replace(
    /async function opslaanDataNaarCloud\(data = null, showToastNotice = false\) \{[\s\S]*?\n    \}/,
    `async function opslaanDataNaarCloud(data = null, showToastNotice = false) {
      const stateToUpload = data || appState;
      try {
        if (showToastNotice) {
          showToast("💾 Bezig met opslaan in de database...", "info");
        }
        const response = await fetch("/api/marker-state", {
          method: "PUT",
          headers: { Accept: "application/json", "Content-Type": "application/json" },
          body: JSON.stringify({ appState: stateToUpload })
        });
        if (!response.ok) {
          throw new Error("marker-state HTTP " + response.status);
        }
        if (showToastNotice) {
          showToast("✅ Opgeslagen in de database. Nieuwe app schrijft niet naar Val.", "success");
        }
        updateCloudSyncUI(true);
        return true;
      } catch (err) {
        console.warn("Fout bij opslaanDataNaarCloud:", err);
        if (showToastNotice) {
          showToast("⚠️ Kon niet opslaan in de database.", "error");
        }
        updateCloudSyncUI(false);
        return false;
      }
    }`,
);

html = html.replace(
    /function triggerDebouncedCloudSync\(\) \{[\s\S]*?\n    \}/,
    `function triggerDebouncedCloudSync() {
      scheduleLaravelPersist();
    }`,
);

html = html.replace(
    'function startApp() {\n      initAppState();',
    'async function startApp() {\n      await initAppState();\n      document.getElementById("ironforgeBootOverlay")?.remove();',
);

html = html.replaceAll(
    'Object.entries(dayData).forEach(([slotK, slot]) => {\n              if (slot.selectedName === exerciseName) {',
    'Object.entries(dayData).forEach(([slotK, slot]) => {\n              if (!slot || typeof slot !== "object" || !Array.isArray(slot.sets)) return;\n              if (slot.selectedName === exerciseName) {',
);

html = html.replace(
    'id="lwVideoArea" class="hidden md:flex flex-col',
    'id="lwVideoArea" class="hidden flex-col',
);

html = html.replace(
    'videoArea.classList.remove("hidden");\n        videoArea.classList.add("flex");',
    'videoArea.classList.remove("hidden", "md:flex");\n        videoArea.classList.add("flex");',
);

html = html.replace(
    'videoArea.classList.add("hidden");\n        videoArea.classList.remove("flex");',
    'videoArea.classList.add("hidden");\n        videoArea.classList.remove("flex", "md:flex");',
);

html = html.replace(
    'if (!appState.showLiveVideoPanel || liveWorkout.phase === "summary") {\n        videoArea.classList.add("hidden");\n        return;',
    'if (!appState.showLiveVideoPanel || liveWorkout.phase === "summary") {\n        videoArea.classList.add("hidden");\n        videoArea.classList.remove("flex", "md:flex");\n        return;',
);

html = html.replace(
    '    /* Helper: Bepaalt de beste video gegevens (eigen bewaarde link -> bibliotheek -> fuzzy -> geen Athlean-stempel) */\n    function getExerciseVideoData(exerciseName, slotKey = null) {\n      const name = (exerciseName || "").trim();\n      \n      // 1. Heeft de gebruiker een eigen video bewaard voor deze oefening?',
    `    /* Helper: Bepaalt de beste video gegevens (eigen bewaarde link -> Athlean-X match -> fallback) */
    function getExerciseVideoData(exerciseName, slotKey = null) {
      const name = (exerciseName || "").trim();

      if (appState && appState.exerciseVideos && appState.exerciseVideos[name] && !(appState.customExerciseVideos && appState.customExerciseVideos[name])) {
        const managed = appState.exerciseVideos[name];
        return {
          videoId: managed.videoId || "",
          title: managed.title || name,
          channel: managed.channel || "",
          cues: managed.cues || [],
          isCustom: false,
          isAthlean: !!managed.isAthlean,
          url: managed.url || (managed.videoId ? ("https://www.youtube.com/watch?v=" + managed.videoId) : "")
        };
      }
      
      // 1. Heeft de gebruiker een eigen video bewaard voor deze oefening?`,
);

html = html.replace(
    `<div class="text-[10px] font-mono uppercase tracking-wider text-slate-500 font-bold mb-2">Systeem</div>
        <div class="space-y-1.5">
          <button onclick="closeMenuDrawer(); openCloudSyncModal();" class="w-full p-2.5 rounded-xl bg-blue-500/10 hover:bg-blue-500/20 text-left border border-blue-500/30 transition flex items-center gap-3 cursor-pointer">
            <div class="w-8 h-8 rounded-lg bg-blue-500/20 text-blue-400 flex items-center justify-center text-base flex-shrink-0">
              ☁️
            </div>
            <div class="flex-1">
              <div class="text-xs font-bold text-blue-200 flex items-center justify-between">
                <span>Cloud Synchronisatie</span>
                <span id="cloudSyncStatusBadge" class="text-[9px] font-mono px-1.5 py-0.5 rounded bg-blue-500/20 text-blue-300">Val Town</span>
              </div>
              <div class="text-[10px] text-blue-400/80">fit.val.run • Ophalen & Opslaan</div>
            </div>
          </button>

          <button onclick="closeMenuDrawer(); openDataModal();" class="w-full p-2.5 rounded-xl bg-slate-800/60 hover:bg-slate-800 text-left border border-slate-800 hover:border-slate-700 transition flex items-center gap-3 cursor-pointer">
            <div class="w-8 h-8 rounded-lg bg-slate-700/60 text-slate-300 flex items-center justify-center text-base flex-shrink-0">
              💾
            </div>
            <div class="flex-1">
              <div class="text-xs font-bold text-slate-200">JSON Back-up & Herstel</div>
              <div class="text-[10px] text-slate-400">Exporteer of importeer je trainingsdata</div>
            </div>
          </button>
        </div>
      </div>`,
    `<div class="text-[10px] font-mono uppercase tracking-wider text-slate-500 font-bold mb-2">Systeem</div>
        <div class="space-y-1.5">
          <button onclick="closeMenuDrawer(); syncFromValOnDemand();" class="w-full p-2.5 rounded-xl bg-slate-800/60 hover:bg-slate-800 text-left border border-slate-800 hover:border-slate-700 transition flex items-center gap-3 cursor-pointer">
            <div class="w-8 h-8 rounded-lg bg-blue-500/15 text-blue-300 flex items-center justify-center text-base flex-shrink-0">
              ☁️
            </div>
            <div class="flex-1">
              <div class="text-xs font-bold text-slate-200 flex items-center justify-between">
                <span>Val importeren</span>
                <span id="cloudSyncStatusBadge" class="text-[9px] font-mono px-1.5 py-0.5 rounded bg-slate-700 text-slate-300">GET</span>
              </div>
              <div class="text-[10px] text-slate-400">Overschrijft de huidige cyclus</div>
            </div>
          </button>

          <button onclick="closeMenuDrawer(); clearCurrentDay();" class="w-full p-2.5 rounded-xl bg-slate-800/60 hover:bg-slate-800 text-left border border-slate-800 hover:border-slate-700 transition flex items-center gap-3 cursor-pointer">
            <div class="w-8 h-8 rounded-lg bg-rose-500/15 text-rose-300 flex items-center justify-center text-base flex-shrink-0">
              🔄
            </div>
            <div class="flex-1">
              <div class="text-xs font-bold text-slate-200">Dag resetten</div>
              <div class="text-[10px] text-slate-400">Sets van vandaag wissen</div>
            </div>
          </button>

          <a href="/beheer" class="w-full p-2.5 rounded-xl bg-emerald-500/10 hover:bg-emerald-500/20 text-left border border-emerald-500/30 transition flex items-center gap-3 cursor-pointer">
            <div class="w-8 h-8 rounded-lg bg-emerald-500/20 text-emerald-300 flex items-center justify-center text-base flex-shrink-0">
              🛠️
            </div>
            <div class="flex-1">
              <div class="text-xs font-bold text-emerald-200">Beheer</div>
              <div class="text-[10px] text-emerald-400/80">Oefeningen, video, overload</div>
            </div>
          </a>
        </div>
      </div>`,
);

html = html.replace(
    '<body class="min-h-screen flex flex-col bg-slate-900 text-slate-100 selection:bg-emerald-500 selection:text-slate-950 pb-28">',
    `<body class="min-h-screen flex flex-col bg-slate-900 text-slate-100 selection:bg-emerald-500 selection:text-slate-950 pb-28">
<div id="ironforgeBootOverlay" class="fixed inset-0 z-[300] bg-slate-950 flex flex-col items-center justify-center gap-2">
  <div class="text-sm font-bold tracking-wide text-slate-100">Training laden…</div>
  <div class="text-[11px] text-slate-500">Data komt uit de database</div>
</div>`,
);

html = html.replace(
    '<span class="text-emerald-400 font-bold">● Offline & Lokaal Veilig</span>\n      <span>v2.8 Thuisgym</span>',
    '<span class="text-slate-400 font-bold">● Data in MySQL</span>\n      <span>v2.9 IronForge</span>',
);

html = html.replace(
    /<button onclick="(?:openCloudSyncModal|syncFromValOnDemand)\(\)" id="headerCloudSyncBtn"[\s\S]*?<\/button>\s*/,
    '',
);

html = html.replace(
    'onclick="closeMenuDrawer(); openCloudSyncModal();"',
    'onclick="closeMenuDrawer(); syncFromValOnDemand();"',
);

html = html.replace(
    /\/\/ 1\. Toon bij het openen van de app de cloud synchronisatie vraag\n      setTimeout\(\(\) => \{\n        openCloudSyncModal\(\);\n      \}, 350\);\n\n/,
    '',
);

html = html.replace(
    'window.openCloudSyncModal = openCloudSyncModal;',
    `window.openCloudSyncModal = syncFromValOnDemand;
    window.syncFromValOnDemand = syncFromValOnDemand;

    async function syncFromValOnDemand() {
      let preview;
      try {
        const response = await fetch("/api/import/val", { headers: { Accept: "application/json" }, cache: "no-store" });
        preview = await response.json();
        if (!response.ok) throw new Error(preview.message || ("preview HTTP " + response.status));
      } catch (err) {
        alert("Val-dump ophalen mislukt: " + (err && err.message ? err.message : err));
        return;
      }
      if (!preview.completedSets) {
        alert("Val-dump heeft geen voltooide sets. Bestaande training in de database blijft staan.");
        return;
      }
      if (!confirm("Val-dump (" + preview.completedSets + " sets, " + (preview.updatedAt || "geen datum") + ") overschrijft de huidige cyclus in MySQL. Doorgaan?")) {
        return;
      }
      const imported = await jsonApi("/api/import/val", "POST", { confirm: true });
      const result = await imported.json();
      if (!imported.ok) {
        alert(result.message || "Import mislukt.");
        return;
      }
      const fresh = await fetch("/api/marker-state", { headers: { Accept: "application/json" }, cache: "no-store" });
      const payload = await fresh.json();
      if (!fresh.ok || !payload || !payload.appState || !payload.appState.weeks) {
        alert("Import klaar, maar de trainerstate kon niet opnieuw geladen worden.");
        return;
      }
      appState = payload.appState;
      lastPersisted = clonePersistedState(appState);
      renderWeekPills();
      switchDay(appState.currentDay || "mon");
      updateDashboard();
    }`,
);

html = html.replace(
    /async function fetchDataVanCloud\(showToastNotice = true\) \{[\s\S]*?\n    \}/,
    `async function fetchDataVanCloud(showToastNotice = true) {
      if (showToastNotice) {
        showToast("Sync loopt via de database, niet via localStorage.", "info");
      }
      await syncFromValOnDemand();
      return false;
    }`,
);

html = html.replace(
    'const ok = await fetchDataVanCloud(true);',
    'await syncFromValOnDemand();\n        const ok = false;',
);

html = html.replace(
    /\/\/ Registreer de Service Worker voor PWA & Offline ondersteuning\n      if \('serviceWorker' in navigator\) \{\n        navigator\.serviceWorker\.register\('\/sw\.js'\)\.then\(\(reg\) => \{\n          console\.log\('IronForge Service Worker geregistreerd:', reg\.scope\);\n        \}\)\.catch\(\(err\) => \{\n          console\.warn\('Service Worker registratie:', err\);\n        \}\);\n      \}/,
    `function registerIronForgeServiceWorker() {
        if (!('serviceWorker' in navigator)) return;
        let refreshing = false;
        navigator.serviceWorker.addEventListener('controllerchange', () => {
          if (refreshing) return;
          refreshing = true;
          window.location.reload();
        });
        navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' }).then((reg) => {
          const promptUpdate = (worker) => {
            if (!worker) return;
            worker.addEventListener('statechange', () => {
              if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                worker.postMessage({ type: 'SKIP_WAITING' });
              }
            });
          };
          if (reg.waiting) {
            reg.waiting.postMessage({ type: 'SKIP_WAITING' });
          }
          reg.addEventListener('updatefound', () => promptUpdate(reg.installing));
          document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
              reg.update();
            }
          });
        }).catch((err) => {
          console.warn('Service Worker registratie:', err);
        });
      }
      registerIronForgeServiceWorker();`,
);

const portChecks = {
    initAppState: html.includes('async function initAppState'),
    persist: html.includes('persistIncrementalToLaravel'),
    awaitInit: html.includes('await initAppState'),
    bootOverlay: html.includes('id="ironforgeBootOverlay"'),
    bootHide: html.includes('ironforgeBootOverlay")?.remove()'),
    sw: html.includes('registerIronForgeServiceWorker'),
    valSync: html.includes('syncFromValOnDemand'),
    slotGuard: html.includes('!Array.isArray(slot.sets)) return'),
    videoArea: html.includes('id="lwVideoArea" class="hidden flex-col'),
    beheer: html.includes('href="/beheer"'),
    videos: html.includes('appState.exerciseVideos'),
    valApi: html.includes('/api/import/val'),
    valEmpty: html.includes('Val-dump heeft geen voltooide sets'),
    valOverwrite: html.includes('overschrijft de huidige cyclus in MySQL'),
    nextPeriod: html.includes('canStartNewPeriod'),
    soundIcon: html.includes('soundToggleIcon'),
    noJson: !html.includes('JSON Back-up & Herstel'),
    noMdFlex: !html.includes('id="lwVideoArea" class="hidden md:flex'),
    noPersistAll: !html.includes('persistAppStateToLaravel'),
    noAutoCloud: !html.includes('Toon bij het openen van de app de cloud synchronisatie vraag'),
    noAwaitFetch: !html.includes('await fetchDataVanCloud(true)'),
    noHeaderSync: !html.includes('id="headerCloudSyncBtn"'),
};
const failedPortChecks = Object.entries(portChecks).filter(([, ok]) => !ok).map(([name]) => name);
if (failedPortChecks.length) {
    throw new Error('Marker-port patches failed: ' + failedPortChecks.join(', '));
}

if (!html.includes('id="ironforge-vue"')) {
    html = html.replace('</body>', '  <div id="ironforge-vue" hidden></div>\n</body>');
}

const destination = path.join(root, 'resources', 'ironforge.html');
fs.writeFileSync(destination, html);
console.log('Wrote', destination, '(' + html.length + ' bytes)');
