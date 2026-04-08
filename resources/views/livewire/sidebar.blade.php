<div x-data="{ collapsed: false }">
    <div x-show="!collapsed" class="p-3 text-sm italic text-[var(--ui-secondary)] uppercase border-b border-[var(--ui-border)] mb-2">
        Whisper
    </div>

    <x-ui-sidebar-list label="Allgemein">
        <x-ui-sidebar-item :href="route('whisper.dashboard')">
            @svg('heroicon-o-microphone', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Dashboard</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    <div x-show="collapsed" class="px-2 py-2 border-b border-[var(--ui-border)]">
        <div class="flex flex-col gap-2">
            <a href="{{ route('whisper.dashboard') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-microphone', 'w-5 h-5')
            </a>
        </div>
    </div>
</div>
