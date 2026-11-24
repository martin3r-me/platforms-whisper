{{--
    Aufnahme-Leiste: ruhig im Leerlauf (ein Knopf + Upload), aufgeklappt beim Aufnehmen
    (Zeit, Pegel, Pause, Stopp). Startet auch über das Fenster-Event `whisper-record`
    (Knopf in der Actionbar). Upload geht an whisper.upload → RecordingIntakeService.
--}}
<div id="recorder"
     wire:ignore
     x-data="{
        state: 'idle',
        error: null,
        progress: 0,
        mediaRecorder: null,
        chunks: [],
        stream: null,
        audioCtx: null,
        analyser: null,
        levels: Array(32).fill(0),
        raf: null,
        startedAt: null,
        accumulated: 0,
        elapsed: 0,
        timer: null,
        uploadUrl: @js(route('whisper.upload')),
        init() {
            if (!navigator.mediaDevices || !window.MediaRecorder) {
                this.error = 'Dein Browser unterstützt keine Audioaufnahme. Datei-Upload geht trotzdem.';
            }
            window.addEventListener('beforeunload', (e) => {
                if (this.state === 'recording' || this.state === 'paused' || this.state === 'uploading') {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        },
        async start() {
            if (this.state !== 'idle') return;
            this.error = null;
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true },
                });
            } catch (e) {
                this.error = 'Kein Zugriff aufs Mikrofon: ' + e.message;
                return;
            }
            this.chunks = [];
            const mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'].find(m => MediaRecorder.isTypeSupported(m)) || '';
            const opts = { audioBitsPerSecond: 24000 };
            if (mime) opts.mimeType = mime;
            this.mediaRecorder = new MediaRecorder(this.stream, opts);
            this.mediaRecorder.addEventListener('dataavailable', (e) => { if (e.data && e.data.size > 0) this.chunks.push(e.data); });
            this.mediaRecorder.addEventListener('stop', () => this.upload());
            // 1-Sekunden-Timeslice: regelmäßige Daten, damit auch lange Aufnahmen sauber enden.
            this.mediaRecorder.start(1000);
            this.meter();
            this.state = 'recording';
            this.accumulated = 0;
            this.startedAt = Date.now();
            this.elapsed = 0;
            this.timer = setInterval(() => {
                if (this.state === 'recording') this.elapsed = this.accumulated + Math.floor((Date.now() - this.startedAt) / 1000);
            }, 250);
        },
        meter() {
            try {
                this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                this.analyser = this.audioCtx.createAnalyser();
                this.analyser.fftSize = 64;
                this.audioCtx.createMediaStreamSource(this.stream).connect(this.analyser);
                const data = new Uint8Array(this.analyser.frequencyBinCount);
                const tick = () => {
                    this.analyser.getByteFrequencyData(data);
                    this.levels = Array.from(data.slice(0, 32), v => this.state === 'paused' ? 0 : v / 255);
                    this.raf = requestAnimationFrame(tick);
                };
                tick();
            } catch (e) { /* Pegel ist nur Zierde */ }
        },
        pause() {
            if (this.mediaRecorder?.state !== 'recording') return;
            this.mediaRecorder.pause();
            this.accumulated += Math.floor((Date.now() - this.startedAt) / 1000);
            this.elapsed = this.accumulated;
            this.state = 'paused';
        },
        resume() {
            if (this.mediaRecorder?.state !== 'paused') return;
            this.mediaRecorder.resume();
            this.startedAt = Date.now();
            this.state = 'recording';
        },
        stop() {
            clearInterval(this.timer); this.timer = null;
            cancelAnimationFrame(this.raf);
            this.audioCtx?.close();
            if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') this.mediaRecorder.stop();
            this.stream?.getTracks().forEach(t => t.stop());
            this.state = 'uploading';
        },
        async upload() {
            const type = this.mediaRecorder?.mimeType || 'audio/webm';
            await this.send(new Blob(this.chunks, { type }), 'aufnahme.' + (type.includes('mp4') ? 'm4a' : 'webm'));
        },
        async pickFile(event) {
            const file = event.target.files?.[0];
            event.target.value = '';
            if (!file) return;
            this.error = null;
            this.state = 'uploading';
            await this.send(file, file.name);
        },
        send(blob, filename) {
            // XHR statt fetch: nur so gibt es einen Upload-Fortschritt.
            return new Promise((resolve) => {
                const fd = new FormData();
                fd.append('audio', blob, filename);
                const xhr = new XMLHttpRequest();
                xhr.open('POST', this.uploadUrl);
                xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name=csrf-token]')?.content || '');
                xhr.setRequestHeader('Accept', 'application/json');
                this.progress = 0;
                xhr.upload.onprogress = (e) => { if (e.lengthComputable) this.progress = Math.round(e.loaded / e.total * 100); };
                xhr.onload = () => {
                    let data = {};
                    try { data = JSON.parse(xhr.responseText); } catch (e) {}
                    this.chunks = [];
                    if (xhr.status >= 200 && xhr.status < 300) {
                        this.state = 'idle';
                        if (data.redirect) { window.Livewire?.navigate ? Livewire.navigate(data.redirect) : (window.location.href = data.redirect); }
                        else window.Livewire?.dispatch('recording-saved');
                    } else {
                        this.error = 'Upload fehlgeschlagen: ' + (data.error || data.message || ('HTTP ' + xhr.status));
                        this.state = 'idle';
                    }
                    resolve();
                };
                xhr.onerror = () => { this.error = 'Upload fehlgeschlagen: keine Verbindung.'; this.state = 'idle'; resolve(); };
                xhr.send(fd);
            });
        },
        clock() {
            const h = Math.floor(this.elapsed / 3600), m = Math.floor((this.elapsed % 3600) / 60), s = this.elapsed % 60;
            return (h ? h + ':' : '') + String(m).padStart(h ? 2 : 1, '0') + ':' + String(s).padStart(2, '0');
        },
     }"
     x-on:whisper-record.window="start()"
     class="rounded-[8px] border border-[color:var(--nx-line)] bg-[color:var(--nx-surface)]">

    {{-- Leerlauf --}}
    <div x-show="state === 'idle'" class="flex items-center gap-4 px-4 py-3">
        <button type="button" @click="start()" aria-label="Aufnahme starten" title="Aufnahme starten"
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[color:var(--nx-danger)] text-white transition-transform hover:scale-105 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--nx-line-strong)]">
            @svg('heroicon-s-microphone', 'h-5 w-5')
        </button>
        <div class="min-w-0 flex-1">
            <div class="text-sm font-medium text-[color:var(--nx-text)]">Neue Aufnahme</div>
            <div class="text-xs text-[color:var(--nx-muted)]">Mikrofon im Browser — oder eine Datei hochladen (m4a, mp3, wav, ogg …).</div>
        </div>
        <label class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-[6px] border border-[color:var(--nx-line-strong)] px-3 text-sm font-medium text-[color:var(--nx-text)] transition-colors hover:bg-[color:var(--nx-hover)]">
            @svg('heroicon-o-arrow-up-tray', 'h-4 w-4')
            Datei hochladen
            <input type="file" class="hidden" accept="audio/*,.m4a,.mp3,.wav,.webm,.ogg,.aac,.flac,.mp4" @change="pickFile($event)">
        </label>
    </div>

    {{-- Aufnahme / Pause --}}
    <div x-show="state === 'recording' || state === 'paused'" x-cloak class="flex items-center gap-4 px-4 py-3">
        <span class="relative flex h-10 w-10 shrink-0 items-center justify-center">
            <span x-show="state === 'recording'" class="absolute inset-0 animate-ping rounded-full bg-[color:var(--nx-danger)] opacity-20"></span>
            <span class="h-3 w-3 rounded-full"
                  :class="state === 'recording' ? 'bg-[color:var(--nx-danger)]' : 'bg-[color:var(--nx-warning)]'"></span>
        </span>
        <div class="w-20 shrink-0">
            <div class="font-mono text-lg font-semibold tabular-nums text-[color:var(--nx-text)]" x-text="clock()"></div>
            <div class="text-[11px] text-[color:var(--nx-muted)]" x-text="state === 'recording' ? 'Aufnahme läuft' : 'Pausiert'"></div>
        </div>
        <div class="flex h-8 min-w-0 flex-1 items-center gap-[3px]" aria-hidden="true">
            <template x-for="(level, i) in levels" :key="i">
                <span class="w-1 flex-1 rounded-full bg-[color:var(--nx-line-strong)] transition-[height] duration-75"
                      :style="'height:' + Math.max(8, Math.round(level * 100)) + '%'"></span>
            </template>
        </div>
        <button type="button" x-show="state === 'recording'" @click="pause()"
                class="inline-flex h-8 items-center gap-1.5 rounded-[6px] border border-[color:var(--nx-line-strong)] px-3 text-sm font-medium text-[color:var(--nx-text)] hover:bg-[color:var(--nx-hover)]">
            @svg('heroicon-s-pause', 'h-4 w-4') Pause
        </button>
        <button type="button" x-show="state === 'paused'" @click="resume()"
                class="inline-flex h-8 items-center gap-1.5 rounded-[6px] border border-[color:var(--nx-line-strong)] px-3 text-sm font-medium text-[color:var(--nx-text)] hover:bg-[color:var(--nx-hover)]">
            @svg('heroicon-s-play', 'h-4 w-4') Weiter
        </button>
        <button type="button" @click="stop()"
                class="inline-flex h-8 items-center gap-1.5 rounded-[6px] bg-[color:var(--nx-accent)] px-3 text-sm font-medium text-[color:var(--nx-on-accent)] hover:bg-[color:var(--nx-accent-hover)]">
            @svg('heroicon-s-stop', 'h-4 w-4') Beenden & speichern
        </button>
    </div>

    {{-- Upload --}}
    <div x-show="state === 'uploading'" x-cloak class="flex items-center gap-4 px-4 py-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center">
            @svg('heroicon-o-arrow-path', 'h-5 w-5 animate-spin text-[color:var(--nx-muted)]')
        </span>
        <div class="min-w-0 flex-1">
            <div class="text-sm font-medium text-[color:var(--nx-text)]">Wird hochgeladen … <span class="tabular-nums" x-text="progress + ' %'"></span></div>
            <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-[color:var(--nx-accent-soft)]">
                <div class="h-full rounded-full bg-[color:var(--nx-accent)] transition-[width]" :style="'width:' + progress + '%'"></div>
            </div>
        </div>
    </div>

    <div x-show="error" x-cloak class="border-t border-[color:var(--nx-line)] px-4 py-2 text-xs text-[color:var(--nx-danger)]" x-text="error"></div>
</div>
