<?php
// includes/validation.php

function validatePhilippineMobile($phone) {
    if ($phone === null) return false;
    $phone = trim($phone);
    if ($phone === '') return false;
    if (preg_match('/^09[0-9]{9}$/', $phone)) {
        return $phone;
    }
    return false;
}

function validateName($name) {
    if ($name === null) return false;
    $name = trim($name);
    if ($name === '') return false;
    if (preg_match('/^\d+$/', $name)) return false;
    return $name;
}

function validateText($text) {
    if ($text === null) return false;
    $text = trim($text);
    if ($text === '') return false;
    return $text;
}

function validateInteger($value, $min = 0, $max = PHP_INT_MAX) {
    if ($value === null || $value === '') return false;
    $filtered = filter_var($value, FILTER_VALIDATE_INT);
    if ($filtered === false) return false;
    if ($filtered < $min || $filtered > $max) return false;
    return $filtered;
}

function validateAge($value) {
    return validateInteger($value, 0, 120);
}

function validateEmail($email) {
    if ($email === null) return false;
    $email = trim($email);
    if ($email === '') return false;
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }
    return false;
}

function validateDate($date, $format = 'Y-m-d') {
    if ($date === null || trim($date) === '') return false;
    $d = DateTime::createFromFormat($format, trim($date));
    if ($d && $d->format($format) === trim($date)) {
        return trim($date);
    }
    return false;
}

function validateTime($time) {
    if ($time === null || trim($time) === '') return false;
    $time = trim($time);
    if (preg_match('/^(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $time)) {
        return $time;
    }
    return false;
}

function validateEnum($value, array $allowlist) {
    if ($value === null) return false;
    $value = trim($value);
    if (in_array($value, $allowlist, true)) {
        return $value;
    }
    return false;
}
