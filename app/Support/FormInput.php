<?php

namespace App\Support;

/**
 * Reads old form input (after a rejected form) in a safe way.
 * A broken or hand-made request can send an array where the form expects text, or the other way round.
 * These helpers always return the type the view needs, so redisplaying the form can never crash.
 */
class FormInput
{
    /** Old text value, or $default when nothing (or something that is not text) was sent. */
    public static function text($key, $default = '')
    {
        if (! session()->hasOldInput($key)) {
            return $default;
        }
        $value = old($key);
        if ($value === null) {
            return '';
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }

    /** Old list of text values (for example the day checkboxes). Anything else becomes an empty list. */
    public static function list($key, $default = [])
    {
        if (! session()->hasOldInput($key)) {
            return $default;
        }
        $value = old($key);
        if (! is_array($value)) {
            return [];
        }
        $clean = [];
        foreach ($value as $item) {
            if (is_string($item) || is_int($item)) {
                $clean[] = (string) $item;
            }
        }
        return $clean;
    }

    /** Decodes a JSON list of [lat, lng] points. Bad points are dropped. */
    public static function path($json)
    {
        $points = is_string($json) ? json_decode($json, true) : null;
        $clean = [];
        if (! is_array($points)) {
            return $clean;
        }
        foreach ($points as $point) {
            if (is_array($point) && count($point) === 2 && isset($point[0], $point[1]) && is_numeric($point[0]) && is_numeric($point[1])) {
                $clean[] = [(float) $point[0], (float) $point[1]];
            }
        }
        return $clean;
    }

    /** Decodes a JSON list of street names. Anything that is not text is dropped. */
    public static function streets($json)
    {
        $names = is_string($json) ? json_decode($json, true) : null;
        $clean = [];
        if (! is_array($names)) {
            return $clean;
        }
        foreach ($names as $name) {
            if (is_string($name) && trim($name) !== '') {
                $clean[] = $name;
            }
        }
        return $clean;
    }
}
