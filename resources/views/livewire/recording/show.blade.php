@php
    $title = $recording->title ?: 'Aufnahme #'.$recording->id;
    $src = $recording->sourceDisplay();
    $recordedAt = ($recording->recorded_at ?? $recording->created_at)->copy()->timezone(config('app.timezone'))->locale('de');
    $fmt = function (float $s): string {
        $s = (int) floor($s);
        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    };
    $hasAudio = $audioUrl !== null;
    $plainText = collect($segments)->map(fn ($s) => '['.$fmt($s['start']).'] '.($speakers[$s['speaker']]['name'] ?? 'Sprecher '.$s['speaker']).': '.$s['text'])->implode("\n\n")
        ?: (string) $recording->transcript;
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar :title="$title" icon="heroicon-o-microphone" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Whisper', 'href' => route('whisper.dashboard'), 'icon' => 'microphone'],
            ['label' => $title, 'href' => route('whisper.recordings.show', ['recording' => $recording->id]), 'icon' => 'document-text'],
        ]">
            <x-nx-dropdown label="Aktionen">
                <x-nx-dropdown-item x-data x-on:click="navigator.clipboard.writeText(document.getElementById('whisper-plain').value)">
                    @svg('heroicon-o-clipboard-document', 'h-4 w-4') Transkript kopieren
                </x-nx-dropdown-item>
                <x-nx-dropdown-item x-data
                    x-on:click="const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([document.getElementById('whisper-plain').value], { type: 'text/plain;charset=utf-8' })); a.download = @js(\Illuminate\Support\Str::slug($title).'.txt'); a.click();">
                    @svg('heroicon-o-document-arrow-down', 'h-4 w-4') Transkript als .txt
                </x-nx-dropdown-item>
                @if($hasAudio)
                    <x-nx-dropdown-item :href="$audioUrl" download>
                        @svg('heroicon-o-musical-note', 'h-4 w-4') Audio herunterladen
                    </x-nx-dropdown-item>
                    <x-nx-dropdown-item wire:click="retranscribe" :disabled="$isInFlight"
                        wire:confirm="Neu transkribieren? Das aktuelle Transkript wird ersetzt.">
                        @svg('heroicon-o-arrow-path', 'h-4 w-4') Neu transkribieren
                    </x-nx-dropdown-item>
                @endif
                <x-nx-dropdown-divider />
                <x-nx-dropdown-item variant="danger" wire:click="delete"
                    wire:confirm="Aufnahme wirklich löschen? Das lässt sich nicht rückgängig machen.">
                    @svg('heroicon-o-trash', 'h-4 w-4') Löschen
                </x-nx-dropdown-item>
            </x-nx-dropdown>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="contained" spacing="space-y-6">
        {{-- Klartext für Kopieren/Download — einmal im DOM statt in jedem Klick-Attribut. --}}
        <textarea id="whisper-plain" hidden aria-hidden="true">{{ $plainText }}</textarea>
        <div @if($isInFlight) wire:poll.4s @endif
             x-data="{
                tab: 'transcript',
                playing: false,
                current: 0,
                duration: {{ (float) ($recording->duration_seconds ?: 0) }},
                rate: 1,
                follow: true,
                active: -1,
                starts: @js(array_column($segments, 'start')),
                q: '',
                hits: null,
                audio() { return this.$refs.audio; },
                toggle() { const a = this.audio(); a.paused ? a.play() : a.pause(); },
                skip(s) { const a = this.audio(); a.currentTime = Math.max(0, Math.min((a.duration || this.duration), a.currentTime + s)); },
                seek(s) { const a = this.audio(); if (!a) return; a.currentTime = s; a.play(); },
                scrub(e) { this.audio().currentTime = e.target.value; },
                cycleRate() { const r = [1, 1.25, 1.5, 2]; this.rate = r[(r.indexOf(this.rate) + 1) % r.length]; this.audio().playbackRate = this.rate; },
                onTime() {
                    this.current = this.audio().currentTime;
                    let lo = 0, hi = this.starts.length - 1, idx = -1;
                    while (lo <= hi) { const mid = (lo + hi) >> 1; if (this.starts[mid] <= this.current) { idx = mid; lo = mid + 1; } else hi = mid - 1; }
                    if (idx === this.active) return;
                    this.$refs.lines?.querySelector('[data-i=\'' + this.active + '\']')?.classList.remove('is-active');
                    this.active = idx;
                    const row = this.$refs.lines?.querySelector('[data-i=\'' + idx + '\']');
                    row?.classList.add('is-active');
                    if (row && this.follow && this.playing && this.tab === 'transcript') row.scrollIntoView({ block: 'center', behavior: 'smooth' });
                },
                filter() {
                    const term = this.q.trim().toLowerCase();
                    let n = 0;
                    this.$refs.lines?.querySelectorAll('[data-i]').forEach(row => {
                        const match = !term || row.dataset.text.includes(term);
                        row.hidden = !match;
                        if (match && term) n++;
                    });
                    this.hits = term ? n : null;
                },
                clock(s) {
                    s = Math.floor(s || 0);
                    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
                    return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(sec).padStart(2, '0');
                },
             }"
             class="space-y-6">

            {{-- Kopf --}}
            <div class="space-y-3">
                <a href="{{ route('whisper.dashboard') }}" wire:navigate
                   class="inline-flex items-center gap-1 text-xs text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)]">
                    @svg('heroicon-o-arrow-left', 'h-3.5 w-3.5') Aufnahmen
                </a>
                <div class="flex items-center gap-2 text-xs text-[color:var(--nx-muted)]">
                    <span class="inline-flex items-center gap-1 rounded-full bg-[color:var(--nx-accent-soft)] px-2 py-0.5 font-medium text-[color:var(--nx-text)]">
                        @svg($src['icon'], 'h-3.5 w-3.5') {{ $src['label'] }}
                    </span>
                    <span>{{ $recordedAt->isoFormat('dddd, D. MMMM YYYY') }} · {{ $recording->timeRange() }}</span>
                </div>
                <div x-data="{ editing: false, value: @js($title) }">
                    <h1 x-show="!editing" @click="editing = true; $nextTick(() => $refs.title.select())"
                        title="Zum Umbenennen klicken"
                        class="-mx-1 cursor-text rounded-[6px] px-1 text-2xl font-semibold tracking-tight text-[color:var(--nx-text)] hover:bg-[color:var(--nx-hover)]"
                        x-text="value"></h1>
                    <input x-show="editing" x-cloak x-ref="title" x-model="value" maxlength="255"
                           @keydown.enter.prevent="editing = false; $wire.renameTitle(value)"
                           @keydown.escape="editing = false; value = @js($title)"
                           @blur="if (editing) { editing = false; $wire.renameTitle(value) }"
                           class="-mx-1 w-full rounded-[6px] border border-[color:var(--nx-line-strong)] bg-[color:var(--nx-surface)] px-1 text-2xl font-semibold tracking-tight text-[color:var(--nx-text)] focus:outline-none focus:ring-1 focus:ring-[color:var(--nx-accent)]">
                </div>
            </div>

            {{-- Eigenschaften --}}
            <div class="-mx-2">
                @if($d = $recording->durationLabel())
                    <x-nx-property-row icon="heroicon-o-clock" label="Dauer">{{ $d }}</x-nx-property-row>
                @endif
                @if(count($speakers) > 0)
                    <x-nx-property-row icon="heroicon-o-users" label="Sprecher">
                        <div class="flex flex-wrap items-center gap-1.5">
                            @foreach($speakers as $sp)
                                <div wire:key="sp-{{ $sp['label'] }}"
                                     x-data="{ editing: false, value: @js($sp['name'] ?? '') }"
                                     class="inline-flex items-center gap-1.5 rounded-full border border-[color:var(--nx-line)] py-0.5 pl-1.5 pr-2 text-xs"
                                     title="{{ $sp['share'] }} % Redeanteil — klicken zum Benennen">
                                    <span class="h-2 w-2 rounded-full" style="background: var(--nx-tone-{{ $sp['tone'] }})"></span>
                                    <button type="button" x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.focus())"
                                            class="font-medium text-[color:var(--nx-text)]"
                                            x-text="value || @js('Sprecher '.$sp['label'])"></button>
                                    <input x-show="editing" x-cloak x-ref="input" x-model="value" maxlength="80" placeholder="Name"
                                           @keydown.enter.prevent="editing = false; $wire.renameSpeaker(@js($sp['label']), value)"
                                           @keydown.escape="editing = false; value = @js($sp['name'] ?? '')"
                                           @blur="if (editing) { editing = false; $wire.renameSpeaker(@js($sp['label']), value) }"
                                           class="w-28 border-0 border-b border-[color:var(--nx-line-strong)] bg-transparent p-0 text-xs focus:outline-none focus:ring-0">
                                    <span class="tabular-nums text-[color:var(--nx-faint)]">{{ $sp['share'] }} %</span>
                                </div>
                            @endforeach
                        </div>
                        @if(count($speakers) > 1)
                            <div class="mt-2 flex h-1.5 max-w-md overflow-hidden rounded-full" aria-hidden="true">
                                @foreach($speakers as $sp)
                                    <span style="width: {{ $sp['share'] }}%; background: var(--nx-tone-{{ $sp['tone'] }})"></span>
                                @endforeach
                            </div>
                        @endif
                    </x-nx-property-row>
                @endif
                <x-nx-property-row icon="heroicon-o-language" label="Sprache">{{ $recording->language ? strtoupper($recording->language) : '—' }}</x-nx-property-row>
                @if($recording->device_serial)
                    <x-nx-property-row icon="heroicon-o-device-phone-mobile" label="Gerät">
                        <span class="font-mono text-xs">{{ $recording->device_serial }}</span>
                    </x-nx-property-row>
                @endif
                <x-nx-property-row icon="heroicon-o-cpu-chip" label="Transkription">
                    <span class="text-[color:var(--nx-muted)]">{{ str_starts_with((string) $recording->model, 'import:') ? 'übernommen von '.$src['label'] : ($recording->model ?: '—') }}</span>
                </x-nx-property-row>
            </div>

            {{-- Status --}}
            @if($isInFlight)
                <x-nx-callout variant="info" icon="heroicon-o-arrow-path" :title="$recording->status === 'pending' ? 'Wartet auf die Transkription' : 'Wird transkribiert …'">
                    Das Audio ist gesichert. Je nach Länge dauert das ein paar Minuten — die Seite aktualisiert sich von selbst.
                </x-nx-callout>
            @elseif($recording->status === 'failed')
                <x-nx-callout variant="danger" title="Transkription fehlgeschlagen">
                    <div class="whitespace-pre-wrap">{{ $recording->error_message }}</div>
                    @if($hasAudio)
                        <x-slot name="action">
                            <x-nx-button wire:click="retranscribe">@svg('heroicon-o-arrow-path', 'h-4 w-4') Erneut versuchen</x-nx-button>
                        </x-slot>
                    @endif
                </x-nx-callout>
            @elseif($recording->error_message)
                <x-nx-callout variant="warning" title="Hinweis">
                    <div class="whitespace-pre-wrap text-xs">{{ $recording->error_message }}</div>
                </x-nx-callout>
            @endif

            {{-- Player --}}
            @if($hasAudio)
                <div class="sticky top-0 z-10 -mx-2 bg-[color:var(--nx-surface)] px-2 py-2">
                    <div class="flex items-center gap-3 rounded-[8px] border border-[color:var(--nx-line)] px-3 py-2">
                        <audio x-ref="audio" src="{{ $audioUrl }}" preload="metadata" class="hidden"
                               @play="playing = true" @pause="playing = false" @ended="playing = false"
                               @timeupdate="onTime()" @loadedmetadata="duration = $refs.audio.duration || duration"></audio>
                        <button type="button" @click="skip(-15)" title="15 s zurück"
                                class="flex h-8 w-8 items-center justify-center rounded-full text-[color:var(--nx-muted)] hover:bg-[color:var(--nx-hover)] hover:text-[color:var(--nx-text)]">
                            @svg('heroicon-o-backward', 'h-4 w-4')
                        </button>
                        <button type="button" @click="toggle()" :title="playing ? 'Pause' : 'Abspielen'"
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-[color:var(--nx-accent)] text-[color:var(--nx-on-accent)] hover:bg-[color:var(--nx-accent-hover)]">
                            <span x-show="!playing">@svg('heroicon-s-play', 'ml-0.5 h-4 w-4')</span>
                            <span x-show="playing" x-cloak>@svg('heroicon-s-pause', 'h-4 w-4')</span>
                        </button>
                        <button type="button" @click="skip(15)" title="15 s vor"
                                class="flex h-8 w-8 items-center justify-center rounded-full text-[color:var(--nx-muted)] hover:bg-[color:var(--nx-hover)] hover:text-[color:var(--nx-text)]">
                            @svg('heroicon-o-forward', 'h-4 w-4')
                        </button>
                        <span class="w-12 text-right text-xs tabular-nums text-[color:var(--nx-muted)]" x-text="clock(current)"></span>
                        <input type="range" min="0" step="0.1" :max="duration || 0" :value="current" @input="scrub($event)"
                               class="h-1 min-w-0 flex-1 cursor-pointer accent-[color:var(--nx-accent)]" aria-label="Position">
                        <span class="w-12 text-xs tabular-nums text-[color:var(--nx-faint)]" x-text="clock(duration)"></span>
                        <button type="button" @click="cycleRate()" title="Geschwindigkeit"
                                class="h-7 w-11 rounded-[6px] text-xs font-medium tabular-nums text-[color:var(--nx-muted)] hover:bg-[color:var(--nx-hover)] hover:text-[color:var(--nx-text)]"
                                x-text="rate + '×'"></button>
                    </div>
                </div>
            @endif

            {{-- Reiter --}}
            <div class="space-y-4">
                <div class="flex items-center gap-4 border-b border-[color:var(--nx-line)]">
                    <button type="button" @click="tab = 'transcript'"
                            :class="tab === 'transcript' ? 'border-[color:var(--nx-text)] text-[color:var(--nx-text)]' : 'border-transparent text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)]'"
                            class="-mb-px border-b-2 px-1 py-2 text-sm font-medium">
                        Transkript
                    </button>
                    @if($sourceNoteHtml)
                        <button type="button" @click="tab = 'note'"
                                :class="tab === 'note' ? 'border-[color:var(--nx-text)] text-[color:var(--nx-text)]' : 'border-transparent text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)]'"
                                class="-mb-px border-b-2 px-1 py-2 text-sm font-medium">
                            Notiz von {{ $src['label'] }}
                        </button>
                    @endif
                    <div x-show="tab === 'transcript'" class="ml-auto flex items-center gap-3 pb-1.5">
                        @if($hasAudio && count($segments) > 0)
                            <label class="inline-flex cursor-pointer items-center gap-1.5 text-xs text-[color:var(--nx-muted)]"
                                   title="Beim Abspielen zur aktuellen Stelle scrollen">
                                <input type="checkbox" x-model="follow" class="h-3.5 w-3.5 rounded border-[color:var(--nx-line-strong)] accent-[color:var(--nx-accent)]">
                                Mitlaufen
                            </label>
                        @endif
                        @if(count($segments) > 0)
                            <div class="relative">
                                @svg('heroicon-o-magnifying-glass', 'pointer-events-none absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[color:var(--nx-faint)]')
                                <input type="search" x-model="q" @input.debounce.150ms="filter()" placeholder="Im Transkript suchen"
                                       class="w-56 rounded-[6px] border border-[color:var(--nx-line-strong)] bg-[color:var(--nx-surface)] py-1 pl-7 pr-2 text-xs text-[color:var(--nx-text)] placeholder-[color:var(--nx-faint)] focus:border-[color:var(--nx-accent)] focus:outline-none focus:ring-1 focus:ring-[color:var(--nx-accent)]">
                            </div>
                            <span x-show="hits !== null" x-cloak class="text-xs tabular-nums text-[color:var(--nx-muted)]" x-text="hits + ' Treffer'"></span>
                        @endif
                    </div>
                </div>

                {{-- Transkript --}}
                <div x-show="tab === 'transcript'">
                    @if(count($segments) > 0)
                        <div x-ref="lines" class="space-y-0.5">
                            @foreach($segments as $i => $seg)
                                @php
                                    $sp = $speakers[$seg['speaker']];
                                    $newSpeaker = $i === 0 || $segments[$i - 1]['speaker'] !== $seg['speaker'];
                                @endphp
                                <div data-i="{{ $i }}" data-text="{{ mb_strtolower($seg['text'].' '.($sp['name'] ?? '')) }}"
                                     @class(['group flex gap-4 rounded-[6px] px-2 py-1.5 transition-colors hover:bg-[color:var(--nx-hover)] [&.is-active]:bg-[color:var(--nx-accent-soft)]',
                                             'mt-3' => $newSpeaker && $i > 0])>
                                    <button type="button" @if($hasAudio) @click="seek({{ $seg['start'] }})" title="Ab hier abspielen" @else disabled @endif
                                            class="w-12 shrink-0 pt-0.5 text-right text-xs tabular-nums text-[color:var(--nx-faint)] {{ $hasAudio ? 'hover:text-[color:var(--nx-text)] hover:underline' : 'cursor-default' }}">
                                        {{ $fmt($seg['start']) }}
                                    </button>
                                    <div class="min-w-0 max-w-[70ch]">
                                        @if($newSpeaker)
                                            <div class="mb-0.5 flex items-center gap-1.5 text-xs font-semibold" style="color: var(--nx-tone-{{ $sp['tone'] }})">
                                                <span class="h-1.5 w-1.5 rounded-full" style="background: var(--nx-tone-{{ $sp['tone'] }})"></span>
                                                {{ $sp['name'] ?? 'Sprecher '.$sp['label'] }}
                                            </div>
                                        @endif
                                        <p class="text-[15px] leading-relaxed text-[color:var(--nx-text)] [overflow-wrap:anywhere]">{{ $seg['text'] }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div x-show="hits === 0" x-cloak>
                            <x-nx-empty icon="heroicon-o-magnifying-glass">Nichts gefunden.</x-nx-empty>
                        </div>
                    @elseif($recording->transcript)
                        <p class="max-w-[70ch] whitespace-pre-wrap text-[15px] leading-relaxed text-[color:var(--nx-text)]">{{ $recording->transcript }}</p>
                    @else
                        <x-nx-empty icon="heroicon-o-document-text">
                            {{ $isInFlight ? 'Das Transkript erscheint hier, sobald es fertig ist.' : 'Kein Transkript vorhanden.' }}
                        </x-nx-empty>
                    @endif
                </div>

                {{-- Notiz der Quelle --}}
                @if($sourceNoteHtml)
                    <div x-show="tab === 'note'" x-cloak class="space-y-3">
                        <p class="text-xs text-[color:var(--nx-muted)]">
                            Von {{ $src['label'] }} mitgeliefert — zum Vergleich. Die eigene Zusammenfassung entsteht im Eingang.
                        </p>
                        <x-nx-prose>{!! $sourceNoteHtml !!}</x-nx-prose>
                    </div>
                @endif
            </div>
        </div>
    </x-ui-page-container>
</x-ui-page>
