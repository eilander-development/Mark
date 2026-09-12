import { computed, reactive } from 'vue'
import { api } from './api'
import type { AppState, DayKey, Slot, WeekReport } from './types'

export const toast = reactive({ message: '', tone: 'info' as 'info' | 'success' | 'error' })

export function showToast(message: string, tone: 'info' | 'success' | 'error' = 'info'): void {
    toast.message = message
    toast.tone = tone
    window.setTimeout(() => {
        if (toast.message === message) {
            toast.message = ''
        }
    }, 2800)
}

export const store = reactive({
    state: null as AppState | null,
    loading: true,
    error: '',
    showDetailedSets: false,
    report: null as WeekReport | null,
})

export const currentSession = computed(() => {
    if (!store.state) {
        return null
    }

    return store.state.weeks[String(store.state.currentWeek)]?.[store.state.currentDay] ?? null
})

export const isDeload = computed(() => (store.state?.deloadWeeks ?? []).includes(store.state?.currentWeek ?? 0))

export function requiredSets(week = store.state?.currentWeek ?? 1): number {
    return (store.state?.deloadWeeks ?? []).includes(week) ? 2 : 3
}

export function workingSets(slot: Slot, week = store.state?.currentWeek ?? 1): Slot['sets'] {
    return slot.sets.filter((set) => set.position <= requiredSets(week))
}

export function dayStats(week: number, day: DayKey) {
    const session = store.state?.weeks[String(week)]?.[day]
    if (!session) {
        return { completed: 0, total: 0, volume: 0, started: false, done: false }
    }
    const need = requiredSets(week)
    let completed = 0
    let total = 0
    let volume = 0
    for (const slot of session.slots) {
        for (const set of slot.sets) {
            if (set.position > need) {
                continue
            }
            total++
            if (set.completed) {
                completed++
                volume += (Number(set.weight) || 0) * (Number(set.reps) || 0)
            }
        }
    }

    return { completed, total, volume, started: completed > 0, done: total > 0 && completed >= total }
}

export function weekVolume(week: number): number {
    return (store.state?.days ?? []).reduce((sum, day) => sum + dayStats(week, day).volume, 0)
}

export function weekCompletion(week: number): number {
    const days = store.state?.days ?? []
    let completed = 0
    let total = 0
    for (const day of days) {
        const stats = dayStats(week, day)
        completed += stats.completed
        total += stats.total
    }

    return total === 0 ? 0 : Math.round((completed / total) * 100)
}

export function previousSetLabel(slot: Slot, position: number): string {
    const week = (store.state?.currentWeek ?? 1) - 1
    if (week < 1 || !store.state) {
        return 'Geen vorig'
    }
    const prev = store.state.weeks[String(week)]?.[store.state.currentDay]?.slots.find((item) => item.slotKey === slot.slotKey)
    const set = prev?.sets.find((item) => item.position === position)
    if (!set || (set.weight === '' && set.reps === '')) {
        return 'Geen vorig'
    }
    if (slot.isBodyweight) {
        return `${set.reps || 0} reps (BW)`
    }

    return `${set.weight || 0}kg × ${set.reps || 0}`
}

export function restLabel(seconds: number): string {
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}m`
}

export function formatClock(totalSeconds: number): string {
    const minutes = Math.floor(totalSeconds / 60)
    const seconds = totalSeconds % 60

    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
}

export async function loadState(): Promise<void> {
    store.loading = true
    store.error = ''
    try {
        store.state = await api.state()
    } catch (error) {
        store.error = error instanceof Error ? error.message : 'Kon state niet laden'
    } finally {
        store.loading = false
    }
}

export async function applyState(next: AppState): Promise<void> {
    store.state = next
}

export async function patchPreferences(data: Record<string, unknown>): Promise<void> {
    store.state = await api.preferences(data)
}

export async function patchSet(id: number, data: Record<string, unknown>): Promise<void> {
    store.state = await api.updateSet(id, data)
}

export async function patchSlot(id: number, data: Record<string, unknown>): Promise<void> {
    store.state = await api.updateSlot(id, data)
}
