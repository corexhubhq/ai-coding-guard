<?php

namespace AICodingGuard\Exceptions;

/**
 * The project is not in a state where a change can be tried safely
 * (not a git repository, dirty working tree, ...). Not the AI's fault,
 * so it is never recorded as a lesson.
 */
class EnvironmentException extends GuardException
{
}
