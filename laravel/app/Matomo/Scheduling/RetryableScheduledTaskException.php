<?php

declare(strict_types=1);

namespace App\Matomo\Scheduling;

use RuntimeException;

class RetryableScheduledTaskException extends RuntimeException {}
