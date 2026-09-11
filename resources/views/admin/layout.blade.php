<!DOCTYPE html>
<html lang="nl" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Beheer') · IronForge</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>
        body { background: #0f172a; color: #f8fafc; font-family: system-ui, sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <header class="border-b border-slate-800 bg-slate-950/90">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
            <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/20 text-xs font-black text-emerald-300">IF</span>
                <span>
                    <span class="block text-sm font-black tracking-wide">IRONFORGE BEHEER</span>
                    <span class="block text-[10px] font-mono uppercase text-slate-500">Programma · Video's · Overload</span>
                </span>
            </a>
            <nav class="flex flex-wrap gap-2 text-xs font-mono font-bold">
                <a href="{{ route('admin.dashboard') }}" class="rounded-lg border border-slate-800 px-2.5 py-1.5 hover:border-emerald-500/40">Overzicht</a>
                <a href="{{ route('admin.program') }}" class="rounded-lg border border-slate-800 px-2.5 py-1.5 hover:border-emerald-500/40">Programma</a>
                <a href="{{ route('admin.exercises') }}" class="rounded-lg border border-slate-800 px-2.5 py-1.5 hover:border-emerald-500/40">Oefeningen</a>
                <a href="{{ route('admin.rules') }}" class="rounded-lg border border-slate-800 px-2.5 py-1.5 hover:border-emerald-500/40">Trainingsregels</a>
                <a href="{{ route('admin.preferences') }}" class="rounded-lg border border-slate-800 px-2.5 py-1.5 hover:border-emerald-500/40">Voorkeuren</a>
                <a href="/" class="rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-2.5 py-1.5 text-emerald-300">← Trainer</a>
                @if ((string) config('ironforge.admin_password') !== '')
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button type="submit" class="rounded-lg border border-slate-800 px-2.5 py-1.5 hover:border-rose-500/40">Uitloggen</button>
                    </form>
                @endif
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6">
        @if (session('status'))
            <p class="mb-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-200">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $errors->first() }}</p>
        @endif
        @yield('content')
    </main>
</body>
</html>
