<?php

namespace App\FollowUps;

use LogicException;

/** The state machine refused a move (docs/113 F3). Never coerced, never swallowed. */
final class IllegalTransition extends LogicException {}
