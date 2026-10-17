<?php
require_once __DIR__ . '/precana.php';

function feeRuleFor(string $category, ?string $scheduleType, string $classification): ?array
{
    $scheduleType = in_array($category, ['Funeral','Wake'], true) ? 'Any' : ($scheduleType ?: 'Special');
    $stmt = db()->prepare('SELECT * FROM service_fee_rules WHERE service_category = ? AND schedule_type = ? AND pss_classification = ?');
    $stmt->execute([$category, $scheduleType, $classification]);
    return $stmt->fetch() ?: null;
}

/**
 * $precanaType (Wedding only): the Pre-Cana seminar is a separate line —
 * Special adds PRECANA_SPECIAL_FEE (₱2,500) once, whatever the PSS classification;
 * Regular adds nothing. NULL (other services, older Weddings) leaves the
 * calculation exactly as before.
 */
function calculateServiceFee(string $category, ?string $scheduleType, string $classification, int $sponsors = 0, ?string $precanaType = null): ?array
{
    $rule = feeRuleFor($category, $scheduleType, $classification);
    if (!$rule) return null;
    $additionalCount = max($sponsors - (int) $rule['included_sponsors'], 0);
    $additionalFee = $additionalCount * (float) $rule['additional_sponsor_fee'];
    $total = (float) $rule['base_fee'] + (float) $rule['priest_stipend'] + $additionalFee;
    $calculation = ['base_fee'=>(float)$rule['base_fee'], 'priest_stipend'=>(float)$rule['priest_stipend'], 'additional_sponsor_count'=>$additionalCount, 'additional_sponsor_fee'=>$additionalFee, 'total'=>$total, 'classification'=>$classification, 'schedule_type'=>$scheduleType, 'sponsor_count'=>$sponsors];
    if ($category === 'Wedding' && in_array($precanaType, ['Regular', 'Special'], true)) {
        $calculation['precana_type'] = $precanaType;
        $calculation['precana_fee'] = precanaFee($precanaType);
        $calculation['total'] = $total + $calculation['precana_fee'];
    }
    return $calculation;
}

/** Services priced by service_fee_rules whose verified total can be ₱0 — then there is no payment step. */
const NO_PAYMENT_CATEGORIES = ['Baptism', 'Wedding', 'Funeral'];
/** How a ₱0 total is shown to parishioners, guests and staff. */
const NO_PAYMENT_REQUIRED_LABEL = '₱0.00 — No Payment Required';

/** True when this service's total is ₱0 (or less): nothing to pay — no Cash, no PayMongo, no receipt. */
function feeTotalNeedsNoPayment(string $category, ?float $total): bool
{
    return in_array($category, NO_PAYMENT_CATEGORIES, true) && $total !== null && $total <= 0;
}

/**
 * Baptisms, Weddings and Funerals whose verified (frozen) fee is ₱0 have no
 * payment step: the appointment goes straight on to confirmation instead of
 * waiting for a ₱0 payment, and is never auto-cancelled as unpaid. Never true
 * before the PSS classification is verified.
 */
function appointmentNoPaymentRequired(array $appointment): bool
{
    if (!in_array($appointment['pss_classification'] ?? '', ['pss', 'non_pss'], true)) return false;
    $snapshot = json_decode((string) ($appointment['fee_snapshot'] ?? ''), true);
    if (!is_array($snapshot) || !isset($snapshot['total']) || !is_numeric($snapshot['total'])) return false;
    return feeTotalNeedsNoPayment((string) ($appointment['category'] ?? ''), (float) $snapshot['total']);
}
