<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Whisper" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Whisper', 'href' => route('whisper.dashboard'), 'icon' => 'microphone'],
        ]" />
    </x-slot>

    <x-ui-page-container>
        @php
            $hasInFlight = $recordings->whereIn('status', ['pending', 'processing'])->isNotEmpty();
        @endphp

        <div class="space-y-6"
             @if($hasInFlight) wire:poll.5s @endif>
            {{-- Stats --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="p-4 rounded-lg border border-[var(--ui-border)] bg-[var(--ui-muted-5)]">
                    <div class="text-xs uppercase text-[var(--ui-muted)] mb-1">Aufnahmen</div>
                    <div class="text-2xl font-semibold text-[var(--ui-secondary)]">{{ $stats['total'] }}</div>
                </div>
                <div class="p-4 rounded-lg border border-[var(--ui-border)] bg-[var(--ui-muted-5)]">
                    <div class="text-xs uppercase text-[var(--ui-muted)] mb-1">Fertig</div>
                    <div class="text-2xl font-semibold text-emerald-600">{{ $stats['completed'] }}</div>
                </div>
                <div class="p-4 rounded-lg border border-[var(--ui-border)] bg-[var(--ui-muted-5)]">
                    <div class="text-xs uppercase text-[var(--ui-muted)] mb-1">In Verarbeitung</div>
                    <div class="text-2xl font-semibold text-blue-600">{{ $stats['in_flight'] }}</div>
                </div>
                <div class="p-4 rounded-lg border border-[var(--ui-border)] bg-[var(--ui-muted-5)]">
                    <div class="text-xs uppercase text-[var(--ui-muted)] mb-1">Audio gesamt</div>
                    <div class="text-2xl font-semibold text-[var(--ui-secondary)]">{{ $stats['total_minutes'] }} min</div>
                </div>
            </div>

            {{-- Recorder Panel --}}
            <x-ui-panel title="Audio aufnehmen" subtitle="Lange Meetings sind ok — Aufnahme wird gechunked und im Hintergrund verarbeitet.">
                <div class="px-6 py-10 flex flex-col items-center justify-center gap-5"
                     wire:ignore
                     x-data="{
                        state: 'idle',
                        error: null,
                        info: null,
                        mediaRecorder: null,
                        chunks: [],
                        stream: null,
                        startedAt: null,
                        elapsed: 0,
                        timer: null,
                        uploadUrl: '{{ route('whisper.upload') }}',
                        init() {
                            if (!navigator.mediaDevices || !window.MediaRecorder) {
                                this.error = 'Dein Browser unterstützt keine Audioaufnahme.';
                            }
                        },
                        async start() {
                            this.error = null;
                            this.info = null;
                            try {
                                this.stream = await navigator.mediaDevices.getUserMedia({
                                    audio: {
                                        channelCount: 1,
                                        echoCancellation: true,
                                        noiseSuppression: true,
                                        autoGainControl: true,
                                    },
                                });
                            } catch (e) {
                                this.error = 'Mikrofon-Zugriff verweigert: ' + e.message;
                                return;
                            }
                            this.chunks = [];
                            const mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
                                ? 'audio/webm;codecs=opus'
                                : (MediaRecorder.isTypeSupported('audio/webm')
                                    ? 'audio/webm'
                                    : (MediaRecorder.isTypeSupported('audio/mp4') ? 'audio/mp4' : ''));
                            const opts = { audioBitsPerSecond: 24000 };
                            if (mime) opts.mimeType = mime;
                            this.mediaRecorder = new MediaRecorder(this.stream, opts);
                            this.mediaRecorder.addEventListener('dataavailable', (e) => {
                                if (e.data && e.data.size > 0) this.chunks.push(e.data);
                            });
                            this.mediaRecorder.addEventListener('stop', () => this.upload());
                            // 1-Sekunden-Timeslice → regelmäßige dataavailable Events,
                            // damit auch lange Aufnahmen sauber als Blob enden.
                            this.mediaRecorder.start(1000);
                            this.state = 'recording';
                            this.startedAt = Date.now();
                            this.elapsed = 0;
                            this.timer = setInterval(() => {
                                this.elapsed = Math.floor((Date.now() - this.startedAt) / 1000);
                            }, 250);
                        },
                        stop() {
                            if (this.timer) { clearInterval(this.timer); this.timer = null; }
                            if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
                                this.mediaRecorder.stop();
                            }
                            if (this.stream) {
                                this.stream.getTracks().forEach(t => t.stop());
                            }
                            this.state = 'uploading';
                        },
                        async upload() {
                            const blob = new Blob(this.chunks, { type: this.mediaRecorder?.mimeType || 'audio/webm' });
                            const sizeMb = (blob.size / 1024 / 1024).toFixed(1);
                            this.info = 'Upload (' + sizeMb + ' MB) läuft…';
                            const fd = new FormData();
                            const ext = (this.mediaRecorder?.mimeType || '').includes('mp4') ? 'm4a' : 'webm';
                            fd.append('audio', blob, 'audio.' + ext);
                            const csrf = document.querySelector('meta[name=&quot;csrf-token&quot;]')?.getAttribute('content');
                            try {
                                const res = await fetch(this.uploadUrl, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                                    body: fd,
                                });
                                const data = await res.json().catch(() => ({}));
                                if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
                                this.state = 'idle';
                                this.chunks = [];
                                this.info = null;
                                if (data.redirect) {
                                    window.location.href = data.redirect;
                                } else if (window.Livewire) {
                                    window.Livewire.dispatch('recording-saved');
                                }
                            } catch (e) {
                                this.error = 'Upload fehlgeschlagen: ' + e.message;
                                this.state = 'idle';
                                this.chunks = [];
                                this.info = null;
                            }
                        },
                        formatElapsed() {
                            const h = Math.floor(this.elapsed / 3600).toString().padStart(2, '0');
                            const m = Math.floor((this.elapsed % 3600) / 60).toString().padStart(2, '0');
                            const s = (this.elapsed % 60).toString().padStart(2, '0');
                            return h + ':' + m + ':' + s;
                        },
                     }"
                     x-init="init()">

                    {{-- Status indicator --}}
                    <template x-if="error">
                        <div class="w-full max-w-md p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm text-center" x-text="error"></div>
                    </template>
                    <template x-if="info">
                        <div class="w-full max-w-md p-3 rounded-lg bg-sky-50 border border-sky-200 text-sky-700 text-sm text-center" x-text="info"></div>
                    </template>

                    {{-- Idle state --}}
                    <div x-show="state === 'idle'" class="flex flex-col items-center gap-4">
                        <button type="button"
                                @click="start()"
                                aria-label="Aufnahme starten"
                                style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); box-shadow: 0 10px 30px -8px rgba(220, 38, 38, 0.55), 0 0 0 1px rgba(255,255,255,0.06) inset;"
                                class="group relative inline-flex items-center justify-center w-28 h-28 rounded-full text-white transition-transform duration-150 hover:scale-105 active:scale-95 focus:outline-none focus:ring-4 focus:ring-rose-300">
                            <span class="absolute inset-0 rounded-full opacity-0 group-hover:opacity-100 transition" style="background: radial-gradient(circle at 30% 30%, rgba(255,255,255,0.18), transparent 60%);"></span>
                            @svg('heroicon-s-microphone', 'relative w-12 h-12')
                        </button>
                        <div class="text-center">
                            <div class="text-base font-medium text-[var(--ui-secondary)]">Aufnahme starten</div>
                            <div class="text-xs text-[var(--ui-muted)] mt-0.5">Klicke den Button und sprich los</div>
                        </div>
                    </div>

                    {{-- Recording state --}}
                    <div x-show="state === 'recording'" class="flex flex-col items-center gap-4">
                        <div class="relative inline-flex items-center justify-center">
                            <span class="absolute inline-flex w-32 h-32 rounded-full bg-rose-500/30 animate-ping"></span>
                            <button type="button"
                                    @click="stop()"
                                    aria-label="Aufnahme stoppen"
                                    style="background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); box-shadow: 0 10px 30px -8px rgba(185, 28, 28, 0.65), 0 0 0 1px rgba(255,255,255,0.08) inset;"
                                    class="relative inline-flex items-center justify-center w-28 h-28 rounded-full text-white transition-transform hover:scale-105 active:scale-95 focus:outline-none focus:ring-4 focus:ring-rose-300">
                                @svg('heroicon-s-stop', 'w-12 h-12')
                            </button>
                        </div>
                        <div class="text-center">
                            <div class="text-3xl font-mono font-semibold text-rose-600 tracking-wider" x-text="formatElapsed()"></div>
                            <div class="text-xs text-[var(--ui-muted)] mt-1">Klicke zum Stoppen</div>
                        </div>
                    </div>

                    {{-- Uploading / processing --}}
                    <div x-show="state === 'uploading'" class="flex flex-col items-center gap-4">
                        <div class="inline-flex items-center justify-center w-28 h-28 rounded-full bg-[var(--ui-muted-5)] border border-[var(--ui-border)]">
                            @svg('heroicon-o-arrow-path', 'w-12 h-12 text-[var(--ui-secondary)] animate-spin')
                        </div>
                        <div class="text-center">
                            <div class="text-base font-medium text-[var(--ui-secondary)]">Lade hoch …</div>
                            <div class="text-xs text-[var(--ui-muted)] mt-0.5">Job wird in die Queue gestellt</div>
                        </div>
                    </div>
                </div>
            </x-ui-panel>

            {{-- Recordings Liste --}}
            <x-ui-panel title="Aufnahmen" subtitle="Letzte 50 Aufnahmen">
                @if($recordings->isEmpty())
                    <div class="p-6 text-center text-[var(--ui-muted)]">
                        Noch keine Aufnahmen. Starte jetzt deine erste Aufnahme.
                    </div>
                @else
                    <div class="divide-y divide-[var(--ui-border)]">
                        @foreach($recordings as $rec)
                            @php
                                $dotColor = match($rec->status) {
                                    'completed' => '#10b981',
                                    'processing' => '#3b82f6',
                                    'pending' => '#9ca3af',
                                    'failed' => '#ef4444',
                                    default => '#9ca3af',
                                };
                            @endphp
                            <div class="flex items-center gap-3 px-4 py-2 hover:bg-[var(--ui-muted-5)] transition text-sm">
                                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background-color: {{ $dotColor }}" title="{{ $rec->status }}"></span>
                                <a href="{{ route('whisper.recordings.show', ['recording' => $rec->id]) }}"
                                   wire:navigate
                                   class="flex-grow-1 min-w-0 truncate text-[var(--ui-primary)] hover:underline">
                                    {{ $rec->title ?: 'Aufnahme #'.$rec->id }}
                                </a>
                                <span class="text-xs text-[var(--ui-muted)] flex-shrink-0 hidden sm:inline">{{ $rec->created_at->format('d.m.Y H:i') }}</span>
                                <span class="text-xs text-[var(--ui-muted)] flex-shrink-0 font-mono w-16 text-right">{{ $rec->duration_seconds ? gmdate('H:i:s', $rec->duration_seconds) : '—' }}</span>
                                @if($rec->speakers_count && $rec->speakers_count > 1)
                                    <span class="text-xs text-[var(--ui-muted)] flex-shrink-0 inline-flex items-center gap-1" title="{{ $rec->speakers_count }} Sprecher">
                                        @svg('heroicon-o-users', 'w-3.5 h-3.5')
                                        {{ $rec->speakers_count }}
                                    </span>
                                @endif
                                <button type="button"
                                        wire:click="deleteRecording({{ $rec->id }})"
                                        wire:confirm="Aufnahme wirklich löschen?"
                                        class="flex-shrink-0 p-1 rounded text-[var(--ui-muted)] hover:text-rose-600 hover:bg-rose-50 transition"
                                        title="Löschen">
                                    @svg('heroicon-o-trash', 'w-4 h-4')
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui-panel>
        </div>
    </x-ui-page-container>

    {{-- Rechte Sidebar --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Letzte Aufnahmen" width="w-72" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-2 text-sm">
                @foreach($recordings->take(10) as $rec)
                    <a href="{{ route('whisper.recordings.show', ['recording' => $rec->id]) }}"
                       wire:navigate
                       class="block p-2 rounded border border-[var(--ui-border)]/60 bg-[var(--ui-muted-5)] hover:bg-[var(--ui-muted-10)]">
                        <div class="font-medium text-[var(--ui-secondary)] truncate">{{ $rec->title ?: 'Aufnahme #'.$rec->id }}</div>
                        <div class="text-xs text-[var(--ui-muted)]">{{ $rec->created_at->diffForHumans() }} · {{ $rec->status }}</div>
                    </a>
                @endforeach
            </div>
        </x-ui-page-sidebar>
    </x-slot>
</x-ui-page>
