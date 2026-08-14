<?php

declare(strict_types=1);

namespace App\Matomo\Security;

use RuntimeException;

final class BlockedEgressTarget extends RuntimeException {}
