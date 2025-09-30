<?php
// Test script to check Sept 16 tours
$json = file_get_contents('http://localhost:8080/api/tours.php');
$tours = json_decode($json, true);

echo "Tours on 2025-09-16 (should show in payment form):\n";
echo str_repeat("=", 80) . "\n";

foreach ($tours as $tour) {
    if ($tour['date'] === '2025-09-16') {
        $needsGuide = empty($tour['guide_id']) || $tour['guide_id'] === null || $tour['guide_id'] === '';
        $totalPaid = floatval($tour['total_amount_paid'] ?? 0);

        echo sprintf("ID: %-3d | %-40s | Guide: %-15s\n",
            $tour['id'],
            substr($tour['title'], 0, 40),
            $tour['guide_name'] ?: 'NO GUIDE'
        );
        echo sprintf("        Paid: %-5s | Status: %-8s | Amount: €%-8s | Needs Guide: %s\n",
            $tour['paid'] ? 'true' : 'false',
            $tour['payment_status'],
            number_format($totalPaid, 2),
            $needsGuide ? 'YES' : 'NO'
        );
        echo sprintf("        Should show: %s\n",
            ($needsGuide || $tour['payment_status'] === 'unpaid' || $totalPaid === 0) ? 'YES' : 'NO'
        );
        echo str_repeat("-", 80) . "\n";
    }
}
?>