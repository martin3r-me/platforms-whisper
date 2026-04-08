<div>
    {{-- Modul Header --}}
    <div x-show="!collapsed" class="p-3 text-sm italic text-[var(--ui-secondary)] uppercase border-b border-[var(--ui-border)] mb-2">
        Whisper
    </div>

    {{-- Abschnitt: Allgemein --}}
    <x-ui-sidebar-list label="Allgemein">
        <x-ui-sidebar-item :href="route('whisper.dashboard')">
            @svg('heroicon-o-home', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Dashboard</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('whisper.dashboard') . '#recorder'">
            @svg('heroicon-o-microphone', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Neue Aufnahme</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    {{-- Collapsed: Icons-only --}}
    <div x-show="collapsed" class="px-2 py-2 border-b border-[var(--ui-border)]">
        <div class="flex flex-col gap-2">
            <a href="{{ route('whisper.dashboard') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-home', 'w-5 h-5')
            </a>
            <a href="{{ route('whisper.dashboard') }}#recorder" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-microphone', 'w-5 h-5')
            </a>
        </div>
    </div>

    {{-- Aufnahmen nach Organization gruppiert --}}
    <div x-show="!collapsed" class="mt-2">
        {{-- Entity-Type Gruppen --}}
        @foreach($entityTypeGroups as $typeGroup)
            <x-ui-sidebar-list wire:key="rec-type-{{ $typeGroup['type_id'] }}" :label="$typeGroup['type_name']">
                @foreach($typeGroup['entities'] as $entity)
                    <div wire:key="rec-entity-{{ $entity['entity_id'] }}" class="mb-1">
                        <div class="d-flex items-center gap-1.5 px-3 py-1 text-[11px] uppercase tracking-wide text-[var(--ui-muted)]">
                            @svg('heroicon-o-building-office-2', 'w-3 h-3')
                            <span class="truncate">{{ $entity['entity_name'] }}</span>
                        </div>
                        @foreach($entity['recordings'] as $rec)
                            @php
                                $dotColor = match($rec->status) {
                                    'completed' => '#10b981',
                                    'processing' => '#3b82f6',
                                    'pending' => '#9ca3af',
                                    'failed' => '#ef4444',
                                    default => '#9ca3af',
                                };
                            @endphp
                            <a wire:key="rec-{{ $rec->id }}-{{ $entity['entity_id'] }}"
                               href="{{ route('whisper.recordings.show', ['recording' => $rec->id]) }}"
                               wire:navigate
                               title="{{ $rec->title }}"
                               class="flex items-center gap-1.5 py-0.5 pl-6 pr-2 text-[var(--ui-secondary)] hover:text-[var(--ui-primary)] transition truncate">
                                <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background-color: {{ $dotColor }}"></span>
                                <span class="truncate text-[11px]">{{ $rec->title ?: 'Aufnahme #'.$rec->id }}</span>
                                @if($rec->duration_seconds)
                                    <span class="ml-auto text-[10px] text-[var(--ui-muted)] flex-shrink-0">{{ gmdate('i:s', $rec->duration_seconds) }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </x-ui-sidebar-list>
        @endforeach

        {{-- Unverknüpfte --}}
        @if($unlinkedRecordings->isNotEmpty())
            <x-ui-sidebar-list label="Unverknüpft">
                @foreach($unlinkedRecordings as $rec)
                    @php
                        $dotColor = match($rec->status) {
                            'completed' => '#10b981',
                            'processing' => '#3b82f6',
                            'pending' => '#9ca3af',
                            'failed' => '#ef4444',
                            default => '#9ca3af',
                        };
                    @endphp
                    <a wire:key="unlinked-rec-{{ $rec->id }}"
                       href="{{ route('whisper.recordings.show', ['recording' => $rec->id]) }}"
                       wire:navigate
                       title="{{ $rec->title }}"
                       class="flex items-center gap-1.5 py-0.5 pl-3 pr-2 text-[var(--ui-secondary)] hover:text-[var(--ui-primary)] transition truncate">
                        <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background-color: {{ $dotColor }}"></span>
                        <span class="truncate text-[11px]">{{ $rec->title ?: 'Aufnahme #'.$rec->id }}</span>
                        @if($rec->duration_seconds)
                            <span class="ml-auto text-[10px] text-[var(--ui-muted)] flex-shrink-0">{{ gmdate('i:s', $rec->duration_seconds) }}</span>
                        @endif
                    </a>
                @endforeach
            </x-ui-sidebar-list>
        @endif

        {{-- Empty --}}
        @if($entityTypeGroups->isEmpty() && $unlinkedRecordings->isEmpty())
            <div class="px-3 py-2 text-xs text-[var(--ui-muted)]">
                Noch keine Aufnahmen
            </div>
        @endif
    </div>
</div>
