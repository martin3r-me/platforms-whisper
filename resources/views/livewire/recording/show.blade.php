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
                    <div class="p-4 flex items-center gap-3 text-sm text-[var(--ui-muted)]">
                        <svg class="animate-spin h-4 w-4 text-[var(--ui-primary)]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <span>Audio wird transkribiert… Die Seite aktualisiert sich automatisch alle 3 Sekunden.</span>
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

            {{-- Action Items (falls vorhanden) --}}
            @if($recording->action_items)
                <x-ui-panel title="Action Items">
                    <div class="p-4">
                        <div class="whitespace-pre-wrap text-sm leading-relaxed text-[var(--ui-fg)]">{{ $recording->action_items }}</div>
                    </div>
                </x-ui-panel>
            @endif

            {{-- Transcript --}}
            <x-ui-panel :title="$recording->speakers_count > 1 ? 'Transkript · '.$recording->speakers_count.' Sprecher' : 'Transkript'">
                <div class="p-4">
                    @if($recording->status === 'failed')
                        <div class="p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                            <strong>Fehler:</strong> {{ $recording->error_message }}
                        </div>
                    @elseif(!$recording->transcript && $isInFlight)
                        <div class="p-3 rounded-lg bg-[var(--ui-muted-5)] text-[var(--ui-muted)] text-sm">
                            Transkription läuft… Die Verarbeitung kann je nach Länge einen Moment dauern.
                        </div>
                    @else
                        @php
                            $segments = is_array($recording->segments) ? $recording->segments : [];
                            $hasSegments = count($segments) > 0;
                            // Stabile Farben pro Sprecher (rotierend)
                            $speakerPalette = ['sky', 'emerald', 'violet', 'amber', 'rose', 'cyan', 'lime', 'fuchsia'];
                            $speakerIndex = [];
                            foreach ($segments as $seg) {
                                $sp = $seg['speaker'] ?? 'A';
                                if (!isset($speakerIndex[$sp])) {
                                    $speakerIndex[$sp] = count($speakerIndex);
                                }
                            }
                            $speakerMap = is_array($recording->speaker_map) ? $recording->speaker_map : [];
                        @endphp
                        <div x-data="{ copied: false, view: '{{ $hasSegments ? 'speakers' : 'plain' }}' }" class="space-y-3">
                            <div class="flex items-center justify-between">
                                @if($hasSegments)
                                    <div class="inline-flex rounded-md border border-[var(--ui-border)] overflow-hidden text-xs">
                                        <button type="button"
                                                class="px-3 py-1 transition"
                                                :class="view === 'speakers' ? 'bg-[var(--ui-primary)] text-white' : 'bg-transparent text-[var(--ui-muted)] hover:bg-[var(--ui-muted-5)]'"
                                                @click="view = 'speakers'">
                                            Sprecher
                                        </button>
                                        <button type="button"
                                                class="px-3 py-1 transition"
                                                :class="view === 'plain' ? 'bg-[var(--ui-primary)] text-white' : 'bg-transparent text-[var(--ui-muted)] hover:bg-[var(--ui-muted-5)]'"
                                                @click="view = 'plain'">
                                            Fließtext
                                        </button>
                                    </div>
                                @else
                                    <div></div>
                                @endif

                                <x-ui-button
                                    variant="secondary"
                                    size="sm"
                                    x-on:click="navigator.clipboard.writeText($refs.transcript.innerText); copied = true; setTimeout(() => copied = false, 1500)">
                                    <span x-show="!copied">Kopieren</span>
                                    <span x-show="copied">Kopiert!</span>
                                </x-ui-button>
                            </div>

                            {{-- Sprecher-Legende (rename) --}}
                            @if($hasSegments && count($speakerIndex) > 0)
                                <div x-show="view === 'speakers'" class="flex flex-wrap gap-2 p-3 rounded-lg bg-[var(--ui-muted-5)] border border-[var(--ui-border)]">
                                    <div class="text-xs text-[var(--ui-muted)] self-center mr-1">Sprecher benennen:</div>
                                    @foreach($speakerIndex as $sp => $idx)
                                        @php
                                            $legendPalette = $speakerPalette[$idx % count($speakerPalette)];
                                            $currentName = $speakerMap[$sp] ?? '';
                                        @endphp
                                        <div x-data="{ editing: false, value: @js($currentName) }" class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md bg-white border border-{{ $legendPalette }}-300">
                                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-{{ $legendPalette }}-500 text-white text-[10px] font-bold">{{ $sp }}</span>
                                            <template x-if="!editing">
                                                <button type="button"
                                                        class="text-xs font-medium text-[var(--ui-fg)] hover:text-[var(--ui-primary)]"
                                                        @click="editing = true; $nextTick(() => $refs.input.focus())">
                                                    <span x-text="value || 'Name hinzufügen…'"
                                                          :class="value ? '' : 'italic text-[var(--ui-muted)]'"></span>
                                                </button>
                                            </template>
                                            <template x-if="editing">
                                                <input type="text"
                                                       x-ref="input"
                                                       x-model="value"
                                                       @keydown.enter.prevent="editing = false; $wire.renameSpeaker(@js($sp), value)"
                                                       @keydown.escape="editing = false; value = @js($currentName)"
                                                       @blur="editing = false; $wire.renameSpeaker(@js($sp), value)"
                                                       placeholder="Name"
                                                       maxlength="80"
                                                       class="text-xs px-1 py-0.5 border border-{{ $legendPalette }}-400 rounded outline-none focus:ring-1 focus:ring-{{ $legendPalette }}-500 w-32">
                                            </template>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Sprecher-Ansicht --}}
                            @if($hasSegments)
                                <div x-show="view === 'speakers'" x-ref="transcript" class="space-y-3">
                                    @foreach($segments as $seg)
                                        @php
                                            $sp = $seg['speaker'] ?? 'A';
                                            $paletteKey = $speakerPalette[$speakerIndex[$sp] % count($speakerPalette)];
                                            $start = (int) floor((float) ($seg['start'] ?? 0));
                                            $mm = str_pad((string) intdiv($start, 60), 2, '0', STR_PAD_LEFT);
                                            $ss = str_pad((string) ($start % 60), 2, '0', STR_PAD_LEFT);
                                            $displayName = $speakerMap[$sp] ?? null;
                                        @endphp
                                        <div class="flex gap-3 p-3 rounded-lg bg-{{ $paletteKey }}-50 border border-{{ $paletteKey }}-200">
                                            <div class="flex-shrink-0 flex flex-col items-center min-w-[3.5rem]">
                                                <span class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-{{ $paletteKey }}-500 text-white text-xs font-bold">
                                                    {{ $sp }}
                                                </span>
                                                @if($displayName)
                                                    <span class="mt-1 text-[10px] font-semibold text-{{ $paletteKey }}-700 text-center leading-tight max-w-[5rem] truncate">{{ $displayName }}</span>
                                                @endif
                                                <span class="mt-1 text-[10px] font-mono text-{{ $paletteKey }}-700">{{ $mm }}:{{ $ss }}</span>
                                            </div>
                                            <div class="flex-1 text-sm leading-relaxed text-[var(--ui-fg)] whitespace-pre-wrap">{{ $seg['text'] ?? '' }}</div>
                                        </div>
                                    @endforeach
                                </div>
                                <div x-show="view === 'plain'"
                                     class="whitespace-pre-wrap text-sm leading-relaxed p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]">{{ $recording->transcript ?: '—' }}</div>
                            @else
                                <div x-ref="transcript"
                                     class="whitespace-pre-wrap text-sm leading-relaxed p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]">{{ $recording->transcript ?: '—' }}</div>
                            @endif
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
