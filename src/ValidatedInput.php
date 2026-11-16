<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\ValidatedInput as BaseValidatedInput;

/**
 * Laravel's ValidatedInput, plus file access. Returned by Validated::safe().
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
