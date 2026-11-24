<?php

namespace Platform\Whisper\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Platform\Core\Models\User;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Models\WhisperRecordingLink;

/**
 * Anhänge-Naht: Aufnahmen lose an Objekte anderer Module hängen (Morph-Alias + ID).
 * Mechanik wie slides_deck_links — der Verbraucher (z. B. gathering) registriert seinen
 * Morph-Alias und nutzt diese Klasse; whisper kennt die Module nicht.
 *
 *   $links = app(RecordingLinks::class);
 *   $links->link($recording, 'gathering_meeting', $meeting->id, 'protocol', $user);
 *   $links->recordingsFor('gathering_meeting', $meeting->id, $meeting->team_id);
 */
class RecordingLinks
{
    /** Idempotent: bestehender Link wird nur ergänzt (role), nicht verdoppelt. */
    public function link(WhisperRecording $recording, string $linkableType, int $linkableId, ?string $role = null, ?User $user = null): WhisperRecordingLink
    {
        $linkableType = trim($linkableType);
        if (!preg_match('/^[a-z0-9_.\\\\-]{2,100}$/i', $linkableType) || $linkableId <= 0) {
            throw new InvalidArgumentException('linkable_type (Morph-Alias) und linkable_id sind erforderlich.');
        }

        $link = WhisperRecordingLink::firstOrNew([
            'recording_id' => $recording->id,
            'linkable_type' => $linkableType,
            'linkable_id' => $linkableId,
        ]);
        $link->team_id = $recording->team_id;
        $link->role = $role !== null ? mb_substr(trim($role), 0, 50) : $link->role;
        $link->created_by ??= $user?->id;
        $link->save();

        return $link;
    }

    public function unlink(WhisperRecording $recording, string $linkableType, int $linkableId): bool
    {
        return WhisperRecordingLink::query()
            ->where('recording_id', $recording->id)
            ->where('linkable_type', $linkableType)
            ->where('linkable_id', $linkableId)
            ->delete() > 0;
    }

    /**
     * Aufnahmen, die an einem Objekt hängen (neueste zuerst). Mit $teamId nur aus diesem Team.
     *
     * @return Collection<int, WhisperRecording>
     */
    public function recordingsFor(string $linkableType, int $linkableId, ?int $teamId = null): Collection
    {
        return WhisperRecording::query()
            ->whereIn('id', WhisperRecordingLink::query()
                ->where('linkable_type', $linkableType)
                ->where('linkable_id', $linkableId)
                ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
                ->select('recording_id'))
            ->orderByRaw('COALESCE(recorded_at, created_at) DESC')
            ->get();
    }

    /** @return Collection<int, WhisperRecordingLink> Woran hängt die Aufnahme? */
    public function linksFor(WhisperRecording $recording): Collection
    {
        return WhisperRecordingLink::query()->where('recording_id', $recording->id)->orderBy('id')->get();
    }

    /**
     * Lesbare Beschreibung eines verknüpften Objekts für die Anzeige — ohne das Modul zu kennen:
     * Morph-Alias → Modell, Name über HasDisplayName/title/name. Null, wenn nicht (mehr) auflösbar.
     *
     * @return array{type: string, label: string}|null
     */
    public function describe(WhisperRecordingLink $link): ?array
    {
        $class = Relation::getMorphedModel($link->linkable_type) ?? $link->linkable_type;
        if (!class_exists($class)) {
            return null;
        }
        $model = $class::find($link->linkable_id);
        if (!$model) {
            return null;
        }

        $label = $model instanceof \Platform\Core\Contracts\HasDisplayName ? $model->getDisplayName() : null;
        $label = $label ?: ($model->title ?? $model->name ?? null) ?: '#' . $link->linkable_id;
        $type = str($link->linkable_type)->afterLast('_')->headline()->toString();

        return ['type' => $type, 'label' => (string) $label];
    }
}
