<?php
$im = imagecreatefromjpeg('public/img/crop_funeral.jpg');
$w = imagesx($im);
$h = imagesy($im);
$crop = imagecrop($im, ['x' => 0, 'y' => (int)($h * 0.18), 'width' => $w, 'height' => (int)($h * 0.50)]);
imagepng($crop, 'public/img/crop_funeral_fields.png');
echo "Cropped fields saved\n";
