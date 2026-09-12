import type { AppState, WeekReport } from './types'

async function request<T>(url: string, options: RequestInit = {}): Promise<T> {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.headers || {}),
        },
        ...options,
    })

    const body = await response.json().catch(() => ({}))
    if (!response.ok) {
        throw new Error(body.message || Object.values(body.errors || {}).flat().join(' ') || 'Verzoek mislukt')
    }

    return body as T
}

export const api = {
    state: () => request<AppState>('/api/state'),
    report: (week: number) => request<WeekReport>(`/api/weeks/${week}/report`),
    updateSet: (id: number, data: Record<string, unknown>) =>
        request<AppState>(`/api/sets/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
    updateSlot: (id: number, data: Record<string, unknown>) =>
        request<AppState>(`/api/slots/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
    updateSession: (id: number, data: Record<string, unknown>) =>
        request<AppState>(`/api/sessions/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
    preferences: (data: Record<string, unknown>) =>
        request<AppState>('/api/preferences', { method: 'PATCH', body: JSON.stringify(data) }),
    profile: (data: Record<string, unknown>) =>
        request<AppState>('/api/profile', { method: 'PATCH', body: JSON.stringify(data) }),
    clearDay: (week: number, day: string) =>
        request<AppState>('/api/days/clear', { method: 'POST', body: JSON.stringify({ week, day }) }),
    advance: (week: number) =>
        request<AppState>('/api/weeks/advance', { method: 'POST', body: JSON.stringify({ week }) }),
    startCycle: (schema?: Record<string, unknown>) =>
        request<AppState>('/api/cycles', { method: 'POST', body: JSON.stringify(schema ? { schema } : {}) }),
}
