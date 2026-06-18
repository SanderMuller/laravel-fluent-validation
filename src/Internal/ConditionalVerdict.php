<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Outcome of pre-evaluating an attribute's `exclude_unless`/`exclude_if`
 * tuples. {@see ConditionalEvaluationPhase::evaluate()}.
 *
 * The `Defer` case is the load-bearing distinction: it means the optimizer
 * could not safely reproduce Laravel's verdict (an unresolved associative
 * wildcard, or a null/bool/non-scalar dependent needing Laravel's coercion).
 * Such attributes must be left fully intact for the validator — not just
 * "not excluded", because fast-checking them away would let a field Laravel
 * would exclude leak into the validated payload.
 *
 * @internal
 */
enum ConditionalVerdict
{
    case Exclude;
    case NotExcluded;
    case Defer;
}
