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
        <div class="space-y-6">
            {{-- Meta --}}
            <x-ui-panel title="Details">
                <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <div class="text-[var(--ui-muted)] text-xs uppercase">Datum</div>
                        <div class="font-medium">{{ $recording->created_at->format('d.m.Y H:i') }}</div>
                    </div>
                    <div>
                        <div class="text-[var(--ui-muted)] text-xs uppercase">Dauer</div>
                        <div class="font-medium">{{ $recording->duration_seconds ? gmdate('i:s', $recording->duration_seconds) : '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[var(--ui-muted)] text-xs uppercase">Sprache</div>
                        <div class="font-medium">{{ $recording->language ?: '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[var(--ui-muted)] text-xs uppercase">Status</div>
                        <div>
                            @php
                                $variant = match($recording->status) {
                                    'completed' => 'success',
                                    'processing' => 'info',
                                    'failed' => 'danger',
                                    default => 'secondary',
                                };
                            @endphp
                            <x-ui-badge :variant="$variant">{{ $recording->status }}</x-ui-badge>
                        </div>
                    </div>
                </div>
            </x-ui-panel>

            {{-- Transcript --}}
            <x-ui-panel title="Transkript">
                <div class="p-4">
                    @if($recording->status === 'failed')
                        <div class="p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                            <strong>Fehler:</strong> {{ $recording->error_message }}
                        </div>
                    @elseif($recording->status === 'processing')
                        <div class="p-3 rounded-lg bg-[var(--ui-muted-5)] text-[var(--ui-muted)] text-sm">
                            Transkription läuft…
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
</x-ui-page>
