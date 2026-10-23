<?php
/**
 * "Back to Booking Form" from a draft's Supporting Documents step.
 *
 * Baptism and Wedding drafts live in appointment_drafts; Funeral drafts live
 * in the session (see book.php). This loads the draft the current parishioner
 * or guest is returning to — through each type's own access check — and
 * normalizes the Step 1 booking values, so the Services page can refill the
 * form and book.php can update that same draft instead of starting a new one.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/baptism-draft.php';
require_once __DIR__ . '/wedding-draft.php';
require_once __DIR__ . '/funeral-draft.php';

const RESUMABLE_DRAFT_CATEGORIES = ['baptism' => 'Baptism', 'wedding' => 'Wedding', 'funeral' => 'Funeral'];

/** @return array{type:string,category:string,id:int,service_id:int,priest_id:?int,date:?string,time:?string,schedule_type:?string,pss_claim:?string,sponsor_count:?int,wedding_sponsor_count:?int,remarks:?string,date_of_death:?string}|null */
function loadResumableBookingDraft(string $type, int $id): ?array
{
    if ($id < 1 || !isset(RESUMABLE_DRAFT_CATEGORIES[$type])) return null;
    $user = currentUser();

    if ($type === 'funeral') {
        $d = funeralDraftLoad($id, $user);
        if (!$d) return null;
        return [
            'type' => $type, 'category' => 'Funeral', 'id' => $id,
            'service_id' => (int) $d['service_id'],
            'priest_id' => !empty($d['priest_id']) ? (int) $d['priest_id'] : null,
            'date' => null, 'time' => null, 'schedule_type' => null,
            'pss_claim' => $d['pssClaim'] ?? null,
            'sponsor_count' => null, 'wedding_sponsor_count' => null,
            'remarks' => $d['remarks'] ?? null,
            'date_of_death' => $d['dateOfDeath'] ?? null,
            // Preferred burial / Funeral Mass schedule (one schedule).
            'preferred_burial_date' => $d['preferredBurialDate'] ?? null,
            'preferred_burial_time' => !empty($d['preferredBurialTime']) ? substr((string) $d['preferredBurialTime'], 0, 5) : null,
            'outside_priest_name' => $d['outside_priest_name'] ?? null,
        ];
    }

    $d = $type === 'baptism'
        ? baptismDraftLoad(db(), $id, $user, baptismDraftToken($id))
        : weddingDraftLoad(db(), $id, $user, weddingDraftGuestToken($id));
    if (!$d || ($d['status'] ?? '') !== 'draft') return null;
    if (!empty($d['expires_at']) && strtotime((string) $d['expires_at']) <= time()) return null;
    return [
        'type' => $type, 'category' => RESUMABLE_DRAFT_CATEGORIES[$type], 'id' => $id,
        'service_id' => (int) $d['service_id'],
        'priest_id' => !empty($d['priest_id']) ? (int) $d['priest_id'] : null,
        'outside_priest_name' => $d['outside_priest_name'] ?? null,
        'date' => $d['appointment_date'] ?? null,
        'time' => !empty($d['appointment_time']) ? substr((string) $d['appointment_time'], 0, 5) : null,
        'schedule_type' => $d['schedule_type'] ?? null,
        'pss_claim' => $d['pss_claim'] ?? null,
        'sponsor_count' => isset($d['sponsor_count']) ? (int) $d['sponsor_count'] : null,
        'wedding_sponsor_count' => isset($d['wedding_sponsor_count']) ? (int) $d['wedding_sponsor_count'] : null,
        'remarks' => $d['remarks'] ?? null,
        'date_of_death' => null,
        // Wedding Pre-Cana seminar (NULL before database/migration_precana.sql or for older drafts).
        'precana_type' => $d['precana_type'] ?? null,
        'precana_date' => $d['precana_date'] ?? null,
        'precana_time' => !empty($d['precana_time']) ? substr((string) $d['precana_time'], 0, 5) : null,
    ];
}
