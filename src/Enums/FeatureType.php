<?php

declare(strict_types=1);

namespace Beziworld\Ml\Enums;

/**
 * How a raw attribute is cast into a sample value.
 */
enum FeatureType: string
{
    /** Integers, floats and numeric strings; missing values become NAN. */
    case Numeric = 'numeric';

    /** Discrete values such as a plan name; missing values become the "?" placeholder. */
    case Categorical = 'categorical';

    /** True or false, encoded as 1.0 and 0.0. */
    case Boolean = 'boolean';

    /** Dates and timestamps, encoded as days since the Unix epoch. */
    case Date = 'date';

    /** Free text, vectorised with token counts and TF-IDF. */
    case Text = 'text';

    /**
     * Whether the sample value is a float (continuous in Rubix terms).
     */
    public function isContinuous(): bool
    {
        return $this === self::Numeric || $this === self::Boolean || $this === self::Date;
    }
}
