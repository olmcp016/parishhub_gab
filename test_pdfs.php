<?php
require 'vendor/autoload.php';
require 'config/config.php';
require 'includes/functions.php';
require 'includes/wedding-forms.php';
require 'includes/baptism-forms.php';
require 'includes/funeral-forms.php';

$weddingData = ['groom_name'=>'John Doe','bride_name'=>'Jane Doe'];
file_put_contents('test_wedding_matrimony.pdf', weddingMarriageApplicationPdf($weddingData));
file_put_contents('test_wedding_katin.pdf', weddingKatinPdf($weddingData));
file_put_contents('test_wedding_sponsor.pdf', weddingSponsorPdf($weddingData));

$baptismData = ['child_name'=>'Baby Doe', 'service_date'=>'2026-10-15'];
file_put_contents('test_baptism_katin.pdf', baptismKatinPdf($baptismData));
file_put_contents('test_baptism_sponsor.pdf', baptismSponsorPdf($baptismData));

$funeralData = ['ngalan_sa_ilubong'=>'Dead Doe', 'edad'=>'99'];
file_put_contents('test_funeral_katin.pdf', funeralKatinAwanPdf($funeralData));

echo "PDFs generated.\n";
