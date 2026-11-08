<?php

use App\Support\FormInput;

// Safe versions of old() for forms. Loaded by AppServiceProvider.
if (! function_exists('old_text')) {
    /** Old input as text. Wrong input types (like an array sent to a text field) become an empty value. */
    function old_text($key, $default = '')
    {
        return FormInput::text($key, $default);
    }
}

if (! function_exists('old_list')) {
    /** Old input as a list of text values (for check boxes). */
    function old_list($key, $default = [])
    {
        return FormInput::list($key, $default);
    }
}
