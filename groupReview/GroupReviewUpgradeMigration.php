<?php

/**
 * @file GroupReviewUpgradeMigration.php
 *
 * Idempotent bridge for OJS's plugin upload/upgrade path.
 */

namespace APP\plugins\generic\groupReview;

use Illuminate\Database\Migrations\Migration;
use PKP\install\DowngradeNotSupportedException;

class GroupReviewUpgradeMigration extends Migration
{
    public function up(): void
    {
        (new GroupReviewMigration())->up();
    }

    public function down(): void
    {
        // Never destroy group-review/editorial data during an automatic
        // installer rollback. Restoring the prior archive is the safe rollback.
        throw new DowngradeNotSupportedException();
    }
}
