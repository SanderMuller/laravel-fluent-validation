<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\ValidatedInput as BaseValidatedInput;

/**
 * Laravel's ValidatedInput, plus file access and nullable string and integer accessors.
 * Returned by Validated::safe().
 */
final class ValidatedInput extends BaseValidatedInput
{
    /**
     * Retrieve an uploaded file from the validated input. Laravel 13.3 added
     * this method; the override gives it to older Laravel versions too.
     *
     * @param  string  $key
     * @param  mixed  $default  returned when it is a file and the value is not
     */
    public function file($key, $default = null): ?UploadedFile
    {
        $value = $this->input($key);

        if ($value instanceof UploadedFile) {
            return $value;
        }

        return $default instanceof UploadedFile ? $default : null;
    }

    /**
     * Retrieve a value as a plain string, or the default when the value is
     * missing, blank, or not a string or number. Numbers are cast. A
     * blank value is one that filled() rejects: null, '' or only
     * whitespace.
     *
     * @return ($default is string ? string : string|null)
     */
    public function nullableString(string $key, ?string $default = null): ?string
    {
        $value = $this->input($key);

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return $default;
        }

        $value = (string) $value;

        return trim($value) === '' ? $default : $value;
    }

    /**
     * Retrieve a value as an integer, or the default when the value is blank.
     * A blank value is one that filled() rejects: null, '' or only whitespace.
     * Otherwise the result is what integer() returns.
     *
     * @return ($default is int ? int : int|null)
     */
    public function nullableInteger(string $key, ?int $default = null): ?int
    {
        return $this->filled($key) ? $this->integer($key) : $default;
    }

    /**
     * Retrieve the uploaded files under a key. A single file becomes a list of
     * one. Values that are not files are left out.
     *
     * @return list<UploadedFile>
     */
    public function files(string $key): array
    {
        $value = $this->input($key);

        return array_values(array_filter(
            is_array($value) ? $value : [$value],
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));
    }
}
