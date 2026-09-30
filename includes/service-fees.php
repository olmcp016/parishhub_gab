<?php
function feeRuleFor(string $category, ?string $scheduleType, string $classification): ?array
{
    $scheduleType = in_array($category, ['Funeral','Wake'], true) ? 'Any' : ($scheduleType ?: 'Special');
    $stmt = db()->prepare('SELECT * FROM service_fee_rules WHERE service_category = ? AND schedule_type = ? AND pss_classification = ?');
    $stmt->execute([$category, $scheduleType, $classification]);
    return $stmt->fetch() ?: null;
}

function calculateServiceFee(string $category, ?string $scheduleType, string $classification, int $sponsors = 0): ?array
{
    $rule = feeRuleFor($category, $scheduleType, $classification);
    if (!$rule) return null;
    $additionalCount = max($sponsors - (int) $rule['included_sponsors'], 0);
    $additionalFee = $additionalCount * (float) $rule['additional_sponsor_fee'];
    $total = (float) $rule['base_fee'] + (float) $rule['priest_stipend'] + $additionalFee;
    return ['base_fee'=>(float)$rule['base_fee'], 'priest_stipend'=>(float)$rule['priest_stipend'], 'additional_sponsor_count'=>$additionalCount, 'additional_sponsor_fee'=>$additionalFee, 'total'=>$total, 'classification'=>$classification, 'schedule_type'=>$scheduleType, 'sponsor_count'=>$sponsors];
}
