@extends('admin.layout')
@section('title', 'Oefeningen')
@section('content')
    <h1 class="mb-1 text-xl font-black">Oefeningen & form-video's</h1>
    <p class="mb-6 text-sm text-slate-400">
        {{ $exercises->count() }} oefeningen uit het programma.
        {{ $athleanCount }} met geverifieerde ATHLEAN-X™ / Jeff Cavaliere.
        @if ($missingVideos === 0)
            Elke rij heeft een form-video.
        @else
            {{ $missingVideos }} nog zonder video.
        @endif
        ATHLEAN-X™ alleen als het kanaal klopt. Plak een YouTube-link om te wijzigen.
    </p>

    <div class="overflow-x-auto rounded-2xl border border-slate-800">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-900 text-[10px] font-mono uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-3 py-2">Oefening</th>
                    <th class="px-3 py-2">Video</th>
                    <th class="px-3 py-2">YouTube</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800 bg-slate-950">
                @foreach ($exercises as $exercise)
                    <tr>
                        <td class="px-3 py-3 align-top">
                            <div class="font-bold text-slate-100">{{ $exercise->name }}</div>
                            <div class="text-[11px] text-slate-500">{{ $exercise->channel ?: 'Geen kanaal' }}</div>
                        </td>
                        <td class="px-3 py-3 align-top">
                            @if ($exercise->youtube_id)
                                <a href="https://www.youtube.com/watch?v={{ $exercise->youtube_id }}" target="_blank" rel="noreferrer" class="text-sky-300 underline">
                                    {{ $exercise->title ?: $exercise->youtube_id }}
                                </a>
                            @else
                                <span class="text-amber-300">Geen video</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 align-top" colspan="2">
                            <form method="post" action="{{ route('admin.exercises.update', $exercise) }}" class="flex flex-wrap items-end gap-2">
                                @csrf
                                @method('PATCH')
                                <input name="youtube_url" value="{{ $exercise->youtube_id ? 'https://www.youtube.com/watch?v='.$exercise->youtube_id : '' }}" placeholder="https://youtube.com/watch?v=..." class="min-w-56 flex-1 rounded-lg border border-slate-700 bg-slate-900 px-2 py-1.5 font-mono text-xs">
                                <input name="title" value="{{ $exercise->title }}" placeholder="Titel" class="w-48 rounded-lg border border-slate-700 bg-slate-900 px-2 py-1.5 text-xs">
                                <button class="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-bold">Opslaan</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
