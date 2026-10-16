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

/**
 * Weddings and Funerals whose verified fee is ₱0 have no payment step: the
 * appointment goes straight on to confirmation instead of waiting for a ₱0
 * payment. Never true before the PSS classification is verified.
 */
function appointmentNoPaymentRequired(array $appointment): bool
{
    if (!in_array($appointment['category'] ?? '', ['Wedding', 'Funeral'], true)) return false;
    if (!in_array($appointment['pss_classification'] ?? '', ['pss', 'non_pss'], true)) return false;
    $snapshot = json_decode((string) ($appointment['fee_snapshot'] ?? ''), true);
    return is_array($snapshot) && isset($snapshot['total']) && is_numeric($snapshot['total']) && (float) $snapshot['total'] <= 0;
}
