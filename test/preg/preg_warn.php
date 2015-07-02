<?php
// This passes a variable to preg_replace rather than a constant string literal, which returns a warning with -W
preg_replace($a, $b, $c);
// Same thing with a function.
preg_replace(foo(), $b, $c);
