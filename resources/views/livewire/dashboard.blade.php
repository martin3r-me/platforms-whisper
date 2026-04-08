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
        <div class="space-y-6">
            {{-- Recorder Panel --}}
            <x-ui-panel title="Audio aufnehmen" subtitle="Sprich los — Whisper transkribiert deine Aufnahme.">
                <div class="p-6 d-flex flex-col items-center gap-4"
                     x-data="whisperRecorder()"
                     x-init="init()"
                     wire:ignore>

                    {{-- Status indicator --}}
                    <template x-if="error">
                        <div class="w-full p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm" x-text="error"></div>
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
                        <div class="text-lg font-mono text-red-600" x-text="formatElapsed()"></div>
                        <div class="text-sm text-[var(--ui-muted)]">Klicke zum Stoppen</div>
                    </div>

                    {{-- Uploading / processing --}}
                    <div x-show="state === 'uploading'" class="d-flex flex-col items-center gap-3">
                        <div class="d-flex items-center justify-center w-24 h-24 rounded-full bg-[var(--ui-muted-5)]">
                            @svg('heroicon-o-arrow-path', 'w-12 h-12 text-[var(--ui-secondary)] animate-spin')
                        </div>
                        <div class="text-sm text-[var(--ui-muted)]">Transkribiere mit Whisper…</div>
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
                    <x-ui-table>
                        <thead>
                            <tr>
                                <th class="text-left">Titel</th>
                                <th class="text-left">Datum</th>
                                <th class="text-left">Dauer</th>
                                <th class="text-left">Sprache</th>
                                <th class="text-left">Status</th>
                                <th class="text-right">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recordings as $rec)
                                <tr>
                                    <td>
                                        <a href="{{ route('whisper.recordings.show', ['recording' => $rec->id]) }}"
                                           wire:navigate
                                           class="font-medium text-[var(--ui-primary)] hover:underline">
                                            {{ $rec->title ?: 'Aufnahme #'.$rec->id }}
                                        </a>
                                    </td>
                                    <td class="text-sm text-[var(--ui-muted)]">{{ $rec->created_at->format('d.m.Y H:i') }}</td>
                                    <td class="text-sm">{{ $rec->duration_seconds ? gmdate('i:s', $rec->duration_seconds) : '—' }}</td>
                                    <td class="text-sm">{{ $rec->language ?: '—' }}</td>
                                    <td>
                                        @php
                                            $variant = match($rec->status) {
                                                'completed' => 'success',
                                                'processing' => 'info',
                                                'failed' => 'danger',
                                                default => 'secondary',
                                            };
                                        @endphp
                                        <x-ui-badge :variant="$variant">{{ $rec->status }}</x-ui-badge>
                                    </td>
                                    <td class="text-right">
                                        <x-ui-button
                                            variant="danger"
                                            size="sm"
                                            wire:click="deleteRecording({{ $rec->id }})"
                                            wire:confirm="Aufnahme wirklich löschen?">
                                            Löschen
                                        </x-ui-button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui-table>
                @endif
            </x-ui-panel>
        </div>
    </x-ui-page-container>

    @push('scripts')
    <script>
        function whisperRecorder() {
            return {
                state: 'idle', // idle | recording | uploading
                error: null,
                mediaRecorder: null,
                chunks: [],
                stream: null,
                startedAt: null,
                elapsed: 0,
                timer: null,

                init() {
                    if (!navigator.mediaDevices || !window.MediaRecorder) {
                        this.error = 'Dein Browser unterstützt keine Audioaufnahme.';
                    }
                },

                async start() {
                    this.error = null;
                    try {
                        this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    } catch (e) {
                        this.error = 'Mikrofon-Zugriff verweigert: ' + e.message;
                        return;
                    }

                    this.chunks = [];
                    const mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
                        ? 'audio/webm;codecs=opus'
                        : 'audio/webm';
                    this.mediaRecorder = new MediaRecorder(this.stream, { mimeType: mime });

                    this.mediaRecorder.addEventListener('dataavailable', (e) => {
                        if (e.data && e.data.size > 0) this.chunks.push(e.data);
                    });

                    this.mediaRecorder.addEventListener('stop', () => this.upload());

                    this.mediaRecorder.start();
                    this.state = 'recording';
                    this.startedAt = Date.now();
                    this.elapsed = 0;
                    this.timer = setInterval(() => {
                        this.elapsed = Math.floor((Date.now() - this.startedAt) / 1000);
                    }, 250);
                },

                stop() {
                    if (this.timer) {
                        clearInterval(this.timer);
                        this.timer = null;
                    }
                    if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
                        this.mediaRecorder.stop();
                    }
                    if (this.stream) {
                        this.stream.getTracks().forEach(t => t.stop());
                    }
                    this.state = 'uploading';
                },

                async upload() {
                    const blob = new Blob(this.chunks, { type: 'audio/webm' });
                    const fd = new FormData();
                    fd.append('audio', blob, 'audio.webm');

                    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

                    try {
                        const res = await fetch('{{ route('whisper.upload') }}', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': csrf,
                                'Accept': 'application/json',
                            },
                            body: fd,
                        });

                        const data = await res.json().catch(() => ({}));

                        if (!res.ok) {
                            throw new Error(data.error || ('HTTP ' + res.status));
                        }

                        this.state = 'idle';
                        this.chunks = [];
                        Livewire.dispatch('recording-saved');
                    } catch (e) {
                        this.error = 'Upload fehlgeschlagen: ' + e.message;
                        this.state = 'idle';
                        this.chunks = [];
                    }
                },

                formatElapsed() {
                    const m = Math.floor(this.elapsed / 60).toString().padStart(2, '0');
                    const s = (this.elapsed % 60).toString().padStart(2, '0');
                    return m + ':' + s;
                },
            }
        }
    </script>
    @endpush
</x-ui-page>
