<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * The results of {@see RuleKeyPruner} that are not a kept value. An enum
 * case, so no data value can match one.
 *
 * @internal Implementation detail of `RuleSet`.
 */
enum Pruned
{
    /** No part of the value is kept. */
    case None;

    /** A data key below a wildcard holds a dot, which Laravel escapes. */
    case EscapedKey;
}
