<?php

namespace App\Http\Controllers;

use App\Models\Exercise;
use App\Models\ProgramSlot;
use App\Models\WorkoutSlot;
use App\Services\Catalog;
use App\Services\CycleFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly CycleFactory $factory,
    ) {}

    public function showLogin(Request $request): View|RedirectResponse
    {
        if ((string) config('ironforge.admin_password') === '' || $request->session()->get('ironforge.admin') === true) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $password = (string) config('ironforge.admin_password');
        if ($password === '') {
            return redirect()->route('admin.dashboard');
        }

        $key = 'admin-login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'password' => 'Te veel pogingen. Wacht een minuut.',
            ]);
        }

        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! hash_equals($password, $data['password'])) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'password' => 'Onjuist wachtwoord.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('ironforge.admin', true);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('ironforge.admin');
        $request->session()->regenerate();

        return redirect()->route('admin.login');
    }

    public function dashboard(): View
    {
        $this->catalog->sync();
        $prefs = $this->factory->preferences();

        return view('admin.dashboard', [
            'slotCount' => ProgramSlot::query()->count(),
            'exerciseCount' => Exercise::query()->count(),
            'missingVideos' => $this->catalog->missingVideoCount(),
            'overloadIncrement' => $prefs->overload_increment,
            'overloadFrequency' => $prefs->overload_frequency,
            'currentWeek' => $prefs->current_week,
        ]);
    }

    public function program(): View
    {
        $this->catalog->sync();

        return view('admin.program', [
            'splits' => $this->catalog->splits(),
            'slots' => ProgramSlot::query()->orderBy('slot_key')->get()->keyBy('slot_key'),
        ]);
    }

    public function updateProgram(Request $request, ProgramSlot $slot): RedirectResponse
    {
        $data = $request->validate([
            'default_name' => ['required', 'string', 'max:120'],
            'target_reps' => ['required', 'integer', 'min:1', 'max:30'],
            'rest_time' => ['required', 'integer', 'min:15', 'max:300'],
            'rest_type' => ['nullable', 'string', 'max:80'],
            'alternatives' => ['nullable', 'string'],
        ]);

        $alternatives = collect(preg_split('/\r\n|\r|\n/', (string) $data['alternatives']))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values()
            ->all();
        if ($alternatives === []) {
            $alternatives = [$data['default_name']];
        }
        if (! in_array($data['default_name'], $alternatives, true)) {
            array_unshift($alternatives, $data['default_name']);
        }

        $slot->fill([
            'default_name' => $data['default_name'],
            'target_reps' => $data['target_reps'],
            'rest_time' => $data['rest_time'],
            'rest_type' => $data['rest_type'] ?? $slot->rest_type,
            'alternatives' => $alternatives,
        ])->save();

        $this->catalog->sync();

        return back()->with('status', $slot->slot_key.' opgeslagen.');
    }

    public function applyProgram(ProgramSlot $slot): RedirectResponse
    {
        $cycle = $this->factory->ensureCurrent();
        $sessionIds = $cycle->sessions()->pluck('id');
        WorkoutSlot::query()
            ->whereIn('workout_session_id', $sessionIds)
            ->where('slot_key', $slot->slot_key)
            ->update([
                'selected_name' => $slot->default_name,
                'target_reps' => $slot->target_reps,
            ]);

        return back()->with('status', $slot->slot_key.' toegepast op de huidige cyclus.');
    }

    public function exercises(): View
    {
        $exercises = $this->catalog->exercises();

        return view('admin.exercises', [
            'exercises' => $exercises,
            'missingVideos' => $this->catalog->missingVideoCount(),
            'athleanCount' => $exercises->filter(
                fn ($exercise) => str_contains((string) $exercise->channel, 'ATHLEAN'),
            )->count(),
        ]);
    }

    public function updateExercise(Request $request, Exercise $exercise): RedirectResponse
    {
        $data = $request->validate([
            'youtube_url' => ['required', 'string', 'max:200'],
            'title' => ['nullable', 'string', 'max:160'],
        ]);

        $id = $this->extractYoutubeId($data['youtube_url']);
        if ($id === null) {
            return back()->withErrors(['youtube_url' => 'Geen geldige YouTube-link of video-id.']);
        }

        $exercise->youtube_id = $id;
        $exercise->title = $data['title'] ?: $exercise->title;
        $exercise->channel = $this->catalog->isVerifiedAthlean($id) ? 'ATHLEAN-X™' : '';
        $exercise->save();

        return back()->with('status', $exercise->name.' video bijgewerkt.');
    }

    public function rules(): View
    {
        $prefs = $this->factory->preferences();

        return view('admin.rules', [
            'increment' => (float) $prefs->overload_increment,
            'frequency' => $prefs->overload_frequency,
        ]);
    }

    public function preferences(): View
    {
        return view('admin.preferences', [
            'prefs' => $this->factory->preferences(),
        ]);
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'overload_increment' => ['required', 'numeric', 'min:0.5', 'max:10'],
            'overload_frequency' => ['required', 'in:weekly,biweekly'],
            'current_week' => ['required', 'integer', 'min:1', 'max:14'],
        ]);

        $prefs = $this->factory->preferences();
        $prefs->fill($data)->save();

        return back()->with('status', 'Voorkeuren opgeslagen. De trainer gebruikt dit bij de volgende overload.');
    }

    private function extractYoutubeId(string $value): ?string
    {
        $trimmed = trim($value);
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $trimmed) === 1) {
            return $trimmed;
        }
        if (preg_match('/(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|shorts\/|watch\?v=|watch\?.+&v=))([a-zA-Z0-9_-]{11})/', $trimmed, $match) === 1) {
            return $match[1];
        }

        return null;
    }
}
