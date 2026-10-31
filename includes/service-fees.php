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

/** Services priced by service_fee_rules: the PSS classification is verified and the fee frozen in fee_snapshot. */
const FEE_RULE_CATEGORIES = ['Baptism', 'Wedding', 'Funeral'];
/** Paid when submitted and finalized by the Cashier — never "no payment required". */
const SUBMISSION_PAID_CATEGORIES = ['Mass Intention', 'Donation'];
/** How a ₱0 total is shown to parishioners, guests and staff. */
const NO_PAYMENT_REQUIRED_LABEL = '₱0.00 — No Payment Required';

/** True when this service's known total is exactly ₱0: nothing to pay — no Cash, no PayMongo, no receipt. */
function feeTotalNeedsNoPayment(string $category, ?float $total): bool
{
    return !in_array($category, SUBMISSION_PAID_CATEGORIES, true)
        && $total !== null && is_finite($total) && abs($total) < 0.005;
}

/**
 * An appointment whose authoritative fee is ₱0 has no payment step: it goes on
 * to confirmation instead of waiting for a ₱0 payment, and is never flagged as
 * unpaid. The fee is the one parishioner/pay.php and guest-pay.php charge:
 *   - Baptism / Wedding / Funeral (and a Wake that carries a PSS classification):
 *     the frozen fee_snapshot total, only once the PSS classification is verified;
 *   - every other service: the fee frozen in fee_snapshot at approval (see
 *     flatFeeAmountDue()). A legacy appointment approved before fees were frozen is
 *     never ₱0 here: the Secretary verifies it on Confirm instead.
 * Missing, malformed or negative fee data is never treated as ₱0.
 */
function appointmentNoPaymentRequired(array $appointment): bool
{
    $category = (string) ($appointment['category'] ?? '');
    if ($category === '' || in_array($category, SUBMISSION_PAID_CATEGORIES, true)) return false;
    $classification = $appointment['pss_classification'] ?? null;
    if (in_array($category, FEE_RULE_CATEGORIES, true) || ($category === 'Wake' && $classification !== null)) {
        if (!in_array($classification, ['pss', 'non_pss'], true)) return false;
        $snapshot = json_decode((string) ($appointment['fee_snapshot'] ?? ''), true);
        if (!is_array($snapshot) || !isset($snapshot['total']) || !is_numeric($snapshot['total'])) return false;
        return feeTotalNeedsNoPayment($category, (float) $snapshot['total']);
    }
    if (appointmentHasUnfrozenApprovedFee($appointment)) return false;
    $amount = flatFeeAmountDue($appointment);
    return $amount !== null && feeTotalNeedsNoPayment($category, $amount);
}

/** True when the appointment is priced by services.fee (not by the PSS fee rules, not a Cashier-flow submission). */
function appointmentUsesFlatFee(array $appointment): bool
{
    $category = (string) ($appointment['category'] ?? '');
    if ($category === '' || in_array($category, SUBMISSION_PAID_CATEGORIES, true) || in_array($category, FEE_RULE_CATEGORIES, true)) return false;
    return !($category === 'Wake' && ($appointment['pss_classification'] ?? null) !== null);
}

/**
 * What a flat-fee appointment must pay: the total frozen in fee_snapshot when it has
 * one, otherwise the current services.fee (still pending, or approved before fees were
 * frozen). Null when the data cannot be trusted — a snapshot that cannot be read, or a
 * missing / invalid fee — never ₱0.
 */
function flatFeeAmountDue(array $appointment): ?float
{
    if (trim((string) ($appointment['fee_snapshot'] ?? '')) !== '') {
        $snapshot = json_decode((string) $appointment['fee_snapshot'], true);
        if (!is_array($snapshot) || !isset($snapshot['total']) || !is_numeric($snapshot['total']) || (float) $snapshot['total'] < 0) return null;
        return (float) $snapshot['total'];
    }
    $fee = $appointment['fee'] ?? null;
    return is_numeric($fee) && (float) $fee >= 0 ? (float) $fee : null;
}

/**
 * The fee_snapshot JSON frozen when a flat-fee appointment is approved, or null when
 * nothing is frozen (PSS fee-rule service, already has a snapshot, or no valid fee).
 */
function flatFeeSnapshotForApproval(array $appointment, int $approvedBy): ?string
{
    if (!appointmentUsesFlatFee($appointment) || trim((string) ($appointment['fee_snapshot'] ?? '')) !== '') return null;
    $fee = $appointment['fee'] ?? null;
    if (!is_numeric($fee) || (float) $fee < 0) return null;
    return json_encode(['total' => round((float) $fee, 2), 'source' => 'service_fee', 'frozen_at' => date('c'), 'frozen_by' => $approvedBy]);
}

/**
 * A flat-fee appointment that was approved before fees were frozen at approval: the
 * fee it was approved with is unknown, so the current service fee cannot prove ₱0.
 */
function appointmentHasUnfrozenApprovedFee(array $appointment): bool
{
    return appointmentUsesFlatFee($appointment)
        && trim((string) ($appointment['fee_snapshot'] ?? '')) === ''
        && (int) ($appointment['status_id'] ?? 2) >= 2;
}

/** A legacy Approved appointment whose current service fee is ₱0: the Secretary must verify that ₱0 before confirming. */
function appointmentNeedsZeroFeeVerification(array $appointment): bool
{
    return (int) ($appointment['status_id'] ?? 0) === 2
        && appointmentHasUnfrozenApprovedFee($appointment)
        && ($amount = flatFeeAmountDue($appointment)) !== null
        && feeTotalNeedsNoPayment((string) $appointment['category'], $amount);
}
