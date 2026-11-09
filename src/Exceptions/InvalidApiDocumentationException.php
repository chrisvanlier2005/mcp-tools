<?php

namespace Lier\McpTools\Exceptions;

use LogicException;

final class InvalidApiDocumentationException extends LogicException
{
    /**
     * Create a new exception instance.
     *
     * @param string $path
     * @return self
     */
    public static function unreadable(string $path): self
    {
        return new self("The API documentation at [{$path}] can't be read.");
    }

    /**
     * Create a new exception instance.
     *
     * @param string $path
     * @return self
     */
    public static function notAnObject(string $path): self
    {
        return new self("The API documentation at [{$path}] is not a JSON object.");
    }

    /**
     * Create a new exception instance.
     *
     * @param string $method
     * @param string $path
     * @return self
     */
    public static function undocumentedOperation(string $method, string $path): self
    {
        return new self(
            "No API documentation found for [{$method} {$path}]. Is it documented and exported?",
        );
    }

    /**
     * Create a new exception instance.
     *
     * @param string $name
     * @return self
     */
    public static function missingComponent(string $name): self
    {
        return new self("Schema component [{$name}] does not exist.");
    }
}
