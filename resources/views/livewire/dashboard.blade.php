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
                <div class="p-6 d-flex flex-col items-center gap-4"
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
                        <div class="w-full p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm" x-text="error"></div>
                    </template>
                    <template x-if="info">
                        <div class="w-full p-3 rounded-lg bg-blue-50 border border-blue-200 text-blue-700 text-sm" x-text="info"></div>
                    </template>

                    {{-- Idle state --}}
                    <div x-show="state === 'idle'" class="d-flex flex-col items-center gap-3">
                        <button type="button"
                                @click="start()"
                                class="d-flex items-center justify-center w-24 h-24 rounded-full bg-red-600 hover:bg-red-700 text-white shadow-lg transition">
                            @svg('heroicon-o-microphone', 'w-12 h-12')
                        </button>
                        <div class="text-sm text-[var(--ui-muted)]">Klicke zum Aufnehmen</div>
                    </div>

                    {{-- Recording state --}}
                    <div x-show="state === 'recording'" class="d-flex flex-col items-center gap-3">
                        <button type="button"
                                @click="stop()"
                                class="d-flex items-center justify-center w-24 h-24 rounded-full bg-red-600 text-white shadow-lg animate-pulse">
                            @svg('heroicon-o-stop', 'w-12 h-12')
                        </button>
                        <div class="text-2xl font-mono text-red-600" x-text="formatElapsed()"></div>
                        <div class="text-sm text-[var(--ui-muted)]">Klicke zum Stoppen</div>
                    </div>

                    {{-- Uploading / processing --}}
                    <div x-show="state === 'uploading'" class="d-flex flex-col items-center gap-3">
                        <div class="d-flex items-center justify-center w-24 h-24 rounded-full bg-[var(--ui-muted-5)]">
                            @svg('heroicon-o-arrow-path', 'w-12 h-12 text-[var(--ui-secondary)] animate-spin')
                        </div>
                        <div class="text-sm text-[var(--ui-muted)]">Lade hoch und stelle Job in die Queue…</div>
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
                                $variant = match($rec->status) {
                                    'completed' => 'success',
                                    'processing' => 'info',
                                    'pending' => 'secondary',
                                    'failed' => 'danger',
                                    default => 'secondary',
                                };
                            @endphp
                            <div class="d-flex items-center gap-4 px-5 py-4 hover:bg-[var(--ui-muted-5)] transition">
                                <div class="flex-grow-1 min-w-0">
                                    <a href="{{ route('whisper.recordings.show', ['recording' => $rec->id]) }}"
                                       wire:navigate
                                       class="font-medium text-[var(--ui-primary)] hover:underline truncate block">
                                        {{ $rec->title ?: 'Aufnahme #'.$rec->id }}
                                    </a>
                                    <div class="d-flex items-center gap-3 mt-1 text-xs text-[var(--ui-muted)]">
                                        <span>{{ $rec->created_at->format('d.m.Y H:i') }}</span>
                                        <span>·</span>
                                        <span>{{ $rec->duration_seconds ? gmdate('H:i:s', $rec->duration_seconds) : '—' }}</span>
                                        @if($rec->language)
                                            <span>·</span>
                                            <span class="uppercase">{{ $rec->language }}</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="d-flex items-center gap-2 flex-shrink-0">
                                    <x-ui-badge :variant="$variant">{{ $rec->status }}</x-ui-badge>
                                    @if(in_array($rec->status, ['pending','processing']) && $rec->chunks_total)
                                        <span class="text-xs text-[var(--ui-muted)]">{{ $rec->progressPercent() }}%</span>
                                    @endif
                                </div>
                                <div class="flex-shrink-0">
                                    <x-ui-button
                                        variant="danger-outline"
                                        size="sm"
                                        wire:click="deleteRecording({{ $rec->id }})"
                                        wire:confirm="Aufnahme wirklich löschen?">
                                        Löschen
                                    </x-ui-button>
                                </div>
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
