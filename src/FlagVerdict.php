<?php

declare(strict_types=1);

namespace BillDogEng;

/**
 * A local-evaluation verdict that is neither `true`, `false`, nor a variant key.
 *
 * `Cold` is returned by {@see Flags::evaluateLocally} when a DB-backed condition (segment / cohort /
 * survey_answer / event_fired_in_window / group_property) is load-bearing: the flag is neither on nor off
 * from here, and the caller must ask the server. A distinct case — NOT `false` — because "we don't know"
 * and "not in the audience" are different answers, and conflating them is how targeting breaks quietly.
 */
enum FlagVerdict
{
    case Cold;
}
