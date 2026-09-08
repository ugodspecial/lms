<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| This file only *loads* the per-area route files. Keeping each portal area in
| its own file means the prefix, middleware stack and layout of a whole area can
| be reviewed at a glance, and a change to the student portal cannot break the
| admin area by accident (§66).
|
| Order matters only for overlapping prefixes; each file registers a distinct
| prefix so they are independent.
|
*/

$web = __DIR__.'/web';

foreach ([
    'public.php',      // marketing + anonymous pages
    'student.php',     // /student
    'parent.php',      // /parent
    'tutor.php',       // /tutor
    'evaluator.php',   // /evaluator
    'admin.php',       // /admin
] as $file) {
    if (is_file($web.'/'.$file)) {
        require $web.'/'.$file;
    }
}
