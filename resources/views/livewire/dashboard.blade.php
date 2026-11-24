<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Whisper" icon="heroicon-o-microphone" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Whisper', 'href' => route('whisper.dashboard'), 'icon' => 'microphone'],
        ]">
            <x-slot name="left">
                <div class="flex items-center gap-2">
                    <x-nx-input-text name="search" size="sm" placeholder="Titel oder Transkript durchsuchen …"
                                     wire:model.live.debounce.300ms="search" class="w-64" />
                    <div class="flex items-center gap-0.5 rounded-[6px] bg-[color:var(--nx-hover)] p-0.5 text-xs">
                        <button type="button" wire:click="setSource('')"
                                @class(['rounded-[5px] px-2.5 py-1 font-medium transition-colors',
                                        'bg-[color:var(--nx-surface)] text-[color:var(--nx-text)] shadow-sm' => $source === '',
                                        'text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)]' => $source !== ''])>
                            Alle
                        </button>
                        @foreach($sources as $key => $src)
                            <button type="button" wire:click="setSource('{{ $key }}')"
                                    @class(['rounded-[5px] px-2.5 py-1 font-medium transition-colors',
                                            'bg-[color:var(--nx-surface)] text-[color:var(--nx-text)] shadow-sm' => $source === $key,
                                            'text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)]' => $source !== $key])>
                                {{ $src['label'] }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </x-slot>

            <x-nx-button variant="primary" x-data x-on:click="$dispatch('whisper-record')">
                @svg('heroicon-s-microphone', 'h-4 w-4') Aufnehmen
            </x-nx-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="contained" spacing="space-y-6">
        <div @if($hasInFlight) wire:poll.5s @endif class="space-y-6">
            {{-- Kopf --}}
            <div class="flex items-end justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight text-[color:var(--nx-text)]">Aufnahmen</h1>
                    <p class="mt-0.5 text-sm text-[color:var(--nx-muted)]">
                        {{ $stats['total'] }} {{ $stats['total'] === 1 ? 'Aufnahme' : 'Aufnahmen' }}
                        · {{ number_format($stats['hours'], 1, ',', '.') }} Std. Audio
                        @if($stats['in_flight'] > 0)
                            · <span class="text-[color:var(--nx-info)]">{{ $stats['in_flight'] }} in Arbeit</span>
                        @endif
                    </p>
                </div>
            </div>

            @include('whisper::livewire.partials.recorder')

            {{-- Liste, nach Tagen --}}
            @forelse($groups as $day => $items)
                <section wire:key="day-{{ $day }}" class="space-y-2">
                    <div class="px-1 text-[11px] font-semibold uppercase tracking-wider text-[color:var(--nx-faint)]">{{ $day }}</div>
                    <x-nx-card flush class="divide-y divide-[color:var(--nx-line)]">
                        @foreach($items as $rec)
                            @php
                                $src = $rec->sourceDisplay();
                                $status = $rec->statusDisplay();
                                $speakers = (int) $rec->speakers_count;
                            @endphp
                            <article wire:key="rec-{{ $rec->id }}"
                                     class="group relative flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-[color:var(--nx-hover)]">
                                <a href="{{ route('whisper.recordings.show', ['recording' => $rec->id]) }}" wire:navigate
                                   class="absolute inset-0" aria-label="{{ $rec->title ?: 'Aufnahme #'.$rec->id }}"></a>

                                <span class="pointer-events-none flex h-8 w-8 shrink-0 items-center justify-center rounded-[6px] bg-[color:var(--nx-accent-soft)] text-[color:var(--nx-muted)]"
                                      title="{{ $src['label'] }}">
                                    @svg($src['icon'], 'h-4 w-4')
                                </span>

                                <div class="pointer-events-none min-w-0 flex-1">
                                    <div class="truncate text-[14px] font-medium text-[color:var(--nx-text)]">{{ $rec->title ?: 'Aufnahme #'.$rec->id }}</div>
                                    <div class="mt-0.5 flex items-center gap-1.5 truncate text-xs text-[color:var(--nx-muted)]">
                                        <span class="tabular-nums">{{ $rec->timeRange() }}</span>
                                        @if($d = $rec->durationLabel())
                                            <span class="text-[color:var(--nx-faint)]">·</span><span>{{ $d }}</span>
                                        @endif
                                        @if($speakers > 1)
                                            <span class="text-[color:var(--nx-faint)]">·</span><span>{{ $speakers }} Sprecher</span>
                                        @endif
                                        <span class="text-[color:var(--nx-faint)]">·</span><span>{{ $src['label'] }}</span>
                                    </div>
                                </div>

                                @if($status)
                                    <x-nx-badge :variant="$status['variant']" dot class="pointer-events-none shrink-0">{{ $status['label'] }}</x-nx-badge>
                                @endif

                                <div class="relative shrink-0 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
                                    <x-nx-dropdown>
                                        <x-nx-dropdown-item :href="route('whisper.recordings.show', ['recording' => $rec->id])" wire:navigate>
                                            @svg('heroicon-o-arrow-top-right-on-square', 'h-4 w-4') Öffnen
                                        </x-nx-dropdown-item>
                                        <x-nx-dropdown-divider />
                                        <x-nx-dropdown-item variant="danger"
                                                            wire:click="deleteRecording({{ $rec->id }})"
                                                            wire:confirm="Aufnahme „{{ $rec->title ?: '#'.$rec->id }}“ wirklich löschen? Das lässt sich nicht rückgängig machen.">
                                            @svg('heroicon-o-trash', 'h-4 w-4') Löschen
                                        </x-nx-dropdown-item>
                                    </x-nx-dropdown>
                                </div>
                            </article>
                        @endforeach
                    </x-nx-card>
                </section>
            @empty
                <x-nx-card>
                    @if($filtered)
                        <x-nx-empty icon="heroicon-o-magnifying-glass">
                            Keine Aufnahme passt zu diesem Filter.
                            <x-slot name="action">
                                <x-nx-button variant="ghost" wire:click="$set('search', ''); $set('source', '')">Filter zurücksetzen</x-nx-button>
                            </x-slot>
                        </x-nx-empty>
                    @else
                        <x-nx-empty icon="heroicon-o-microphone">
                            Noch keine Aufnahmen. Starte oben eine Aufnahme oder lade eine Datei hoch —
                            Plaud-Aufnahmen kommen automatisch dazu.
                        </x-nx-empty>
                    @endif
                </x-nx-card>
            @endforelse

            @if($hasMore)
                <div class="flex justify-center">
                    <x-nx-button variant="ghost" wire:click="loadMore">Ältere Aufnahmen laden</x-nx-button>
                </div>
            @endif
        </div>
    </x-ui-page-container>
</x-ui-page>
