import { readFileSync } from 'node:fs';
import { stripTypeScriptTypes } from 'node:module';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const source = readFileSync(new URL('../resources/js/store.ts', import.meta.url), 'utf8');
const script = stripTypeScriptTypes(source).replace(/^import .*$/gm, '').replace(/^export /gm, '');

test('store totals use server multipliers and count only completed valid working sets', () => {
    const context = vm.createContext({ reactive: value => value, computed: callback => callback });
    vm.runInContext(`${script}\nglobalThis.store = store;`, context);
    const set = (weight, reps, completed = true) => ({ position: 1, weight, reps, completed });
    context.store.state = {
        days: ['mon', 'thu'], deloadWeeks: [7], weeks: { 7: {
            mon: { slots: [
                { volumeMultiplier: 2, sets: [set(20, 10), { ...set(20, 10), position: 3 }] },
                { volumeMultiplier: 1, sets: [set(20, 10), set(30, 10, false)] },
                { volumeMultiplier: 0, isBodyweight: true, sets: [set(20, 10)] },
                { volumeMultiplier: 2, sets: [set(-20, 10), set(20, 0), set(Infinity, 10), set(20, Infinity)] },
            ] },
            thu: { slots: [{ volumeMultiplier: 2, sets: [set(17.5, 8)] }] },
        } },
    };

    assert.equal(context.dayStats(7, 'mon').volume, 600);
    assert.equal(context.weekVolume(7), 880);
});
