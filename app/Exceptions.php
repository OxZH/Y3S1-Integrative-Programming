<?php
// Application exceptions. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

namespace App;

use RuntimeException;

// Rendered as 403. Carries the same wording as NotFoundException on purpose, so
// probing ids cannot tell "not yours" apart from "does not exist".
class AuthorizationException extends RuntimeException
{
}

// Rendered as 404.
class NotFoundException extends RuntimeException
{
}

// A consumed web service did not answer, or answered with an error.
class ServiceUnavailableException extends RuntimeException
{
}

class ValidationException extends RuntimeException
{
    public function __construct(private array $errors)
    {
        parent::__construct('Please correct the highlighted fields.');
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
