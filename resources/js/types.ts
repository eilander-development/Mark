export type DayKey = 'mon' | 'tue' | 'thu' | 'fri'
export type AdviceType =
    | 'same_week'
    | 'inregel_logged'
    | 'inregel_baseline'
    | 'deload'
    | 'overload'
    | 'repeat'
    | 'initial_no_data'

export interface WorkoutSet {
    id: number
    position: number
    weight: string
    reps: string
    completed: boolean
    isPr: boolean
    exertion: 'easy' | 'good' | 'max'
    estimated1Rm: number | null
}

export interface CatalogItem {
    defaultName: string
    targetReps: number
    restType: string
    restTime: number
    muscles: string
    equipment: string
    tips: string[]
    alternatives: string[]
}

export interface SlotAdvice {
    adviceType: AdviceType
    advisedWeight: number | null
    targetReps: number
    prevMax: number | null
    prevWeekFound: string | null
    sameWeekDay: string | null
    currentLoggedWeight: number | null
    isBodyweight: boolean
    increment: number
}

export interface Slot {
    id: number
    slotKey: string
    selectedName: string
    note: string | null
    targetReps: number
    catalog: CatalogItem
    advisedWeight: number | null
    advice: SlotAdvice
    record: { max1RM: number; maxWeight: number; maxReps: number }
    isBodyweight: boolean
    isTargetAchieved: boolean
    sets: WorkoutSet[]
}

export interface SessionDay {
    id: number
    title: string
    duration: number | null
    avgRest: number | null
    dayName: string
    dayShort: string
    slots: Slot[]
}

export interface AppState {
    currentWeek: number
    currentDay: DayKey
    totalWeeks: number
    currentCycle: number
    cycleId: number
    cycleStartedAt: string | null
    routineLocked: boolean
    soundEnabled: boolean
    overloadIncrement: number
    overloadFrequency: 'weekly' | 'biweekly'
    profile: {
        birthYear: number
        bodyWeightKg: number
        experienceLevel: string
        equipment: Record<string, boolean> | null
    }
    weeks: Record<string, Record<string, SessionDay>>
    splits: Record<string, { title: string; slots: string[] }>
    catalog: Record<string, CatalogItem>
    days: DayKey[]
    cyclesHistory: Array<{
        id: number
        number: number
        started_at: string
        completed_at: string | null
        snapshot: unknown
    }>
    deloadWeeks: number[]
}

export interface ImportPreview {
    updatedAt: string | null
    completedSets: number
    weeks: number
    currentWeek: number
    cycle: number
    mysqlCompletedSets?: number
    preview: boolean
    imported: boolean
    message?: string
    state?: AppState
}

export interface WeekReport {
    week: number
    isDeload: boolean
    volume: number
    completedSets: number
    totalSets: number
    exercises: Array<{ name: string; sets: number; volume: number; maxWeight: number }>
}
