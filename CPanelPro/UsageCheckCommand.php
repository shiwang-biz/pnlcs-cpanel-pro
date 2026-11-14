<?php

namespace Modules\Servers\CPanelPro;

use Illuminate\Console\Command;

class UsageCheckCommand extends Command
{
    protected $signature = 'cpanelpro:usage-check';

    protected $description = 'cPanel/WHM Pro: warn clients at 80% and 95% of their disk or bandwidth limit';

    public function handle(UsageAlerts $alerts): int
    {
        $this->info('Usage warnings sent: '.$alerts->run());

        return self::SUCCESS;
    }
}
