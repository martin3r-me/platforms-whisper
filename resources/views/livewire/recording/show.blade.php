<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar :title="$recording->title ?: 'Aufnahme #'.$recording->id" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Whisper', 'href' => route('whisper.dashboard'), 'icon' => 'microphone'],
            ['label' => $recording->title ?: 'Aufnahme #'.$recording->id, 'href' => route('whisper.recordings.show', ['recording' => $recording->id]), 'icon' => 'document-text'],
        ]" />
    </x-slot>

    <x-ui-page-container>
        @php
            $isInFlight = in_array($recording->status, ['pending', 'processing'], true);
            $progress = $recording->progressPercent();
        @endphp

        <div class="space-y-6"
             @if($isInFlight) wire:poll.3s @endif>
            {{-- Meta --}}
            <x-ui-panel title="Details">
                <div class="p-4 grid grid-cols-2 md:grid-cols-5 gap-4 text-sm">
                    <div>
                        <div class="text-[var(--ui-muted)] text-xs uppercase">Datum</div>
                        <div class="font-medium">{{ $recording->created_at->format('d.m.Y H:i') }}</div>
                    </div>
                    <div>
                        <div class="text-[var(--ui-muted)] text-xs uppercase">Dauer</div>
                        <div class="font-medium">{{ $recording->duration_seconds ? gmdate('H:i:s', $recording->duration_seconds) : '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[var(--ui-muted)] text-xs uppercase">Sprache</div>
                        <div class="font-medium">{{ $recording->language ?: '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[var(--ui-muted)] text-xs uppercase">Größe</div>
                        <div class="font-medium">
                            {{ $recording->file_size_bytes ? number_format($recording->file_size_bytes / 1024 / 1024, 1, ',', '.').' MB' : '—' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-[var(--ui-muted)] text-xs uppercase">Status</div>
                        <div>
                            @php
                                $variant = match($recording->status) {
                                    'completed' => 'success',
                                    'processing' => 'info',
                                    'pending' => 'secondary',
                                    'failed' => 'danger',
                                    default => 'secondary',
                                };
                            @endphp
                            <x-ui-badge :variant="$variant">{{ $recording->status }}</x-ui-badge>
                        </div>
                    </div>
                </div>
            </x-ui-panel>

            {{-- Progress (während Verarbeitung) --}}
            @if($isInFlight)
                <x-ui-panel title="Verarbeitung läuft">
                    <div class="p-4 space-y-3">
                        <div class="d-flex items-center justify-between text-sm">
                            <span class="text-[var(--ui-muted)]">
                                @if($recording->chunks_total)
                                    Chunk {{ $recording->chunks_done ?? 0 }} von {{ $recording->chunks_total }}
                                @else
                                    Audio wird vorbereitet…
                                @endif
                            </span>
                            <span class="font-mono">{{ $progress }}%</span>
                        </div>
                        <div class="w-full h-2 bg-[var(--ui-muted-5)] rounded-full overflow-hidden">
                            <div class="h-full bg-[var(--ui-primary)] transition-all duration-500"
                                 style="width: {{ $progress }}%"></div>
                        </div>
                        <div class="text-xs text-[var(--ui-muted)]">Aktualisiert sich automatisch alle 3 Sekunden.</div>
                    </div>
                </x-ui-panel>
            @endif

            {{-- Summary (falls vorhanden) --}}
            @if($recording->summary)
                <x-ui-panel title="Zusammenfassung">
                    <div class="p-4">
                        <div class="whitespace-pre-wrap text-sm leading-relaxed text-[var(--ui-fg)]">{{ $recording->summary }}</div>
                    </div>
                </x-ui-panel>
            @endif

            {{-- Transcript --}}
            <x-ui-panel title="Transkript">
                <div class="p-4">
                    @if($recording->status === 'failed')
                        <div class="p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                            <strong>Fehler:</strong> {{ $recording->error_message }}
                        </div>
                    @elseif(!$recording->transcript && $isInFlight)
                        <div class="p-3 rounded-lg bg-[var(--ui-muted-5)] text-[var(--ui-muted)] text-sm">
                            Transkription läuft… Erste Ergebnisse erscheinen hier sobald der erste Chunk fertig ist.
                        </div>
                    @else
                        <div x-data="{ copied: false }" class="space-y-3">
                            <div class="d-flex justify-end">
                                <x-ui-button
                                    variant="secondary"
                                    size="sm"
                                    x-on:click="navigator.clipboard.writeText($refs.transcript.innerText); copied = true; setTimeout(() => copied = false, 1500)">
                                    <span x-show="!copied">Kopieren</span>
                                    <span x-show="copied">Kopiert!</span>
                                </x-ui-button>
                            </div>
                            <div x-ref="transcript"
                                 class="whitespace-pre-wrap text-sm leading-relaxed p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]">{{ $recording->transcript ?: '—' }}</div>
                        </div>
                    @endif
                </div>
            </x-ui-panel>

            {{-- Actions --}}
            <div class="d-flex justify-end gap-2">
                <x-ui-button
                    variant="danger"
                    wire:click="delete"
                    wire:confirm="Aufnahme wirklich löschen?">
                    Löschen
                </x-ui-button>
            </div>
        </div>
    </x-ui-page-container>

    {{-- Rechte Sidebar --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Details" width="w-72" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 text-sm">
                <div>
                    <div class="text-xs uppercase text-[var(--ui-muted)]">Modell</div>
                    <div class="font-medium">{{ $recording->model }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-[var(--ui-muted)]">Erstellt</div>
                    <div class="font-medium">{{ $recording->created_at->diffForHumans() }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-[var(--ui-muted)]">Datei-Größe</div>
                    <div class="font-medium">
                        {{ $recording->file_size_bytes ? number_format($recording->file_size_bytes / 1024 / 1024, 2, ',', '.').' MB' : '—' }}
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>
</x-ui-page>
